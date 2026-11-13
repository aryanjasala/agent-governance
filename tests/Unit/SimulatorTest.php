<?php
/**
 * Tests for the policy simulation.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Policy;
use AryanX\Axle\AgentGovernance\Settings;
use AryanX\Axle\AgentGovernance\Simulator;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

/**
 * Covers Simulator, and proves it decides every call the way Policy::decide() does.
 */
final class SimulatorTest extends AgentTestCase {

	/**
	 * The simulator.
	 *
	 * @var Simulator
	 */
	private Simulator $simulator;

	protected function setUp(): void {
		parent::setUp();
		$this->simulator = new Simulator( $this->settings, $this->log );
	}

	/**
	 * A log row to replay.
	 *
	 * @param array $overrides Columns.
	 * @return array
	 */
	private function replay_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'       => 1,
				'created'  => '2026-11-10 10:00:00',
				'identity' => 'app:aaaa-1111',
				'agent'    => 'Helper',
				'user_id'  => 2,
				'via'      => 'app-password',
				'source'   => 'rest',
				'target'   => '/wp/v2/posts/5',
				'method'   => 'POST',
				'kind'     => 'write',
				'decision' => 'allowed',
			),
			$overrides
		);
	}

	/**
	 * Saves rules and no rate limit.
	 *
	 * @param array[] $rules Rules.
	 */
	private function live_rules( array $rules, int $rate_limit = 0 ): void {
		$this->options['agent_governance_rules']    = $rules;
		$this->options['agent_governance_settings'] = array( 'rate_limit' => $rate_limit );
		$this->build();
		$this->simulator = new Simulator( $this->settings, $this->log );
	}

	public function test_the_replay_decides_every_call_as_the_live_policy_does(): void {
		$this->live_rules(
			array(
				array(
					'scope'   => 'role',
					'subject' => 'editor',
					'pattern' => 'POST|PUT /wp/v2/pages/*',
					'action'  => 'require_approval',
				),
				array(
					'scope'   => 'default',
					'pattern' => '/wp/v2/users*',
					'action'  => 'deny',
				),
				array(
					'scope'   => 'agent',
					'subject' => 'aaaa-1111',
					'pattern' => 'core/*',
					'action'  => 'allow',
				),
			)
		);

		$calls = array(
			array( '/wp/v2/pages/3', 'POST', 'write', 'rest' ),
			array( '/wp/v2/pages/3', 'GET', 'readonly', 'rest' ),
			array( '/wp/v2/users/1', 'POST', 'write', 'rest' ),
			array( '/wp/v2/posts/9', 'DELETE', 'destructive', 'rest' ),
			array( '/wp/v2/posts/9', 'POST', 'write', 'rest' ),
			array( 'core/get-site-info', '', 'readonly', 'ability' ),
			array( 'my/destroy', '', 'destructive', 'ability' ),
		);
		$rows  = array();
		foreach ( $calls as $index => $call ) {
			$rows[] = $this->replay_row(
				array(
					'id'     => $index + 1,
					'target' => $call[0],
					'method' => $call[1],
					'kind'   => $call[2],
					'source' => $call[3],
				)
			);
		}

		$result = Simulator::compare( array( 'live' => $this->simulator->live() ), $rows );

		$expected = array_fill_keys( Simulator::OUTCOMES, 0 );
		foreach ( $calls as $call ) {
			$decision = $this->policy->decide(
				array(
					'source' => $call[3],
					'target' => $call[0],
					'method' => $call[1],
					'kind'   => $call[2],
					'input'  => null,
				),
				array(
					'key'     => 'app:aaaa-1111',
					'label'   => 'Helper',
					'user_id' => 2,
					'via'     => 'app-password',
					'session' => '',
				)
			);
			++$expected[ $decision['action'] ];
		}

		$this->assertSame( $expected, $result['totals']['live'], 'The replay and live decisions agree on every call.' );
		$this->assertSame( 7, $result['calls'] );
	}

	public function test_a_draft_is_compared_with_what_is_live(): void {
		$this->live_rules( array() );
		$draft = $this->simulator->draft(
			array(
				'rules' => array(
					array(
						'scope'   => 'default',
						'pattern' => 'POST /wp/v2/posts/*',
						'action'  => 'require_approval',
					),
				),
			)
		);

		$rows = array(
			$this->replay_row( array( 'id' => 1 ) ),
			$this->replay_row( array( 'id' => 2, 'created' => '2026-11-10 10:01:00' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->replay_row( array( 'id' => 3, 'target' => '/wp/v2/posts', 'method' => 'GET', 'kind' => 'readonly' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);

		$result = Simulator::compare(
			array(
				'live'  => $this->simulator->live(),
				'draft' => $draft,
			),
			$rows,
			1
		);

		$this->assertSame( 3, $result['totals']['live']['allow'] );
		$this->assertSame( 1, $result['totals']['draft']['allow'] );
		$this->assertSame( 2, $result['totals']['draft']['require_approval'] );

		$agent = $result['agents']['app:aaaa-1111'];
		$this->assertSame( 3, $agent['calls'] );
		$this->assertSame( 2, $agent['changes']['draft']['allow>require_approval']['count'] );
		$this->assertCount( 1, $agent['changes']['draft']['allow>require_approval']['examples'], 'Examples are capped.' );
		$this->assertSame( '/wp/v2/posts/5', $agent['changes']['draft']['allow>require_approval']['examples'][0]['target'] );

		$summary = Simulator::summary( $result );
		$this->assertSame( '2 calls that ran would have waited for approval.', $summary['changes'][0]['summary'] );
		$this->assertSame( 3, $summary['baseline']['allow'] );
	}

	public function test_the_rate_limit_is_counted_over_a_minute_of_replayed_calls(): void {
		$this->live_rules( array(), 0 );

		$rows = array(
			$this->replay_row( array( 'id' => 1, 'created' => '2026-11-10 10:00:00' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->replay_row( array( 'id' => 2, 'created' => '2026-11-10 10:00:10' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->replay_row( array( 'id' => 3, 'created' => '2026-11-10 10:00:20' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->replay_row( array( 'id' => 4, 'created' => '2026-11-10 10:01:30' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);

		$result = Simulator::compare(
			array(
				'live'  => array(
					'rules'      => array(),
					'defaults'   => Settings::defaults()['defaults'],
					'rate_limit' => 0,
				),
				'limit' => array(
					'rules'      => array(),
					'defaults'   => Settings::defaults()['defaults'],
					'rate_limit' => 2,
				),
			),
			$rows
		);

		$this->assertSame( 3, $result['totals']['limit']['allow'], 'The third call is over the limit, the fourth is a new minute.' );
		$this->assertSame( 1, $result['totals']['limit']['rate_limit'] );
		$this->assertSame( 'allow>rate_limit', array_key_first( $result['agents']['app:aaaa-1111']['changes']['limit'] ) );
	}

	public function test_the_same_window_logic_as_live_refuses_the_third_call(): void {
		$this->options['agent_governance_settings'] = array( 'rate_limit' => 2 );
		$this->wpdb->returns['get_var'][]           = 2;

		$live = $this->policy->decide(
			array(
				'source' => 'rest',
				'target' => '/wp/v2/posts/5',
				'method' => 'POST',
				'kind'   => 'write',
				'input'  => null,
			),
			array(
				'key'     => 'app:aaaa-1111',
				'user_id' => 2,
			)
		);
		$this->assertSame( 'rate_limit', $live['reason'] );

		$resolved = Policy::resolve(
			array(
				'source' => 'rest',
				'target' => '/wp/v2/posts/5',
				'method' => 'POST',
				'kind'   => 'write',
			),
			array(
				'key'     => 'app:aaaa-1111',
				'user_id' => 2,
			),
			array(
				'rules'      => array(),
				'defaults'   => Settings::defaults()['defaults'],
				'rate_limit' => 2,
			),
			array(
				'paused' => false,
				'recent' => 2,
			)
		);
		$this->assertSame( $live, $resolved );
	}

	public function test_a_draft_keeps_what_it_leaves_out_and_code_rules(): void {
		$this->live_rules(
			array(
				array(
					'id'      => 'saved1',
					'scope'   => 'default',
					'pattern' => 'a/*',
					'action'  => 'deny',
				),
			),
			15
		);
		Filters\expectApplied( 'agent_governance_rules' )->andReturnUsing(
			static function ( $rules ) {
				$rules[] = array(
					'id'      => 'code1',
					'scope'   => 'default',
					'pattern' => 'b/*',
					'action'  => 'deny',
				);
				return $rules;
			}
		);

		$draft = $this->simulator->draft(
			array(
				'rules'    => array(
					array(
						'id'      => 'new1',
						'scope'   => 'default',
						'pattern' => 'c/*',
						'action'  => 'allow',
					),
					array(
						'id'      => 'gone',
						'scope'   => 'default',
						'pattern' => 'd/*',
						'action'  => 'allow',
						'remove'  => '1',
					),
					'not a rule',
				),
				'defaults' => array(
					'write'       => 'deny',
					'destructive' => 'bogus',
				),
			)
		);

		$this->assertSame( array( 'new1', 'code1' ), array_column( $draft['rules'], 'id' ), 'Rules marked remove are dropped, code rules stay.' );
		$this->assertSame( 'deny', $draft['defaults']['write'] );
		$this->assertSame( 'require_approval', $draft['defaults']['destructive'], 'An invalid default keeps its live value.' );
		$this->assertSame( 15, $draft['rate_limit'], 'A rate limit left out keeps its live value.' );

		$this->assertSame( 500, $this->simulator->draft( array( 'rate_limit' => 500 ) )['rate_limit'] );
		$this->assertSame( 100000, $this->simulator->draft( array( 'rate_limit' => 9999999 ) )['rate_limit'], 'A draft is clamped like saved settings.' );
	}

	public function test_the_time_limit_stops_a_replay_with_a_partial_result(): void {
		$rows = array();
		for ( $i = 1; $i <= 5; ++$i ) {
			$rows[] = $this->replay_row( array( 'id' => $i ) );
		}

		$result = Simulator::compare( array( 'live' => $this->simulator->live() ), $rows, 3, time() - 10 );

		$this->assertTrue( $result['partial'] );
		$this->assertSame( 0, $result['calls'] );
	}

	public function test_run_reads_the_log_in_chunks_and_reports_the_window(): void {
		$this->live_rules( array() );
		// replay_start(): first row of the window, then the row that leaves the cap.
		$this->wpdb->returns['get_var']     = array( 10, 12 );
		$this->wpdb->returns['get_results'] = array(
			array( $this->replay_row( array( 'id' => 12 ) ), $this->replay_row( array( 'id' => 13 ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array(),
		);

		Actions\expectDone( 'agent_governance_policy_simulated' )->once();
		$result = $this->simulator->run( array( 'live' => $this->simulator->live() ), 7 );

		$this->assertSame( 2, $result['calls'] );
		$this->assertSame( 7, $result['days'] );
		$this->assertTrue( $result['truncated'], 'The cap cut the window short.' );
		$this->assertStringContainsString( "id > '11'", $this->wpdb->queries[2] );
		$this->assertStringContainsString( "id > '13'", $this->wpdb->queries[3] );
		$this->assertStringContainsString( "NOT LIKE 'agent-governance/%'", $this->wpdb->queries[2], 'The plugin’s own abilities are not replayed.' );
	}

	public function test_an_empty_window_replays_nothing(): void {
		$this->wpdb->returns['get_var'] = array( null );

		$result = $this->simulator->run( array( 'live' => $this->simulator->live() ) );

		$this->assertSame( 0, $result['calls'] );
		$this->assertSame( 30, $result['days'] );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_the_filters_clamp_the_window_the_cap_and_the_examples(): void {
		Filters\expectApplied( 'agent_governance_simulation_days' )->once()->with( 30 )->andReturn( 9999 );
		$this->assertSame( 365, Simulator::days() );

		Filters\expectApplied( 'agent_governance_simulation_max_calls' )->once()->andReturn( 5 );
		$this->assertSame( 100, Simulator::max_calls() );

		Filters\expectApplied( 'agent_governance_simulation_examples' )->once()->andReturn( 99 );
		$this->assertSame( 10, Simulator::examples_limit() );

		Filters\expectApplied( 'agent_governance_simulation_seconds' )->once()->andReturn( 0 );
		$this->assertSame( 1, Simulator::time_limit() );
	}

	public function test_changes_are_described_in_plain_words(): void {
		$this->assertSame( '14 calls that ran would have waited for approval.', Simulator::describe_change( 'allow', 'require_approval', 14 ) );
		$this->assertSame( '1 call that ran would have been refused.', Simulator::describe_change( 'allow', 'deny', 1 ) );
		$this->assertSame( '3 calls that waited for approval would have run at once.', Simulator::describe_change( 'require_approval', 'allow', 3 ) );
		$this->assertSame( '2 refused calls would have run.', Simulator::describe_change( 'rate_limit', 'allow', 2 ) );
		$this->assertSame( '2 calls would change from Refused to Rate limited.', Simulator::describe_change( 'deny', 'rate_limit', 2 ) );
	}
}
