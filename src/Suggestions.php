<?php
/**
 * Allow rules the approvals suggest.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * When an approver has said yes to the same kind of call from the same agent
 * many times and never no, the queue offers an allow rule for it, with what the
 * rule would have done over the last days. Nothing is applied by itself.
 */
final class Suggestions {

	public const TRANSIENT  = 'agent_governance_suggestions';
	public const USER_META  = 'agent_governance_dismissed_suggestions';
	public const STEPS_META = 'agent_governance_steps_dismissed';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The approval queue.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * The simulator.
	 *
	 * @var Simulator
	 */
	private Simulator $simulator;

	/**
	 * Sets up the suggestions.
	 *
	 * @param Settings  $settings  Settings and rules.
	 * @param Requests  $requests  The approval queue.
	 * @param Simulator $simulator The simulator.
	 */
	public function __construct( Settings $settings, Requests $requests, Simulator $simulator ) {
		$this->settings  = $settings;
		$this->requests  = $requests;
		$this->simulator = $simulator;
	}

	/**
	 * Approvals in a row, with no rejection, before a rule is suggested.
	 *
	 * @return int
	 */
	public static function threshold(): int {
		/**
		 * Filters how many times the same kind of call from the same agent must have
		 * been approved, and never rejected, before an allow rule is suggested.
		 *
		 * @param int $times Default 5. 2 to 1000.
		 */
		return max( 2, min( 1000, (int) apply_filters( 'agent_governance_suggestion_threshold', 5 ) ) );
	}

	/**
	 * Most suggestions shown at once.
	 *
	 * @return int
	 */
	public static function limit(): int {
		/**
		 * Filters how many rule suggestions the queue shows at once.
		 *
		 * @param int $limit Default 3. 1 to 10.
		 */
		return max( 1, min( 10, (int) apply_filters( 'agent_governance_suggestions_limit', 3 ) ) );
	}

	/**
	 * The pattern for a kind of call: ability names as they are, REST routes with
	 * ID segments turned into "*", and the method in front.
	 *
	 * @param string $source ability or rest.
	 * @param string $method HTTP method.
	 * @param string $target Ability name or REST route.
	 * @return string
	 */
	public static function pattern_for( string $source, string $method, string $target ): string {
		if ( 'rest' !== $source ) {
			return $target;
		}

		return trim( strtoupper( $method ) . ' ' . (string) preg_replace( '#/\d+(?=/|$)#', '/*', $target ) );
	}

	/**
	 * A short ID for a suggestion, so a form can name it without sending the rule.
	 *
	 * @param string $identity Agent key.
	 * @param string $pattern  Pattern.
	 * @return string
	 */
	public static function key( string $identity, string $pattern ): string {
		return substr( md5( $identity . '|' . $pattern ), 0, 12 );
	}

