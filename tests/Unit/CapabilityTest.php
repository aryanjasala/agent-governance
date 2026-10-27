<?php
/**
 * Tests for the approver capability: the fallback, the notice, restore, and
 * removal on deactivation.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Capability;
use AryanX\Axle\AgentGovernance\Health\SiteHealth;
use AryanX\Axle\AgentGovernance\Schema;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Capability and the capability parts of Schema, Notifier and SiteHealth.
 */
final class CapabilityTest extends AgentTestCase {

	/**
	 * Takes the approver capability off every role, as a role clean-up would.
	 */
	private function strip(): void {
		foreach ( $this->roles->role_objects as $role ) {
			$role->remove_cap( Schema::CAPABILITY );
		}
	}

	public function test_it_hooks_the_fallback_the_notice_and_the_restore_button(): void {
		$capability = new Capability();
		$capability->register();

		$this->assertSame( 10, has_filter( 'map_meta_cap', array( $capability, 'map' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $capability, 'notice' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_agent_governance_restore_capability', array( $capability, 'handle_restore' ) ) );
	}

	public function test_while_a_role_has_it_the_capability_is_left_alone(): void {
		$this->assertTrue( Capability::granted() );
		$this->assertSame( array( Schema::CAPABILITY ), ( new Capability() )->map( array( Schema::CAPABILITY ), Schema::CAPABILITY ) );
		$this->assertSame( Schema::CAPABILITY, Capability::effective() );
	}

	public function test_with_no_role_left_manage_options_stands_in(): void {
		$this->strip();

		$this->assertFalse( Capability::granted() );
		$this->assertSame( array( 'manage_options' ), ( new Capability() )->map( array( Schema::CAPABILITY ), Schema::CAPABILITY ), 'Admins can still approve.' );
		$this->assertSame( 'manage_options', Capability::effective() );
		$this->assertSame( array( 'edit_posts' ), ( new Capability() )->map( array( 'edit_posts' ), 'edit_posts' ), 'Other capabilities are untouched.' );
	}

	public function test_the_fallback_capability_filter(): void {
		$this->strip();
		Filters\expectApplied( 'agent_governance_fallback_capability' )->once()->with( 'manage_options' )->andReturn( 'manage_network' );
		$this->assertSame( array( 'manage_network' ), ( new Capability() )->map( array(), Schema::CAPABILITY ) );

		Filters\expectApplied( 'agent_governance_fallback_capability' )->once()->andReturn( '' );
		$this->assertSame( 'manage_options', Capability::fallback(), 'An empty value cannot leave the site without an approver.' );
	}

	public function test_a_filtered_approver_capability_gets_the_fallback_too(): void {
		Filters\expectApplied( 'agent_governance_approver_capability' )->andReturn( 'approve_agents' );

		$this->assertSame( array( 'manage_options' ), ( new Capability() )->map( array( 'approve_agents' ), 'approve_agents' ) );
	}

	public function test_revoke_takes_it_off_every_role_and_grant_gives_it_back_to_the_approver_roles(): void {
		$this->roles->role_objects['editor']->add_cap( Schema::CAPABILITY );
		Functions\when( 'get_role' )->alias(
			function ( $name ) {
				return $this->roles->get_role( $name );
			}
		);

		$this->assertSame( array( 'administrator', 'editor' ), Capability::revoke() );
		$this->assertFalse( Capability::granted() );

		$this->assertSame( array( 'administrator' ), Capability::grant() );
		$this->assertTrue( $this->roles->role_objects['administrator']->has_cap( Schema::CAPABILITY ) );
		$this->assertFalse( $this->roles->role_objects['editor']->has_cap( Schema::CAPABILITY ) );
		$this->assertSame( array(), Capability::grant(), 'Nothing to give twice.' );
	}

	public function test_restore_fires_its_action(): void {
		$this->strip();
		Functions\when( 'get_role' )->alias(
			function ( $name ) {
				return $this->roles->get_role( $name );
			}
		);
		Actions\expectDone( 'agent_governance_capability_restored' )->once()->with( array( 'administrator' ), 3 );

		$this->assertSame( array( 'administrator' ), Capability::restore( 3 ) );
		$this->assertTrue( Capability::granted() );
	}

	public function test_deactivating_takes_the_capability_off_and_forgets_the_version(): void {
		$this->options[ Schema::DB_VERSION_OPTION ] = Schema::DB_VERSION;
		Functions\expect( 'wp_clear_scheduled_hook' )->once()->with( Schema::CRON_HOOK );
		Actions\expectDone( 'agent_governance_capability_removed' )->once()->with( array( 'administrator' ) );

		Schema::deactivate();

		$this->assertFalse( Capability::granted(), 'Nothing is left on a role while the plugin is off.' );
		$this->assertArrayNotHasKey( Schema::DB_VERSION_OPTION, $this->options, 'The next activation sets the capability up again.' );
	}

	public function test_the_remove_on_deactivate_filter_keeps_it(): void {
		$this->options[ Schema::DB_VERSION_OPTION ] = Schema::DB_VERSION;
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Filters\expectApplied( 'agent_governance_remove_capability_on_deactivate' )->once()->with( true )->andReturn( false );
		Actions\expectDone( 'agent_governance_capability_removed' )->never();

		Schema::deactivate();

		$this->assertTrue( Capability::granted() );
		$this->assertSame( Schema::DB_VERSION, $this->options[ Schema::DB_VERSION_OPTION ] );
	}

	public function test_network_deactivation_covers_every_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'get_sites' )->twice()->andReturn( array( 1, 2 ), array() );
		Functions\expect( 'switch_to_blog' )->twice();
		Functions\expect( 'restore_current_blog' )->twice();
		Functions\expect( 'wp_clear_scheduled_hook' )->twice();

		Schema::deactivate( true );

		$this->assertFalse( Capability::granted() );
	}

	public function test_the_notice_shows_only_while_no_role_has_it(): void {
		$this->caps['manage_options'] = true;
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_wpnonce" value="n">' );
		$capability = new Capability();

		ob_start();
		$capability->notice();
		$this->assertSame( '', ob_get_clean() );

		$this->strip();
		ob_start();
		$capability->notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No role has the agent_governance_approve capability', $html );
		$this->assertStringContainsString( 'manage_options', $html );
		$this->assertStringContainsString( 'value="agent_governance_restore_capability"', $html );
		$this->assertStringContainsString( 'Give it back to Administrator', $html );
	}

	public function test_the_notice_message_filter_and_who_sees_it(): void {
		$this->strip();
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		$capability = new Capability();

		ob_start();
		$capability->notice();
		$this->assertSame( '', ob_get_clean(), 'Only people who manage options see it.' );

		$this->caps['manage_options'] = true;
		Filters\expectApplied( 'agent_governance_no_approver_message' )->once()->with( Mockery::type( 'string' ) )->andReturn( 'Custom text.' );
		ob_start();
		$capability->notice();
		$this->assertStringContainsString( 'Custom text.', (string) ob_get_clean() );
	}

	public function test_approval_emails_go_to_the_stand_ins_while_no_role_has_it(): void {
		$this->strip();
		$this->options['agent_governance_settings'] = array( 'email' => true );
		Functions\expect( 'get_users' )->once()->with( Mockery::on( static function ( $args ) { return 'manage_options' === $args['capability']; } ) )->andReturn( array( (object) array( 'user_email' => 'owner@example.com' ) ) ); // phpcs:ignore

		$this->assertSame( array( 'owner@example.com' ), $this->notifier->recipients( $this->request_row() ) );
	}

	public function test_site_health_is_critical_while_no_role_has_it(): void {
		$this->strip();
		Functions\when( 'esc_url' )->returnArg( 1 );
		$this->wpdb->returns['get_var'] = array( 'wp_agent_governance_log', 'wp_agent_governance_requests' );

		$result = ( new SiteHealth( $this->settings, $this->requests ) )->run();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertSame( 'No role can approve agent calls', $result['label'] );
		$this->assertStringContainsString( 'restore-capability', $result['description'] );
	}
}
