<?php
/**
 * Tests for the policy engine.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Policy;
use AryanX\Axle\AgentGovernance\Settings;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers Policy: own abilities, the pause, the rate limit, rule scopes and
 * patterns, defaults and the final filter.
 */
final class PolicyTest extends AgentTestCase {

	/**
	 * An agent.
	 *
	 * @param array $overrides Fields.
	 * @return array
	 */
	private function agent( array $overrides = array() ): array {
		return array_merge(
			array(
				'key'     => 'app:aaaa-1111',
				'label'   => 'Helper',
				'user_id' => 2,
				'via'     => 'app-password',
				'session' => '',
			),
			$overrides
		);
	}

	/**
	 * A call.
	 *
	 * @param string $target Ability or route.
	 * @param string $kind   readonly, write or destructive.
	 * @param string $method HTTP method.
	 * @return array
	 */
	private function call( string $target, string $kind = 'write', string $method = 'POST' ): array {
		return array(
			'source' => 0 === strpos( $target, '/' ) ? 'rest' : 'ability',
			'target' => $target,
			'method' => $method,
			'kind'   => $kind,
			'input'  => array(),
		);
	}

	/**
	 * Saves rules.
	 *
	 * @param array[] $rules Rules.
	 */
	private function rules( array $rules ): void {
		$this->options['agent_governance_rules'] = $rules;
	}

	/**
	 * Turns the rate limit off, so no count is read.
	 */
	private function no_rate_limit(): void {
		$this->options['agent_governance_settings'] = array( 'rate_limit' => 0 );
	}

	public function test_defaults_allow_reads_and_writes_and_hold_destructive_calls(): void {
		$this->no_rate_limit();

		$this->assertSame( Settings::ALLOW, $this->policy->decide( $this->call( 'core/get-site-info', 'readonly', 'GET' ), $this->agent() )['action'] );
		$this->assertSame( Settings::ALLOW, $this->policy->decide( $this->call( 'my/save', 'write' ), $this->agent() )['action'] );

		$decision = $this->policy->decide( $this->call( 'my/delete', 'destructive' ), $this->agent() );
		$this->assertSame( Settings::REQUIRE_APPROVAL, $decision['action'] );
		$this->assertSame( 'default', $decision['reason'] );
	}

	public function test_saved_defaults_per_kind(): void {
		$this->options['agent_governance_settings'] = array(
			'rate_limit' => 0,
			'defaults'   => array(
				'write'       => 'require_approval',
				'destructive' => 'deny',
				'readonly'    => 'nonsense',
			),
		);

		$this->assertSame( Settings::REQUIRE_APPROVAL, $this->policy->decide( $this->call( 'my/save' ), $this->agent() )['action'] );
		$this->assertSame( Settings::DENY, $this->policy->decide( $this->call( 'my/delete', 'destructive' ), $this->agent() )['action'] );
		$this->assertSame( Settings::ALLOW, $this->policy->decide( $this->call( 'my/read', 'readonly' ), $this->agent() )['action'], 'An invalid saved value keeps the default.' );
	}

	public function test_the_default_rules_filter_changes_the_defaults(): void {
		$this->no_rate_limit();
		Filters\expectApplied( 'agent_governance_default_rules' )->once()->andReturn(
			array(
				'write'    => 'deny',
				'readonly' => 'maybe',
			)
		);

		$this->assertSame( Settings::DENY, $this->policy->decide( $this->call( 'my/save' ), $this->agent() )['action'] );
	}

	public function test_agent_governance_abilities_always_run(): void {
		$this->options['agent_governance_settings'] = array( 'paused' => true );
		$this->rules(
			array(
				array(
					'scope'   => 'default',
					'pattern' => '*',
					'action'  => 'deny',
				),
			)
		);

		$decision = $this->policy->decide( $this->call( $this->own() . '/get-request', 'readonly', 'GET' ), $this->agent() );

		$this->assertSame( Settings::ALLOW, $decision['action'] );
		$this->assertSame( 'own', $decision['reason'] );
		$this->assertTrue( Policy::is_own( 'agent-governance/decide-request' ) );
		$this->assertFalse( Policy::is_own( 'agent-governance-x/decide' ) );
	}

	public function test_the_pause_refuses_writes_but_not_reads(): void {
		$this->options['agent_governance_settings'] = array(
			'paused'     => true,
			'rate_limit' => 0,
		);

		$write = $this->policy->decide( $this->call( 'my/save' ), $this->agent() );
		$this->assertSame( Settings::DENY, $write['action'] );
		$this->assertSame( 'paused', $write['reason'] );

		$this->assertSame( Settings::ALLOW, $this->policy->decide( $this->call( '/wp/v2/posts', 'readonly', 'GET' ), $this->agent() )['action'] );
	}

