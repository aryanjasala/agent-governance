<?php
/**
 * From a post to the agent calls that changed it.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * One row for each post an agent call created or updated: which log row did it,
 * when, and whether somebody undid it since. The editor panel reads it to say
 * "an agent changed this", and the single-post undo uses it to find the call.
 *
 * Rows are written when the call is logged, so they follow the log's retention.
 * They hold no personal data, except who undid a change, which the privacy
 * exporter and eraser cover.
 */
final class PostIndex implements Service {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the index.
	 *
	 * @param Settings $settings Settings, for the retention period.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'agent_governance_call_logged', array( $this, 'record' ), 10, 1 );
		add_action( 'agent_governance_session_rolled_back', array( $this, 'session_rolled_back' ), 10, 3 );
		add_action( 'agent_governance_cleaned_up', array( $this, 'purge_old' ) );
	}

	/**
	 * Indexes the posts a logged call changed.
	 *
	 * @param array $row The log row, with its id and changes (JSON).
	 */
	public function record( $row ): void {
		global $wpdb;

		$row = (array) $row;
		if ( empty( $row['id'] ) || empty( $row['changes'] ) || ! in_array( $row['decision'] ?? '', array( 'allowed', 'executed' ), true ) ) {
			return;
		}

		$changes = json_decode( (string) $row['changes'], true );
		$seen    = array();
		foreach ( is_array( $changes ) ? $changes : array() as $change ) {
			$post_id = (int) ( $change['post_id'] ?? 0 );
			$action  = (string) ( $change['action'] ?? '' );
			// A post deleted for good is gone: there is no editor to show it in.
			if ( $post_id < 1 || 'deleted' === $action || isset( $seen[ $post_id ] ) ) {
				continue;
			}
			$seen[ $post_id ] = true;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table, written once per changed post.
			$wpdb->insert(
				Schema::posts_table(),
				array(
					'post_id' => $post_id,
					'log_id'  => (int) $row['id'],
					'created' => (string) ( $row['created'] ?? gmdate( 'Y-m-d H:i:s' ) ),
					'action'  => substr( $action, 0, 10 ),
				)
			);
		}
	}

	/**
	 * The latest agent change to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Index row: id, post_id, log_id, created, action, undone, undone_by.
	 */
	public function latest( int $post_id ): ?array {
		global $wpdb;

		if ( $post_id < 1 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read when an editor opens the post; indexed on the post.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id DESC LIMIT 1', Schema::posts_table(), $post_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Marks one index row as undone.
	 *
	 * @param int $id      Index row ID.
	 * @param int $user_id Who undid it, 0 for WP-CLI.
	 * @return bool Whether a row changed.
	 */
	public function mark_undone( int $id, int $user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET undone = %s, undone_by = %d WHERE id = %d AND undone IS NULL', Schema::posts_table(), gmdate( 'Y-m-d H:i:s' ), $user_id, $id ) ) > 0;
	}

	/**
	 * A session rollback puts these posts back too: mark their index rows undone.
	 *
	 * @param string $scope  Session key or identity.
	 * @param array  $result Counts.
	 * @param array  $plan   posts, other and rows.
	 */
	public function session_rolled_back( $scope, $result = array(), $plan = array() ): void {
		global $wpdb;

		$plan = (array) $plan;
		$rows = array_values( array_filter( array_map( 'intval', (array) ( $plan['rows'] ?? array() ) ) ) );
		$done = array();
		foreach ( (array) ( $plan['posts'] ?? array() ) as $item ) {
			if ( ! empty( $item['possible'] ) && empty( $item['denied'] ) ) {
				$done[] = (int) $item['post_id'];
			}
		}
		if ( ! $rows || ! $done ) {
			return;
		}

		$log_ph  = implode( ',', array_fill( 0, count( $rows ), '%d' ) );
		$post_ph = implode( ',', array_fill( 0, count( $done ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Both lists are %d placeholders; the plugin's own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET undone = %s, undone_by = %d WHERE undone IS NULL AND log_id IN ({$log_ph}) AND post_id IN ({$post_ph})", array_merge( array( Schema::posts_table(), gmdate( 'Y-m-d H:i:s' ), get_current_user_id() ), $rows, $done ) ) );
	}

	/**
	 * Deletes index rows older than the log is kept, after each clean-up.
	 */
	public function purge_old(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched clean-up of the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created < %s ORDER BY id ASC LIMIT 1000', Schema::posts_table(), gmdate( 'Y-m-d H:i:s', time() - $this->settings->retention_days() * DAY_IN_SECONDS ) ) );
	}

	/**
	 * The changes a user undid, for the personal data exporter.
	 *
	 * @param int $user_id User ID.
	 * @param int $page    Page, from 1.
	 * @param int $per     Rows per page.
	 * @return array[]
	 */
	public static function undone_by( int $user_id, int $page, int $per ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paged export, run once per request.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE undone_by = %d ORDER BY id ASC LIMIT %d OFFSET %d', Schema::posts_table(), $user_id, $per, ( max( 1, $page ) - 1 ) * $per ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Removes a user from the changes they undid.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Rows per run.
	 * @return int Rows changed.
	 */
	public static function anonymize_user( int $user_id, int $limit ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched erase on the plugin's own table.
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET undone_by = 0 WHERE undone_by = %d LIMIT %d', Schema::posts_table(), $user_id, $limit ) );
	}
}
