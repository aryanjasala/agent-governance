<?php
/**
 * Tests for telling agent calls apart and naming the agent.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance\Tests\Unit;

use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers Classifier and the signals Context collects.
 */
final class ClassifierTest extends AgentTestCase {

	/**
	 * A REST request double.
	 *
	 * @param string $route   Route.
	 * @param string $method  Method.
	 * @param string $session Mcp-Session-Id header.
	 * @return \WP_REST_Request
	 */
	private function rest( string $route, string $method = 'POST', string $session = '' ) {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_route' )->andReturn( $route );
		$request->shouldReceive( 'get_method' )->andReturn( $method );
		$request->shouldReceive( 'get_header' )->with( 'mcp_session_id' )->andReturn( $session );

		return $request;
	}

	public function test_a_signed_in_person_in_wp_admin_is_not_an_agent(): void {
		$this->assertNull( $this->classifier->current() );
	}

	public function test_a_rest_call_without_an_application_password_is_not_an_agent(): void {
		$this->context->capture_rest( null, null, $this->rest( '/wp/v2/posts/5' ) );

		$this->assertNull( $this->classifier->current() );
	}

	public function test_each_application_password_is_its_own_agent(): void {
		$this->as_app_password( 'aaaa-1111', 'Helper' );

		$identity = $this->classifier->current();

		$this->assertSame( 'app:aaaa-1111', $identity['key'] );
		$this->assertSame( 'Helper', $identity['label'] );
		$this->assertSame( 'app-password', $identity['via'] );
		$this->assertSame( 1, $identity['user_id'] );
		$this->assertSame( '', $identity['session'] );
	}

	public function test_the_abilities_rest_api_counts_as_an_agent_even_with_a_cookie(): void {
		$this->context->capture_rest( null, null, $this->rest( '/wp-abilities/v1/abilities/core/get-site-info/run', 'GET' ) );

		$identity = $this->classifier->current();

		$this->assertSame( 'user:1', $identity['key'] );
		$this->assertSame( 'admin', $identity['label'] );
		$this->assertSame( 'abilities-api', $identity['via'] );
	}

	public function test_the_mcp_route_carries_its_session(): void {
		$this->as_app_password();
		$this->context->capture_rest( null, null, $this->rest( '/mcp/mcp-adapter-default-server', 'POST', 'sess-42' ) );

		$identity = $this->classifier->current();

		$this->assertSame( 'mcp', $identity['via'] );
		$this->assertSame( 'mcp:sess-42', $identity['session'] );
		$this->assertSame( 'app:aaaa-1111', $identity['key'] );
	}

	public function test_only_the_outermost_rest_request_is_recorded(): void {
		$this->context->capture_rest( null, null, $this->rest( '/mcp/server' ) );
		$this->context->capture_rest( null, null, $this->rest( '/wp/v2/posts' ) );

		$this->assertSame( '/mcp/server', $this->context->rest()['route'] );
	}

	public function test_an_mcp_tool_call_over_stdio_counts_as_an_agent(): void {
		$this->context->enter_mcp_tool( array() );

		$this->assertSame( 'mcp', $this->classifier->current()['via'] );

		$this->context->leave_mcp_tool( array() );
		$this->assertNull( $this->classifier->current() );
	}

	public function test_a_visitor_on_an_agent_route_is_labelled(): void {
		$this->user_id = 0;
		$this->context->capture_rest( null, null, $this->rest( '/wp-abilities/v1/abilities' ) );

		$this->assertSame( 'user:0', $this->classifier->current()['key'] );
		$this->assertSame( 'Not signed in', $this->classifier->current()['label'] );
	}

	public function test_the_is_agent_request_filter_decides(): void {
		$this->as_app_password();
		Filters\expectApplied( 'agent_governance_is_agent_request' )->once()->with( true, Mockery::type( 'array' ) )->andReturn( false );

		$this->assertNull( $this->classifier->current() );
	}

