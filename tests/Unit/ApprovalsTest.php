<?php
/**
 * Tests for approving, rejecting, running and expiring held requests.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Approvals;
use AryanX\Axle\AgentGovernance\Requests;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Approvals.
 */
final class ApprovalsTest extends AgentTestCase {

	/**
	 * Queues the rows get() returns.
	 *
	 * @param array ...$rows Rows.
	 */
	private function rows( array ...$rows ): void {
		foreach ( $rows as $row ) {
			$this->wpdb->returns['get_row'][] = $row;
		}
	}

	/**
	 * The update made to the request with a status.
	 *
	 * @param string $key Column.
	 * @return array[]
	 */
	private function updates_with( string $key ): array {
		return array_values(
			array_filter(
				$this->wpdb->updated,
				static function ( $update ) use ( $key ) {
					return isset( $update['data'][ $key ] );
				}
			)
		);
	}

	public function test_approving_runs_the_ability_as_the_agents_user_and_stores_the_result(): void {
		$ability      = new \Agg_Ability_Double();
		$seen_user    = null;
		$ability->run = function ( $input ) use ( &$seen_user ) {
			$seen_user = $this->user_id;
			return array( 'deleted' => $input['id'] );
		};
		Functions\expect( 'wp_get_ability' )->once()->with( 'my/ability' )->andReturn( $ability );
		$this->rows( $this->request_row(), $this->request_row( array( 'status' => Requests::EXECUTED ) ) );
		Actions\expectDone( 'agent_governance_request_approved' )->once()->with( Mockery::on( static function ( $r ) { return Requests::APPROVED === $r['status']; } ), 1 ); // phpcs:ignore
		Actions\expectDone( 'agent_governance_request_executed' )->once()->with( Mockery::type( 'array' ), array( 'deleted' => 5 ) );

		$result = $this->approvals->approve( 7, 1 );

		$this->assertSame( Requests::EXECUTED, $result['status'] );
		$this->assertSame( 2, $seen_user, 'It ran as the user the agent signed in as.' );
		$this->assertSame( 1, $this->user_id, 'The approver is back afterwards.' );
		$this->assertSame( array( array( 'id' => 5, 'password' => 'hunter2' ) ), $ability->executed ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertNull( $this->context->replay() );

		$first = $this->wpdb->updated[0];
		$this->assertSame( Requests::APPROVED, $first['data']['status'] );
		$this->assertSame( Requests::PENDING, $first['where']['status'], 'Only a pending request can be approved.' );

		$final = $this->updates_with( 'result' )[0]['data'];
		$this->assertSame( Requests::EXECUTED, $final['status'] );
		$this->assertSame( '{"deleted":5}', $final['result'] );
		$this->assertSame( '{"id":5,"password":"[redacted]"}', $final['input'], 'Secrets go once it ran.' );
	}

	public function test_a_failing_ability_marks_the_request_failed(): void {
		$ability      = new \Agg_Ability_Double();
		$ability->run = static function () {
			return new \WP_Error( 'nope', 'No.' );
		};
		Functions\when( 'wp_get_ability' )->justReturn( $ability );
		$this->rows( $this->request_row(), $this->request_row( array( 'status' => Requests::FAILED ) ) );

		$this->approvals->approve( 7, 1 );

		$final = $this->updates_with( 'result' )[0]['data'];
		$this->assertSame( Requests::FAILED, $final['status'] );
		$this->assertSame( '{"code":"nope","message":"No.","data":""}', $final['result'] );
	}

	public function test_a_missing_ability_fails(): void {
		Functions\when( 'wp_get_ability' )->justReturn( null );

		$result = ( new Approvals( $this->requests, $this->context ) )->execute( $this->request_row() );

		$this->assertSame( 'agent_governance_ability_missing', $result->get_error_code() );
	}

	public function test_an_exception_becomes_an_error(): void {
		$ability      = new \Agg_Ability_Double();
		$ability->run = static function () {
			throw new \RuntimeException( 'kaboom' );
		};
		Functions\when( 'wp_get_ability' )->justReturn( $ability );

		$result = $this->approvals->execute( $this->request_row() );

		$this->assertSame( 'kaboom', $result->get_error_message() );
		$this->assertSame( 1, $this->user_id );
	}

	public function test_approving_a_rest_call_sends_it_again(): void {
		$sent = null;
		Functions\expect( 'rest_do_request' )->once()->andReturnUsing(
			function ( $request ) use ( &$sent ) {
				$sent = $request;
				return new \Agg_Test_Response( array( 'deleted' => true ), 200 );
			}
		);
		Functions\when( 'rest_ensure_response' )->returnArg( 1 );
		Functions\when( 'rest_get_server' )->justReturn( null );
		$request = $this->request_row(
			array(
				'source' => 'rest',
				'target' => '/wp/v2/posts/5',
				'method' => 'DELETE',
				'input'  => '{"query":{"force":"true"},"body":{"note":"x"}}',
			)
		);

		$approvals = new Approvals(
			$this->requests,
			$this->context,
			static function ( $method, $route ) {
				return new \Agg_Built_Request( $method, $route );
			}
		);

		$result = $approvals->execute( $request );

		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( 'application/json', $sent->headers['content-type'] );
		$this->assertSame( 'DELETE', $sent->method );
		$this->assertSame( '/wp/v2/posts/5', $sent->route );
		$this->assertSame( array( 'force' => 'true' ), $sent->query );
		$this->assertSame( '{"note":"x"}', $sent->body );
		$this->assertSame( array( 'status' => 200, 'data' => array( 'deleted' => true ) ), Approvals::result_data( $result ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( Approvals::failed( $result ) );
		$this->assertTrue( Approvals::failed( new \Agg_Test_Response( null, 403 ) ) );
	}

	public function test_rejecting_stores_the_reason(): void {
		$this->rows( $this->request_row(), $this->request_row( array( 'status' => Requests::REJECTED, 'reason' => 'Wrong post.' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Actions\expectDone( 'agent_governance_request_rejected' )->once()->with( Mockery::type( 'array' ), 1, 'Wrong post.' );

		$result = $this->approvals->reject( 7, 1, ' Wrong post. ' );

		$this->assertSame( Requests::REJECTED, $result['status'] );
		$data = $this->wpdb->updated[0]['data'];
		$this->assertSame( 'Wrong post.', $data['reason'] );
		$this->assertSame( '{"id":5,"password":"[redacted]"}', $data['input'] );
	}

	public function test_only_pending_requests_can_be_decided(): void {
		$this->rows( $this->request_row( array( 'status' => Requests::REJECTED ) ) );
		$this->assertSame( 'agent_governance_already_decided', $this->approvals->approve( 7, 1 )->get_error_code() );

		$this->assertSame( 'agent_governance_not_found', $this->approvals->reject( 99, 1 )->get_error_code() );
	}

	public function test_a_request_someone_else_decided_first_is_refused(): void {
		$this->rows( $this->request_row() );
		$this->wpdb->update_result = 0;

		$this->assertSame( 'agent_governance_already_decided', $this->approvals->approve( 7, 1 )->get_error_code() );
	}

	public function test_an_overdue_request_expires_instead(): void {
		$this->rows( $this->request_row( array( 'created' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ) );
		Actions\expectDone( 'agent_governance_request_expired' )->once();

		$error = $this->approvals->approve( 7, 1 );

		$this->assertSame( 'agent_governance_expired', $error->get_error_code() );
		$this->assertSame( Requests::EXPIRED, $this->wpdb->updated[0]['data']['status'] );
	}

	public function test_expire_overdue_expires_each_one(): void {
		$this->wpdb->returns['get_results'][] = array( $this->request_row( array( 'id' => 1 ) ), $this->request_row( array( 'id' => 2 ) ) );
		Actions\expectDone( 'agent_governance_request_expired' )->twice();

		$this->assertSame( 2, $this->approvals->expire_overdue() );
	}

	public function test_an_already_decided_request_does_not_expire(): void {
		$this->wpdb->update_result = 0;
		Actions\expectDone( 'agent_governance_request_expired' )->never();

		$this->assertFalse( $this->approvals->expire( $this->request_row() ) );
	}

	public function test_a_revoked_application_password_stops_an_approved_call(): void {
		\WP_Application_Passwords::$items = array();
		Functions\expect( 'wp_get_ability' )->never();
		$this->rows( $this->request_row() );
		Actions\expectDone( 'agent_governance_request_approved' )->never();
		Actions\expectDone( 'agent_governance_request_expired' )->once()->with( Mockery::type( 'array' ), Mockery::type( 'string' ) );

		$result = $this->approvals->approve( 7, 1 );

		$this->assertSame( 'agent_governance_credential_revoked', $result->get_error_code() );
		$this->assertSame( 410, $result->get_error_data()['status'] );
		$expired = $this->updates_with( 'reason' )[0]['data'];
		$this->assertSame( Requests::EXPIRED, $expired['status'], 'The request ends, it does not wait for another click.' );
		$this->assertStringContainsString( 'revoked', $expired['reason'] );
		$this->assertCount( 1, $this->wpdb->updated, 'Never marked approved.' );
	}

	public function test_a_password_that_belongs_to_another_user_does_not_count(): void {
		\WP_Application_Passwords::$items = array(
			1 => array( 'aaaa-1111' => array( 'uuid' => 'aaaa-1111', 'name' => 'Not theirs' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
		$this->rows( $this->request_row() );

		$this->assertSame( 'agent_governance_credential_revoked', $this->approvals->approve( 7, 1 )->get_error_code() );
	}

	public function test_a_deleted_user_stops_an_approved_call(): void {
		unset( $this->users[2] );
		$this->rows( $this->request_row() );

		$this->assertSame( 'agent_governance_user_gone', $this->approvals->approve( 7, 1 )->get_error_code() );
	}

	public function test_the_password_is_checked_again_right_before_the_call_runs(): void {
		\WP_Application_Passwords::$items = array();
		Functions\expect( 'wp_get_ability' )->never();

		$result = $this->approvals->execute( $this->request_row() );

		$this->assertSame( 'agent_governance_credential_revoked', $result->get_error_code() );
		$this->assertSame( 1, $this->user_id, 'Nobody was switched to.' );
	}

	public function test_a_call_without_an_application_password_needs_only_its_user(): void {
		$ability = new \Agg_Ability_Double();
		Functions\expect( 'wp_get_ability' )->once()->andReturn( $ability );
		\WP_Application_Passwords::$items = array();

		$result = $this->approvals->execute( $this->request_row( array( 'identity' => 'user:2' ) ) );

		$this->assertNull( $result );
		$this->assertCount( 1, $ability->executed );
	}

	public function test_rejecting_still_works_after_the_password_was_revoked(): void {
		\WP_Application_Passwords::$items = array();
		$this->rows( $this->request_row(), $this->request_row( array( 'status' => Requests::REJECTED ) ) );

		$result = $this->approvals->reject( 7, 1, 'No.' );

		$this->assertSame( Requests::REJECTED, $result['status'] );
	}

	public function test_expiring_for_a_user_covers_only_their_application_password_requests(): void {
		$this->wpdb->returns['get_results'][] = array( $this->request_row( array( 'id' => 7 ) ), $this->request_row( array( 'id' => 8 ) ) );

		$count = $this->approvals->expire_for_user( 2, 'Reported.' );

		$this->assertSame( 2, $count );
		$this->assertStringContainsString( "identity LIKE 'app:%'", $this->wpdb->queries[0] );
		$this->assertStringContainsString( "user_id = '2'", $this->wpdb->queries[0] );
		$this->assertSame( 'Reported.', $this->wpdb->updated[0]['data']['reason'] );
	}
}
