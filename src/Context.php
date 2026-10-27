<?php
/**
 * What this request knows about who is calling and how.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the signals the classifier needs while a request runs: the
 * application password it authenticated with, the REST route and MCP session,
 * whether an MCP tool call is running, and approved requests being replayed.
 *
 * It also holds the open call frames, so changes made during an agent call can
 * be recorded against it.
 */
final class Context implements Service {

	/**
	 * Application password used for this request: uuid, name, user_id.
	 *
	 * @var array|null
	 */
	private ?array $app_password = null;

	/**
	 * The outermost REST request: route, method, mcp_session.
	 *
	 * @var array|null
	 */
	private ?array $rest = null;

	/**
	 * How many MCP tool calls are running.
	 *
	 * @var int
	 */
	private int $mcp_depth = 0;

	/**
	 * Approved requests being run, innermost last.
	 *
	 * @var array[]
	 */
	private array $replays = array();

	/**
	 * Open agent calls, innermost last.
	 *
	 * @var array[]
	 */
	private array $frames = array();

	/**
	 * Calls the 7.1 filter already let through, innermost last.
	 *
	 * @var array[]
	 */
	private array $cleared = array();

	/**
	 * Adds the hooks that collect the signals.
	 */
	public function register(): void {
		add_action( 'application_password_did_authenticate', array( $this, 'app_password_used' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( $this, 'capture_rest' ), -1000, 3 );
		add_filter( 'mcp_adapter_pre_tool_call', array( $this, 'enter_mcp_tool' ), -1000 );
		add_filter( 'mcp_adapter_tool_call_result', array( $this, 'leave_mcp_tool' ), 1000 );
	}

	/**
	 * Remembers the application password a request authenticated with.
	 *
	 * @param \WP_User $user The user.
	 * @param array    $item The application password: uuid, name and more.
	 */
	public function app_password_used( $user, $item ): void {
		$item               = (array) $item;
		$this->app_password = array(
			'uuid'    => (string) ( $item['uuid'] ?? '' ),
			'name'    => (string) ( $item['name'] ?? '' ),
			'user_id' => (int) $user->ID,
		);
	}

	/**
	 * The application password for this request, if any.
	 *
	 * Falls back to what core recorded for the REST API, in case the user was
	 * authenticated before the plugin loaded.
	 *
	 * @return array|null uuid, name, user_id.
	 */
	public function app_password(): ?array {
		if ( null !== $this->app_password ) {
			return '' === $this->app_password['uuid'] ? null : $this->app_password;
		}

		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return null;
		}

		$uuid = (string) rest_get_authenticated_app_password();
		if ( '' === $uuid ) {
			return null;
		}

		$user_id = get_current_user_id();
		$item    = class_exists( 'WP_Application_Passwords' ) ? \WP_Application_Passwords::get_user_application_password( $user_id, $uuid ) : null;

		return array(
			'uuid'    => $uuid,
			'name'    => is_array( $item ) ? (string) $item['name'] : '',
			'user_id' => $user_id,
		);
	}

	/**
	 * Records the outermost REST request: its route, method and MCP session.
	 *
	 * @param mixed            $result  Response so far. Passed through.
	 * @param \WP_REST_Server  $server  REST server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function capture_rest( $result, $server, $request ) {
		if ( null === $this->rest ) {
			$this->rest = array(
				'route'       => (string) $request->get_route(),
				'method'      => (string) $request->get_method(),
				'mcp_session' => substr( sanitize_text_field( (string) $request->get_header( 'mcp_session_id' ) ), 0, 128 ),
			);
		}

		return $result;
	}

	/**
	 * The outermost REST request, if this is one.
	 *
	 * @return array|null route, method, mcp_session.
	 */
	public function rest(): ?array {
		return $this->rest;
	}

	/**
	 * An MCP tool call starts.
	 *
	 * @param mixed $args Tool arguments. Passed through.
	 * @return mixed
	 */
	public function enter_mcp_tool( $args ) {
		++$this->mcp_depth;
		return $args;
	}

	/**
	 * An MCP tool call ended.
	 *
	 * @param mixed $result Tool result. Passed through.
	 * @return mixed
	 */
	public function leave_mcp_tool( $result ) {
		$this->mcp_depth = max( 0, $this->mcp_depth - 1 );
		return $result;
	}

	/**
	 * Marks the start of an ability that runs other abilities for MCP.
	 */
	public function enter_mcp(): void {
		++$this->mcp_depth;
	}

