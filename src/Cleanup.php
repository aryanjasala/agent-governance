<?php
/**
 * The hourly clean-up.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Expires requests that waited too long, and deletes log rows and decided
 * requests older than the retention period, 1000 of each per run.
 */
final class Cleanup implements Service {

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
	 * Approvals, for expiring.
	 *
	 * @var Approvals
	 */
	private Approvals $approvals;

	/**
	 * Sets up the clean-up.
	 *
	 * @param Settings  $settings  Settings.
	 * @param Log       $log       The audit log.
	 * @param Requests  $requests  The approval queue.
	 * @param Approvals $approvals Approvals, for expiring.
	 */
	public function __construct( Settings $settings, Log $log, Requests $requests, Approvals $approvals ) {
		$this->settings  = $settings;
		$this->log       = $log;
		$this->requests  = $requests;
		$this->approvals = $approvals;
	}

	/**
	 * Hooks the schedule.
	 */
	public function register(): void {
		add_action( Schema::CRON_HOOK, array( $this, 'scheduled' ) );
	}

	/**
	 * Runs from the hourly schedule.
	 */
	public function scheduled(): void {
		$this->run();
	}

	/**
	 * Runs one clean-up.
	 *
	 * @return array{expired: int, log: int, requests: int}
	 */
	public function run(): array {
		$days   = $this->settings->retention_days();
		$result = array(
			'expired'  => $this->approvals->expire_overdue(),
			'log'      => $this->log->purge( $days ),
			'requests' => $this->requests->purge( $days ),
		);

		/**
		 * Fires after the clean-up ran.
		 *
		 * @param array $result expired (requests), log (rows deleted) and requests (rows deleted).
		 */
		do_action( 'agent_governance_cleaned_up', $result );

		return $result;
	}
}
