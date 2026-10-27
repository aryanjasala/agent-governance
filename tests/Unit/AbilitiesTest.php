<?php
/**
 * Tests for Agent Governance's own abilities.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Abilities;
use AryanX\Axle\AgentGovernance\Requests;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Abilities.
 */
final class AbilitiesTest extends AgentTestCase {

	/**
	 * The abilities.
	 *
	 * @var Abilities
	 */
	private Abilities $abilities;

	protected function setUp(): void {
		parent::setUp();
		$this->abilities = new Abilities( $this->requests, $this->approvals, $this->classifier );
	}

	public function test_it_registers_four_public_abilities_with_annotations(): void {
		Functions\when( 'wp_register_ability_category' )->justReturn( true );
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ) {
				$registered[ $name ] = $args;
			}
		);

		$this->abilities->register();
		$this->assertSame( 10, has_action( 'wp_abilities_api_init', array( $this->abilities, 'register_abilities' ) ) );
		$this->abilities->register_category();
		$this->abilities->register_abilities();

		$this->assertSame( array( 'agent-governance/get-request', 'agent-governance/list-my-requests', 'agent-governance/list-pending', 'agent-governance/decide-request' ), array_keys( $registered ) );
		foreach ( $registered as $args ) {
			$this->assertTrue( $args['meta']['public'] );
			$this->assertTrue( $args['meta']['mcp']['public'] );
			$this->assertTrue( $args['meta']['show_in_rest'] );
		}
		$this->assertTrue( $registered['agent-governance/get-request']['meta']['annotations']['readonly'] );
		$this->assertTrue( $registered['agent-governance/decide-request']['meta']['annotations']['destructive'] );

		$this->caps = array();
		$this->assertFalse( $registered['agent-governance/decide-request']['permission_callback']() );
		$this->assertTrue( $registered['agent-governance/list-my-requests']['permission_callback']() );
	}

	public function test_an_agent_can_never_decide_a_request(): void {
		$this->as_app_password();

		$error = $this->abilities->decide_request(
			array(
				'id'       => 7,
				'decision' => 'approve',
			)
		);

		$this->assertSame( 'agent_governance_agent_cannot_decide', $error->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $error->get_error_data() );
		$this->assertSame( array(), $this->wpdb->updated );
	}

	public function test_a_person_can_reject_through_the_ability(): void {
		$this->wpdb->returns['get_row'][] = $this->request_row();
		$this->wpdb->returns['get_row'][] = $this->request_row(
			array(
				'status' => Requests::REJECTED,
				'reason' => 'No.',
			)
		);

		$result = $this->abilities->decide_request(
			array(
				'id'       => 7,
				'decision' => 'reject',
				'reason'   => 'No.',
			)
		);

		$this->assertSame( Requests::REJECTED, $result['status'] );
		$this->assertSame( 'No.', $result['reason'] );
	}

	public function test_get_request_is_for_its_user_or_an_approver(): void {
		$this->user_id = 2;
		$this->caps    = array();
		$this->wpdb->returns['get_row'][] = $this->request_row();
		$this->assertTrue( $this->abilities->can_see_request( array( 'id' => 7 ) ) );

		$this->user_id                    = 3;
		$this->wpdb->returns['get_row'][] = $this->request_row();
		$this->assertFalse( $this->abilities->can_see_request( array( 'id' => 7 ) ) );

		$this->user_id = 0;
		$this->assertFalse( $this->abilities->can_see_request( array( 'id' => 7 ) ) );

		$this->user_id = 1;
		$this->caps    = array( 'agent_governance_approve' => true );
		$this->assertTrue( $this->abilities->can_see_request( array( 'id' => 7 ) ) );
	}

	public function test_get_request_returns_the_status_and_result(): void {
		$this->user_id                    = 2;
		$this->wpdb->returns['get_row'][] = $this->request_row(
			array(
				'status'     => Requests::EXECUTED,
				'decided_at' => '2026-10-23 10:00:00',
				'result'     => '{"deleted":5}',
			)
		);

		$out = $this->abilities->get_request( array( 'id' => 7 ) );

		$this->assertSame( 'executed', $out['status'] );
		$this->assertSame( 'Approved and executed', $out['message'] );
		$this->assertSame( array( 'deleted' => 5 ), $out['result'] );
		$this->assertSame( 'POST my/ability', $out['call'] );
		$this->assertSame( 'Deletes it.', $out['preview'] );
		$this->assertSame( '', $out['expires'] );
		$this->assertSame( '2026-10-23T10:00:00+00:00', $out['decided_at'] );
	}

	public function test_get_request_hides_other_peoples_requests(): void {
		$this->user_id                    = 3;
		$this->caps                       = array();
		$this->wpdb->returns['get_row'][] = $this->request_row();

		$this->assertSame( 'agent_governance_not_found', $this->abilities->get_request( array( 'id' => 7 ) )->get_error_code() );
	}

	public function test_get_request_expires_an_overdue_request(): void {
		$old                              = $this->request_row( array( 'created' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
		$this->wpdb->returns['get_row'][] = $old;
		$this->wpdb->returns['get_row'][] = array_merge( $old, array( 'status' => Requests::EXPIRED ) );

		$this->assertSame( 'expired', $this->abilities->get_request( array( 'id' => 7 ) )['status'] );
	}

	public function test_list_my_requests_is_scoped_to_the_application_password(): void {
		$this->as_app_password( 'cccc-3333' );
		$this->wpdb->returns['get_results'][] = array( $this->request_row() );

		$list = $this->abilities->list_my_requests( array( 'status' => 'pending' ) );

		$this->assertCount( 1, $list );
		$this->assertStringContainsString( "status = 'pending' AND identity = 'app:cccc-3333' AND user_id = '1'", $this->wpdb->queries[0] );
	}

	public function test_list_pending_drops_overdue_requests(): void {
		$this->wpdb->returns['get_results'][] = array(
			$this->request_row(),
			$this->request_row(
				array(
					'id'      => 8,
					'created' => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
				)
			),
		);

		$this->assertSame( array( 7 ), array_column( $this->abilities->list_pending( array() ), 'id' ) );
	}
}
