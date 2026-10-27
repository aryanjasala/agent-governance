<?php
/**
 * The Activity tab: the audit log.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Admin;

use AryanX\Axle\AgentGovernance\Log;
use AryanX\Axle\AgentGovernance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every agent call, newest first, filtered by agent, outcome and ability or route.
 */
final class ActivityView {

	/**
	 * The audit log.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * Rules, to name the one behind a decision.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the tab.
	 *
	 * @param Log      $log      The audit log.
	 * @param Settings $settings Rules, to name the one behind a decision.
	 */
	public function __construct( Log $log, Settings $settings ) {
		$this->log      = $log;
		$this->settings = $settings;
	}

	/**
	 * Renders the filters and the table.
	 */
	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$identity = isset( $_GET['identity'] ) ? sanitize_text_field( wp_unslash( $_GET['identity'] ) ) : '';
		$decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';
		$search   = isset( $_GET['target'] ) ? sanitize_text_field( wp_unslash( $_GET['target'] ) ) : '';
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$per      = Page::per_page();
		$patterns = array_column( $this->settings->all_rules(), 'pattern', 'id' );

		$decisions = array(
			''         => __( 'Every outcome', 'agent-governance' ),
			'allowed'  => __( 'Allowed', 'agent-governance' ),
			'denied'   => __( 'Denied', 'agent-governance' ),
			'held'     => __( 'Held', 'agent-governance' ),
			'executed' => __( 'Executed', 'agent-governance' ),
			'failed'   => __( 'Failed', 'agent-governance' ),
			'refused'  => __( 'Refused by another plugin', 'agent-governance' ),
		);

		echo '<form method="get" class="agg-filters"><input type="hidden" name="page" value="' . esc_attr( Page::SLUG ) . '"><input type="hidden" name="tab" value="activity">';
		echo '<label class="screen-reader-text" for="agg-identity">' . esc_html__( 'Agent', 'agent-governance' ) . '</label><select id="agg-identity" name="identity"><option value="">' . esc_html__( 'Every agent', 'agent-governance' ) . '</option>';
		foreach ( $this->log->identities() as $key => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $key ), selected( $key, $identity, false ), esc_html( $label ) );
		}
		echo '</select> ';
		echo '<label class="screen-reader-text" for="agg-decision">' . esc_html__( 'Outcome', 'agent-governance' ) . '</label><select id="agg-decision" name="decision">';
		foreach ( $decisions as $key => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $key ), selected( $key, $decision, false ), esc_html( $label ) );
		}
		echo '</select> ';
		printf( '<label class="screen-reader-text" for="agg-target">%1$s</label><input type="search" id="agg-target" name="target" value="%2$s" placeholder="%3$s"> ', esc_html__( 'Ability or route', 'agent-governance' ), esc_attr( $search ), esc_attr__( 'Ability or route', 'agent-governance' ) );
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'agent-governance' ) . '</button></form>';

		$found = $this->log->query(
			array(
				'identity' => $identity,
				'decision' => $decision,
				'search'   => $search,
				'limit'    => $per,
				'offset'   => ( $paged - 1 ) * $per,
			)
		);

		$columns = array(
			'when'     => __( 'When', 'agent-governance' ),
			'agent'    => __( 'Agent', 'agent-governance' ),
			'call'     => __( 'Call', 'agent-governance' ),
			'kind'     => __( 'Kind', 'agent-governance' ),
			'outcome'  => __( 'Outcome', 'agent-governance' ),
			'why'      => __( 'Why', 'agent-governance' ),
			'result'   => __( 'Result', 'agent-governance' ),
			'duration' => __( 'Time', 'agent-governance' ),
		);

		echo '<table class="widefat striped agg-table agg-stack agg-activity"><thead><tr>';
		foreach ( $columns as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $found['rows'] ) {
			echo '<tr><td colspan="8" class="agg-empty">' . esc_html__( 'No agent calls yet.', 'agent-governance' ) . '</td></tr>';
		}

		foreach ( $found['rows'] as $row ) {
			$outcome = Display::badge( (string) $row['decision'] );
			if ( (int) $row['request_id'] ) {
				$outcome .= sprintf(
					' <a class="agg-link" href="%1$s">%2$s</a>',
					esc_url( Page::request_url( (int) $row['request_id'] ) ),
					/* translators: %d: request ID. */
					esc_html( sprintf( __( 'Request #%d', 'agent-governance' ), (int) $row['request_id'] ) )
				);
			}
			printf(
				'<tr data-log="%1$d"><td data-colname="%11$s">%2$s</td><td data-colname="%12$s">%3$s<div class="agg-sub">%4$s</div></td><td data-colname="%13$s">%5$s</td><td data-colname="%14$s">%6$s</td><td data-colname="%15$s">%7$s</td><td data-colname="%16$s">%8$s</td><td data-colname="%17$s">%9$s</td><td data-colname="%18$s">%10$s</td></tr>',
				(int) $row['id'],
				Display::time( (string) $row['created'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				esc_html( Log::identity_label( $row ) ),
				esc_html( Display::via( (string) $row['via'] ) ),
				Display::call( $row ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				esc_html( Display::kind( (string) $row['kind'] ) ),
				$outcome, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				esc_html( Display::reason( (string) $row['reason'], $patterns ) ),
				Display::result_html( (string) $row['result'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				esc_html( sprintf( /* translators: %d: milliseconds. */ __( '%d ms', 'agent-governance' ), (int) $row['duration_ms'] ) ),
				esc_attr( $columns['when'] ),
				esc_attr( $columns['agent'] ),
				esc_attr( $columns['call'] ),
				esc_attr( $columns['kind'] ),
				esc_attr( $columns['outcome'] ),
				esc_attr( $columns['why'] ),
				esc_attr( $columns['result'] ),
				esc_attr( $columns['duration'] )
			);
		}
		echo '</tbody></table>';

		RequestsView::pagination( $found['total'], $per, $paged );
	}
}
