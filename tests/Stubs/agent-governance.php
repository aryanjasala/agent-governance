<?php
/**
 * Test doubles for Agent Governance: an in-memory $wpdb, and the WordPress
 * classes the template stubs do not cover.
 *
 * @package AryanX\AgentGovernance
 */

// phpcs:ignoreFile

foreach ( array(
	'ARRAY_A'                => 'ARRAY_A',
	'MINUTE_IN_SECONDS'      => 60,
	'HOUR_IN_SECONDS'        => 3600,
	'DAY_IN_SECONDS'         => 86400,
	'WEEK_IN_SECONDS'        => 604800,
	'JSON_PRETTY_PRINT'      => 128,
	'JSON_UNESCAPED_SLASHES' => 64,
) as $agg_name => $agg_value ) {
	defined( $agg_name ) || define( $agg_name, $agg_value );
}

// The main plugin file defines these; the tests do not load it.
defined( 'AryanX\\AgentGovernance\\PLUGIN_FILE' ) || define( 'AryanX\\AgentGovernance\\PLUGIN_FILE', dirname( __DIR__, 2 ) . '/agent-governance.php' );
defined( 'AryanX\\AgentGovernance\\VERSION' ) || define( 'AryanX\\AgentGovernance\\VERSION', '1.0.0' );

if ( ! class_exists( 'WP_Filter_Sentinel' ) ) {
	/**
	 * The default value of wp_pre_execute_ability, from WordPress 7.1.
	 */
	final class WP_Filter_Sentinel {}
}

/**
 * A stand-in for $wpdb: records writes, and answers reads from queues the test fills.
 */
class Agg_Fake_Wpdb {

	/** @var string */
	public $prefix = 'wp_';

	/** @var int */
	public $insert_id = 0;

	/**
	 * Inserted rows per table.
	 *
	 * @var array<string, array[]>
	 */
	public $inserted = array();

	/**
	 * Updates: table, data, where.
	 *
	 * @var array[]
	 */
	public $updated = array();

	/**
	 * Every SQL string passed to a read or query, after prepare.
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Answers for get_var, get_row, get_results and query, oldest first.
	 *
	 * @var array<string, array>
	 */
	public $returns = array(
		'get_var'     => array(),
		'get_row'     => array(),
		'get_results' => array(),
		'query'       => array(),
	);

	/** @var int|null Rows update() reports, null for 1. */
	public $update_result = null;

	/** @var bool Whether insert() fails. */
	public $fail_insert = false;

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( '%i', '`%s`', $query );
		$query = str_replace( array( '%d', '%s' ), array( '%s', "'%s'" ), $query );
		return vsprintf( $query, array_map( 'strval', $args ) );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function get_charset_collate() {
		return '';
	}

	public function insert( $table, $data ) {
		if ( $this->fail_insert ) {
			return false;
		}
		++$this->insert_id;
		$data['id']                 = $this->insert_id;
		$this->inserted[ $table ][] = $data;
		return 1;
	}

	public function update( $table, $data, $where ) {
		$this->updated[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);
		return null === $this->update_result ? 1 : $this->update_result;
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		return array_shift( $this->returns['get_var'] );
	}

	public function get_row( $sql, $output = null ) {
		$this->queries[] = $sql;
		return array_shift( $this->returns['get_row'] );
	}

	public function get_results( $sql, $output = null ) {
		$this->queries[] = $sql;
		$next = array_shift( $this->returns['get_results'] );
		return null === $next ? array() : $next;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		$next = array_shift( $this->returns['query'] );
		return null === $next ? 0 : $next;
	}

	/**
	 * Rows inserted into one of the plugin's tables.
	 *
	 * @param string $name log or requests.
	 * @return array[]
	 */
	public function rows( $name ) {
		return $this->inserted[ $this->prefix . 'agent_governance_' . $name ] ?? array();
	}
}

/**
 * A REST response with a status, like WP_REST_Response.
 */
class Agg_Test_Response {

	/** @var int */
	private $status;

	/** @var mixed */
	private $data;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_data() {
		return $this->data;
	}
}

/**
 * An ability, like WP_Ability, with answers set by the test.
 */
class Agg_Ability_Double {

	/** @var array */
	public $meta = array();

	/** @var mixed What check_permissions() returns. */
	public $permitted = true;

	/** @var mixed What normalize_input() returns, null for its input. */
	public $normalized = null;

	/** @var mixed What validate_input() returns. */
	public $valid = true;

	/** @var callable|null What execute() runs. */
	public $run = null;

	/** @var array Inputs execute() got. */
	public $executed = array();

	public function __construct( array $meta = array(), $permitted = true ) {
		$this->meta      = $meta;
		$this->permitted = $permitted;
	}

	public function get_meta() {
		return $this->meta;
	}

	public function normalize_input( $input = null ) {
		return null === $this->normalized ? $input : $this->normalized;
	}

	public function validate_input( $input = null ) {
		return $this->valid;
	}

	public function check_permissions( $input = null ) {
		return $this->permitted;
	}

	public function execute( $input = null ) {
		$this->executed[] = $input;
		return $this->run ? call_user_func( $this->run, $input ) : null;
	}
}

/**
 * A REST request Approvals builds, recording what it was given.
 */
class Agg_Built_Request {

	/** @var string */
	public $method;

	/** @var string */
	public $route;

	/** @var array */
	public $query = array();

	/** @var array */
	public $headers = array();

	/** @var string */
	public $body = '';

	public function __construct( $method, $route ) {
		$this->method = $method;
		$this->route  = $route;
	}

	public function set_query_params( $params ) {
		$this->query = $params;
	}

	public function set_header( $key, $value ) {
		$this->headers[ $key ] = $value;
	}

	public function set_body( $body ) {
		$this->body = $body;
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/**
	 * A term.
	 */
	class WP_Term {
		/** @var int */
		public $term_id = 0;
		/** @var string */
		public $name = '';
		/** @var string */
		public $slug = '';
		/** @var string */
		public $description = '';
		/** @var int */
		public $parent = 0;
		/** @var int */
		public $count = 0;
		/** @var string */
		public $taxonomy = '';
	}
}
