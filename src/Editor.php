<?php
/**
 * Agent changes, in the editor: what an agent did to this post, with Undo, and
 * requests held on it, with Approve and Reject.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

use const AryanX\Axle\AgentGovernance\PLUGIN_FILE;
use const AryanX\Axle\AgentGovernance\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * A small REST API under agent-governance/v1 and the script that draws the panel.
 *
 * Every route is checked against the post it names: a person who cannot edit
 * the post gets a 403 and no data, so the panel never shows a change, an agent
 * or a held request on something its viewer cannot edit. Deciding a request
 * needs the approver capability on top, and the request must be one the person
 * may review (Review::can), exactly as on the Requests screen.
 */
final class Editor implements Service {

	public const REST_NAMESPACE = 'agent-governance/v1';
	public const HANDLE         = 'agent-governance-editor';

	/**
	 * The index from posts to calls.
	 *
	 * @var PostIndex
	 */
	private PostIndex $index;

	/**
	 * The audit log.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * The approval queue.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * Approvals.
	 *
	 * @var Approvals
	 */
	private Approvals $approvals;

	/**
	 * Rollback.
	 *
	 * @var Rollback
	 */
	private Rollback $rollback;

	/**
	 * Agent detection.
	 *
	 * @var Classifier
	 */
	private Classifier $classifier;

