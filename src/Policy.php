<?php
/**
 * Decides what happens to each agent call.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * The policy engine.
 *
 * In order: Agent Governance's own abilities always run (their permission
 * checks guard them); the pause refuses every write; the rate limit refuses
 * calls over the limit; then the first matching rule of the most specific
 * scope (agent, then user, then role, then every agent); then the default for
 * the kind of call. The agent_governance_policy filter has the last word,
 * except that it cannot let a write through while agents are paused.
 */
final class Policy {

	/**
	 * Settings and rules.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The log, for the rate limit.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * Sets up the engine.
	 *
	 * @param Settings $settings Settings and rules.
	 * @param Log      $log      The log, for the rate limit.
	 */
	public function __construct( Settings $settings, Log $log ) {
		$this->settings = $settings;
		$this->log      = $log;
	}

	/**
	 * Decides a call.
	 *
	 * @param array $call     source (ability or rest), target, method, kind (readonly, write or destructive), input.
	 * @param array $identity key, label, user_id, via, session.
	 * @return array{action: string, reason: string, rule: array|null}
	 */
	public function decide( array $call, array $identity ): array {
		$decision = $this->evaluate( $call, $identity );

		/**
		 * Filters the final decision for an agent call.
		 *
		 * @param array $decision action (allow, deny or require_approval), reason
		 *                        (own, paused, rate_limit, rule or default) and rule
		 *                        (the matching rule, or null).
		 * @param array $call     source (ability or rest), target (ability name or
		 *                        REST route), method, kind (readonly, write or
		 *                        destructive) and input.
		 * @param array $identity The agent: key, label, user_id, via and session.
		 */
		$filtered = (array) apply_filters( 'agent_governance_policy', $decision, $call, $identity );

		if ( isset( $filtered['action'] ) && in_array( $filtered['action'], Settings::ACTIONS, true ) ) {
			$decision = array(
				'action' => $filtered['action'],
				'reason' => isset( $filtered['reason'] ) ? sanitize_key( (string) $filtered['reason'] ) : 'filter',
				'rule'   => isset( $filtered['rule'] ) && is_array( $filtered['rule'] ) ? $filtered['rule'] : null,
			);
		}

		// The pause is the emergency stop: nothing may write while it is on.
		if ( Settings::ALLOW === $decision['action'] && 'readonly' !== $call['kind'] && ! self::is_own( (string) $call['target'] ) && $this->settings->is_paused() ) {
			$decision = array(
				'action' => Settings::DENY,
				'reason' => 'paused',
				'rule'   => null,
			);
		}

		return $decision;
	}

	/**
	 * The decision before the filter.
	 *
	 * @param array $call     The call.
	 * @param array $identity The agent.
	 * @return array{action: string, reason: string, rule: array|null}
	 */
	private function evaluate( array $call, array $identity ): array {
		if ( self::is_own( (string) $call['target'] ) ) {
			return self::decision( Settings::ALLOW, 'own' );
		}

		if ( 'readonly' !== $call['kind'] && $this->settings->is_paused() ) {
			return self::decision( Settings::DENY, 'paused' );
		}

		$limit = $this->settings->rate_limit( (string) $identity['key'] );
		if ( $limit > 0 && $this->log->recent_calls( (string) $identity['key'] ) >= $limit ) {
			return self::decision( Settings::DENY, 'rate_limit' );
		}

		$rule = $this->match( $call, $identity, $this->settings->all_rules() );
		if ( $rule ) {
			return self::decision( $rule['action'], 'rule', $rule );
		}

		$defaults = $this->settings->default_rules();
		$kind     = in_array( $call['kind'], Settings::KINDS, true ) ? $call['kind'] : 'write';

		return self::decision( $defaults[ $kind ], 'default' );
	}

	/**
	 * The first matching rule of the most specific scope.
	 *
	 * @param array   $call     The call.
	 * @param array   $identity The agent.
	 * @param array[] $rules    Rules.
	 * @return array|null
	 */
	public function match( array $call, array $identity, array $rules ): ?array {
		foreach ( Settings::SCOPES as $scope ) {
			foreach ( $rules as $rule ) {
				if ( $rule['scope'] === $scope && self::applies_to( $rule, $identity ) && self::pattern_matches( (string) $rule['pattern'], (string) $call['target'], (string) $call['method'] ) ) {
					return $rule;
				}
			}
		}

		return null;
	}

	/**
	 * Whether a rule's scope covers the agent.
	 *
	 * @param array $rule     Rule.
	 * @param array $identity The agent.
	 * @return bool
	 */
	public static function applies_to( array $rule, array $identity ): bool {
		$subject = (string) $rule['subject'];
		$user_id = (int) $identity['user_id'];

		switch ( $rule['scope'] ) {
			case 'agent':
				return $identity['key'] === $subject || 'app:' . $subject === $identity['key'];
			case 'user':
				if ( ctype_digit( $subject ) ) {
					return (int) $subject === $user_id;
				}
				$user = $user_id ? get_userdata( $user_id ) : false;
				return $user && ( $user->user_login === $subject || $user->user_email === $subject );
			case 'role':
				$user = $user_id ? get_userdata( $user_id ) : false;
				return $user && in_array( $subject, (array) $user->roles, true );
			default:
				return true;
		}
	}

	/**
	 * Whether a rule pattern matches a target.
	 *
	 * "*" matches anything, including slashes, "?" one character. A pattern may
	 * start with HTTP methods, such as "DELETE /wp/v2/*" or "POST|PUT /wp/v2/users*",
	 * which then only match REST calls with that method.
	 *
	 * @param string $pattern Pattern.
	 * @param string $target  Ability name or REST route.
	 * @param string $method  HTTP method, empty for abilities run from PHP.
	 * @return bool
	 */
	public static function pattern_matches( string $pattern, string $target, string $method ): bool {
		$pattern = trim( $pattern );

		if ( preg_match( '/^([A-Za-z]+(?:\|[A-Za-z]+)*)\s+(\S.*)$/', $pattern, $parts ) ) {
			$methods = explode( '|', strtoupper( $parts[1] ) );
			if ( ! in_array( strtoupper( $method ), $methods, true ) ) {
				return false;
			}
			$pattern = $parts[2];
		}

		$regex = '#^' . str_replace( array( '\*', '\?' ), array( '.*', '.' ), preg_quote( $pattern, '#' ) ) . '$#i';

		return (bool) preg_match( $regex, $target );
	}

	/**
	 * Whether a target is one of Agent Governance's own abilities.
	 *
	 * @param string $target Ability name or route.
	 * @return bool
	 */
	public static function is_own( string $target ): bool {
		return 0 === strpos( $target, Abilities::CATEGORY . '/' );
	}

	/**
	 * Builds a decision.
	 *
	 * @param string     $action Action.
	 * @param string     $reason Why.
	 * @param array|null $rule   Matching rule.
	 * @return array{action: string, reason: string, rule: array|null}
	 */
	private static function decision( string $action, string $reason, ?array $rule = null ): array {
		return array(
			'action' => $action,
			'reason' => $reason,
			'rule'   => $rule,
		);
	}
}
