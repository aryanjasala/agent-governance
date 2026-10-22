<?php
/**
 * Tests for settings, rules and the pause.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance\Tests\Unit;

use AryanX\AgentGovernance\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers Settings.
 */
final class SettingsTest extends AgentTestCase {

	public function test_defaults(): void {
		$all = $this->settings->all();

		$this->assertFalse( $all['paused'] );
		$this->assertSame( 60, $all['rate_limit'] );
		$this->assertSame( 30, $all['retention_days'] );
		$this->assertTrue( $all['email'] );
		$this->assertTrue( $all['notice'] );
		$this->assertSame(
			array(
				'readonly'    => 'allow',
				'write'       => 'allow',
				'destructive' => 'require_approval',
			),
			$all['defaults']
		);
	}

	public function test_sanitize_clamps_and_falls_back(): void {
		$clean = Settings::sanitize(
			array(
				'rate_limit'     => -5,
				'retention_days' => 99999,
				'email'          => 0,
				'defaults'       => 'nope',
			)
		);

		$this->assertSame( 0, $clean['rate_limit'] );
		$this->assertSame( 3650, $clean['retention_days'] );
		$this->assertFalse( $clean['email'] );
		$this->assertSame( 'require_approval', $clean['defaults']['destructive'] );
		$this->assertSame( Settings::defaults(), Settings::sanitize( 'garbage' ) );
	}

	public function test_update_saves_and_fires_settings_saved(): void {
		Actions\expectDone( 'agent_governance_settings_saved' )->once()->with(
			Mockery::on(
				static function ( $settings ) {
					return 10 === $settings['rate_limit'];
				}
			),
			Mockery::on(
				static function ( $old ) {
					return 60 === $old['rate_limit'];
				}
			)
		);

		$this->settings->update( array( 'rate_limit' => 10 ) );

		$this->assertSame( 10, $this->options['agent_governance_settings']['rate_limit'] );
		$this->assertSame( 10, $this->settings->get( 'rate_limit' ) );
	}

	public function test_pause_and_resume_fire_once(): void {
		Actions\expectDone( 'agent_governance_paused' )->once()->with( 1 );
		Actions\expectDone( 'agent_governance_resumed' )->once()->with( 1 );

		$this->assertTrue( $this->settings->pause( 1 ) );
		$this->assertTrue( $this->settings->is_paused() );
		$this->assertFalse( $this->settings->pause( 1 ), 'Already paused.' );
		$this->assertSame( 1, $this->settings->get( 'paused_by' ) );

		$this->assertTrue( $this->settings->resume( 1 ) );
		$this->assertFalse( $this->settings->is_paused() );
		$this->assertFalse( $this->settings->resume( 1 ), 'Not paused.' );
	}

	public function test_rules_are_added_and_deleted_and_fire_rules_saved(): void {
		Actions\expectDone( 'agent_governance_rules_saved' )->twice();

		$rule = $this->settings->add_rule(
			array(
				'scope'   => 'role',
				'subject' => 'editor',
				'pattern' => '  DELETE   /wp/v2/* ',
				'action'  => 'deny',
			)
		);

		$this->assertSame( 'DELETE /wp/v2/*', $rule['pattern'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{8}$/', $rule['id'] );
		$this->assertCount( 1, $this->settings->rules() );

		$this->assertFalse( $this->settings->delete_rule( 'nope' ) );
		$this->assertTrue( $this->settings->delete_rule( $rule['id'] ) );
		$this->assertSame( array(), $this->settings->rules() );
	}

	public function test_invalid_rules_are_dropped(): void {
		$this->assertNull( Settings::sanitize_rule( 'x' ) );
		$this->assertNull(
			Settings::sanitize_rule(
				array(
					'pattern' => 'a',
					'action'  => 'maybe',
				)
			)
		);
		$this->assertNull(
			Settings::sanitize_rule(
				array(
					'scope'   => 'role',
					'pattern' => 'a',
					'action'  => 'deny',
				)
			),
			'A role rule needs a role.'
		);
		$this->assertNull( $this->settings->add_rule( array( 'pattern' => '' ) ) );

		$rule = Settings::sanitize_rule(
			array(
				'scope'   => 'galaxy',
				'subject' => 'x',
				'pattern' => 'a/*',
				'action'  => 'allow',
				'id'      => 'Bad ID!',
			)
		);
		$this->assertSame( 'default', $rule['scope'], 'An unknown scope applies to every agent.' );
		$this->assertSame( '', $rule['subject'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]{8}$/', $rule['id'] );
	}

	public function test_retention_days_filter_is_clamped(): void {
		Filters\expectApplied( 'agent_governance_retention_days' )->once()->with( 30 )->andReturn( 0 );

		$this->assertSame( 1, $this->settings->retention_days() );
	}

	public function test_rate_limit_filter_is_clamped(): void {
		Filters\expectApplied( 'agent_governance_rate_limit' )->once()->with( 60, 'app:x' )->andReturn( -1 );

		$this->assertSame( 0, $this->settings->rate_limit( 'app:x' ) );
	}

	public function test_request_ttl_filter_is_clamped(): void {
		Filters\expectApplied( 'agent_governance_request_ttl' )->once()->with( DAY_IN_SECONDS )->andReturn( 5 );
		$this->assertSame( MINUTE_IN_SECONDS, Settings::request_ttl() );

		Filters\expectApplied( 'agent_governance_request_ttl' )->once()->andReturn( 3600 );
		$this->assertSame( 3600, Settings::request_ttl() );
	}

	public function test_payload_limit_filter_gets_the_context(): void {
		Filters\expectApplied( 'agent_governance_payload_limit' )->once()->with( 65536, 'dry_run' )->andReturn( 10 );

		$this->assertSame( 512, Settings::payload_limit( 'dry_run' ) );
		$this->assertSame( 16384, Settings::payload_limit( 'input' ) );
	}
}