	/**
	 * Marks the end of an ability that runs other abilities for MCP.
	 */
	public function leave_mcp(): void {
		$this->mcp_depth = max( 0, $this->mcp_depth - 1 );
	}

	/**
	 * Whether an MCP tool call is running.
	 *
	 * @return bool
	 */
	public function in_mcp(): bool {
		return $this->mcp_depth > 0;
	}

	/**
	 * Starts running an approved request.
	 *
	 * @param array $request The request row.
	 */
	public function begin_replay( array $request ): void {
		$this->replays[] = $request;
	}

	/**
	 * Ends running an approved request.
	 */
	public function end_replay(): void {
		array_pop( $this->replays );
	}

	/**
	 * The approved request being run, if any.
	 *
	 * @return array|null
	 */
	public function replay(): ?array {
		return $this->replays ? end( $this->replays ) : null;
	}

	/**
	 * Opens a frame for an agent call that was let through.
	 *
	 * @param array $frame Call, identity, decision and start time.
	 * @return int Frame number, to close it with.
	 */
	public function push_frame( array $frame ): int {
		$frame['changes'] = array();
		$this->frames[]   = $frame;

		return count( $this->frames );
	}

	/**
	 * Closes a frame and every frame opened after it.
	 *
	 * @param int $number Frame number from push_frame().
	 * @return array|null The frame, or null when it was already closed.
	 */
	public function pop_frame( int $number ): ?array {
		if ( $number < 1 || $number > count( $this->frames ) ) {
			return null;
		}

		$frame        = $this->frames[ $number - 1 ];
		$this->frames = array_slice( $this->frames, 0, $number - 1 );

		return $frame;
	}

	/**
	 * Whether an agent call is running.
	 *
	 * @return bool
	 */
	public function has_frame(): bool {
		return (bool) $this->frames;
	}

	/**
	 * Every open frame, outermost first. For the shutdown fallback.
	 *
	 * @return array[]
	 */
	public function frames(): array {
		return $this->frames;
	}

	/**
	 * Records a change against the innermost open agent call.
	 *
	 * @param array $change What changed.
	 */
	public function add_change( array $change ): void {
		if ( ! $this->frames ) {
			return;
		}

		$this->frames[ count( $this->frames ) - 1 ]['changes'][] = $change;
	}

	/**
	 * Whether the innermost open call already recorded this post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function frame_has_post( int $post_id ): bool {
		if ( ! $this->frames ) {
			return false;
		}

		foreach ( $this->frames[ count( $this->frames ) - 1 ]['changes'] as $change ) {
			if ( (int) ( $change['post_id'] ?? 0 ) === $post_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Remembers that the 7.1 filter let an ability call through.
	 *
	 * @param string $name     Ability name.
	 * @param array  $decision The decision.
	 */
	public function clear( string $name, array $decision ): void {
		$this->cleared[] = array(
			'name'     => $name,
			'decision' => $decision,
		);
		// A call that failed its permission check never reaches the callback. Keep the list short.
		if ( count( $this->cleared ) > 50 ) {
			array_shift( $this->cleared );
		}
	}

	/**
	 * Takes the decision the 7.1 filter made for this ability call.
	 *
	 * @param string $name Ability name.
	 * @return array|null The decision, or null when the filter did not see the call.
	 */
	public function take_cleared( string $name ): ?array {
		for ( $i = count( $this->cleared ) - 1; $i >= 0; $i-- ) {
			if ( $this->cleared[ $i ]['name'] === $name ) {
				$decision      = $this->cleared[ $i ]['decision'];
				$this->cleared = array_slice( $this->cleared, 0, $i );
				return $decision;
			}
		}

		return null;
	}

	/**
	 * The caller's IP address, for the log.
	 *
	 * @return string
	 */
	public function ip(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__ -- Only stored in the log, never used for access decisions; validated below.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';

		/**
		 * Filters the IP address stored with each log entry.
		 *
		 * Return an empty string to keep IP addresses out of the log, or the
		 * visitor's address from a trusted proxy header.
		 *
		 * @param string $ip The address PHP sees. Empty from WP-CLI.
		 */
		$ip = (string) apply_filters( 'agent_governance_client_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Forgets everything, between unit tests.
	 */
	public function reset(): void {
		$this->app_password = null;
		$this->rest         = null;
		$this->mcp_depth    = 0;
		$this->replays      = array();
		$this->frames       = array();
		$this->cleared      = array();
	}
}
