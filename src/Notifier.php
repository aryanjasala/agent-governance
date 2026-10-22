<?php
/**
 * Tells approvers about held requests.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance;

use AryanX\AgentGovernance\Admin\Page;

defined( 'ABSPATH' ) || exit;

/**
 * Emails approvers when a call is held, and shows them a notice in wp-admin
 * while requests wait. Both can be turned off on the Policies screen.
 */
final class Notifier implements Service {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The approval queue.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * Sets up notifications.
	 *
	 * @param Settings $settings Settings.
	 * @param Requests $requests The approval queue.
	 */
	public function __construct( Settings $settings, Requests $requests ) {
		$this->settings = $settings;
		$this->requests = $requests;
	}

	/**
	 * Adds the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * The approver capability.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability that may approve, reject and roll back agent calls
		 * and see the activity log.
		 *
		 * @param string $capability Default agent_governance_approve, given to administrators on activation.
		 */
		$cap = (string) apply_filters( 'agent_governance_approver_capability', Schema::CAPABILITY );

		return '' !== $cap ? $cap : Schema::CAPABILITY;
	}

	/**
	 * Email addresses of the approvers.
	 *
	 * @param array $request The held request.
	 * @return string[]
	 */
	public function recipients( array $request ): array {
		$users = get_users(
			array(
				'capability' => self::capability(),
				'fields'     => array( 'user_email' ),
				'number'     => 50,
			)
		);

		$emails = array();
		foreach ( $users as $user ) {
			$emails[] = (string) $user->user_email;
		}

		/**
		 * Filters who is emailed when an agent call is held for approval.
		 *
		 * @param string[] $emails  Default every user with the approver capability, at most 50.
		 * @param array    $request The request row.
		 */
		$emails = (array) apply_filters( 'agent_governance_notify_recipients', $emails, $request );

		return array_values( array_unique( array_filter( array_map( 'sanitize_email', array_map( 'strval', $emails ) ), 'is_email' ) ) );
	}

	/**
	 * Emails the approvers about a held request.
	 *
	 * @param array $request The request row.
	 * @return bool Whether an email was sent.
	 */
	public function request_held( array $request ): bool {
		if ( ! $this->settings->get( 'email' ) ) {
			return false;
		}

		$to = $this->recipients( $request );
		if ( ! $to ) {
			return false;
		}

		$preview = json_decode( (string) $request['dry_run'], true );
		$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$lines   = array(
			/* translators: %s: agent name. */
			sprintf( __( 'An AI agent is waiting for your approval: %s.', 'agent-governance' ), Log::identity_label( $request ) ),
			'',
			/* translators: 1: method, 2: ability name or REST route. */
			sprintf( __( 'Call: %1$s %2$s', 'agent-governance' ), (string) $request['method'], (string) $request['target'] ),
		);
		if ( is_array( $preview ) && ! empty( $preview['summary'] ) ) {
			/* translators: %s: dry-run summary. */
			$lines[] = sprintf( __( 'What it would do: %s', 'agent-governance' ), (string) $preview['summary'] );
		}
		$lines[] = '';
		/* translators: %s: link to the request. */
		$lines[] = sprintf( __( 'Review it: %s', 'agent-governance' ), Page::request_url( (int) $request['id'] ) );
		/* translators: %s: how long until it expires, such as "1 day". */
		$lines[] = sprintf( __( 'It expires in %s if nobody decides.', 'agent-governance' ), human_time_diff( time(), Requests::expires_at( $request ) ) );

		$email = array(
			'to'      => $to,
			/* translators: 1: site name, 2: request ID. */
			'subject' => sprintf( __( '[%1$s] Agent request #%2$d needs approval', 'agent-governance' ), $site, (int) $request['id'] ),
			'message' => implode( "\n", $lines ),
			'headers' => array(),
		);

		/**
		 * Filters the email approvers get about a held request.
		 *
		 * @param array|false $email   to, subject, message and headers. False skips it.
		 * @param array       $request The request row.
		 */
		$email = apply_filters( 'agent_governance_notification_email', $email, $request );
		if ( ! is_array( $email ) || empty( $email['to'] ) ) {
			return false;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One email per held request, to the approvers.
		return (bool) wp_mail( $email['to'], (string) ( $email['subject'] ?? '' ), (string) ( $email['message'] ?? '' ), $email['headers'] ?? array() );
	}

	/**
	 * Shows approvers how many requests wait, on every admin screen but ours.
	 */
	public function notice(): void {
		if ( ! $this->settings->get( 'notice' ) || ! current_user_can( self::capability() ) || Page::is_current() ) {
			return;
		}

		$count = $this->requests->pending_count();
		if ( ! $count ) {
			return;
		}

		$message = sprintf(
			/* translators: %d: number of requests. */
			_n( '%d AI agent request is waiting for your approval.', '%d AI agent requests are waiting for your approval.', $count, 'agent-governance' ),
			$count
		);

		/**
		 * Filters the admin notice about waiting agent requests.
		 *
		 * @param string $message Message. Plain text.
		 * @param int    $count   Requests waiting.
		 */
		$message = (string) apply_filters( 'agent_governance_notice_message', $message, $count );

		printf(
			'<div class="notice notice-warning agg-notice"><p>%s <a href="%s">%s</a></p></div>',
			esc_html( $message ),
			esc_url( Page::url( 'requests' ) ),
			esc_html__( 'Review them', 'agent-governance' )
		);
	}
}
