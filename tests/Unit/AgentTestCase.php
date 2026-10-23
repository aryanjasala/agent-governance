<?php
/**
 * Base class for Agent Governance unit tests.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance\Tests\Unit;

use AryanX\AgentGovernance\Abilities;
use AryanX\AgentGovernance\Approvals;
use AryanX\AgentGovernance\Classifier;
use AryanX\AgentGovernance\Context;
use AryanX\AgentGovernance\DryRun;
use AryanX\AgentGovernance\Gate;
use AryanX\AgentGovernance\Log;
use AryanX\AgentGovernance\Notifier;
use AryanX\AgentGovernance\Policy;
use AryanX\AgentGovernance\Requests;
use AryanX\AgentGovernance\Rollback;
use AryanX\AgentGovernance\Settings;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Stubs/agent-governance.php';

/**
 * Wires the real services to an in-memory $wpdb, with the WordPress functions
 * they call stubbed.
 */
abstract class AgentTestCase extends TestCase {

	/** @var \Agg_Fake_Wpdb */
	protected $wpdb;

	/** @var int */
	protected $user_id = 1;

	/** @var array<int, object> Users by ID. */
	protected $users = array();

	/** @var array<string, mixed> Object cache. */
	protected $cache = array();

	/** @var array<string, bool> Capabilities of the current user. */
	protected $caps = array();

	/** @var \Agg_Fake_Roles The site's roles. */
	protected $roles;

	protected Settings $settings;
	protected Context $context;
	protected Classifier $classifier;
	protected Log $log;
	protected Requests $requests;
	protected Policy $policy;
	protected Notifier $notifier;
	protected Gate $gate;
	protected Approvals $approvals;
	protected Rollback $rollback;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb      = new \Agg_Fake_Wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->cache     = array();
		$this->caps      = array( 'agent_governance_approve' => true );
		$this->users     = array(
			1 => (object) array(
				'ID'           => 1,
				'user_login'   => 'admin',
				'user_email'   => 'admin@example.com',
				'display_name' => 'Admin',
				'roles'        => array( 'administrator' ),
			),
			2 => (object) array(
				'ID'           => 2,
				'user_login'   => 'ed',
				'user_email'   => 'ed@example.com',
				'display_name' => 'Ed',
				'roles'        => array( 'editor' ),
			),
		);
		$this->user_id = 1;

