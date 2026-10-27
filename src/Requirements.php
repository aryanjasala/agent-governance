<?php
/**
 * The WordPress version check.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Agent Governance needs the Abilities API, which arrived in WordPress 6.9.
 *
 * WordPress refuses to activate the plugin on older versions because of the
 * "Requires at least" header. This covers the other ways it can end up active
 * there: a downgrade, a database copied to an older install, or an
 * active_plugins option edited by hand. It then loads nothing, says why, and
 * deactivates itself.
 */
final class Requirements {

	public const MIN_WP = '6.9';

	/**
	 * Whether this WordPress can run the plugin.
	 *
	 * @param string|null $version WordPress version, or null for the running one.
	 * @return bool
	 */
	public static function met( ?string $version = null ): bool {
		$version = null === $version ? (string) get_bloginfo( 'version' ) : $version;

		return version_compare( $version, self::MIN_WP, '>=' ) && function_exists( 'wp_register_ability' );
	}

	/**
	 * Shows the notice and deactivates the plugin on the next admin request.
	 */
	public static function fail(): void {
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'network_admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_init', array( self::class, 'deactivate' ) );
	}

	/**
	 * Deactivates the plugin, on the network too when it was network activated.
	 */
	public static function deactivate(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			return;
		}

		$basename = plugin_basename( PLUGIN_FILE );
		deactivate_plugins( $basename, true, is_multisite() && is_plugin_active_for_network( $basename ) );
	}

	/**
	 * Explains why the plugin turned itself off.
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required WordPress version, 2: running WordPress version. */
					__( 'Agent Governance needs WordPress %1$s or later, for the Abilities API. This site runs %2$s, so the plugin has been deactivated.', 'agent-governance' ),
					self::MIN_WP,
					(string) get_bloginfo( 'version' )
				)
			)
		);
	}
}
