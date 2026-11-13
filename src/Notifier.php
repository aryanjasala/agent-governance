<?php
/**
 * Tells approvers about held requests.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

use AryanX\Axle\AgentGovernance\Admin\Page;

defined( 'ABSPATH' ) || exit;

/**
 * Tells approvers about held requests by email and webhook, gathers bursts into
 * one message, reminds them before a request expires and shows a notice in
 * wp-admin while requests wait. Each channel can be turned off on the Policies
 * screen, and each person can turn their own messages off on their profile.
 */
final class Notifier implements Service {

	public const FLUSH_HOOK      = 'agent_governance_flush_notifications';
	public const OUTBOX_OPTION   = 'agent_governance_outbox';
	public const LAST_OPTION     = 'agent_governance_last_notified';
	public const REMINDED_OPTION = 'agent_governance_reminded';

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
	 * The webhook.
	 *
	 * @var Webhook
	 */
	private Webhook $webhook;

	/**
	 * Sets up notifications.
	 *
	 * @param Settings     $settings Settings.
	 * @param Requests     $requests The approval queue.
	 * @param Webhook|null $webhook  The webhook.
	 */
	public function __construct( Settings $settings, Requests $requests, ?Webhook $webhook = null ) {
		$this->settings = $settings;
		$this->requests = $requests;
		$this->webhook  = $webhook ?? new Webhook( $settings );
	}

	/**
	 * The webhook, for the settings screen and WP-CLI.
	 *
	 * @return Webhook
	 */
	public function webhook(): Webhook {
		return $this->webhook;
	}

