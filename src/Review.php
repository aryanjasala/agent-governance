<?php
/**
 * Whether a person may look at, and decide, what a held request touches.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * The approver capability says who may use the queue. This says whether that
 * person could change the object a request is about, so a preview never shows
 * a draft, a user or a setting to someone who cannot open it themselves.
 */
final class Review {

	/**
	 * Whether a user may see the preview of a request and decide it.
	 *
	 * @param array $request The request row: source, target, method and dry_run.
	 * @param int   $user_id The user, 0 for the current one.
	 * @return bool
	 */
	public static function can( array $request, int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		// WP-CLI has no signed-in person: whoever has shell access decides.
		$allowed = $user_id > 0 ? self::check( $request, $user_id ) : defined( 'WP_CLI' ) && WP_CLI;

		/**
		 * Filters whether a user may see what a held request touches and decide it.
		 *
		 * The built-in check covers the object a preview names (a post, term or
		 * user) and the REST routes for settings, plugins, users and new posts.
		 * Use it for objects of your own abilities.
		 *
		 * @param bool  $allowed Whether they may.
		 * @param array $request The request row.
		 * @param int   $user_id The user.
		 */
		return (bool) apply_filters( 'agent_governance_can_review_request', $allowed, $request, $user_id );
	}

	/**
	 * The check before the filter.
	 *
	 * @param array $request The request row.
	 * @param int   $user_id The user.
	 * @return bool
	 */
	private static function check( array $request, int $user_id ): bool {
		$preview = json_decode( (string) ( $request['dry_run'] ?? '' ), true );
		$object  = is_array( $preview ) && isset( $preview['object'] ) && is_array( $preview['object'] ) ? $preview['object'] : array();
		$type    = (string) ( $object['type'] ?? '' );
		$id      = (int) ( $object['id'] ?? 0 );

		if ( '' !== $type && $id > 0 ) {
			if ( 'user' === $type ) {
				return ! get_userdata( $id ) || user_can( $user_id, 'edit_user', $id );
			}
			if ( post_type_exists( $type ) ) {
				return ! get_post( $id ) || user_can( $user_id, 'edit_post', $id );
			}
			if ( taxonomy_exists( $type ) ) {
				return ! get_term( $id, $type ) || user_can( $user_id, 'edit_term', $id );
			}
		}

		if ( 'rest' !== ( $request['source'] ?? '' ) ) {
			return true;
		}

		$route  = (string) ( $request['target'] ?? '' );
		$method = strtoupper( (string) ( $request['method'] ?? '' ) );

		if ( '/wp/v2/settings' === $route ) {
			return user_can( $user_id, 'manage_options' );
		}
		if ( 0 === strpos( $route, '/wp/v2/plugins' ) ) {
			return user_can( $user_id, 'DELETE' === $method ? 'delete_plugins' : 'activate_plugins' );
		}
		if ( '/wp/v2/users' === $route && 'POST' === $method ) {
			return user_can( $user_id, 'create_users' );
		}

		if ( 'POST' === $method ) {
			foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $post_type ) {
				if ( DryRun::rest_path( $post_type ) === $route ) {
					return user_can( $user_id, (string) ( $post_type->cap->create_posts ?? 'edit_posts' ) );
				}
			}
		}

		return true;
	}
}
