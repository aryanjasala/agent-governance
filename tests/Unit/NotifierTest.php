<?php
/**
 * Tests for telling approvers.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Notifier;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Notifier.
 */
final class NotifierTest extends AgentTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->options['blogname'] = 'Test site';
		Functions\when( 'get_users' )->justReturn(
			array(
				(object) array( 'user_email' => 'admin@example.com' ),
				(object) array( 'user_email' => 'admin@example.com' ),
				(object) array( 'user_email' => 'not-an-email' ),
			)
		);
	}

	public function test_it_adds_the_notice(): void {
		$this->notifier->register();

		$this->assertSame( 10, has_action( 'admin_notices', array( $this->notifier, 'notice' ) ) );
	}

	public function test_the_approver_capability_filter(): void {
		$this->assertSame( 'agent_governance_approve', Notifier::capability() );

		Filters\expectApplied( 'agent_governance_approver_capability' )->once()->andReturn( 'manage_options' );
		$this->assertSame( 'manage_options', Notifier::capability() );

		Filters\expectApplied( 'agent_governance_approver_capability' )->once()->andReturn( '' );
		$this->assertSame( 'agent_governance_approve', Notifier::capability(), 'An empty capability falls back.' );
	}

	public function test_recipients_are_the_approvers_once_each(): void {
		$this->assertSame( array( 'admin@example.com' ), $this->notifier->recipients( $this->request_row() ) );
	}

	public function test_the_notify_recipients_filter(): void {
		Filters\expectApplied( 'agent_governance_notify_recipients' )->once()->with( Mockery::type( 'array' ), Mockery::type( 'array' ) )->andReturn( array( 'security@example.com', 'junk' ) );

		$this->assertSame( array( 'security@example.com' ), $this->notifier->recipients( $this->request_row() ) );
	}

	public function test_a_held_request_emails_the_approvers(): void {
		Functions\expect( 'wp_mail' )->once()->with(
			array( 'admin@example.com' ),
			'[Test site] Agent request #7 needs approval',
			Mockery::on(
				static function ( $message ) {
					return false !== strpos( $message, 'Helper' ) && false !== strpos( $message, 'POST my/ability' ) && false !== strpos( $message, 'Deletes it.' ) && false !== strpos( $message, 'request=7' );
				}
			),
			array()
		)->andReturn( true );

		$this->assertTrue( $this->notifier->request_held( $this->request_row() ) );
	}

	public function test_the_notification_email_filter_changes_or_skips_it(): void {
		Filters\expectApplied( 'agent_governance_notification_email' )->once()->andReturnUsing(
			static function ( $email ) {
				$email['subject'] = 'Custom';
				return $email;
			}
		);
		Functions\expect( 'wp_mail' )->once()->with( Mockery::any(), 'Custom', Mockery::any(), Mockery::any() )->andReturn( true );
		$this->assertTrue( $this->notifier->request_held( $this->request_row() ) );

		Filters\expectApplied( 'agent_governance_notification_email' )->once()->andReturn( false );
		$this->assertFalse( $this->notifier->request_held( $this->request_row() ) );
	}

	public function test_no_email_when_it_is_off_or_nobody_approves(): void {
		Functions\expect( 'wp_mail' )->never();

		$this->options['agent_governance_settings'] = array( 'email' => false );
		$this->build();
		$this->assertFalse( $this->notifier->request_held( $this->request_row() ) );

		$this->options['agent_governance_settings'] = array();
		$this->build();
		Functions\when( 'get_users' )->justReturn( array() );
		$this->assertFalse( $this->notifier->request_held( $this->request_row() ) );
	}

	public function test_the_notice_counts_waiting_requests(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'dashboard' ) );
		$this->wpdb->returns['get_var'][] = 3;
		Filters\expectApplied( 'agent_governance_notice_message' )->once()->with( '3 AI agent requests are waiting for your approval.', 3 )->andReturn( 'Three waiting.' );

		ob_start();
		$this->notifier->notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Three waiting.', $html );
		$this->assertStringContainsString( 'tab=requests', $html );
	}

	public function test_no_notice_on_the_plugin_screen_or_for_others_or_when_off(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'tools_page_agent-governance' ) );
		ob_start();
		$this->notifier->notice();
		$this->assertSame( '', ob_get_clean() );

		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'dashboard' ) );
		$this->caps = array();
		ob_start();
		$this->notifier->notice();
		$this->assertSame( '', ob_get_clean() );

		$this->caps                       = array( 'agent_governance_approve' => true );
		$this->wpdb->returns['get_var'][] = 0;
		ob_start();
		$this->notifier->notice();
		$this->assertSame( '', ob_get_clean() );

		$this->options['agent_governance_settings'] = array( 'notice' => false );
		$this->build();
		ob_start();
		$this->notifier->notice();
		$this->assertSame( '', ob_get_clean() );
	}
}
