<?php
/**
 * The Requests tab: the approval queue and one request.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Admin;

use AryanX\Axle\AgentGovernance\Log;
use AryanX\Axle\AgentGovernance\Notifier;
use AryanX\Axle\AgentGovernance\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Lists held requests by status, and shows one with its input, the dry-run
 * preview, and the Approve and Reject buttons.
 */
final class RequestsView {

	/**
	 * The approval queue.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * Sets up the tab.
	 *
	 * @param Requests $requests The approval queue.
	 */
	public function __construct( Requests $requests ) {
		$this->requests = $requests;
	}

	/**
	 * Renders the list, or one request.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks what to show.
		$id = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0;
		if ( $id ) {
			$this->render_one( $id );
			return;
		}

		$this->render_list();
	}

	/**
	 * The list, filtered by status.
	 */
	private function render_list(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : Requests::PENDING;
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$status = 'all' === $status || in_array( $status, Requests::STATUSES, true ) ? $status : Requests::PENDING;
		$per    = Page::per_page();

		$filters = array(
			Requests::PENDING  => __( 'Waiting', 'agent-governance' ),
			Requests::EXECUTED => __( 'Executed', 'agent-governance' ),
			Requests::FAILED   => __( 'Failed', 'agent-governance' ),
			Requests::REJECTED => __( 'Rejected', 'agent-governance' ),
			Requests::EXPIRED  => __( 'Expired', 'agent-governance' ),
			'all'              => __( 'All', 'agent-governance' ),
		);
		echo '<ul class="subsubsub agg-status-filter">';
		$links = array();
		foreach ( $filters as $key => $label ) {
			$links[] = sprintf( '<li><a href="%1$s"%2$s>%3$s</a>', esc_url( Page::url( 'requests', array( 'status' => $key ) ) ), $key === $status ? ' class="current" aria-current="page"' : '', esc_html( $label ) );
		}
		// As core's list tables: the bar inside the item, a space between items, so it reads "Waiting | Executed".
		echo implode( " |</li>\n", $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		echo '</ul>';

		$found = $this->requests->query(
			array(
				'status' => 'all' === $status ? '' : $status,
				'limit'  => $per,
				'offset' => ( $paged - 1 ) * $per,
			)
		);

		$columns = array(
			'request' => __( 'Request', 'agent-governance' ),
			'agent'   => __( 'Agent', 'agent-governance' ),
			'preview' => __( 'What it does', 'agent-governance' ),
			'asked'   => __( 'Asked', 'agent-governance' ),
			'status'  => __( 'Status', 'agent-governance' ),
		);

		echo '<table class="widefat striped agg-table agg-stack agg-requests"><thead><tr>';
		foreach ( $columns as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $found['rows'] ) {
			echo '<tr><td colspan="5" class="agg-empty">' . esc_html( Requests::PENDING === $status ? __( 'No agent calls are waiting for approval.', 'agent-governance' ) : __( 'No requests here.', 'agent-governance' ) ) . '</td></tr>';
		}

		foreach ( $found['rows'] as $row ) {
			$preview = json_decode( (string) $row['dry_run'], true );
			$status  = Requests::is_overdue( $row ) ? Requests::EXPIRED : (string) $row['status'];
			printf(
				'<tr data-request="%1$d"><td data-colname="%8$s"><a class="row-title" href="%2$s">#%1$d</a> %3$s</td><td data-colname="%9$s">%4$s</td><td data-colname="%10$s">%5$s</td><td data-colname="%11$s">%6$s</td><td data-colname="%12$s">%7$s</td></tr>',
				(int) $row['id'],
				esc_url( Page::request_url( (int) $row['id'] ) ),
				Display::call( $row ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				esc_html( Log::identity_label( $row ) ),
				esc_html( self::summary( is_array( $preview ) ? $preview : array() ) ),
				Display::time( (string) $row['created'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				Display::badge( $status ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				esc_attr( $columns['request'] ),
				esc_attr( $columns['agent'] ),
				esc_attr( $columns['preview'] ),
				esc_attr( $columns['asked'] ),
				esc_attr( $columns['status'] )
			);
		}
		echo '</tbody></table>';

		self::pagination( $found['total'], $per, $paged );
	}

	/**
	 * One request.
	 *
	 * @param int $id Request ID.
	 */
	private function render_one( int $id ): void {
		$row = $this->requests->get( $id );
		if ( ! $row ) {
			echo '<p>' . esc_html__( 'No request with that ID.', 'agent-governance' ) . '</p>';
			return;
		}

		$status  = Requests::is_overdue( $row ) ? Requests::EXPIRED : (string) $row['status'];
		$preview = json_decode( (string) $row['dry_run'], true );
		$decider = $row['decided_by'] ? get_userdata( (int) $row['decided_by'] ) : false;
		$user    = get_userdata( (int) $row['user_id'] );

		printf( '<p><a href="%1$s">%2$s %3$s</a></p>', esc_url( Page::url( 'requests' ) ), Display::back_arrow(), esc_html__( 'All requests', 'agent-governance' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An HTML entity from Display.
		printf( '<h2 class="agg-request-title">%1$s %2$s</h2>', esc_html( sprintf( /* translators: %d: request ID. */ __( 'Request #%d', 'agent-governance' ), $id ) ), Display::badge( $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.

		echo '<div class="agg-columns"><div class="agg-main">';

		$pending = Requests::PENDING === $status;
		echo '<div class="agg-card agg-preview"><h3>' . esc_html( $pending ? __( 'What it would change', 'agent-governance' ) : __( 'What it was asked to change', 'agent-governance' ) ) . '</h3>';
		$this->render_preview( is_array( $preview ) ? $preview : array(), $pending );
		echo '</div>';

		echo '<div class="agg-card"><h3>' . esc_html__( 'Input', 'agent-governance' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'What the agent sent. Passwords, tokens and keys are hidden here.', 'agent-governance' ) . '</p>';
		echo Display::json( \AryanX\Axle\AgentGovernance\Redactor::encode( json_decode( (string) $row['input'], true ), 'input' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
		echo '</div>';

		if ( null !== $row['result'] && '' !== (string) $row['result'] ) {
			echo '<div class="agg-card agg-result"><h3>' . esc_html__( 'Result', 'agent-governance' ) . '</h3>';
			echo Display::json( (string) $row['result'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			echo '</div>';
		}

		echo '</div><div class="agg-side">';

		echo '<div class="agg-card"><table class="agg-facts">';
		$facts = array(
			__( 'Agent', 'agent-governance' )        => esc_html( Log::identity_label( $row ) ),
			__( 'Signed in as', 'agent-governance' ) => esc_html( $user ? $user->user_login : '#' . (int) $row['user_id'] ),
			__( 'Through', 'agent-governance' )      => esc_html( Display::via( (string) $row['via'] ) ),
			__( 'Call', 'agent-governance' )         => Display::call( $row ),
			__( 'Kind', 'agent-governance' )         => esc_html( Display::kind( (string) $row['kind'] ) ),
			__( 'Asked', 'agent-governance' )        => Display::time( (string) $row['created'] ),
		);
		if ( Requests::PENDING === $status ) {
			/* translators: %s: time span. */
			$facts[ __( 'Expires', 'agent-governance' ) ] = esc_html( sprintf( __( 'in %s', 'agent-governance' ), human_time_diff( time(), Requests::expires_at( $row ) ) ) );
		}
		if ( $row['decided_at'] ) {
			$facts[ __( 'Decided', 'agent-governance' ) ] = Display::time( (string) $row['decided_at'] ) . ( $decider ? ' ' . esc_html( sprintf( /* translators: %s: user name. */ __( 'by %s', 'agent-governance' ), $decider->display_name ) ) : '' );
		}
		if ( '' !== (string) $row['reason'] ) {
			$facts[ __( 'Reason', 'agent-governance' ) ] = esc_html( (string) $row['reason'] );
		}
		$facts[ __( 'Session', 'agent-governance' ) ] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( Page::url( 'sessions', array( 'session' => (string) $row['session'] ) ) ), esc_html__( 'All calls in this session', 'agent-governance' ) );
		foreach ( $facts as $label => $value ) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each value is escaped above.
		}
		echo '</table></div>';

		if ( Requests::PENDING === $status && current_user_can( Notifier::capability() ) ) {
			$this->render_decision( $id );
		}

		echo '</div></div>';
	}

	/**
	 * The preview's summary, without the advice to check the input that requests
	 * held by version 1.0.0 stored in it.
	 *
	 * @param array $preview Stored preview.
	 * @return string
	 */
	public static function summary( array $preview ): string {
		$summary = (string) ( $preview['summary'] ?? '' );
		$advice  = ' ' . __( 'Check the input below before you approve it.', 'agent-governance' );

		return substr( $summary, -strlen( $advice ) ) === $advice ? substr( $summary, 0, -strlen( $advice ) ) : $summary;
	}

	/**
	 * The preview: summary, the object and the field changes.
	 *
	 * @param array $preview Stored preview.
	 * @param bool  $pending Whether the request still waits for a decision.
	 */
	private function render_preview( array $preview, bool $pending = true ): void {
		if ( ! empty( $preview['_truncated'] ) ) {
			echo '<p>' . esc_html__( 'The preview was too big to store.', 'agent-governance' ) . '</p>';
			return;
		}

		printf( '<p class="agg-summary%1$s">%2$s</p>', empty( $preview['supported'] ) ? ' agg-summary--none' : '', esc_html( self::summary( $preview ) ) );
		if ( empty( $preview['supported'] ) && $pending ) {
			echo '<p class="description agg-check-input">' . esc_html__( 'Check the input below before you approve it.', 'agent-governance' ) . '</p>';
		}

		if ( ! empty( $preview['object']['url'] ) ) {
			printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( (string) $preview['object']['url'] ), esc_html( sprintf( /* translators: %s: item name. */ __( 'Open "%s"', 'agent-governance' ), (string) $preview['object']['label'] ) ) );
		}

		$changes = isset( $preview['changes'] ) && is_array( $preview['changes'] ) ? $preview['changes'] : array();
		if ( ! $changes ) {
			return;
		}

		echo '<table class="widefat agg-diff"><thead><tr><th scope="col">' . esc_html__( 'Field', 'agent-governance' ) . '</th><th scope="col">' . esc_html__( 'Now', 'agent-governance' ) . '</th><th scope="col">' . esc_html__( 'After', 'agent-governance' ) . '</th></tr></thead><tbody>';
		foreach ( $changes as $change ) {
			$before = (string) ( $change['before'] ?? '' );
			$after  = (string) ( $change['after'] ?? '' );
			if ( strlen( $before ) > 120 || strlen( $after ) > 120 || false !== strpos( $before . $after, "\n" ) ) {
				$diff = wp_text_diff( $before, $after, array( 'show_split_view' => true ) );
				printf( '<tr><th scope="row">%1$s</th><td colspan="2" class="agg-text-diff" dir="ltr">%2$s</td></tr>', esc_html( (string) $change['field'] ), $diff ? wp_kses_post( $diff ) : esc_html__( 'No visible change.', 'agent-governance' ) );
				continue;
			}
			printf(
				'<tr><th scope="row">%1$s</th><td dir="auto"><del>%2$s</del></td><td dir="auto"><ins>%3$s</ins></td></tr>',
				esc_html( (string) $change['field'] ),
				'' === $before ? '<em>' . esc_html__( 'empty', 'agent-governance' ) . '</em>' : esc_html( $before ),
				'' === $after ? '<em>' . esc_html__( 'empty', 'agent-governance' ) . '</em>' : esc_html( $after )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Approve and Reject.
	 *
	 * @param int $id Request ID.
	 */
	private function render_decision( int $id ): void {
		$action = esc_url( admin_url( 'admin-post.php' ) );
		$nonce  = wp_nonce_field( 'agent_governance_decide_' . $id, '_wpnonce', true, false );

		echo '<div class="agg-card agg-decide"><h3>' . esc_html__( 'Decide', 'agent-governance' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Approving runs the call now, as the user the agent signed in as.', 'agent-governance' ) . '</p>';

		printf(
			'<form method="post" action="%1$s"><input type="hidden" name="action" value="agent_governance_decide"><input type="hidden" name="request" value="%2$d"><input type="hidden" name="decision" value="approve">%3$s<p><button type="submit" class="button button-primary agg-approve" data-agg-confirm="%4$s" data-agg-action="approve">%5$s</button></p></form>',
			$action, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			(int) $id,
			$nonce, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's nonce field.
			esc_attr__( 'Approve this call and run it now?', 'agent-governance' ),
			esc_html__( 'Approve and run', 'agent-governance' )
		);

		printf(
			'<form method="post" action="%1$s" class="agg-reject"><input type="hidden" name="action" value="agent_governance_decide"><input type="hidden" name="request" value="%2$d"><input type="hidden" name="decision" value="reject">%3$s<p><label for="agg-reason">%4$s</label><textarea id="agg-reason" name="reason" rows="3" class="large-text"></textarea></p><p><button type="submit" class="button agg-reject-button" data-agg-confirm="%5$s" data-agg-action="reject">%6$s</button></p></form>',
			$action, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			(int) $id,
			$nonce, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's nonce field.
			esc_html__( 'Reason, shown to the agent', 'agent-governance' ),
			esc_attr__( 'Reject this call? The agent sees your reason.', 'agent-governance' ),
			esc_html__( 'Reject', 'agent-governance' )
		);

		echo '</div>';
	}

	/**
	 * Previous and next page links.
	 *
	 * @param int $total Rows.
	 * @param int $per   Rows per page.
	 * @param int $paged Current page.
	 */
	public static function pagination( int $total, int $per, int $paged ): void {
		$pages = (int) ceil( $total / $per );
		if ( $pages <= 1 ) {
			return;
		}

		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		/* translators: 1: current page, 2: number of pages. */
		echo '<span class="displaying-num">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'agent-governance' ), $paged, $pages ) ) . '</span> ';
		if ( $paged > 1 ) {
			printf( '<a class="button" href="%1$s">%2$s</a> ', esc_url( add_query_arg( 'paged', $paged - 1 ) ), esc_html__( 'Newer', 'agent-governance' ) );
		}
		if ( $paged < $pages ) {
			printf( '<a class="button" href="%1$s">%2$s</a>', esc_url( add_query_arg( 'paged', $paged + 1 ) ), esc_html__( 'Older', 'agent-governance' ) );
		}
		echo '</div></div>';
	}
}
