<?php
/**
 * Abilities: let agents follow their held requests, and approvers decide them.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Agent Governance's abilities.
 *
 * An agent whose call was held polls get-request for the outcome. Approvers can
 * list and decide requests, but never through an application password or MCP:
 * an agent must not approve its own request, or another agent's.
 */
final class Abilities implements Service {

	public const CATEGORY = 'agent-governance';

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
	 * Agent detection.
	 *
	 * @var Classifier
	 */
	private Classifier $classifier;

	/**
	 * Sets up the abilities.
	 *
	 * @param Requests   $requests   The approval queue.
	 * @param Approvals  $approvals  Approvals.
	 * @param Classifier $classifier Agent detection.
	 */
	public function __construct( Requests $requests, Approvals $approvals, Classifier $classifier ) {
		$this->requests   = $requests;
		$this->approvals  = $approvals;
		$this->classifier = $classifier;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Agent Governance', 'agent-governance' ),
				'description' => __( 'Follow agent calls held for human approval, and decide them.', 'agent-governance' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$request_schema = self::request_schema();
		$logged_in      = static function (): bool {
			return is_user_logged_in();
		};
		$approver       = static function (): bool {
			return current_user_can( Notifier::capability() );
		};

		$this->ability(
			'get-request',
			__( 'Get a held request', 'agent-governance' ),
			__( 'The status of a call that was held for approval: pending, approved, rejected (with the reason), expired, executed (with the result) or failed. Poll this after a call returns agent_governance_pending, every 30 seconds or so.', 'agent-governance' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Request ID from the agent_governance_pending error.', 'agent-governance' ),
					),
				),
				'required'             => array( 'id' ),
				'additionalProperties' => false,
			),
			$request_schema,
			array( $this, 'get_request' ),
			array( $this, 'can_see_request' ),
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-my-requests',
			__( 'List my held requests', 'agent-governance' ),
			__( 'Calls of this agent (its application password, or else its user) that were held for approval, newest first.', 'agent-governance' ),
			self::list_input( true ),
			array(
				'type'  => 'array',
				'items' => $request_schema,
			),
			array( $this, 'list_my_requests' ),
			$logged_in,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-pending',
			__( 'List requests waiting for approval', 'agent-governance' ),
			__( 'Every agent call waiting for a person to approve or reject it, newest first, with what it would change.', 'agent-governance' ),
			self::list_input( false ),
			array(
				'type'  => 'array',
				'items' => $request_schema,
			),
			array( $this, 'list_pending' ),
			$approver,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'decide-request',
			__( 'Approve or reject a held request', 'agent-governance' ),
			__( 'Approves (and runs) or rejects a held call. Only for approvers signed in as themselves: calls through an application password or MCP are always refused, so an agent cannot approve its own request.', 'agent-governance' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'id'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'decision' => array(
						'type' => 'string',
						'enum' => array( 'approve', 'reject' ),
					),
					'reason'   => array(
						'type'        => 'string',
						'maxLength'   => 1000,
						'description' => __( 'Why it was rejected. The agent sees it.', 'agent-governance' ),
					),
				),
				'required'             => array( 'id', 'decision' ),
				'additionalProperties' => false,
			),
			$request_schema,
			array( $this, 'decide_request' ),
			$approver,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);
	}

	/**
	 * Permission for get-request: the user who made it, or an approver.
	 *
	 * @param array|null $input Ability input.
	 * @return bool
	 */
	public function can_see_request( $input = null ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( current_user_can( Notifier::capability() ) ) {
			return true;
		}

		$request = $this->requests->get( (int) ( ( (array) $input )['id'] ?? 0 ) );

		return ! $request || get_current_user_id() === (int) $request['user_id'];
	}

	/**
	 * Ability: one request.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function get_request( $input = null ) {
		$request = $this->requests->get( (int) ( ( (array) $input )['id'] ?? 0 ) );
		if ( ! $request || ( get_current_user_id() !== (int) $request['user_id'] && ! current_user_can( Notifier::capability() ) ) ) {
			return new \WP_Error( 'agent_governance_not_found', __( 'No request with that ID.', 'agent-governance' ), array( 'status' => 404 ) );
		}

		if ( Requests::is_overdue( $request ) && $this->approvals->expire( $request ) ) {
			$request = (array) $this->requests->get( (int) $request['id'] );
		}

		return self::present( $request );
	}

	/**
	 * Ability: the calling agent's requests.
	 *
	 * @param array|null $input Ability input.
	 * @return array[]
	 */
	public function list_my_requests( $input = null ): array {
		$input    = (array) $input;
		$identity = $this->classifier->current();
		$args     = array(
			'status' => isset( $input['status'] ) ? (string) $input['status'] : '',
			'limit'  => (int) ( $input['limit'] ?? 20 ),
		);

		if ( $identity && 0 === strpos( (string) $identity['key'], 'app:' ) ) {
			$args['identity'] = (string) $identity['key'];
		}
		$args['user_id'] = get_current_user_id();

		return array_map( array( self::class, 'present' ), $this->requests->query( $args )['rows'] );
	}

	/**
	 * Ability: requests waiting for approval.
	 *
	 * @param array|null $input Ability input.
	 * @return array[]
	 */
	public function list_pending( $input = null ): array {
		$rows = $this->requests->query(
			array(
				'status' => Requests::PENDING,
				'limit'  => (int) ( ( (array) $input )['limit'] ?? 20 ),
			)
		)['rows'];

		$rows = array_filter(
			$rows,
			static function ( array $row ): bool {
				return ! Requests::is_overdue( $row );
			}
		);

		return array_values( array_map( array( self::class, 'present' ), $rows ) );
	}

	/**
	 * Ability: approves or rejects a request.
	 *
	 * @param array|null $input Ability input.
	 * @return array|\WP_Error
	 */
	public function decide_request( $input = null ) {
		if ( $this->classifier->is_machine() ) {
			return new \WP_Error( 'agent_governance_agent_cannot_decide', __( 'Requests can only be decided by a person signed in to WordPress, not through an application password or MCP. An agent cannot approve its own request.', 'agent-governance' ), array( 'status' => 403 ) );
		}

		$input = (array) $input;
		$id    = (int) ( $input['id'] ?? 0 );
		$user  = get_current_user_id();

		$result = 'approve' === ( $input['decision'] ?? '' )
			? $this->approvals->approve( $id, $user )
			: $this->approvals->reject( $id, $user, (string) ( $input['reason'] ?? '' ) );

		return is_wp_error( $result ) ? $result : self::present( $result );
	}

	/**
	 * A request as abilities return it.
	 *
	 * @param array $row Request row.
	 * @return array
	 */
	public static function present( array $row ): array {
		$result  = json_decode( (string) $row['result'], true );
		$preview = json_decode( (string) $row['dry_run'], true );

		return array(
			'id'         => (int) $row['id'],
			'status'     => (string) $row['status'],
			'message'    => Requests::status_label( (string) $row['status'] ),
			'agent'      => (string) $row['agent'],
			'call'       => trim( $row['method'] . ' ' . $row['target'] ),
			'created'    => gmdate( 'c', (int) strtotime( $row['created'] . ' UTC' ) ),
			'expires'    => Requests::PENDING === $row['status'] ? gmdate( 'c', Requests::expires_at( $row ) ) : '',
			'decided_at' => $row['decided_at'] ? gmdate( 'c', (int) strtotime( $row['decided_at'] . ' UTC' ) ) : '',
			'reason'     => (string) $row['reason'],
			'preview'    => is_array( $preview ) ? (string) ( $preview['summary'] ?? '' ) : '',
			'result'     => $result,
		);
	}

	/**
	 * Output schema of a request.
	 *
	 * @return array
	 */
	private static function request_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'status'     => array(
					'type' => 'string',
					'enum' => Requests::STATUSES,
				),
				'message'    => array( 'type' => 'string' ),
				'agent'      => array( 'type' => 'string' ),
				'call'       => array(
					'type'        => 'string',
					'description' => 'HTTP method and REST route, or the ability name.',
				),
				'created'    => array(
					'type'        => 'string',
					'description' => 'ISO 8601, UTC.',
				),
				'expires'    => array(
					'type'        => 'string',
					'description' => 'ISO 8601, UTC. Empty once decided.',
				),
				'decided_at' => array( 'type' => 'string' ),
				'reason'     => array(
					'type'        => 'string',
					'description' => 'Why it was rejected.',
				),
				'preview'    => array(
					'type'        => 'string',
					'description' => 'What the call would change.',
				),
				'result'     => array(
					'type'        => array( 'object', 'array', 'string', 'number', 'integer', 'boolean', 'null' ),
					'description' => 'What the call returned once it ran: the ability output, or status and data for a REST call.',
				),
			),
		);
	}

	/**
	 * Input schema of the list abilities.
	 *
	 * @param bool $with_status Whether a status filter is allowed.
	 * @return array
	 */
	private static function list_input( bool $with_status ): array {
		$properties = array(
			'limit' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			),
		);
		if ( $with_status ) {
			$properties['status'] = array(
				'type' => 'string',
				'enum' => Requests::STATUSES,
			);
		}

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	/**
	 * Registers one ability in the category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does, for people and agents.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => $annotations,
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}
}
