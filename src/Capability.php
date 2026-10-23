<?php
/**
 * The approver capability: who has it, and what happens when nobody does.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance;

use AryanX\AgentGovernance\Admin\Page;

defined( 'ABSPATH' ) || exit;

/**
 * A site must never be left without an approver. Another plugin, such as a role
 * clean-up tool, or a person editing roles can take the approver capability
 * off every role. Until a role has it again, anyone with the fallback
 * capability (manage_options) may approve, and people who manage options see a
 * notice with a button that gives the capability back.
 *
 * The capability is given to the approver roles on activation, taken off every
 * role on deactivation (so nothing is left on a role while the plugin is off)
 * and on uninstall.
 */
final class Capability implements Service {

	public const RESTORE_ACTION = 'agent_governance_restore_capability';

	/**
	 * Adds the fallback, the notice and the restore handler.
	 */
	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'map' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_post_' . self::RESTORE_ACTION, array( $this, 'handle_restore' ) );
	}

	/**
	 * The capability that stands in while no role has the approver capability.
	 *
	 * @return string
	 */
	public static function fallback(): string {
		/**
		 * Filters the capability that may approve agent calls while no role has the
		 * approver capability, so held calls never wait for nobody.
		 *
		 * @param string $capability Default manage_options.
		 */
		$cap = trim( (string) apply_filters( 'agent_governance_fallback_capability', 'manage_options' ) );

		return '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * Whether any role has the approver capability.
	 *
	 * @return bool
	 */
	public static function granted(): bool {
		$cap = Notifier::capability();

		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The capability to look approvers up by: the approver capability, or the
	 * fallback while no role has it.
	 *
	 * @return string
	 */
	public static function effective(): string {
		return self::granted() ? Notifier::capability() : self::fallback();
	}

	/**
	 * Maps the approver capability to the fallback while no role has it.
	 *
	 * @param string[] $caps Primitive capabilities the user needs.
	 * @param string   $cap  Capability being checked.
	 * @return string[]
	 */
	public function map( $caps, $cap ): array {
		static $running = false;

		$caps = (array) $caps;
		// A filter on the approver or fallback capability may check capabilities itself.
		if ( $running ) {
			return $caps;
		}

		$running = true;
		try {
			if ( Notifier::capability() === $cap && ! self::granted() ) {
				$caps = array( self::fallback() );
			}
		} finally {
			$running = false;
		}

		return $caps;
	}

	/**
	 * Gives the approver capability to the approver roles.
	 *
	 * @return string[] Roles that got it.
	 */
	public static function grant(): array {
		$cap   = Schema::CAPABILITY;
		$given = array();

		foreach ( Schema::approver_roles() as $name ) {
			$role = get_role( $name );
			if ( $role && ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
				$given[] = $name;
			}
		}

		return $given;
	}

	/**
	 * Takes the approver capability off every role.
	 *
	 * @return string[] Roles that had it.
	 */
	public static function revoke(): array {
		$taken = array();

		foreach ( wp_roles()->role_objects as $name => $role ) {
			if ( $role->has_cap( Schema::CAPABILITY ) ) {
				$role->remove_cap( Schema::CAPABILITY );
				$taken[] = (string) $name;
			}
		}

		return $taken;
	}

	/**
	 * Gives the capability back to the approver roles, from the notice or WP-CLI.
	 *
	 * @param int $user_id Who did it.
	 * @return string[] Roles that got it.
	 */
	public static function restore( int $user_id ): array {
		$roles = self::grant();

		/**
		 * Fires after the approver capability was given back to the approver roles,
		 * from the admin notice or `wp agent-governance restore-capability`.
		 *
		 * @param string[] $roles   Roles that got it.
		 * @param int      $user_id Who did it.
		 */
		do_action( 'agent_governance_capability_restored', $roles, $user_id );

		return $roles;
	}

	/**
	 * Tells people who manage options that no role can approve agent calls.
	 */
	public function notice(): void {
		if ( ! current_user_can( Page::settings_capability() ) || self::granted() ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: capability name, 2: fallback capability name. */
			__( 'No role has the %1$s capability any more, so nobody is set up to approve AI agent calls. Until a role has it again, anyone with the %2$s capability approves them.', 'agent-governance' ),
			Notifier::capability(),
			self::fallback()
		);

		/**
		 * Filters the admin notice shown while no role has the approver capability.
		 *
		 * @param string $message Message. Plain text.
		 */
		$message = (string) apply_filters( 'agent_governance_no_approver_message', $message );

		printf(
			'<div class="notice notice-error agg-no-approver"><p>%1$s</p><form method="post" action="%2$s"><input type="hidden" name="action" value="%3$s">%4$s<p><button type="submit" class="button button-primary">%5$s</button></p></form></div>',
			esc_html( $message ),
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( self::RESTORE_ACTION ),
			wp_nonce_field( self::RESTORE_ACTION, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's nonce field.
			esc_html( self::restore_label() )
		);
	}

	/**
	 * The restore button's label, naming the approver roles.
	 *
	 * @return string
	 */
	public static function restore_label(): string {
		$roles = wp_roles()->role_names;
		$names = array();
		foreach ( Schema::approver_roles() as $name ) {
			$names[] = isset( $roles[ $name ] ) ? translate_user_role( (string) $roles[ $name ] ) : $name;
		}

		/* translators: %s: role names, such as "Administrator". */
		return sprintf( __( 'Give it back to %s', 'agent-governance' ), implode( ', ', $names ) );
	}

	/**
	 * Handles the restore button.
	 */
	public function handle_restore(): void {
		check_admin_referer( self::RESTORE_ACTION );
		if ( ! current_user_can( Page::settings_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change who approves agent calls.', 'agent-governance' ), 403 );
		}

		self::restore( get_current_user_id() );

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : Page::url() );
		exit;
	}
}
