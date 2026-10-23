<?php
/**
 * Tells agent calls apart from people and decides which agent it is.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * A call counts as an agent call when there is a sign of an agent: it comes
 * through the MCP adapter (its REST route, or an MCP tool call such as
 * `wp mcp-adapter serve` over STDIO), or it is a REST request signed in with
 * an application password. A person in a browser, signed in with the cookie
 * and a REST nonce, is not an agent, even on the Abilities REST API.
 *
 * Each application password is one agent identity, "app:<uuid>". Without one,
 * the identity is the user, "user:<id>". MCP calls over HTTP also carry the
 * MCP session, which groups them for rollback.
 */
final class Classifier {

	/**
	 * Request signals.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Sets up the classifier.
	 *
	 * @param Context $context Request signals.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Route prefixes that make a call an agent call, and what they are called.
	 *
	 * @return array<string, string> Prefix => via.
	 */
	public static function agent_routes(): array {
		/**
		 * Filters the REST route prefixes that count as agent calls, whoever signs in.
		 *
		 * @param array<string, string> $routes Route prefix => label. Default the
		 *                                      MCP adapter. The Abilities API is not
		 *                                      one: a person in wp-admin uses it too.
		 */
		$routes = (array) apply_filters(
			'agent_governance_agent_routes',
			array(
				'/mcp/' => 'mcp',
			)
		);

		$clean = array();
		foreach ( (array) $routes as $prefix => $via ) {
			if ( '' !== (string) $prefix ) {
				$clean[ $prefix ] = sanitize_key( (string) $via );
			}
		}

		return $clean;
	}

	/**
	 * Signals for the current call.
	 *
	 * @return array user_id, app_password, route, method, mcp_session, mcp.
	 */
	public function signals(): array {
		$rest = $this->context->rest();

		return array(
			'user_id'      => get_current_user_id(),
			'app_password' => $this->context->app_password(),
			'route'        => $rest['route'] ?? '',
			'method'       => $rest['method'] ?? '',
			'mcp_session'  => $rest['mcp_session'] ?? '',
			'mcp'          => $this->context->in_mcp(),
		);
	}

	/**
	 * The agent behind the current call, or null when it is not an agent call.
	 *
	 * While an approved request runs, that request's agent.
	 *
	 * @return array|null key, label, user_id, via, session.
	 */
	public function current(): ?array {
		$replay = $this->context->replay();
		if ( $replay ) {
			return array(
				'key'     => (string) $replay['identity'],
				'label'   => (string) $replay['agent'],
				'user_id' => (int) $replay['user_id'],
				'via'     => (string) $replay['via'],
				'session' => (string) $replay['session'],
			);
		}

		return $this->classify( $this->signals() );
	}

	/**
	 * Whether the caller is a machine: signed in with an application password, or
	 * coming through MCP. Such a caller may never decide a held request.
	 *
	 * @return bool
	 */
	public function is_machine(): bool {
		$signals = $this->signals();

		return null !== $signals['app_password'] || $signals['mcp'] || 'mcp' === $this->via_route( (string) $signals['route'] );
	}

	/**
	 * Classifies a call from its signals.
	 *
	 * @param array $signals user_id, app_password, route, method, mcp_session, mcp.
	 * @return array|null key, label, user_id, via, session. Null when it is not an agent call.
	 */
	public function classify( array $signals ): ?array {
		$app   = isset( $signals['app_password'] ) && is_array( $signals['app_password'] ) && '' !== (string) ( $signals['app_password']['uuid'] ?? '' ) ? $signals['app_password'] : null;
		$route = (string) ( $signals['route'] ?? '' );
		$via   = $this->via_route( $route );

		if ( ! empty( $signals['mcp'] ) ) {
			$via = 'mcp';
		}
		if ( '' === $via && $app ) {
			$via = 'app-password';
		}

		/**
		 * Filters whether a call counts as an agent call.
		 *
		 * @param bool  $is_agent Whether it does. Default true for the MCP adapter
		 *                        and application passwords. False for a person
		 *                        signed in with the cookie and a REST nonce, also
		 *                        on the Abilities REST API.
		 * @param array $signals  user_id, app_password (uuid, name, user_id or null),
		 *                        route, method, mcp_session and mcp (an MCP tool call is running).
		 */
		if ( ! apply_filters( 'agent_governance_is_agent_request', '' !== $via, $signals ) ) {
			return null;
		}

		$user_id  = (int) ( $signals['user_id'] ?? 0 );
		$session  = (string) ( $signals['mcp_session'] ?? '' );
		$identity = array(
			'key'     => $app ? 'app:' . $app['uuid'] : 'user:' . $user_id,
			'label'   => $app ? (string) $app['name'] : self::user_label( $user_id ),
			'user_id' => $user_id,
			'via'     => '' === $via ? 'custom' : $via,
			'session' => '' === $session ? '' : 'mcp:' . $session,
		);

		/**
		 * Filters the identity of an agent call.
		 *
		 * The key groups calls for policies, rate limits and the log. Give each
		 * agent its own key, for example from a header your agents send.
		 *
		 * @param array $identity key (max 191 characters), label, user_id, via and
		 *                        session (empty to group by time).
		 * @param array $signals  The call's signals, as for agent_governance_is_agent_request.
		 */
		$filtered = (array) apply_filters( 'agent_governance_identity', $identity, $signals );

		if ( isset( $filtered['key'] ) && is_string( $filtered['key'] ) && '' !== trim( $filtered['key'] ) ) {
			$identity = array(
				'key'     => substr( trim( $filtered['key'] ), 0, 191 ),
				'label'   => substr( (string) ( $filtered['label'] ?? $identity['label'] ), 0, 191 ),
				'user_id' => (int) ( $filtered['user_id'] ?? $identity['user_id'] ),
				'via'     => sanitize_key( (string) ( $filtered['via'] ?? $identity['via'] ) ),
				'session' => substr( (string) ( $filtered['session'] ?? $identity['session'] ), 0, 191 ),
			);
		}

		return $identity;
	}

	/**
	 * Which agent route a REST route is under.
	 *
	 * @param string $route REST route.
	 * @return string The route's label, or empty.
	 */
	private function via_route( string $route ): string {
		if ( '' === $route ) {
			return '';
		}

		foreach ( self::agent_routes() as $prefix => $via ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				return '' === $via ? 'custom' : $via;
			}
		}

		return '';
	}

	/**
	 * A user's login, for the label of an identity without an application password.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function user_label( int $user_id ): string {
		if ( ! $user_id ) {
			return __( 'Not signed in', 'agent-governance' );
		}

		$user = get_userdata( $user_id );

		return $user ? (string) $user->user_login : '#' . $user_id;
	}
}
