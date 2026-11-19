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
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/axle-ui.js', PLUGIN_FILE ), array( 'wp-a11y' ), VERSION, true );
		wp_add_inline_script(
			self::HANDLE,
			'window.axleUiL10n = window.axleUiL10n || ' . wp_json_encode( array( 'justNow' => __( 'just now', 'agent-governance' ) ) ) . ';',
			'before'
		);
	}

	/**
	 * Keeps a top-level admin-bar node visible on phones. Core hides every
	 * top-level node it does not know below 782 px; this shows the node as a
	 * Dashicon there (its text stays for screen readers), the way core shows
	 * its own. Call it right after $wp_admin_bar->add_node().
	 *
	 * @param string $node_id  The node's ID.
	 * @param string $dashicon A Dashicons class, such as "dashicons-lock".
	 */
	public static function admin_bar_mobile( string $node_id, string $dashicon ): void {
		global $wp_admin_bar;

		if ( ! $wp_admin_bar instanceof \WP_Admin_Bar ) {
			return;
		}

		$node = $wp_admin_bar->get_node( $node_id );

		if ( null === $node ) {
			return;
		}

		$title = (string) $node->title;
		$meta  = (array) $node->meta;
		$class = trim( ( $meta['class'] ?? '' ) . ' axle-ab-mobile' );

		if ( false === strpos( $title, 'ab-label' ) ) {
			$title = '<span class="ab-icon dashicons ' . esc_attr( sanitize_html_class( $dashicon ) ) . '" aria-hidden="true"></span><span class="ab-label">' . $title . '</span>';
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => $node_id,
				'title' => $title,
				'meta'  => array_merge( $meta, array( 'class' => $class ) ),
			)
		);

		// The bar renders after the head, so this prints with the late styles in the footer.
		wp_enqueue_style( 'axle-admin-bar', plugins_url( 'assets/axle-admin-bar.css', PLUGIN_FILE ), array( 'admin-bar' ), VERSION );
	}

	/**
	 * Prints a complete standalone page in WordPress's own login-screen look,
	 * for people who are signed out, refused or visiting as guests. Prints
	 * nothing else; the caller exits after it.
	 *
	 * Page parts in $args: title (the document title), message (HTML shown as
	 * a notice), error (HTML shown as an error), body (HTML inside the white
	 * box, forms included; escape it yourself), links ([url, label] pairs under
	 * the box) and status (the HTTP status code).
	 *
	 * @param array<string, mixed> $args Page parts, see above.
	 */
	public static function guest_page( array $args ): void {
		$args = wp_parse_args(
			$args,
			array(
				'title'   => '',
				'message' => '',
				'error'   => '',
				'body'    => '',
				'links'   => array(),
				'status'  => 200,
			)
		);

		if ( ! headers_sent() ) {
			status_header( (int) $args['status'] );
			nocache_headers();
			// Older cores leave out no-store; guest pages can hold codes and personal details.
			header( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private' );
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$icon = (string) get_site_icon_url( 84 );
		$css  = '' !== $icon
			? '.login h1 a{background-image:url("' . esc_url_raw( $icon ) . '");background-size:84px;border-radius:8px}'
			: '.login h1 a{background:none;width:auto;height:auto;text-indent:0;font-size:20px;line-height:1.3;font-weight:600;color:#3c434a;text-decoration:none}';
		$css .= '.login .axle-guest-box{margin-top:20px;padding:26px 24px;font-weight:400;overflow:hidden;background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 3px rgba(0,0,0,.04)}'
			. '.login .axle-guest-box form{margin:0;padding:0;border:0;box-shadow:none;background:none}'
			. '.login .axle-guest-box > :first-child{margin-top:0}.login .axle-guest-box > :last-child{margin-bottom:0}'
			. '.login .axle-guest-box p{margin:0 0 16px}.login .axle-guest-box .button{margin-right:6px}'
			. '.login .axle-guest-box .button-primary{float:none}';

		wp_register_style( 'axle-guest', false, array( 'login' ), VERSION );
		wp_add_inline_style( 'axle-guest', $css );

		$allowed = array_merge(
			wp_kses_allowed_html( 'post' ),
			array(
				'form'     => array(
					'action'     => true,
					'method'     => true,
					'class'      => true,
					'id'         => true,
					'novalidate' => true,
				),
				'input'    => array(
					'type'             => true,
					'name'             => true,
					'value'            => true,
					'id'               => true,
					'class'            => true,
					'autocomplete'     => true,
					'inputmode'        => true,
					'pattern'          => true,
					'maxlength'        => true,
					'required'         => true,
					'aria-describedby' => true,
					'spellcheck'       => true,
					'size'             => true,
					'checked'          => true,
					'readonly'         => true,
					'autofocus'        => true,
					'disabled'         => true,
				),
				'button'   => array(
					'type'     => true,
					'name'     => true,
					'value'    => true,
					'class'    => true,
					'id'       => true,
					'disabled' => true,
				),
				'select'   => array(
					'name'  => true,
					'id'    => true,
					'class' => true,
				),
				'option'   => array(
					'value'    => true,
					'selected' => true,
				),
				'label'    => array(
					'for'   => true,
					'class' => true,
				),
				'fieldset' => array( 'class' => true ),
				'legend'   => array( 'class' => true ),
			)
		);
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<meta name="referrer" content="strict-origin-when-cross-origin" />
<title><?php echo esc_html( ( '' !== $args['title'] ? $args['title'] . ' &lsaquo; ' : '' ) . $site ); ?></title>
		<?php wp_print_styles( array( 'login', 'axle-guest' ) ); ?>
</head>
<body class="login no-js wp-core-ui axle-guest">
<div id="login">
<h1 class="wp-login-logo"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( $site ); ?></a></h1>
		<?php if ( '' !== $args['error'] ) : ?>
<div id="login_error" class="notice notice-error"><p><?php echo wp_kses_post( $args['error'] ); ?></p></div>
		<?php endif; ?>
		<?php if ( '' !== $args['message'] ) : ?>
<div id="login-message" class="notice notice-info message"><p><?php echo wp_kses_post( $args['message'] ); ?></p></div>
		<?php endif; ?>
		<?php if ( '' !== $args['body'] ) : ?>
<div class="axle-guest-box"><?php echo wp_kses( $args['body'], $allowed ); ?></div>
		<?php endif; ?>
		<?php if ( array() !== $args['links'] ) : ?>
<p id="nav">
			<?php
			$links = array();
			foreach ( (array) $args['links'] as $link ) {
				$links[] = '<a href="' . esc_url( (string) $link[0] ) . '">' . esc_html( (string) $link[1] ) . '</a>';
			}
			echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each link is escaped above.
			?>
</p>
		<?php endif; ?>
<p id="backtoblog"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<?php
		/* translators: %s: Site title. */
		echo wp_kses_post( sprintf( _x( '&larr; Go to %s', 'site', 'agent-governance' ), esc_html( $site ) ) );
		?>
</a></p>
</div>
</body>
</html>
		<?php
	}

	/**
	 * Front-end styles and the measuring script for a bar in the admin bar's
	 * colours: <div class="axle-front-bar axle-front-bar--bottom wp-core-ui"
	 * role="region" aria-label="…"><p class="axle-front-bar__text">…</p>
	 * <p class="axle-front-bar__actions"><a class="button">…</a></p></div>.
	 * Theme CSS does not reach inside it; the page is padded by its real height.
	 */
	public static function enqueue_front(): void {
		wp_enqueue_style( 'axle-front', plugins_url( 'assets/axle-front.css', PLUGIN_FILE ), array( 'buttons', 'dashicons' ), VERSION );
		wp_enqueue_script( 'axle-front', plugins_url( 'assets/axle-front.js', PLUGIN_FILE ), array(), VERSION, true );
	}

	/**
	 * The environment type, as Axle plugins see it. Staging Guard hooks the
	 * filter when it knows better than core (a copy found by its production
	 * lock still reports "production" to core).
	 */
	public static function environment_type(): string {
		$core = wp_get_environment_type();

		/**
		 * Filters the environment type Axle plugins act on.
		 *
		 * @param string $type One of local, development, staging, production.
		 */
		$type = (string) apply_filters( 'axle_environment_type', $core ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- One hook shared by the whole Axle family.

		return in_array( $type, array( 'local', 'development', 'staging', 'production' ), true ) ? $type : $core;
	}

	/**
	 * Headers that let a loopback request to this same site through Staging
	 * Guard's password gate. Signed, valid for two minutes, and only sent to
	 * this site's own host.
	 *
	 * @param string $url The URL the request goes to.
	 * @return array<string, string>
	 */
	public static function loopback_headers( string $url ): array {
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$hosts = array_filter(
			array(
				strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
			)
		);

		if ( '' === $host || ! in_array( $host, $hosts, true ) ) {
			return array();
		}

		$time = time();

		return array( 'X-Axle-Loopback' => $time . '.' . self::loopback_signature( $time ) );
	}

	/**
	 * Whether the current request carries a valid loopback header.
	 */
	public static function is_loopback(): bool {
		$header = isset( $_SERVER['HTTP_X_AXLE_LOOPBACK'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AXLE_LOOPBACK'] ) ) : '';

		if ( 1 !== preg_match( '/^(\d{9,11})\.([a-f0-9]{64})$/', $header, $match ) ) {
			return false;
		}

		if ( abs( time() - (int) $match[1] ) > 2 * MINUTE_IN_SECONDS ) {
			return false;
		}

		return hash_equals( self::loopback_signature( (int) $match[1] ), $match[2] );
	}

	/**
	 * The signature every Axle plugin computes the same way.
	 *
	 * @param int $time Unix timestamp.
	 */
	private static function loopback_signature( int $time ): string {
		return hash_hmac( 'sha256', 'axle-loopback|' . $time . '|' . home_url( '/' ), wp_salt( 'auth' ) );
	}

	/**
	 * The shared "ask at the wall" data, for a REST error's data['axle_wall'].
	 * Give either action_url (a screen to open) or request_route (a REST path
	 * the button POSTs request_data to; the response's "message" is shown).
	 *
	 * @param array<string, mixed> $wall plugin, capability, message, action_label, action_url, request_route, request_data.
	 * @return array<string, mixed>
	 */
	public static function wall( array $wall ): array {
		$wall = wp_parse_args(
			$wall,
			array(
				'plugin'        => 'agent-governance',
				'capability'    => '',
				'message'       => '',
				'action_label'  => '',
				'action_url'    => '',
				'request_route' => '',
				'request_data'  => array(),
			)
		);

		return array(
			'plugin'        => sanitize_key( (string) $wall['plugin'] ),
			'capability'    => sanitize_key( (string) $wall['capability'] ),
			'message'       => wp_strip_all_tags( (string) $wall['message'] ),
			'action_label'  => wp_strip_all_tags( (string) $wall['action_label'] ),
			'action_url'    => esc_url_raw( (string) $wall['action_url'] ),
			'request_route' => ltrim( (string) $wall['request_route'], '/' ),
			'request_data'  => (array) $wall['request_data'],
		);
	}

	/**
	 * The wall as an HTML fragment for wp_die() pages and screens. Uses the
	 * action URL; a request route needs JavaScript, so give a URL here.
	 *
	 * @param array<string, mixed> $wall See wall().
	 */
	public static function wall_html( array $wall ): string {
		$wall = self::wall( $wall );
		$html = '<p class="axle-wall__message">' . esc_html( (string) $wall['message'] ) . '</p>';

		if ( '' !== $wall['action_label'] && '' !== $wall['action_url'] ) {
			$html .= '<p class="axle-wall__action"><a class="button button-primary" href="' . esc_url( (string) $wall['action_url'] ) . '">' . esc_html( (string) $wall['action_label'] ) . '</a></p>';
		}

		return $html;
	}

	/**
	 * Loads the middleware that turns any REST error carrying data['axle_wall']
	 * into a notice with its action, in the block editor and in wp-admin.
	 */
	public static function enqueue_wall(): void {
		wp_enqueue_script( 'axle-wall', plugins_url( 'assets/axle-wall.js', PLUGIN_FILE ), array( 'wp-api-fetch', 'wp-data', 'wp-notices', 'wp-a11y' ), VERSION, true );
		wp_add_inline_script(
			'axle-wall',
			'window.axleWallL10n = window.axleWallL10n || ' . wp_json_encode(
				array(
					'sent'   => __( 'Request sent.', 'agent-governance' ),
					'failed' => __( 'The request could not be sent. Try again.', 'agent-governance' ),
				)
			) . ';',
			'before'
		);
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
