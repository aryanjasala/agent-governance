<?php
/**
 * Minimal stand-ins for WordPress classes and constants used in unit tests.
 *
 * @package AryanX\AgentGovernance
 */

// phpcs:ignoreFile

foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
	'ARRAY_N'           => 'ARRAY_N',
) as $name => $value ) {
	defined( $name ) || define( $name, $value );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Enough of WP_Error for code that creates one and reads its message.
	 */
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;
		/** @var mixed */
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * A post built from an array of fields. Fields beyond the declared ones are
	 * allowed, as on the real class, so PHP 8.2+ raises no deprecation.
	 */
	#[\AllowDynamicProperties]
	class WP_Post {
		/** @var int */
		public $ID = 0;
		/** @var int|string */
		public $post_author = 0;
		/** @var string */
		public $post_date = '';
		/** @var string */
		public $post_date_gmt = '';
		/** @var string */
		public $post_content = '';
		/** @var string */
		public $post_title = '';
		/** @var string */
		public $post_excerpt = '';
		/** @var string */
		public $post_status = 'publish';
		/** @var string */
		public $post_name = '';
		/** @var string */
		public $post_modified = '';
		/** @var string */
		public $post_modified_gmt = '';
		/** @var int */
		public $post_parent = 0;
		/** @var string */
		public $post_type = 'post';
		/** @var int|string */
		public $comment_count = 0;

		public function __construct( $fields = array() ) {
			foreach ( (array) $fields as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Enough of WP_REST_Request for code that reads parameters.
	 */
	class WP_REST_Request {
		/** @var array */
		private $params;

		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Enough of WP_REST_Response for code that reads and changes its data.
	 */
	class WP_REST_Response {
		/** @var mixed */
		private $data;

		public function __construct( $data = null ) {
			$this->data = $data;
		}

		public function get_data() {
			return $this->data;
		}

		public function set_data( $data ) {
			$this->data = $data;
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query for unit tests. Each new query takes the next queued result.
	 */
	class WP_Query {

		/**
		 * Results for the next queries: each array( 'posts' => array(), 'found_posts' => int ).
		 *
		 * @var array[]
		 */
		public static $queue = array();

		/**
		 * Arguments of every query made, oldest first.
		 *
		 * @var array[]
		 */
		public static $made = array();

		/**
		 * Posts or IDs found.
		 *
		 * @var array
		 */
		public $posts = array();

		/**
		 * Total matches.
		 *
		 * @var int
		 */
		public $found_posts = 0;

		/**
		 * The arguments this query was made with.
		 *
		 * @var array
		 */
		public $query_vars = array();

		/**
		 * Runs the "query".
		 *
		 * @param array $args Query arguments.
		 */
		public function __construct( array $args = array() ) {
			self::$made[]      = $args;
			$this->query_vars  = $args;
			$next              = array_shift( self::$queue );
			$this->posts       = $next['posts'] ?? array();
			$this->found_posts = $next['found_posts'] ?? count( $this->posts );
		}

		/**
		 * One query argument, like the real get().
		 *
		 * @param string $key     Argument name.
		 * @param mixed  $default Value when the argument is not set.
		 * @return mixed
		 */
		public function get( $key, $default = '' ) {
			return $this->query_vars[ $key ] ?? $default;
		}
	}
}
