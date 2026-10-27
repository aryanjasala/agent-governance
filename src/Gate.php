<?php
/**
 * Applies the policy to an agent call and records the outcome.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the ability and REST guards.
 *
 * The enter() method classifies the call and asks the policy. A denied call gets an error
 * and a log row. A call that needs approval is stored in the queue and the agent
 * gets an `agent_governance_pending` error with HTTP 202. An allowed call opens
 * a frame, and the leave() method writes its log row with the result, the time it took and
 * the posts it changed.
 */
final class Gate {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Request signals and frames.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Agent detection.
	 *
	 * @var Classifier
	 */
	private Classifier $classifier;

	/**
	 * The policy engine.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * The audit log.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * The approval queue.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * Previews.
	 *
	 * @var DryRun
	 */
	private DryRun $dry_run;

	/**
	 * Tells approvers.
	 *
	 * @var Notifier
	 */
	private Notifier $notifier;

	/**
	 * Sets up the gate.
	 *
	 * @param Settings   $settings   Settings.
	 * @param Context    $context    Request signals and frames.
	 * @param Classifier $classifier Agent detection.
	 * @param Policy     $policy     The policy engine.
	 * @param Log        $log        The audit log.
	 * @param Requests   $requests   The approval queue.
	 * @param DryRun     $dry_run    Previews.
	 * @param Notifier   $notifier   Tells approvers.
	 */
	public function __construct( Settings $settings, Context $context, Classifier $classifier, Policy $policy, Log $log, Requests $requests, DryRun $dry_run, Notifier $notifier ) {
		$this->settings   = $settings;
		$this->context    = $context;
		$this->classifier = $classifier;
		$this->policy     = $policy;
		$this->log        = $log;
		$this->requests   = $requests;
		$this->dry_run    = $dry_run;
		$this->notifier   = $notifier;
	}

	/**
	 * The agent behind the current call, or null.
	 *
	 * @return array|null
	 */
	public function identity(): ?array {
		return $this->classifier->current();
	}

	/**
	 * Decides whether a call may run.
	 *
	 * @param array         $call       source (ability or rest), target, method, kind, input.
	 * @param callable|null $precheck   For held calls: returns false when the call
	 *                                  would fail its own permission check anyway, so
	 *                                  nothing is queued.
	 * @param array|null    $identity   The agent, when already known.
	 * @param array|null    $decision   The decision, when already made.
	 * @return \WP_Error|int|null An error to return to the agent; a frame number for
	 *                            an allowed agent call; null for a call that is not an agent's.
	 */
	public function enter( array $call, ?callable $precheck = null, ?array $identity = null, ?array $decision = null ) {
		$identity = $identity ?? $this->classifier->current();
		if ( null === $identity ) {
			return null;
		}

		$call   = self::normalize_call( $call );
		$replay = $this->context->replay();
		if ( $replay ) {
			// An approved ability runs from PHP, with no HTTP method of its own: keep the one the agent used.
			if ( '' === $call['method'] && (string) $replay['target'] === $call['target'] ) {
				$call['method'] = strtoupper( (string) $replay['method'] );
			}
			return $this->open( $call, $identity, 'executed', 'approved', (int) $replay['id'] );
		}

		$decision = $decision ?? $this->policy->decide( $call, $identity );

		if ( Settings::ALLOW === $decision['action'] ) {
			return $this->open( $call, $identity, 'allowed', self::reason( $decision ), 0 );
		}

		if ( Settings::REQUIRE_APPROVAL === $decision['action'] ) {
			if ( $precheck && ! $precheck() ) {
				return null;
			}

			return $this->hold( $call, $identity, $decision );
		}

		return $this->deny( $call, $identity, $decision );
	}

	/**
	 * Decides without acting on the decision, for the WordPress 7.1 filter.
	 *
	 * @param array $call The call.
	 * @return array|null identity and decision, or null for a call that is not an agent's.
	 */
	public function decide( array $call ): ?array {
		$identity = $this->classifier->current();
		if ( null === $identity ) {
			return null;
		}

		if ( $this->context->replay() ) {
			return array(
				'identity' => $identity,
				'decision' => array(
					'action' => Settings::ALLOW,
					'reason' => 'approved',
					'rule'   => null,
				),
			);
		}

		return array(
			'identity' => $identity,
			'decision' => $this->policy->decide( self::normalize_call( $call ), $identity ),
		);
	}