	/**
	 * Sets up the panel's back end.
	 *
	 * @param PostIndex  $index      The index from posts to calls.
	 * @param Log        $log        The audit log.
	 * @param Requests   $requests   The approval queue.
	 * @param Approvals  $approvals  Approvals.
	 * @param Rollback   $rollback   Rollback.
	 * @param Classifier $classifier Agent detection.
	 */
	public function __construct( PostIndex $index, Log $log, Requests $requests, Approvals $approvals, Rollback $rollback, Classifier $classifier ) {
		$this->index      = $index;
		$this->log        = $log;
		$this->requests   = $requests;
		$this->approvals  = $approvals;
		$this->rollback   = $rollback;
		$this->classifier = $classifier;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_state' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => array( $this, 'can_edit' ),
				'args'                => array(
					'id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'log_id' => array(
						'type'        => 'integer',
						'required'    => true,
						'description' => 'The call the panel showed. Refused when a newer one changed the post.',
					),
					'force'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/requests/(?P<id>\d+)/decide',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'decide' ),
				'permission_callback' => array( $this, 'can_decide' ),
				'args'                => array(
					'id'       => array(
						'type'     => 'integer',
						'required' => true,
					),
					'decision' => array(
						'type'     => 'string',
						'enum'     => array( 'approve', 'reject' ),
						'required' => true,
					),
					'reason'   => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
				),
			)
		);
	}

	/**
	 * Whether the person may edit the post a route names.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit( $request ): bool {
		$id = (int) $request['id'];

		return $id > 0 && (bool) get_post( $id ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Whether the person may decide held requests at all. The request itself is
	 * checked in decide() and again by Approvals.
	 *
	 * @return bool
	 */
	public function can_decide(): bool {
		return is_user_logged_in() && current_user_can( Notifier::capability() );
	}

	/**
	 * Whether the panel is wanted for a post.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	private static function wanted( $post ): bool {
		/**
		 * Filters whether the editor shows the Agent changes panel for a post.
		 *
		 * The panel is only ever offered to people who can edit the post.
		 *
		 * @param bool     $show Default true.
		 * @param \WP_Post $post The post.
		 */
		return (bool) apply_filters( 'agent_governance_editor_panel', true, $post );
	}

	/**
	 * Loads the panel script in the block editor, for people who can edit the post.
	 */
	public function enqueue(): void {
		$post = get_post();
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) || ! self::wanted( $post ) ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/editor.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/editor.js', PLUGIN_FILE ), array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ), VERSION, true );
		wp_set_script_translations( self::HANDLE, 'agent-governance' );
		wp_add_inline_script( self::HANDLE, 'window.agentGovernanceEditor = ' . wp_json_encode( array( 'base' => '/' . self::REST_NAMESPACE ) ) . ';', 'before' );
	}

	/**
	 * Route: the panel's state for one post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_state( $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || ! self::wanted( $post ) ) {
			return rest_ensure_response(
				array(
					'change'   => null,
					'requests' => array(),
				)
			);
		}

		return rest_ensure_response( $this->state( $post ) );
	}

	/**
	 * The panel's state: the latest agent change and the requests held on the post.
	 * The caller has checked that the person may edit the post.
	 *
	 * @param \WP_Post $post The post.
	 * @param int      $user_id The person, 0 for the current one.
	 * @return array{change: array|null, requests: array[]}
	 */
	public function state( $post, int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		return array(
			'change'   => $this->change_of( $post, $user_id ),
			'requests' => $this->requests_of( $post, $user_id ),
		);
	}

	/**
	 * The latest agent change to a post, as the panel shows it.
	 *
	 * @param \WP_Post $post    The post.
	 * @param int      $user_id The person.
	 * @return array|null
	 */
	private function change_of( $post, int $user_id ): ?array {
		$entry = $this->index->latest( (int) $post->ID );
		$row   = $entry ? $this->log->get( (int) $entry['log_id'] ) : null;
		if ( ! $entry || ! $row ) {
			return null;
		}

		$changes = json_decode( (string) $row['changes'], true );
		$found   = null;
		foreach ( is_array( $changes ) ? $changes : array() as $change ) {
			if ( (int) ( $change['post_id'] ?? 0 ) === (int) $post->ID ) {
				$found = $change;
				break;
			}
		}
		if ( ! $found ) {
			return null;
		}

		$undone = ! empty( $entry['undone'] );
		$timing = (int) strtotime( (string) $entry['created'] . ' UTC' );
		$edited = ! $undone && Rollback::edited_since( $post, $row );
		$can    = false;
		$note   = '';

		if ( ! $undone ) {
			$cap = 'created' === ( $found['action'] ?? '' ) ? 'delete_post' : 'edit_post';
			$can = self::may_undo( (int) $post->ID, $user_id, $cap );

			if ( $can ) {
				$plan = $this->rollback->plan( array( $row ) );
				foreach ( $plan['posts'] as $item ) {
					if ( (int) $item['post_id'] === (int) $post->ID && ! $item['possible'] ) {
						$can  = false;
						$note = (string) $item['note'];
					}
				}
			}
		}

		return array(
			'log_id'       => (int) $row['id'],
			'agent'        => Log::identity_label( $row ),
			'action'       => (string) ( $found['action'] ?? 'updated' ),
			'when'         => $timing,
			'ago'          => $this->ago( $timing ),
			'undone'       => $undone,
			'undone_ago'   => $undone ? $this->ago( (int) strtotime( (string) $entry['undone'] . ' UTC' ) ) : '',
			'edited_since' => $edited,
			'can_undo'     => $can,
			'note'         => $note,
			'fields'       => $undone ? array() : $this->field_changes( $post, $found ),
		);
	}

	/**
	 * Whether a person may undo an agent's change to a post.
	 *
	 * @param int    $post_id The post.
	 * @param int    $user_id The person.
	 * @param string $cap     edit_post, or delete_post for a post the agent created.
	 * @return bool
	 */
	private static function may_undo( int $post_id, int $user_id, string $cap ): bool {
		/**
		 * Filters whether a person may undo an agent's change to a post from the editor.
		 *
		 * The default is whoever may edit (or, for a post the agent created, delete) the post.
		 *
		 * @param bool $can     Whether they may.
		 * @param int  $post_id The post.
		 * @param int  $user_id The person.
		 */
		return (bool) apply_filters( 'agent_governance_can_undo_change', user_can( $user_id, $cap, $post_id ), $post_id, $user_id );
	}

	/**
	 * "2 hours ago".
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function ago( int $timestamp ): string {
		/* translators: %s: how long ago, such as "2 hours". */
		return sprintf( __( '%s ago', 'agent-governance' ), human_time_diff( $timestamp, time() ) );
	}

	/**
	 * What the agent changed in the post, field by field: how it was before the
	 * call and how it is now. Only fields that differ.
	 *
	 * @param \WP_Post $post   The post.
	 * @param array    $change The recorded change.
	 * @return array[] field, before, after.
	 */
	private function field_changes( $post, array $change ): array {
		if ( 'created' === ( $change['action'] ?? '' ) ) {
			return array();
		}

		$before = array();
		$rev_id = (int) ( $change['revision_id'] ?? 0 );
		$rev    = $rev_id ? wp_get_post_revision( $rev_id ) : null;
		if ( $rev ) {
			$before = array(
				'post_title'   => (string) $rev->post_title,
				'post_content' => (string) $rev->post_content,
				'post_excerpt' => (string) $rev->post_excerpt,
			);
		} elseif ( ! empty( $change['fields'] ) && is_array( $change['fields'] ) ) {
			$before = array_map( 'strval', $change['fields'] );
		}

		$labels = array(
			'post_title'   => __( 'Title', 'agent-governance' ),
			'post_content' => __( 'Content', 'agent-governance' ),
			'post_excerpt' => __( 'Excerpt', 'agent-governance' ),
		);
		$fields = array();
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $before[ $key ] ) || $before[ $key ] === (string) $post->$key ) {
				continue;
			}
			$fields[] = array(
				'field'  => $label,
				'before' => self::clip( $before[ $key ] ),
				'after'  => self::clip( (string) $post->$key ),
			);
		}

		$status = (string) ( $change['status'] ?? '' );
		if ( '' !== $status && $status !== $post->post_status ) {
			$fields[] = array(
				'field'  => __( 'Status', 'agent-governance' ),
				'before' => $status,
				'after'  => (string) $post->post_status,
			);
		}

		return $fields;
	}

	/**
	 * Shortens a long value for the panel.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clip( string $text ): string {
		/**
		 * Filters how many characters of a field the editor panel shows on each side of a change.
		 *
		 * @param int $length Default 1500. 100 to 20000.
		 */
		$max = max( 100, min( 20000, (int) apply_filters( 'agent_governance_editor_clip', 1500 ) ) );

		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . "\u{2026}" : $text;
	}

	/**
	 * Requests held on the post that this person may see and decide.
	 *
	 * @param \WP_Post $post    The post.
	 * @param int      $user_id The person.
	 * @return array[]
	 */
	private function requests_of( $post, int $user_id ): array {
		if ( ! user_can( $user_id, Notifier::capability() ) ) {
			return array();
		}

		$found = array();
		foreach ( $this->requests->pending_on( (string) $post->post_type, (int) $post->ID ) as $row ) {
			// The object column is only an index: the check is the same as on the Requests screen.
			if ( Requests::is_overdue( $row ) || ! Review::can( $row, $user_id ) ) {
				continue;
			}

			$preview = json_decode( (string) $row['dry_run'], true );
			$preview = is_array( $preview ) ? $preview : array();
			$fields  = array();
			foreach ( isset( $preview['changes'] ) && is_array( $preview['changes'] ) ? $preview['changes'] : array() as $change ) {
				$fields[] = array(
					'field'  => (string) ( $change['field'] ?? '' ),
					'before' => self::clip( (string) ( $change['before'] ?? '' ) ),
					'after'  => self::clip( (string) ( $change['after'] ?? '' ) ),
				);
			}

			$found[] = array(
				'id'      => (int) $row['id'],
				'agent'   => Log::identity_label( $row ),
				'ago'     => $this->ago( (int) strtotime( (string) $row['created'] . ' UTC' ) ),
				'summary' => Admin\RequestsView::summary( $preview ),
				'fields'  => $fields,
				'url'     => Admin\Page::request_url( (int) $row['id'] ),
			);
		}

		return $found;
	}

	/**
	 * Route: undoes the latest agent change to a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function undo( $request ) {
		$post_id = (int) $request['id'];

		return $this->undo_change( $post_id, (int) $request['log_id'], (bool) $request['force'], get_current_user_id() );
	}

	/**
	 * Undoes the latest agent change to a post. The caller has checked that the person may edit it.
	 *
	 * @param int  $post_id Post ID.
	 * @param int  $log_id  The call the person was shown. A newer call on the post makes this refuse.
	 * @param bool $force   Undo although somebody saved the post since.
	 * @param int  $user_id Who undoes it, 0 for WP-CLI.
	 * @return array|\WP_Error
	 */
	public function undo_change( int $post_id, int $log_id, bool $force, int $user_id ) {
		$post  = get_post( $post_id );
		$entry = $post ? $this->index->latest( $post_id ) : null;
		if ( ! $post || ! $entry || ! empty( $entry['undone'] ) ) {
			return new \WP_Error( 'agent_governance_nothing_to_undo', __( 'There is nothing to undo for this post.', 'agent-governance' ), array( 'status' => 404 ) );
		}

		// The person looked at one call. If another one changed the post meanwhile, they have to look again.
		if ( $log_id > 0 && (int) $entry['log_id'] !== $log_id ) {
			return new \WP_Error( 'agent_governance_changed_again', __( 'An agent changed this post again. Reload to see the latest change.', 'agent-governance' ), array( 'status' => 409 ) );
		}

		$row = $this->log->get( (int) $entry['log_id'] );
		if ( ! $row ) {
			return new \WP_Error( 'agent_governance_nothing_to_undo', __( 'There is nothing to undo for this post.', 'agent-governance' ), array( 'status' => 404 ) );
		}

		$changes = json_decode( (string) $row['changes'], true );
		$cap     = 'edit_post';
		foreach ( is_array( $changes ) ? $changes : array() as $change ) {
			if ( (int) ( $change['post_id'] ?? 0 ) === $post_id && 'created' === ( $change['action'] ?? '' ) ) {
				$cap = 'delete_post';
			}
		}

		$can = $user_id < 1 || self::may_undo( $post_id, $user_id, $cap );
		if ( ! $can ) {
			return new \WP_Error( 'agent_governance_forbidden', __( 'You are not allowed to change this post.', 'agent-governance' ), array( 'status' => 403 ) );
		}

		$result = $this->rollback->undo_post( $row, $post_id, $force );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->index->mark_undone( (int) $entry['id'], $user_id );

		/**
		 * Fires after one agent change to a post was undone.
		 *
		 * @param int    $post_id The post.
		 * @param int    $log_id  The log row of the call that was undone.
		 * @param int    $user_id Who undid it, 0 for WP-CLI.
		 * @param string $action  restored or trashed.
		 */
		do_action( 'agent_governance_change_undone', $post_id, (int) $row['id'], $user_id, (string) $result['action'] );

		return array(
			'undone' => true,
			'action' => (string) $result['action'],
		);
	}

	/**
	 * Route: approves or rejects a request held on a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public function decide( $request ) {
		// An agent must not decide a request, its own or another's.
		if ( $this->classifier->is_machine() ) {
			return new \WP_Error( 'agent_governance_agent_cannot_decide', __( 'Requests can only be decided by a person signed in to WordPress, not through an application password or MCP.', 'agent-governance' ), array( 'status' => 403 ) );
		}

		$id   = (int) $request['id'];
		$user = get_current_user_id();

		$result = 'approve' === $request['decision']
			? $this->approvals->approve( $id, $user )
			: $this->approvals->reject( $id, $user, (string) $request['reason'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'      => $id,
			'status'  => (string) $result['status'],
			'message' => Requests::status_label( (string) $result['status'] ),
		);
	}
}
