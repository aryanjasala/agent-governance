<?php
/**
 * Records what agent calls do to posts, so a session can be rolled back.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * While an agent call runs, every post it creates, updates, trashes or deletes
 * is recorded against the call.
 *
 * Before a post changes, its current state is saved as a revision (the revision
 * WordPress would compare against anyway) and that revision's ID is recorded,
 * with the status. Post types without revisions get a copy of the title,
 * content, excerpt and status instead.
 */
final class Changes implements Service {

	/**
	 * Request signals and frames.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Sets up the recorder.
	 *
	 * @param Context $context Request signals and frames.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'pre_post_update', array( $this, 'before_update' ), 1, 2 );
		add_action( 'wp_insert_post', array( $this, 'inserted' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'before_delete' ), 10, 2 );
	}

	/**
	 * Post types whose changes are not recorded.
	 *
	 * @return string[]
	 */
	public static function untracked_types(): array {
		/**
		 * Filters the post types whose changes agent sessions do not record.
		 *
		 * @param string[] $types Default revision, customize_changeset, oembed_cache and user_request.
		 */
		$types = (array) apply_filters( 'agent_governance_untracked_post_types', array( 'revision', 'customize_changeset', 'oembed_cache', 'user_request' ) );

		return array_map( 'strval', $types );
	}

	/**
	 * Records a post's state before an agent call changes it.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    New data, unused.
	 */
	public function before_update( $post_id, $data = array() ): void {
		$post_id = (int) $post_id;
		if ( ! $this->context->has_frame() || $this->context->frame_has_post( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || in_array( $post->post_type, self::untracked_types(), true ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->context->add_change(
			array(
				'post_id'     => $post_id,
				'post_type'   => (string) $post->post_type,
				'title'       => (string) $post->post_title,
				'action'      => 'updated',
				'status'      => (string) $post->post_status,
				'revision_id' => $this->snapshot_revision( $post ),
				'fields'      => $this->snapshot_fields( $post ),
			)
		);
	}

	/**
	 * Records a post an agent call created.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Whether it was an update.
	 */
	public function inserted( $post_id, $post, $update ): void {
		if ( $update || ! $this->context->has_frame() ) {
			return;
		}
		if ( in_array( $post->post_type, self::untracked_types(), true ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->context->add_change(
			array(
				'post_id'   => (int) $post_id,
				'post_type' => (string) $post->post_type,
				'title'     => (string) $post->post_title,
				'action'    => 'created',
			)
		);
	}

	/**
	 * Records a post an agent call deleted for good, which cannot be rolled back.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function before_delete( $post_id, $post = null ): void {
		if ( ! $this->context->has_frame() || ! is_object( $post ) || in_array( $post->post_type, self::untracked_types(), true ) ) {
			return;
		}

		$this->context->add_change(
			array(
				'post_id'   => (int) $post_id,
				'post_type' => (string) $post->post_type,
				'title'     => (string) $post->post_title,
				'action'    => 'deleted',
			)
		);
	}

	/**
	 * Saves the post's current state as a revision and returns its ID.
	 *
	 * @param \WP_Post $post Post.
	 * @return int Revision ID, 0 when the post type has no revisions.
	 */
	private function snapshot_revision( $post ): int {
		if ( ! post_type_supports( $post->post_type, 'revisions' ) || ! wp_revisions_enabled( $post ) ) {
			return 0;
		}

		$saved = wp_save_post_revision( $post->ID );
		if ( $saved && ! is_wp_error( $saved ) ) {
			return (int) $saved;
		}

		// Nothing changed since the last revision, so that one is the current state.
		$latest = wp_get_post_revisions(
			$post->ID,
			array(
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return $latest ? (int) reset( $latest ) : 0;
	}

	/**
	 * A copy of the main fields, for post types without revisions.
	 *
	 * @param \WP_Post $post Post.
	 * @return array|null
	 */
	private function snapshot_fields( $post ): ?array {
		if ( post_type_supports( $post->post_type, 'revisions' ) && wp_revisions_enabled( $post ) ) {
			return null;
		}

		$fields = array(
			'post_title'   => (string) $post->post_title,
			'post_content' => (string) $post->post_content,
			'post_excerpt' => (string) $post->post_excerpt,
		);

		return strlen( (string) wp_json_encode( $fields ) ) <= Settings::payload_limit( 'input' ) * 4 ? $fields : null;
	}
}
