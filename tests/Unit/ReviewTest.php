<?php
/**
 * Tests for object-level checks on requests, suggestions and evidence.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Abilities;
use AryanX\Axle\AgentGovernance\Evidence;
use AryanX\Axle\AgentGovernance\Requests;
use AryanX\Axle\AgentGovernance\Review;
use AryanX\Axle\AgentGovernance\Simulator;
use AryanX\Axle\AgentGovernance\Suggestions;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;

/**
 * Covers Review, Suggestions and Evidence.
 */
final class ReviewTest extends AgentTestCase {

	/**
	 * Capabilities by user ID, then name.
	 *
	 * @var array<int, array<string, bool>>
	 */
	private $user_caps = array();

	protected function setUp(): void {
		parent::setUp();
		$this->user_caps = array(
			1 => array( 'edit_post' => true, 'edit_user' => true, 'edit_term' => true, 'manage_options' => true, 'create_users' => true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			2 => array(),
		);
		Functions\when( 'user_can' )->alias(
			function ( $user, $cap ) {
				return ! empty( $this->user_caps[ (int) $user ][ $cap ] );
			}
		);
		Functions\when( 'post_type_exists' )->alias(
			static function ( $type ) {
				return 'post' === $type || 'page' === $type;
			}
		);
		Functions\when( 'taxonomy_exists' )->alias(
			static function ( $type ) {
				return 'category' === $type;
			}
		);
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 5 ) );
		Functions\when( 'get_term' )->justReturn( (object) array( 'term_id' => 3 ) );
		Functions\when( 'get_post_types' )->justReturn( array() );
	}

	/**
	 * A request about an object.
	 *
	 * @param array  $object Object: type, id.
	 * @param string $target Route.
	 * @param string $method Method.
	 * @return array
	 */
	private function request_about( array $object, string $target = '/wp/v2/posts/5', string $method = 'POST' ): array {
		return $this->request_row(
			array(
				'source'  => 'rest',
				'target'  => $target,
				'method'  => $method,
				'dry_run' => (string) wp_json_encode( array( 'supported' => true, 'summary' => 'x', 'object' => $object ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			)
		);
	}

	public function test_an_approver_needs_access_to_the_object(): void {
		$post = $this->request_about( array( 'type' => 'post', 'id' => 5 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( Review::can( $post, 1 ) );
		$this->assertFalse( Review::can( $post, 2 ), 'An approver who cannot edit the post may not see its preview.' );

		$this->assertFalse( Review::can( $this->request_about( array( 'type' => 'user', 'id' => 1 ), '/wp/v2/users/1' ), 2 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( Review::can( $this->request_about( array( 'type' => 'category', 'id' => 3 ), '/wp/v2/categories/3' ), 2 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_routes_without_an_object_use_their_own_capability(): void {
		$this->assertFalse( Review::can( $this->request_about( array(), '/wp/v2/settings' ), 2 ) );
		$this->assertTrue( Review::can( $this->request_about( array(), '/wp/v2/settings' ), 1 ) );
		$this->assertFalse( Review::can( $this->request_about( array(), '/wp/v2/users' ), 2 ) );
		$this->assertFalse( Review::can( $this->request_about( array(), '/wp/v2/plugins/akismet/akismet', 'DELETE' ), 2 ) );
		$this->assertTrue( Review::can( $this->request_row(), 2 ), 'Calls with nothing to check pass.' );
	}

	public function test_the_filter_has_the_last_word(): void {
		\Brain\Monkey\Filters\expectApplied( 'agent_governance_can_review_request' )->once()->andReturn( false );
		$this->assertFalse( Review::can( $this->request_row(), 1 ) );
	}

	public function test_deciding_is_refused_without_access_and_nothing_changes(): void {
		$this->wpdb->returns['get_row'][] = $this->request_about( array( 'type' => 'post', 'id' => 5 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$error = $this->approvals->approve( 7, 2 );

		$this->assertSame( 'agent_governance_forbidden', $error->get_error_code() );
		$this->assertSame( array(), $this->wpdb->updated );
	}

	public function test_the_pending_list_and_one_request_hide_what_the_approver_cannot_open(): void {
		$this->user_id = 2;
		$row           = $this->request_about( array( 'type' => 'post', 'id' => 5 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$row['user_id'] = 1;
		$abilities     = new Abilities( $this->requests, $this->approvals, $this->classifier );

		$this->wpdb->returns['get_results'][] = array( $row );
		$this->assertSame( array(), $abilities->list_pending( array() ) );

		$this->wpdb->returns['get_row'][] = $row;
		$this->assertSame( 'agent_governance_forbidden', $abilities->get_request( array( 'id' => 7 ) )->get_error_code() );

		$this->user_id                    = 1;
		$this->wpdb->returns['get_row'][] = $row;
		$this->assertSame( 7, $abilities->get_request( array( 'id' => 7 ) )['id'] );
	}

	public function test_only_approvers_who_can_open_the_object_are_emailed(): void {
		Functions\when( 'get_users' )->justReturn(
			array(
				(object) array( 'ID' => 1, 'user_email' => 'admin@example.com' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				(object) array( 'ID' => 2, 'user_email' => 'ed@example.com' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			)
		);

		$this->assertSame( array( 'admin@example.com' ), $this->notifier->recipients( $this->request_about( array( 'type' => 'post', 'id' => 5 ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_a_rollback_leaves_posts_the_person_may_not_edit_and_stays_open(): void {
		$this->caps['edit_post'] = false;
		Functions\when( 'get_post' )->justReturn( (object) array( 'ID' => 5, 'post_status' => 'draft', 'post_title' => 'Secret title' ) );
		Functions\when( 'get_the_title' )->justReturn( 'Secret title' );
		Functions\when( 'wp_get_post_revision' )->justReturn( (object) array() );
		Functions\expect( 'wp_restore_post_revision' )->never();

		$plan = $this->rollback->plan( array( $this->log_row( array( 'changes' => '[{"post_id":5,"action":"updated","revision_id":41,"status":"publish"}]' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( $plan['posts'][0]['possible'] );
		$this->assertSame( 'Post #5', $plan['posts'][0]['title'], 'The title is not shown.' );

		$result = $this->rollback->run( $plan, 's1' );
		$this->assertSame( 1, $result['denied'] );
		$this->assertSame( array(), $this->wpdb->queries, 'The session is not marked as rolled back.' );
	}

	public function test_a_rule_is_suggested_after_enough_approvals_and_never_after_a_rejection(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$base = array(
			'identity' => 'app:aaaa-1111',
			'agent'    => 'Helper',
			'user_id'  => 2,
			'kind'     => 'destructive',
			'source'   => 'rest',
			'method'   => 'DELETE',
		);
		// Approvals of different posts are one kind of call.
		$this->wpdb->returns['get_results'] = array(
			array(
				$base + array( 'target' => '/wp/v2/posts/1', 'status' => 'executed', 'n' => 3 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				$base + array( 'target' => '/wp/v2/posts/2', 'status' => 'failed', 'n' => 3 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				$base + array( 'target' => '/wp/v2/pages/9', 'status' => 'executed', 'n' => 9 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				$base + array( 'target' => '/wp/v2/pages/10', 'status' => 'rejected', 'n' => 1 ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			),
			array(),
		);
		$this->wpdb->returns['get_var'] = array( null );

		$suggestions = new Suggestions( $this->settings, $this->requests, new Simulator( $this->settings, $this->log ) );
		$found       = $suggestions->all();

		$this->assertCount( 1, $found, 'Pages were rejected once, so no rule for them.' );
		$this->assertSame( 'DELETE /wp/v2/posts/*', $found[0]['pattern'] );
		$this->assertSame( 6, $found[0]['approved'] );
		$this->assertSame( 'agent', $found[0]['rule']['scope'] );
		$this->assertSame( 'aaaa-1111', $found[0]['rule']['subject'] );
		$this->assertSame( 'allow', $found[0]['rule']['action'] );
	}

	public function test_accepting_puts_the_rule_first_and_fires_the_action(): void {
		$this->options['agent_governance_rules'] = array(
			array(
				'id'      => 'old1',
				'scope'   => 'default',
				'pattern' => '*',
				'action'  => 'deny',
			),
		);
		$this->build();
		$suggestions = new Suggestions( $this->settings, $this->requests, new Simulator( $this->settings, $this->log ) );
		Actions\expectDone( 'agent_governance_suggestion_accepted' )->once();

		$rule = $suggestions->accept(
			array(
				'rule' => array(
					'scope'   => 'agent',
					'subject' => 'aaaa-1111',
					'pattern' => 'my/save',
					'action'  => 'allow',
				),
			),
			1
		);

		$this->assertSame( 'my/save', $this->options['agent_governance_rules'][0]['pattern'] );
		$this->assertSame( 'old1', $this->options['agent_governance_rules'][1]['id'] );
		$this->assertSame( $rule['id'], $this->options['agent_governance_rules'][0]['id'] );
	}

	public function test_the_suggestion_threshold_and_pattern(): void {
		\Brain\Monkey\Filters\expectApplied( 'agent_governance_suggestion_threshold' )->once()->andReturn( 1 );
		$this->assertSame( 2, Suggestions::threshold() );
		$this->assertSame( 'my/save', Suggestions::pattern_for( 'ability', '', 'my/save' ) );
		$this->assertSame( 'PUT /wp/v2/posts/*/meta/*', Suggestions::pattern_for( 'rest', 'put', '/wp/v2/posts/12/meta/4' ) );
	}

	public function test_evidence_is_signed_and_a_changed_file_fails_verification(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		$this->wpdb->returns['get_results'] = array(
			array( $this->log_row( array( 'id' => 1, 'request_id' => 7, 'decision' => 'held' ) ), $this->log_row( array( 'id' => 2 ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array(
				array(
					'id'         => 7,
					'created'    => '2026-11-10 10:00:00',
					'status'     => Requests::EXECUTED,
					'decided_by' => 1,
					'decided_at' => '2026-11-10 10:05:00',
					'reason'     => '',
				),
			),
		);
		Actions\expectDone( 'agent_governance_evidence_exported' )->once();

		$evidence = new Evidence( $this->log, $this->requests );
		$file     = $evidence->export( 's1' );

		$this->assertSame( 'admin', $file['payload']['calls'][0]['request']['approver'] );
		$this->assertSame( 300, $file['payload']['calls'][0]['request']['seconds_to_decide'] );
		$this->assertNull( $file['payload']['calls'][1]['request'] );
		$this->assertArrayNotHasKey( 'input', $file['payload']['calls'][0], 'Inputs are not in the file.' );

		$decoded = json_decode( Evidence::encode( $file ), true );
		$this->assertTrue( Evidence::verify( $decoded ) );

		$decoded['payload']['calls'][0]['decision'] = 'allowed';
		$this->assertFalse( Evidence::verify( $decoded ), 'Any change breaks the signature.' );
		$this->assertFalse( Evidence::verify( 'nope' ) );
	}

	public function test_an_empty_session_has_no_evidence(): void {
		$this->assertNull( ( new Evidence( $this->log, $this->requests ) )->export( 'none' ) );
	}

	public function test_the_simulate_ability_reports_changes_without_saving(): void {
		$this->wpdb->returns['get_var']     = array( 1, null );
		$this->wpdb->returns['get_results'] = array( array( $this->log_row( array( 'id' => 1, 'created' => '2026-11-10 10:00:00' ) ), ), array() ); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment
		$abilities                          = new Abilities( $this->requests, $this->approvals, $this->classifier, new Simulator( $this->settings, $this->log ), null );

		$out = $abilities->simulate_policy(
			array(
				'defaults' => array( 'write' => 'require_approval' ),
				'rules'    => array(),
			)
		);

		$this->assertSame( 1, $out['calls'] );
		$this->assertSame( 1, $out['baseline']['allow'] );
		$this->assertSame( 1, $out['policy']['require_approval'] );
		$this->assertSame( 'allow', $out['changes'][0]['from'] );
		$this->assertSame( array(), $this->wpdb->updated );
		$this->assertArrayNotHasKey( 'agent_governance_rules', $this->options );
	}
}
