<?php
/**
 * A signed record of one agent session.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the log and the queue know about a session as one JSON file: the
 * calls, what was decided for each, who approved and how long it took. The file
 * carries an HMAC of its contents made with a key only this site has, so the
 * site can later say whether the file was changed. Inputs and results are left
 * out: they can hold content the file should not carry around.
 */
final class Evidence {

	public const VERSION   = 1;
	public const ALGORITHM = 'hmac-sha256';

	/**
	 * The log.
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
	 * Sets up the export.
	 *
	 * @param Log      $log      The log.
	 * @param Requests $requests The approval queue.
	 */
	public function __construct( Log $log, Requests $requests ) {
		$this->log      = $log;
		$this->requests = $requests;
	}

	/**
	 * The signed file for a session.
	 *
	 * @param string $session Session ID.
	 * @return array|null payload, algorithm and signature. Null when the session has no calls.
	 */
	public function export( string $session ): ?array {
		$rows = $this->log->session_rows( $session );
		if ( ! $rows ) {
			return null;
		}

		$requests = $this->requests->get_many( array_column( $rows, 'request_id' ) );
		$calls    = array();
		foreach ( $rows as $row ) {
			$call = array(
				'id'          => (int) $row['id'],
				'time'        => gmdate( 'c', (int) strtotime( $row['created'] . ' UTC' ) ),
				'method'      => (string) $row['method'],
				'target'      => (string) $row['target'],
				'kind'        => (string) $row['kind'],
				'decision'    => (string) $row['decision'],
				'reason'      => (string) $row['reason'],
				'result'      => (string) $row['result'],
				'duration_ms' => (int) $row['duration_ms'],
				'rolled_back' => $row['rolled_back'] ? gmdate( 'c', (int) strtotime( $row['rolled_back'] . ' UTC' ) ) : null,
				'request'     => null,
			);

			$request = $requests[ (int) $row['request_id'] ] ?? null;
			if ( $request ) {
				$asked   = (int) strtotime( $request['created'] . ' UTC' );
				$decided = $request['decided_at'] ? (int) strtotime( $request['decided_at'] . ' UTC' ) : 0;
				$user    = $request['decided_by'] ? get_userdata( (int) $request['decided_by'] ) : false;

				$call['request'] = array(
					'id'                => (int) $request['id'],
					'status'            => (string) $request['status'],
					'asked'             => gmdate( 'c', $asked ),
					'decided'           => $decided ? gmdate( 'c', $decided ) : null,
					'seconds_to_decide' => $decided ? max( 0, $decided - $asked ) : null,
					'approver'          => $user ? (string) $user->user_login : null,
					'reason'            => (string) $request['reason'],
				);
			}

			$calls[] = $call;
		}

		$first   = $rows[0];
		$payload = array(
			'version'   => self::VERSION,
			'site'      => home_url(),
			'generated' => gmdate( 'c' ),
			'session'   => $session,
			'agent'     => Log::identity_label( $first ),
			'identity'  => (string) $first['identity'],
			'calls'     => $calls,
		);

		/**
		 * Filters the contents of a session evidence file before it is signed.
		 *
		 * @param array  $payload The file: version, site, generated, session, agent, identity and calls.
		 * @param string $session Session ID.
		 */
		$payload = (array) apply_filters( 'agent_governance_evidence', $payload, $session );

		do_action( 'agent_governance_evidence_exported', $session, count( $calls ) );

		return array(
			'payload'   => $payload,
			'algorithm' => self::ALGORITHM,
			'signature' => self::sign( $payload ),
		);
	}

	/**
	 * The signature of a payload.
	 *
	 * @param array $payload The payload.
	 * @return string
	 */
	public static function sign( array $payload ): string {
		/**
		 * Filters the key evidence files are signed with.
		 *
		 * @param string $key Default derived from the site's authentication salt.
		 */
		$key = (string) apply_filters( 'agent_governance_evidence_key', wp_salt( 'auth' ) . '|agent-governance-evidence' );

		return hash_hmac( 'sha256', (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $key );
	}

	/**
	 * Whether a file was signed by this site and has not changed since.
	 *
	 * @param mixed $file The decoded file.
	 * @return bool
	 */
	public static function verify( $file ): bool {
		if ( ! is_array( $file ) || ! isset( $file['payload'], $file['signature'] ) || ! is_array( $file['payload'] ) || self::ALGORITHM !== ( $file['algorithm'] ?? '' ) ) {
			return false;
		}

		return hash_equals( self::sign( $file['payload'] ), (string) $file['signature'] );
	}

	/**
	 * The file as text, ready to download.
	 *
	 * @param array $file From export().
	 * @return string
	 */
	public static function encode( array $file ): string {
		return (string) wp_json_encode( $file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
