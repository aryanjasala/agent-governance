<?php
/**
 * Tools > Agent Governance.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Admin;

use AryanX\Axle\AgentGovernance\Approvals;
use AryanX\Axle\AgentGovernance\Evidence;
use AryanX\Axle\AgentGovernance\Log;
use AryanX\Axle\AgentGovernance\Notifier;
use AryanX\Axle\AgentGovernance\Requests;
use AryanX\Axle\AgentGovernance\Review;
use AryanX\Axle\AgentGovernance\Rollback;
use AryanX\Axle\AgentGovernance\Service;
use AryanX\Axle\AgentGovernance\Settings;
use AryanX\Axle\AgentGovernance\Simulator;
use AryanX\Axle\AgentGovernance\Suggestions;

use const AryanX\Axle\AgentGovernance\PLUGIN_FILE;
use const AryanX\Axle\AgentGovernance\VERSION;

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
	 * Allow rules the approvals suggest.
	 *
	 * @var Suggestions
	 */
	private Suggestions $suggestions;

	/**
	 * Replays the log against a draft policy.
	 *
	 * @var Simulator
	 */
	private Simulator $simulator;

	/**
	 * Signed session evidence.
	 *
	 * @var Evidence
	 */
	private Evidence $evidence;

	/**
	 * Sets up the screen.
	 *
	 * @param Settings    $settings  Settings.
	 * @param Log         $log       The audit log.
	 * @param Requests    $requests  The approval queue.
	 * @param Approvals   $approvals Approvals.
	 * @param Rollback    $rollback  Session rollback.
	 * @param Suggestions $suggestions Allow rules the approvals suggest.
	 * @param Simulator   $simulator Replays the log against a draft policy.
	 * @param Evidence    $evidence  Signed session evidence.
	 */
	public function __construct( Settings $settings, Log $log, Requests $requests, Approvals $approvals, Rollback $rollback, Suggestions $suggestions, Simulator $simulator, Evidence $evidence ) {
		$this->settings    = $settings;
		$this->log         = $log;
		$this->requests    = $requests;
		$this->approvals   = $approvals;
		$this->rollback    = $rollback;
		$this->suggestions = $suggestions;
		$this->simulator   = $simulator;
		$this->evidence    = $evidence;
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
		add_action( 'admin_post_agent_governance_accept_suggestion', array( $this, 'handle_accept_suggestion' ) );
		add_action( 'admin_post_agent_governance_dismiss_suggestion', array( $this, 'handle_dismiss_suggestion' ) );
		add_action( 'admin_post_agent_governance_undo_rule', array( $this, 'handle_undo_rule' ) );
		add_action( 'admin_post_agent_governance_dismiss_steps', array( $this, 'handle_dismiss_steps' ) );
		add_action( 'admin_post_agent_governance_evidence', array( $this, 'handle_evidence' ) );
		add_action( 'agent_governance_rules_saved', array( Suggestions::class, 'flush' ) );
		add_action( 'agent_governance_request_executed', array( Suggestions::class, 'flush' ) );
		add_action( 'agent_governance_request_rejected', array( Suggestions::class, 'flush' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds a link to the screen in the plugin's row on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$links = (array) $links;
		if ( current_user_can( Notifier::capability() ) ) {
			array_unshift( $links, sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::url() ), esc_html__( 'Open', 'agent-governance' ) ) );
		}

		return $links;
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
			add_action( "load-{$hook}", array( $this, 'help' ) );
		}
	}

	/**
	 * Styles and the small script for confirmations and the rules table.
	 */
	public function enqueue(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'agent-governance-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		wp_enqueue_script( 'agent-governance-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks', 'wp-a11y' ), VERSION, true );
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

		echo '<div class="wrap axle-ui agg-wrap" data-agg-pending="' . (int) $count . '" data-agg-tab="' . esc_attr( $tab ) . '" data-agg-cancel="' . esc_attr__( 'Cancel', 'agent-governance' ) . '" data-agg-busy="' . esc_attr__( 'Trying the policy on past calls.', 'agent-governance' ) . '">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Agent Governance', 'agent-governance' ) . '</h1>';
		$this->render_pause( false );
		echo '<hr class="wp-header-end">';
		$this->render_pause( true );
		$this->render_message();

		echo '<nav class="nav-tab-wrapper agg-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>', esc_url( self::url( $key ) ), $key === $tab ? ' nav-tab-active' : '', $key === $tab ? ' aria-current="page"' : '', esc_html( $label ) );
		}
		echo '</nav>';

		$intros = array(
			'requests' => __( 'Agent calls that wait for a person. Approving runs the call as the agent\'s user; rejecting tells the agent why.', 'agent-governance' ),
			'activity' => __( 'Every call an agent made, and what your rules did with it.', 'agent-governance' ),
			'policies' => __( 'Rules decide which agent calls run, wait for approval or are refused.', 'agent-governance' ),
		);
		if ( isset( $intros[ $tab ] ) ) {
			echo '<p class="axle-intro">' . esc_html( $intros[ $tab ] ) . '</p>';
		}

		if ( 'requests' === $tab && ! isset( $_GET['request'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks what to show.
			$this->render_steps();
		}

		switch ( $tab ) {
			case 'activity':
				( new ActivityView( $this->log, $this->settings ) )->render();
				break;
			case 'sessions':
				( new SessionsView( $this->log, $this->rollback ) )->render();
				break;
			case 'policies':
				if ( isset( $tabs['policies'] ) ) {
					$this->render_policies();
				}
				break;
			default:
				( new RequestsView( $this->requests, $this->suggestions ) )->render();
		}

		AxleUi::footer();
		echo '</div>';
	}

	/**
	 * The pause button beside the heading, or the banner under it once paused.
	 *
	 * @param bool $banner False for the part before the header rule, true for the part after it.
	 */
	private function render_pause( bool $banner ): void {
		$can     = current_user_can( self::settings_capability() );
		$paused  = $this->settings->is_paused();
		$confirm = $paused ? '' : __( 'This refuses every agent write on the site until someone resumes them. Agents can still read. You can undo it with the Resume button.', 'agent-governance' );

		if ( $banner !== $paused ) {
			if ( ! $banner && $paused ) {
				echo '<span class="axle-status"><span class="axle-badge axle-badge--warning">' . esc_html__( 'Paused', 'agent-governance' ) . '</span></span>';
			}
			return;
		}

		if ( $paused ) {
			$by = get_userdata( (int) $this->settings->get( 'paused_by' ) );
			echo '<div class="notice notice-warning inline agg-paused" role="status"><p><strong>' . esc_html__( 'Agent writes are paused.', 'agent-governance' ) . '</strong> ';
			if ( $by ) {
				/* translators: %s: user name. */
				echo esc_html( sprintf( __( 'Paused by %s.', 'agent-governance' ), $by->display_name ) ) . ' ' . AxleUi::relative_time( (int) $this->settings->get( 'paused_at' ) ) . '. '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi.
			}
			echo esc_html__( 'Agents can still read; every write is refused.', 'agent-governance' );
			echo '</p>';
		}

		if ( $can ) {
			printf(
				'<form method="post" action="%1$s" class="agg-pause-form"><input type="hidden" name="action" value="agent_governance_pause"><input type="hidden" name="do" value="%2$s">%3$s<button type="submit" class="%4$s" data-agg-confirm="%5$s" data-agg-action="%2$s">%6$s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( $paused ? 'resume' : 'pause' ),
				wp_nonce_field( 'agent_governance_pause', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's nonce field.
				esc_attr( $paused ? 'button' : 'page-title-action agg-pause-button' ),
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
			'rule_added'  => array( 'success', __( 'Rule added. It is the first rule, so it applies right away.', 'agent-governance' ) ),
			'rule_gone'   => array( 'success', __( 'Rule removed.', 'agent-governance' ) ),
			'dismissed'   => array( 'success', __( 'Suggestion hidden. It will not come back for you.', 'agent-governance' ) ),
			'steps_done'  => array( 'success', __( 'Getting started hidden.', 'agent-governance' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		// An added rule can be taken out again.
		$undo = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only value set by our own redirect.
		$rule_id = isset( $_GET['agg_rule'] ) ? sanitize_key( wp_unslash( $_GET['agg_rule'] ) ) : '';
		if ( 'rule_added' === $code && '' !== $rule_id && current_user_can( self::settings_capability() ) ) {
			$undo = sprintf( ' <a href="%1$s">%2$s</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=agent_governance_undo_rule&rule=' . $rule_id ), 'agent_governance_undo_rule_' . $rule_id ) ), esc_html__( 'Undo', 'agent-governance' ) );
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible agg-message" data-agg-speak="%4$s"><p>%2$s%3$s%5$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] ),
			'' !== $extra ? ' ' . esc_html( $extra ) : '',
			esc_attr( $messages[ $code ][1] . ( '' !== $extra ? ' ' . $extra : '' ) ),
			$undo // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		);
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
		if ( $result['denied'] ) {
			$detail .= ' ' . sprintf(
				/* translators: %d: number of posts. */
				_n( '%d post was left alone because you are not allowed to change it.', '%d posts were left alone because you are not allowed to change them.', $result['denied'], 'agent-governance' ),
				$result['denied']
			);
		}

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

		$posted = $this->posted_policy();

		$this->settings->update(
			array(
				'rate_limit'     => $posted['rate_limit'],
				'retention_days' => isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 30,
				'email'          => ! empty( $_POST['email'] ),
				'notice'         => ! empty( $_POST['notice'] ),
				'defaults'       => $posted['defaults'],
			)
		);

		$rules = $posted['rules'];
		$this->settings->save_rules( $rules );

		$this->back( self::url( 'policies' ), 'saved' );
	}

	/**
	 * The policy fields of the Policies form: rules, what happens when no rule matches and the rate limit.
	 * The caller has checked the nonce and the capability.
	 *
	 * @return array{rules: array[], defaults: array, rate_limit: int}
	 */
	private function posted_policy(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The caller checked the nonce.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is sanitised below and again in Settings.
		$defaults = isset( $_POST['defaults'] ) && is_array( $_POST['defaults'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['defaults'] ) ) : array();
		$posted   = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array();
		$limit    = isset( $_POST['rate_limit'] ) ? absint( $_POST['rate_limit'] ) : 60;
		// phpcs:enable

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

		return array(
			'rules'      => $rules,
			'defaults'   => $defaults,
			'rate_limit' => $limit,
		);
	}

	/**
	 * The Policies tab. When the form was posted with "Try this policy", replays
	 * the log against the draft in it and shows the result above the form.
	 */
	private function render_policies(): void {
		$view = new PoliciesView( $this->settings, $this->log, $this->simulator );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below, when the form was posted.
		if ( ! isset( $_POST['agg_try'] ) ) {
			$view->render();
			return;
		}

		check_admin_referer( 'agent_governance_policies' );

		$posted = $this->posted_policy();
		$days   = Simulator::days( isset( $_POST['try_days'] ) ? absint( $_POST['try_days'] ) : 0 );
		$draft  = $this->simulator->draft( $posted );
		$result = $this->simulator->run(
			array(
				'live'  => $this->simulator->live(),
				'draft' => $draft,
			),
			$days
		);

		update_user_meta( get_current_user_id(), 'agent_governance_tried_policy', time() );

		$view->render( $draft, $result, $days );
	}

	/**
	 * The "Get started" card, until it is done or dismissed by this user.
	 */
	private function render_steps(): void {
		$user = get_current_user_id();
		if ( ! current_user_can( self::settings_capability() ) || get_user_meta( $user, Suggestions::STEPS_META, true ) ) {
			return;
		}

		$steps = array(
			array(
				'title' => __( 'Connect an agent', 'agent-governance' ),
				'text'  => __( 'An agent counts once it calls the site with an application password or through the MCP adapter. Create an application password in your profile.', 'agent-governance' ),
				'done'  => (bool) $this->log->identities(),
				'url'   => admin_url( 'profile.php#application-passwords-section' ),
				'label' => __( 'Create an application password', 'agent-governance' ),
			),
			array(
				'title' => __( 'Check the rules', 'agent-governance' ),
				'text'  => __( 'By default reads and writes run and are logged, and destructive calls wait for approval. Change that for an agent, a user or a role.', 'agent-governance' ),
				'done'  => false !== get_option( Settings::OPTION, false ) || (bool) $this->settings->rules(),
				'url'   => self::url( 'policies' ),
				'label' => __( 'Open policies', 'agent-governance' ),
			),
			array(
				'title' => __( 'Try a policy on past calls', 'agent-governance' ),
				'text'  => __( 'See what a rule would have held, allowed or refused over the last 30 days before you switch it on.', 'agent-governance' ),
				'done'  => (bool) get_user_meta( $user, 'agent_governance_tried_policy', true ),
				'url'   => self::url( 'policies' ) . '#agg-try',
				'label' => __( 'Try a policy', 'agent-governance' ),
			),
		);

		$open = array_filter(
			$steps,
			static function ( array $step ): bool {
				return ! $step['done'];
			}
		);
		if ( ! $open ) {
			return;
		}

		echo '<section class="axle-card agg-steps" aria-labelledby="agg-steps-title"><h2 class="axle-card__title" id="agg-steps-title">' . esc_html__( 'Get started', 'agent-governance' ) . '</h2><ol class="axle-steps">';
		foreach ( $steps as $step ) {
			printf(
				'<li class="axle-step"><div class="axle-step__body"><strong class="axle-step__title">%1$s</strong><p>%2$s</p></div>%3$s</li>',
				esc_html( $step['title'] ),
				esc_html( $step['text'] ),
				$step['done']
					? '<span class="axle-badge axle-badge--good">' . esc_html__( 'Done', 'agent-governance' ) . '</span>'
					: sprintf( '<a class="button" href="%1$s">%2$s</a>', esc_url( $step['url'] ), esc_html( $step['label'] ) )
			);
		}
		echo '</ol>';
		printf(
			'<form method="post" action="%1$s"><input type="hidden" name="action" value="agent_governance_dismiss_steps">%2$s<p><button type="submit" class="button-link">%3$s</button></p></form></section>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'agent_governance_dismiss_steps', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core's nonce field.
			esc_html__( 'Hide this', 'agent-governance' )
		);
	}

	/**
	 * Help tabs for the screen: an overview, and one tab for each job of the tab being shown.
	 */
	public function help(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which help to show.
		$tab = $this->tab();

		$tabs = array(
			array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'agent-governance' ),
				'content' => '<p>' . esc_html__( 'Agent Governance watches AI agents that call this site, through application passwords or the MCP adapter. It holds risky calls until a person approves them, keeps a log of every call and lets you roll back what an agent changed.', 'agent-governance' ) . '</p><p>' . esc_html__( 'Requests are the calls waiting for you. Activity is the log. Sessions group one agent\'s work. Policies are the rules.', 'agent-governance' ) . '</p>',
			),
		);

		$jobs = array(
			'requests' => array(
				array(
					'id'      => 'deciding',
					'title'   => __( 'Deciding a request', 'agent-governance' ),
					'content' => '<p>' . esc_html__( 'Open a waiting request to see what it would change. Approve and run runs it now as the user the agent signed in as. Reject tells the agent your reason. You can only decide a request about something you could change yourself.', 'agent-governance' ) . '</p>',
				),
				array(
					'id'      => 'suggestions',
					'title'   => __( 'Suggested rules', 'agent-governance' ),
					'content' => '<p>' . esc_html__( 'When you approve the same kind of call from the same agent many times and never reject it, the queue suggests an allow rule. It shows what the rule would have done over the last 30 days. Nothing is added until you accept, and you can undo it.', 'agent-governance' ) . '</p>',
				),
			),
			'activity' => array(
				array(
					'id'      => 'filtering',
					'title'   => __( 'Finding a call', 'agent-governance' ),
					'content' => '<p>' . esc_html__( 'Filter by agent, outcome or part of the ability name or route. The Why column says which rule or default decided the call.', 'agent-governance' ) . '</p>',
				),
			),
			'sessions' => array(
				array(
					'id'      => 'rollback',
					'title'   => __( 'Rolling back', 'agent-governance' ),
					'content' => '<p>' . esc_html__( 'Review a session to see the posts the agent changed. Roll back restores each one to how it was before, or moves what the agent created to the trash. Posts you are not allowed to edit are left alone.', 'agent-governance' ) . '</p>',
				),
				array(
					'id'      => 'evidence',
					'title'   => __( 'Evidence', 'agent-governance' ),
					'content' => '<p>' . esc_html__( 'Download signed evidence for a session: its calls, decisions, approvers and timings as a JSON file this site signs. The command wp agent-governance verify-evidence checks it later.', 'agent-governance' ) . '</p>',
				),
			),
			'policies' => array(
				array(
					'id'      => 'rules',
					'title'   => __( 'Rules', 'agent-governance' ),
					'content' => '<p>' . esc_html__( 'The first matching rule wins: rules for the agent first, then its user, then its role, then every agent. When no rule matches, the default for the kind of call decides.', 'agent-governance' ) . '</p>',
				),
				array(
					'id'      => 'try',
					'title'   => __( 'Trying a policy', 'agent-governance' ),
					'content' => '<p>' . esc_html__( 'Try this policy replays past calls against the form as it is now, without saving it. It shows, per agent, how many calls would be allowed, held, refused or rate limited, and how that differs from today.', 'agent-governance' ) . '</p>',
				),
			),
		);

		AxleUi::help_tabs( array_merge( $tabs, $jobs[ $tab ] ?? array() ) );
	}

	/**
	 * Adds a suggested allow rule.
	 */
	public function handle_accept_suggestion(): void {
		$key = isset( $_POST['suggestion'] ) ? sanitize_key( wp_unslash( $_POST['suggestion'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked on the next line.
		check_admin_referer( 'agent_governance_suggestion_' . $key );
		if ( ! current_user_can( self::settings_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change agent policies.', 'agent-governance' ), 403 );
		}

		$suggestion = $this->suggestions->find( $key );
		$rule       = $suggestion ? $this->suggestions->accept( $suggestion, get_current_user_id() ) : null;
		if ( ! $rule ) {
			$this->back( self::url( 'requests' ), 'error', __( 'That suggestion is no longer valid. Nothing was changed.', 'agent-governance' ) );
		}

		wp_safe_redirect( add_query_arg( array( 'agg_msg' => 'rule_added', 'agg_rule' => $rule['id'] ), self::url( 'requests' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short list.
		exit;
	}

	/**
	 * Hides a suggestion for the current user.
	 */
	public function handle_dismiss_suggestion(): void {
		$key = isset( $_POST['suggestion'] ) ? sanitize_key( wp_unslash( $_POST['suggestion'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked on the next line.
		check_admin_referer( 'agent_governance_suggestion_' . $key );
		if ( ! current_user_can( self::settings_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change agent policies.', 'agent-governance' ), 403 );
		}

		$this->suggestions->dismiss( $key, get_current_user_id() );
		$this->back( self::url( 'requests' ), 'dismissed' );
	}

	/**
	 * Takes an added rule out again.
	 */
	public function handle_undo_rule(): void {
		$id = isset( $_GET['rule'] ) ? sanitize_key( wp_unslash( $_GET['rule'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked on the next line.
		check_admin_referer( 'agent_governance_undo_rule_' . $id );
		if ( ! current_user_can( self::settings_capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change agent policies.', 'agent-governance' ), 403 );
		}

		$this->settings->delete_rule( $id );
		$this->back( self::url( 'requests' ), 'rule_gone' );
	}

	/**
	 * Hides the Get started card for the current user.
	 */
	public function handle_dismiss_steps(): void {
		check_admin_referer( 'agent_governance_dismiss_steps' );
		if ( ! current_user_can( Notifier::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see agent activity.', 'agent-governance' ), 403 );
		}

		update_user_meta( get_current_user_id(), Suggestions::STEPS_META, 1 );
		$this->back( self::url( 'requests' ), 'steps_done' );
	}

	/**
	 * Sends the signed evidence file of a session.
	 */
	public function handle_evidence(): void {
		$session = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked on the next line.
		check_admin_referer( 'agent_governance_evidence_' . $session );
		if ( ! current_user_can( Notifier::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to see agent activity.', 'agent-governance' ), 403 );
		}

		$file = $this->evidence->export( $session );
		if ( ! $file ) {
			$this->back( self::url( 'sessions' ), 'error', __( 'No calls in that session.', 'agent-governance' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="agent-session-evidence-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo Evidence::encode( $file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JSON file, not HTML.
		exit;
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