	/**
	 * Closes an allowed call and logs it.
	 *
	 * @param int   $frame  Frame number from enter().
	 * @param mixed $result What the call returned.
	 */
	public function leave( int $frame, $result ): void {
		$open = $this->context->pop_frame( $frame );
		if ( null === $open ) {
			return;
		}

		// Another plugin refused it from inside, such as Site Read-Only Mode's ability wrapper on 6.9 and 7.0.
		$refused = is_wp_error( $result ) && in_array( $result->get_error_code(), self::refusal_codes(), true );

		$this->log->add(
			array(
				'identity'    => $open['identity']['key'],
				'agent'       => $open['identity']['label'],
				'user_id'     => $open['identity']['user_id'],
				'session'     => $open['session'],
				'via'         => $open['identity']['via'],
				'source'      => $open['call']['source'],
				'target'      => $open['call']['target'],
				'method'      => $open['call']['method'],
				'kind'        => $open['call']['kind'],
				'decision'    => $refused ? 'refused' : ( self::failed( $result ) && 'executed' === $open['decision'] ? 'failed' : $open['decision'] ),
				'reason'      => $refused ? (string) $result->get_error_code() : $open['reason'],
				'result'      => self::result_code( $result ),
				'request_id'  => $open['request_id'],
				'duration_ms' => (int) round( ( microtime( true ) - $open['start'] ) * 1000 ),
				'ip'          => $this->context->ip(),
				'input'       => Redactor::encode( $open['call']['input'], 'input' ),
				'changes'     => $open['changes'] ? (string) wp_json_encode( $open['changes'] ) : null,
			)
		);
	}

	/**
	 * Logs any call still open when the request ends, such as a REST route that
	 * stopped with exit.
	 */
	public function close_open_frames(): void {
		foreach ( array_reverse( array_keys( $this->context->frames() ) ) as $index ) {
			$this->leave( $index + 1, null );
		}
	}

	/**
	 * Logs an agent call another plugin refused before Agent Governance could
	 * decide it, such as a write Site Read-Only Mode stopped during a freeze.
	 *
	 * @param array     $call  source (ability or rest), target, method, kind, input.
	 * @param \WP_Error $error What the other plugin returned.
	 * @return int Log row ID, or 0 when it was not an agent call.
	 */
	public function refused( array $call, \WP_Error $error ): int {
		$identity = $this->classifier->current();
		if ( null === $identity ) {
			return 0;
		}

		$call = self::normalize_call( $call );
		$code = (string) $error->get_error_code();

		$id = $this->log->add(
			array(
				'identity' => $identity['key'],
				'agent'    => $identity['label'],
				'user_id'  => $identity['user_id'],
				'session'  => $identity['session'],
				'via'      => $identity['via'],
				'source'   => $call['source'],
				'target'   => $call['target'],
				'method'   => $call['method'],
				'kind'     => $call['kind'],
				'decision' => 'refused',
				'reason'   => '' !== $code ? $code : 'refused',
				'result'   => '' !== $code ? $code : 'refused',
				'ip'       => $this->context->ip(),
				'input'    => Redactor::encode( $call['input'], 'input' ),
			)
		);

		/**
		 * Fires when another plugin refused an agent call before it ran, such as
		 * Site Read-Only Mode during a freeze.
		 *
		 * @param array     $call     The call: source, target, method, kind and input.
		 * @param array     $identity The agent: key, label, user_id, via and session.
		 * @param \WP_Error $error    What the other plugin returned.
		 */
		do_action( 'agent_governance_call_refused', $call, $identity, $error );

		return $id;
	}

	/**
	 * Error codes that mean another plugin refused a call, rather than the call failing.
	 *
	 * @return string[]
	 */
	public static function refusal_codes(): array {
		/**
		 * Filters the error codes that mean another plugin refused an agent call
		 * from inside it, so the log shows it as refused rather than as allowed.
		 *
		 * Calls another plugin stops before they reach Agent Governance are always
		 * logged as refused, whatever their code.
		 *
		 * @param string[] $codes Default site_read_only_mode (a freeze).
		 */
		$codes = (array) apply_filters( 'agent_governance_refusal_codes', array( 'site_read_only_mode' ) );

		return array_values( array_filter( array_map( 'strval', $codes ) ) );
	}

