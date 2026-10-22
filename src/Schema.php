<?php
/**
 * Database tables, the approver capability and the clean-up schedule.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the two tables, grants the approver capability and
 * schedules the hourly clean-up.
 *
 * The version check runs on every load, so sites added to a network after the
 * plugin was network activated get their tables on their first request.
 */
final class Schema {

	public const DB_VERSION        = '1';
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
	 * Activation: tables, capability and schedule.
	 */
	public static function activate(): void {
		if ( ! Requirements::met() ) {
			return;
		}

		self::install();
	}

	/**
	 * Deactivation: stops the schedule. Data stays until uninstall.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
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
		self::create_tables();
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
		 * Filters the roles that get the approver capability on activation.
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
		foreach ( self::approver_roles() as $name ) {
			$role = get_role( $name );
			if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
				$role->add_cap( self::CAPABILITY );
			}
		}
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
				PRIMARY KEY  (id),
				KEY status_created (status,created),
				KEY identity (identity),
				KEY user_id (user_id)
			) {$charset};"
		);
	}

	/**
	 * Whether both tables exist, for Site Health.
	 *
	 * @return bool
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( array( self::log_table(), self::requests_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checks the plugin's own table, for Site Health.
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return false;
			}
		}

		return true;
	}
}
