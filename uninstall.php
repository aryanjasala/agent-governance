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
	wp_clear_scheduled_hook( 'agent_governance_flush_notifications' );
	delete_option( 'agent_governance_settings' );
	delete_option( 'agent_governance_rules' );
	delete_option( 'agent_governance_db_version' );
	delete_option( 'agent_governance_outbox' );
	delete_option( 'agent_governance_last_notified' );
	delete_option( 'agent_governance_reminded' );
	delete_option( 'agent_governance_webhook_status' );
	delete_transient( 'agent_governance_suggestions' );

	foreach ( array( 'agent_governance_dismissed_suggestions', 'agent_governance_steps_dismissed', 'agent_governance_tried_policy', 'agent_governance_notify' ) as $agent_governance_meta ) {
		delete_metadata( 'user', 0, $agent_governance_meta, '', true );
	}

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
