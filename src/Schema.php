<?php
/**
 * Database tables, the approver capability and the clean-up schedule.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the two tables, grants the approver capability and
 * schedules the hourly clean-up.
 *
 * The version check runs on every load, so sites added to a network after the
 * plugin was network activated get their tables on their first request.
 */
final class Schema {

	public const DB_VERSION        = '2';
	public const DB_VERSION_OPTION = 'agent_governance_db_version';
	public const CAPABILITY        = 'agent_governance_approve';
	public const CRON_HOOK         = 'agent_governance_cleanup';

	/**
	 * The log table name.
	 *
	 * @return string
	 */
	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'agent_governance_log';
	}

	/**
	 * The requests table name.
	 *
	 * @return string
	 */
	public static function requests_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'agent_governance_requests';
	}

	/**
	 * The table that points from a post to the agent calls that changed it.
	 *
	 * @return string
	 */
	public static function posts_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'agent_governance_posts';
	}

	/**
	 * Activation: tables, capability and schedule.
	 */
	public static function activate(): void {
		if ( ! Requirements::met() ) {
			return;
		}

		self::install();
	}

	/**
	 * Deactivation: stops the schedule and takes the approver capability off
	 * every role, so nothing is left on a role while the plugin is off. The
	 * tables, settings and log stay until uninstall. The stored version goes
	 * too, so the next activation (or the first request on each site of a
	 * network) sets the capability up again.
	 *
	 * @param bool $network_wide Whether it was network deactivated.
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			$offset = 0;
			do {
				$sites = get_sites(
					array(
						'fields' => 'ids',
						'number' => 100,
						'offset' => $offset,
					)
				);
				foreach ( $sites as $site ) {
					switch_to_blog( (int) $site );
					self::deactivate_site();
					restore_current_blog();
				}
				$offset += 100;
			} while ( $sites );
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Deactivation on the current site.
	 */
	public static function deactivate_site(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Notifier::FLUSH_HOOK );

		/**
		 * Filters whether deactivating takes the approver capability off every role.
		 *
		 * Activating gives it back to the approver roles.
		 *
		 * @param bool $remove Default true.
		 */
		if ( ! apply_filters( 'agent_governance_remove_capability_on_deactivate', true ) ) {
			return;
		}

		$roles = Capability::revoke();
		delete_option( self::DB_VERSION_OPTION );

		/**
		 * Fires after deactivating took the approver capability off the roles that had it.
		 *
		 * @param string[] $roles Roles that had it.
		 */
		do_action( 'agent_governance_capability_removed', $roles );
	}

	/**
	 * Installs or upgrades when the stored version differs.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Creates the tables and grants the capability.
	 */
	public static function install(): void {
		$from = (string) get_option( self::DB_VERSION_OPTION, '' );
		self::create_tables();
		if ( '' !== $from && version_compare( $from, '2', '<' ) ) {
			self::backfill_objects();
		}
		self::grant_capability();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Roles that get the approver capability.
	 *
	 * @return string[]
	 */
	public static function approver_roles(): array {
		/**
		 * Filters the roles that get the approver capability on activation, and
		 * from the "Give it back" button when no role has it.
		 *
		 * @param string[] $roles Role names. Default administrator.
		 */
		$roles = (array) apply_filters( 'agent_governance_approver_roles', array( 'administrator' ) );

		return array_values( array_filter( array_map( 'strval', $roles ) ) );
	}

	/**
	 * Adds the approver capability to the approver roles.
	 */
	public static function grant_capability(): void {
		Capability::grant();
	}

	/**
	 * Creates or updates both tables with dbDelta.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$log     = self::log_table();
		$queue   = self::requests_table();
		$posts   = self::posts_table();

		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL default '0000-00-00 00:00:00',
				identity varchar(191) NOT NULL default '',
				agent varchar(191) NOT NULL default '',
				user_id bigint(20) unsigned NOT NULL default 0,
				session varchar(191) NOT NULL default '',
				via varchar(20) NOT NULL default '',
				source varchar(20) NOT NULL default '',
				target varchar(191) NOT NULL default '',
				method varchar(10) NOT NULL default '',
				kind varchar(12) NOT NULL default '',
				decision varchar(20) NOT NULL default '',
				reason varchar(191) NOT NULL default '',
				result varchar(191) NOT NULL default '',
				request_id bigint(20) unsigned NOT NULL default 0,
				duration_ms int(10) unsigned NOT NULL default 0,
				ip varchar(45) NOT NULL default '',
				input longtext NULL,
				changes longtext NULL,
				rolled_back datetime NULL,
				PRIMARY KEY  (id),
				KEY identity_created (identity,created),
				KEY session (session),
				KEY created (created),
				KEY user_id (user_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$queue} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created datetime NOT NULL default '0000-00-00 00:00:00',
				identity varchar(191) NOT NULL default '',
				agent varchar(191) NOT NULL default '',
				user_id bigint(20) unsigned NOT NULL default 0,
				session varchar(191) NOT NULL default '',
				via varchar(20) NOT NULL default '',
				source varchar(20) NOT NULL default '',
				target varchar(191) NOT NULL default '',
				method varchar(10) NOT NULL default '',
				kind varchar(12) NOT NULL default '',
				input longtext NULL,
				status varchar(20) NOT NULL default 'pending',
				decided_by bigint(20) unsigned NOT NULL default 0,
				decided_at datetime NULL,
				reason text NULL,
				result longtext NULL,
				dry_run longtext NULL,
				object_type varchar(40) NOT NULL default '',
				object_id bigint(20) unsigned NOT NULL default 0,
				PRIMARY KEY  (id),
				KEY object (object_id,status),
				KEY status_created (status,created),
				KEY identity (identity),
				KEY user_id (user_id)
			) {$charset};"
		);

		// One row per post an agent call changed: the call, when and how, and whether it was undone since.
		dbDelta(
			"CREATE TABLE {$posts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL default 0,
				log_id bigint(20) unsigned NOT NULL default 0,
				created datetime NOT NULL default '0000-00-00 00:00:00',
				action varchar(10) NOT NULL default '',
				undone datetime NULL,
				undone_by bigint(20) unsigned NOT NULL default 0,
				PRIMARY KEY  (id),
				KEY post_created (post_id,created),
				KEY log_id (log_id),
				KEY created (created)
			) {$charset};"
		);
	}


	/**
	 * Fills the object columns of requests that were held before they existed,
	 * so the editor finds the ones still waiting. Decided requests are left alone.
	 */
	private static function backfill_objects(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off upgrade of the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, dry_run FROM %i WHERE status = %s AND object_id = 0 ORDER BY id DESC LIMIT 1000', self::requests_table(), Requests::PENDING ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$object = Requests::object_of( (string) $row['dry_run'] );
			if ( $object['id'] > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off upgrade of the plugin's own table.
				$wpdb->update(
					self::requests_table(),
					array(
						'object_type' => $object['type'],
						'object_id'   => $object['id'],
					),
					array( 'id' => (int) $row['id'] )
				);
			}
		}
	}

	/**
	 * Whether all three tables exist, for Site Health.
	 *
	 * @return bool
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( array( self::log_table(), self::requests_table(), self::posts_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks the plugin's own table, for Site Health.
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return false;
			}
		}

		return true;
	}
}
