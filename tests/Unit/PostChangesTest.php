<?php
/**
 * Tests for agent changes in the editor: the index from posts to calls, the
 * panel's state, undoing one post and deciding a held request there.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Abilities;
use AryanX\Axle\AgentGovernance\Editor;
use AryanX\Axle\AgentGovernance\Log;
use AryanX\Axle\AgentGovernance\PostIndex;
use AryanX\Axle\AgentGovernance\Requests;
use AryanX\Axle\AgentGovernance\Rollback;
use AryanX\Axle\AgentGovernance\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Covers PostIndex, Editor and Rollback::undo_post().
 */
final class PostChangesTest extends AgentTestCase {

	/** @var array<int, object> Posts by ID. */
	private $posts = array();

	/** @var array<int, array<string, array<int, bool>>> What each user may do: user => cap => post => bool. */
	private $user_caps = array();

	/** @var PostIndex */
	private $index;

	/** @var Editor */
	private $editor;

	protected function setUp(): void {
		parent::setUp();

		$this->users[3] = (object) array(
			'ID'           => 3,
			'user_login'   => 'second',
			'user_email'   => 'second@example.com',
			'display_name' => 'Second',
			'roles'        => array( 'editor' ),
		);
		$this->posts = array(
			5 => $this->post( 5, 'Agent title', 'Agent body', 'publish', gmdate( 'Y-m-d H:i:s', time() - 7200 ) ),
			6 => $this->post( 6, 'Made by agent', '', 'publish', gmdate( 'Y-m-d H:i:s', time() - 7200 ) ),
		);

		// User 1 may edit and delete both posts; user 3 may edit neither; user 2 only post 6.
		$this->user_caps = array(
			1 => array(
				'edit_post'   => array( 5 => true, 6 => true ),
				'delete_post' => array( 5 => true, 6 => true ),
			),
			2 => array(
				'edit_post'   => array( 6 => true ),
				'delete_post' => array( 6 => true ),
			),
			3 => array(),
		);
		Functions\when( 'user_can' )->alias(
			function ( $user, $cap, $id = 0 ) {
				if ( 'agent_governance_approve' === $cap ) {
					return in_array( (int) $user, array( 1, 3 ), true );
				}
				return ! empty( $this->user_caps[ (int) $user ][ $cap ][ (int) $id ] );
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $cap, $id = 0 ) {
				return $this->can_now( $cap, (int) $id );
			}
		);
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return $this->posts[ (int) ( is_object( $id ) ? $id->ID : $id ) ] ?? null;
			}
		);
		Functions\when( 'get_the_title' )->alias(
			static function ( $post ) {
				return $post->post_title;
			}
		);
		Functions\when( 'post_type_exists' )->alias(
			static function ( $type ) {
				return 'post' === $type;
			}
		);
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'wp_revisions_enabled' )->justReturn( true );
		Functions\when( 'human_time_diff' )->justReturn( '2 hours' );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '?' . http_build_query( $args );
			}
		);

		$this->index  = new PostIndex( new Settings() );
		$this->editor = new Editor( $this->index, $this->log, $this->requests, $this->approvals, $this->rollback, $this->classifier );
	}

	/**
	 * Whether the signed-in test user has a capability, for a post.
	 *
	 * @param string $cap Capability.
	 * @param int    $id  Post ID.
	 * @return bool
	 */
	private function can_now( string $cap, int $id ): bool {
		if ( 'agent_governance_approve' === $cap ) {
			return ! empty( $this->caps[ $cap ] ) || in_array( $this->user_id, array( 1, 3 ), true );
		}

		return ! empty( $this->user_caps[ $this->user_id ][ $cap ][ $id ] );
	}

	/**
	 * A post object.
	 *
	 * @param int    $id       ID.
	 * @param string $title    Title.
	 * @param string $content  Content.
	 * @param string $status   Status.
	 * @param string $modified Last modified, GMT.
	 * @return object
	 */
	private function post( int $id, string $title, string $content, string $status, string $modified ): object {
		return (object) array(
			'ID'               => $id,
			'post_type'        => 'post',
			'post_title'       => $title,
			'post_content'     => $content,
			'post_excerpt'     => '',
			'post_status'      => $status,
			'post_modified_gmt' => $modified,
		);
	}

	/**
	 * A log row of a call that changed posts.
	 *
	 * @param array $changes The changes.
	 * @param array $extra   Row overrides.
	 * @return array
	 */
	private function call( array $changes, array $extra = array() ): array {
		return $this->log_row(
			array_merge(
				array(
					'id'      => 31,
					'agent'   => 'Content assistant',
					'created' => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
					'changes' => (string) wp_json_encode( $changes ),
				),
				$extra
			)
		);
	}

	public function test_a_logged_call_indexes_each_post_it_changed_once(): void {
		$row = $this->call(
			array(
				array( 'post_id' => 5, 'action' => 'updated', 'revision_id' => 41 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'post_id' => 5, 'action' => 'updated', 'revision_id' => 42 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'post_id' => 6, 'action' => 'created' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'post_id' => 9, 'action' => 'deleted' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			)
		);

		$this->index->record( $row );

		$rows = $this->wpdb->inserted['wp_agent_governance_posts'];
		$this->assertSame( array( 5, 6 ), array_column( $rows, 'post_id' ), 'A post deleted for good has no editor, so it is not indexed.' );
		$this->assertSame( array( 31, 31 ), array_column( $rows, 'log_id' ) );
		$this->assertSame( array( 'updated', 'created' ), array_column( $rows, 'action' ) );
	}

	public function test_calls_that_changed_nothing_or_did_not_run_are_not_indexed(): void {
		$this->index->record( $this->call( array(), array( 'changes' => null ) ) );
		$this->index->record( $this->call( array( array( 'post_id' => 5, 'action' => 'updated' ) ), array( 'decision' => 'denied' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->index->record( $this->call( array( array( 'post_id' => 5, 'action' => 'updated' ) ), array( 'decision' => 'held' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertArrayNotHasKey( 'wp_agent_governance_posts', $this->wpdb->inserted );
	}

	public function test_the_index_listens_to_the_log_and_to_session_rollbacks(): void {
		Actions\expectAdded( 'agent_governance_call_logged' )->once();
		Actions\expectAdded( 'agent_governance_session_rolled_back' )->once();
		Actions\expectAdded( 'agent_governance_cleaned_up' )->once();

		$this->index->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_a_session_rollback_marks_the_posts_it_put_back_as_undone(): void {
		$this->index->session_rolled_back(
			's1',
			array(),
			array(
				'rows'  => array( 31, 32 ),
				'posts' => array(
					array( 'post_id' => 5, 'possible' => true, 'denied' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'post_id' => 6, 'possible' => true, 'denied' => true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
			)
		);

		$sql = $this->wpdb->queries[0];
		$this->assertStringContainsString( "log_id IN ('31','32')", $sql );
		$this->assertStringContainsString( "post_id IN ('5')", $sql, 'A post the person could not touch stays open.' );
	}

	public function test_the_panel_says_which_agent_changed_the_post_and_what(): void {
		$this->posts[5]->post_title = 'Agent title';
		$this->wpdb->returns['get_row'][] = array( 'id' => 1, 'post_id' => 5, 'log_id' => 31, 'created' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'action' => 'updated', 'undone' => null, 'undone_by' => 0 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->returns['get_row'][] = $this->call( array( array( 'post_id' => 5, 'action' => 'updated', 'revision_id' => 41, 'status' => 'publish' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Functions\when( 'wp_get_post_revision' )->justReturn( (object) array( 'post_title' => 'Human title', 'post_content' => 'Human body', 'post_excerpt' => '' ) );
		Functions\when( 'wp_get_post_revisions' )->justReturn( array() );

		$state = $this->editor->state( $this->posts[5], 1 );

		$this->assertSame( 'Content assistant (admin)', $state['change']['agent'] );
		$this->assertSame( '2 hours ago', $state['change']['ago'] );
		$this->assertTrue( $state['change']['can_undo'] );
		$this->assertFalse( $state['change']['edited_since'] );
		$this->assertSame( array( 'Title', 'Content' ), array_column( $state['change']['fields'], 'field' ) );
		$this->assertSame( 'Human title', $state['change']['fields'][0]['before'] );
		$this->assertSame( 'Agent title', $state['change']['fields'][0]['after'] );
	}

	public function test_a_post_nobody_changed_has_an_empty_panel(): void {
		$state = $this->editor->state( $this->posts[5], 1 );

		$this->assertNull( $state['change'] );
		$this->assertSame( array(), $state['requests'] );
	}

	public function test_a_save_after_the_call_is_reported(): void {
		$this->posts[5]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$this->assertTrue( Rollback::edited_since( $this->posts[5], $this->call( array() ) ) );

		$this->posts[5]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', time() - 3600 + 2 );
		$this->assertFalse( Rollback::edited_since( $this->posts[5], $this->call( array() ) ), 'A save in the same second as the call is the call.' );
	}

	public function test_undoing_one_post_restores_it_and_keeps_the_current_version_as_a_revision(): void {
		$order = array();
		Functions\when( 'wp_get_post_revision' )->justReturn( (object) array( 'ID' => 41 ) );
		Functions\expect( 'wp_save_post_revision' )->once()->with( 5 )->andReturnUsing(
			static function () use ( &$order ) {
				$order[] = 'saved';
				return 99;
			}
		);
		Functions\expect( 'wp_restore_post_revision' )->once()->with( 41 )->andReturnUsing(
			static function () use ( &$order ) {
				$order[] = 'restored';
				return 41;
			}
		);
		$row = $this->call( array( array( 'post_id' => 5, 'action' => 'updated', 'revision_id' => 41, 'status' => 'publish' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$result = $this->rollback->undo_post( $row, 5 );

		$this->assertSame( 'restored', $result['action'] );
		$this->assertSame( array( 'saved', 'restored' ), $order, 'What the post is now is saved first, so the undo can be undone.' );
	}

	public function test_undoing_a_post_the_agent_created_moves_it_to_the_trash(): void {
		Functions\expect( 'wp_trash_post' )->once()->with( 6 )->andReturn( $this->posts[6] );

		$result = $this->rollback->undo_post( $this->call( array( array( 'post_id' => 6, 'action' => 'created' ) ) ), 6 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 'trashed', $result['action'] );
	}

	public function test_an_edit_after_the_agent_blocks_the_undo_unless_forced(): void {
		$this->posts[5]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', time() - 60 );
		Functions\when( 'wp_get_post_revision' )->justReturn( (object) array( 'ID' => 41 ) );
		Functions\when( 'wp_save_post_revision' )->justReturn( 99 );
		Functions\expect( 'wp_restore_post_revision' )->once()->with( 41 )->andReturn( 41 );
		$row = $this->call( array( array( 'post_id' => 5, 'action' => 'updated', 'revision_id' => 41, 'status' => 'publish' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$refused = $this->rollback->undo_post( $row, 5 );
		$this->assertSame( 'agent_governance_edited_since', $refused->get_error_code() );
		$this->assertSame( 409, $refused->get_error_data()['status'] );

		$this->assertSame( 'restored', $this->rollback->undo_post( $row, 5, true )['action'] );
	}

	public function test_a_person_who_cannot_edit_the_post_cannot_undo_it_and_learns_nothing(): void {
		$this->user_id = 3;
		Functions\expect( 'wp_restore_post_revision' )->never();
		Functions\when( 'wp_get_post_revision' )->justReturn( (object) array( 'ID' => 41 ) );
		$row = $this->call( array( array( 'post_id' => 5, 'action' => 'updated', 'revision_id' => 41 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$error = $this->rollback->undo_post( $row, 5 );

		$this->assertSame( 'agent_governance_forbidden', $error->get_error_code() );
		$this->assertStringNotContainsString( 'Agent title', $error->get_error_message() );
	}

	public function test_the_undo_route_refuses_a_change_that_was_replaced_by_a_newer_one(): void {
		$this->wpdb->returns['get_row'][] = array( 'id' => 2, 'post_id' => 5, 'log_id' => 40, 'created' => gmdate( 'Y-m-d H:i:s' ), 'action' => 'updated', 'undone' => null, 'undone_by' => 0 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$error = $this->editor->undo_change( 5, 31, false, 1 );

		$this->assertSame( 'agent_governance_changed_again', $error->get_error_code() );
	}

	public function test_a_successful_undo_marks_the_index_and_fires_the_action(): void {
		Functions\when( 'wp_get_post_revision' )->justReturn( (object) array( 'ID' => 41 ) );
		Functions\when( 'wp_save_post_revision' )->justReturn( 99 );
		Functions\when( 'wp_restore_post_revision' )->justReturn( 41 );
		$this->wpdb->returns['get_row'][] = array( 'id' => 2, 'post_id' => 5, 'log_id' => 31, 'created' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'action' => 'updated', 'undone' => null, 'undone_by' => 0 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->returns['get_row'][] = $this->call( array( array( 'post_id' => 5, 'action' => 'updated', 'revision_id' => 41, 'status' => 'publish' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->returns['query'][]   = 1;
		Actions\expectDone( 'agent_governance_change_undone' )->once()->with( 5, 31, 1, 'restored' );

		$result = $this->editor->undo_change( 5, 31, false, 1 );

		$this->assertTrue( $result['undone'] );
		$this->assertStringContainsString( 'SET undone', end( $this->wpdb->queries ) );
		$this->assertStringContainsString( "undone_by = '1'", end( $this->wpdb->queries ) );
	}

	public function test_the_undo_capability_filter_can_deny_it(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'agent_governance_can_undo_change' === $hook ? false : $value;
			}
		);
		$this->wpdb->returns['get_row'][] = array( 'id' => 2, 'post_id' => 5, 'log_id' => 31, 'created' => gmdate( 'Y-m-d H:i:s', time() - 3600 ), 'action' => 'updated', 'undone' => null, 'undone_by' => 0 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->wpdb->returns['get_row'][] = $this->call( array( array( 'post_id' => 5, 'action' => 'updated', 'revision_id' => 41 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 'agent_governance_forbidden', $this->editor->undo_change( 5, 31, false, 1 )->get_error_code() );
	}

	/**
	 * A held request on post 5 or 6, as the table stores it.
	 *
	 * @param int    $id   Request ID.
	 * @param int    $post Post ID.
	 * @param string $what The summary, which names the post.
	 * @return array
	 */
	private function held( int $id, int $post, string $what ): array {
		return $this->request_row(
			array(
				'id'          => $id,
				'object_type' => 'post',
				'object_id'   => $post,
				'dry_run'     => (string) wp_json_encode(
					array(
						'supported' => true,
						'summary'   => $what,
						'object'    => array( 'type' => 'post', 'id' => $post ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						'changes'   => array( array( 'field' => 'status', 'before' => 'publish', 'after' => 'trash' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					)
				),
			)
		);
	}

	public function test_an_approver_sees_the_request_held_on_the_post_with_its_changes(): void {
		$this->wpdb->returns['get_results'][] = array( $this->held( 12, 5, 'Moves "Agent title" to the trash.' ) );

		$state = $this->editor->state( $this->posts[5], 1 );

		$this->assertCount( 1, $state['requests'] );
		$this->assertSame( 12, $state['requests'][0]['id'] );
		$this->assertSame( 'Moves "Agent title" to the trash.', $state['requests'][0]['summary'] );
		$this->assertSame( 'status', $state['requests'][0]['fields'][0]['field'] );
	}

	public function test_an_approver_who_cannot_edit_the_post_sees_no_held_request_and_no_title(): void {
		$this->wpdb->returns['get_results'][] = array( $this->held( 12, 5, 'Moves "Agent title" to the trash.' ) );

		$state = $this->editor->state( $this->posts[5], 3 );

		$this->assertSame( array(), $state['requests'] );
		$this->assertStringNotContainsString( 'Agent title', (string) wp_json_encode( $state ) );
	}

	public function test_someone_who_cannot_approve_sees_no_held_request_even_when_they_can_edit(): void {
		$this->wpdb->returns['get_results'][] = array( $this->held( 12, 6, 'Moves it to the trash.' ) );

		$this->assertSame( array(), $this->editor->state( $this->posts[6], 2 )['requests'] );
		$this->assertStringNotContainsString( 'agent_governance_requests', implode( ' ', $this->wpdb->queries ), 'The queue is not even looked at.' );
	}

	public function test_a_request_that_expired_is_not_offered(): void {
		$old = $this->held( 12, 5, 'x' );
		$old['created'] = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$this->wpdb->returns['get_results'][] = array( $old );

		$this->assertSame( array(), $this->editor->state( $this->posts[5], 1 )['requests'] );
	}

	public function test_the_routes_need_edit_access_to_the_post_they_name(): void {
		$route = static function ( int $id ) {
			return array( 'id' => $id );
		};

		$this->user_id = 1;
		$this->assertTrue( $this->editor->can_edit( $route( 5 ) ) );
		$this->user_id = 3;
		$this->assertFalse( $this->editor->can_edit( $route( 5 ) ), 'An approver who cannot edit the post gets a 403 and no data.' );
		$this->user_id = 2;
		$this->assertFalse( $this->editor->can_edit( $route( 5 ) ) );
		$this->assertTrue( $this->editor->can_edit( $route( 6 ) ) );
		$this->assertFalse( $this->editor->can_edit( $route( 404 ) ) );
	}

	public function test_deciding_in_the_editor_is_refused_to_an_agent(): void {
		$this->as_app_password();

		$result = $this->editor->decide( array( 'id' => 12, 'decision' => 'approve' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 'agent_governance_agent_cannot_decide', $result->get_error_code() );
	}

	public function test_deciding_in_the_editor_goes_through_the_same_object_check(): void {
		$this->user_id = 3;
		$this->wpdb->returns['get_row'][] = $this->held( 12, 5, 'x' );

		$result = $this->editor->decide( array( 'id' => 12, 'decision' => 'reject', 'reason' => 'No.' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 'agent_governance_forbidden', $result->get_error_code() );
		$this->assertSame( array(), $this->wpdb->updated );
	}

	public function test_a_held_request_stores_the_object_it_is_about(): void {
		$id = $this->requests->create( array( 'dry_run' => $this->held( 1, 5, 'x' )['dry_run'] ) );

		$this->assertGreaterThan( 0, $id );
		$row = $this->wpdb->rows( 'requests' )[0];
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( 5, $row['object_id'] );
	}

	public function test_a_request_without_an_object_stores_none(): void {
		$this->requests->create( array( 'dry_run' => '{"supported":false,"object":null}' ) );

		$row = $this->wpdb->rows( 'requests' )[0];
		$this->assertSame( '', $row['object_type'] );
		$this->assertSame( 0, $row['object_id'] );
	}

	public function test_the_abilities_follow_the_same_rules(): void {
		$abilities = new Abilities( $this->requests, $this->approvals, $this->classifier, null, null, null, $this->editor );

		$this->as_app_password();
		$refused = $abilities->undo_post_change( array( 'post_id' => 5 ) );
		$this->assertSame( 'agent_governance_agent_cannot_decide', $refused->get_error_code(), 'An agent cannot undo.' );
	}

	public function test_the_undone_by_column_is_exported_and_erased(): void {
		$this->wpdb->returns['get_results'][] = array( array( 'id' => 4, 'post_id' => 5, 'undone' => '2026-11-20 10:00:00', 'undone_by' => 2 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertCount( 1, PostIndex::undone_by( 2, 1, 100 ) );

		PostIndex::anonymize_user( 2, 500 );
		$this->assertStringContainsString( 'SET undone_by = 0', end( $this->wpdb->queries ) );
	}

	public function test_old_index_rows_leave_with_the_log(): void {
		$this->index->purge_old();

		$this->assertStringContainsString( 'DELETE FROM', $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'agent_governance_posts', $this->wpdb->queries[0] );
	}

	public function test_upgrading_fills_the_object_of_requests_that_still_wait(): void {
		$this->wpdb->returns['get_results'][] = array(
			array( 'id' => 12, 'dry_run' => $this->held( 12, 5, 'x' )['dry_run'] ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'id' => 13, 'dry_run' => '{"supported":false,"object":null}' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);

		$method = new \ReflectionMethod( \AryanX\Axle\AgentGovernance\Schema::class, 'backfill_objects' );
		$method->setAccessible( true );
		$method->invoke( null );

		$this->assertCount( 1, $this->wpdb->updated, 'A request with no object stays as it is.' );
		$this->assertSame( array( 'object_type' => 'post', 'object_id' => 5 ), $this->wpdb->updated[0]['data'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( array( 'id' => 12 ), $this->wpdb->updated[0]['where'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}
}
