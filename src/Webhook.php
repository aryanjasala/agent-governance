<?php
/**
 * Posts waiting requests to a webhook, as JSON or in Slack's format.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

use AryanX\Axle\AgentGovernance\Admin\Page;

defined( 'ABSPATH' ) || exit;

/**
 * One site-wide address for a chat channel or an automation. The message holds
 * what the agent asked for and the dry-run summary with secrets removed, and a
 * link to the request in wp-admin. A webhook never carries a way to decide:
 * approving needs a sign-in and a confirm in wp-admin.
 *
 * A channel has no person to check, so details are only included for objects
 * the user who saved the webhook could open themselves. Other requests go out
 * as a bare notice that one is waiting.
 */
final class Webhook {

	public const STATUS_OPTION = 'agent_governance_webhook_status';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up the webhook.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The address, or an empty string when none is set.
	 *
	 * @return string
	 */
	public function url(): string {
		/**
		 * Filters the webhook address, for example to read it from a constant or a secrets store.
		 *
		 * @param string $url Default the address saved on the Policies screen.
		 */
		return Settings::clean_webhook_url( apply_filters( 'agent_governance_webhook_url', (string) $this->settings->get( 'webhook_url' ) ) );
	}

	/**
	 * Whether a webhook is set.
	 *
	 * @return bool
	 */
	public function configured(): bool {
		return '' !== $this->url();
	}

	/**
	 * The request rows as the message shows them, with secrets removed.
	 *
	 * @param array[] $requests Request rows.
	 * @return array[]
	 */
	public function describe( array $requests ): array {
		$viewer = (int) $this->settings->get( 'webhook_by' );
		$items  = array();

		foreach ( $requests as $request ) {
			$item = array(
				'id'         => (int) $request['id'],
				'agent'      => Log::identity_label( $request ),
				'kind'       => (string) $request['kind'],
				'review_url' => Page::request_url( (int) $request['id'] ),
				'expires_at' => gmdate( 'c', Requests::expires_at( $request ) ),
			);

			// Details only when the person who set the webhook up could open the object.
			if ( $viewer > 0 && user_can( $viewer, Capability::effective() ) && Review::can( $request, $viewer ) ) {
				$preview         = json_decode( (string) ( $request['dry_run'] ?? '' ), true );
				$preview         = is_array( $preview ) ? $preview : array();
				$item['method']  = (string) $request['method'];
				$item['target']  = (string) $request['target'];
				$item['summary'] = (string) ( $preview['summary'] ?? '' );
				$item['changes'] = self::changes( $preview );
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * The first changes of a preview, short, with secret values hidden.
	 *
	 * @param array $preview The dry-run preview.
	 * @return array[] field, before and after.
	 */
	private static function changes( array $preview ): array {
		$keys    = Redactor::keys();
		$changes = array();

		foreach ( array_slice( (array) ( $preview['changes'] ?? array() ), 0, 5 ) as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}

			$field  = (string) ( $change['field'] ?? '' );
			$secret = Redactor::matches( $field, $keys );
			$cut    = static function ( $value ) use ( $secret ): string {
				$value = is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( Redactor::redact( $value ) );
				if ( $secret && '' !== $value ) {
					return Redactor::MASK;
				}

				return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $value, 0, 120, '...' ) : substr( $value, 0, 120 );
			};

			$changes[] = array(
				'field'  => $field,
				'before' => $cut( $change['before'] ?? '' ),
				'after'  => $cut( $change['after'] ?? '' ),
			);
		}

		return $changes;
	}

	/**
	 * The message body.
	 *
	 * @param array[] $requests Request rows.
	 * @param string  $kind     held or reminder.
	 * @return array
	 */
	public function payload( array $requests, string $kind ): array {
		$items = $this->describe( $requests );
		$site  = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );

		if ( 'slack' === $this->settings->get( 'webhook_format' ) ) {
			$payload = self::slack( $items, $kind, $site );
		} else {
			$payload = array(
				'event'    => 'held' === $kind ? 'requests_held' : 'requests_expiring',
				'site'     => $site,
				'site_url' => home_url(),
				'queue'    => Page::url( 'requests' ),
				'count'    => count( $items ),
				'requests' => $items,
			);
		}

		/**
		 * Filters what is posted to the webhook.
		 *
		 * @param array   $payload  The body, before it is encoded as JSON.
		 * @param array[] $requests Request rows.
		 * @param string  $kind     held or reminder.
		 */
		return (array) apply_filters( 'agent_governance_webhook_payload', $payload, $requests, $kind );
	}

