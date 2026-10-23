<?php
/**
 * Shows what a held call would change, without changing it.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the preview an approver sees: a summary and a before and after per
 * field. REST writes to posts (any post type), terms, users, settings and
 * plugins get one built in. Abilities supply their own through the
 * agent_governance_dry_run filter, since only they know what their input means.
 */
final class DryRun {

	/**
	 * Post fields the REST API takes, and their column.
	 */
	private const POST_FIELDS = array(
		'title'          => 'post_title',
		'content'        => 'post_content',
		'excerpt'        => 'post_excerpt',
		'status'         => 'post_status',
		'slug'           => 'post_name',
		'author'         => 'post_author',
		'parent'         => 'post_parent',
		'menu_order'     => 'menu_order',
		'comment_status' => 'comment_status',
		'ping_status'    => 'ping_status',
		'date'           => 'post_date',
		'password'       => 'post_password',
	);

	/**
	 * User fields the REST API takes, and their property.
	 */
	private const USER_FIELDS = array(
		'name'        => 'display_name',
		'email'       => 'user_email',
		'url'         => 'user_url',
		'first_name'  => 'first_name',
		'last_name'   => 'last_name',
		'nickname'    => 'nickname',
		'description' => 'description',
		'slug'        => 'user_nicename',
		'password'    => 'user_pass',
	);

	/**
	 * The preview for a call.
	 *
	 * @param array $call source, target, method, kind, input.
	 * @return array supported, summary, object (type, id, label, url), changes (field, before, after).
	 */
	public function preview( array $call ): array {
		/**
		 * Filters the dry-run preview of a held call.
		 *
		 * Return an array to show your own preview, for example for your ability:
		 * summary (one sentence), changes (a list of field, before and after) and
		 * optionally object (type, id, label, url). Return null to use the built-in
		 * one, which covers REST writes to posts, terms, users, settings and plugins.
		 * Compute it without saving anything.
		 *
		 * @param array|null $preview Null by default.
		 * @param array      $call    source (ability or rest), target (ability name or
		 *                            REST route), method, kind and input.
		 */
		$custom = apply_filters( 'agent_governance_dry_run', null, $call );
		if ( is_array( $custom ) ) {
			return self::normalize( $custom + array( 'supported' => true ) );
		}

		if ( 'rest' === $call['source'] ) {
			$preview = $this->rest( (string) $call['method'], (string) $call['target'], (array) ( $call['input'] ?? array() ) );
			if ( $preview ) {
				return self::normalize( $preview );
			}
		}

		if ( 'ability' === ( $call['source'] ?? '' ) ) {
			return self::normalize( self::ability( (string) $call['target'], $call['input'] ?? null ) );
		}

		return self::normalize(
			array(
				'supported' => false,
				'summary'   => __( 'No preview for this call.', 'agent-governance' ),
			)
		);
	}

	/**
	 * What an ability says about itself, for abilities that give no preview of
	 * their own: its label and what it does.
	 *
	 * @param string $name  Ability name.
	 * @param mixed  $input Input.
	 * @return array
	 */
	public static function ability( string $name, $input ): array {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! $ability ) {
			return array(
				'supported' => false,
				'summary'   => __( 'No preview for this call.', 'agent-governance' ),
			);
		}

		$label       = trim( (string) $ability->get_label() );
		$description = trim( (string) $ability->get_description() );
		$label       = '' !== $label ? $label : $name;

		$summary = '' !== $description
			/* translators: 1: ability label, 2: what the ability says it does. */
			? sprintf( __( 'Runs "%1$s": %2$s', 'agent-governance' ), $label, $description )
			/* translators: %s: ability label. */
			: sprintf( __( 'Runs "%s".', 'agent-governance' ), $label );

		/**
		 * Filters the preview built from an ability's own label and description,
		 * for abilities that give no preview through agent_governance_dry_run. The
		 * approver sees the input next to it.
		 *
		 * @param array  $preview supported (false: nothing was computed) and summary.
		 * @param string $name    Ability name.
		 * @param mixed  $input   Input.
		 */
		$preview = (array) apply_filters(
			'agent_governance_ability_preview',
			array(
				'supported' => false,
				'summary'   => $summary,
			),
			$name,
			$input
		);

