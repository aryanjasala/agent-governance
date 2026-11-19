<?php
/**
 * Tests for the reports from User Session Manager that expire held requests.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Requests;
use AryanX\Axle\AgentGovernance\SessionSignals;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * The plugin is never mounted: its two actions are fired with do_action.
 */
final class SessionSignalsTest extends AgentTestCase {

	/**
	 * What was hooked with add_action: hook => list of [callback, accepted args].
	 *
	 * @var array<string, array[]>
	 */
	private $hooked = array();

	protected function setUp(): void {
		parent::setUp();

		$this->hooked = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10, $args = 1 ) {
				$this->hooked[ $hook ][] = array( $callback, (int) $args );
				return true;
			}
		);
		( new SessionSignals( $this->approvals ) )->register();
	}

	/**
	 * Fires a hook the way do_action does: every callback, with as many arguments as it accepts.
	 *
	 * @param string $hook Hook name.
	 * @param mixed  ...$args Arguments.
	 */
	private function do_action_stub( string $hook, ...$args ): void {
		foreach ( $this->hooked[ $hook ] ?? array() as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
		}
	}

	public function test_it_listens_with_plain_add_action(): void {
		$this->assertArrayHasKey( 'user_session_manager_not_me_reported', $this->hooked );
		$this->assertArrayHasKey( 'user_session_manager_user_signed_out_everywhere', $this->hooked );
		$this->assertSame( 2, $this->hooked['user_session_manager_not_me_reported'][0][1], 'user and report' );
		$this->assertSame( 3, $this->hooked['user_session_manager_user_signed_out_everywhere'][0][1], 'user, count and context' );
	}

	public function test_a_not_me_report_expires_the_users_waiting_requests(): void {
		$this->wpdb->returns['get_results'][] = array( $this->request_row( array( 'id' => 7 ) ) );
		Actions\expectDone( 'agent_governance_requests_expired_for_user' )->once()->with( 2, 1, 'not_me' );

		$this->do_action_stub( 'user_session_manager_not_me_reported', 2, array( 'session' => 'x' ) );

		$this->assertSame( Requests::EXPIRED, $this->wpdb->updated[0]['data']['status'] );
		$this->assertStringContainsString( 'not theirs', $this->wpdb->updated[0]['data']['reason'] );
		$this->assertSame( Requests::PENDING, $this->wpdb->updated[0]['where']['status'], 'Only a request still waiting expires.' );
	}

	public function test_an_older_version_that_sends_the_user_object_works_too(): void {
		$this->wpdb->returns['get_results'][] = array( $this->request_row( array( 'id' => 7 ) ) );

		$this->do_action_stub( 'user_session_manager_not_me_reported', (object) array( 'ID' => 2 ), array() );

		$this->assertStringContainsString( "user_id = '2'", $this->wpdb->queries[0] );
	}

	public function test_signing_out_everywhere_expires_them_too(): void {
		$this->wpdb->returns['get_results'][] = array( $this->request_row( array( 'id' => 7 ) ), $this->request_row( array( 'id' => 8 ) ) );
		Actions\expectDone( 'agent_governance_requests_expired_for_user' )->once()->with( 2, 2, 'signed_out_everywhere' );

		$this->do_action_stub( 'user_session_manager_user_signed_out_everywhere', 2, 3, 'self' );

		$this->assertCount( 2, $this->wpdb->updated );
		$this->assertStringContainsString( 'signed out everywhere', $this->wpdb->updated[0]['data']['reason'] );
	}

	public function test_a_user_with_nothing_waiting_changes_nothing(): void {
		Actions\expectDone( 'agent_governance_requests_expired_for_user' )->never();

		$this->do_action_stub( 'user_session_manager_user_signed_out_everywhere', 9, 0, '' );

		$this->assertSame( array(), $this->wpdb->updated );
	}

	public function test_the_filter_can_turn_it_off(): void {
		Filters\expectApplied( 'agent_governance_expire_on_session_report' )->once()->with( true, 2, 'not_me' )->andReturn( false );

		$this->do_action_stub( 'user_session_manager_not_me_reported', 2, array() );

		$this->assertSame( array(), $this->wpdb->queries, 'Nothing was even looked up.' );
	}

	public function test_a_report_without_a_user_is_ignored(): void {
		$this->do_action_stub( 'user_session_manager_not_me_reported', 0, array() );

		$this->assertSame( array(), $this->wpdb->queries );
	}
}
