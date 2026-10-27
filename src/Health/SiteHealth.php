<?php
/**
 * Site Health check.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Health;

use AryanX\Axle\AgentGovernance\Admin\Page;
use AryanX\Axle\AgentGovernance\Capability;
use AryanX\Axle\AgentGovernance\Notifier;
use AryanX\Axle\AgentGovernance\Requests;
use AryanX\Axle\AgentGovernance\Schema;
use AryanX\Axle\AgentGovernance\Service;
use AryanX\Axle\AgentGovernance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds an Agent Governance test to Tools > Site Health.
 *
 * Critical when the tables are missing, since every agent call then goes
 * unrecorded, and when no role has the approver capability. Recommended when
 * no user can approve held calls, when calls have waited over an hour, or when
 * agent writes are paused.
 */
final class SiteHealth implements Service {

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
	 * Sets up the check.
	 *
	 * @param Settings $settings Settings.
	 * @param Requests $requests The approval queue.
	 */
	public function __construct( Settings $settings, Requests $requests ) {
		$this->settings = $settings;
		$this->requests = $requests;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_test' ) );
	}

	/**
	 * Adds the test to the direct tests.
	 *
	 * @param array $tests Site Health tests.
	 * @return array
	 */
	public function add_test( $tests ): array {
		$tests                               = (array) $tests;
		$tests['direct']['agent_governance'] = array(
			'label' => __( 'Agent Governance', 'agent-governance' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * Runs the test.
	 *
	 * @return array Site Health result.
	 */
	public function run(): array {
		$actions = sprintf( '<p><a href="%s">%s</a></p>', esc_url( Page::url( 'requests' ) ), esc_html__( 'Open Agent Governance', 'agent-governance' ) );

		if ( ! Schema::tables_exist() ) {
			return self::result( 'critical', __( 'Agent calls are not being recorded', 'agent-governance' ), __( 'The Agent Governance tables are missing, so agent calls cannot be logged or held for approval. Deactivate and activate the plugin to create them.', 'agent-governance' ), $actions );
		}

		if ( ! Capability::granted() ) {
			return self::result(
				'critical',
				__( 'No role can approve agent calls', 'agent-governance' ),
				sprintf(
					/* translators: 1: capability name, 2: fallback capability name. */
					__( 'No role has the %1$s capability any more, for example because a role clean-up removed it. Until a role has it again, anyone with the %2$s capability approves held agent calls. Give it back from the notice in wp-admin or with wp agent-governance restore-capability.', 'agent-governance' ),
					Notifier::capability(),
					Capability::fallback()
				),
				$actions
			);
		}

		$approvers = get_users(
			array(
				'capability' => Notifier::capability(),
				'fields'     => 'ID',
				'number'     => 1,
			)
		);
		if ( ! $approvers ) {
			return self::result(
				'recommended',
				__( 'Nobody can approve agent calls', 'agent-governance' ),
				/* translators: %s: capability name. */
				sprintf( __( 'No user has the capability to approve held agent calls, so they wait until they expire. Give a user a role with the %s capability.', 'agent-governance' ), Notifier::capability() ),
				$actions
			);
		}

		$oldest = $this->requests->oldest_pending();
		if ( $oldest && time() - $oldest > HOUR_IN_SECONDS ) {
			return self::result(
				'recommended',
				__( 'Agent calls are waiting for approval', 'agent-governance' ),
				/* translators: %s: time span. */
				sprintf( __( 'An agent call has waited %s for someone to approve or reject it. Agents keep waiting until it is decided or expires.', 'agent-governance' ), human_time_diff( $oldest, time() ) ),
				$actions
			);
		}

		if ( $this->settings->is_paused() ) {
			return self::result( 'recommended', __( 'Agent writes are paused', 'agent-governance' ), __( 'Every write by an AI agent is refused until someone resumes them. Reading still works.', 'agent-governance' ), $actions );
		}

		return self::result( 'good', __( 'AI agents are governed', 'agent-governance' ), __( 'Agent calls are logged, and risky ones wait for approval.', 'agent-governance' ), $actions );
	}

	/**
	 * A Site Health result.
	 *
	 * @param string $status      good, recommended or critical.
	 * @param string $label       Title.
	 * @param string $description Text.
	 * @param string $actions     HTML links.
	 * @return array
	 */
	private static function result( string $status, string $label, string $description, string $actions ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'agent-governance' ),
				'color' => 'critical' === $status ? 'red' : 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => 'agent_governance',
		);
	}
}