		return isset( $preview['summary'] ) ? $preview : array(
			'supported' => false,
			'summary'   => $summary,
		);
	}

	/**
	 * Built-in previews for REST writes.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param array  $input  query and body.
	 * @return array|null
	 */
	public function rest( string $method, string $route, array $input ): ?array {
		$params = array_merge( (array) ( $input['query'] ?? array() ), (array) ( $input['body'] ?? array() ) );

		if ( '/wp/v2/settings' === $route ) {
			return $this->settings( $params );
		}

		if ( preg_match( '#^/wp/v2/users/(\d+|me)$#', $route, $m ) ) {
			return $this->user( $method, 'me' === $m[1] ? get_current_user_id() : (int) $m[1], $params );
		}

		if ( preg_match( '#^/wp/v2/plugins/(.+)$#', $route, $m ) ) {
			return $this->plugin( $method, $m[1], $params );
		}

		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $type ) {
			$base = self::rest_path( $type );
			if ( preg_match( '#^' . preg_quote( $base, '#' ) . '(?:/(\d+))?$#', $route, $m ) ) {
				return $this->post( $method, $type, isset( $m[1] ) ? (int) $m[1] : 0, $params );
			}
		}

		foreach ( get_taxonomies( array( 'show_in_rest' => true ), 'objects' ) as $taxonomy ) {
			$base = self::rest_path( $taxonomy );
			if ( preg_match( '#^' . preg_quote( $base, '#' ) . '(?:/(\d+))?$#', $route, $m ) ) {
				return $this->term( $method, $taxonomy, isset( $m[1] ) ? (int) $m[1] : 0, $params );
			}
		}

		return null;
	}

	/**
	 * Preview of a post write.
	 *
	 * @param string        $method HTTP method.
	 * @param \WP_Post_Type $type   Post type.
	 * @param int           $id     Post ID, 0 to create.
	 * @param array         $params Request parameters.
	 * @return array|null
	 */
	private function post( string $method, $type, int $id, array $params ): ?array {
		$label = (string) ( $type->labels->singular_name ?? $type->name );

		if ( ! $id ) {
			if ( 'POST' !== $method ) {
				return null;
			}

			return array(
				'supported' => true,
				/* translators: %s: post type name, such as Post. */
				'summary'   => sprintf( __( 'Creates a new %s.', 'agent-governance' ), $label ),
				'changes'   => $this->post_changes( null, $params, $type ),
			);
		}

		$post = get_post( $id );
		if ( ! $post || $post->post_type !== $type->name ) {
			return array(
				'supported' => true,
				/* translators: %d: post ID. */
				'summary'   => sprintf( __( 'Post %d does not exist, so this call would fail.', 'agent-governance' ), $id ),
			);
		}

		$object = array(
			'type'  => $type->name,
			'id'    => $id,
			'label' => get_the_title( $post ),
			'url'   => (string) get_edit_post_link( $id, 'raw' ),
		);

		if ( 'DELETE' === $method ) {
			$force = ! empty( $params['force'] ) && 'false' !== $params['force'];

			return array(
				'supported' => true,
				'summary'   => $force
					/* translators: 1: post type name, 2: title. */
					? sprintf( __( 'Deletes the %1$s "%2$s" permanently. This cannot be rolled back.', 'agent-governance' ), $label, $object['label'] )
					/* translators: 1: post type name, 2: title. */
					: sprintf( __( 'Moves the %1$s "%2$s" to the trash.', 'agent-governance' ), $label, $object['label'] ),
				'object'    => $object,
				'changes'   => $force ? array() : array( self::change( __( 'Status', 'agent-governance' ), self::status_label( $post->post_status ), self::status_label( 'trash' ) ) ),
			);
		}

		$changes = $this->post_changes( $post, $params, $type );

		return array(
			'supported' => true,
			'summary'   => $changes
				/* translators: 1: post type name, 2: title, 3: number of fields. */
				? sprintf( _n( 'Changes %3$d field of the %1$s "%2$s".', 'Changes %3$d fields of the %1$s "%2$s".', count( $changes ), 'agent-governance' ), $label, $object['label'], count( $changes ) )
				/* translators: 1: post type name, 2: title. */
				: sprintf( __( 'Saves the %1$s "%2$s" without changing any field shown here.', 'agent-governance' ), $label, $object['label'] ),
			'object'    => $object,
			'changes'   => $changes,
		);
	}

	/**
	 * Field changes for a post write.
	 *
	 * @param \WP_Post|null $post   Post, or null when creating.
	 * @param array         $params Request parameters.
	 * @param \WP_Post_Type $type   Post type.
	 * @return array[]
	 */
	private function post_changes( $post, array $params, $type ): array {
		$changes = array();

		foreach ( self::POST_FIELDS as $field => $column ) {
			if ( ! array_key_exists( $field, $params ) ) {
				continue;
			}

			$after  = self::raw( $params[ $field ] );
			$before = $post ? (string) $post->$column : '';
			if ( 'password' === $field ) {
				$before = '' === $before ? '' : Redactor::MASK;
				$after  = '' === $after ? '' : Redactor::MASK;
			}
			if ( 'status' === $field && $before !== $after ) {
				$before = '' === $before ? '' : self::status_label( $before );
				$after  = self::status_label( $after );
			}
			if ( null === $post || $before !== $after ) {
				$changes[] = self::change( ucfirst( str_replace( '_', ' ', $field ) ), $before, $after );
			}
		}

		if ( array_key_exists( 'featured_media', $params ) ) {
			$before = $post ? (int) get_post_thumbnail_id( $post ) : 0;
			$after  = (int) $params['featured_media'];
			if ( null === $post || $before !== $after ) {
				$changes[] = self::change( __( 'Featured image', 'agent-governance' ), $before ? (string) $before : '', $after ? (string) $after : '' );
			}
		}

		foreach ( get_object_taxonomies( $type->name, 'objects' ) as $taxonomy ) {
			$base = $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name;
			if ( empty( $taxonomy->show_in_rest ) || ! array_key_exists( $base, $params ) ) {
				continue;
			}

			$after = array_map( 'intval', (array) $params[ $base ] );
			sort( $after );
			$before = array();
			if ( $post ) {
				$terms  = wp_get_object_terms( $post->ID, $taxonomy->name, array( 'fields' => 'ids' ) );
				$before = is_array( $terms ) ? array_map( 'intval', $terms ) : array();
				sort( $before );
			}
			if ( null === $post || $before !== $after ) {
				$changes[] = self::change( (string) $taxonomy->labels->name, self::term_names( $before ), self::term_names( $after ) );
			}
		}

		return $changes;
	}

	/**
	 * Preview of a term write.
	 *
	 * @param string       $method   HTTP method.
	 * @param \WP_Taxonomy $taxonomy Taxonomy.
	 * @param int          $id       Term ID, 0 to create.
	 * @param array        $params   Request parameters.
	 * @return array|null
	 */
	private function term( string $method, $taxonomy, int $id, array $params ): ?array {
		$label = (string) ( $taxonomy->labels->singular_name ?? $taxonomy->name );
		$term  = $id ? get_term( $id, $taxonomy->name ) : null;

		if ( ! $id ) {
			if ( 'POST' !== $method ) {
				return null;
			}
			$term = null;
		} elseif ( ! $term instanceof \WP_Term ) {
			return array(
				'supported' => true,
				/* translators: %d: term ID. */
				'summary'   => sprintf( __( 'Term %d does not exist, so this call would fail.', 'agent-governance' ), $id ),
			);
		}

		$object = $term ? array(
			'type'  => $taxonomy->name,
			'id'    => $id,
			'label' => $term->name,
			'url'   => (string) get_edit_term_link( $id, $taxonomy->name ),
		) : array();

		if ( 'DELETE' === $method && $term ) {
			return array(
				'supported' => true,
				/* translators: 1: taxonomy name, 2: term name, 3: number of posts. */
				'summary'   => sprintf( _n( 'Deletes the %1$s "%2$s", used by %3$d post.', 'Deletes the %1$s "%2$s", used by %3$d posts.', (int) $term->count, 'agent-governance' ), $label, $term->name, (int) $term->count ),
				'object'    => $object,
			);
		}

		$changes = array();
		foreach ( array( 'name', 'slug', 'description', 'parent' ) as $field ) {
			if ( ! array_key_exists( $field, $params ) ) {
				continue;
			}
			$before = $term ? (string) $term->$field : '';
			$after  = (string) self::raw( $params[ $field ] );
			if ( ! $term || $before !== $after ) {
				$changes[] = self::change( ucfirst( $field ), $before, $after );
			}
		}

		return array(
			'supported' => true,
			'summary'   => $term
				/* translators: 1: taxonomy name, 2: term name. */
				? sprintf( __( 'Changes the %1$s "%2$s".', 'agent-governance' ), $label, $term->name )
				/* translators: %s: taxonomy name, such as Category. */
				: sprintf( __( 'Creates a new %s.', 'agent-governance' ), $label ),
			'object'    => $object,
			'changes'   => $changes,
		);
	}

	/**
	 * Preview of a user write.
	 *
	 * @param string $method  HTTP method.
	 * @param int    $user_id User ID.
	 * @param array  $params  Request parameters.
	 * @return array
	 */
	private function user( string $method, int $user_id, array $params ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'supported' => true,
				/* translators: %d: user ID. */
				'summary'   => sprintf( __( 'User %d does not exist, so this call would fail.', 'agent-governance' ), $user_id ),
			);
		}

		$object = array(
			'type'  => 'user',
			'id'    => $user_id,
			'label' => $user->user_login,
			'url'   => (string) get_edit_user_link( $user_id ),
		);

		if ( 'DELETE' === $method ) {
			$reassign = isset( $params['reassign'] ) ? (int) $params['reassign'] : 0;

			return array(
				'supported' => true,
				'summary'   => $reassign
					/* translators: 1: user login, 2: user ID the content goes to. */
					? sprintf( __( 'Deletes the user "%1$s" and gives their content to user %2$d.', 'agent-governance' ), $user->user_login, $reassign )
					/* translators: %s: user login. */
					: sprintf( __( 'Deletes the user "%s".', 'agent-governance' ), $user->user_login ),
				'object'    => $object,
			);
		}

		$changes = array();
		foreach ( self::USER_FIELDS as $field => $property ) {
			if ( ! array_key_exists( $field, $params ) ) {
				continue;
			}
			if ( 'password' === $field ) {
				$changes[] = self::change( __( 'Password', 'agent-governance' ), Redactor::MASK, __( '[new password]', 'agent-governance' ) );
				continue;
			}
			$before = (string) $user->$property;
			$after  = (string) self::raw( $params[ $field ] );
			if ( $before !== $after ) {
				$changes[] = self::change( ucfirst( str_replace( '_', ' ', $field ) ), $before, $after );
			}
		}
		if ( array_key_exists( 'roles', $params ) ) {
			$before = array_map( 'strval', (array) $user->roles );
			$after  = array_map( 'strval', (array) $params['roles'] );
			if ( $before !== $after ) {
				$changes[] = self::change( __( 'Roles', 'agent-governance' ), self::role_names( $before ), self::role_names( $after ) );
			}
		}

		return array(
			'supported' => true,
			/* translators: %s: user login. */
			'summary'   => sprintf( __( 'Changes the user "%s".', 'agent-governance' ), $user->user_login ),
			'object'    => $object,
			'changes'   => $changes,
		);
	}

	/**
	 * Preview of a settings write.
	 *
	 * @param array $params Request parameters.
	 * @return array
	 */
	private function settings( array $params ): array {
		$changes = array();

		foreach ( get_registered_settings() as $option => $args ) {
			if ( empty( $args['show_in_rest'] ) ) {
				continue;
			}
			$name = is_array( $args['show_in_rest'] ) && ! empty( $args['show_in_rest']['name'] ) ? (string) $args['show_in_rest']['name'] : (string) $option;
			if ( ! array_key_exists( $name, $params ) ) {
				continue;
			}

			$before = self::scalar( get_option( $option ) );
			$after  = self::scalar( $params[ $name ] );
			if ( $before !== $after ) {
				$changes[] = self::change( $name, $before, $after );
			}
		}

		return array(
			'supported' => true,
			'summary'   => $changes
				/* translators: %d: number of settings. */
				? sprintf( _n( 'Changes %d site setting.', 'Changes %d site settings.', count( $changes ), 'agent-governance' ), count( $changes ) )
				: __( 'Saves the site settings without changing any of them.', 'agent-governance' ),
			'changes'   => $changes,
		);
	}

	/**
	 * Preview of a plugin change.
	 *
	 * @param string $method HTTP method.
	 * @param string $plugin Plugin path without .php.
	 * @param array  $params Request parameters.
	 * @return array
	 */
	private function plugin( string $method, string $plugin, array $params ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$file    = $plugin . '.php';
		$plugins = get_plugins();
		$name    = isset( $plugins[ $file ] ) ? (string) $plugins[ $file ]['Name'] : $plugin;
		$active  = is_plugin_active( $file );

		if ( 'DELETE' === $method ) {
			return array(
				'supported' => true,
				/* translators: %s: plugin name. */
				'summary'   => sprintf( __( 'Deletes the plugin "%s" and its files.', 'agent-governance' ), $name ),
			);
		}

		$changes = array();
		if ( isset( $params['status'] ) ) {
			$labels = array(
				'active'         => __( 'Active', 'agent-governance' ),
				'inactive'       => __( 'Inactive', 'agent-governance' ),
				'network-active' => __( 'Network active', 'agent-governance' ),
			);
			$before = $active ? 'active' : 'inactive';
			$after  = (string) $params['status'];
			if ( $before !== $after ) {
				$changes[] = self::change( __( 'Status', 'agent-governance' ), $labels[ $before ], $labels[ $after ] ?? $after );
			}
		}

		return array(
			'supported' => true,
			/* translators: %s: plugin name. */
			'summary'   => sprintf( __( 'Changes the plugin "%s".', 'agent-governance' ), $name ),
			'changes'   => $changes,
		);
	}

	/**
	 * A preview in its stored shape.
	 *
	 * @param array $preview Preview.
	 * @return array
	 */
	public static function normalize( array $preview ): array {
		$changes = array();
		foreach ( isset( $preview['changes'] ) && is_array( $preview['changes'] ) ? $preview['changes'] : array() as $change ) {
			if ( is_array( $change ) && isset( $change['field'] ) ) {
				$changes[] = self::change( (string) $change['field'], self::scalar( $change['before'] ?? '' ), self::scalar( $change['after'] ?? '' ) );
			}
		}

		$object = isset( $preview['object'] ) && is_array( $preview['object'] ) && $preview['object'] ? array(
			'type'  => (string) ( $preview['object']['type'] ?? '' ),
			'id'    => (int) ( $preview['object']['id'] ?? 0 ),
			'label' => (string) ( $preview['object']['label'] ?? '' ),
			'url'   => esc_url_raw( (string) ( $preview['object']['url'] ?? '' ) ),
		) : null;

		return array(
			'supported' => ! empty( $preview['supported'] ),
			'summary'   => (string) ( $preview['summary'] ?? '' ),
			'object'    => $object,
			'changes'   => $changes,
		);
	}

	/**
	 * One field change.
	 *
	 * @param string $field  Field.
	 * @param string $before Before.
	 * @param string $after  After.
	 * @return array{field: string, before: string, after: string}
	 */
	private static function change( string $field, string $before, string $after ): array {
		return array(
			'field'  => $field,
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * The raw value of a REST field, which may be { raw: ... }.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function raw( $value ): string {
		if ( is_array( $value ) && array_key_exists( 'raw', $value ) ) {
			$value = $value['raw'];
		}

		return self::scalar( $value );
	}

	/**
	 * Any value as a string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		return (string) wp_json_encode( $value );
	}

	/**
	 * A post status as people read it, such as "Published" for publish.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$object = get_post_status_object( $status );

		return $object && ! empty( $object->label ) ? (string) $object->label : $status;
	}

	/**
	 * Role names as people read them, such as "Administrator" for administrator.
	 *
	 * @param string[] $roles Role slugs.
	 * @return string
	 */
	private static function role_names( array $roles ): string {
		$names = wp_roles()->role_names;
		$out   = array();
		foreach ( $roles as $role ) {
			$out[] = isset( $names[ $role ] ) ? translate_user_role( (string) $names[ $role ] ) : $role;
		}

		return implode( ', ', $out );
	}

	/**
	 * Term names for IDs.
	 *
	 * @param int[] $ids Term IDs.
	 * @return string
	 */
	private static function term_names( array $ids ): string {
		$names = array();
		foreach ( $ids as $id ) {
			$term    = get_term( $id );
			$names[] = $term instanceof \WP_Term ? $term->name : '#' . $id;
		}

		return implode( ', ', $names );
	}

	/**
	 * The REST path of a post type or taxonomy, such as /wp/v2/posts.
	 *
	 * @param \WP_Post_Type|\WP_Taxonomy $type Post type or taxonomy.
	 * @return string
	 */
	private static function rest_path( $type ): string {
		$namespace = ! empty( $type->rest_namespace ) ? (string) $type->rest_namespace : 'wp/v2';
		$base      = ! empty( $type->rest_base ) ? (string) $type->rest_base : (string) $type->name;

		return '/' . trim( $namespace, '/' ) . '/' . $base;
	}
}
