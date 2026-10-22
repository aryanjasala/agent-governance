<?php
/**
 * The Sessions tab: review and roll back an agent session.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance\Admin;

use AryanX\AgentGovernance\Log;
use AryanX\AgentGovernance\Rollback;

defined( 'ABSPATH' ) || exit;

/**
 * Lists sessions (one agent's calls close together in time, or one MCP
 * session) and shows what rolling one back would do before it does it.
 */
final class SessionsView {

	/**
	 * The audit log.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * Session rollback.
	 *
	 * @var Rollback
	 */
	private Rollback $rollback;

	/**
	 * Sets up the tab.
	 *
	 * @param Log      $log      The audit log.
	 * @param Rollback $rollback Session rollback.
	 */
	public function __construct( Log $log, Rollback $rollback ) {
		$this->log      = $log;
		$this->rollback = $rollback;
	}

	/**
	 * Renders the list, or one session.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks what to show.
		$session = isset( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : '';
		if ( '' !== $session ) {
			$this->render_one( $session );
			return;
		}

		/* translators: %s: time span, such as "30 mins". */
		echo '<p class="description agg-intro">' . esc_html( sprintf( __( 'A session is one agent\'s calls with no gap longer than %s, or one MCP session. Review a session to see what it changed and roll back its post edits.', 'agent-governance' ), human_time_diff( 0, Log::session_gap() ) ) ) . '</p>';

		$sessions = $this->log->sessions( array( 'limit' => Page::per_page() ) );

		echo '<table class="widefat striped agg-table agg-sessions"><thead><tr>';
		foreach ( array( __( 'Agent', 'agent-governance' ), __( 'Started', 'agent-governance' ), __( 'Last call', 'agent-governance' ), __( 'Calls', 'agent-governance' ), __( 'Writes', 'agent-governance' ), __( 'Rolled back', 'agent-governance' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $sessions ) {
			echo '<tr><td colspan="7" class="agg-empty">' . esc_html__( 'No agent sessions yet.', 'agent-governance' ) . '</td></tr>';
		}

		foreach ( $sessions as $row ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$d</td><td>%5$d</td><td>%6$s</td><td><a class="button" href="%7$s">%8$s</a></td></tr>',
				esc_html( Log::identity_label( $row ) ),
				Display::time( (string) $row['first'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				Display::time( (string) $row['last'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				(int) $row['calls'],
				(int) $row['writes'],
				$row['rolled_back'] ? Display::time( (string) $row['rolled_back'] ) : '&ndash;', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				esc_url( Page::url( 'sessions', array( 'session' => (string) $row['session'] ) ) ),
				esc_html__( 'Review', 'agent-governance' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * One session: its calls and the rollback plan.
	 *
	 * @param string $session Session key.
	 */
	private function render_one( string $session ): void {
		$rows = $this->log->session_rows( $session );

		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( Page::url( 'sessions' ) ), esc_html__( 'All sessions', 'agent-governance' ) );
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No calls in this session.', 'agent-governance' ) . '</p>';
			return;
		}

		$first = $rows[0];
		printf(
			'<h2>%1$s</h2><p class="description">%2$s</p>',
			esc_html( sprintf( /* translators: %s: agent name. */ __( 'Session of %s', 'agent-governance' ), Log::identity_label( $first ) ) ),
			esc_html( sprintf( /* translators: %d: number of calls. */ _n( '%d call in this session.', '%d calls in this session.', count( $rows ), 'agent-governance' ), count( $rows ) ) )
		);

		$plan = $this->rollback->plan( $rows );

		echo '<div class="agg-columns"><div class="agg-main">';
		echo '<div class="agg-card agg-plan"><h3>' . esc_html__( 'Roll back', 'agent-governance' ) . '</h3>';

		if ( ! $plan['posts'] && ! $plan['other'] ) {
			echo '<p>' . esc_html__( 'Nothing to roll back: this session only read, or was rolled back already.', 'agent-governance' ) . '</p>';
		}

		if ( $plan['posts'] ) {
			echo '<table class="widefat agg-plan-posts"><thead><tr><th scope="col">' . esc_html__( 'Post', 'agent-governance' ) . '</th><th scope="col">' . esc_html__( 'Rolling back', 'agent-governance' ) . '</th></tr></thead><tbody>';
			foreach ( $plan['posts'] as $item ) {
				$link = get_edit_post_link( (int) $item['post_id'] );
				printf(
					'<tr class="%1$s"><td>%2$s</td><td>%3$s</td></tr>',
					$item['possible'] ? 'agg-plan-ok' : 'agg-plan-skip',
					$link ? sprintf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( (string) $item['title'] ) ) : esc_html( (string) $item['title'] ),
					esc_html( (string) $item['note'] )
				);
			}
			echo '</tbody></table>';
		}

		if ( $plan['other'] ) {
			echo '<h4>' . esc_html__( 'Not reversible here', 'agent-governance' ) . '</h4><ul class="agg-other">';
			foreach ( $plan['other'] as $other ) {
				printf( '<li>%1$s <span class="agg-sub">%2$s</span></li>', Display::call( $other + array( 'source' => 'rest' ) ), esc_html( (string) $other['note'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			}
			echo '</ul>';
			$undo = Rollback::undo_url();
			if ( '' !== $undo ) {
				printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $undo ), esc_html__( 'Undo these in Activity Log with Undo', 'agent-governance' ) );
			}
		}

		$possible = array_filter(
			$plan['posts'],
			static function ( array $item ): bool {
				return $item['possible'];
			}
		);
		if ( $possible ) {
			printf(
				'<form method="post" action="%1$s"><input type="hidden" name="action" value="agent_governance_rollback"><input type="hidden" name="session" value="%2$s">%3$s<p><button type="submit" class="button button-primary agg-rollback" data-agg-confirm="%4$s" data-agg-action="rollback">%5$s</button></p></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $session ),
				wp_nonce_field( 'agent_governance_rollback_' . $session, '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's nonce field.
				esc_attr__( 'Roll back this session? The posts listed are restored or moved to the trash.', 'agent-governance' ),
				esc_html__( 'Roll back session', 'agent-governance' )
			);
		}
		echo '</div></div><div class="agg-side">';

		echo '<div class="agg-card"><h3>' . esc_html__( 'Calls', 'agent-governance' ) . '</h3><ol class="agg-calls">';
		foreach ( $rows as $row ) {
			printf( '<li>%1$s %2$s <span class="agg-sub">%3$s</span></li>', Display::badge( (string) $row['decision'] ), Display::call( $row ), Display::time( (string) $row['created'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
		}
		echo '</ol></div>';

		echo '</div></div>';
	}
}
