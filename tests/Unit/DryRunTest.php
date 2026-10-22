<?php
/**
 * Tests for the dry-run previews.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance\Tests\Unit;

use AryanX\AgentGovernance\DryRun;
use AryanX\AgentGovernance\Redactor;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers DryRun.
 */
final class DryRunTest extends AgentTestCase {

	/**
	 * The previewer.
	 *
	 * @var DryRun
	 */
	private DryRun $dry;

	protected function setUp(): void {
		parent::setUp();
		$this->dry = new DryRun();

		$post_type = (object) array(
			'name'           => 'post',
			'rest_base'      => 'posts',
			'rest_namespace' => 'wp/v2',
			'labels'         => (object) array( 'singular_name' => 'Post' ),
		);
		$category  = (object) array(
			'name'           => 'category',
			'rest_base'      => 'categories',
			'rest_namespace' => 'wp/v2',
			'show_in_rest'   => true,
			'labels'         => (object) array(
				'singular_name' => 'Category',
				'name'          => 'Categories',
			),
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => $post_type ) );
		Functions\when( 'get_taxonomies' )->justReturn( array( 'category' => $category ) );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'category' => $category ) );
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				return 5 === (int) $id ? (object) array(
					'ID'             => 5,
					'post_type'      => 'post',
					'post_title'     => 'Hello',
					'post_content'   => 'Old body',
					'post_excerpt'   => '',
					'post_status'    => 'publish',
					'post_name'      => 'hello',
					'post_author'    => '1',
					'post_parent'    => '0',
					'menu_order'     => '0',
					'comment_status' => 'open',
					'ping_status'    => 'open',
					'post_date'      => '2026-10-01 10:00:00',
					'post_password'  => '',
				) : null;
			}
		);
		Functions\when( 'get_the_title' )->justReturn( 'Hello' );
		Functions\when( 'get_edit_post_link' )->justReturn( 'https://example.com/wp-admin/post.php?post=5&action=edit' );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );
		Functions\when( 'wp_get_object_terms' )->justReturn( array( 1 ) );
		Functions\when( 'get_term' )->alias(
			static function ( $id ) {
				$term = new \WP_Term();
				foreach ( array(
					'term_id'     => (int) $id,
					'name'        => 1 === (int) $id ? 'Uncategorized' : 'News',
					'slug'        => 1 === (int) $id ? 'uncategorized' : 'news',
					'description' => '',
					'parent'      => 0,
					'count'       => 4,
				) as $key => $value ) {
					$term->$key = $value;
				}
				return 99 === (int) $id ? null : $term;
			}
		);
		Functions\when( 'get_edit_term_link' )->justReturn( 'https://example.com/term' );
	}

	/**
	 * A REST call.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $body   Body.
	 * @param array  $query  Query.
	 * @return array
	 */
	private function rest( string $method, string $route, array $body = array(), array $query = array() ): array {
		return array(
			'source' => 'rest',
			'target' => $route,
			'method' => $method,
			'kind'   => 'write',
			'input'  => array(
				'query' => $query,
				'body'  => $body,
			),
		);
	}

	public function test_a_post_update_shows_only_the_fields_that_change(): void {
		$preview = $this->dry->preview(
			$this->rest(
				'POST',
				'/wp/v2/posts/5',
				array(
					'title'      => array( 'raw' => 'Hello' ),
					'content'    => 'New body',
					'status'     => 'draft',
					'password'   => 'secret',
					'categories' => array( 2, 1 ),
				)
			)
		);

		$this->assertTrue( $preview['supported'] );
		$this->assertSame( 'Changes 4 fields of the Post "Hello".', $preview['summary'] );
		$this->assertSame( 5, $preview['object']['id'] );
		$this->assertSame(
			array(
				array(
					'field'  => 'Content',
					'before' => 'Old body',
					'after'  => 'New body',
				),
				array(
					'field'  => 'Status',
					'before' => 'publish',
					'after'  => 'draft',
				),
				array(
					'field'  => 'Password',
					'before' => '',
					'after'  => Redactor::MASK,
				),
				array(
					'field'  => 'Categories',
					'before' => 'Uncategorized',
					'after'  => 'Uncategorized, News',
				),
			),
			$preview['changes']
		);
	}

	public function test_a_post_delete_trashes_unless_forced(): void {
		$trash = $this->dry->preview( $this->rest( 'DELETE', '/wp/v2/posts/5' ) );
		$this->assertSame( 'Moves the Post "Hello" to the trash.', $trash['summary'] );
		$this->assertSame( 'trash', $trash['changes'][0]['after'] );

		$force = $this->dry->preview( $this->rest( 'DELETE', '/wp/v2/posts/5', array(), array( 'force' => 'true' ) ) );
		$this->assertStringContainsString( 'permanently', $force['summary'] );
		$this->assertSame( array(), $force['changes'] );
	}

	public function test_a_new_post_lists_what_it_sets(): void {
		$preview = $this->dry->preview( $this->rest( 'POST', '/wp/v2/posts', array( 'title' => 'Fresh' ) ) );

		$this->assertSame( 'Creates a new Post.', $preview['summary'] );
		$this->assertSame( 'Fresh', $preview['changes'][0]['after'] );
		$this->assertNull( $preview['object'] );
	}

	public function test_a_missing_post_says_so(): void {
		$preview = $this->dry->preview( $this->rest( 'POST', '/wp/v2/posts/404', array( 'title' => 'x' ) ) );

		$this->assertSame( 'Post 404 does not exist, so this call would fail.', $preview['summary'] );
	}

	public function test_terms(): void {
		$edit = $this->dry->preview( $this->rest( 'POST', '/wp/v2/categories/2', array( 'name' => 'Updates' ) ) );
		$this->assertSame( 'Changes the Category "News".', $edit['summary'] );
		$this->assertSame( 'Updates', $edit['changes'][0]['after'] );

		$delete = $this->dry->preview( $this->rest( 'DELETE', '/wp/v2/categories/2' ) );
		$this->assertSame( 'Deletes the Category "News", used by 4 posts.', $delete['summary'] );

		$this->assertSame( 'Creates a new Category.', $this->dry->preview( $this->rest( 'POST', '/wp/v2/categories', array( 'name' => 'New' ) ) )['summary'] );
		$this->assertStringContainsString( 'does not exist', $this->dry->preview( $this->rest( 'POST', '/wp/v2/categories/99' ) )['summary'] );
	}

	public function test_settings(): void {
		Functions\when( 'get_registered_settings' )->justReturn(
			array(
				'blogname'        => array( 'show_in_rest' => array( 'name' => 'title' ) ),
				'blogdescription' => array( 'show_in_rest' => true ),
				'secret_thing'    => array( 'show_in_rest' => false ),
			)
		);
		$this->options['blogname']        = 'Old name';
		$this->options['blogdescription'] = 'Same';

		$preview = $this->dry->preview(
			$this->rest(
				'POST',
				'/wp/v2/settings',
				array(
					'title'           => 'New name',
					'blogdescription' => 'Same',
					'secret_thing'    => 'x',
				)
			)
		);

		$this->assertSame( 'Changes 1 site setting.', $preview['summary'] );
		$this->assertSame( 'title', $preview['changes'][0]['field'] );
	}

	public function test_users(): void {
		Functions\when( 'get_edit_user_link' )->justReturn( 'https://example.com/user' );

		$edit = $this->dry->preview(
			$this->rest(
				'POST',
				'/wp/v2/users/2',
				array(
					'roles'    => array( 'administrator' ),
					'password' => 'x',
				)
			)
		);
		$this->assertSame( 'Changes the user "ed".', $edit['summary'] );
		$this->assertSame( array( 'Password', 'Roles' ), array_column( $edit['changes'], 'field' ) );
		$this->assertSame( 'administrator', $edit['changes'][1]['after'] );

		$delete = $this->dry->preview( $this->rest( 'DELETE', '/wp/v2/users/2', array(), array( 'reassign' => '1' ) ) );
		$this->assertSame( 'Deletes the user "ed" and gives their content to user 1.', $delete['summary'] );

		$this->assertStringContainsString( 'does not exist', $this->dry->preview( $this->rest( 'POST', '/wp/v2/users/50' ) )['summary'] );
	}

	public function test_plugins(): void {
		Functions\when( 'get_plugins' )->justReturn( array( 'akismet/akismet.php' => array( 'Name' => 'Akismet' ) ) );
		Functions\when( 'is_plugin_active' )->justReturn( false );

		$preview = $this->dry->preview( $this->rest( 'POST', '/wp/v2/plugins/akismet/akismet', array( 'status' => 'active' ) ) );

		$this->assertSame( 'Changes the plugin "Akismet".', $preview['summary'] );
		$this->assertSame( 'inactive', $preview['changes'][0]['before'] );
	}

	public function test_abilities_have_no_built_in_preview(): void {
		$preview = $this->dry->preview(
			array(
				'source' => 'ability',
				'target' => 'my/thing',
				'method' => 'POST',
				'kind'   => 'write',
				'input'  => array(),
			)
		);

		$this->assertFalse( $preview['supported'] );
		$this->assertStringContainsString( 'No preview', $preview['summary'] );
	}

	public function test_routes_without_a_preview_fall_back(): void {
		$this->assertFalse( $this->dry->preview( $this->rest( 'POST', '/my/v1/thing' ) )['supported'] );
		$this->assertFalse( $this->dry->preview( $this->rest( 'PUT', '/wp/v2/posts' ) )['supported'] );
	}

	public function test_the_dry_run_filter_supplies_a_preview(): void {
		Filters\expectApplied( 'agent_governance_dry_run' )->once()->with( null, \Mockery::type( 'array' ) )->andReturn(
			array(
				'summary' => 'Sends 3 emails.',
				'changes' => array(
					array(
						'field' => 'Recipients',
						'after' => array( 'a', 'b' ),
					),
					'junk',
				),
				'object'  => array(
					'type' => 'campaign',
					'id'   => '9',
				),
			)
		);

		$preview = $this->dry->preview( $this->rest( 'POST', '/my/v1/send' ) );

		$this->assertTrue( $preview['supported'] );
		$this->assertSame( 'Sends 3 emails.', $preview['summary'] );
		$this->assertSame( '["a","b"]', $preview['changes'][0]['after'] );
		$this->assertCount( 1, $preview['changes'] );
		$this->assertSame( 9, $preview['object']['id'] );
	}
}
