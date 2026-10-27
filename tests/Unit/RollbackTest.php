<?php
/**
 * Tests for recording post changes and rolling back a session.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Changes;
use AryanX\Axle\AgentGovernance\Rollback;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Changes and Rollback.
 */
final class RollbackTest extends AgentTestCase {

	/** @var array<int, object> Posts by ID. */
	private $posts = array();

	protected function setUp(): void {
		parent::setUp();

		$this->posts = array(
			5 => (object) array(
				'ID'           => 5,
				'post_type'    => 'post',
				'post_title'   => 'Agent edit',
				'post_content' => 'New body',
				'post_excerpt' => '',
				'post_status'  => 'draft',
			),
			6 => (object) array(
				'ID'           => 6,
				'post_type'    => 'post',
				'post_title'   => 'Made by agent',
				'post_content' => '',
				'post_excerpt' => '',
				'post_status'  => 'publish',
			),
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
		Functions\when( 'post_type_supports' )->justReturn( true );
		Functions\when( 'wp_revisions_enabled' )->justReturn( true );
	}

	/**
	 * Opens an agent call frame.
	 */
	private function open_frame(): void {
		$this->context->push_frame( array( 'call' => array() ) );
	}

	/**
	 * Changes recorded in the open frame.
	 *
	 * @return array[]
	 */
	private function recorded(): array {
		return $this->context->pop_frame( 1 )['changes'];
	}

	public function test_changes_hooks(): void {
		$changes = new Changes( $this->context );
		$changes->register();

		$this->assertSame( 1, has_action( 'pre_post_update', array( $changes, 'before_update' ) ) );
		$this->assertSame( 10, has_action( 'wp_insert_post', array( $changes, 'inserted' ) ) );
		$this->assertSame( 10, has_action( 'before_delete_post', array( $changes, 'before_delete' ) ) );
	}

	public function test_nothing_is_recorded_outside_an_agent_call(): void {
		Functions\expect( 'wp_save_post_revision' )->never();
		$changes = new Changes( $this->context );

		$changes->before_update( 5 );
		$changes->inserted( 6, $this->posts[6], false );

		$this->assertFalse( $this->context->has_frame() );
	}

	public function test_an_update_saves_the_current_state_as_a_revision_first(): void {
		Functions\expect( 'wp_save_post_revision' )->once()->with( 5 )->andReturn( 41 );
		$changes = new Changes( $this->context );
		$this->open_frame();

		$changes->before_update( 5 );
		$changes->before_update( 5 );

		$recorded = $this->recorded();
		$this->assertCount( 1, $recorded, 'Once per post per call.' );
		$this->assertSame( 'updated', $recorded[0]['action'] );
		$this->assertSame( 41, $recorded[0]['revision_id'] );
		$this->assertSame( 'draft', $recorded[0]['status'] );
		$this->assertNull( $recorded[0]['fields'] );
	}

	public function test_without_changes_the_latest_revision_is_the_current_state(): void {
		Functions\when( 'wp_save_post_revision' )->justReturn( null );
		Functions\expect( 'wp_get_post_revisions' )->once()->andReturn( array( 39 ) );
		$changes = new Changes( $this->context );
		$this->open_frame();

		$changes->before_update( 5 );

		$this->assertSame( 39, $this->recorded()[0]['revision_id'] );
	}

	public function test_post_types_without_revisions_keep_a_copy_of_the_fields(): void {
		Functions\when( 'post_type_supports' )->justReturn( false );
		$changes = new Changes( $this->context );
		$this->open_frame();

		$changes->before_update( 5 );

		$recorded = $this->recorded()[0];
		$this->assertSame( 0, $recorded['revision_id'] );
		$this->assertSame( 'New body', $recorded['fields']['post_content'] );
	}

	public function test_creations_and_permanent_deletes_are_recorded(): void {
		$changes = new Changes( $this->context );
		$this->open_frame();

		$changes->inserted( 6, $this->posts[6], false );
		$changes->inserted( 6, $this->posts[6], true );
		$changes->before_delete( 5, $this->posts[5] );

		$recorded = $this->recorded();
		$this->assertSame( array( 'created', 'deleted' ), array_column( $recorded, 'action' ) );
	}

	public function test_the_untracked_post_types_filter(): void {
		Filters\expectApplied( 'agent_governance_untracked_post_types' )->once()->andReturn( array( 'post' ) );
		$changes = new Changes( $this->context );
		$this->open_frame();

		$changes->inserted( 6, $this->posts[6], false );

		$this->assertSame( array(), $this->recorded() );
	}

	public function test_the_plan_restores_edits_trashes_creations_and_lists_the_rest(): void {
		Functions\when( 'wp_get_post_revision' )->justReturn( (object) array( 'ID' => 41 ) );
		$rows = array(
			$this->log_row( array( 'id' => 1, 'kind' => 'readonly', 'decision' => 'allowed' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->log_row( array( 'id' => 2, 'decision' => 'denied' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->log_row(
				array(
					'id'      => 3,
					'changes' => '[{"post_id":5,"action":"updated","revision_id":41,"status":"publish","title":"Original"}]',
				)
			),
			$this->log_row(
				array(
					'id'      => 4,
					'changes' => '[{"post_id":5,"action":"updated","revision_id":45,"status":"draft"},{"post_id":6,"action":"created","title":"Made by agent"}]',
				)
			),
			$this->log_row( array( 'id' => 5, 'target' => '/wp/v2/settings', 'changes' => null ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->log_row( array( 'id' => 6, 'target' => '/wp/v2/users/3', 'result' => 'rest_forbidden' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->log_row( array( 'id' => 7, 'target' => 'agent-governance/decide-request', 'source' => 'ability' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->log_row( array( 'id' => 8, 'changes' => '[{"post_id":9,"action":"deleted","title":"Gone"}]' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->log_row( array( 'id' => 9, 'rolled_back' => '2026-10-23 00:00:00', 'changes' => '[{"post_id":6,"action":"updated"}]' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);

		$plan = $this->rollback->plan( $rows );

		$this->assertSame( array( 3, 4, 5, 6, 7, 8 ), $plan['rows'] );
		$this->assertCount( 2, $plan['posts'] );
		$this->assertSame( 'restore', $plan['posts'][0]['action'] );
		$this->assertSame( 41, $plan['posts'][0]['revision_id'], 'The earliest change in the session wins.' );
		$this->assertSame( 'publish', $plan['posts'][0]['status'] );
		$this->assertTrue( $plan['posts'][0]['possible'] );
		$this->assertSame( 'trash', $plan['posts'][1]['action'] );
		$this->assertTrue( $plan['posts'][1]['possible'] );
		$this->assertSame( array( '/wp/v2/settings', '/wp/v2/posts/5' ), array_column( $plan['other'], 'target' ) );
		$this->assertStringContainsString( 'deleted permanently', $plan['other'][1]['note'] );
	}

	public function test_the_plan_skips_what_cannot_be_rolled_back(): void {
		Functions\when( 'wp_get_post_revision' )->justReturn( null );
		$this->posts[6]->post_status = 'trash';
		$rows                        = array(
			$this->log_row( array( 'changes' => '[{"post_id":5,"action":"updated","revision_id":41,"status":"publish"}]' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->log_row( array( 'changes' => '[{"post_id":6,"action":"created"},{"post_id":77,"action":"updated","revision_id":1}]' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->log_row( array( 'changes' => '[{"post_id":8,"action":"updated","revision_id":0}]' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
		$this->posts[8] = (object) array(
			'ID'          => 8,
			'post_title'  => 'No revisions',
			'post_status' => 'publish',
		);

		$plan = $this->rollback->plan( $rows );

		$this->assertSame( array( false, false, false, false ), array_column( $plan['posts'], 'possible' ) );
		$this->assertSame( 'The revision saved before the change was deleted.', $plan['posts'][0]['note'] );
		$this->assertSame( 'Already in the trash.', $plan['posts'][1]['note'] );
		$this->assertSame( 'The post no longer exists.', $plan['posts'][2]['note'] );
		$this->assertSame( 'Nothing was saved to restore from.', $plan['posts'][3]['note'] );
	}

	public function test_run_restores_trashes_marks_and_fires_the_action(): void {
		Functions\expect( 'wp_untrash_post' )->once()->with( 5 );
		Functions\expect( 'wp_restore_post_revision' )->once()->with( 41 )->andReturn( 41 );
		Functions\expect( 'wp_update_post' )->once()->with( array( 'ID' => 5, 'post_status' => 'publish' ), true )->andReturn( 5 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Functions\expect( 'wp_trash_post' )->once()->with( 6 )->andReturn( $this->posts[6] );
		$this->posts[5]->post_status = 'trash';
		$plan                        = array(
			'posts' => array(
				array(
					'post_id'     => 5,
					'action'      => 'restore',
					'revision_id' => 41,
					'status'      => 'publish',
					'fields'      => null,
					'possible'    => true,
				),
				array(
					'post_id'  => 6,
					'action'   => 'trash',
					'possible' => true,
				),
				array(
					'post_id'  => 7,
					'action'   => 'trash',
					'possible' => false,
				),
			),
			'other' => array( array( 'target' => '/wp/v2/settings' ) ),
			'rows'  => array( 3, 4 ),
		);
		Actions\expectDone( 'agent_governance_session_rolled_back' )->once()->with(
			's1',
			array(
				'restored' => 1,
				'trashed'  => 1,
				'failed'   => 0,
				'skipped'  => 2,
			),
			$plan
		);

		$result = $this->rollback->run( $plan, 's1' );

		$this->assertSame( 1, $result['restored'] );
		$this->assertStringContainsString( 'SET rolled_back', $this->wpdb->queries[0] );
		$this->assertStringContainsString( "IN ('3','4')", $this->wpdb->queries[0] );
	}

	public function test_run_restores_copied_fields_without_a_revision(): void {
		Functions\when( 'wp_slash' )->returnArg( 1 );
		Functions\expect( 'wp_update_post' )->once()->with( Mockery::on( static function ( $args ) { return 'Old' === $args['post_title'] && 5 === $args['ID']; } ), true )->andReturn( 5 ); // phpcs:ignore
		Functions\expect( 'wp_restore_post_revision' )->never();

		$result = $this->rollback->run(
			array(
				'posts' => array(
					array(
						'post_id'     => 5,
						'action'      => 'restore',
						'revision_id' => 0,
						'status'      => 'draft',
						'fields'      => array( 'post_title' => 'Old' ),
						'possible'    => true,
					),
				),
				'other' => array(),
				'rows'  => array(),
			),
			's1'
		);

		$this->assertSame( 1, $result['restored'] );
	}

	public function test_a_failed_restore_is_counted(): void {
		Functions\when( 'wp_restore_post_revision' )->justReturn( false );

		$result = $this->rollback->run(
			array(
				'posts' => array(
					array(
						'post_id'     => 5,
						'action'      => 'restore',
						'revision_id' => 41,
						'status'      => 'draft',
						'fields'      => null,
						'possible'    => true,
					),
				),
				'other' => array(),
				'rows'  => array(),
			),
			's1'
		);

		$this->assertSame( 1, $result['failed'] );
	}

	public function test_undo_url_filter(): void {
		$this->assertSame( '', Rollback::undo_url(), 'Activity Log with Undo is not active.' );

		Filters\expectApplied( 'agent_governance_undo_url' )->once()->with( '' )->andReturn( 'https://example.com/undo' );
		$this->assertSame( 'https://example.com/undo', Rollback::undo_url() );
	}
}
