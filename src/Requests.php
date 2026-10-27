<?php
/**
 * Held agent calls waiting for a person.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * The approval queue table: one row per held call, from pending to approved
 * and executed (or failed), rejected or expired.
 */
final class Requests {

	public const PENDING  = 'pending';
	public const APPROVED = 'approved';
	public const REJECTED = 'rejected';
	public const EXPIRED  = 'expired';
	public const EXECUTED = 'executed';
	public const FAILED   = 'failed';
	public const STATUSES = array( self::PENDING, self::APPROVED, self::REJECTED, self::EXPIRED, self::EXECUTED, self::FAILED );

	/**
	 * Stores a held call.
	 *
	 * @param array $row identity, agent, user_id, session, via, source, target, method, kind, input (JSON), dry_run (JSON).
	 * @return int Request ID, 0 on failure.
	 */
	public function create( array $row ): int {
		global $wpdb;

		$row = array_merge(
			array(
				'created'  => gmdate( 'Y-m-d H:i:s' ),
				'identity' => '',
				'agent'    => '',
				'user_id'  => 0,
				'session'  => '',
				'via'      => '',
				'source'   => '',
				'target'   => '',
				'method'   => '',
				'kind'     => '',
				'input'    => null,
				'status'   => self::PENDING,
				'dry_run'  => null,
			),
			$row
		);

		foreach ( array( 'identity', 'agent', 'session', 'target' ) as $column ) {
			$row[ $column ] = substr( (string) $row[ $column ], 0, 191 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table; the pending count cache is cleared below.
		if ( ! $wpdb->insert( Schema::requests_table(), $row ) ) {
			return 0;
		}

		self::flush();

		return (int) $wpdb->insert_id;
	}

	/**
	 * One request.
	 *
	 * @param int $id Request ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A request changes status from other requests; always read fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::requests_table(), $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Changes columns of a request.
	 *
	 * @param int         $id      Request ID.
	 * @param array       $changes Columns.
	 * @param string|null $only_if_status Only change it while it has this status.
	 * @return bool Whether a row changed.
	 */
	public function update( int $id, array $changes, ?string $only_if_status = null ): bool {
		global $wpdb;

		$where = array( 'id' => $id );
		if ( null !== $only_if_status ) {
			$where['status'] = $only_if_status;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; the pending count cache is cleared below.
		$changed = (int) $wpdb->update( Schema::requests_table(), $changes, $where );
		self::flush();

		return $changed > 0;
	}

	/**
	 * Requests matching filters, newest first.
	 *
	 * @param array $args status, identity, user_id, limit, offset.
	 * @return array{rows: array[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();
		foreach ( array( 'status', 'identity' ) as $column ) {
			if ( isset( $args[ $column ] ) && '' !== (string) $args[ $column ] ) {
				$where[]  = "{$column} = %s";
				$values[] = (string) $args[ $column ];
			}
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $args['user_id'];
		}

		$where  = implode( ' AND ', $where );
		$limit  = max( 1, min( 1000, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where only holds placeholders built above; the plugin's own table.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( array( Schema::requests_table() ), $values, array( $limit, $offset ) ) ), ARRAY_A );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( array( Schema::requests_table() ), $values ) ) );
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * How many requests wait for a decision. Cached until one changes.
	 *
	 * @return int
	 */
	public function pending_count(): int {
		global $wpdb;

		$cached = wp_cache_get( 'pending_count', Log::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table; cached below until a request changes.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s AND created >= %s', Schema::requests_table(), self::PENDING, gmdate( 'Y-m-d H:i:s', time() - Settings::request_ttl() ) ) );
		wp_cache_set( 'pending_count', $count, Log::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * When the oldest pending request was made.
	 *
	 * @return int Unix time, 0 when nothing waits.
	 */
	public function oldest_pending(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for Site Health.
		$created = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created) FROM %i WHERE status = %s AND created >= %s', Schema::requests_table(), self::PENDING, gmdate( 'Y-m-d H:i:s', time() - Settings::request_ttl() ) ) );

		return $created ? (int) strtotime( $created . ' UTC' ) : 0;
	}

	/**
	 * Pending requests older than the waiting time, at most 200.
	 *
	 * @return array[]
	 */
	public function overdue(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched clean-up of the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status = %s AND created < %s ORDER BY id ASC LIMIT 200', Schema::requests_table(), self::PENDING, gmdate( 'Y-m-d H:i:s', time() - Settings::request_ttl() ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Whether a pending request has waited too long.
	 *
	 * @param array $row Request.
	 * @return bool
	 */
	public static function is_overdue( array $row ): bool {
		return self::PENDING === $row['status'] && (int) strtotime( $row['created'] . ' UTC' ) + Settings::request_ttl() < time();
	}

	/**
	 * When a request stops waiting, as Unix time.
	 *
	 * @param array $row Request.
	 * @return int
	 */
	public static function expires_at( array $row ): int {
		return (int) strtotime( $row['created'] . ' UTC' ) + Settings::request_ttl();
	}

	/**
	 * Deletes decided requests older than the retention period, at most 1000 per run.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public function purge( int $days ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched clean-up of the plugin's own table.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE status <> %s AND created < %s ORDER BY id ASC LIMIT 1000', Schema::requests_table(), self::PENDING, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * A user's requests, for the personal data exporter.
	 *
	 * @param int $user_id User ID.
	 * @param int $page    Page, from 1.
	 * @param int $per     Rows per page.
	 * @return array[]
	 */
	public function for_user( int $user_id, int $page, int $per ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paged export, run once per request.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', Schema::requests_table(), $user_id, $per, ( max( 1, $page ) - 1 ) * $per ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Removes the personal data from a user's requests. Pending ones are rejected,
	 * since they could not run without their input.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Rows per run.
	 * @return int Rows changed.
	 */
	public function anonymize_user( int $user_id, int $limit ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched erase on the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, decided_at = %s, reason = %s WHERE user_id = %d AND status = %s', Schema::requests_table(), self::REJECTED, gmdate( 'Y-m-d H:i:s' ), 'Personal data erased.', $user_id, self::PENDING ) );
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET user_id = 0, agent = '', input = NULL, result = NULL, identity = IF(LEFT(identity, 5) = 'user:', 'user:0', identity) WHERE user_id = %d LIMIT %d", Schema::requests_table(), $user_id, $limit ) );
		// phpcs:enable
		self::flush();

		return $changed;
	}

	/**
	 * Clears the cached pending count.
	 */
	public static function flush(): void {
		wp_cache_delete( 'pending_count', Log::CACHE_GROUP );
	}

	/**
	 * Readable status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			self::PENDING  => __( 'Waiting for approval', 'agent-governance' ),
			self::APPROVED => __( 'Approved, running', 'agent-governance' ),
			self::REJECTED => __( 'Rejected', 'agent-governance' ),
			self::EXPIRED  => __( 'Expired', 'agent-governance' ),
			self::EXECUTED => __( 'Approved and executed', 'agent-governance' ),
			self::FAILED   => __( 'Approved, but it failed', 'agent-governance' ),
		);

		return $labels[ $status ] ?? $status;
	}
}
