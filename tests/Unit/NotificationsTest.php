<?php
/**
 * Tests for webhook delivery, bursts, reminders and per-user settings.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Tests\Unit;

use AryanX\Axle\AgentGovernance\Notifier;
use AryanX\Axle\AgentGovernance\Preferences;
use AryanX\Axle\AgentGovernance\Settings;
use AryanX\Axle\AgentGovernance\Webhook;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Webhook, Preferences and the batching and reminders in Notifier.
 */
final class NotificationsTest extends AgentTestCase {

	/** @var array[] Webhook posts: url and args. */
	private array $posts = array();

	/** @var array[] Emails: to, subject and message. */
	private array $mails = array();

	protected function setUp(): void {
		parent::setUp();
		$this->options['blogname'] = 'Test site';
		$this->users[3]            = (object) array( 'ID' => 3, 'user_login' => 'sam', 'user_email' => 'sam@example.com', 'roles' => array( 'administrator' ) );
		Functions\when( 'get_users' )->justReturn(
			array(
				(object) array( 'ID' => 1, 'user_email' => 'admin@example.com' ),
				(object) array( 'ID' => 3, 'user_email' => 'sam@example.com' ),
			)
		);
		Functions\when( 'wp_mail' )->alias(
			function ( $to, $subject, $message ) {
				$this->mails[] = array( 'to' => $to, 'subject' => $subject, 'message' => $message );
				return true;
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.com' );
		Functions\when( 'user_can' )->justReturn( true );
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'wp_safe_remote_post' )->alias(
			function ( $url, $args ) {
				$this->posts[] = array( 'url' => $url, 'args' => $args );
				return array( 'response' => array( 'code' => 200 ) );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_next_scheduled' )->alias(
			function ( $hook ) {
				return $this->scheduled[ $hook ] ?? false;
			}
		);
	}

	private function with_settings( array $settings ): void {
		$this->options['agent_governance_settings'] = $settings;
		$this->build();
	}

	public function test_the_webhook_url_must_be_http_or_https(): void {
		$this->assertSame( 'https://hooks.example.com/x', Settings::clean_webhook_url( ' https://hooks.example.com/x ' ) );
		$this->assertSame( '', Settings::clean_webhook_url( 'javascript:alert(1)' ) );
		$this->assertSame( '', Settings::clean_webhook_url( 'ftp://example.com/x' ) );
		$this->assertSame( '', Settings::clean_webhook_url( '' ) );
	}

	public function test_new_settings_are_clamped(): void {
		$clean = Settings::sanitize( array( 'batch_minutes' => 999, 'reminder_hours' => -4, 'webhook_format' => 'xml', 'webhook_by' => -2 ) );

		$this->assertSame( 60, $clean['batch_minutes'] );
		$this->assertSame( 0, $clean['reminder_hours'] );
		$this->assertSame( 'json', $clean['webhook_format'] );
		$this->assertSame( 0, $clean['webhook_by'] );
		$this->assertSame( 5, Settings::sanitize( array() )['batch_minutes'] );
	}

	public function test_the_batch_window_and_reminder_lead_are_filtered_and_clamped(): void {
		Filters\expectApplied( 'agent_governance_batch_window' )->once()->with( 300 )->andReturn( 999999 );
		$this->assertSame( 3600, $this->settings->batch_window() );

		Filters\expectApplied( 'agent_governance_reminder_lead' )->once()->with( 14400 )->andReturn( 90 * 3600 );
		$this->assertSame( 12 * 3600, $this->settings->reminder_lead(), 'Never more than half the waiting time.' );
	}

	public function test_a_held_request_goes_out_at_once_to_email_and_webhook(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/x', 'webhook_by' => 1 ) );
		Actions\expectDone( 'agent_governance_approvers_notified' )->once();

		$this->assertTrue( $this->notifier->request_held( $this->request_row() ) );

		$this->assertCount( 2, $this->mails, 'One email per approver.' );
		$this->assertCount( 1, $this->posts );
		$this->assertSame( 'https://hooks.example.com/x', $this->posts[0]['url'] );
		$body = json_decode( $this->posts[0]['args']['body'], true );
		$this->assertSame( 'requests_held', $body['event'] );
		$this->assertSame( 'Deletes it.', $body['requests'][0]['summary'] );
		$this->assertStringContainsString( 'request=7', $body['requests'][0]['review_url'] );
		$this->assertSame( 0, $this->posts[0]['args']['redirection'] );
	}

	public function test_the_slack_format_has_text_and_blocks_and_escapes_markup(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/x', 'webhook_format' => 'slack', 'webhook_by' => 1 ) );
		$row = $this->request_row( array( 'dry_run' => '{"summary":"Deletes <b>it</b> & more.","changes":[{"field":"Title","before":"A","after":"B"},{"field":"user_pass","before":"x","after":"y"}]}' ) );

		$this->notifier->request_held( $row );

		$body = json_decode( $this->posts[0]['args']['body'], true );
		$this->assertStringContainsString( 'needs approval', $body['text'] );
		$text = $body['blocks'][1]['text']['text'];
		$this->assertStringContainsString( 'Deletes &lt;b&gt;it&lt;/b&gt; &amp; more.', $text );
		$this->assertStringContainsString( '- Title: A -> B', $text );
		$this->assertStringContainsString( '- user_pass: [redacted] -> [redacted]', $text );
		$this->assertStringNotContainsString( 'hunter2', $this->posts[0]['args']['body'] );
	}

	public function test_secret_values_never_reach_the_webhook(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/x', 'webhook_by' => 1 ) );
		$row = $this->request_row( array( 'dry_run' => '{"summary":"Changes the user.","changes":[{"field":"Password","before":"hunter2","after":"hunter3"}]}' ) );

		$this->notifier->request_held( $row );

		$this->assertStringNotContainsString( 'hunter', $this->posts[0]['args']['body'] );
		$this->assertStringContainsString( '[redacted]', $this->posts[0]['args']['body'] );
	}

	public function test_the_webhook_sends_no_details_for_what_its_owner_cannot_open(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/x', 'webhook_by' => 2 ) );
		Filters\expectApplied( 'agent_governance_can_review_request' )->atLeast()->once()->andReturn( false );

		$this->notifier->request_held( $this->request_row() );

		$item = json_decode( $this->posts[0]['args']['body'], true )['requests'][0];
		$this->assertArrayNotHasKey( 'summary', $item );
		$this->assertArrayNotHasKey( 'target', $item );
		$this->assertSame( 7, $item['id'] );
	}

	public function test_a_webhook_without_an_owner_sends_no_details(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/x' ) );

		$this->notifier->request_held( $this->request_row() );

		$this->assertArrayNotHasKey( 'summary', json_decode( $this->posts[0]['args']['body'], true )['requests'][0] );
	}

	public function test_people_who_cannot_open_the_object_are_not_emailed(): void {
		Filters\expectApplied( 'agent_governance_can_review_request' )->twice()->andReturnUsing(
			static function ( $allowed, $request, $user_id ) {
				return 1 === $user_id;
			}
		);

		$this->notifier->request_held( $this->request_row() );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( 'admin@example.com' ), $this->mails[0]['to'] );
	}

	public function test_a_failed_webhook_is_recorded(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/x' ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( array( 'response' => array( 'code' => 404 ) ) );
		Actions\expectDone( 'agent_governance_webhook_sent' )->once()->with( false, Mockery::type( 'array' ), 'held' );

		$webhook = new Webhook( $this->settings );
		$this->assertFalse( $webhook->send( array( $this->request_row() ), 'held' ) );

		$status = Webhook::status();
		$this->assertFalse( $status['ok'] );
		$this->assertStringContainsString( 'HTTP 404', $status['message'] );
	}

	public function test_a_webhook_error_is_recorded(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/x' ) );
		Functions\when( 'wp_safe_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'A valid URL was not provided.' ) );

		$this->assertFalse( ( new Webhook( $this->settings ) )->send( array( $this->request_row() ), 'held' ) );
		$this->assertSame( 'A valid URL was not provided.', Webhook::status()['message'] );
	}

	public function test_the_webhook_url_filter(): void {
		$this->with_settings( array() );
		Filters\expectApplied( 'agent_governance_webhook_url' )->once()->andReturn( 'https://from-constant.example.com/h' );

		$this->assertSame( 'https://from-constant.example.com/h', ( new Webhook( $this->settings ) )->url() );
	}

	public function test_the_webhook_payload_and_args_filters(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/x' ) );
		Filters\expectApplied( 'agent_governance_webhook_payload' )->once()->with( Mockery::type( 'array' ), Mockery::type( 'array' ), 'held' )->andReturn( array( 'custom' => true ) );
		Filters\expectApplied( 'agent_governance_webhook_args' )->once()->andReturnUsing(
			static function ( $args ) {
				$args['headers']['X-Token'] = 'abc';
				return $args;
			}
		);
		( new Webhook( $this->settings ) )->send( array( $this->request_row() ), 'held' );

		$this->assertSame( '{"custom":true}', $this->posts[0]['args']['body'] );
		$this->assertSame( 'abc', $this->posts[0]['args']['headers']['X-Token'] );
	}

	public function test_no_webhook_means_no_post(): void {
		$this->notifier->request_held( $this->request_row() );

		$this->assertSame( array(), $this->posts );
		$this->assertNull( Webhook::status() );
	}

	public function test_a_burst_is_gathered_and_sent_as_one_message(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/x', 'webhook_by' => 1 ) );
		Actions\expectDone( 'agent_governance_notification_queued' )->twice();

		$this->assertTrue( $this->notifier->request_held( $this->request_row( array( 'id' => 7 ) ) ) );
		$this->assertCount( 2, $this->mails );

		$this->assertFalse( $this->notifier->request_held( $this->request_row( array( 'id' => 8 ) ) ), 'Within the window it waits.' );
		$this->assertFalse( $this->notifier->request_held( $this->request_row( array( 'id' => 9 ) ) ) );
		$this->assertCount( 2, $this->mails, 'Nothing more went out yet.' );
		$this->assertSame( array( 8, 9 ), $this->options[ Notifier::OUTBOX_OPTION ] );
		$this->assertArrayHasKey( Notifier::FLUSH_HOOK, $this->scheduled );
		$this->assertSame( (int) $this->options[ Notifier::LAST_OPTION ] + 300, $this->scheduled[ Notifier::FLUSH_HOOK ] );

		$this->wpdb->returns['get_results'][] = array( $this->request_row( array( 'id' => 8 ) ), $this->request_row( array( 'id' => 9 ) ) );
		$this->assertTrue( $this->notifier->flush() );

		$this->assertCount( 4, $this->mails, 'One email each for the two approvers.' );
		$this->assertStringContainsString( '2 agent requests need approval', $this->mails[2]['subject'] );
		$this->assertStringContainsString( 'Request #8', $this->mails[2]['message'] );
		$this->assertStringContainsString( 'Request #9', $this->mails[2]['message'] );
		$this->assertCount( 2, $this->posts, 'One webhook message for the burst.' );
		$this->assertSame( 2, json_decode( $this->posts[1]['args']['body'], true )['count'] );
		$this->assertArrayNotHasKey( Notifier::OUTBOX_OPTION, $this->options );
	}

	public function test_a_batch_window_of_zero_sends_each_one_at_once(): void {
		$this->with_settings( array( 'batch_minutes' => 0 ) );

		$this->notifier->request_held( $this->request_row( array( 'id' => 7 ) ) );
		$this->notifier->request_held( $this->request_row( array( 'id' => 8 ) ) );

		$this->assertCount( 4, $this->mails );
	}

	public function test_a_request_decided_while_it_waited_is_not_sent(): void {
		$this->notifier->request_held( $this->request_row( array( 'id' => 7 ) ) );
		$this->notifier->request_held( $this->request_row( array( 'id' => 8 ) ) );
		$this->mails = array();

		$this->wpdb->returns['get_results'][] = array( $this->request_row( array( 'id' => 8, 'status' => 'approved' ) ) );

		$this->assertFalse( $this->notifier->flush() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_reminder_goes_out_once_per_request(): void {
		$old = $this->request_row( array( 'id' => 11, 'created' => gmdate( 'Y-m-d H:i:s', time() - 21 * 3600 ) ) );
		Actions\expectDone( 'agent_governance_reminder_sent' )->once();

		$this->wpdb->returns['get_results'][] = array( $old );
		$this->assertSame( 1, $this->notifier->remind() );

		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'Reminder', $this->mails[0]['subject'] );
		$this->assertStringContainsString( 'Request #11', $this->mails[0]['message'] );
		$this->assertArrayHasKey( 11, $this->options[ Notifier::REMINDED_OPTION ] );

		$this->wpdb->returns['get_results'][] = array( $old );
		$this->wpdb->returns['get_results'][] = array( array( 'id' => 11, 'status' => 'pending' ) );
		$this->assertSame( 0, $this->notifier->remind(), 'Already reminded.' );
		$this->assertCount( 2, $this->mails );
	}

	public function test_the_reminder_list_forgets_decided_requests(): void {
		$this->options[ Notifier::REMINDED_OPTION ] = array( 11 => 1000, 12 => 1000 );

		$this->wpdb->returns['get_results'][] = array();
		$this->wpdb->returns['get_results'][] = array( array( 'id' => 11, 'status' => 'executed' ), array( 'id' => 12, 'status' => 'pending' ) );
		$this->notifier->remind();

		$this->assertSame( array( 12 => 1000 ), $this->options[ Notifier::REMINDED_OPTION ] );
	}

	public function test_no_reminder_when_the_lead_is_zero(): void {
		$this->with_settings( array( 'reminder_hours' => 0 ) );
		$this->wpdb->returns['get_results'][] = array();

		$this->assertSame( 0, $this->notifier->remind() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_a_reminder_respects_each_persons_choice(): void {
		Preferences::save( 3, array( 'reminder' => false ) );

		$this->wpdb->returns['get_results'][] = array( $this->request_row( array( 'id' => 11, 'created' => gmdate( 'Y-m-d H:i:s', time() - 21 * 3600 ) ) ) );
		$this->notifier->remind();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( 'admin@example.com' ), $this->mails[0]['to'] );
	}

	public function test_a_held_email_respects_each_persons_choice(): void {
		Preferences::save( 1, array( 'email' => false ) );

		$this->notifier->request_held( $this->request_row() );

		$this->assertCount( 1, $this->mails );
		$this->assertSame( array( 'sam@example.com' ), $this->mails[0]['to'] );
	}

	public function test_the_email_says_nothing_is_approved_from_it(): void {
		$this->notifier->request_held( $this->request_row() );

		$this->assertStringContainsString( 'Nothing is approved from this email', $this->mails[0]['message'] );
	}

	public function test_preferences_default_to_on_and_save(): void {
		$this->assertSame( array( 'email' => true, 'reminder' => true, 'notice' => true ), Preferences::get( 1 ) );

		Actions\expectDone( 'agent_governance_preferences_saved' )->once();
		$saved = Preferences::save( 1, array( 'reminder' => false, 'bogus' => true ) );

		$this->assertSame( array( 'email' => true, 'reminder' => false, 'notice' => true ), $saved );
		$this->assertFalse( Preferences::wants( 1, 'reminder' ) );
		$this->assertTrue( Preferences::wants( 1, 'email' ) );
	}

	public function test_the_default_preferences_filter(): void {
		Filters\expectApplied( 'agent_governance_default_preferences' )->once()->andReturn( array( 'email' => false ) );

		$this->assertSame( array( 'email' => false, 'reminder' => true, 'notice' => true ), Preferences::get( 1 ) );
	}

	public function test_the_notice_respects_the_persons_choice(): void {
		Functions\when( 'get_current_screen' )->justReturn( (object) array( 'id' => 'dashboard' ) );
		Preferences::save( 1, array( 'notice' => false ) );
		$this->wpdb->returns['get_var'][] = 3;

		ob_start();
		$this->notifier->notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_the_profile_section_is_for_your_own_profile_as_an_approver(): void {
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'checked' )->alias(
			static function ( $a, $b ) {
				return $a === $b ? ' checked' : '';
			}
		);
		$preferences = new Preferences();

		ob_start();
		$preferences->render( (object) array( 'ID' => 1 ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="agent_governance_notify[reminder]"', $html );
		$this->assertStringContainsString( 'sign in', $html );

		ob_start();
		$preferences->render( (object) array( 'ID' => 3 ) );
		$this->assertSame( '', ob_get_clean(), 'Not someone else\'s profile.' );
	}

	public function test_the_profile_saves_with_a_valid_nonce_only(): void {
		Functions\when( 'wp_verify_nonce' )->alias(
			static function ( $nonce ) {
				return 'good' === $nonce ? 1 : false;
			}
		);
		$preferences = new Preferences();

		$_POST = array( '_agent_governance_prefs' => 'bad', 'agent_governance_notify' => array( 'email' => '1' ) );
		$preferences->save_profile( 1 );
		$this->assertSame( array(), $this->user_meta );

		$_POST = array( '_agent_governance_prefs' => 'good', 'agent_governance_notify' => array( 'email' => '1' ) );
		$preferences->save_profile( 1 );
		$this->assertSame( array( 'email' => true, 'reminder' => false, 'notice' => false ), $this->user_meta[1][ Preferences::META ] );

		$_POST = array( '_agent_governance_prefs' => 'good', 'agent_governance_notify' => array() );
		$preferences->save_profile( 3 );
		$this->assertArrayNotHasKey( 3, $this->user_meta, 'Not for another person.' );
		$_POST = array();
	}

	public function test_the_heartbeat_carries_the_count_for_approvers_only(): void {
		$this->wpdb->returns['get_var'][] = 4;
		$this->assertSame( 4, $this->notifier->heartbeat( array(), array( 'agent_governance' => 'pending' ) )['agent_governance_pending'] );

		$this->assertArrayNotHasKey( 'agent_governance_pending', $this->notifier->heartbeat( array(), array() ), 'Only when the queue screen asked.' );

		$this->caps = array();
		$this->assertArrayNotHasKey( 'agent_governance_pending', $this->notifier->heartbeat( array(), array( 'agent_governance' => 'pending' ) ) );
	}

	public function test_erasing_a_user_removes_their_settings(): void {
		Preferences::save( 2, array( 'email' => false ) );
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 2 ) );
		$this->wpdb->returns['query'] = array( 0, 0, 0 );

		( new \AryanX\Axle\AgentGovernance\Privacy( $this->settings, $this->log, $this->requests ) )->erase( 'ed@example.com' );

		$this->assertArrayNotHasKey( Preferences::META, $this->user_meta[2] ?? array() );
	}

	public function test_the_test_message_says_nothing_is_waiting(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/x', 'webhook_by' => 1 ) );

		$this->assertTrue( ( new Webhook( $this->settings ) )->test() );

		$this->assertStringContainsString( 'Nothing is waiting', $this->posts[0]['args']['body'] );
	}

	public function test_the_notification_ability_never_returns_the_address(): void {
		$this->with_settings( array( 'webhook_url' => 'https://hooks.example.com/secret-path' ) );
		$abilities = new \AryanX\Axle\AgentGovernance\Abilities( $this->requests, $this->approvals, $this->classifier, null, null, $this->settings );

		$out = $abilities->get_notification_settings();

		$this->assertTrue( $out['site']['webhook'] );
		$this->assertStringNotContainsString( 'secret-path', (string) wp_json_encode( $out ) );
		$this->assertTrue( $out['me']['email'] );
	}
}