	/**
	 * Adds the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 90 );
		add_action( self::FLUSH_HOOK, array( $this, 'run_flush' ) );
		add_action( Schema::CRON_HOOK, array( $this, 'run_remind' ) );
		add_filter( 'heartbeat_received', array( $this, 'heartbeat' ), 10, 2 );
	}

	/**
	 * Adds the number of waiting requests to the admin bar, for approvers.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar.
	 */
	public function admin_bar( $bar ): void {
		/**
		 * Filters whether approvers see the number of waiting agent requests in the admin bar.
		 *
		 * @param bool $show Default true.
		 */
		if ( ! apply_filters( 'agent_governance_show_admin_bar', true ) || ! is_admin_bar_showing() || ! current_user_can( self::capability() ) ) {
			return;
		}

		$count = $this->requests->pending_count();
		if ( ! $count ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'agent-governance',
				/* translators: %d: number of requests. */
				'title' => esc_html( sprintf( _n( '%d agent request waiting', '%d agent requests waiting', $count, 'agent-governance' ), $count ) ),
				'href'  => Page::url( 'requests' ),
			)
		);
	}

	/**
	 * Tells the queue screen how many requests wait, so its tab title stays current.
	 *
	 * @param array $response Heartbeat response.
	 * @param array $data     What the page sent.
	 * @return array
	 */
	public function heartbeat( $response, $data = array() ): array {
		$response = (array) $response;
		if ( isset( $data['agent_governance'] ) && current_user_can( self::capability() ) ) {
			$response['agent_governance_pending'] = $this->requests->pending_count();
		}

		return $response;
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
		 * @param string $capability Default agent_governance_approve, given to administrators
		 *                           on activation. While no role has it, manage_options
		 *                           stands in (agent_governance_fallback_capability).
		 */
		$cap = (string) apply_filters( 'agent_governance_approver_capability', Schema::CAPABILITY );

		return '' !== $cap ? $cap : Schema::CAPABILITY;
	}

	/**
	 * Email addresses of the approvers who want this kind of message about a request.
	 *
	 * @param array  $request The held request.
	 * @param string $kind    held or reminder.
	 * @return string[]
	 */
	public function recipients( array $request, string $kind = 'held' ): array {
		$users = get_users(
			array(
				// While no role has the approver capability, whoever stands in for it.
				'capability' => Capability::effective(),
				'fields'     => array( 'ID', 'user_email' ),
				'number'     => 50,
			)
		);

		// Only people who could open what the call touches get its summary, and only
		// when they have not turned this kind of message off for themselves.
		$emails = array();
		foreach ( $users as $user ) {
			if ( Preferences::wants( (int) $user->ID, 'held' === $kind ? 'email' : 'reminder' ) && Review::can( $request, (int) $user->ID ) ) {
				$emails[] = (string) $user->user_email;
			}
		}

		/**
		 * Filters who is emailed when an agent call is held for approval, and when one is about to expire.
		 *
		 * The default already leaves out people who turned the email off in their
		 * profile and people who could not open what the call touches. Anyone you
		 * add here gets the summary, so check access yourself.
		 *
		 * @param string[] $emails  Default every user with the approver capability (or the
		 *                          fallback capability while no role has it), at most 50.
		 * @param array    $request The request row.
		 * @param string   $kind    held or reminder.
		 */
		$emails = (array) apply_filters( 'agent_governance_notify_recipients', $emails, $request, $kind );

		return array_values( array_unique( array_filter( array_map( 'sanitize_email', array_map( 'strval', $emails ) ), 'is_email' ) ) );
	}

	/**
	 * Tells the approvers about a held request: at once after a quiet spell, or
	 * with the others of a burst a little later.
	 *
	 * @param array $request The request row.
	 * @return bool Whether something was sent now.
	 */
	public function request_held( array $request ): bool {
		$window = $this->settings->batch_window();
		$last   = (int) get_option( self::LAST_OPTION, 0 );

		if ( $window > 0 && $last > 0 && time() - $last < $window ) {
			$queued   = array_map( 'intval', (array) get_option( self::OUTBOX_OPTION, array() ) );
			$queued[] = (int) $request['id'];
			update_option( self::OUTBOX_OPTION, array_slice( array_values( array_unique( $queued ) ), -200 ), false );

			if ( ! wp_next_scheduled( self::FLUSH_HOOK ) ) {
				wp_schedule_single_event( $last + $window, self::FLUSH_HOOK );
			}

			/**
			 * Fires when a held request waits for the next message because one just went out.
			 *
			 * @param array $request The request row.
			 * @param int   $window  Seconds requests are gathered.
			 */
			do_action( 'agent_governance_notification_queued', $request, $window );

			return false;
		}

		return $this->deliver( array( $request ), 'held' );
	}

	/**
	 * Runs flush() from its scheduled event.
	 */
	public function run_flush(): void {
		$this->flush();
	}

	/**
	 * Runs remind() from the hourly schedule.
	 */
	public function run_remind(): void {
		$this->remind();
	}

	/**
	 * Sends the requests that waited for the end of a burst.
	 *
	 * @return bool Whether something was sent.
	 */
	public function flush(): bool {
		$ids = array_map( 'intval', (array) get_option( self::OUTBOX_OPTION, array() ) );
		delete_option( self::OUTBOX_OPTION );
		if ( ! $ids ) {
			return false;
		}

		// A request decided in the meantime is no longer news.
		$waiting = array();
		foreach ( $this->requests->rows( $ids ) as $row ) {
			if ( Requests::PENDING === $row['status'] && ! Requests::is_overdue( $row ) ) {
				$waiting[] = $row;
			}
		}

		return $waiting ? $this->deliver( $waiting, 'held' ) : false;
	}

	/**
	 * Reminds approvers of requests that expire within the lead time. Each request is reminded once.
	 *
	 * @return int Requests reminded about.
	 */
	public function remind(): int {
		$lead     = $this->settings->reminder_lead();
		$reminded = (array) get_option( self::REMINDED_OPTION, array() );
		$due      = array();

		if ( $lead > 0 ) {
			foreach ( $this->requests->expiring( $lead ) as $row ) {
				if ( ! isset( $reminded[ (int) $row['id'] ] ) ) {
					$due[] = $row;
				}
			}
		}

		// Remember only requests still waiting, so the list stays short.
		$keep = array();
		foreach ( $due as $row ) {
			$keep[ (int) $row['id'] ] = time();
		}
		foreach ( $this->requests->get_many( array_map( 'intval', array_keys( $reminded ) ) ) as $row ) {
			if ( Requests::PENDING === $row['status'] ) {
				$keep[ (int) $row['id'] ] = (int) $reminded[ (int) $row['id'] ];
			}
		}
		if ( $keep !== $reminded ) {
			update_option( self::REMINDED_OPTION, $keep, false );
		}

		if ( ! $due ) {
			return 0;
		}

		$this->deliver( $due, 'reminder' );

		/**
		 * Fires after approvers were reminded about requests that expire soon.
		 *
		 * @param array[] $due The request rows.
		 */
		do_action( 'agent_governance_reminder_sent', $due );

		return count( $due );
	}

	/**
	 * Sends one email to each approver, and one webhook message, about these requests.
	 *
	 * @param array[] $requests Request rows.
	 * @param string  $kind     held or reminder.
	 * @return bool Whether anything went out.
	 */
	public function deliver( array $requests, string $kind ): bool {
		update_option( self::LAST_OPTION, time(), false );

		$sent = false;

		if ( $this->settings->get( 'email' ) ) {
			// Each person gets one email with the requests they may open.
			$by_email = array();
			foreach ( $requests as $request ) {
				foreach ( $this->recipients( $request, $kind ) as $address ) {
					$by_email[ $address ][] = $request;
				}
			}

			foreach ( $by_email as $address => $rows ) {
				$sent = $this->email( (string) $address, $rows, $kind ) || $sent;
			}
		}

		if ( $this->webhook->configured() ) {
			$sent = $this->webhook->send( $requests, $kind ) || $sent;
		}

		/**
		 * Fires after approvers were told about waiting requests.
		 *
		 * @param array[] $requests Request rows.
		 * @param string  $kind     held or reminder.
		 * @param bool    $sent     Whether any email or webhook message went out.
		 */
		do_action( 'agent_governance_approvers_notified', $requests, $kind, $sent );

		return $sent;
	}

	/**
	 * Sends the email for one person.
	 *
	 * @param string  $address  Email address.
	 * @param array[] $requests Requests they may open.
	 * @param string  $kind     held or reminder.
	 * @return bool
	 */
	private function email( string $address, array $requests, string $kind ): bool {
		$request = $requests[0];
		$count   = count( $requests );
		$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		if ( 1 === $count && 'held' === $kind ) {
			$subject = sprintf(
				/* translators: 1: site name, 2: request ID. */
				__( '[%1$s] Agent request #%2$d needs approval', 'agent-governance' ),
				$site,
				(int) $request['id']
			);
			/* translators: %s: agent name. */
			$lines = array( sprintf( __( 'An AI agent is waiting for your approval: %s.', 'agent-governance' ), Log::identity_label( $request ) ), '' );
		} elseif ( 'held' === $kind ) {
			/* translators: 1: site name, 2: number of requests. */
			$subject = sprintf( __( '[%1$s] %2$d agent requests need approval', 'agent-governance' ), $site, $count );
			/* translators: %d: number of requests. */
			$lines = array( sprintf( __( 'AI agents are waiting for your approval: %d requests.', 'agent-governance' ), $count ), '' );
		} else {
			/* translators: 1: site name, 2: number of requests. */
			$subject = sprintf( _n( '[%1$s] Reminder: %2$d agent request expires soon', '[%1$s] Reminder: %2$d agent requests expire soon', $count, 'agent-governance' ), $site, $count );
			/* translators: %d: number of requests. */
			$lines = array( sprintf( _n( '%d agent request has waited a long time and will expire if nobody decides it.', '%d agent requests have waited a long time and will expire if nobody decides them.', $count, 'agent-governance' ), $count ), '' );
		}

		foreach ( $requests as $row ) {
			$preview = json_decode( (string) $row['dry_run'], true );
			if ( $count > 1 || 'held' !== $kind ) {
				/* translators: 1: request ID, 2: agent name. */
				$lines[] = sprintf( __( 'Request #%1$d from %2$s', 'agent-governance' ), (int) $row['id'], Log::identity_label( $row ) );
			}
			/* translators: 1: method, 2: ability name or REST route. */
			$lines[] = sprintf( __( 'Call: %1$s %2$s', 'agent-governance' ), (string) $row['method'], (string) $row['target'] );
			if ( is_array( $preview ) && ! empty( $preview['summary'] ) ) {
				/* translators: %s: dry-run summary. */
				$lines[] = sprintf( __( 'What it would do: %s', 'agent-governance' ), (string) $preview['summary'] );
			}
			/* translators: %s: link to the request. */
			$lines[] = sprintf( __( 'Review it: %s', 'agent-governance' ), Page::request_url( (int) $row['id'] ) );
			/* translators: %s: how long until it expires, such as "1 day". */
			$lines[] = sprintf( __( 'It expires in %s if nobody decides.', 'agent-governance' ), human_time_diff( time(), Requests::expires_at( $row ) ) );
			$lines[] = '';
		}
		$lines[] = __( 'The link opens wp-admin. You sign in and confirm there. Nothing is approved from this email.', 'agent-governance' );

		$email = array(
			'to'      => array( $address ),
			'subject' => $subject,
			'message' => implode( "\n", $lines ),
			'headers' => array(),
		);

		/**
		 * Filters the email approvers get about held requests.
		 *
		 * @param array|false $email    to, subject, message and headers. False skips it.
		 * @param array       $request  The first request row.
		 * @param array[]     $requests Every request in the email: one, or a burst.
		 * @param string      $kind     held or reminder.
		 */
		$email = apply_filters( 'agent_governance_notification_email', $email, $request, $requests, $kind );
		if ( ! is_array( $email ) || empty( $email['to'] ) ) {
			return false;
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- One email per approver, for the requests they may open.
		return (bool) wp_mail( $email['to'], (string) ( $email['subject'] ?? '' ), (string) ( $email['message'] ?? '' ), $email['headers'] ?? array() );
	}

	/**
	 * Shows approvers how many requests wait, on every admin screen but ours.
	 */
	public function notice(): void {
		if ( ! $this->settings->get( 'notice' ) || ! current_user_can( self::capability() ) || ! Preferences::wants( get_current_user_id(), 'notice' ) || Page::is_current() ) {
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
