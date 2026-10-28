<?php
/**
 * Tests for the ability guard on WordPress 7.1 and on 6.9 and 7.0.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Guards\AbilityGuard;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers AbilityGuard.
 */
final class AbilityGuardTest extends AgentTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->options['agent_governance_settings'] = array(
			'rate_limit' => 0,
			'email'      => false,
		);
	}

	/**
	 * Registration arguments for an ability.
	 *
	 * @param array    $annotations Annotations.
	 * @param callable $execute     Callback.
	 * @return array
	 */
	private function args( array $annotations, callable $execute ): array {
		return array(
			'execute_callback' => $execute,
			'meta'             => array( 'annotations' => $annotations ),
		);
	}

	/**
	 * An ability double for the 7.1 filter.
	 *
	 * @param array     $annotations Annotations.
	 * @param bool|null $permitted   What check_permissions returns.
	 * @return object
	 */
	private function ability( array $annotations, ?bool $permitted = true ) {
		return new \Agg_Ability_Double( array( 'annotations' => $annotations ), $permitted );
	}

	public function test_it_hooks_both_ways_in(): void {
		$guard = new AbilityGuard( $this->gate, $this->context, true );
		$guard->register();

		$this->assertSame( 20, has_filter( 'wp_pre_execute_ability', array( $guard, 'pre_execute' ) ) );
		$this->assertSame( 20, has_filter( 'wp_register_ability_args', array( $guard, 'wrap' ) ) );
		$this->assertTrue( AbilityGuard::has_pre_execute(), 'The test stubs define WP_Filter_Sentinel.' );
	}

	public function test_kind_follows_the_annotations(): void {
		$this->assertSame( 'readonly', AbilityGuard::kind( array( 'annotations' => array( 'readonly' => true ) ) ) );
		$this->assertSame( 'destructive', AbilityGuard::kind( array( 'annotations' => array( 'destructive' => true ) ) ) );
		$this->assertSame( 'write', AbilityGuard::kind( array() ) );
	}

	public function test_wrap_leaves_odd_arguments_alone(): void {
		$guard = new AbilityGuard( $this->gate, $this->context, false );

		$this->assertSame( 'x', $guard->wrap( 'x', 'my/a' ) );
		$this->assertSame( array( 'label' => 'A' ), $guard->wrap( array( 'label' => 'A' ), 'my/a' ) );
	}

	public function test_on_69_the_wrapper_runs_calls_that_are_not_an_agents(): void {
		$guard = new AbilityGuard( $this->gate, $this->context, false );
		$args  = $guard->wrap(
			$this->args(
				array( 'destructive' => true ),
				static function ( $input ) {
					return $input['id'] * 2;
				}
			),
			'my/delete'
		);

		$this->assertSame( 10, $args['execute_callback']( array( 'id' => 5 ) ) );
		$this->assertSame( array(), $this->wpdb->inserted );
	}

	public function test_on_69_the_wrapper_holds_a_destructive_agent_call(): void {
		$this->as_app_password();
		$ran   = false;
		$guard = new AbilityGuard( $this->gate, $this->context, false );
		$args  = $guard->wrap(
			$this->args(
				array( 'destructive' => true ),
				static function () use ( &$ran ) {
					$ran = true;
				}
			),
			'my/delete'
		);

		$result = $args['execute_callback']( array( 'id' => 5 ) );

		$this->assertFalse( $ran );
		$this->assertSame( 'agent_governance_pending', $result->get_error_code() );
		$this->assertSame( 'my/delete', $this->wpdb->rows( 'requests' )[0]['target'] );
	}

	public function test_on_69_the_wrapper_logs_an_allowed_agent_call(): void {
		$this->as_app_password();
		$guard = new AbilityGuard( $this->gate, $this->context, false );
		$args  = $guard->wrap(
			$this->args(
				array( 'readonly' => true ),
				static function () {
					return 'site info';
				}
			),
			'core/get-site-info'
		);

		$this->assertSame( 'site info', $args['execute_callback']() );

		$row = $this->wpdb->rows( 'log' )[0];
		$this->assertSame( 'core/get-site-info', $row['target'] );
		$this->assertSame( 'readonly', $row['kind'] );
		$this->assertSame( 'allowed', $row['decision'] );
		$this->assertSame( 'null', $row['input'] );
	}

	public function test_a_callback_that_throws_is_still_logged(): void {
		$this->as_app_password();
		$guard = new AbilityGuard( $this->gate, $this->context, false );
		$args  = $guard->wrap(
			$this->args(
				array(),
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			),
			'my/save'
		);

		try {
			$args['execute_callback']( array() );
			$this->fail( 'Expected the exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertCount( 1, $this->wpdb->rows( 'log' ) );
	}

	public function test_dispatchers_run_their_abilities_as_mcp_calls(): void {
		$guard = new AbilityGuard( $this->gate, $this->context, false );
		$seen  = null;
		$args  = $guard->wrap(
			$this->args(
				array( 'destructive' => true ),
				function () use ( &$seen ) {
					$seen = $this->classifier->current();
					return array( 'success' => true );
				}
			),
			'mcp-adapter/execute-ability'
		);

		$args['execute_callback']( array( 'ability_name' => 'my/save' ) );

		$this->assertSame( 'mcp', $seen['via'], 'Calls inside count as MCP calls.' );
		$this->assertFalse( $this->context->in_mcp() );
		$this->assertSame( array(), $this->wpdb->inserted, 'The dispatcher itself is neither checked nor logged.' );
	}

	public function test_the_dispatch_abilities_filter_adds_dispatchers(): void {
		Filters\expectApplied( 'agent_governance_dispatch_abilities' )->andReturn( array( 'my/router' ) );
		$guard = new AbilityGuard( $this->gate, $this->context, true );

		$this->assertSame( array( 'my/router' ), AbilityGuard::dispatchers() );
		$sentinel = new \WP_Filter_Sentinel();
		$this->assertSame( $sentinel, $guard->pre_execute( $sentinel, 'my/router', array() ) );
	}

	public function test_on_71_another_plugins_answer_is_kept(): void {
		$guard = new AbilityGuard( $this->gate, $this->context, true );
		$error = new \WP_Error( 'axle_read_only_mode', 'Frozen.' );

		$this->assertSame( $error, $guard->pre_execute( $error, 'my/save', array() ) );
	}

	public function test_on_71_the_filter_refuses_and_the_callback_never_runs(): void {
		$this->as_app_password();
		$this->options['agent_governance_settings']['paused'] = true;
		$guard = new AbilityGuard( $this->gate, $this->context, true );

		$result = $guard->pre_execute( new \WP_Filter_Sentinel(), 'my/save', array(), $this->ability( array() ) );

		$this->assertSame( 'agent_governance_paused', $result->get_error_code() );
	}

	public function test_on_71_an_allowed_call_is_logged_by_the_wrapper(): void {
		$this->as_app_password();
		$guard    = new AbilityGuard( $this->gate, $this->context, true );
		$sentinel = new \WP_Filter_Sentinel();
		$args     = $guard->wrap(
			$this->args(
				array(),
				static function () {
					return 'saved';
				}
			),
			'my/save'
		);

		$this->assertSame( $sentinel, $guard->pre_execute( $sentinel, 'my/save', array( 'id' => 1 ), $this->ability( array() ) ) );
		$this->assertSame( array(), $this->wpdb->inserted );

		$this->assertSame( 'saved', $args['execute_callback']( array( 'id' => 1 ) ) );
		$this->assertSame( 'allowed', $this->wpdb->rows( 'log' )[0]['decision'] );
		$this->assertNull( $this->context->take_cleared( 'my/save' ), 'The decision is used once.' );
	}

	public function test_on_71_a_held_call_is_queued_before_it_runs(): void {
		$this->as_app_password();
		$guard = new AbilityGuard( $this->gate, $this->context, true );

		$result = $guard->pre_execute( new \WP_Filter_Sentinel(), 'my/delete', array( 'id' => 1 ), $this->ability( array( 'destructive' => true ) ) );

		$this->assertSame( 'agent_governance_pending', $result->get_error_code() );
		$this->assertCount( 1, $this->wpdb->rows( 'requests' ) );
	}

	public function test_on_71_a_call_without_permission_is_left_to_core(): void {
		$this->as_app_password();
		$guard    = new AbilityGuard( $this->gate, $this->context, true );
		$sentinel = new \WP_Filter_Sentinel();

		$result = $guard->pre_execute( $sentinel, 'my/delete', array( 'id' => 1 ), $this->ability( array( 'destructive' => true ), false ) );

		$this->assertSame( $sentinel, $result );
		$this->assertSame( array(), $this->wpdb->inserted, 'Nothing is queued that could never run.' );
	}

	public function test_on_71_the_ability_is_looked_up_when_core_does_not_pass_it(): void {
		$this->as_app_password();
		Functions\expect( 'wp_get_ability' )->atLeast()->once()->with( 'my/delete' )->andReturn( $this->ability( array( 'destructive' => true ) ) );
		$guard = new AbilityGuard( $this->gate, $this->context, true );

		$result = $guard->pre_execute( new \WP_Filter_Sentinel(), 'my/delete', array( 'id' => 1 ) );

		$this->assertSame( 'agent_governance_pending', $result->get_error_code() );
	}

	public function test_on_71_an_agent_call_another_plugin_refused_is_logged(): void {
		$this->as_app_password();
		$guard  = new AbilityGuard( $this->gate, $this->context, true );
		$frozen = new \WP_Error( 'axle_read_only_mode', 'Frozen.', array( 'status' => 423 ) );

		$this->assertSame( $frozen, $guard->pre_execute( $frozen, 'my/delete', array( 'id' => 3 ), $this->ability( array( 'destructive' => true ) ) ) );

		$row = $this->wpdb->rows( 'log' )[0];
		$this->assertSame( 'refused', $row['decision'] );
		$this->assertSame( 'my/delete', $row['target'] );
		$this->assertSame( 'destructive', $row['kind'] );
		$this->assertSame( array(), $this->wpdb->rows( 'requests' ), 'Nothing is held.' );
	}

	public function test_on_71_another_plugins_answer_that_is_not_an_error_is_not_logged(): void {
		$this->as_app_password();
		$guard = new AbilityGuard( $this->gate, $this->context, true );

		$this->assertSame( array( 'cached' => true ), $guard->pre_execute( array( 'cached' => true ), 'my/delete', array(), $this->ability( array() ) ) );
		$this->assertSame( array(), $this->wpdb->inserted );
	}

	public function test_on_71_calls_that_are_not_an_agents_pass(): void {
		$guard    = new AbilityGuard( $this->gate, $this->context, true );
		$sentinel = new \WP_Filter_Sentinel();

		$this->assertSame( $sentinel, $guard->pre_execute( $sentinel, 'my/delete', array(), $this->ability( array( 'destructive' => true ) ) ) );
	}

	public function test_permitted_checks_input_and_permission(): void {
		$this->assertTrue( AbilityGuard::permitted( null, array() ) );

		$bad_input             = new \Agg_Ability_Double();
		$bad_input->normalized = new \WP_Error( 'x' );
		$this->assertFalse( AbilityGuard::permitted( $bad_input, array() ) );

		$invalid        = new \Agg_Ability_Double();
		$invalid->valid = new \WP_Error( 'x' );
		$this->assertFalse( AbilityGuard::permitted( $invalid, array() ) );

		$this->assertFalse( AbilityGuard::permitted( new \Agg_Ability_Double( array(), new \WP_Error( 'no' ) ), array() ) );
		$this->assertTrue( AbilityGuard::permitted( new \Agg_Ability_Double(), array() ) );
	}
}