	/**
	 * The suggestions, from the approvals of the last days. Cached for a few
	 * minutes and cleared when rules change.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array[] Each with key, identity, agent, pattern, approved and rule.
	 */
	public function all( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$days      = Simulator::days();
		$groups    = array();
		$threshold = self::threshold();

		foreach ( $this->requests->decisions_since( time() - $days * DAY_IN_SECONDS ) as $row ) {
			$pattern = self::pattern_for( (string) $row['source'], (string) $row['method'], (string) $row['target'] );
			$key     = self::key( (string) $row['identity'], $pattern );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'key'      => $key,
					'identity' => (string) $row['identity'],
					'agent'    => Log::identity_label( $row ),
					'pattern'  => $pattern,
					'source'   => (string) $row['source'],
					'method'   => (string) $row['method'],
					'target'   => (string) $row['target'],
					'user_id'  => (int) $row['user_id'],
					'kind'     => (string) $row['kind'],
					'approved' => 0,
					'rejected' => 0,
				);
			}
			$groups[ $key ][ 'rejected' === $row['status'] ? 'rejected' : 'approved' ] += (int) $row['n'];
		}

		$live  = $this->simulator->live();
		$found = array();
		foreach ( $groups as $group ) {
			if ( $group['approved'] < $threshold || $group['rejected'] > 0 ) {
				continue;
			}

			// Only a call that still waits for approval needs a rule.
			$identity = array(
				'key'     => $group['identity'],
				'user_id' => $group['user_id'],
			);
			$now      = Policy::resolve(
				array(
					'source' => $group['source'],
					'target' => $group['target'],
					'method' => $group['method'],
					'kind'   => in_array( $group['kind'], Settings::KINDS, true ) ? $group['kind'] : 'write',
				),
				$identity,
				$live,
				array(
					'paused' => false,
					'recent' => 0,
				)
			);
			if ( Settings::REQUIRE_APPROVAL !== $now['action'] ) {
				continue;
			}

			$subject       = 0 === strpos( $group['identity'], 'app:' ) ? substr( $group['identity'], 4 ) : $group['identity'];
			$group['rule'] = Settings::sanitize_rule(
				array(
					'scope'   => 'agent',
					'subject' => $subject,
					'pattern' => $group['pattern'],
					'action'  => Settings::ALLOW,
				)
			);
			if ( $group['rule'] ) {
				$found[] = $group;
			}
		}

		/**
		 * Filters the allow rules suggested from past approvals.
		 *
		 * @param array[] $suggestions Each with key, identity, agent, pattern, approved, rejected and rule (the rule it would add).
		 */
		$found = (array) apply_filters( 'agent_governance_suggestions', $found );
		$found = array_slice( array_values( array_filter( $found, 'is_array' ) ), 0, self::limit() + 20 );

		$found = $this->with_results( $found );
		set_transient( self::TRANSIENT, $found, 10 * MINUTE_IN_SECONDS );

		return $found;
	}

	/**
	 * Adds what each rule would have done over the last days, from one replay.
	 *
	 * @param array[] $found Suggestions.
	 * @return array[]
	 */
	private function with_results( array $found ): array {
		if ( ! $found ) {
			return $found;
		}

		$live     = $this->simulator->live();
		$policies = array( 'live' => $live );
		foreach ( $found as $index => $suggestion ) {
			// First in line, so an older rule for the same scope cannot shadow it.
			$policies[ 's' . $index ] = array_merge( $live, array( 'rules' => array_merge( array( $suggestion['rule'] ), $live['rules'] ) ) );
		}

		$result = $this->simulator->run( $policies );

		foreach ( $found as $index => $suggestion ) {
			$changes = $result['changes'][ 's' . $index ] ?? array();
			$freed   = 0;
			$other   = 0;
			foreach ( $changes as $change ) {
				if ( 'require_approval' === $change['from'] && 'allow' === $change['to'] ) {
					$freed += $change['count'];
				} else {
					$other += $change['count'];
				}
			}

			$found[ $index ]['result'] = array(
				'days'  => $result['days'],
				'freed' => $freed,
				'other' => $other,
			);
		}

		return $found;
	}

	/**
	 * One suggestion by key, worked out fresh.
	 *
	 * @param string $key Suggestion key.
	 * @return array|null
	 */
	public function find( string $key ): ?array {
		foreach ( $this->all( true ) as $suggestion ) {
			if ( $suggestion['key'] === $key ) {
				return $suggestion;
			}
		}

		return null;
	}

	/**
	 * The suggestions a user has not dismissed.
	 *
	 * @param int $user_id User.
	 * @return array[]
	 */
	public function for_user( int $user_id ): array {
		$dismissed = array_map( 'strval', (array) get_user_meta( $user_id, self::USER_META, true ) );

		$list = array_filter(
			$this->all(),
			static function ( array $suggestion ) use ( $dismissed ): bool {
				return ! in_array( $suggestion['key'], $dismissed, true );
			}
		);

		return array_slice( array_values( $list ), 0, self::limit() );
	}

	/**
	 * Adds the suggested rule, first in the list.
	 *
	 * @param array $suggestion A suggestion from find().
	 * @param int   $user_id    Who accepted it.
	 * @return array|null The saved rule, null when it could not be saved.
	 */
	public function accept( array $suggestion, int $user_id ): ?array {
		$rule = Settings::sanitize_rule( $suggestion['rule'] );
		if ( ! $rule ) {
			return null;
		}

		$this->settings->save_rules( array_merge( array( $rule ), $this->settings->rules() ) );

		/**
		 * Fires when someone accepts a suggested allow rule.
		 *
		 * @param array $rule       The rule that was added.
		 * @param array $suggestion The suggestion: agent, pattern, approved and result.
		 * @param int   $user_id    Who accepted it.
		 */
		do_action( 'agent_governance_suggestion_accepted', $rule, $suggestion, $user_id );

		return $rule;
	}

	/**
	 * Hides a suggestion from one user.
	 *
	 * @param string $key     Suggestion key.
	 * @param int    $user_id User.
	 */
	public function dismiss( string $key, int $user_id ): void {
		$dismissed   = array_map( 'strval', (array) get_user_meta( $user_id, self::USER_META, true ) );
		$dismissed[] = $key;
		update_user_meta( $user_id, self::USER_META, array_slice( array_values( array_unique( array_filter( $dismissed ) ) ), -200 ) );

		/**
		 * Fires when someone dismisses a suggested rule.
		 *
		 * @param string $key     Suggestion key.
		 * @param int    $user_id User.
		 */
		do_action( 'agent_governance_suggestion_dismissed', $key, $user_id );
	}

	/**
	 * Forgets the cached suggestions.
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}
}
