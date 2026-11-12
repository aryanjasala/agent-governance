<?php
/**
 * The parts every Axle plugin screen shares.
 *
 * Generated from _dev/repo-template by apply-template.py. Do not edit it in a
 * plugin; change the template and re-apply. See _dev/DESIGN.md.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Admin;

use const AryanX\Axle\AgentGovernance\PLUGIN_FILE;
use const AryanX\Axle\AgentGovernance\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Loads axle-ui.css and prints the family footer.
 */
final class AxleUi {

	public const HANDLE = 'agent-governance-axle-ui';

	/**
	 * Registers and enqueues the shared stylesheet. Call it from the
	 * plugin's own admin_enqueue_scripts callback, after checking the screen.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/axle-ui.css', PLUGIN_FILE ), array(), VERSION );
	}

	/**
	 * Prints the one-line footer at the bottom of the plugin's own screens.
	 */
	public static function footer(): void {
		printf(
			'<p class="axle-footer">%1$s &middot; <a href="%2$s">%3$s</a> &middot; <a href="%4$s">%5$s</a></p>',
			esc_html__( 'Part of Axle by AryanX', 'agent-governance' ),
			esc_url( 'https://axlewp.com/plugins/agent-governance/' ),
			esc_html__( 'Documentation', 'agent-governance' ),
			esc_url( 'https://wordpress.org/support/plugin/agent-governance/' ),
			esc_html__( 'Support', 'agent-governance' )
		);
	}

	/**
	 * A time as "5 minutes ago" or "in 2 hours", with the exact time in the
	 * title and a machine-readable datetime. Returns escaped HTML.
	 *
	 * @param int    $timestamp Unix timestamp. Zero or less means there is no time.
	 * @param string $fallback  What to show when there is no time. Empty shows "Never".
	 */
	public static function relative_time( int $timestamp, string $fallback = '' ): string {
		if ( $timestamp <= 0 ) {
			return '<span class="axle-muted">' . esc_html( '' !== $fallback ? $fallback : __( 'Never', 'agent-governance' ) ) . '</span>';
		}

		$now  = time();
		$span = human_time_diff( $timestamp, $now );

		if ( abs( $now - $timestamp ) < MINUTE_IN_SECONDS ) {
			$text = __( 'just now', 'agent-governance' );
		} elseif ( $timestamp > $now ) {
			/* translators: %s: a time span such as "5 minutes". */
			$text = sprintf( __( 'in %s', 'agent-governance' ), $span );
		} else {
			/* translators: %s: a time span such as "5 minutes". */
			$text = sprintf( __( '%s ago', 'agent-governance' ), $span );
		}

		$exact = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );

		return sprintf(
			'<time class="axle-time" datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_attr( (string) $exact ),
			esc_html( $text )
		);
	}

	/**
	 * Adds Help tabs to the current screen, with the documentation and
	 * support links in the sidebar. Call it on the screen's load-{$hook} action.
	 *
	 * @param array<int, array{id: string, title: string, content: string}> $tabs One entry per tab. The content is HTML.
	 */
	public static function help_tabs( array $tabs ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen ) {
			return;
		}

		foreach ( $tabs as $tab ) {
			$screen->add_help_tab(
				array(
					'id'      => 'agent-governance-' . $tab['id'],
					'title'   => $tab['title'],
					'content' => wp_kses_post( $tab['content'] ),
				)
			);
		}

		$screen->set_help_sidebar(
			sprintf(
				'<p><strong>%1$s</strong></p><p><a href="%2$s">%3$s</a></p><p><a href="%4$s">%5$s</a></p>',
				esc_html__( 'For more information:', 'agent-governance' ),
				esc_url( 'https://axlewp.com/plugins/agent-governance/' ),
				esc_html__( 'Documentation', 'agent-governance' ),
				esc_url( 'https://wordpress.org/support/plugin/agent-governance/' ),
				esc_html__( 'Support', 'agent-governance' )
			)
		);
	}
}