	/**
	 * Opens a frame for a call that may run.
	 *
	 * @param array  $call       The call.
	 * @param array  $identity   The agent.
	 * @param string $decision   allowed or executed.
	 * @param string $reason     Why.
	 * @param int    $request_id Request being executed, or 0.
	 * @return int Frame number.
	 */
	private function open( array $call, array $identity, string $decision, string $reason, int $request_id ): int {
		$replay = $this->context->replay();

		return $this->context->push_frame(
			array(
				'call'       => $call,
				'identity'   => $identity,
				'session'    => $replay ? (string) $replay['session'] : (string) $identity['session'],
				'decision'   => $decision,
				'reason'     => $reason,
				'request_id' => $request_id,
				'start'      => microtime( true ),
			)
		);
	}

	/**
	 * Stores a call for approval and tells the agent to wait.
	 *
	 * @param array $call     The call.
	 * @param array $identity The agent.
	 * @param array $decision The decision.
	 * @return \WP_Error
	 */
	private function hold( array $call, array $identity, array $decision ): \WP_Error {
		$session = '' !== $identity['session'] ? (string) $identity['session'] : $this->log->session_for( (string) $identity['key'], time() );
		$preview = $this->dry_run->preview( $call );

		$id = $this->requests->create(
			array(
				'identity' => $identity['key'],
				'agent'    => $identity['label'],
				'user_id'  => $identity['user_id'],
				'session'  => $session,
				'via'      => $identity['via'],
				'source'   => $call['source'],
				'target'   => $call['target'],
				'method'   => $call['method'],
				'kind'     => $call['kind'],
				// Unredacted, so it can run as sent. Redacted once the request is decided.
				'input'    => (string) wp_json_encode( $call['input'] ),
				'dry_run'  => Redactor::encode( $preview, 'dry_run', false ),
			)
		);

		if ( ! $id ) {
			return new \WP_Error( 'agent_governance_queue_failed', __( 'This call needs approval, but it could not be saved for review. Nothing was changed.', 'agent-governance' ), array( 'status' => 503 ) );
		}

		$this->log->add(
			array(
				'identity'   => $identity['key'],
				'agent'      => $identity['label'],
				'user_id'    => $identity['user_id'],
				'session'    => $session,
				'via'        => $identity['via'],
				'source'     => $call['source'],
				'target'     => $call['target'],
				'method'     => $call['method'],
				'kind'       => $call['kind'],
				'decision'   => 'held',
				'reason'     => self::reason( $decision ),
				'result'     => 'agent_governance_pending',
				'request_id' => $id,
				'ip'         => $this->context->ip(),
				'input'      => Redactor::encode( $call['input'], 'input' ),
			)
		);

		$request = (array) $this->requests->get( $id );

		/**
		 * Fires when an agent call was held for approval.
		 *
		 * @param array $request  The request row: id, created, identity, agent,
		 *                        user_id, session, source, target, method, kind,
		 *                        input (JSON), status and dry_run (JSON).
		 * @param array $call     The call: source, target, method, kind and input.
		 * @param array $decision Why it was held: action, reason and rule.
		 */
		do_action( 'agent_governance_request_held', $request, $call, $decision );

		$this->notifier->request_held( $request );

		$message = sprintf(
			/* translators: 1: request ID, 2: JSON input for the ability. */
			__( 'This call needs approval from a site administrator before it runs. It was saved as request #%1$d. Nothing has changed yet. Poll the agent-governance/get-request ability with %2$s to see when it is approved, rejected or done, and to get its result.', 'agent-governance' ),
			$id,
			(string) wp_json_encode( array( 'id' => $id ) )
		);

		/**
		 * Filters the message an agent gets when its call is held for approval.
		 *
		 * MCP clients only see this message, so keep the request ID in it.
		 *
		 * @param string $message Message.
		 * @param int    $id      Request ID.
		 * @param array  $call    The call.
		 */
		$message = (string) apply_filters( 'agent_governance_pending_message', $message, $id, $call );

		return new \WP_Error(
			'agent_governance_pending',
			$message,
			array(
				'status'     => 202,
				'request_id' => $id,
				'poll'       => array(
					'ability' => Abilities::CATEGORY . '/get-request',
					'input'   => array( 'id' => $id ),
				),
			)
		);
	}

