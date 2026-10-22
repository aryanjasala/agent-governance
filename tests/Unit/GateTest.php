<?php
/**
 * Tests for applying decisions: logging, refusing and holding calls.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance\Tests\Unit;

use AryanX\AgentGovernance\Gate;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Gate.
 */
final class GateTest extends AgentTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->options['agent_governance_settings'] = array( 'rate_limit' => 0 );
		$this->options['blogname']                  = 'Test site';
		Functions\when( 'get_users' )->justReturn( array( (object) array( 'user_email' => 'admin@example.com' ) ) );
	}

	/**
	 * A call.
	 *
	 * @param string $kind   readonly, write or destructive.
	 * @param string $target Target.
	 * @return array
	 */
	private function call( string $kind = 'write', string $target = 'my/save' ): array {
		return array(
			'source' => 'ability',
			'target' => $target,
			'method' => 'POST',
			'kind'   => $kind,
			'input'  => array(
				'id'       => 5,
				'password' => 'hunter2',
			),
		);
	}

	public function test_calls_that_are_not_an_agents_pass_untouched(): void {
		$this->assertNull( $this->gate->enter( $this->call() ) );
		$this->assertNull( $this->gate->decide( $this->call() ) );
		$this->assertSame( array(), $this->wpdb->inserted );
	}

	public function test_an_allowed_call_is_logged_once_it_ran(): void {
		$this->as_app_password();
		Actions\expectDone( 'agent_governance_call_logged' )->once()->with(
			Mockery::on(
				static function ( $row ) {
					return 'allowed' === $row['decision'] && 'ok' === $row['result'] && $row['id'] > 0;
				}
			)
		);

		$frame = $this->gate->enter( $this->call() );
		$this->assertIsInt( $frame );
		$this->assertSame( array(), $this->wpdb->rows( 'log' ), 'Nothing is logged until the call ends.' );

		$this->context->add_change(
			array(
				'post_id' => 5,
				'action'  => 'updated',
			)
		);
		$this->gate->leave( $frame, array( 'ok' => true ) );
		$this->gate->leave( $frame, array( 'ok' => true ) );

		$rows = $this->wpdb->rows( 'log' );
		$this->assertCount( 1, $rows, 'A frame closes once.' );
		$this->assertSame( 'app:aaaa-1111', $rows[0]['identity'] );
		$this->assertSame( 'Helper', $rows[0]['agent'] );
		$this->assertSame( 'default', $rows[0]['reason'] );
		$this->assertSame( '{"id":5,"password":"[redacted]"}', $rows[0]['input'] );
		$this->assertSame( '[{"post_id":5,"action":"updated"}]', $rows[0]['changes'] );
		$this->assertStringStartsWith( 'app:aaaa-1111@', $rows[0]['session'] );
	}

	public function test_a_failed_allowed_call_logs_its_error_code(): void {
		$this->as_app_password();
		$frame = $this->gate->enter( $this->call() );

		$this->gate->leave( $frame, new \WP_Error( 'nope', 'No.' ) );

		$this->assertSame( 'allowed', $this->wpdb->rows( 'log' )[0]['decision'] );
		$this->assertSame( 'nope', $this->wpdb->rows( 'log' )[0]['result'] );
	}

	public function test_a_denied_call_gets_403_and_is_logged(): void {
		$this->as_app_password();
		$this->options['agent_governance_rules'] = array(
			array(
				'scope'   => 'default',
				'pattern' => 'my/*',
				'action'  => 'deny',
			),
		);
		Actions\expectDone( 'agent_governance_call_denied' )->once()->with( Mockery::type( 'array' ), Mockery::type( 'array' ), Mockery::on( static function ( $d ) { return 'rule' === $d['reason']; } ) ); // phpcs:ignore
		Filters\expectApplied( 'agent_governance_denied_message' )->once()->andReturn( 'Ask Aryan.' );

		$error = $this->gate->enter( $this->call() );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'agent_governance_denied', $error->get_error_code() );
		$this->assertSame( 'Ask Aryan.', $error->get_error_message() );
		$this->assertSame( array( 'status' => 403 ), $error->get_error_data() );
		$row = $this->wpdb->rows( 'log' )[0];
		$this->assertSame( 'denied', $row['decision'] );
		$this->assertMatchesRegularExpression( '/^rule:[a-z0-9]{8}$/', $row['reason'] );
	}

	public function test_a_paused_write_gets_423(): void {
		$this->as_app_password();
		$this->options['agent_governance_settings']['paused'] = true;

		$error = $this->gate->enter( $this->call() );

		$this->assertSame( 'agent_governance_paused', $error->get_error_code() );
		$this->assertSame( array( 'status' => 423 ), $error->get_error_data() );
		$this->assertSame( 'paused', $this->wpdb->rows( 'log' )[0]['reason'] );
	}

	public function test_a_call_over_the_rate_limit_gets_429(): void {
		$this->as_app_password();
		$this->options['agent_governance_settings']['rate_limit'] = 2;
		$this->wpdb->returns['get_var'][]                         = 2;

		$error = $this->gate->enter( $this->call( 'readonly' ) );

		$this->assertSame( 'agent_governance_rate_limited', $error->get_error_code() );
		$this->assertSame( array( 'status' => 429 ), $error->get_error_data() );
		$this->assertStringContainsString( '2 calls per minute', $error->get_error_message() );
	}

	public function test_a_held_call_is_queued_with_its_preview_and_the_agent_gets_202(): void {
		$this->as_app_password();
		$this->wpdb->returns['get_row'][] = null; // No earlier session.
		$this->wpdb->returns['get_row'][] = $this->request_row( array( 'id' => 1 ) );
		Actions\expectDone( 'agent_governance_request_held' )->once()->with( Mockery::type( 'array' ), Mockery::type( 'array' ), Mockery::type( 'array' ) );
		Filters\expectApplied( 'agent_governance_pending_message' )->once()->with( Mockery::type( 'string' ), 1, Mockery::type( 'array' ) )->andReturnUsing(
			static function ( $message, $id ) {
				return 'Held as #' . $id;
			}
		);
		Functions\expect( 'wp_mail' )->once()->andReturn( true );

		$error = $this->gate->enter( $this->call( 'destructive' ) );

		$this->assertSame( 'agent_governance_pending', $error->get_error_code() );
		$this->assertSame( 'Held as #1', $error->get_error_message() );
		$data = $error->get_error_data();
		$this->assertSame( 202, $data['status'] );
		$this->assertSame( 1, $data['request_id'] );
		$this->assertSame( 'agent-governance/get-request', $data['poll']['ability'] );

		$queued = $this->wpdb->rows( 'requests' )[0];
		$this->assertSame( 'my/save', $queued['target'] );
		$this->assertSame( '{"id":5,"password":"hunter2"}', $queued['input'], 'Kept as sent, so it can run after approval.' );
		$this->assertStringContainsString( 'No preview', $queued['dry_run'] );

		$held = $this->wpdb->rows( 'log' )[0];
		$this->assertSame( 'held', $held['decision'] );
		$this->assertSame( 1, $held['request_id'] );
		$this->assertSame( $queued['session'], $held['session'] );
		$this->assertStringContainsString( '[redacted]', $held['input'] );
	}

	public function test_the_default_pending_message_names_the_request_and_the_poll(): void {
		$this->as_app_password();
		$this->options['agent_governance_settings']['email'] = false;

		$error = $this->gate->enter( $this->call( 'destructive' ) );

		$this->assertStringContainsString( 'request #1', $error->get_error_message() );
		$this->assertStringContainsString( 'agent-governance/get-request', $error->get_error_message() );
		$this->assertStringContainsString( '{"id":1}', $error->get_error_message() );
	}

	public function test_a_call_that_would_fail_its_permission_check_is_not_queued(): void {
		$this->as_app_password();

		$result = $this->gate->enter(
			$this->call( 'destructive' ),
			static function () {
				return false;
			}
		);

		$this->assertNull( $result );
		$this->assertSame( array(), $this->wpdb->inserted );
	}

	public function test_a_queue_failure_refuses_the_call(): void {
		$this->as_app_password();
		$this->wpdb->fail_insert = true;

		$error = $this->gate->enter( $this->call( 'destructive' ) );

		$this->assertSame( 'agent_governance_queue_failed', $error->get_error_code() );
	}

	public function test_an_approved_request_runs_and_is_logged_as_executed(): void {
		$this->context->begin_replay( $this->request_row() );

		$frame = $this->gate->enter( $this->call( 'destructive' ) );
		$this->gate->leave( $frame, array( 'done' => true ) );

		$row = $this->wpdb->rows( 'log' )[0];
		$this->assertSame( 'executed', $row['decision'] );
		$this->assertSame( 'approved', $row['reason'] );
		$this->assertSame( 7, $row['request_id'] );
		$this->assertSame( 'app:aaaa-1111@20261023T100000', $row['session'] );
		$this->assertSame( 2, $row['user_id'] );

		$decided = $this->gate->decide( $this->call() );
		$this->assertSame( 'approved', $decided['decision']['reason'] );
	}

	public function test_an_approved_request_that_fails_is_logged_as_failed(): void {
		$this->context->begin_replay( $this->request_row() );
		$response = new \Agg_Test_Response( array( 'code' => 'rest_post_invalid_id' ), 404 );

		$this->gate->leave( $this->gate->enter( $this->call() ), $response );

		$this->assertSame( 'failed', $this->wpdb->rows( 'log' )[0]['decision'] );
		$this->assertSame( 'rest_post_invalid_id', $this->wpdb->rows( 'log' )[0]['result'] );
	}

	public function test_open_frames_are_logged_at_shutdown(): void {
		$this->as_app_password();
		$this->gate->enter( $this->call() );
		$this->gate->enter( $this->call( 'readonly' ) );

		$this->gate->close_open_frames();

		$this->assertCount( 2, $this->wpdb->rows( 'log' ) );
		$this->assertFalse( $this->context->has_frame() );
	}

	public function test_result_codes(): void {
		$ok    = new \Agg_Test_Response( array(), 201 );
		$plain = new \Agg_Test_Response( 'x', 500 );

		$this->assertSame( 'ok', Gate::result_code( array() ) );
		$this->assertSame( 'ok', Gate::result_code( $ok ) );
		$this->assertSame( 'http_500', Gate::result_code( $plain ) );
		$this->assertSame( 'boom', Gate::result_code( new \WP_Error( 'boom' ) ) );
	}

	public function test_identity_is_the_classifiers(): void {
		$this->assertNull( $this->gate->identity() );
		$this->as_app_password();
		$this->assertSame( 'app:aaaa-1111', $this->gate->identity()['key'] );
	}
}