	/**
	 * A message Slack, and the services that copy its format, can post.
	 *
	 * @param array[] $items Described requests.
	 * @param string  $kind  held or reminder.
	 * @param string  $site  Site name.
	 * @return array
	 */
	private static function slack( array $items, string $kind, string $site ): array {
		$count = count( $items );
		$title = 'held' === $kind
			/* translators: 1: site name, 2: number of requests. */
			? sprintf( _n( '[%1$s] %2$d agent request needs approval', '[%1$s] %2$d agent requests need approval', $count, 'agent-governance' ), $site, $count )
			/* translators: 1: site name, 2: number of requests. */
			: sprintf( _n( '[%1$s] %2$d agent request expires soon', '[%1$s] %2$d agent requests expire soon', $count, 'agent-governance' ), $site, $count );

		$blocks = array(
			array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => '*' . self::mrkdwn( $title ) . '*',
				),
			),
		);

		foreach ( array_slice( $items, 0, 10 ) as $item ) {
			$lines = array( sprintf( '*#%1$d* %2$s', $item['id'], self::mrkdwn( $item['agent'] ) ) );
			if ( isset( $item['target'] ) ) {
				$lines[] = '`' . self::mrkdwn( trim( $item['method'] . ' ' . $item['target'] ) ) . '`';
				if ( '' !== $item['summary'] ) {
					$lines[] = self::mrkdwn( $item['summary'] );
				}
				foreach ( $item['changes'] as $change ) {
					$lines[] = sprintf( '- %1$s: %2$s -> %3$s', self::mrkdwn( $change['field'] ), self::mrkdwn( $change['before'] ), self::mrkdwn( $change['after'] ) );
				}
			}
			$lines[] = '<' . $item['review_url'] . '|' . self::mrkdwn( __( 'Open in wp-admin', 'agent-governance' ) ) . '>';

			$blocks[] = array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => implode( "\n", $lines ),
				),
			);
		}

		return array(
			'text'   => $title,
			'blocks' => $blocks,
		);
	}

	/**
	 * Escapes the three characters Slack treats as markup.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function mrkdwn( string $text ): string {
		return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
	}

	/**
	 * Posts a message. Records how it went.
	 *
	 * @param array[] $requests Request rows.
	 * @param string  $kind     held or reminder.
	 * @return bool Whether the receiver accepted it.
	 */
	public function send( array $requests, string $kind ): bool {
		$url = $this->url();
		if ( '' === $url || ! $requests ) {
			return false;
		}

		$args = array(
			'timeout'     => 3,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'        => (string) wp_json_encode( $this->payload( $requests, $kind ) ),
		);

		/**
		 * Filters the arguments of the webhook request.
		 *
		 * The address is checked with wp_safe_remote_post(), so it cannot point at a
		 * private network address.
		 *
		 * @param array  $args wp_remote_post() arguments.
		 * @param string $kind held or reminder.
		 */
		$args = (array) apply_filters( 'agent_governance_webhook_args', $args, $kind );

		$response = wp_safe_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			$ok      = false;
			$message = $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$ok   = $code >= 200 && $code < 300;
			/* translators: %d: HTTP status code. */
			$message = $ok ? '' : sprintf( __( 'The receiver answered HTTP %d.', 'agent-governance' ), $code );
		}

		update_option(
			self::STATUS_OPTION,
			array(
				'time'    => time(),
				'ok'      => $ok,
				'message' => $message,
				'count'   => count( $requests ),
			),
			false
		);

		/**
		 * Fires after a webhook message was sent.
		 *
		 * @param bool    $ok       Whether the receiver accepted it.
		 * @param array[] $requests Request rows.
		 * @param string  $kind     held or reminder.
		 */
		do_action( 'agent_governance_webhook_sent', $ok, $requests, $kind );

		return $ok;
	}

	/**
	 * Posts a sample message that says nothing is waiting.
	 *
	 * @return bool Whether the receiver accepted it.
	 */
	public function test(): bool {
		$sample = array(
			'id'       => 0,
			'created'  => gmdate( 'Y-m-d H:i:s' ),
			'identity' => 'test',
			'agent'    => __( 'Test message', 'agent-governance' ),
			'user_id'  => 0,
			'source'   => 'ability',
			'target'   => 'agent-governance/test',
			'method'   => 'POST',
			'kind'     => 'write',
			'status'   => Requests::PENDING,
			'dry_run'  => (string) wp_json_encode(
				array(
					'supported' => true,
					'summary'   => __( 'This is a test. Nothing is waiting for approval.', 'agent-governance' ),
					'changes'   => array(),
				)
			),
		);

		return $this->send( array( $sample ), 'held' );
	}

	/**
	 * How the last message went.
	 *
	 * @return array{time: int, ok: bool, message: string, count: int}|null Null before the first one.
	 */
	public static function status(): ?array {
		$status = get_option( self::STATUS_OPTION );
		if ( ! is_array( $status ) || empty( $status['time'] ) ) {
			return null;
		}

		return array(
			'time'    => (int) $status['time'],
			'ok'      => ! empty( $status['ok'] ),
			'message' => (string) ( $status['message'] ?? '' ),
			'count'   => (int) ( $status['count'] ?? 0 ),
		);
	}
}
