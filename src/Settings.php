<?php
/**
 * Saved settings and policy rules.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and saves the settings and rules, with every number clamped and every
 * value checked, and applies the filters that let code change them.
 */
final class Settings {

	public const OPTION       = 'agent_governance_settings';
	public const RULES_OPTION = 'agent_governance_rules';

	public const ALLOW            = 'allow';
	public const DENY             = 'deny';
	public const REQUIRE_APPROVAL = 'require_approval';
	public const ACTIONS          = array( self::ALLOW, self::DENY, self::REQUIRE_APPROVAL );

	public const SCOPES = array( 'agent', 'user', 'role', 'default' );
	public const KINDS  = array( 'readonly', 'write', 'destructive' );

	/**
	 * Settings for this request, after sanitising.
	 *
	 * @var array|null
	 */
	private ?array $cache = null;

	/**
	 * The defaults.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'paused'         => false,
			'paused_by'      => 0,
			'paused_at'      => 0,
			'rate_limit'     => 60,
			'retention_days' => 30,
			'email'          => true,
			'notice'         => true,
			'defaults'       => array(
				'readonly'    => self::ALLOW,
				'write'       => self::ALLOW,
				'destructive' => self::REQUIRE_APPROVAL,
			),
		);
	}

	/**
	 * Every setting, sanitised.
	 *
	 * @return array
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$this->cache = self::sanitize( get_option( self::OPTION, array() ) );
		}

		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public function get( string $key ) {
		return $this->all()[ $key ] ?? null;
	}

	/**
	 * Saves changed settings.
	 *
	 * @param array $changes Settings to change.
	 * @return array The saved settings.
	 */
	public function update( array $changes ): array {
		$old      = $this->all();
		$settings = self::sanitize( array_replace( $old, $changes ) );
		update_option( self::OPTION, $settings, true );
		$this->cache = $settings;

		/**
		 * Fires after the settings were saved, from the screen, WP-CLI or code.
		 *
		 * @param array $settings New settings.
		 * @param array $old      Previous settings.
		 */
		do_action( 'agent_governance_settings_saved', $settings, $old );

		return $settings;
	}

	/**
	 * Cleans up stored settings, falling back to the default for anything invalid.
	 *
	 * @param mixed $value Stored or submitted settings.
	 * @return array
	 */
	public static function sanitize( $value ): array {
		$defaults = self::defaults();
		$value    = is_array( $value ) ? $value : array();

		$clean = array(
			'paused'         => ! empty( $value['paused'] ),
			'paused_by'      => max( 0, (int) ( $value['paused_by'] ?? 0 ) ),
			'paused_at'      => max( 0, (int) ( $value['paused_at'] ?? 0 ) ),
			'rate_limit'     => isset( $value['rate_limit'] ) ? max( 0, min( 100000, (int) $value['rate_limit'] ) ) : $defaults['rate_limit'],
			'retention_days' => isset( $value['retention_days'] ) ? max( 1, min( 3650, (int) $value['retention_days'] ) ) : $defaults['retention_days'],
			'email'          => array_key_exists( 'email', $value ) ? (bool) $value['email'] : $defaults['email'],
			'notice'         => array_key_exists( 'notice', $value ) ? (bool) $value['notice'] : $defaults['notice'],
			'defaults'       => $defaults['defaults'],
		);

		$given = isset( $value['defaults'] ) && is_array( $value['defaults'] ) ? $value['defaults'] : array();
		foreach ( self::KINDS as $kind ) {
			if ( isset( $given[ $kind ] ) && in_array( $given[ $kind ], self::ACTIONS, true ) ) {
				$clean['defaults'][ $kind ] = $given[ $kind ];
			}
		}

		return $clean;
	}

