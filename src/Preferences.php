<?php
/**
 * Each approver's own notification settings.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * What a person wants to hear about: the email when a request is held, the
 * reminder before one expires and the notice in wp-admin. Stored as one user
 * meta, edited on the person's own profile, with WP-CLI or an ability.
 */
final class Preferences implements Service {

	public const META   = 'agent_governance_notify';
	public const ACTION = 'agent_governance_save_preferences';

	/**
	 * Adds the profile section.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile' ) );
	}

	/**
	 * The settings a person has until they change them.
	 *
	 * @return array{email: bool, reminder: bool, notice: bool}
	 */
	public static function defaults(): array {
		/**
		 * Filters the notification settings an approver has until they change them.
		 *
		 * @param array $defaults email, reminder and notice, each a bool. Default all true.
		 */
		$filtered = (array) apply_filters(
			'agent_governance_default_preferences',
			array(
				'email'    => true,
				'reminder' => true,
				'notice'   => true,
			) 
		);

		return array(
			'email'    => ! array_key_exists( 'email', $filtered ) || (bool) $filtered['email'],
			'reminder' => ! array_key_exists( 'reminder', $filtered ) || (bool) $filtered['reminder'],
			'notice'   => ! array_key_exists( 'notice', $filtered ) || (bool) $filtered['notice'],
		);
	}

	/**
	 * One person's settings.
	 *
	 * @param int $user_id User.
	 * @return array{email: bool, reminder: bool, notice: bool}
	 */
	public static function get( int $user_id ): array {
		$stored = $user_id > 0 ? get_user_meta( $user_id, self::META, true ) : array();

		return self::clean( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Whether a person wants one kind of message.
	 *
	 * @param int    $user_id User.
	 * @param string $key     email, reminder or notice.
	 * @return bool
	 */
	public static function wants( int $user_id, string $key ): bool {
		return ! empty( self::get( $user_id )[ $key ] );
	}

	/**
	 * Saves changes to one person's settings.
	 *
	 * @param int   $user_id User.
	 * @param array $changes Any of email, reminder and notice.
	 * @return array The saved settings.
	 */
	public static function save( int $user_id, array $changes ): array {
		$old   = self::get( $user_id );
		$clean = self::clean( $changes, $old );
		update_user_meta( $user_id, self::META, $clean );

		/**
		 * Fires after a person changed their notification settings.
		 *
		 * @param int   $user_id The person.
		 * @param array $clean   New settings.
		 * @param array $old     Previous settings.
		 */
		do_action( 'agent_governance_preferences_saved', $user_id, $clean, $old );

		return $clean;
	}

	/**
	 * Keeps the known keys as bools, falling back on the given values.
	 *
	 * @param array $value    Submitted or stored settings.
	 * @param array $fallback Values for keys that are missing.
	 * @return array{email: bool, reminder: bool, notice: bool}
	 */
	private static function clean( array $value, array $fallback ): array {
		$clean = array();
		foreach ( array( 'email', 'reminder', 'notice' ) as $key ) {
			$clean[ $key ] = array_key_exists( $key, $value ) ? (bool) $value[ $key ] : (bool) $fallback[ $key ];
		}

		return $clean;
	}

	/**
	 * The section on a person's own profile, for approvers.
	 *
	 * @param \WP_User $user The profile being edited.
	 */
	public function render( $user ): void {
		if ( empty( $user->ID ) || get_current_user_id() !== (int) $user->ID || ! user_can( $user, Capability::effective() ) ) {
			return;
		}

		$prefs = self::get( (int) $user->ID );
		$rows  = array(
			'email'    => __( 'Email me when an agent call is held for approval', 'agent-governance' ),
			'reminder' => __( 'Email me before a waiting request expires', 'agent-governance' ),
			'notice'   => __( 'Show a notice in wp-admin while requests wait', 'agent-governance' ),
		);

		echo '<h2 id="agent-governance-notifications">' . esc_html__( 'Agent approval notifications', 'agent-governance' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Tell me', 'agent-governance' ) . '</th><td>';
		wp_nonce_field( self::ACTION, '_agent_governance_prefs' );
		foreach ( $rows as $key => $label ) {
			printf( '<label><input type="checkbox" name="agent_governance_notify[%1$s]" value="1"%2$s> %3$s</label><br>', esc_attr( $key ), checked( $prefs[ $key ], true, false ), esc_html( $label ) );
		}
		echo '<p class="description">' . esc_html__( 'You are only told about requests on things you could change yourself. You still decide in wp-admin, after you sign in.', 'agent-governance' ) . '</p></td></tr></tbody></table>';
	}

	/**
	 * Saves the profile section.
	 *
	 * @param int $user_id The profile saved.
	 */
	public function save_profile( $user_id ): void {
		$user_id = (int) $user_id;
		if ( get_current_user_id() !== $user_id || ! user_can( $user_id, Capability::effective() ) ) {
			return;
		}

		$nonce = isset( $_POST['_agent_governance_prefs'] ) ? sanitize_text_field( wp_unslash( $_POST['_agent_governance_prefs'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			return;
		}

		// An unticked box is not sent, so a missing key means off.
		$posted = isset( $_POST['agent_governance_notify'] ) && is_array( $_POST['agent_governance_notify'] ) ? array_map( 'absint', wp_unslash( $_POST['agent_governance_notify'] ) ) : array();
		self::save(
			$user_id,
			array(
				'email'    => ! empty( $posted['email'] ),
				'reminder' => ! empty( $posted['reminder'] ),
				'notice'   => ! empty( $posted['notice'] ),
			)
		);
	}
}
