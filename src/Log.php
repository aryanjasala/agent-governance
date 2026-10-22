<?php
/**
 * The audit log of agent calls.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * One row per agent call: allowed, denied, held, executed or failed. Rows of one
 * agent close together in time (or of one MCP session) share a session key, so
 * a whole session can be reviewed and rolled back.
 */
final class Log {

	public const CACHE_GROUP = 'agent_governance';

	/**
	 * Latest session per identity in this request.
	 *
	 * @var array<string, array{session: string, time: int}>
	 */
	private array $sessions = array();

	/**
	 * Seconds of quiet that start a new session.
	 *
	 * @return int
	 */
	public static function session_gap(): int {
		/**
		 * Filters how long an agent may be quiet before its next call starts a new session.
		 *
		 * MCP calls over HTTP are grouped by their MCP session instead.
		 *
		 * @param int $seconds Default 30 minutes. 60 to one week.
		 */
		return max( MINUTE_IN_SECONDS, min( WEEK_IN_SECONDS, (int) apply_filters( 'agent_governance_session_gap', 30 * MINUTE_IN_SECONDS ) ) );
	}

	/**
	 * Adds a row.
	 *
	 * @param array $row Columns. created, session and ip are filled in when missing.
	 * @return int Row ID, 0 on failure.
	 */
	public function add( array $row ): int {
		global $wpdb;

		$time = time();
		$row  = array_merge(
			array(
				'created'     => gmdate( 'Y-m-d H:i:s', $time ),
				'identity'    => '',
				'agent'       => '',
				'user_id'     => 0,
				'session'     => '',
				'via'         => '',
				'source'      => '',
				'target'      => '',
				'method'      => '',
				'kind'        => '',
				'decision'    => '',
				'reason'      => '',
				'result'      => '',
				'request_id'  => 0,
				'duration_ms' => 0,
				'ip'          => '',
				'input'       => null,
				'changes'     => null,
			),
			$row
		);

		if ( '' === $row['session'] ) {
			$row['session'] = $this->session_for( (string) $row['identity'], $time );
		}
		$this->sessions[ (string) $row['identity'] ] = array(
			'session' => (string) $row['session'],
			'time'    => $time,
		);

		foreach ( array( 'identity', 'agent', 'session', 'target', 'reason', 'result' ) as $column ) {
			$row[ $column ] = substr( (string) $row[ $column ], 0, 191 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table; counts are cached per minute at most.
		$ok = $wpdb->insert( Schema::log_table(), $row );
		if ( ! $ok ) {
			return 0;
		}

		$row['id'] = (int) $wpdb->insert_id;
		wp_cache_delete( 'identities', self::CACHE_GROUP );

		/**
		 * Fires after an agent call was written to the log.
		 *
		 * For sending agent activity to an outside log or alert channel.
		 *
		 * @param array $row The row: id, created, identity, agent, user_id, session,
		 *                   via, source, target, method, kind, decision, reason,
		 *                   result, request_id, duration_ms, ip, input and changes (JSON).
		 */
		do_action( 'agent_governance_call_logged', $row );

		return $row['id'];
	}

	/**
	 * Session key for the next call of an identity.
	 *
	 * Reuses the identity's last session when its last call was within the gap.
	 *
	 * @param string $identity Identity key.
	 * @param int    $time     Now.
	 * @return string
	 */
	public function session_for( string $identity, int $time ): string {
		global $wpdb;

		$last = $this->sessions[ $identity ] ?? null;
		if ( null === $last ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One indexed lookup per identity per request; remembered below.
			$found = $wpdb->get_row( $wpdb->prepare( 'SELECT session, created FROM %i WHERE identity = %s ORDER BY id DESC LIMIT 1', Schema::log_table(), $identity ), ARRAY_A );
			if ( $found ) {
				$last = array(
					'session' => (string) $found['session'],
					'time'    => (int) strtotime( $found['created'] . ' UTC' ),
				);
			}
		}

		if ( $last && '' !== $last['session'] && 0 !== strpos( $last['session'], 'mcp:' ) && $time - $last['time'] <= self::session_gap() ) {
			return $last['session'];
		}

		return substr( $identity, 0, 170 ) . '@' . gmdate( 'Ymd\THis', $time );
	}

	/**
	 * Calls an identity made in the last minute that count towards its rate limit.
	 *
	 * Refused calls and Agent Governance's own abilities do not count.
	 *
	 * @param string $identity Identity key.
	 * @return int
	 */
	public function recent_calls( string $identity ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A rate limit has to see calls from other requests; indexed on identity and time.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE identity = %s AND created >= %s AND decision IN ('allowed','held') AND target NOT LIKE %s", Schema::log_table(), $identity, gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ), $wpdb->esc_like( Abilities::CATEGORY . '/' ) . '%' ) );
	}

	/**
	 * Rows matching filters, newest first.
	 *
	 * @param array $args identity, decision, session, user_id, search, request_id, limit, offset.
	 * @return array{rows: array[], total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		list( $where, $values ) = $this->where( $args );
		$limit                  = max( 1, min( 1000, (int) ( $args['limit'] ?? 50 ) ) );
		$offset                 = max( 0, (int) ( $args['offset'] ?? 0 ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where only holds placeholders built in where(); the plugin's own table, read on its own screens.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( array( Schema::log_table() ), $values, array( $limit, $offset ) ) ), ARRAY_A );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", array_merge( array( Schema::log_table() ), $values ) ) );
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Builds the WHERE clause for query().
	 *
	 * @param array $args Filters.
	 * @return array{0: string, 1: array}
	 */
	private function where( array $args ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array();

		foreach ( array( 'identity', 'decision', 'session', 'source' ) as $column ) {
			if ( isset( $args[ $column ] ) && '' !== (string) $args[ $column ] ) {
				$where[]  = "{$column} = %s";
				$values[] = (string) $args[ $column ];
			}
		}
		if ( ! empty( $args['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $args['user_id'];
		}
		if ( ! empty( $args['request_id'] ) ) {
			$where[]  = 'request_id = %d';
			$values[] = (int) $args['request_id'];
		}
		if ( isset( $args['search'] ) && '' !== (string) $args['search'] ) {
			$where[]  = 'target LIKE %s';
			$values[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created >= %s';
			$values[] = gmdate( 'Y-m-d H:i:s', (int) $args['since'] );
		}

		return array( implode( ' AND ', $where ), $values );
	}

	/**
	 * Every row of a session, oldest first.
	 *
	 * @param string $session Session key.
	 * @return array[]
	 */
	public function session_rows( string $session ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for a rollback review.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE session = %s ORDER BY id ASC LIMIT 5000', Schema::log_table(), $session ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every row of an identity since a time, oldest first.
	 *
	 * @param string $identity Identity key.
	 * @param int    $since    Unix time.
	 * @return array[]
	 */
	public function identity_rows( string $identity, int $since ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for a rollback review.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE identity = %s AND created >= %s ORDER BY id ASC LIMIT 5000', Schema::log_table(), $identity, gmdate( 'Y-m-d H:i:s', $since ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * When an identity's latest session started.
	 *
	 * @param string $identity Identity key.
	 * @return int Unix time, 0 when the identity has no calls.
	 */
	public function latest_session_start( string $identity ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for WP-CLI rollback.
		$session = $wpdb->get_var( $wpdb->prepare( 'SELECT session FROM %i WHERE identity = %s ORDER BY id DESC LIMIT 1', Schema::log_table(), $identity ) );
		if ( null === $session ) {
			return 0;
		}
		$first = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created) FROM %i WHERE session = %s', Schema::log_table(), $session ) );
		// phpcs:enable

		return $first ? (int) strtotime( $first . ' UTC' ) : 0;
	}

	/**
	 * Sessions, most recent first.
	 *
	 * @param array $args identity, limit.
	 * @return array[] session, identity, agent, user_id, first, last, calls, writes, rolled_back.
	 */
	public function sessions( array $args = array() ): array {
		global $wpdb;

		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read on its own screen.
		if ( ! empty( $args['identity'] ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT session, MAX(identity) AS identity, MAX(agent) AS agent, MAX(user_id) AS user_id, MIN(created) AS first, MAX(created) AS last, COUNT(*) AS calls, SUM(kind <> 'readonly' AND decision IN ('allowed','executed')) AS writes, MAX(rolled_back) AS rolled_back FROM %i WHERE identity = %s GROUP BY session ORDER BY last DESC LIMIT %d", Schema::log_table(), (string) $args['identity'], $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT session, MAX(identity) AS identity, MAX(agent) AS agent, MAX(user_id) AS user_id, MIN(created) AS first, MAX(created) AS last, COUNT(*) AS calls, SUM(kind <> 'readonly' AND decision IN ('allowed','executed')) AS writes, MAX(rolled_back) AS rolled_back FROM %i GROUP BY session ORDER BY last DESC LIMIT %d", Schema::log_table(), $limit ), ARRAY_A );
		}
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Identities seen in the log, for filters.
	 *
	 * @return array<string, string> Key => label.
	 */
	public function identities(): array {
		global $wpdb;

		$cached = wp_cache_get( 'identities', self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table; cached until the next log row.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT identity, MAX(agent) AS agent, MAX(user_id) AS user_id FROM %i GROUP BY identity ORDER BY MAX(id) DESC LIMIT 200', Schema::log_table() ), ARRAY_A );
		$list = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$list[ (string) $row['identity'] ] = self::identity_label( $row );
		}

		wp_cache_set( 'identities', $list, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $list;
	}

	/**
	 * A readable name for the agent of a row.
	 *
	 * @param array $row Row with identity, agent and user_id.
	 * @return string
	 */
	public static function identity_label( array $row ): string {
		$user  = ! empty( $row['user_id'] ) ? get_userdata( (int) $row['user_id'] ) : false;
		$login = $user ? $user->user_login : '';
		$agent = (string) ( $row['agent'] ?? '' );

		if ( 0 === strpos( (string) $row['identity'], 'app:' ) ) {
			/* translators: 1: application password name, 2: user login. */
			return $login ? sprintf( __( '%1$s (%2$s)', 'agent-governance' ), $agent, $login ) : $agent;
		}

		return '' !== $agent ? $agent : (string) $row['identity'];
	}

	/**
	 * Marks rows as rolled back.
	 *
	 * @param int[] $ids Row IDs.
	 */
	public function mark_rolled_back( array $ids ): void {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a list of %d; the plugin's own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET rolled_back = %s WHERE id IN ({$placeholders})", array_merge( array( Schema::log_table(), gmdate( 'Y-m-d H:i:s' ) ), $ids ) ) );
	}

	/**
	 * Deletes rows older than the retention period, at most 1000 per run.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public function purge( int $days ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched clean-up of the plugin's own table.
		$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created < %s ORDER BY id ASC LIMIT 1000', Schema::log_table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		if ( $deleted ) {
			wp_cache_delete( 'identities', self::CACHE_GROUP );
		}

		return $deleted;
	}

	/**
	 * A user's rows, for the personal data exporter.
	 *
	 * @param int $user_id User ID.
	 * @param int $page    Page, from 1.
	 * @param int $per     Rows per page.
	 * @return array[]
	 */
	public function for_user( int $user_id, int $page, int $per ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paged export, run once per request.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC LIMIT %d OFFSET %d', Schema::log_table(), $user_id, $per, ( max( 1, $page ) - 1 ) * $per ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Removes the personal data from a user's rows, keeping the record of the calls.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   Rows per run.
	 * @return int Rows changed.
	 */
	public function anonymize_user( int $user_id, int $limit ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batched erase on the plugin's own table.
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET user_id = 0, ip = '', agent = '', input = NULL, identity = IF(LEFT(identity, 5) = 'user:', 'user:0', identity) WHERE user_id = %d LIMIT %d", Schema::log_table(), $user_id, $limit ) );
		wp_cache_delete( 'identities', self::CACHE_GROUP );

		return $changed;
	}
}