	public function test_the_policy_filter_cannot_let_a_write_through_a_pause(): void {
		$this->options['agent_governance_settings'] = array( 'paused' => true );
		Filters\expectApplied( 'agent_governance_policy' )->once()->andReturn(
			array(
				'action' => 'allow',
				'reason' => 'custom',
			)
		);

		$decision = $this->policy->decide( $this->call( 'my/save' ), $this->agent() );

		$this->assertSame( Settings::DENY, $decision['action'] );
		$this->assertSame( 'paused', $decision['reason'] );
	}

	public function test_the_rate_limit_counts_recent_calls(): void {
		$this->options['agent_governance_settings'] = array( 'rate_limit' => 3 );
		$this->wpdb->returns['get_var'][]           = 3;

		$decision = $this->policy->decide( $this->call( 'my/read', 'readonly' ), $this->agent() );

		$this->assertSame( Settings::DENY, $decision['action'] );
		$this->assertSame( 'rate_limit', $decision['reason'] );
		$this->assertStringContainsString( "identity = 'app:aaaa-1111'", $this->wpdb->queries[0] );
		$this->assertStringContainsString( "NOT LIKE 'agent-governance/%'", $this->wpdb->queries[0], 'Polling its own requests does not count.' );
	}

	public function test_under_the_rate_limit_calls_go_on(): void {
		$this->options['agent_governance_settings'] = array( 'rate_limit' => 3 );
		$this->wpdb->returns['get_var'][]           = 2;

		$this->assertSame( Settings::ALLOW, $this->policy->decide( $this->call( 'my/read', 'readonly' ), $this->agent() )['action'] );
	}

	public function test_the_rate_limit_filter_turns_it_off_per_agent(): void {
		Filters\expectApplied( 'agent_governance_rate_limit' )->once()->with( 60, 'app:aaaa-1111' )->andReturn( 0 );

		$this->assertSame( Settings::ALLOW, $this->policy->decide( $this->call( 'my/read', 'readonly' ), $this->agent() )['action'] );
		$this->assertSame( array(), $this->wpdb->queries, 'No count is read without a limit.' );
	}

	public function test_an_agent_rule_beats_a_rule_for_every_agent(): void {
		$this->no_rate_limit();
		$this->rules(
			array(
				array(
					'scope'   => 'default',
					'pattern' => 'my/*',
					'action'  => 'deny',
				),
				array(
					'scope'   => 'agent',
					'subject' => 'aaaa-1111',
					'pattern' => 'my/save',
					'action'  => 'allow',
				),
			)
		);

		$decision = $this->policy->decide( $this->call( 'my/save' ), $this->agent() );

		$this->assertSame( Settings::ALLOW, $decision['action'] );
		$this->assertSame( 'rule', $decision['reason'] );
		$this->assertSame( 'agent', $decision['rule']['scope'] );
		$this->assertSame( Settings::DENY, $this->policy->decide( $this->call( 'my/other' ), $this->agent() )['action'] );
		$this->assertSame( Settings::DENY, $this->policy->decide( $this->call( 'my/save' ), $this->agent( array( 'key' => 'app:bbbb' ) ) )['action'], 'Another agent gets the general rule.' );
	}