	/**
	 * What happens to a call that no rule matches, per kind of call.
	 *
	 * @return array{readonly: string, write: string, destructive: string}
	 */
	public function default_rules(): array {
		$saved = $this->get( 'defaults' );

		/**
		 * Filters what happens to an agent call that no rule matches.
		 *
		 * Keys are the kinds of call: readonly (abilities marked read-only, REST
		 * GET), write (other abilities, REST POST, PUT and PATCH) and destructive
		 * (abilities marked destructive, REST DELETE). Values are allow, deny or
		 * require_approval. An invalid value keeps the saved setting.
		 *
		 * @param array $defaults Action per kind.
		 */
		$filtered = (array) apply_filters( 'agent_governance_default_rules', $saved );

		foreach ( self::KINDS as $kind ) {
			if ( isset( $filtered[ $kind ] ) && in_array( $filtered[ $kind ], self::ACTIONS, true ) ) {
				$saved[ $kind ] = $filtered[ $kind ];
			}
		}

		return $saved;
	}

	/**
	 * Calls per minute one agent may make. 0 means no limit.
	 *
	 * @param string $identity Agent identity key.
	 * @return int
	 */
	public function rate_limit( string $identity ): int {
		/**
		 * Filters the calls per minute an agent may make. 0 turns the limit off.
		 *
		 * @param int    $limit    Calls per minute. Default from the setting, 60.
		 * @param string $identity Agent identity, such as "app:<uuid>" or "user:3".
		 */
		return max( 0, min( 100000, (int) apply_filters( 'agent_governance_rate_limit', (int) $this->get( 'rate_limit' ), $identity ) ) );
	}

	/**
	 * Days the log and decided requests are kept.
	 *
	 * @return int
	 */
	public function retention_days(): int {
		/**
		 * Filters the days log entries and decided requests are kept.
		 *
		 * @param int $days Days, 1 to 3650. Default from the setting, 30.
		 */
		return max( 1, min( 3650, (int) apply_filters( 'agent_governance_retention_days', (int) $this->get( 'retention_days' ) ) ) );
	}

	/**
	 * Seconds a held request waits for a decision before it expires.
	 *
	 * @return int
	 */
	public static function request_ttl(): int {
		/**
		 * Filters how long a held request waits for a decision, in seconds.
		 *
		 * @param int $seconds Default one day. 60 to 30 days.
		 */
		return max( MINUTE_IN_SECONDS, min( 30 * DAY_IN_SECONDS, (int) apply_filters( 'agent_governance_request_ttl', DAY_IN_SECONDS ) ) );
	}

	/**
	 * Most bytes of JSON stored for one input, result or preview.
	 *
	 * @param string $context input, result or dry_run.
	 * @return int
	 */
	public static function payload_limit( string $context ): int {
		$default = 'dry_run' === $context ? 65536 : 16384;

		/**
		 * Filters the most bytes stored for one input, result or preview.
		 *
		 * Bigger values are cut to a preview and marked as truncated.
		 *
		 * @param int    $bytes   Default 16384, or 65536 for a dry-run preview.
		 * @param string $context input, result or dry_run.
		 */
		return max( 512, min( 4194304, (int) apply_filters( 'agent_governance_payload_limit', $default, $context ) ) );
	}

	/**
	 * Whether agent writes are paused.
	 *
	 * @return bool
	 */
	public function is_paused(): bool {
		return (bool) $this->get( 'paused' );
	}

	/**
	 * Pauses every agent write.
	 *
	 * @param int $user_id Who paused.
	 * @return bool False when already paused.
	 */
	public function pause( int $user_id ): bool {
		if ( $this->is_paused() ) {
			return false;
		}

		$this->update(
			array(
				'paused'    => true,
				'paused_by' => $user_id,
				'paused_at' => time(),
			)
		);

		/**
		 * Fires when agent writes are paused.
		 *
		 * @param int $user_id Who paused them, 0 for code or WP-CLI without a user.
		 */
		do_action( 'agent_governance_paused', $user_id );

		return true;
	}

	/**
	 * Lets agent writes through again.
	 *
	 * @param int $user_id Who resumed.
	 * @return bool False when not paused.
	 */
	public function resume( int $user_id ): bool {
		if ( ! $this->is_paused() ) {
			return false;
		}

		$this->update(
			array(
				'paused'    => false,
				'paused_by' => 0,
				'paused_at' => 0,
			)
		);

		/**
		 * Fires when agent writes are let through again.
		 *
		 * @param int $user_id Who resumed them, 0 for code or WP-CLI without a user.
		 */
		do_action( 'agent_governance_resumed', $user_id );

		return true;
	}

