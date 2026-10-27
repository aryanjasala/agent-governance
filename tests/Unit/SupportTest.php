<?php
/**
 * Tests for the clean-up, privacy tools, schema, version check, screen helpers
 * and Site Health.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Admin\Page;
use AryanX\Axle\AgentGovernance\Cleanup;
use AryanX\Axle\AgentGovernance\Health\SiteHealth;
use AryanX\Axle\AgentGovernance\Privacy;
use AryanX\Axle\AgentGovernance\Requirements;
use AryanX\Axle\AgentGovernance\Schema;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Cleanup, Privacy, Schema, Requirements, Context, Page and SiteHealth.
 */
final class SupportTest extends AgentTestCase {

	public function test_cleanup_expires_purges_and_fires_cleaned_up(): void {
		$this->wpdb->returns['get_results'][] = array( $this->request_row() );
		$this->wpdb->returns['query'][]       = 12;
		$this->wpdb->returns['query'][]       = 3;
		Actions\expectDone( 'agent_governance_cleaned_up' )->once()->with(
			array(
				'expired'  => 1,
				'log'      => 12,
				'requests' => 3,
			)
		);
		$cleanup = new Cleanup( $this->settings, $this->log, $this->requests, $this->approvals );
		$cleanup->register();

		$this->assertSame( 10, has_action( Schema::CRON_HOOK, array( $cleanup, 'scheduled' ) ) );
		$cleanup->scheduled();
	}

	public function test_cleanup_keeps_the_log_for_the_retention_days(): void {
		Filters\expectApplied( 'agent_governance_retention_days' )->once()->andReturn( 7 );

		( new Cleanup( $this->settings, $this->log, $this->requests, $this->approvals ) )->run();

		$cutoff = gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
		$this->assertStringContainsString( $cutoff, $this->wpdb->queries[1] );
	}

