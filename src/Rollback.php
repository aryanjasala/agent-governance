<?php
/**
 * Puts back what an agent session changed.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Rolls back the posts an agent session changed: each post it edited goes back
 * to the revision saved just before its first change in the session, with its
 * old status, and each post it created goes to the trash.
 *
 * Everything else the session wrote (options, terms, users, posts deleted for
 * good) is listed as not reversible here.
 */
final class Rollback {

	/**
	 * The audit log.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * Sets up rollback.
	 *
	 * @param Log $log The audit log.
	 */
	public function __construct( Log $log ) {
		$this->log = $log;
	}

	/**
	 * What rolling back these rows would do.
	 *
	 * @param array[] $rows Log rows, oldest first.
	 * @return array{posts: array[], other: array[], rows: int[]} posts: post_id, title,
	 *               action (restore or trash), revision_id, status, fields, possible, note.
	 *               other: target, method, created, note. rows: log row IDs covered.
	 */
	public function plan( array $rows ): array {
		$posts = array();
		$other = array();
		$ids   = array();

		foreach ( $rows as $row ) {
			if ( ! empty( $row['rolled_back'] ) || 'readonly' === $row['kind'] || ! in_array( $row['decision'], array( 'allowed', 'executed' ), true ) ) {
				continue;
			}

			$ids[]   = (int) $row['id'];
			$changes = json_decode( (string) $row['changes'], true );
			$changes = is_array( $changes ) ? $changes : array();

			// A call that failed without touching a post, or one of this plugin's own, changed nothing to undo.
			if ( ! $changes && ( 'ok' !== $row['result'] || Policy::is_own( (string) $row['target'] ) ) ) {
				continue;
			}

			if ( ! $changes ) {
				$other[] = array(
					'target'  => (string) $row['target'],
					'method'  => (string) $row['method'],
					'created' => (string) $row['created'],
					'note'    => __( 'Not reversible here: no post changes were recorded for this call.', 'agent-governance' ),
				);
				continue;
			}

			foreach ( $changes as $change ) {
				$post_id = (int) ( $change['post_id'] ?? 0 );
				if ( ! $post_id ) {
					continue;
				}
				if ( 'deleted' === ( $change['action'] ?? '' ) ) {
					$other[] = array(
						'target'  => (string) $row['target'],
						'method'  => (string) $row['method'],
						'created' => (string) $row['created'],
						/* translators: 1: post title, 2: post ID. */
						'note'    => sprintf( __( 'Not reversible here: "%1$s" (#%2$d) was deleted permanently.', 'agent-governance' ), (string) ( $change['title'] ?? '' ), $post_id ),
					);
					continue;
				}
				if ( ! isset( $posts[ $post_id ] ) ) {
					// The earliest change in the session holds the state to go back to.
					$posts[ $post_id ] = $change;
				}
			}
		}

		$items = array();
		foreach ( $posts as $post_id => $change ) {
			$items[] = $this->item( (int) $post_id, $change );
		}

		return array(
			'posts' => $items,
			'other' => $other,
			'rows'  => $ids,
		);
	}

