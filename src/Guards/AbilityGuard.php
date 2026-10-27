<?php
/**
 * Applies the policy to every ability an agent runs.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Guards;

use AryanX\Axle\AgentGovernance\Context;
use AryanX\Axle\AgentGovernance\Gate;
use AryanX\Axle\AgentGovernance\Service;
use AryanX\Axle\AgentGovernance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * On WordPress 7.1 and later, the `wp_pre_execute_ability` filter decides
 * before an ability runs. On 6.9 and 7.0, which have no such filter, a wrapper
 * around each ability's execute callback decides. `WP_Filter_Sentinel` arrived
 * with the filter, so it tells the two apart, the way Site Read-Only Mode does.
 *
 * The wrapper is added on every version, because it is also where an allowed
 * call is timed, its post changes recorded and its log row written.
 *
 * Abilities that only run other abilities, such as the MCP adapter's
 * execute-ability, are not decided or logged themselves: the ability they run
 * is. While one runs, the calls inside it count as MCP calls.
 */
final class AbilityGuard implements Service {

	/**
	 * Applies the policy.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Request signals.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Whether this WordPress has the `wp_pre_execute_ability` filter.
	 *
	 * @var bool
	 */
	private bool $has_filter;

	/**
	 * Sets up the guard.
	 *
	 * @param Gate      $gate        Applies the policy.
	 * @param Context   $context     Request signals.
	 * @param bool|null $pre_execute Whether WordPress has the `wp_pre_execute_ability`
	 *                               filter. Null to detect it.
	 */
	public function __construct( Gate $gate, Context $context, ?bool $pre_execute = null ) {
		$this->gate       = $gate;
		$this->context    = $context;
		$this->has_filter = null === $pre_execute ? self::has_pre_execute() : $pre_execute;
	}

	/**
	 * Hooks into the Abilities API.
	 */
	public function register(): void {
		add_filter( 'wp_pre_execute_ability', array( $this, 'pre_execute' ), 20, 4 );
		add_filter( 'wp_register_ability_args', array( $this, 'wrap' ), 20, 2 );
	}

	/**
	 * Whether this WordPress has the `wp_pre_execute_ability` filter.
	 *
	 * @return bool
	 */
	public static function has_pre_execute(): bool {
		return class_exists( 'WP_Filter_Sentinel' );
	}

	/**
	 * Abilities that only run other abilities.
	 *
	 * @return string[]
	 */
	public static function dispatchers(): array {
		/**
		 * Filters the abilities that only run other abilities, such as the MCP
		 * adapter's execute tool. They are not checked or logged themselves; the
		 * ability they run is, as an MCP call.
		 *
		 * @param string[] $abilities Ability names. Default mcp-adapter/execute-ability.
		 */
		$list = (array) apply_filters( 'agent_governance_dispatch_abilities', array( 'mcp-adapter/execute-ability' ) );

		return array_map( 'strval', $list );
	}

	/**
	 * Decides an ability call on WordPress 7.1 and later.
	 *
	 * @param mixed  $pre     The sentinel, or another plugin's short-circuit value.
	 * @param string $name    Ability name.
	 * @param mixed  $input   Raw input.
	 * @param mixed  $ability The ability.
	 * @return mixed
	 */
	public function pre_execute( $pre, $name, $input = null, $ability = null ) {
		$name = (string) $name;

		if ( in_array( $name, self::dispatchers(), true ) ) {
			return $pre;
		}

		$ability = is_object( $ability ) ? $ability : ( function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null );

		// Another plugin already answered, such as Site Read-Only Mode during a freeze.
		// When it refused an agent's call, the call still belongs in the log.
		if ( ! is_a( $pre, 'WP_Filter_Sentinel' ) ) {
			if ( is_wp_error( $pre ) ) {
				$this->gate->refused( $this->call( $name, $input, $ability ), $pre );
			}
			return $pre;
		}

		$call    = $this->call( $name, $input, $ability );
		$outcome = $this->gate->decide( $call );
		if ( null === $outcome ) {
			return $pre;
		}

		if ( Settings::ALLOW === $outcome['decision']['action'] ) {
			// The wrapper opens the frame and logs the call once it ran.
			$this->context->clear( $name, $outcome );
			return $pre;
		}

		$precheck = static function () use ( $ability, $input ): bool {
			return self::permitted( $ability, $input );
		};

		$result = $this->gate->enter( $call, $precheck, $outcome['identity'], $outcome['decision'] );

		return is_wp_error( $result ) ? $result : $pre;
	}

