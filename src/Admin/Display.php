<?php
/**
 * Small formatting helpers for the screens.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Dates, outcome badges and JSON blocks, escaped for output.
 */
final class Display {

	/**
	 * A stored UTC datetime in the site's format, with "x ago".
	 *
	 * @param string|null $datetime Y-m-d H:i:s in UTC.
	 * @return string Escaped HTML.
	 */
	public static function time( ?string $datetime ): string {
		if ( ! $datetime ) {
			return '&ndash;';
		}

		$time = (int) strtotime( $datetime . ' UTC' );

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_attr( (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ),
			/* translators: %s: time span, such as "5 mins". */
			esc_html( sprintf( __( '%s ago', 'agent-governance' ), human_time_diff( $time, time() ) ) )
		);
	}

	/**
	 * A colored badge for an outcome or status.
	 *
	 * @param string $value Outcome or status.
	 * @return string Escaped HTML.
	 */
	public static function badge( string $value ): string {
		$labels = array(
			'allowed'  => __( 'Allowed', 'agent-governance' ),
			'denied'   => __( 'Denied', 'agent-governance' ),
			'held'     => __( 'Held', 'agent-governance' ),
			'executed' => __( 'Executed', 'agent-governance' ),
			'failed'   => __( 'Failed', 'agent-governance' ),
			'pending'  => __( 'Pending', 'agent-governance' ),
			'approved' => __( 'Approved', 'agent-governance' ),
			'rejected' => __( 'Rejected', 'agent-governance' ),
			'expired'  => __( 'Expired', 'agent-governance' ),
		);

		return sprintf( '<span class="agg-badge agg-badge--%1$s">%2$s</span>', esc_attr( sanitize_html_class( $value ) ), esc_html( $labels[ $value ] ?? $value ) );
	}

	/**
	 * Pretty-printed JSON in a pre block.
	 *
	 * @param string|null $json Stored JSON.
	 * @return string Escaped HTML.
	 */
	public static function json( ?string $json ): string {
		$data = json_decode( (string) $json, true );
		if ( null === $data && 'null' !== trim( (string) $json ) ) {
			return '<pre class="agg-json">' . esc_html( (string) $json ) . '</pre>';
		}

		return '<pre class="agg-json">' . esc_html( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}

	/**
	 * The call: method and target.
	 *
	 * @param array $row Log or request row.
	 * @return string Escaped HTML.
	 */
	public static function call( array $row ): string {
		$method = (string) $row['method'];

		return ( '' !== $method && 'rest' === $row['source'] ? '<span class="agg-method">' . esc_html( $method ) . '</span> ' : '' ) . '<code>' . esc_html( (string) $row['target'] ) . '</code>';
	}

	/**
	 * Readable kind of call.
	 *
	 * @param string $kind readonly, write or destructive.
	 * @return string
	 */
	public static function kind( string $kind ): string {
		$labels = array(
			'readonly'    => __( 'Read', 'agent-governance' ),
			'write'       => __( 'Write', 'agent-governance' ),
			'destructive' => __( 'Destructive', 'agent-governance' ),
		);

		return $labels[ $kind ] ?? $kind;
	}

	/**
	 * Readable reason for a decision.
	 *
	 * @param string                $reason   Stored reason.
	 * @param array<string, string> $patterns Rule patterns by rule ID.
	 * @return string
	 */
	public static function reason( string $reason, array $patterns = array() ): string {
		if ( 0 === strpos( $reason, 'rule:' ) ) {
			$id = substr( $reason, 5 );
			/* translators: %s: rule pattern, or its ID when the rule was removed. */
			return sprintf( __( 'Rule %s', 'agent-governance' ), $patterns[ $id ] ?? $id );
		}

		$labels = array(
			'own'        => __( 'Agent Governance ability', 'agent-governance' ),
			'paused'     => __( 'Paused', 'agent-governance' ),
			'rate_limit' => __( 'Rate limit', 'agent-governance' ),
			'default'    => __( 'Default', 'agent-governance' ),
			'approved'   => __( 'Approved', 'agent-governance' ),
			'filter'     => __( 'Custom code', 'agent-governance' ),
		);

		return $labels[ $reason ] ?? $reason;
	}
}