	public function test_user_rules_match_by_id_login_or_email_and_beat_role_rules(): void {
		$this->no_rate_limit();
		$this->rules(
			array(
				array(
					'scope'   => 'role',
					'subject' => 'editor',
					'pattern' => '*',
					'action'  => 'deny',
				),
				array(
					'scope'   => 'user',
					'subject' => 'ed',
					'pattern' => 'my/save',
					'action'  => 'require_approval',
				),
			)
		);

		$this->assertSame( Settings::REQUIRE_APPROVAL, $this->policy->decide( $this->call( 'my/save' ), $this->agent() )['action'] );
		$this->assertSame( Settings::DENY, $this->policy->decide( $this->call( 'my/read', 'readonly' ), $this->agent() )['action'], 'The role rule covers the rest.' );
		$this->assertSame( Settings::ALLOW, $this->policy->decide( $this->call( 'my/save' ), $this->agent( array( 'user_id' => 1 ) ) )['action'], 'Administrators are not editors.' );

		$this->assertTrue( Policy::applies_to( array( 'scope' => 'user', 'subject' => '2' ), $this->agent() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( Policy::applies_to( array( 'scope' => 'user', 'subject' => 'ed@example.com' ), $this->agent() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( Policy::applies_to( array( 'scope' => 'user', 'subject' => 'nobody' ), $this->agent() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( Policy::applies_to( array( 'scope' => 'role', 'subject' => 'editor' ), $this->agent( array( 'user_id' => 0 ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( Policy::applies_to( array( 'scope' => 'agent', 'subject' => 'app:aaaa-1111' ), $this->agent() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_the_first_matching_rule_in_a_scope_wins(): void {
		$this->no_rate_limit();
		$this->rules(
			array(
				array(
					'scope'   => 'default',
					'pattern' => 'my/delete',
					'action'  => 'allow',
				),
				array(
					'scope'   => 'default',
					'pattern' => 'my/*',
					'action'  => 'deny',
				),
			)
		);

		$this->assertSame( Settings::ALLOW, $this->policy->decide( $this->call( 'my/delete', 'destructive' ), $this->agent() )['action'] );
	}

	/**
	 * Patterns.
	 *
	 * @return array[]
	 */
	public function patterns(): array {
		return array(
			'exact'                => array( 'my/save', 'my/save', 'POST', true ),
			'star'                 => array( 'my/*', 'my/save', '', true ),
			'star crosses slashes' => array( '/wp/v2/*', '/wp/v2/posts/12', 'POST', true ),
			'everything'           => array( '*', '/anything', 'GET', true ),
			'question mark'        => array( 'my/sav?', 'my/save', '', true ),
			'case'                 => array( 'MY/Save', 'my/save', '', true ),
			'no partial match'     => array( 'my/save', 'my/save-all', '', false ),
			'dots are literal'     => array( 'my.save', 'myxsave', '', false ),
			'method'               => array( 'DELETE /wp/v2/*', '/wp/v2/posts/1', 'DELETE', true ),
			'other method'         => array( 'DELETE /wp/v2/*', '/wp/v2/posts/1', 'POST', false ),
			'several methods'      => array( 'post|put /wp/v2/users*', '/wp/v2/users/3', 'PUT', true ),
			'method on ability'    => array( 'DELETE my/*', 'my/save', '', false ),
		);
	}

	/**
	 * Pattern matching.
	 *
	 * @dataProvider patterns
	 *
	 * @param string $pattern Pattern.
	 * @param string $target  Target.
	 * @param string $method  Method.
	 * @param bool   $matches Expected.
	 */
	public function test_patterns( string $pattern, string $target, string $method, bool $matches ): void {
		$this->assertSame( $matches, Policy::pattern_matches( $pattern, $target, $method ) );
	}

	public function test_the_rules_filter_adds_rules_in_code(): void {
		$this->no_rate_limit();
		Filters\expectApplied( 'agent_governance_rules' )->once()->andReturnUsing(
			static function ( array $rules ) {
				$rules[] = array(
					'scope'   => 'role',
					'subject' => 'editor',
					'pattern' => 'DELETE /wp/v2/*',
					'action'  => 'deny',
				);
				$rules[] = 'not a rule';
				return $rules;
			}
		);

		$decision = $this->policy->decide( $this->call( '/wp/v2/pages/3', 'destructive', 'DELETE' ), $this->agent() );

		$this->assertSame( Settings::DENY, $decision['action'] );
	}

	public function test_the_policy_filter_has_the_last_word(): void {
		$this->no_rate_limit();
		Filters\expectApplied( 'agent_governance_policy' )->once()->with(
			Mockery::on(
				static function ( $decision ) {
					return 'allow' === $decision['action'] && 'default' === $decision['reason'];
				}
			),
			Mockery::type( 'array' ),
			Mockery::type( 'array' )
		)->andReturn(
			array(
				'action' => 'require_approval',
				'reason' => 'Night Shift',
			)
		);

		$decision = $this->policy->decide( $this->call( 'my/save' ), $this->agent() );

		$this->assertSame( Settings::REQUIRE_APPROVAL, $decision['action'] );
		$this->assertSame( 'nightshift', $decision['reason'] );
	}

	public function test_an_invalid_policy_filter_result_is_ignored(): void {
		$this->no_rate_limit();
		Filters\expectApplied( 'agent_governance_policy' )->once()->andReturn( array( 'action' => 'yolo' ) );

		$this->assertSame( Settings::REQUIRE_APPROVAL, $this->policy->decide( $this->call( 'my/delete', 'destructive' ), $this->agent() )['action'] );
	}

	public function test_an_unknown_kind_counts_as_a_write(): void {
		$this->options['agent_governance_settings'] = array(
			'rate_limit' => 0,
			'defaults'   => array( 'write' => 'deny' ),
		);

		$this->assertSame( Settings::DENY, $this->policy->decide( $this->call( 'my/x', 'weird' ), $this->agent() )['action'] );
	}
}
