<?php
/**
 * Applies the policy to REST calls agents make outside the Abilities API.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Guards;

use AryanX\Axle\AgentGovernance\Classifier;
use AryanX\Axle\AgentGovernance\Gate;
use AryanX\Axle\AgentGovernance\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Checks each REST request an agent makes, such as POST /wp/v2/posts/12 with an
 * application password, before WordPress runs it.
 *
 * Routes that run abilities (the Abilities API and the MCP adapter) and the
 * batch route are let through here, because each ability or request inside
 * them is checked on its own.
 */
final class RestGuard implements Service {

	/**
	 * The Abilities REST API.
	 */
	public const ABILITIES_ROUTE = '/wp-abilities/v1/';

	/**
	 * Applies the policy.
	 *
	 * @var Gate
	 */
	private Gate $gate;

	/**
	 * Open frames per request object.
	 *
	 * @var array<int, int>
	 */
	private array $frames = array();

	/**
	 * Sets up the guard.
	 *
	 * @param Gate $gate Applies the policy.
	 */
	public function __construct( Gate $gate ) {
		$this->gate = $gate;
	}

	/**
	 * Runs before and after each REST handler.
	 */
	public function register(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'guard' ), 5, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'after' ), 1000, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'post_dispatch' ), 1000, 3 );
	}

	/**
	 * Route prefixes that are not checked here, because what runs inside them is.
	 *
	 * @return string[]
	 */
	public static function passthrough_routes(): array {
		/**
		 * Filters the REST routes the REST check leaves alone, because what runs
		 * inside them is checked one by one.
		 *
		 * @param string[] $routes Route prefixes. Default the agent routes (the
		 *                         MCP adapter), the Abilities API and /batch/v1.
		 */
		$routes = (array) apply_filters( 'agent_governance_passthrough_routes', array_merge( array_keys( Classifier::agent_routes() ), array( self::ABILITIES_ROUTE, '/batch/v1' ) ) );

		return array_values( array_filter( array_map( 'strval', $routes ) ) );
	}

	/**
	 * Checks a REST request.
	 *
	 * @param mixed            $result  Response to use instead, or null.
	 * @param \WP_REST_Server  $server  REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function guard( $result, $server, $request ) {
		if ( null !== $result ) {
			// Another plugin answered first. When it refused an agent call, such as
			// Axle Read-Only Mode during a freeze, the call still belongs in the log.
			if ( is_wp_error( $result ) ) {
				$this->gate->refused( self::refused_call( $request ), $result );
			}
			return $result;
		}

		$route = (string) $request->get_route();
		foreach ( self::passthrough_routes() as $prefix ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				return $result;
			}
		}

		$method = strtoupper( (string) $request->get_method() );
		$call   = array(
			'source' => 'rest',
			'target' => $route,
			'method' => $method,
			'kind'   => self::kind( $method ),
			'input'  => self::input( $request ),
		);

		$frame = $this->gate->enter( $call );
		if ( is_wp_error( $frame ) ) {
			return $frame;
		}
		if ( is_int( $frame ) ) {
			$this->frames[ spl_object_id( $request ) ] = $frame;
		}

		return $result;
	}

	/**
	 * Logs an allowed call once its handler ran.
	 *
	 * @param mixed            $response Response or error.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function after( $response, $handler, $request ) {
		$this->finish( $request, $response );

		return $response;
	}

	/**
	 * Logs an allowed call that never reached a handler, such as an unknown route.
	 *
	 * @param mixed            $response Response.
	 * @param \WP_REST_Server  $server   REST server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function post_dispatch( $response, $server, $request ) {
		$this->finish( $request, $response );

		return self::retry_after( $response );
	}

	/**
	 * Adds the Retry-After header to a rate-limited response, from the seconds
	 * the error carries. Covers REST calls and abilities run over REST.
	 *
	 * @param mixed $response Response.
	 * @return mixed
	 */
	public static function retry_after( $response ) {
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'header' ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || 'agent_governance_rate_limited' !== ( $data['code'] ?? '' ) || empty( $data['data']['retry_after'] ) ) {
			return $response;
		}

		$response->header( 'Retry-After', (string) max( 1, (int) $data['data']['retry_after'] ) );

		return $response;
	}

	/**
	 * The call behind a REST request another plugin refused. A run of the
	 * Abilities API is named after its ability, as the ability check names it.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array source, target, method, kind, input.
	 */
	public static function refused_call( $request ): array {
		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );

		if ( preg_match( '#^' . preg_quote( self::ABILITIES_ROUTE, '#' ) . 'abilities/(.+)/run$#', $route, $m ) ) {
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $m[1] ) : null;
			$meta    = $ability ? (array) $ability->get_meta() : array();

			return array(
				'source' => 'ability',
				'target' => $m[1],
				'method' => $method,
				'kind'   => AbilityGuard::kind( $meta ),
				'input'  => $request->get_param( 'input' ),
			);
		}

		return array(
			'source' => 'rest',
			'target' => $route,
			'method' => $method,
			'kind'   => self::kind( $method ),
			'input'  => self::input( $request ),
		);
	}

	/**
	 * Closes the frame of a request, once.
	 *
	 * @param mixed $request  Request.
	 * @param mixed $response Response.
	 */
	private function finish( $request, $response ): void {
		if ( ! is_object( $request ) ) {
			return;
		}

		$key = spl_object_id( $request );
		if ( isset( $this->frames[ $key ] ) ) {
			$frame = $this->frames[ $key ];
			unset( $this->frames[ $key ] );
			$this->gate->leave( $frame, $response );
		}
	}

	/**
	 * The kind of call (readonly, destructive or write), from the HTTP method.
	 *
	 * @param string $method HTTP method.
	 * @return string
	 */
	public static function kind( string $method ): string {
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return 'readonly';
		}

		return 'DELETE' === $method ? 'destructive' : 'write';
	}

	/**
	 * What the agent sent, so the request can be replayed after approval.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{query: array, body: array}
	 */
	public static function input( $request ): array {
		$query = (array) $request->get_query_params();
		unset( $query['rest_route'], $query['_method'], $query['_envelope'], $query['_wpnonce'] );

		$body = $request->get_json_params();
		if ( ! $body ) {
			$body = (array) $request->get_body_params();
		}

		return array(
			'query' => $query,
			'body'  => $body,
		);
	}
}