	/**
	 * Refuses a call.
	 *
	 * @param array $call     The call.
	 * @param array $identity The agent.
	 * @param array $decision The decision.
	 * @return \WP_Error
	 */
	private function deny( array $call, array $identity, array $decision ): \WP_Error {
		$reason = (string) $decision['reason'];
		$retry  = 0;

		switch ( $reason ) {
			case 'paused':
				$code    = 'agent_governance_paused';
				$status  = 423;
				$message = __( 'Agent writes are paused on this site. Reading still works. Try again after an administrator resumes them.', 'agent-governance' );
				break;
			case 'rate_limit':
				$code   = 'agent_governance_rate_limited';
				$status = 429;
				$limit  = $this->settings->rate_limit( (string) $identity['key'] );
				$retry  = $this->log->retry_after( (string) $identity['key'] );
				/* translators: 1: calls per minute, 2: seconds to wait. */
				$message = sprintf( _n( 'Too many calls. This agent may make %1$d calls per minute. Try again in %2$d second.', 'Too many calls. This agent may make %1$d calls per minute. Try again in %2$d seconds.', $retry, 'agent-governance' ), $limit, $retry );
				break;
			default:
				$code    = 'agent_governance_denied';
				$status  = 403;
				$message = __( 'A site policy does not allow this agent to make this call.', 'agent-governance' );
		}

		/**
		 * Filters the message an agent gets when its call is refused.
		 *
		 * @param string $message  Message.
		 * @param array  $call     The call.
		 * @param array  $decision Why: action, reason (paused, rate_limit, rule, default or filter) and rule.
		 */
		$message = (string) apply_filters( 'agent_governance_denied_message', $message, $call, $decision );

		$this->log->add(
			array(
				'identity' => $identity['key'],
				'agent'    => $identity['label'],
				'user_id'  => $identity['user_id'],
				'session'  => $identity['session'],
				'via'      => $identity['via'],
				'source'   => $call['source'],
				'target'   => $call['target'],
				'method'   => $call['method'],
				'kind'     => $call['kind'],
				'decision' => 'denied',
				'reason'   => self::reason( $decision ),
				'result'   => $code,
				'ip'       => $this->context->ip(),
				'input'    => Redactor::encode( $call['input'], 'input' ),
			)
		);

		/**
		 * Fires when an agent call was refused: by a rule, the pause or the rate limit.
		 *
		 * @param array $call     The call: source, target, method, kind and input.
		 * @param array $identity The agent: key, label, user_id, via and session.
		 * @param array $decision Why: action, reason and rule.
		 */
		do_action( 'agent_governance_call_denied', $call, $identity, $decision );

		$data = array( 'status' => $status );
		if ( $retry > 0 ) {
			// Sent as the Retry-After header too, by the REST guard.
			$data['retry_after'] = $retry;
		}

		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * A call with every key set.
	 *
	 * @param array $call The call.
	 * @return array
	 */
	private static function normalize_call( array $call ): array {
		return array(
			'source' => (string) ( $call['source'] ?? 'ability' ),
			'target' => (string) ( $call['target'] ?? '' ),
			'method' => strtoupper( (string) ( $call['method'] ?? '' ) ),
			'kind'   => in_array( $call['kind'] ?? '', Settings::KINDS, true ) ? $call['kind'] : 'write',
			'input'  => $call['input'] ?? null,
		);
	}

	/**
	 * The reason column for a decision.
	 *
	 * @param array $decision Decision.
	 * @return string
	 */
	private static function reason( array $decision ): string {
		if ( 'rule' === $decision['reason'] && ! empty( $decision['rule']['id'] ) ) {
			return 'rule:' . $decision['rule']['id'];
		}

		return (string) $decision['reason'];
	}

	/**
	 * Whether a result is a failure.
	 *
	 * @param mixed $result Result.
	 * @return bool
	 */
	private static function failed( $result ): bool {
		if ( is_wp_error( $result ) ) {
			return true;
		}

		return is_object( $result ) && method_exists( $result, 'get_status' ) && (int) $result->get_status() >= 400;
	}

	/**
	 * Short result for the log: ok, an error code, or an HTTP status.
	 *
	 * @param mixed $result Result.
	 * @return string
	 */
	public static function result_code( $result ): string {
		if ( is_wp_error( $result ) ) {
			return (string) $result->get_error_code();
		}

		if ( is_object( $result ) && method_exists( $result, 'get_status' ) ) {
			$status = (int) $result->get_status();
			if ( $status >= 400 ) {
				$data = method_exists( $result, 'get_data' ) ? $result->get_data() : null;
				return is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'http_' . $status;
			}
		}

		return 'ok';
	}
}
