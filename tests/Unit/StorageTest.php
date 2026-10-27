<?php
/**
 * Tests for the log and request tables.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Log;
use AryanX\Axle\AgentGovernance\Requests;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers Log and Requests.
 */
final class StorageTest extends AgentTestCase {

	public function test_a_new_identity_starts_a_session(): void {
		$this->wpdb->returns['get_row'][] = null;

		$this->assertSame( 'app:x@20261023T100000', $this->log->session_for( 'app:x', (int) strtotime( '2026-10-23 10:00:00 UTC' ) ) );
	}

	public function test_calls_within_the_gap_share_a_session(): void {
		$this->wpdb->returns['get_row'][] = array(
			'session' => 'app:x@20261023T090000',
			'created' => '2026-10-23 09:50:00',
		);

		$this->assertSame( 'app:x@20261023T090000', $this->log->session_for( 'app:x', (int) strtotime( '2026-10-23 10:00:00 UTC' ) ) );
	}

	public function test_the_session_gap_filter(): void {
		Filters\expectApplied( 'agent_governance_session_gap' )->once()->with( 1800 )->andReturn( 300 );
		$this->wpdb->returns['get_row'][] = array(
			'session' => 'app:x@20261023T090000',
			'created' => '2026-10-23 09:50:00',
		);

		$this->assertSame( 'app:x@20261023T100000', $this->log->session_for( 'app:x', (int) strtotime( '2026-10-23 10:00:00 UTC' ) ) );
	}

	public function test_an_mcp_session_is_not_continued_by_time(): void {
		$this->wpdb->returns['get_row'][] = array(
			'session' => 'mcp:abc',
			'created' => '2026-10-23 09:59:00',
		);

		$this->assertSame( 'app:x@20261023T100000', $this->log->session_for( 'app:x', (int) strtotime( '2026-10-23 10:00:00 UTC' ) ) );
	}

	public function test_add_remembers_the_session_and_fires_call_logged(): void {
		$this->wpdb->returns['get_row'][] = null;
		Actions\expectDone( 'agent_governance_call_logged' )->twice()->with( Mockery::type( 'array' ) );

		$this->log->add( array( 'identity' => 'app:x' ) );
		$this->log->add( array( 'identity' => 'app:x' ) );

		$rows = $this->wpdb->rows( 'log' );
		$this->assertSame( $rows[0]['session'], $rows[1]['session'] );
		$this->assertCount( 1, $this->wpdb->queries, 'The session is looked up once per identity.' );
	}

	public function test_a_failed_insert_returns_zero(): void {
		$this->wpdb->fail_insert = true;
		Actions\expectDone( 'agent_governance_call_logged' )->never();

		$this->assertSame( 0, $this->log->add( array( 'session' => 's' ) ) );
	}

	public function test_query_builds_filters(): void {
		$this->wpdb->returns['get_results'][] = array( $this->log_row() );
		$this->wpdb->returns['get_var'][]     = 1;

		$found = $this->log->query(
			array(
				'identity' => 'app:x',
				'decision' => 'held',
				'user_id'  => 2,
				'search'   => 'posts',
				'limit'    => 5,
				'offset'   => 10,
			)
		);

		$this->assertSame( 1, $found['total'] );
		$this->assertStringContainsString( "identity = 'app:x' AND decision = 'held' AND user_id = '2' AND target LIKE '%posts%'", $this->wpdb->queries[0] );
		$this->assertStringContainsString( "LIMIT '5' OFFSET '10'", $this->wpdb->queries[0] );
	}