	public function test_privacy_registers_and_exports_a_users_calls(): void {
		$privacy = new Privacy( $this->settings, $this->log, $this->requests );
		$privacy->register();
		$this->assertSame( 10, has_filter( 'wp_privacy_personal_data_exporters', array( $privacy, 'add_exporter' ) ) );
		$this->assertArrayHasKey( 'agent-governance', $privacy->add_exporter( array() ) );
		$this->assertArrayHasKey( 'agent-governance', $privacy->add_eraser( array() ) );

		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 2 ) );
		$this->wpdb->returns['get_results'][] = array( $this->log_row( array( 'ip' => '203.0.113.5' ) ) );
		$this->wpdb->returns['get_results'][] = array( $this->request_row() );

		$export = $privacy->export( 'ed@example.com' );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 2, $export['data'] );
		$this->assertSame( '203.0.113.5', $export['data'][0]['data'][4]['value'] );
		$this->assertSame( '{"id":5,"password":"[redacted]"}', $export['data'][1]['data'][3]['value'] );
	}

	public function test_privacy_erases_by_anonymising(): void {
		$privacy = new Privacy( $this->settings, $this->log, $this->requests );
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 2 ) );
		$this->wpdb->returns['query'] = array( 4, 0, 1 );

		$erased = $privacy->erase( 'ed@example.com' );

		$this->assertTrue( $erased['items_removed'] );
		$this->assertTrue( $erased['items_retained'] );
		$this->assertTrue( $erased['done'] );

		Functions\when( 'get_user_by' )->justReturn( false );
		$this->assertFalse( $privacy->erase( 'nobody@example.com' )['items_removed'] );
		$this->assertSame( array(), $privacy->export( 'nobody@example.com' )['data'] );
	}

	public function test_privacy_policy_text_names_the_retention(): void {
		Functions\expect( 'wp_add_privacy_policy_content' )->once()->with( 'Agent Governance', Mockery::on( static function ( $text ) { return false !== strpos( $text, '30 days' ); } ) ); // phpcs:ignore

		( new Privacy( $this->settings, $this->log, $this->requests ) )->policy_text();
	}

	public function test_the_approver_roles_filter_decides_who_gets_the_capability(): void {
		$role = Mockery::mock();
		$role->shouldReceive( 'has_cap' )->with( 'agent_governance_approve' )->andReturn( false );
		$role->shouldReceive( 'add_cap' )->once()->with( 'agent_governance_approve' );
		Functions\expect( 'get_role' )->once()->with( 'shop_manager' )->andReturn( $role );
		Filters\expectApplied( 'agent_governance_approver_roles' )->once()->with( array( 'administrator' ) )->andReturn( array( 'shop_manager' ) );

		Schema::grant_capability();

		$this->assertSame( 'wp_agent_governance_log', Schema::log_table() );
		$this->assertSame( 'wp_agent_governance_requests', Schema::requests_table() );
	}

	public function test_maybe_upgrade_only_installs_on_a_new_version(): void {
		$this->options[ Schema::DB_VERSION_OPTION ] = Schema::DB_VERSION;
		Functions\expect( 'wp_next_scheduled' )->once()->andReturn( time() + 60 );
		Functions\expect( 'dbDelta' )->never();

		Schema::maybe_upgrade();
		$this->addToAssertionCount( 1 );
	}

	public function test_tables_exist_checks_both(): void {
		$this->wpdb->returns['get_var'] = array( 'wp_agent_governance_log', null );

		$this->assertFalse( Schema::tables_exist() );
	}

	public function test_requirements_need_69_and_the_abilities_api(): void {
		Functions\when( 'wp_register_ability' )->justReturn( true );

		$this->assertTrue( Requirements::met( '6.9.10' ) );
		$this->assertTrue( Requirements::met( '7.1' ) );
		$this->assertFalse( Requirements::met( '6.8.3' ) );
		$this->assertTrue( Requirements::met(), 'The stubbed WordPress is 7.1.' );
	}

	public function test_failing_requirements_show_a_notice_and_deactivate(): void {
		Requirements::fail();
		$this->assertSame( 10, has_action( 'admin_notices', array( Requirements::class, 'notice' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( Requirements::class, 'deactivate' ) ) );

		Functions\when( 'plugin_basename' )->justReturn( 'agent-governance/agent-governance.php' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'deactivate_plugins' )->once()->with( 'agent-governance/agent-governance.php', true, false );
		Requirements::deactivate();

		Functions\when( 'get_bloginfo' )->justReturn( '6.8.3' );
		$this->caps = array( 'activate_plugins' => true );
		ob_start();
		Requirements::notice();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'needs WordPress 6.9 or later', $html );
		$this->assertStringContainsString( 'runs 6.8.3', $html );

		$this->caps = array();
		ob_start();
		Requirements::notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_boot_stops_on_old_wordpress(): void {
		Functions\when( 'get_bloginfo' )->justReturn( '6.8.3' );

		\AryanX\Axle\AgentGovernance\Plugin::boot();

		$this->assertNull( \AryanX\Axle\AgentGovernance\Plugin::instance() );
		$this->assertSame( 10, has_action( 'admin_init', array( Requirements::class, 'deactivate' ) ) );
	}

	public function test_the_client_ip_filter(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertSame( '203.0.113.9', $this->context->ip() );

		Filters\expectApplied( 'agent_governance_client_ip' )->once()->with( '203.0.113.9' )->andReturn( '' );
		$this->assertSame( '', $this->context->ip() );

		Filters\expectApplied( 'agent_governance_client_ip' )->once()->andReturn( 'not an ip' );
		$this->assertSame( '', $this->context->ip() );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	public function test_context_registers_its_signal_hooks_and_resets(): void {
		$this->context->register();
		$this->assertSame( 10, has_action( 'application_password_did_authenticate', array( $this->context, 'app_password_used' ) ) );
		$this->assertSame( -1000, has_filter( 'rest_pre_dispatch', array( $this->context, 'capture_rest' ) ) );
		$this->assertSame( -1000, has_filter( 'mcp_adapter_pre_tool_call', array( $this->context, 'enter_mcp_tool' ) ) );

		$this->context->push_frame( array() );
		$this->context->clear( 'a', array( 'x' ) );
		$this->context->reset();

		$this->assertFalse( $this->context->has_frame() );
		$this->assertNull( $this->context->take_cleared( 'a' ) );
		$this->assertNull( $this->context->pop_frame( 3 ) );
	}

	public function test_page_filters_and_urls(): void {
		$this->assertSame( 'manage_options', Page::settings_capability() );
		Filters\expectApplied( 'agent_governance_settings_capability' )->once()->andReturn( 'agent_governance_approve' );
		$this->assertSame( 'agent_governance_approve', Page::settings_capability() );

		$this->assertSame( 30, Page::per_page() );
		Filters\expectApplied( 'agent_governance_list_per_page' )->once()->andReturn( 1000 );
		$this->assertSame( 200, Page::per_page() );

		$this->assertSame( 'https://example.com/wp-admin/tools.php?page=agent-governance&tab=requests&request=4', Page::request_url( 4 ) );

		Functions\when( 'get_current_screen' )->justReturn( null );
		$this->assertFalse( Page::is_current() );
	}

	public function test_site_health_states(): void {
		Functions\when( 'esc_url' )->returnArg( 1 );
		$health = new SiteHealth( $this->settings, $this->requests );
		$health->register();
		$this->assertArrayHasKey( 'agent_governance', $health->add_test( array() )['direct'] );

		$this->wpdb->returns['get_var'] = array( null );
		$this->assertSame( 'critical', $health->run()['status'] );

		$this->wpdb->returns['get_var'] = array( 'wp_agent_governance_log', 'wp_agent_governance_requests' );
		Functions\when( 'get_users' )->justReturn( array() );
		$this->assertSame( 'Nobody can approve agent calls', $health->run()['label'] );

		Functions\when( 'get_users' )->justReturn( array( 1 ) );
		$this->wpdb->returns['get_var'] = array( 'wp_agent_governance_log', 'wp_agent_governance_requests', gmdate( 'Y-m-d H:i:s', time() - 7200 ) );
		$this->assertSame( 'Agent calls are waiting for approval', $health->run()['label'] );

		$this->options['agent_governance_settings'] = array( 'paused' => true );
		$this->wpdb->returns['get_var']             = array( 'wp_agent_governance_log', 'wp_agent_governance_requests', null );
		$health                                     = new SiteHealth( new \AryanX\Axle\AgentGovernance\Settings(), $this->requests );
		$this->assertSame( 'Agent writes are paused', $health->run()['label'] );

		$this->options['agent_governance_settings'] = array();
		$this->wpdb->returns['get_var']             = array( 'wp_agent_governance_log', 'wp_agent_governance_requests', null );
		$health                                     = new SiteHealth( new \AryanX\Axle\AgentGovernance\Settings(), $this->requests );
		$this->assertSame( 'good', $health->run()['status'] );
	}
}
