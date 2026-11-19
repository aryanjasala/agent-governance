<?php
/**
 * Tests that every "waiting" number counts only what the person may decide.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Abilities;
use AryanX\Axle\AgentGovernance\Requests;
use Brain\Monkey\Functions;

/**
 * Two approvers: one may edit both posts, the other only the second.
 */
final class PerApproverTest extends AgentTestCase {

	/**
	 * What each user may do.
	 *
	 * @var array<int, array<string, array<int, bool>|bool>>
	 */
	private $user_caps = array();

	/**
	 * Request rows the table holds, newest first.
	 *
	 * @var array[]
	 */
	private $rows = array();

	protected function setUp(): void {
		parent::setUp();

		$this->users[3] = (object) array(
			'ID'           => 3,
			'user_login'   => 'second',
			'user_email'   => 'second@example.com',
			'display_name' => 'Second',
			'roles'        => array( 'editor' ),
		);

		// Post 5 is the secret draft; post 6 is open to both people.
		$this->user_caps = array(
			1 => array( 5 => true, 6 => true ),
			3 => array( 6 => true ),
		);
		Functions\when( 'user_can' )->alias(
			function ( $user, $cap, $id = 0 ) {
				return 'edit_post' === $cap && ! empty( $this->user_caps[ (int) $user ][ (int) $id ] );
			}
		);
		Functions\when( 'post_type_exists' )->alias(
			static function ( $type ) {
				return 'post' === $type;
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				return (object) array( 'ID' => (int) $id );
			}
		);
		Functions\when( 'get_post_types' )->justReturn( array() );

		$this->rows = array(
			$this->about( 8, 'Delete the secret launch plan', 5 ),
			$this->about( 7, 'Change the open page', 6 ),
			$this->about( 6, 'Change the open page again', 6 ),
		);
	}

	/**
	 * A pending request about a post.
	 *
	 * @param int    $id      Request ID.
	 * @param string $summary Preview summary, which names the post.
	 * @param int    $post    Post ID.
	 * @return array
	 */
	private function about( int $id, string $summary, int $post ): array {
		return $this->request_row(
			array(
				'id'      => $id,
				'dry_run' => (string) wp_json_encode(
					array(
						'supported' => true,
						'summary'   => $summary,
						'object'    => array(
							'type' => 'post',
							'id'   => $post,
						),
					)
				),
			)
		);
	}

	/**
	 * Makes the table answer the next read with every row.
	 */
	private function table_answers(): void {
		$this->wpdb->returns['get_results'][] = $this->rows;
	}

	public function test_each_approver_counts_only_what_they_may_decide(): void {
		$this->table_answers();
		$this->assertSame( 3, $this->requests->pending_count_for( 1 ) );

		$this->table_answers();
		$this->assertSame( 2, $this->requests->pending_count_for( 3 ), 'The second approver cannot open the secret draft, so it is not in their number.' );
	}

	public function test_the_site_wide_number_is_not_what_a_screen_shows(): void {
		$this->wpdb->returns['get_var'][] = 3;
		$this->assertSame( 3, $this->requests->pending_count(), 'Kept for WP-CLI.' );

		$this->table_answers();
		$this->assertSame( 2, $this->requests->pending_count_for( 3 ) );
	}

	public function test_one_persons_cached_count_is_never_served_to_another(): void {
		$this->table_answers();
		$this->assertSame( 3, $this->requests->pending_count_for( 1 ) );
		$queries = count( $this->wpdb->queries );

		$this->assertSame( 3, $this->requests->pending_count_for( 1 ), 'Cached for the same person.' );
		$this->assertCount( $queries, $this->wpdb->queries );

		$this->table_answers();
		$this->assertSame( 2, $this->requests->pending_count_for( 3 ) );
	}

	public function test_a_change_to_the_queue_drops_every_persons_count(): void {
		$this->table_answers();
		$this->requests->pending_count_for( 1 );
		$this->table_answers();
		$this->requests->pending_count_for( 3 );

		Requests::flush();

		$this->rows = array( $this->about( 6, 'Change the open page again', 6 ) );
		$this->table_answers();
		$this->assertSame( 1, $this->requests->pending_count_for( 1 ) );
		$this->table_answers();
		$this->assertSame( 1, $this->requests->pending_count_for( 3 ) );
	}

	public function test_the_notice_the_bar_and_the_tab_title_use_the_persons_own_count(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'dashboard' ) );
		Functions\when( 'is_admin_bar_showing' )->justReturn( true );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);
		$this->user_id = 3;

		// Notice.
		$this->table_answers();
		ob_start();
		$this->notifier->notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( '2 AI agent requests are waiting for your approval.', $notice );
		$this->assertStringNotContainsString( 'secret', $notice );

		// Admin bar.
		Requests::flush();
		$this->table_answers();
		$nodes = array();
		$bar   = \Mockery::mock();
		$bar->shouldReceive( 'add_node' )->andReturnUsing(
			static function ( $node ) use ( &$nodes ) {
				$nodes[] = $node;
			}
		);
		$this->notifier->admin_bar( $bar );
		$this->assertSame( '2 agent requests waiting', $nodes[0]['title'] );

		// Heartbeat, which feeds the tab title.
		Requests::flush();
		$this->table_answers();
		$this->assertSame( 2, $this->notifier->heartbeat( array(), array( 'agent_governance' => 'pending' ) )['agent_governance_pending'] );
	}

	public function test_an_approver_with_nothing_to_decide_sees_no_notice_and_no_bar_item(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'dashboard' ) );
		Functions\when( 'is_admin_bar_showing' )->justReturn( true );
		$this->user_caps[3] = array();
		$this->user_id      = 3;
		$this->table_answers();

		ob_start();
		$this->notifier->notice();
		$this->assertSame( '', (string) ob_get_clean() );

		$bar = \Mockery::mock();
		$bar->shouldReceive( 'add_node' )->never();
		$this->notifier->admin_bar( $bar );
	}

	public function test_the_pending_list_ability_shows_no_titles_to_an_approver_without_access(): void {
		$this->user_id = 3;
		$this->wpdb->returns['get_results'][] = $this->rows;

		$abilities = new Abilities( $this->requests, $this->approvals, $this->classifier );
		$listed    = $abilities->list_pending( array() );

		$this->assertCount( 2, $listed );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $listed ) );
	}

	public function test_the_scan_limit_filter_bounds_the_rows_read(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'agent_governance_count_scan_limit' === $hook ? 20 : $value;
			}
		);
		$this->table_answers();
		$this->requests->pending_count_for( 1 );

		$this->assertStringContainsString( "LIMIT '20'", end( $this->wpdb->queries ) );
	}
}
