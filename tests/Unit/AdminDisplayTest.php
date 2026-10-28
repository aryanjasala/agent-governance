<?php
/**
 * Tests for what the screens show: labels instead of raw values, the menu
 * label, the status filter and the preview copy.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Admin\ActivityView;
use AryanX\Axle\AgentGovernance\Admin\Display;
use AryanX\Axle\AgentGovernance\Admin\Page;
use AryanX\Axle\AgentGovernance\Admin\RequestsView;
use AryanX\Axle\AgentGovernance\Admin\SessionsView;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Covers Display, Page::menu_title() and the list views' markup.
 */
final class AdminDisplayTest extends AgentTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'wp_date' )->justReturn( 'October 23, 2026 1:26 pm' );
		Functions\when( 'is_rtl' )->justReturn( false );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'sanitize_html_class' )->returnArg( 1 );
	}

	public function test_how_a_call_came_in_is_shown_in_words(): void {
		$this->assertSame( 'Application password', Display::via( 'app-password' ) );
		$this->assertSame( 'MCP', Display::via( 'mcp' ) );
		$this->assertSame( 'Abilities API', Display::via( 'abilities-api' ), 'Older entries still read well.' );
		$this->assertSame( 'My agent', Display::via( 'my-agent' ) );
		$this->assertSame( '', Display::via( '' ) );

		Filters\expectApplied( 'agent_governance_via_labels' )->once()->andReturn( array( 'mcp' => 'Claude over MCP' ) );
		$this->assertSame( 'Claude over MCP', Display::via( 'mcp' ) );
	}

	public function test_result_codes_are_shown_in_words(): void {
		$this->assertSame( 'OK', Display::result( 'ok' ) );
		$this->assertSame( 'Waiting for approval', Display::result( 'agent_governance_pending' ) );
		$this->assertSame( 'Refused: the site is frozen', Display::result( 'axle_read_only_mode' ) );
		$this->assertSame( 'Refused: the site is frozen', Display::result( 'site_read_only_mode' ) );
		$this->assertSame( 'HTTP 404', Display::result( 'http_404' ) );
		$this->assertSame( 'my_plugin_error', Display::result( 'my_plugin_error' ) );

		$this->assertSame( 'OK', Display::result_html( 'ok' ) );
		$this->assertSame( '<code dir="ltr">my_plugin_error</code>', Display::result_html( 'my_plugin_error' ), 'Unknown codes stay readable as code.' );

		Filters\expectApplied( 'agent_governance_result_labels' )->once()->andReturn( array( 'my_plugin_error' => 'The shop is closed' ) );
		$this->assertSame( 'The shop is closed', Display::result( 'my_plugin_error' ) );
	}

	public function test_a_refusal_shows_who_refused(): void {
		$this->assertSame( 'Axle Read-Only Mode freeze', Display::reason( 'axle_read_only_mode' ) );
		$this->assertSame( 'Axle Read-Only Mode freeze', Display::reason( 'site_read_only_mode' ) );
		$this->assertSame( 'Over the rate limit', Display::reason( 'agent_governance_rate_limited' ) );
		$this->assertStringContainsString( '>Refused<', Display::badge( 'refused' ) );
		$this->assertSame( 'Executed', Display::outcome( 'executed' ) );
	}

	public function test_the_back_arrow_follows_the_text_direction(): void {
		$this->assertSame( '&larr;', Display::back_arrow() );

		Functions\when( 'is_rtl' )->justReturn( true );
		$this->assertSame( '&rarr;', Display::back_arrow() );
	}

	public function test_json_and_targets_are_marked_left_to_right(): void {
		$this->assertStringContainsString( '<pre class="agg-json" dir="ltr">', Display::json( '{"a":1}' ) );
		$this->assertStringContainsString( '<code dir="ltr">', Display::call( array( 'method' => 'POST', 'source' => 'ability', 'target' => 'my/x' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_the_menu_label_is_short_and_filterable(): void {
		$this->assertSame( 'AI Agents', Page::menu_title() );

		Filters\expectApplied( 'agent_governance_menu_title' )->once()->andReturn( 'Agents' );
		$this->assertSame( 'Agents', Page::menu_title() );

		Filters\expectApplied( 'agent_governance_menu_title' )->once()->andReturn( '  ' );
		$this->assertSame( 'AI Agents', Page::menu_title(), 'An empty label falls back.' );
	}

	public function test_the_summary_drops_the_old_approve_advice(): void {
		$this->assertSame( 'No preview for this call.', RequestsView::summary( array( 'summary' => 'No preview for this call. Check the input below before you approve it.' ) ) );
		$this->assertSame( 'Moves "Spring sale" to the trash.', RequestsView::summary( array( 'summary' => 'Moves "Spring sale" to the trash.' ) ) );
	}

	public function test_status_filters_are_spaced_like_core(): void {
		$this->wpdb->returns['get_results'] = array( array() );
		$this->wpdb->returns['get_var']     = array( 0 );

		ob_start();
		( new RequestsView( $this->requests ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( "Waiting</a> |</li>\n<li>", $html, 'A space between items, so it reads "Waiting | Executed".' );
	}

	public function test_the_activity_tab_shows_words_and_an_underlined_request_link(): void {
		$this->wpdb->returns['get_results'] = array(
			array(),
			array(
				$this->log_row(
					array(
						'via'        => 'app-password',
						'decision'   => 'held',
						'result'     => 'agent_governance_pending',
						'request_id' => 4,
					)
				),
			),
		);
		$this->wpdb->returns['get_var'] = array( 1 );

		ob_start();
		( new ActivityView( $this->log, $this->settings ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Application password', $html );
		$this->assertStringNotContainsString( '>app-password<', $html );
		$this->assertStringContainsString( 'Waiting for approval', $html );
		$this->assertStringContainsString( '<a class="agg-link" href="', $html );
		$this->assertStringContainsString( 'Request #4</a>', $html );
		$this->assertStringContainsString( 'data-colname="Outcome"', $html );
		$this->assertStringContainsString( '<option value="refused">', $html, 'Refused calls can be filtered.' );
	}

	public function test_every_sessions_column_has_a_heading(): void {
		$this->wpdb->returns['get_results'] = array( array() );
		Functions\when( 'human_time_diff' )->justReturn( '30 mins' );

		ob_start();
		( new SessionsView( $this->log, $this->rollback ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( '<th scope="col"></th>', $html );
		$this->assertStringContainsString( '<span class="screen-reader-text">Actions</span>', $html );
	}
}
