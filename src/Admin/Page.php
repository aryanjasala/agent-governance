<?php
/**
 * Tools > Agent Governance.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance\Admin;

use AryanX\AgentGovernance\Approvals;
use AryanX\AgentGovernance\Log;
use AryanX\AgentGovernance\Notifier;
use AryanX\AgentGovernance\Requests;
use AryanX\AgentGovernance\Rollback;
use AryanX\AgentGovernance\Service;
use AryanX\AgentGovernance\Settings;

use const AryanX\AgentGovernance\PLUGIN_FILE;
use const AryanX\AgentGovernance\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The screen, with four tabs: Requests (the approval queue), Activity (the
 * log), Sessions (review and roll back) and Policies. Forms post to
 * admin-post.php and come back with a message.
 */
final class Page implements Service {

	public const SLUG = 'agent-governance';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The audit log.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * The approval queue.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * Approvals.
	 *
	 * @var Approvals
	 */
	private Approvals $approvals;

	/**
	 * Session rollback.
	 *
	 * @var Rollback
	 */
	private Rollback $rollback;

	/**
	 * Sets up the screen.
	 *
	 * @param Settings  $settings  Settings.
	 * @param Log       $log       The audit log.
	 * @param Requests  $requests  The approval queue.
	 * @param Approvals $approvals Approvals.
	 * @param Rollback  $rollback  Session rollback.
	 */
	public function __construct( Settings $settings, Log $log, Requests $requests, Approvals $approvals, Rollback $rollback ) {
		$this->settings  = $settings;
		$this->log       = $log;
		$this->requests  = $requests;
		$this->approvals = $approvals;
		$this->rollback  = $rollback;
	}

	/**
	 * Adds the menu and form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_agent_governance_decide', array( $this, 'handle_decide' ) );
		add_action( 'admin_post_agent_governance_rollback', array( $this, 'handle_rollback' ) );
		add_action( 'admin_post_agent_governance_pause', array( $this, 'handle_pause' ) );
		add_action( 'admin_post_agent_governance_policies', array( $this, 'handle_policies' ) );
	}

	/**
	 * Who may change policies.
	 *
	 * @return string
	 */
	public static function settings_capability(): string {
		/**
		 * Filters the capability that may change policies, the pause and the settings.
		 *
		 * @param string $capability Default manage_options.
		 */
		$cap = (string) apply_filters( 'agent_governance_settings_capability', 'manage_options' );

		return '' !== $cap ? $cap : 'manage_options';
	}

	/**
	 * Rows per page on the lists.
	 *
	 * @return int
	 */
	public static function per_page(): int {
		/**
		 * Filters the rows per page on the Requests, Activity and Sessions tabs.
		 *
		 * @param int $per_page Default 30. 5 to 200.
		 */
		return max( 5, min( 200, (int) apply_filters( 'agent_governance_list_per_page', 30 ) ) );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  requests, activity, sessions or policies.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( string $tab = 'requests', array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'tools.php' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short list.
	}

	/**
	 * URL of one request.
	 *
	 * @param int $id Request ID.
	 * @return string
	 */
	public static function request_url( int $id ): string {
		return self::url( 'requests', array( 'request' => $id ) );
	}

	/**
	 * Whether the current screen is this one.
	 *
	 * @return bool
	 */
	public static function is_current(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && 'tools_page_' . self::SLUG === $screen->id;
	}

	/**
	 * The label under Tools. Short, so it fits on one line with the count of
	 * waiting requests next to it.
	 *
	 * @return string Plain text.
	 */
	public static function menu_title(): string {
		/**
		 * Filters the menu label under Tools. The screen keeps its title, Agent Governance.
		 *
		 * @param string $title Default "AI Agents".
		 */
		$title = trim( (string) apply_filters( 'agent_governance_menu_title', __( 'AI Agents', 'agent-governance' ) ) );

		return '' !== $title ? $title : __( 'AI Agents', 'agent-governance' );
	}

	/**
	 * Adds Tools > AI Agents, with the count of waiting requests.
	 */
	public function add_page(): void {
		$count = current_user_can( Notifier::capability() ) ? $this->requests->pending_count() : 0;
		$title = __( 'Agent Governance', 'agent-governance' );
		$label = esc_html( self::menu_title() );
		$menu  = $count ? $label . sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $count ) : $label;

		$hook = add_management_page( $title, $menu, Notifier::capability(), self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue' ) );
		}
	}

