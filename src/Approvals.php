<?php
/**
 * Approves, rejects, runs and expires held requests.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * An approved request runs right away, as the user who made it: the current
 * user is switched to them for the call and switched back after. Abilities run
 * through the Abilities API again, so their input and permission checks still
 * apply; REST calls are sent again with rest_do_request(). The result is stored
 * on the request, where the agent picks it up with agent-governance/get-request.
 */
final class Approvals {

	/**
	 * The approval queue.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * Request signals.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Builds the REST request to send again.
	 *
	 * @var callable
	 */
	private $make_request;

	/**
	 * Sets up approvals.
	 *
	 * @param Requests      $requests     The approval queue.
	 * @param Context       $context      Request signals.
	 * @param callable|null $make_request Builds a WP_REST_Request from a method and route. For tests.
	 */
	public function __construct( Requests $requests, Context $context, ?callable $make_request = null ) {
		$this->requests     = $requests;
		$this->context      = $context;
		$this->make_request = $make_request ?? static function ( string $method, string $route ) {
			return new \WP_REST_Request( $method, $route );
		};
	}

	/**
	 * Approves a request and runs it.
	 *
	 * @param int $id          Request ID.
	 * @param int $approver_id Who approved.
	 * @return array|\WP_Error The updated request.
	 */
	public function approve( int $id, int $approver_id ) {
		$request = $this->decidable( $id, $approver_id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		// The call runs as the agent's user, so the credential that made it has to still be good.
		$gone = self::credential_error( $request );
		if ( $gone ) {
			$this->expire( $request, $gone->get_error_message() );
			return $gone;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		if ( ! $this->requests->update(
			$id,
			array(
				'status'     => Requests::APPROVED,
				'decided_by' => $approver_id,
				'decided_at' => $now,
			),
			Requests::PENDING
		) ) {
			return new \WP_Error( 'agent_governance_already_decided', __( 'Someone else decided this request first.', 'agent-governance' ), array( 'status' => 409 ) );
		}

		$request = array_merge(
			$request,
			array(
				'status'     => Requests::APPROVED,
				'decided_by' => $approver_id,
				'decided_at' => $now,
			)
		);

		/**
		 * Fires when a held request was approved, before it runs.
		 *
		 * @param array $request     The request row.
		 * @param int   $approver_id Who approved it.
		 */
		do_action( 'agent_governance_request_approved', $request, $approver_id );

		$result = $this->execute( $request );
		$status = self::failed( $result ) ? Requests::FAILED : Requests::EXECUTED;

		$this->requests->update(
			$id,
			array(
				'status' => $status,
				'result' => Redactor::encode( self::result_data( $result ), 'result' ),
				'input'  => Redactor::encode( json_decode( (string) $request['input'], true ), 'input' ),
			)
		);

		$request = (array) $this->requests->get( $id );

		/**
		 * Fires after an approved request ran.
		 *
		 * @param array $request The request row, with status executed or failed and the result.
		 * @param mixed $result  What the ability or REST route returned.
		 */
		do_action( 'agent_governance_request_executed', $request, $result );

		return $request;
	}

	/**
	 * Rejects a request.
	 *
	 * @param int    $id          Request ID.
	 * @param int    $approver_id Who rejected.
	 * @param string $reason      Why, shown to the agent.
	 * @return array|\WP_Error The updated request.
	 */
	public function reject( int $id, int $approver_id, string $reason = '' ) {
		$request = $this->decidable( $id, $approver_id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$reason = sanitize_textarea_field( $reason );
		if ( ! $this->requests->update(
			$id,
			array(
				'status'     => Requests::REJECTED,
				'decided_by' => $approver_id,
				'decided_at' => gmdate( 'Y-m-d H:i:s' ),
				'reason'     => $reason,
				'input'      => Redactor::encode( json_decode( (string) $request['input'], true ), 'input' ),
			),
			Requests::PENDING
		) ) {
			return new \WP_Error( 'agent_governance_already_decided', __( 'Someone else decided this request first.', 'agent-governance' ), array( 'status' => 409 ) );
		}

		$request = (array) $this->requests->get( $id );

		/**
		 * Fires when a held request was rejected.
		 *
		 * @param array  $request     The request row.
		 * @param int    $approver_id Who rejected it.
		 * @param string $reason      Why.
		 */
		do_action( 'agent_governance_request_rejected', $request, $approver_id, $reason );

		return $request;
	}

	/**
	 * Expires requests that waited too long.
	 *
	 * @return int How many expired.
	 */
	public function expire_overdue(): int {
		$count = 0;
		foreach ( $this->requests->overdue() as $request ) {
			if ( $this->expire( $request ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Expires the requests a person's application passwords made that still wait.
	 *
	 * For when that person reports a sign-in that was not theirs, or signs out
	 * everywhere: what an agent asked with their credentials must not run
	 * after someone clicks Approve.
	 *
	 * @param int    $user_id User whose application passwords made the requests.
	 * @param string $reason  Why, shown on each request.
	 * @return int How many expired.
	 */
	public function expire_for_user( int $user_id, string $reason ): int {
		$count = 0;
		foreach ( $this->requests->pending_for_app_passwords( $user_id ) as $request ) {
			if ( $this->expire( $request, $reason ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Why a request cannot run any more because of who made it, if that is so.
	 *
	 * An application password that was revoked since the call was held, or a
	 * user who is gone, ends the request: nothing may run with those credentials.
	 *
	 * @param array $request The request row.
	 * @return \WP_Error|null Null when the credentials are still good.
	 */
	public static function credential_error( array $request ): ?\WP_Error {
		$user_id = (int) $request['user_id'];
		if ( $user_id > 0 && ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'agent_governance_user_gone', __( 'The user this agent signed in as no longer exists, so the request cannot run.', 'agent-governance' ), array( 'status' => 410 ) );
		}

		$identity = (string) $request['identity'];
		if ( 0 !== strpos( $identity, 'app:' ) ) {
			return null;
		}

		$uuid = substr( $identity, 4 );
		$item = class_exists( 'WP_Application_Passwords' ) ? \WP_Application_Passwords::get_user_application_password( $user_id, $uuid ) : null;
		if ( is_array( $item ) ) {
			return null;
		}

		return new \WP_Error( 'agent_governance_credential_revoked', __( 'The application password this agent used was revoked, so the request cannot run. The agent has to ask again with a valid one.', 'agent-governance' ), array( 'status' => 410 ) );
	}

	/**
	 * Expires one request.
	 *
	 * @param array  $request The request row.
	 * @param string $reason  Why, when it did not simply wait too long.
	 * @return bool Whether it was still pending.
	 */
	public function expire( array $request, string $reason = '' ): bool {
		$changes = array(
			'status'     => Requests::EXPIRED,
			'decided_at' => gmdate( 'Y-m-d H:i:s' ),
			'input'      => Redactor::encode( json_decode( (string) $request['input'], true ), 'input' ),
		);
		if ( '' !== $reason ) {
			$changes['reason'] = $reason;
		}

		$changed = $this->requests->update( (int) $request['id'], $changes, Requests::PENDING );

		if ( $changed ) {
			/**
			 * Fires when a held request expired without a decision.
			 *
			 * @param array  $request The request row, as it was while pending.
			 * @param string $reason  Why, when it was not just the waiting time. Empty otherwise.
			 */
			do_action( 'agent_governance_request_expired', $request, $reason );
		}

		return $changed;
	}

	/**
	 * A request that can still be decided.
	 *
	 * @param int $id          Request ID.
	 * @param int $approver_id Who is deciding.
	 * @return array|\WP_Error
	 */
	private function decidable( int $id, int $approver_id ) {
		$request = $this->requests->get( $id );
		if ( ! $request ) {
			return new \WP_Error( 'agent_governance_not_found', __( 'No request with that ID.', 'agent-governance' ), array( 'status' => 404 ) );
		}

		// Having the approver capability is not enough: the person has to be able to change what the call touches.
		if ( ! Review::can( $request, $approver_id ) ) {
			return new \WP_Error( 'agent_governance_forbidden', __( 'This call touches something you are not allowed to change, so you cannot decide it.', 'agent-governance' ), array( 'status' => 403 ) );
		}

		if ( Requests::is_overdue( $request ) ) {
			$this->expire( $request );
			return new \WP_Error( 'agent_governance_expired', __( 'This request waited too long and expired. The agent has to ask again.', 'agent-governance' ), array( 'status' => 410 ) );
		}

		if ( Requests::PENDING !== $request['status'] ) {
			return new \WP_Error( 'agent_governance_already_decided', __( 'This request was already decided.', 'agent-governance' ), array( 'status' => 409 ) );
		}

		return $request;
	}

	/**
	 * Runs an approved request as the user who made it.
	 *
	 * @param array $request The request row.
	 * @return mixed What the ability or route returned.
	 */
	public function execute( array $request ) {
		// Last look before anything runs: the credential may have been revoked while the request was approved.
		$gone = self::credential_error( $request );
		if ( $gone ) {
			return $gone;
		}

		$input    = json_decode( (string) $request['input'], true );
		$previous = get_current_user_id();

		wp_set_current_user( (int) $request['user_id'] );
		$this->context->begin_replay( $request );

		try {
			if ( 'rest' === $request['source'] ) {
				$result = $this->run_rest( $request, is_array( $input ) ? $input : array() );
			} else {
				$result = $this->run_ability( (string) $request['target'], $input );
			}
		} catch ( \Throwable $error ) {
			$result = new \WP_Error( 'agent_governance_exception', $error->getMessage() );
		} finally {
			$this->context->end_replay();
			wp_set_current_user( $previous );
		}

		return $result;
	}

	/**
	 * Runs an ability.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input Input.
	 * @return mixed
	 */
	private function run_ability( string $name, $input ) {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			/* translators: %s: ability name. */
			return new \WP_Error( 'agent_governance_ability_missing', sprintf( __( 'The ability %s is not registered any more.', 'agent-governance' ), $name ) );
		}

		return $ability->execute( $input );
	}

	/**
	 * Sends a REST request again.
	 *
	 * @param array $request The request row.
	 * @param array $input   query and body.
	 * @return \WP_REST_Response
	 */
	private function run_rest( array $request, array $input ) {
		$rest = call_user_func( $this->make_request, (string) $request['method'], (string) $request['target'] );
		$rest->set_query_params( (array) ( $input['query'] ?? array() ) );
		$body = (array) ( $input['body'] ?? array() );
		if ( $body ) {
			$rest->set_header( 'content-type', 'application/json' );
			$rest->set_body( (string) wp_json_encode( $body ) );
		}

		$response = rest_do_request( $rest );

		// As when core preloads a request: lets the post-dispatch filters see it, including the log.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter.
		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), rest_get_server(), $rest );
	}

	/**
	 * Whether a result is a failure.
	 *
	 * @param mixed $result Result.
	 * @return bool
	 */
	public static function failed( $result ): bool {
		if ( is_wp_error( $result ) ) {
			return true;
		}

		return is_object( $result ) && method_exists( $result, 'get_status' ) && (int) $result->get_status() >= 400;
	}

	/**
	 * A result as plain data for storage.
	 *
	 * @param mixed $result Result.
	 * @return mixed
	 */
	public static function result_data( $result ) {
		if ( is_wp_error( $result ) ) {
			return array(
				'code'    => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'data'    => $result->get_error_data(),
			);
		}

		if ( is_object( $result ) && method_exists( $result, 'get_status' ) && method_exists( $result, 'get_data' ) ) {
			return array(
				'status' => (int) $result->get_status(),
				'data'   => $result->get_data(),
			);
		}

		return $result;
	}
}