	/**
	 * Wraps an ability's execute callback as it is registered.
	 *
	 * @param mixed  $args Ability arguments.
	 * @param string $name Ability name.
	 * @return mixed
	 */
	public function wrap( $args, $name ) {
		if ( ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		$name    = (string) $name;
		$execute = $args['execute_callback'];
		$meta    = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();

		$args['execute_callback'] = function ( ...$params ) use ( $execute, $name, $meta ) {
			return $this->run( $name, $meta, $execute, $params );
		};

		return $args;
	}

	/**
	 * Runs a wrapped ability: decides (on 6.9 and 7.0), runs it, and logs it.
	 *
	 * @param string   $name    Ability name.
	 * @param array    $meta    Ability meta.
	 * @param callable $execute The original callback.
	 * @param array    $params  Arguments core passed: none, or the input.
	 * @return mixed
	 */
	public function run( string $name, array $meta, callable $execute, array $params ) {
		if ( in_array( $name, self::dispatchers(), true ) ) {
			$this->context->enter_mcp();
			try {
				return $execute( ...$params );
			} finally {
				$this->context->leave_mcp();
			}
		}

		$input = $params ? $params[0] : null;
		$call  = array(
			'source' => 'ability',
			'target' => $name,
			'method' => $this->method(),
			'kind'   => self::kind( $meta ),
			'input'  => $input,
		);

		if ( $this->has_filter ) {
			// The filter already decided. Only an allowed call gets here.
			$cleared = $this->context->take_cleared( $name );
			$frame   = $cleared ? $this->gate->enter( $call, null, $cleared['identity'], $cleared['decision'] ) : $this->gate->enter( $call );
		} else {
			// Core already checked the input and the permission, so a held call can run as sent.
			$frame = $this->gate->enter( $call );
		}

		if ( is_wp_error( $frame ) ) {
			return $frame;
		}
		if ( ! is_int( $frame ) ) {
			return $execute( ...$params );
		}

		$result = null;
		try {
			$result = $execute( ...$params );
		} finally {
			$this->gate->leave( $frame, $result );
		}

		return $result;
	}

	/**
	 * The call as the policy sees it.
	 *
	 * @param string $name    Ability name.
	 * @param mixed  $input   Input.
	 * @param mixed  $ability The ability, or null.
	 * @return array
	 */
	private function call( string $name, $input, $ability ): array {
		$meta = is_object( $ability ) && method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();

		return array(
			'source' => 'ability',
			'target' => $name,
			'method' => $this->method(),
			'kind'   => self::kind( $meta ),
			'input'  => $input,
		);
	}

	/**
	 * The HTTP method, when the ability runs over REST.
	 *
	 * @return string
	 */
	private function method(): string {
		$rest = $this->context->rest();

		return $rest ? (string) $rest['method'] : '';
	}

	/**
	 * The kind of call (readonly, destructive or write), from the ability's annotations.
	 *
	 * @param array $meta Ability meta.
	 * @return string
	 */
	public static function kind( array $meta ): string {
		$annotations = isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) ? $meta['annotations'] : array();

		if ( ! empty( $annotations['readonly'] ) ) {
			return 'readonly';
		}

		return ! empty( $annotations['destructive'] ) ? 'destructive' : 'write';
	}

	/**
	 * Whether the ability's own checks would let the call run, so nothing is
	 * queued that could never run. On 7.1 the filter runs before those checks.
	 *
	 * @param mixed $ability The ability.
	 * @param mixed $input   Raw input.
	 * @return bool
	 */
	public static function permitted( $ability, $input ): bool {
		if ( ! is_object( $ability ) || ! method_exists( $ability, 'check_permissions' ) ) {
			return true;
		}

		if ( method_exists( $ability, 'normalize_input' ) ) {
			$input = $ability->normalize_input( $input );
			if ( is_wp_error( $input ) ) {
				return false;
			}
		}

		if ( method_exists( $ability, 'validate_input' ) && is_wp_error( $ability->validate_input( $input ) ) ) {
			return false;
		}

		return true === $ability->check_permissions( $input );
	}
}
