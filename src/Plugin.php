<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance;

use AryanX\AgentGovernance\Admin\Page;
use AryanX\AgentGovernance\Cli\Command;
use AryanX\AgentGovernance\Guards\AbilityGuard;
use AryanX\AgentGovernance\Guards\RestGuard;
use AryanX\AgentGovernance\Health\SiteHealth;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings and rules.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Request signals.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * Agent detection.
	 *
	 * @var Classifier
	 */
	private Classifier $classifier;

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
	 * The policy engine.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Tells approvers.
	 *
	 * @var Notifier
	 */
	private Notifier $notifier;

	/**
	 * Applies the policy.
	 *
	 * @var Gate
	 */
	private Gate $gate;

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
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		if ( ! Requirements::met() ) {
			Requirements::fail();
			return;
		}

		self::$instance = new self();
		self::$instance->register();
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings   = new Settings();
		$this->context    = new Context();
		$this->classifier = new Classifier( $this->context );
		$this->log        = new Log();
		$this->requests   = new Requests();
		$this->policy     = new Policy( $this->settings, $this->log );
		$this->notifier   = new Notifier( $this->settings, $this->requests );
		$this->gate       = new Gate( $this->settings, $this->context, $this->classifier, $this->policy, $this->log, $this->requests, new DryRun(), $this->notifier );
		$this->approvals  = new Approvals( $this->requests, $this->context );
		$this->rollback   = new Rollback( $this->log );
	}

	/**
	 * Settings and rules, for other plugins.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * The approval queue, for other plugins.
	 *
	 * @return Requests
	 */
	public function requests(): Requests {
		return $this->requests;
	}

	/**
	 * Approvals, for other plugins.
	 *
	 * @return Approvals
	 */
	public function approvals(): Approvals {
		return $this->approvals;
	}

	/**
	 * The audit log, for other plugins.
	 *
	 * @return Log
	 */
	public function log(): Log {
		return $this->log;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		Schema::maybe_upgrade();

		$services = array(
			$this->context,
			new Changes( $this->context ),
			new AbilityGuard( $this->gate, $this->context ),
			new RestGuard( $this->gate ),
			$this->notifier,
			new Cleanup( $this->settings, $this->log, $this->requests, $this->approvals ),
			new Privacy( $this->settings, $this->log, $this->requests ),
			new Abilities( $this->requests, $this->approvals, $this->classifier ),
			new Page( $this->settings, $this->log, $this->requests, $this->approvals, $this->rollback ),
			new SiteHealth( $this->settings, $this->requests ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		// A REST route that ends the request with exit still gets its log row.
		add_action( 'shutdown', array( $this->gate, 'close_open_frames' ), 0 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'agent-governance', new Command( $this->settings, $this->log, $this->requests, $this->approvals, $this->rollback ) );
		}
	}
}
