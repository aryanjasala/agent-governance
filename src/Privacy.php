<?php
/**
 * Personal data export, erase and the privacy policy text.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * The log and the queue hold user IDs, IP addresses and what agents sent.
 *
 * The exporter lists a user's agent calls and requests. The eraser removes the
 * user, IP address and input from them but keeps the record that a call
 * happened, and rejects the user's pending requests, which cannot run without
 * their input.
 */
final class Privacy implements Service {

	public const ID   = 'agent-governance';
	private const PER = 100;

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
	 * Sets up the privacy tools.
	 *
	 * @param Settings $settings Settings.
	 * @param Log      $log      The audit log.
	 * @param Requests $requests The approval queue.
	 */
	public function __construct( Settings $settings, Log $log, Requests $requests ) {
		$this->settings = $settings;
		$this->log      = $log;
		$this->requests = $requests;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ): array {
		$exporters             = (array) $exporters;
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'AI agent activity', 'agent-governance' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers             = (array) $erasers;
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'AI agent activity', 'agent-governance' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports one page of a user's agent calls and requests.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$page = max( 1, (int) $page );
		$data = array();
		$done = true;

		if ( $user ) {
			$calls    = $this->log->for_user( (int) $user->ID, $page, self::PER );
			$requests = $this->requests->for_user( (int) $user->ID, $page, self::PER );
			$done     = count( $calls ) < self::PER && count( $requests ) < self::PER;

			foreach ( $calls as $row ) {
				$data[] = array(
					'group_id'    => 'agent-governance-calls',
					'group_label' => __( 'AI agent calls', 'agent-governance' ),
					'item_id'     => 'agent-governance-call-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Time (UTC)', 'agent-governance' ),
							'value' => (string) $row['created'],
						),
						array(
							'name'  => __( 'Agent', 'agent-governance' ),
							'value' => (string) $row['agent'],
						),
						array(
							'name'  => __( 'Call', 'agent-governance' ),
							'value' => trim( $row['method'] . ' ' . $row['target'] ),
						),
						array(
							'name'  => __( 'Outcome', 'agent-governance' ),
							'value' => Admin\Display::outcome( (string) $row['decision'] ),
						),
						array(
							'name'  => __( 'IP address', 'agent-governance' ),
							'value' => (string) $row['ip'],
						),
						array(
							'name'  => __( 'Input', 'agent-governance' ),
							'value' => (string) $row['input'],
						),
					),
				);
			}

			foreach ( $requests as $row ) {
				$data[] = array(
					'group_id'    => 'agent-governance-requests',
					'group_label' => __( 'AI agent requests held for approval', 'agent-governance' ),
					'item_id'     => 'agent-governance-request-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Time (UTC)', 'agent-governance' ),
							'value' => (string) $row['created'],
						),
						array(
							'name'  => __( 'Call', 'agent-governance' ),
							'value' => trim( $row['method'] . ' ' . $row['target'] ),
						),
						array(
							'name'  => __( 'Status', 'agent-governance' ),
							'value' => Requests::status_label( (string) $row['status'] ),
						),
						array(
							'name'  => __( 'Input', 'agent-governance' ),
							'value' => Redactor::encode( json_decode( (string) $row['input'], true ), 'input' ),
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * Removes a user's personal data from the log and the queue, a batch at a time.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1. Each run takes the next batch.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$calls    = $this->log->anonymize_user( (int) $user->ID, 500 );
		$requests = $this->requests->anonymize_user( (int) $user->ID, 500 );
		$changed  = $calls + $requests;

		return array(
			'items_removed'  => $changed > 0,
			'items_retained' => $changed > 0,
			'messages'       => $changed > 0 ? array( __( 'AI agent calls were kept without the user, IP address and input, as a record that they happened.', 'agent-governance' ) ) : array(),
			'done'           => $calls < 500 && $requests < 500,
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = sprintf(
			/* translators: %d: number of days. */
			__( 'When an AI agent works on this site on your behalf, through an application password or the MCP adapter, we keep a record of each call: when it happened, which agent and account made it, the IP address it came from, what it asked for (with passwords and keys removed) and whether it was allowed. Calls that need approval are kept with their full input until an administrator decides, then with secrets removed. These records are deleted after %d days.', 'agent-governance' ),
			$this->settings->retention_days()
		);

		wp_add_privacy_policy_content( __( 'Agent Governance', 'agent-governance' ), '<p>' . esc_html( $text ) . '</p>' );
	}
}