	public function test_the_is_agent_request_filter_can_add_calls(): void {
		Filters\expectApplied( 'agent_governance_is_agent_request' )->once()->with( false, Mockery::type( 'array' ) )->andReturn( true );

		$identity = $this->classifier->current();

		$this->assertSame( 'custom', $identity['via'] );
		$this->assertSame( 'user:1', $identity['key'] );
	}

	public function test_the_identity_filter_renames_the_agent(): void {
		$this->as_app_password();
		Filters\expectApplied( 'agent_governance_identity' )->once()->andReturnUsing(
			static function ( $identity ) {
				$identity['key']   = 'bot:support';
				$identity['label'] = 'Support bot';
				return $identity;
			}
		);

		$identity = $this->classifier->current();

		$this->assertSame( 'bot:support', $identity['key'] );
		$this->assertSame( 'Support bot', $identity['label'] );
	}

	public function test_an_identity_without_a_key_is_ignored(): void {
		$this->as_app_password();
		Filters\expectApplied( 'agent_governance_identity' )->once()->andReturn( array( 'key' => '' ) );

		$this->assertSame( 'app:aaaa-1111', $this->classifier->current()['key'] );
	}

	public function test_the_agent_routes_filter_adds_a_route(): void {
		Filters\expectApplied( 'agent_governance_agent_routes' )->andReturn(
			array(
				'/my-agent/v1/' => 'My Agent',
				''              => 'ignored',
			)
		);
		$this->context->capture_rest( null, null, $this->rest( '/my-agent/v1/run' ) );

		$this->assertSame( 'myagent', $this->classifier->current()['via'] );
	}

	public function test_an_approved_request_runs_as_its_agent(): void {
		$this->context->begin_replay( $this->request_row() );

		$identity = $this->classifier->current();
		$this->assertSame( 'app:aaaa-1111', $identity['key'] );
		$this->assertSame( 2, $identity['user_id'] );
		$this->assertSame( 'app:aaaa-1111@20261023T100000', $identity['session'] );

		$this->context->end_replay();
		$this->assertNull( $this->classifier->current() );
	}

	public function test_machines_are_application_passwords_and_mcp(): void {
		$this->assertFalse( $this->classifier->is_machine() );

		$this->context->capture_rest( null, null, $this->rest( '/wp-abilities/v1/abilities/x/run' ) );
		$this->assertFalse( $this->classifier->is_machine(), 'A person in wp-admin using the Abilities API may decide.' );

		$this->context->enter_mcp();
		$this->assertTrue( $this->classifier->is_machine() );
		$this->context->leave_mcp();

		$this->as_app_password();
		$this->assertTrue( $this->classifier->is_machine() );
	}

	public function test_the_mcp_route_is_a_machine(): void {
		$this->context->capture_rest( null, null, $this->rest( '/mcp/server' ) );

		$this->assertTrue( $this->classifier->is_machine() );
	}

	public function test_core_records_the_application_password_when_the_plugin_missed_it(): void {
		Functions_Helper::stub_app_password( 'bbbb-2222' );

		$app = $this->context->app_password();

		$this->assertSame( 'bbbb-2222', $app['uuid'] );
		$this->assertSame( 'app:bbbb-2222', $this->classifier->current()['key'] );
	}

	public function test_signals_list_everything_the_filters_get(): void {
		$this->as_app_password();
		$this->context->capture_rest( null, null, $this->rest( '/wp/v2/posts', 'GET' ) );

		$signals = $this->classifier->signals();

		$this->assertSame( array( 'user_id', 'app_password', 'route', 'method', 'mcp_session', 'mcp' ), array_keys( $signals ) );
		$this->assertSame( 'GET', $signals['method'] );
	}
}

/**
 * Stubs core's record of the application password a REST request used.
 */
final class Functions_Helper {

	/**
	 * Makes rest_get_authenticated_app_password() return a UUID.
	 *
	 * @param string $uuid UUID.
	 */
	public static function stub_app_password( string $uuid ): void {
		\Brain\Monkey\Functions\when( 'rest_get_authenticated_app_password' )->justReturn( $uuid );
	}
}
