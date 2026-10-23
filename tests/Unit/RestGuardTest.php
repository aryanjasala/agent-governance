<?php
/**
 * Tests for the REST guard.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance\Tests\Unit;

use AryanX\AgentGovernance\Guards\RestGuard;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers RestGuard.
 */
final class RestGuardTest extends AgentTestCase {

	/**
	 * The guard.
	 *
	 * @var RestGuard
	 */
	private RestGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->options['agent_governance_settings'] = array(
			'rate_limit' => 0,
			'email'      => false,
		);
		$this->guard = new RestGuard( $this->gate );
	}

	/**
	 * A REST request double.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $query  Query parameters.
	 * @param array  $json   JSON body.
	 * @param array  $form   Form body.
	 * @return \WP_REST_Request
	 */
	private function request( string $method, string $route, array $query = array(), array $json = array(), array $form = array() ) {
		$request = Mockery::mock( 'WP_REST_Request' );
		$request->shouldReceive( 'get_route' )->andReturn( $route );
		$request->shouldReceive( 'get_method' )->andReturn( $method );
		$request->shouldReceive( 'get_query_params' )->andReturn( $query );
		$request->shouldReceive( 'get_json_params' )->andReturn( $json );
		$request->shouldReceive( 'get_body_params' )->andReturn( $form );

		return $request;
	}

	public function test_it_hooks_before_and_after_the_handler(): void {
		$this->guard->register();

		$this->assertSame( 5, has_filter( 'rest_pre_dispatch', array( $this->guard, 'guard' ) ) );
		$this->assertSame( 1000, has_filter( 'rest_request_after_callbacks', array( $this->guard, 'after' ) ) );
		$this->assertSame( 1000, has_filter( 'rest_post_dispatch', array( $this->guard, 'post_dispatch' ) ) );
	}

	public function test_kind_follows_the_method(): void {
		$this->assertSame( 'readonly', RestGuard::kind( 'GET' ) );
		$this->assertSame( 'readonly', RestGuard::kind( 'OPTIONS' ) );
		$this->assertSame( 'write', RestGuard::kind( 'PATCH' ) );
		$this->assertSame( 'destructive', RestGuard::kind( 'DELETE' ) );
	}

	public function test_an_earlier_answer_is_kept(): void {
		$this->as_app_password();
		$error = new \WP_Error( 'frozen' );

		$this->assertSame( $error, $this->guard->guard( $error, null, $this->request( 'DELETE', '/wp/v2/posts/1' ) ) );
	}

	public function test_an_agent_call_another_plugin_refused_first_is_logged(): void {
		$this->as_app_password();
		$frozen = new \WP_Error( 'site_read_only_mode', 'This site is frozen.', array( 'status' => 423 ) );

		$this->assertSame( $frozen, $this->guard->guard( $frozen, null, $this->request( 'POST', '/wp/v2/posts/5', array(), array( 'title' => 'x' ) ) ) );

		$row = $this->wpdb->rows( 'log' )[0];
		$this->assertSame( 'refused', $row['decision'] );
		$this->assertSame( 'site_read_only_mode', $row['result'] );
		$this->assertSame( 'rest', $row['source'] );
		$this->assertSame( '/wp/v2/posts/5', $row['target'] );
		$this->assertSame( 'write', $row['kind'] );
	}

	public function test_a_refused_ability_run_is_logged_under_the_ability(): void {
		$this->as_app_password();
		$request = $this->request( 'DELETE', '/wp-abilities/v1/abilities/user-session-manager/sign-out-user/run' );
		$request->shouldReceive( 'get_param' )->with( 'input' )->andReturn( array( 'user' => 952 ) );
		\Brain\Monkey\Functions\expect( 'wp_get_ability' )->once()->with( 'user-session-manager/sign-out-user' )->andReturn( new \Agg_Ability_Double( array( 'annotations' => array( 'destructive' => true ) ) ) );

		$this->guard->guard( new \WP_Error( 'site_read_only_mode', 'Frozen.' ), null, $request );

		$row = $this->wpdb->rows( 'log' )[0];
		$this->assertSame( 'ability', $row['source'] );
		$this->assertSame( 'user-session-manager/sign-out-user', $row['target'] );
		$this->assertSame( 'destructive', $row['kind'] );
		$this->assertSame( 'DELETE', $row['method'] );
		$this->assertSame( '{"user":952}', $row['input'] );
	}

	public function test_a_person_refused_by_another_plugin_is_not_logged(): void {
		$this->guard->guard( new \WP_Error( 'site_read_only_mode', 'Frozen.' ), null, $this->request( 'POST', '/wp/v2/posts/5' ) );

		$this->assertSame( array(), $this->wpdb->inserted );
	}

	public function test_a_rate_limited_response_gets_retry_after(): void {
		$response = new class( array( 'code' => 'agent_governance_rate_limited', 'data' => array( 'status' => 429, 'retry_after' => 42 ) ) ) extends \Agg_Test_Response { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			/** @var array<string, string> */
			public $headers = array();

			public function header( $key, $value ) {
				$this->headers[ $key ] = $value;
			}
		};

		$this->assertSame( $response, $this->guard->post_dispatch( $response, null, $this->request( 'GET', '/wp/v2/types' ) ) );
		$this->assertSame( array( 'Retry-After' => '42' ), $response->headers );

		$other = new class( array( 'code' => 'agent_governance_denied', 'data' => array( 'status' => 403 ) ) ) extends \Agg_Test_Response { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			/** @var array<string, string> */
			public $headers = array();

			public function header( $key, $value ) {
				$this->headers[ $key ] = $value;
			}
		};
		RestGuard::retry_after( $other );
		$this->assertSame( array(), $other->headers );
	}

	public function test_the_abilities_api_is_left_to_the_ability_check_but_is_not_an_agent_route(): void {
		$this->assertContains( '/wp-abilities/v1/', RestGuard::passthrough_routes() );
		$this->assertContains( '/mcp/', RestGuard::passthrough_routes() );
		$this->assertContains( '/batch/v1', RestGuard::passthrough_routes() );
	}

	public function test_routes_that_run_abilities_are_checked_per_ability(): void {
		$this->as_app_password();

		foreach ( array( '/wp-abilities/v1/abilities/my/delete/run', '/mcp/mcp-adapter-default-server', '/batch/v1' ) as $route ) {
			$this->assertNull( $this->guard->guard( null, null, $this->request( 'POST', $route ) ), $route );
		}
		$this->assertSame( array(), $this->wpdb->inserted );
	}

	public function test_the_passthrough_routes_filter(): void {
		$this->as_app_password();
		Filters\expectApplied( 'agent_governance_passthrough_routes' )->once()->andReturn( array( '/my/v1/' ) );

		$this->assertNull( $this->guard->guard( null, null, $this->request( 'DELETE', '/my/v1/thing' ) ) );
	}

	public function test_people_signed_in_with_a_cookie_are_not_checked(): void {
		$this->assertNull( $this->guard->guard( null, null, $this->request( 'DELETE', '/wp/v2/posts/1' ) ) );
		$this->assertSame( array(), $this->wpdb->inserted );
	}

	public function test_an_agent_delete_is_held(): void {
		$this->as_app_password();
		\Brain\Monkey\Functions\when( 'get_plugins' )->justReturn( array( 'hello.php' => array( 'Name' => 'Hello Dolly' ) ) );
		\Brain\Monkey\Functions\when( 'is_plugin_active' )->justReturn( true );

		$result = $this->guard->guard( null, null, $this->request( 'DELETE', '/wp/v2/plugins/hello', array( 'force' => 'true' ) ) );

		$this->assertSame( 'agent_governance_pending', $result->get_error_code() );
		$queued = $this->wpdb->rows( 'requests' )[0];
		$this->assertSame( 'rest', $queued['source'] );
		$this->assertSame( 'DELETE', $queued['method'] );
		$this->assertSame( '{"query":{"force":"true"},"body":[]}', $queued['input'] );
		$this->assertStringContainsString( 'Deletes the plugin \\"Hello Dolly\\"', $queued['dry_run'] );
	}

	public function test_an_allowed_agent_write_is_logged_after_its_handler(): void {
		$this->as_app_password();
		$request = $this->request( 'POST', '/wp/v2/posts/5', array(), array( 'title' => 'New' ) );

		$this->assertNull( $this->guard->guard( null, null, $request ) );
		$this->assertSame( array(), $this->wpdb->inserted );

		$response = new \Agg_Test_Response( array( 'id' => 5 ), 200 );
		$this->assertSame( $response, $this->guard->after( $response, array(), $request ) );
		$this->guard->post_dispatch( $response, null, $request );

		$rows = $this->wpdb->rows( 'log' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'POST', $rows[0]['method'] );
		$this->assertSame( '/wp/v2/posts/5', $rows[0]['target'] );
		$this->assertSame( '{"query":[],"body":{"title":"New"}}', $rows[0]['input'] );
	}

	public function test_a_request_that_never_reached_a_handler_is_logged_after_dispatch(): void {
		$this->as_app_password();
		$request = $this->request( 'GET', '/wp/v2/nothing' );

		$this->guard->guard( null, null, $request );
		$this->guard->post_dispatch( new \Agg_Test_Response( array( 'code' => 'rest_no_route' ), 404 ), null, $request );

		$this->assertSame( 'rest_no_route', $this->wpdb->rows( 'log' )[0]['result'] );
	}

	public function test_input_drops_routing_parameters_and_reads_form_bodies(): void {
		$input = RestGuard::input( $this->request( 'POST', '/wp/v2/posts', array( 'rest_route' => '/wp/v2/posts', '_method' => 'POST', 'context' => 'edit' ), array(), array( 'title' => 'Form' ) ) );

		$this->assertSame(
			array(
				'query' => array( 'context' => 'edit' ),
				'body'  => array( 'title' => 'Form' ),
			),
			$input
		);
	}
}
