<?php
/**
 * Plugin Name: Agent Governance e2e fixture
 * Description: Throwaway abilities and an email catcher for the end-to-end tests. Copied into mu-plugins by the tests.
 *
 * @package AgentGovernance
 */

// phpcs:ignoreFile

// Application passwords need HTTPS unless the site is local; the test site is plain HTTP.
add_filter( 'wp_is_application_passwords_available', '__return_true' );

// Catch email instead of sending it.
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$sent   = get_option( 'agg_fixture_mail', array() );
		$sent[] = array(
			'to'      => (array) $atts['to'],
			'subject' => (string) $atts['subject'],
		);
		update_option( 'agg_fixture_mail', $sent, false );
		return true;
	},
	10,
	2
);

add_action(
	'wp_abilities_api_categories_init',
	static function () {
		wp_register_ability_category(
			'agg-fixture',
			array(
				'label'       => 'Agent Governance fixture',
				'description' => 'Abilities for the end-to-end tests.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	static function () {
		$meta = static function ( array $annotations ) {
			return array(
				'annotations'  => $annotations,
				'show_in_rest' => true,
				'public'       => true,
				'mcp'          => array( 'public' => true ),
			);
		};
		$post_input = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'    => array( 'type' => 'integer' ),
				'title' => array( 'type' => 'string' ),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
		$can_edit = static function ( $input = null ) {
			return current_user_can( 'edit_post', (int) ( (array) $input )['id'] );
		};

		wp_register_ability(
			'agg-fixture/read-post',
			array(
				'label'               => 'Read a post',
				'description'         => 'Returns a post title.',
				'category'            => 'agg-fixture',
				'input_schema'        => $post_input,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) {
					return array( 'title' => get_the_title( (int) $input['id'] ) );
				},
				'permission_callback' => $can_edit,
				'meta'                => $meta( array( 'readonly' => true, 'idempotent' => true ) ),
			)
		);

		wp_register_ability(
			'agg-fixture/rename-post',
			array(
				'label'               => 'Rename a post',
				'description'         => 'Changes a post title.',
				'category'            => 'agg-fixture',
				'input_schema'        => $post_input,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) {
					wp_update_post( array( 'ID' => (int) $input['id'], 'post_title' => (string) $input['title'] ) );
					return array( 'title' => get_the_title( (int) $input['id'] ) );
				},
				'permission_callback' => $can_edit,
				'meta'                => $meta( array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ) ),
			)
		);

		// A destructive ability that gives no preview of its own.
		wp_register_ability(
			'agg-fixture/forget-note',
			array(
				'label'               => 'Forget a note',
				'description'         => 'Removes the note saved on a post.',
				'category'            => 'agg-fixture',
				'input_schema'        => $post_input,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) {
					return array( 'forgotten' => delete_post_meta( (int) $input['id'], 'agg_note' ) );
				},
				'permission_callback' => $can_edit,
				'meta'                => $meta( array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ) ),
			)
		);

		wp_register_ability(
			'agg-fixture/trash-post',
			array(
				'label'               => 'Trash a post',
				'description'         => 'Moves a post to the trash.',
				'category'            => 'agg-fixture',
				'input_schema'        => $post_input,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => static function ( $input ) {
					$post = wp_trash_post( (int) $input['id'] );
					return array( 'trashed' => (bool) $post );
				},
				'permission_callback' => $can_edit,
				'meta'                => $meta( array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ) ),
			)
		);
	}
);

// A preview for the fixture's destructive ability.
add_filter(
	'agent_governance_dry_run',
	static function ( $preview, $call ) {
		if ( 'agg-fixture/trash-post' !== $call['target'] ) {
			return $preview;
		}
		$id   = (int) ( (array) $call['input'] )['id'];
		$post = get_post( $id );
		return array(
			'summary' => sprintf( 'Moves "%s" to the trash.', $post ? $post->post_title : '#' . $id ),
			'changes' => array(
				array(
					'field'  => 'Status',
					'before' => $post ? $post->post_status : '',
					'after'  => 'trash',
				),
			),
		);
	},
	10,
	2
);