	/**
	 * One post in the plan.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $change  Its earliest recorded change.
	 * @return array
	 */
	private function item( int $post_id, array $change ): array {
		$post = get_post( $post_id );
		$item = array(
			'post_id'     => $post_id,
			'title'       => $post ? get_the_title( $post ) : (string) ( $change['title'] ?? '' ),
			'action'      => 'created' === $change['action'] ? 'trash' : 'restore',
			'revision_id' => (int) ( $change['revision_id'] ?? 0 ),
			'status'      => (string) ( $change['status'] ?? '' ),
			'fields'      => isset( $change['fields'] ) && is_array( $change['fields'] ) ? $change['fields'] : null,
			'possible'    => true,
			'note'        => '',
		);

		if ( ! $post ) {
			$item['possible'] = false;
			$item['note']     = __( 'The post no longer exists.', 'agent-governance' );
		} elseif ( 'trash' === $item['action'] ) {
			$item['note'] = 'trash' === $post->post_status ? __( 'Already in the trash.', 'agent-governance' ) : __( 'Created by the agent. Moves to the trash.', 'agent-governance' );
			if ( 'trash' === $post->post_status ) {
				$item['possible'] = false;
			}
		} elseif ( $item['revision_id'] && ! wp_get_post_revision( $item['revision_id'] ) ) {
			$item['possible'] = false;
			$item['note']     = __( 'The revision saved before the change was deleted.', 'agent-governance' );
		} elseif ( ! $item['revision_id'] && ! $item['fields'] ) {
			$item['possible'] = false;
			$item['note']     = __( 'Nothing was saved to restore from.', 'agent-governance' );
		} else {
			$item['note'] = $item['revision_id']
				/* translators: %s: post status, such as publish. */
				? sprintf( __( 'Restores the revision saved before the agent changed it, with status "%s".', 'agent-governance' ), $item['status'] )
				/* translators: %s: post status, such as publish. */
				: sprintf( __( 'Restores the title, content and excerpt saved before the agent changed it, with status "%s".', 'agent-governance' ), $item['status'] );
		}

		return $item;
	}

	/**
	 * Carries out a plan.
	 *
	 * @param array  $plan  From plan().
	 * @param string $scope What is rolled back, for the action: a session key or an identity.
	 * @return array{restored: int, trashed: int, failed: int, skipped: int}
	 */
	public function run( array $plan, string $scope ): array {
		$result = array(
			'restored' => 0,
			'trashed'  => 0,
			'failed'   => 0,
			'skipped'  => count( $plan['other'] ),
		);

		foreach ( $plan['posts'] as $item ) {
			if ( ! $item['possible'] ) {
				++$result['skipped'];
				continue;
			}

			if ( 'trash' === $item['action'] ) {
				$done = (bool) wp_trash_post( (int) $item['post_id'] );
				++$result[ $done ? 'trashed' : 'failed' ];
				continue;
			}

			++$result[ $this->restore( $item ) ? 'restored' : 'failed' ];
		}

		$this->log->mark_rolled_back( $plan['rows'] );

		/**
		 * Fires after an agent session was rolled back.
		 *
		 * @param string $scope  The session key, or the identity for WP-CLI rollbacks by time.
		 * @param array  $result restored, trashed, failed and skipped counts.
		 * @param array  $plan   What was planned: posts, other and rows.
		 */
		do_action( 'agent_governance_session_rolled_back', $scope, $result, $plan );

		return $result;
	}

	/**
	 * Restores one post.
	 *
	 * @param array $item Plan item.
	 * @return bool
	 */
	private function restore( array $item ): bool {
		$post_id = (int) $item['post_id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		if ( 'trash' === $post->post_status && 'trash' !== $item['status'] ) {
			wp_untrash_post( $post_id );
		}

		if ( $item['revision_id'] ) {
			if ( ! wp_restore_post_revision( (int) $item['revision_id'] ) ) {
				return false;
			}
		} elseif ( $item['fields'] ) {
			$updated = wp_update_post( array_merge( array( 'ID' => $post_id ), wp_slash( $item['fields'] ) ), true );
			if ( is_wp_error( $updated ) ) {
				return false;
			}
		}

		$post = get_post( $post_id );
		if ( $post && '' !== $item['status'] && $post->post_status !== $item['status'] ) {
			$updated = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $item['status'],
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Where to undo other changes: Activity Log with Undo, when it is active.
	 *
	 * @return string URL, or empty.
	 */
	public static function undo_url(): string {
		$url = class_exists( '\AryanX\Axle\ActivityLogWithUndo\Plugin' ) ? admin_url( 'tools.php?page=activity-log-with-undo' ) : '';

		/**
		 * Filters the link offered for changes a session rollback cannot undo.
		 *
		 * @param string $url Default Activity Log with Undo's screen when it is active, otherwise empty.
		 */
		return esc_url_raw( (string) apply_filters( 'agent_governance_undo_url', $url ) );
	}
}
