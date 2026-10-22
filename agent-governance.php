<?php
/**
 * Plugin Name:       Agent Governance
 * Plugin URI:        https://github.com/aryanjasala/agent-governance
 * Description:       Human approval, policies and an audit log for AI agents acting on WordPress. Holds risky agent calls until someone approves them, shows what they would change, and rolls back an agent's session.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Aryan Jasala
 * Author URI:        https://profiles.wordpress.org/aryanjasala/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-governance
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

// PSR-4: AryanX\AgentGovernance\Foo\Bar lives in src/Foo/Bar.php.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Schema::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Schema::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