	/**
	 * Rules saved on the Policies screen or with WP-CLI.
	 *
	 * @return array[]
	 */
	public function rules(): array {
		$stored = get_option( self::RULES_OPTION, array() );

		return array_values( array_filter( array_map( array( self::class, 'sanitize_rule' ), is_array( $stored ) ? $stored : array() ) ) );
	}

	/**
	 * Saved rules plus rules defined in code.
	 *
	 * @return array[]
	 */
	public function all_rules(): array {
		/**
		 * Filters the policy rules, so they can be defined in code.
		 *
		 * Each rule is an array with scope (agent, user, role or default), subject
		 * (the application password UUID, user ID or login, or role name; empty
		 * for default), pattern (a glob on the ability name or REST route,
		 * optionally starting with an HTTP method such as "DELETE /wp/v2/*") and
		 * action (allow, deny or require_approval). Invalid rules are dropped.
		 *
		 * @param array[] $rules Saved rules.
		 */
		$rules = (array) apply_filters( 'agent_governance_rules', $this->rules() );

		return array_values( array_filter( array_map( array( self::class, 'sanitize_rule' ), $rules ) ) );
	}

	/**
	 * Replaces the saved rules.
	 *
	 * @param array $rules Rules.
	 * @return array[] The saved rules.
	 */
	public function save_rules( array $rules ): array {
		$old   = $this->rules();
		$clean = array();
		foreach ( $rules as $rule ) {
			$rule = self::sanitize_rule( $rule );
			if ( $rule ) {
				$clean[] = $rule;
			}
		}

		update_option( self::RULES_OPTION, $clean, true );

		/**
		 * Fires after the policy rules were saved, from the screen or WP-CLI.
		 *
		 * @param array[] $rules New rules.
		 * @param array[] $old   Previous rules.
		 */
		do_action( 'agent_governance_rules_saved', $clean, $old );

		return $clean;
	}

	/**
	 * Adds one rule at the end.
	 *
	 * @param array $rule Rule.
	 * @return array|null The saved rule, or null when it is invalid.
	 */
	public function add_rule( array $rule ): ?array {
		$rule = self::sanitize_rule( $rule );
		if ( ! $rule ) {
			return null;
		}

		$rules   = $this->rules();
		$rules[] = $rule;
		$this->save_rules( $rules );

		return $rule;
	}

	/**
	 * Removes one rule.
	 *
	 * @param string $id Rule ID.
	 * @return bool Whether it existed.
	 */
	public function delete_rule( string $id ): bool {
		$rules = $this->rules();
		$kept  = array_values(
			array_filter(
				$rules,
				static function ( array $rule ) use ( $id ): bool {
					return $rule['id'] !== $id;
				}
			)
		);

		if ( count( $kept ) === count( $rules ) ) {
			return false;
		}

		$this->save_rules( $kept );

		return true;
	}

	/**
	 * Cleans up one rule.
	 *
	 * @param mixed $rule Rule.
	 * @return array|null Null when the rule is unusable.
	 */
	public static function sanitize_rule( $rule ): ?array {
		if ( ! is_array( $rule ) ) {
			return null;
		}

		$scope   = isset( $rule['scope'] ) && in_array( $rule['scope'], self::SCOPES, true ) ? $rule['scope'] : 'default';
		$action  = isset( $rule['action'] ) && in_array( $rule['action'], self::ACTIONS, true ) ? $rule['action'] : '';
		$pattern = trim( preg_replace( '/\s+/', ' ', (string) ( $rule['pattern'] ?? '' ) ) );
		$subject = 'default' === $scope ? '' : trim( (string) ( $rule['subject'] ?? '' ) );

		if ( '' === $action || '' === $pattern || ( 'default' !== $scope && '' === $subject ) ) {
			return null;
		}

		$id = isset( $rule['id'] ) && preg_match( '/^[a-z0-9]{1,12}$/', (string) $rule['id'] ) ? (string) $rule['id'] : substr( md5( $scope . '|' . $subject . '|' . $pattern . '|' . $action ), 0, 8 );

		return array(
			'id'      => $id,
			'scope'   => $scope,
			'subject' => substr( $subject, 0, 191 ),
			'pattern' => substr( $pattern, 0, 191 ),
			'action'  => $action,
		);
	}
}