	/**
	 * Styles and the small script for confirmations and the rules table.
	 */
	public function enqueue(): void {
		wp_enqueue_style( 'agent-governance-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array(), VERSION );
		wp_enqueue_script( 'agent-governance-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks' ), VERSION, true );
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private function tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the tab to show.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'requests';

		return in_array( $tab, array( 'requests', 'activity', 'sessions', 'policies' ), true ) ? $tab : 'requests';
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Notifier::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see agent activity.', 'agent-governance' ), 403 );
		}

		$tab   = $this->tab();
		$count = $this->requests->pending_count();
		$tabs  = array(
			'requests' => $count
				/* translators: %d: number of waiting requests. */
				? sprintf( __( 'Requests (%d)', 'agent-governance' ), $count )
				: __( 'Requests', 'agent-governance' ),
			'activity' => __( 'Activity', 'agent-governance' ),
			'sessions' => __( 'Sessions', 'agent-governance' ),
		);
		if ( current_user_can( self::settings_capability() ) ) {
			$tabs['policies'] = __( 'Policies', 'agent-governance' );
		}

		echo '<div class="wrap agg-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Agent Governance', 'agent-governance' ) . '</h1>';
		$this->render_pause();
		echo '<hr class="wp-header-end">';
		$this->render_message();

		echo '<nav class="nav-tab-wrapper agg-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>', esc_url( self::url( $key ) ), $key === $tab ? ' nav-tab-active' : '', $key === $tab ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'activity':
				( new ActivityView( $this->log, $this->settings ) )->render();
				break;
			case 'sessions':
				( new SessionsView( $this->log, $this->rollback ) )->render();
				break;
			case 'policies':
				if ( isset( $tabs['policies'] ) ) {
					( new PoliciesView( $this->settings, $this->log ) )->render();
				}
				break;
			default:
				( new RequestsView( $this->requests ) )->render();
		}

		echo '</div>';
	}

	/**
	 * The pause banner or button.
	 */
	private function render_pause(): void {
		$can     = current_user_can( self::settings_capability() );
		$paused  = $this->settings->is_paused();
		$confirm = $paused ? '' : __( 'Pause every agent write on this site? Agents can still read.', 'agent-governance' );

		if ( $paused ) {
			$by = get_userdata( (int) $this->settings->get( 'paused_by' ) );
			echo '<div class="agg-paused" role="status"><strong>' . esc_html__( 'Agent writes are paused.', 'agent-governance' ) . '</strong> ';
			echo esc_html(
				$by
					/* translators: 1: user name, 2: time span. */
					? sprintf( __( 'Paused by %1$s %2$s ago. Agents can still read; every write is refused.', 'agent-governance' ), $by->display_name, human_time_diff( (int) $this->settings->get( 'paused_at' ), time() ) )
					: __( 'Agents can still read; every write is refused.', 'agent-governance' )
			);
		}

		if ( $can ) {
			printf(
				'<form method="post" action="%1$s" class="agg-pause-form"><input type="hidden" name="action" value="agent_governance_pause"><input type="hidden" name="do" value="%2$s">%3$s<button type="submit" class="%4$s" data-agg-confirm="%5$s" data-agg-action="%2$s">%6$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $paused ? 'resume' : 'pause' ),
				wp_nonce_field( 'agent_governance_pause', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's nonce field.
				esc_attr( $paused ? 'button button-primary' : 'page-title-action agg-pause-button' ),
				esc_attr( $confirm ),
				esc_html( $paused ? __( 'Resume agent writes', 'agent-governance' ) : __( 'Pause agent writes', 'agent-governance' ) )
			);
		}

		if ( $paused ) {
			echo '</div>';
		}
	}

	/**
	 * The message after a form was handled.
	 */
	private function render_message(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only values set by our own redirect.
		$code  = isset( $_GET['agg_msg'] ) ? sanitize_key( wp_unslash( $_GET['agg_msg'] ) ) : '';
		$extra = isset( $_GET['agg_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['agg_detail'] ) ) : '';
		// phpcs:enable
		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'executed'    => array( 'success', __( 'Approved. The call ran.', 'agent-governance' ) ),
			'failed'      => array( 'warning', __( 'Approved, but the call failed. The error is under Result.', 'agent-governance' ) ),
			'rejected'    => array( 'success', __( 'Rejected. The agent will see your reason.', 'agent-governance' ) ),
			'error'       => array( 'error', __( 'That did not work.', 'agent-governance' ) ),
			'rolled_back' => array( 'success', __( 'Session rolled back.', 'agent-governance' ) ),
			'paused'      => array( 'warning', __( 'Agent writes are paused.', 'agent-governance' ) ),
			'resumed'     => array( 'success', __( 'Agent writes are allowed again.', 'agent-governance' ) ),
			'saved'       => array( 'success', __( 'Policies saved.', 'agent-governance' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>', esc_attr( $messages[ $code ][0] ), esc_html( $messages[ $code ][1] ), '' !== $extra ? ' ' . esc_html( $extra ) : '' );
	}

	/**
	 * Approve or reject.
	 */
	public function handle_decide(): void {
		$id = isset( $_POST['request'] ) ? absint( $_POST['request'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked on the next line.
		check_admin_referer( 'agent_governance_decide_' . $id );
		if ( ! current_user_can( Notifier::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to decide agent requests.', 'agent-governance' ), 403 );
		}

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		$result = 'approve' === $decision
			? $this->approvals->approve( $id, get_current_user_id() )
			: $this->approvals->reject( $id, get_current_user_id(), $reason );

		if ( is_wp_error( $result ) ) {
			$this->back( self::request_url( $id ), 'error', $result->get_error_message() );
		}

		$this->back( self::request_url( $id ), 'approve' === $decision ? (string) $result['status'] : 'rejected' );
	}

	/**
	 * Roll back a session.
	 */
	public function handle_rollback(): void {
		$session = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked on the next line.
		check_admin_referer( 'agent_governance_rollback_' . $session );
		if ( ! current_user_can( Notifier::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to roll back agent sessions.', 'agent-governance' ), 403 );
		}

		$plan   = $this->rollback->plan( $this->log->session_rows( $session ) );
		$result = $this->rollback->run( $plan, $session );

		$detail = sprintf(
			/* translators: 1: posts restored, 2: posts trashed, 3: failures. */
			__( 'Restored %1$d, moved to the trash %2$d, failed %3$d.', 'agent-governance' ),
			$result['restored'],
			$result['trashed'],
			$result['failed']
		);

		$this->back( self::url( 'sessions', array( 'session' => $session ) ), 'rolled_back', $detail );
	}

	/**
	 * Pause or resume.
	 */
	public function handle_pause(): void {
		check_admin_referer( 'agent_governance_pause' );
		if ( ! current_user_can( self::settings_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to pause agents.', 'agent-governance' ), 403 );
		}

		$resume = isset( $_POST['do'] ) && 'resume' === sanitize_key( wp_unslash( $_POST['do'] ) );
		if ( $resume ) {
			$this->settings->resume( get_current_user_id() );
		} else {
			$this->settings->pause( get_current_user_id() );
		}

		$referer = wp_get_referer();
		$this->back( $referer ? $referer : self::url(), $resume ? 'resumed' : 'paused' );
	}

	/**
	 * Save policies.
	 */
	public function handle_policies(): void {
		check_admin_referer( 'agent_governance_policies' );
		if ( ! current_user_can( self::settings_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change agent policies.', 'agent-governance' ), 403 );
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitised below and again in Settings.
		$defaults = isset( $_POST['defaults'] ) && is_array( $_POST['defaults'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['defaults'] ) ) : array();
		$posted   = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array();
		// phpcs:enable

		$this->settings->update(
			array(
				'rate_limit'     => isset( $_POST['rate_limit'] ) ? absint( $_POST['rate_limit'] ) : 60,
				'retention_days' => isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 30,
				'email'          => ! empty( $_POST['email'] ),
				'notice'         => ! empty( $_POST['notice'] ),
				'defaults'       => $defaults,
			)
		);

		$rules = array();
		foreach ( (array) $posted as $rule ) {
			if ( ! is_array( $rule ) || ! empty( $rule['remove'] ) ) {
				continue;
			}
			$rules[] = array(
				'id'      => sanitize_key( (string) ( $rule['id'] ?? '' ) ),
				'scope'   => sanitize_key( (string) ( $rule['scope'] ?? '' ) ),
				'subject' => sanitize_text_field( (string) ( $rule['subject'] ?? '' ) ),
				'pattern' => sanitize_text_field( (string) ( $rule['pattern'] ?? '' ) ),
				'action'  => sanitize_key( (string) ( $rule['action'] ?? '' ) ),
			);
		}
		$this->settings->save_rules( $rules );

		$this->back( self::url( 'policies' ), 'saved' );
	}

	/**
	 * Redirects back with a message, and stops.
	 *
	 * @param string $url    Where to.
	 * @param string $code   Message code.
	 * @param string $detail Extra text.
	 */
	private function back( string $url, string $code, string $detail = '' ): void {
		$args = array( 'agg_msg' => $code );
		if ( '' !== $detail ) {
			$args['agg_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, remove_query_arg( array( 'agg_msg', 'agg_detail' ), $url ) ) );
		exit;
	}
}
