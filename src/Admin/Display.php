<?php
/**
 * Small formatting helpers for the screens.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Admin;

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
		return sprintf( '<span class="agg-badge agg-badge--%1$s">%2$s</span>', esc_attr( sanitize_html_class( $value ) ), esc_html( self::outcome( $value ) ) );
	}

	/**
	 * An outcome or status in words.
	 *
	 * @param string $value Outcome or status.
	 * @return string
	 */
	public static function outcome( string $value ): string {
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
			'refused'  => __( 'Refused', 'agent-governance' ),
		);

		return $labels[ $value ] ?? $value;
	}

	/**
	 * How a call came in, in words.
	 *
	 * @param string $via Stored value: app-password, mcp, abilities-api (older entries), custom, or a label from agent_governance_agent_routes.
	 * @return string
	 */
	public static function via( string $via ): string {
		/**
		 * Filters the names shown for how an agent call came in.
		 *
		 * @param array<string, string> $labels Stored value => label.
		 */
		$labels = (array) apply_filters(
			'agent_governance_via_labels',
			array(
				'app-password'  => __( 'Application password', 'agent-governance' ),
				'mcp'           => __( 'MCP', 'agent-governance' ),
				'abilities-api' => __( 'Abilities API', 'agent-governance' ),
				'custom'        => __( 'Custom code', 'agent-governance' ),
			)
		);

		if ( '' === $via ) {
			return '';
		}

		$label = trim( (string) ( $labels[ $via ] ?? '' ) );

		return '' !== $label ? $label : ucfirst( str_replace( array( '-', '_' ), ' ', $via ) );
	}

	/**
	 * A stored result code for a table cell: in words when there are words for
	 * it, otherwise the code itself.
	 *
	 * @param string $code Result code.
	 * @return string Escaped HTML.
	 */
	public static function result_html( string $code ): string {
		$label = self::result( $code );

		return $label === $code ? '<code dir="ltr">' . esc_html( $code ) . '</code>' : esc_html( $label );
	}

	/**
	 * A stored result code, in words.
	 *
	 * @param string $code ok, an error code, or http_<status>.
	 * @return string
	 */
	public static function result( string $code ): string {
		/**
		 * Filters the words shown for the result codes in the Activity tab.
		 *
		 * @param array<string, string> $labels Result code => label. Codes not in
		 *                                      the list are shown as they are.
		 */
		$labels = (array) apply_filters(
			'agent_governance_result_labels',
			array(
				'ok'                            => __( 'OK', 'agent-governance' ),
				'agent_governance_pending'      => __( 'Waiting for approval', 'agent-governance' ),
				'agent_governance_denied'       => __( 'Refused by a rule', 'agent-governance' ),
				'agent_governance_paused'       => __( 'Agent writes are paused', 'agent-governance' ),
				'agent_governance_rate_limited' => __( 'Over the rate limit', 'agent-governance' ),
				'agent_governance_exception'    => __( 'The call threw an error', 'agent-governance' ),
				'site_read_only_mode'           => __( 'Refused: the site is frozen', 'agent-governance' ),
				'rest_forbidden'                => __( 'Not allowed', 'agent-governance' ),
				'rest_no_route'                 => __( 'No such route', 'agent-governance' ),
				'rest_invalid_param'            => __( 'Invalid input', 'agent-governance' ),
				'ability_invalid_input'         => __( 'Invalid input', 'agent-governance' ),
				'ability_invalid_permissions'   => __( 'Not allowed', 'agent-governance' ),
			)
		);

		$label = trim( (string) ( $labels[ $code ] ?? '' ) );
		if ( '' !== $label ) {
			return $label;
		}

		if ( preg_match( '/^http_(\d{3})$/', $code, $m ) ) {
			/* translators: %s: HTTP status code. */
			return sprintf( __( 'HTTP %s', 'agent-governance' ), $m[1] );
		}

		return $code;
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
			return '<pre class="agg-json" dir="ltr">' . esc_html( (string) $json ) . '</pre>';
		}

		return '<pre class="agg-json" dir="ltr">' . esc_html( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
	}

	/**
	 * The call: method and target.
	 *
	 * @param array $row Log or request row.
	 * @return string Escaped HTML.
	 */
	public static function call( array $row ): string {
		$method = (string) $row['method'];

		return ( '' !== $method && 'rest' === $row['source'] ? '<span class="agg-method">' . esc_html( $method ) . '</span> ' : '' ) . '<code dir="ltr">' . esc_html( (string) $row['target'] ) . '</code>';
	}

	/**
	 * The "back to the list" arrow, pointing the right way in right-to-left languages.
	 *
	 * @return string HTML entity.
	 */
	public static function back_arrow(): string {
		return function_exists( 'is_rtl' ) && is_rtl() ? '&rarr;' : '&larr;';
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
			'own'                 => __( 'Agent Governance ability', 'agent-governance' ),
			'paused'              => __( 'Paused', 'agent-governance' ),
			'rate_limit'          => __( 'Rate limit', 'agent-governance' ),
			'default'             => __( 'Default', 'agent-governance' ),
			'approved'            => __( 'Approved', 'agent-governance' ),
			'filter'              => __( 'Custom code', 'agent-governance' ),
			'refused'             => __( 'Another plugin', 'agent-governance' ),
			// A refusal by another plugin stores its error code as the reason.
			'site_read_only_mode' => __( 'Site Read-Only Mode freeze', 'agent-governance' ),
		);

		if ( isset( $labels[ $reason ] ) ) {
			return $labels[ $reason ];
		}

		$result = self::result( $reason );

		return $result !== $reason ? $result : $reason;
	}
}
