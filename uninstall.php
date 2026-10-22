<?php
/**
 * Removes Agent Governance's tables, settings, capability and schedule, on every site.
 *
 * @package AgentGovernance
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes Agent Governance data from the current site.
 */
function agent_governance_uninstall_site(): void {
	global $wpdb;

	wp_clear_scheduled_hook( 'agent_governance_cleanup' );
	delete_option( 'agent_governance_settings' );
	delete_option( 'agent_governance_rules' );
	delete_option( 'agent_governance_db_version' );

	foreach ( wp_roles()->role_objects as $agent_governance_role ) {
		$agent_governance_role->remove_cap( 'agent_governance_approve' );
	}

	foreach ( array( 'agent_governance_log', 'agent_governance_requests' ) as $agent_governance_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $agent_governance_table ) );
	}

	wp_cache_delete( 'pending_count', 'agent_governance' );
	wp_cache_delete( 'identities', 'agent_governance' );
}

if ( is_multisite() ) {
	$agent_governance_offset = 0;
	do {
		$agent_governance_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $agent_governance_offset,
			)
		);

		foreach ( $agent_governance_sites as $agent_governance_site ) {
			switch_to_blog( $agent_governance_site );
			agent_governance_uninstall_site();
			restore_current_blog();
		}

		$agent_governance_offset += 100;
	} while ( $agent_governance_sites );
} else {
	agent_governance_uninstall_site();
}
