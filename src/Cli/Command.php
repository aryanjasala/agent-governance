<?php
/**
 * WP-CLI commands.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Cli;

use AryanX\Axle\AgentGovernance\Approvals;
use AryanX\Axle\AgentGovernance\Capability;
use AryanX\Axle\AgentGovernance\Cleanup;
use AryanX\Axle\AgentGovernance\Log;
use AryanX\Axle\AgentGovernance\Requests;
use AryanX\Axle\AgentGovernance\Rollback;
use AryanX\Axle\AgentGovernance\Schema;
use AryanX\Axle\AgentGovernance\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Review agent calls, decide held requests, pause agents and roll back sessions.
 *
 * ## EXAMPLES
 *
 *     wp agent-governance pending
 *     wp agent-governance approve 12
 *     wp agent-governance reject 13 --reason="Not this post."
 *     wp agent-governance log --status=denied --format=json
 *     wp agent-governance rollback 6f0c2f8e-0a3e-4f53-9d1c-0d6a1f1c4b8a --dry-run
 */
final class Command {

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
	 * Sets up the command.
	 *
	 * @param Settings  $settings  Settings.
	 * @param Log       $log       The audit log.
	 * @param Requests  $requests  The approval queue.
	 * @param Approvals $approvals Approvals.
	 * @param Rollback  $rollback  Session rollback.
	 */
	public function __construct( Settings $settings, Log $log, Requests $requests, Approvals $approvals, Rollback $rollback ) {
		$this->settings  = $settings;
		$this->log       = $log;
		$this->requests  = $requests;
		$this->approvals = $approvals;
		$this->rollback  = $rollback;
	}