		Functions\when( 'get_current_user_id' )->alias(
			function () {
				return $this->user_id;
			}
		);
		Functions\when( 'wp_set_current_user' )->alias(
			function ( $id ) {
				$this->user_id = (int) $id;
			}
		);
		Functions\when( 'is_user_logged_in' )->alias(
			function () {
				return $this->user_id > 0;
			}
		);
		Functions\when( 'get_userdata' )->alias(
			function ( $id ) {
				return $this->users[ (int) $id ] ?? false;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return ! empty( $this->caps[ $cap ] );
			}
		);
		Functions\when( 'wp_cache_get' )->alias(
			function ( $key, $group = '' ) {
				return $this->cache[ $group . ':' . $key ] ?? false;
			}
		);
		Functions\when( 'wp_cache_set' )->alias(
			function ( $key, $value, $group = '' ) {
				$this->cache[ $group . ':' . $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group = '' ) {
				unset( $this->cache[ $group . ':' . $key ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, $flags = 0 ) {
				return json_encode( $data, $flags );
			}
		);
		Functions\stubs(
			array(
				'sanitize_key'             => static function ( $key ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
				},
				'sanitize_text_field'      => static function ( $text ) {
					return trim( (string) $text );
				},
				'sanitize_textarea_field'  => static function ( $text ) {
					return trim( (string) $text );
				},
				'wp_unslash'               => static function ( $value ) {
					return $value;
				},
				'esc_url_raw'              => static function ( $url ) {
					return (string) $url;
				},
				'sanitize_email'           => static function ( $email ) {
					return trim( (string) $email );
				},
				'is_email'                 => static function ( $email ) {
					return false !== strpos( (string) $email, '@' ) ? $email : false;
				},
				'admin_url'                => static function ( $path = '' ) {
					return 'https://example.com/wp-admin/' . $path;
				},
				'add_query_arg'            => static function ( $args, $url = '' ) {
					return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
				},
				'human_time_diff'          => '1 day',
				'wp_specialchars_decode'   => static function ( $text ) {
					return $text;
				},
				'get_bloginfo'             => '7.1',
				'absint'                   => static function ( $n ) {
					return abs( (int) $n );
				},
			)
		);

		Functions\when( 'rest_get_authenticated_app_password' )->justReturn( '' );

		$this->roles = new \Agg_Fake_Roles();
		$this->roles->add(
			'administrator',
			'Administrator',
			array(
				'manage_options'           => true,
				'agent_governance_approve' => true,
			)
		);
		$this->roles->add( 'editor', 'Editor', array( 'edit_posts' => true ) );
		Functions\when( 'wp_roles' )->alias(
			function () {
				return $this->roles;
			}
		);
		Functions\when( 'translate_user_role' )->returnArg( 1 );
		Functions\when( 'get_post_status_object' )->alias(
			static function ( $status ) {
				$labels = array(
					'publish' => 'Published',
					'draft'   => 'Draft',
					'trash'   => 'Trash',
				);
				return isset( $labels[ $status ] ) ? (object) array( 'label' => $labels[ $status ] ) : null;
			}
		);

		$this->build();
	}

	/**
	 * Builds the services, as Plugin does.
	 */
	protected function build(): void {
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
	 * Makes the current request one signed in with an application password.
	 *
	 * @param string $uuid Application password UUID.
	 * @param string $name Its name.
	 */
	protected function as_app_password( string $uuid = 'aaaa-1111', string $name = 'Helper' ): void {
		$this->context->app_password_used( (object) array( 'ID' => $this->user_id ), array( 'uuid' => $uuid, 'name' => $name ) );
	}

	/**
	 * A request row, as the requests table returns it.
	 *
	 * @param array $overrides Columns.
	 * @return array
	 */
	protected function request_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'         => 7,
				'created'    => gmdate( 'Y-m-d H:i:s', time() - 60 ),
				'identity'   => 'app:aaaa-1111',
				'agent'      => 'Helper',
				'user_id'    => 2,
				'session'    => 'app:aaaa-1111@20261023T100000',
				'via'        => 'app-password',
				'source'     => 'ability',
				'target'     => 'my/ability',
				'method'     => 'POST',
				'kind'       => 'destructive',
				'input'      => '{"id":5,"password":"hunter2"}',
				'status'     => Requests::PENDING,
				'decided_by' => 0,
				'decided_at' => null,
				'reason'     => '',
				'result'     => null,
				'dry_run'    => '{"supported":true,"summary":"Deletes it.","object":null,"changes":[]}',
			),
			$overrides
		);
	}

	/**
	 * A log row, as the log table returns it.
	 *
	 * @param array $overrides Columns.
	 * @return array
	 */
	protected function log_row( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => 1,
				'created'     => gmdate( 'Y-m-d H:i:s' ),
				'identity'    => 'app:aaaa-1111',
				'agent'       => 'Helper',
				'user_id'     => 1,
				'session'     => 's1',
				'via'         => 'app-password',
				'source'      => 'rest',
				'target'      => '/wp/v2/posts/5',
				'method'      => 'POST',
				'kind'        => 'write',
				'decision'    => 'allowed',
				'reason'      => 'default',
				'result'      => 'ok',
				'request_id'  => 0,
				'duration_ms' => 3,
				'ip'          => '',
				'input'       => '{}',
				'changes'     => null,
				'rolled_back' => null,
			),
			$overrides
		);
	}

	/**
	 * The abilities category, for tests that need the constant loaded.
	 *
	 * @return string
	 */
	protected function own(): string {
		return Abilities::CATEGORY;
	}
}
