<?php
/**
 * Listens to User Session Manager: when a person says a sign-in was not theirs,
 * or signs out everywhere, what their application passwords asked and still
 * waits for approval stops waiting.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Held requests run as the user whose application password made them. If that
 * user just reported a sign-in they did not make, or ended every session, their
 * credentials may be in somebody else's hands, so their agents' waiting requests
 * expire. The agents have to ask again, with a password that is still good.
 *
 * It hooks the plugin's actions with plain add_action, so nothing here needs
 * User Session Manager to be active.
 */
final class SessionSignals implements Service {

	/**
	 * Approvals.
	 *
	 * @var Approvals
	 */
	private Approvals $approvals;

	/**
	 * Sets up the listener.
	 *
	 * @param Approvals $approvals Approvals.
	 */
	public function __construct( Approvals $approvals ) {
		$this->approvals = $approvals;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'user_session_manager_not_me_reported', array( $this, 'not_me_reported' ), 10, 2 );
		add_action( 'user_session_manager_user_signed_out_everywhere', array( $this, 'signed_out_everywhere' ), 10, 3 );
	}

	/**
	 * A person reported a sign-in that was not theirs.
	 *
	 * @param int|object $user   User ID, or the user, as older versions of the plugin send it.
	 * @param mixed      $report What was reported. Unused.
	 */
	public function not_me_reported( $user, $report = null ): void {
		$this->expire( self::user_id( $user ), 'not_me' );
	}

	/**
	 * A user was signed out of every session.
	 *
	 * @param int|object $user    User ID.
	 * @param int        $count   Sessions ended. Unused.
	 * @param mixed      $context Who or what did it. Unused.
	 */
	public function signed_out_everywhere( $user, $count = 0, $context = '' ): void {
		$this->expire( self::user_id( $user ), 'signed_out_everywhere' );
	}

	/**
	 * Expires the user's waiting requests made with application passwords.
	 *
	 * @param int    $user_id User ID.
	 * @param string $source  not_me or signed_out_everywhere.
	 */
	private function expire( int $user_id, string $source ): void {
		/**
		 * Filters whether a report from User Session Manager expires the waiting requests
		 * that agents made with the user's application passwords.
		 *
		 * @param bool   $expire  Default true.
		 * @param int    $user_id The user.
		 * @param string $source  not_me or signed_out_everywhere.
		 */
		if ( $user_id < 1 || ! apply_filters( 'agent_governance_expire_on_session_report', true, $user_id, $source ) ) {
			return;
		}

		$reason = 'not_me' === $source
			? __( 'Expired: the user reported a sign-in that was not theirs.', 'agent-governance' )
			: __( 'Expired: the user was signed out everywhere.', 'agent-governance' );

		$expired = $this->approvals->expire_for_user( $user_id, $reason );

		if ( $expired > 0 ) {
			/**
			 * Fires after a session report expired waiting requests.
			 *
			 * @param int    $user_id The user whose application passwords made them.
			 * @param int    $expired How many.
			 * @param string $source  not_me or signed_out_everywhere.
			 */
			do_action( 'agent_governance_requests_expired_for_user', $user_id, $expired, $source );
		}
	}

	/**
	 * A user ID from what the action passes.
	 *
	 * @param mixed $user An ID, a WP_User or an array with an ID.
	 * @return int
	 */
	private static function user_id( $user ): int {
		if ( is_object( $user ) ) {
			return (int) ( $user->ID ?? 0 );
		}
		if ( is_array( $user ) ) {
			return (int) ( $user['ID'] ?? $user['user_id'] ?? 0 );
		}

		return (int) $user;
	}
}