	/**
	 * Lists agent calls, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--identity=<identity>]
	 * : Only this agent: an application password UUID, app:<uuid>, user:<id> or a user login.
	 *
	 * [--status=<status>]
	 * : Only calls with this outcome.
	 * ---
	 * options:
	 *   - allowed
	 *   - denied
	 *   - held
	 *   - executed
	 *   - failed
	 *   - refused
	 * ---
	 *
	 * [--session=<session>]
	 * : Only calls in this session.
	 *
	 * [--limit=<n>]
	 * : Most rows. Default 50.
	 *
	 * [--fields=<fields>]
	 * : Fields to show.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function log( $args, $assoc_args ): void {
		$found = $this->log->query(
			array(
				'identity' => isset( $assoc_args['identity'] ) ? self::identity( (string) $assoc_args['identity'] ) : '',
				'decision' => (string) ( $assoc_args['status'] ?? '' ),
				'session'  => (string) ( $assoc_args['session'] ?? '' ),
				'limit'    => (int) ( $assoc_args['limit'] ?? 50 ),
			)
		);

		$rows = array_map(
			static function ( array $row ): array {
				return array(
					'id'          => (int) $row['id'],
					'created'     => $row['created'],
					'agent'       => Log::identity_label( $row ),
					'identity'    => $row['identity'],
					'via'         => $row['via'],
					'call'        => trim( $row['method'] . ' ' . $row['target'] ),
					'kind'        => $row['kind'],
					'status'      => $row['decision'],
					'reason'      => $row['reason'],
					'result'      => $row['result'],
					'request_id'  => (int) $row['request_id'],
					'duration_ms' => (int) $row['duration_ms'],
					'session'     => $row['session'],
					'ip'          => $row['ip'],
					'input'       => $row['input'],
				);
			},
			$found['rows']
		);

		self::format( $assoc_args, $rows, array( 'id', 'created', 'agent', 'call', 'status', 'reason', 'result' ) );
	}

	/**
	 * Lists requests waiting for approval.
	 *
	 * ## OPTIONS
	 *
	 * [--fields=<fields>]
	 * : Fields to show.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 *   - ids
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function pending( $args, $assoc_args ): void {
		$rows = array();
		foreach ( $this->requests->query(
			array(
				'status' => Requests::PENDING,
				'limit'  => 1000,
			)
		)['rows'] as $row ) {
			if ( Requests::is_overdue( $row ) ) {
				continue;
			}
			$rows[] = self::request_row( $row );
		}

		self::format( $assoc_args, $rows, array( 'id', 'created', 'agent', 'call', 'preview', 'expires' ) );
	}

	/**
	 * Shows one request, with its input and preview.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Request ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: yaml
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function show( $args, $assoc_args ): void {
		$row = $this->requests->get( (int) $args[0] );
		if ( ! $row ) {
			\WP_CLI::error( 'No request with that ID.' );
			return;
		}

		$data            = self::request_row( $row );
		$data['input']   = json_decode( \AryanX\Axle\AgentGovernance\Redactor::encode( json_decode( (string) $row['input'], true ), 'input' ), true );
		$data['dry_run'] = json_decode( (string) $row['dry_run'], true );
		$data['result']  = json_decode( (string) $row['result'], true );
		$data['reason']  = (string) $row['reason'];

		if ( 'json' === ( $assoc_args['format'] ?? 'yaml' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		\WP_CLI\Utils\format_items( 'yaml', array( $data ), array_keys( $data ) );
	}

	/**
	 * Approves a held request and runs it, as the user the agent signed in as.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Request ID.
	 *
	 * @param array $args Positional arguments.
	 */
	public function approve( $args ): void {
		$result = $this->approvals->approve( (int) $args[0], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		if ( Requests::FAILED === $result['status'] ) {
			\WP_CLI::warning( sprintf( 'Request %d was approved, but the call failed: %s', (int) $result['id'], (string) $result['result'] ) );
			return;
		}

		\WP_CLI::success( sprintf( 'Request %d was approved and ran.', (int) $result['id'] ) );
	}

	/**
	 * Rejects a held request.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Request ID.
	 *
	 * [--reason=<reason>]
	 * : Why. The agent sees it.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function reject( $args, $assoc_args ): void {
		$result = $this->approvals->reject( (int) $args[0], get_current_user_id(), (string) ( $assoc_args['reason'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}

		\WP_CLI::success( sprintf( 'Request %d was rejected.', (int) $result['id'] ) );
	}

	/**
	 * Pauses every agent write. Reading keeps working.
	 */
	public function pause(): void {
		if ( ! $this->settings->pause( get_current_user_id() ) ) {
			\WP_CLI::warning( 'Agent writes were already paused.' );
			return;
		}

		\WP_CLI::success( 'Agent writes are paused.' );
	}

	/**
	 * Lets agent writes through again.
	 */
	public function resume(): void {
		if ( ! $this->settings->resume( get_current_user_id() ) ) {
			\WP_CLI::warning( 'Agent writes were not paused.' );
			return;
		}

		\WP_CLI::success( 'Agent writes are allowed again.' );
	}

	/**
	 * Shows whether agents are paused and how many requests wait.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function status( $args, $assoc_args ): void {
		$data = array(
			'paused'     => $this->settings->is_paused() ? 'yes' : 'no',
			'pending'    => $this->requests->pending_count(),
			'rate_limit' => (int) $this->settings->get( 'rate_limit' ),
			'rules'      => count( $this->settings->all_rules() ),
		);

		self::format( $assoc_args, array( $data ), array_keys( $data ) );
	}

	/**
	 * Rolls back the posts an agent changed: edits go back to the revision saved
	 * before them, and posts it created go to the trash.
	 *
	 * ## OPTIONS
	 *
	 * <identity>
	 * : The agent: an application password UUID, app:<uuid>, user:<id> or a user login.
	 *
	 * [--since=<when>]
	 * : Roll back everything since then, such as "2 hours ago" or "2026-10-23 09:00". Default: the start of the agent's latest session.
	 *
	 * [--session=<session>]
	 * : Roll back this session instead.
	 *
	 * [--dry-run]
	 * : Only show what would happen.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function rollback( $args, $assoc_args ): void {
		$identity = self::identity( (string) $args[0] );

		if ( ! empty( $assoc_args['session'] ) ) {
			$scope = (string) $assoc_args['session'];
			$rows  = $this->log->session_rows( $scope );
		} else {
			$since = isset( $assoc_args['since'] ) ? strtotime( (string) $assoc_args['since'] ) : $this->log->latest_session_start( $identity );
			if ( false === $since ) {
				\WP_CLI::error( 'Could not read --since. Try "2 hours ago" or "2026-10-23 09:00".' );
				return;
			}
			$scope = $identity;
			$rows  = $since ? $this->log->identity_rows( $identity, (int) $since ) : array();
		}

		$plan  = $this->rollback->plan( $rows );
		$items = array();
		foreach ( $plan['posts'] as $item ) {
			$items[] = array(
				'post_id' => $item['post_id'],
				'title'   => $item['title'],
				'action'  => $item['possible'] ? $item['action'] : 'skip',
				'note'    => $item['note'],
			);
		}
		foreach ( $plan['other'] as $other ) {
			$items[] = array(
				'post_id' => '',
				'title'   => trim( $other['method'] . ' ' . $other['target'] ),
				'action'  => 'skip',
				'note'    => $other['note'],
			);
		}

		if ( ! $items ) {
			\WP_CLI::success( 'Nothing to roll back.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $items, array( 'post_id', 'title', 'action', 'note' ) );

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			return;
		}

		\WP_CLI::confirm( 'Roll back these changes?', $assoc_args );
		$result = $this->rollback->run( $plan, $scope );
		\WP_CLI::success( sprintf( 'Restored %d, moved to the trash %d, failed %d, skipped %d.', $result['restored'], $result['trashed'], $result['failed'], $result['skipped'] ) );
	}

	/**
	 * Lists agent sessions, most recent first.
	 *
	 * ## OPTIONS
	 *
	 * [--identity=<identity>]
	 * : Only this agent.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function sessions( $args, $assoc_args ): void {
		$rows = array();
		foreach ( $this->log->sessions( array( 'identity' => isset( $assoc_args['identity'] ) ? self::identity( (string) $assoc_args['identity'] ) : '' ) ) as $row ) {
			$rows[] = array(
				'session'     => $row['session'],
				'agent'       => Log::identity_label( $row ),
				'first'       => $row['first'],
				'last'        => $row['last'],
				'calls'       => (int) $row['calls'],
				'writes'      => (int) $row['writes'],
				'rolled_back' => (string) $row['rolled_back'],
			);
		}

		self::format( $assoc_args, $rows, array( 'session', 'agent', 'first', 'last', 'calls', 'writes', 'rolled_back' ) );
	}

	/**
	 * Lists the policy rules, saved and from code.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function rules( $args, $assoc_args ): void {
		self::format( $assoc_args, $this->settings->all_rules(), array( 'id', 'scope', 'subject', 'pattern', 'action' ) );
	}

	/**
	 * Adds a policy rule at the end.
	 *
	 * ## OPTIONS
	 *
	 * <pattern>
	 * : Ability name or REST route, with * for anything. May start with methods, such as "DELETE /wp/v2/*".
	 *
	 * <action>
	 * : What happens.
	 * ---
	 * options:
	 *   - allow
	 *   - deny
	 *   - require_approval
	 * ---
	 *
	 * [--scope=<scope>]
	 * : Who it applies to.
	 * ---
	 * default: default
	 * options:
	 *   - default
	 *   - agent
	 *   - user
	 *   - role
	 * ---
	 *
	 * [--subject=<subject>]
	 * : The application password UUID, user ID or login, or role. Needed unless the scope is default.
	 *
	 * @subcommand add-rule
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function add_rule( $args, $assoc_args ): void {
		$rule = $this->settings->add_rule(
			array(
				'pattern' => (string) $args[0],
				'action'  => (string) $args[1],
				'scope'   => (string) ( $assoc_args['scope'] ?? 'default' ),
				'subject' => (string) ( $assoc_args['subject'] ?? '' ),
			)
		);

		if ( ! $rule ) {
			\WP_CLI::error( 'That rule is not valid. A scope other than default needs --subject.' );
			return;
		}

		\WP_CLI::success( sprintf( 'Added rule %s.', $rule['id'] ) );
	}

	/**
	 * Removes a policy rule.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Rule ID, from `wp agent-governance rules`.
	 *
	 * @subcommand delete-rule
	 *
	 * @param array $args Positional arguments.
	 */
	public function delete_rule( $args ): void {
		if ( ! $this->settings->delete_rule( (string) $args[0] ) ) {
			\WP_CLI::error( 'No saved rule with that ID.' );
			return;
		}

		\WP_CLI::success( 'Rule removed.' );
	}

	/**
	 * Shows or changes the settings.
	 *
	 * ## OPTIONS
	 *
	 * [--rate-limit=<n>]
	 * : Calls per minute per agent. 0 turns the limit off.
	 *
	 * [--retention-days=<n>]
	 * : Days the log is kept.
	 *
	 * [--email=<on|off>]
	 * : Email approvers when a call is held.
	 *
	 * [--notice=<on|off>]
	 * : Show approvers a notice while calls wait.
	 *
	 * [--readonly=<action>]
	 * : What happens to read-only calls no rule matches: allow, deny or require_approval.
	 *
	 * [--write=<action>]
	 * : What happens to writes no rule matches.
	 *
	 * [--destructive=<action>]
	 * : What happens to destructive calls no rule matches.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function settings( $args, $assoc_args ): void {
		$changes = array();
		if ( isset( $assoc_args['rate-limit'] ) ) {
			$changes['rate_limit'] = (int) $assoc_args['rate-limit'];
		}
		if ( isset( $assoc_args['retention-days'] ) ) {
			$changes['retention_days'] = (int) $assoc_args['retention-days'];
		}
		foreach ( array( 'email', 'notice' ) as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$changes[ $flag ] = in_array( strtolower( (string) $assoc_args[ $flag ] ), array( 'on', '1', 'yes', 'true' ), true );
			}
		}
		$defaults = $this->settings->get( 'defaults' );
		foreach ( Settings::KINDS as $kind ) {
			if ( isset( $assoc_args[ $kind ] ) ) {
				if ( ! in_array( $assoc_args[ $kind ], Settings::ACTIONS, true ) ) {
					\WP_CLI::error( sprintf( '--%s must be allow, deny or require_approval.', $kind ) );
					return;
				}
				$defaults[ $kind ]   = (string) $assoc_args[ $kind ];
				$changes['defaults'] = $defaults;
			}
		}

		$settings = $changes ? $this->settings->update( $changes ) : $this->settings->all();
		$data     = array(
			'paused'         => $settings['paused'] ? 'yes' : 'no',
			'rate_limit'     => $settings['rate_limit'],
			'retention_days' => $settings['retention_days'],
			'email'          => $settings['email'] ? 'on' : 'off',
			'notice'         => $settings['notice'] ? 'on' : 'off',
			'readonly'       => $settings['defaults']['readonly'],
			'write'          => $settings['defaults']['write'],
			'destructive'    => $settings['defaults']['destructive'],
		);

		if ( $changes ) {
			\WP_CLI::success( 'Settings saved.' );
		}
		self::format( $assoc_args, array( $data ), array_keys( $data ) );
	}

	/**
	 * Gives the approver capability back to the approver roles (Administrator by
	 * default), after something took it off every role.
	 *
	 * ## EXAMPLES
	 *
	 *     wp agent-governance restore-capability
	 *
	 * @subcommand restore-capability
	 */
	public function restore_capability(): void {
		$roles = Capability::restore( get_current_user_id() );
		if ( ! $roles ) {
			\WP_CLI::warning( 'The approver roles already have the capability, or none of them exist.' );
			return;
		}

		\WP_CLI::success( sprintf( 'Gave %1$s to %2$s.', Schema::CAPABILITY, implode( ', ', $roles ) ) );
	}

	/**
	 * Expires overdue requests and deletes log rows past the retention period, now.
	 */
	public function cleanup(): void {
		$cleanup = new Cleanup( $this->settings, $this->log, $this->requests, $this->approvals );
		$result  = $cleanup->run();

		\WP_CLI::success( sprintf( 'Expired %d requests, deleted %d log rows and %d old requests.', $result['expired'], $result['log'], $result['requests'] ) );
	}

	/**
	 * A request as a row.
	 *
	 * @param array $row Request.
	 * @return array
	 */
	private static function request_row( array $row ): array {
		$preview = json_decode( (string) $row['dry_run'], true );

		return array(
			'id'       => (int) $row['id'],
			'created'  => $row['created'],
			'agent'    => Log::identity_label( $row ),
			'identity' => $row['identity'],
			'call'     => trim( $row['method'] . ' ' . $row['target'] ),
			'status'   => Requests::is_overdue( $row ) ? Requests::EXPIRED : $row['status'],
			'preview'  => is_array( $preview ) ? (string) ( $preview['summary'] ?? '' ) : '',
			'expires'  => gmdate( 'Y-m-d H:i:s', Requests::expires_at( $row ) ),
			'session'  => $row['session'],
		);
	}

	/**
	 * An identity key from what the user typed.
	 *
	 * @param string $value UUID, app:<uuid>, user:<id>, or a user login.
	 * @return string
	 */
	public static function identity( string $value ): string {
		$value = trim( $value );
		if ( 0 === strpos( $value, 'app:' ) || 0 === strpos( $value, 'user:' ) ) {
			return $value;
		}
		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) {
			return 'app:' . strtolower( $value );
		}

		$user = get_user_by( 'login', $value );
		if ( ! $user && ctype_digit( $value ) ) {
			$user = get_user_by( 'id', (int) $value );
		}

		return $user ? 'user:' . $user->ID : $value;
	}

	/**
	 * Prints rows in the requested format.
	 *
	 * @param array    $assoc_args Options.
	 * @param array[]  $rows       Rows.
	 * @param string[] $fields     Default fields.
	 */
	private static function format( array $assoc_args, array $rows, array $fields ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( isset( $assoc_args['fields'] ) ) {
			$fields = array_map( 'trim', explode( ',', (string) $assoc_args['fields'] ) );
		}
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_column( $rows, 'id' ) ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}
}