	public function test_identity_labels(): void {
		$this->assertSame( 'Helper (ed)', Log::identity_label( $this->log_row( array( 'user_id' => 2 ) ) ) );
		$this->assertSame( 'Helper', Log::identity_label( $this->log_row( array( 'user_id' => 0 ) ) ) );
		$this->assertSame( 'admin', Log::identity_label( $this->log_row( array( 'identity' => 'user:1', 'agent' => 'admin' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'user:1', Log::identity_label( $this->log_row( array( 'identity' => 'user:1', 'agent' => '' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_identities_are_cached(): void {
		$this->wpdb->returns['get_results'][] = array(
			array(
				'identity' => 'app:x',
				'agent'    => 'Helper',
				'user_id'  => 1,
			),
		);

		$this->assertSame( array( 'app:x' => 'Helper (admin)' ), $this->log->identities() );
		$this->assertSame( array( 'app:x' => 'Helper (admin)' ), $this->log->identities() );
		$this->assertCount( 1, $this->wpdb->queries );
	}

	public function test_latest_session_start(): void {
		$this->wpdb->returns['get_var'][] = 's1';
		$this->wpdb->returns['get_var'][] = '2026-10-23 09:00:00';
		$this->assertSame( (int) strtotime( '2026-10-23 09:00:00 UTC' ), $this->log->latest_session_start( 'app:x' ) );

		$this->wpdb->returns['get_var'][] = null;
		$this->assertSame( 0, $this->log->latest_session_start( 'app:y' ) );
	}

	public function test_purge_and_anonymize(): void {
		$this->wpdb->returns['query'][] = 3;
		$this->assertSame( 3, $this->log->purge( 30 ) );
		$this->assertStringContainsString( 'DELETE FROM', $this->wpdb->queries[0] );

		$this->wpdb->returns['query'][] = 2;
		$this->assertSame( 2, $this->log->anonymize_user( 2, 500 ) );
		$this->assertStringContainsString( "user_id = 0, ip = ''", $this->wpdb->queries[1] );
	}

	public function test_requests_are_created_and_counted_with_a_cache(): void {
		$this->cache['agent_governance:pending_count'] = 4;

		$this->assertSame( 1, $this->requests->create( array( 'target' => str_repeat( 'x', 300 ) ) ) );
		$this->assertSame( 191, strlen( $this->wpdb->rows( 'requests' )[0]['target'] ) );
		$this->assertSame( Requests::PENDING, $this->wpdb->rows( 'requests' )[0]['status'] );
		$this->assertArrayNotHasKey( 'agent_governance:pending_count', $this->cache, 'A new request clears the count.' );

		$this->wpdb->returns['get_var'][] = 2;
		$this->assertSame( 2, $this->requests->pending_count() );
		$this->assertSame( 2, $this->requests->pending_count() );
		$this->assertCount( 1, $this->wpdb->queries );
	}

	public function test_overdue_follows_the_request_ttl_filter(): void {
		$row = $this->request_row( array( 'created' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ) );
		$this->assertFalse( Requests::is_overdue( $row ) );

		Filters\expectApplied( 'agent_governance_request_ttl' )->andReturn( 3600 );
		$this->assertTrue( Requests::is_overdue( $row ) );
		$this->assertFalse( Requests::is_overdue( array_merge( $row, array( 'status' => Requests::EXECUTED ) ) ) );
	}

	public function test_status_labels(): void {
		$this->assertSame( 'Waiting for approval', Requests::status_label( Requests::PENDING ) );
		$this->assertSame( 'odd', Requests::status_label( 'odd' ) );
	}

	public function test_requests_query_purge_and_anonymize(): void {
		$this->wpdb->returns['get_results'][] = array( $this->request_row() );
		$this->wpdb->returns['get_var'][]     = 1;
		$found                                = $this->requests->query(
			array(
				'status'  => 'pending',
				'user_id' => 2,
			)
		);
		$this->assertSame( 1, $found['total'] );
		$this->assertStringContainsString( "status = 'pending' AND user_id = '2'", $this->wpdb->queries[0] );

		$this->wpdb->returns['query'][] = 5;
		$this->assertSame( 5, $this->requests->purge( 30 ) );

		$this->wpdb->returns['query'][] = 1;
		$this->wpdb->returns['query'][] = 4;
		$this->assertSame( 4, $this->requests->anonymize_user( 2, 500 ) );
		$this->assertStringContainsString( "status = 'rejected'", $this->wpdb->queries[3] );
	}
}
