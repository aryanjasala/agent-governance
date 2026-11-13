<?php
/**
 * The Policies tab.
 *
 * @package AryanX\Axle\AgentGovernance
 */

namespace AryanX\Axle\AgentGovernance\Admin;

use AryanX\Axle\AgentGovernance\Log;
use AryanX\Axle\AgentGovernance\Settings;
use AryanX\Axle\AgentGovernance\Simulator;
use AryanX\Axle\AgentGovernance\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Rules per agent, user, role or for every agent; what happens when no rule
 * matches; the rate limit; notifications and how long the log is kept.
 */
final class PoliciesView {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The audit log, for the agents seen so far.
	 *
	 * @var Log
	 */
	private Log $log;

	/**
	 * Replays the log against a draft.
	 *
	 * @var Simulator|null
	 */
	private ?Simulator $simulator;

	/**
	 * Sets up the tab.
	 *
	 * @param Settings       $settings  Settings.
	 * @param Log            $log       The audit log.
	 * @param Simulator|null $simulator Replays the log against a draft.
	 */
	public function __construct( Settings $settings, Log $log, ?Simulator $simulator = null ) {
		$this->settings  = $settings;
		$this->log       = $log;
		$this->simulator = $simulator;
	}

	/**
	 * Renders the result of trying a draft, then the form filled with the draft.
	 *
	 * @param array|null $draft  The draft policy from the form, or null to show what is saved.
	 * @param array|null $result A simulation result, or null.
	 * @param int        $days   Days of history that were asked for.
	 */
	public function render( ?array $draft = null, ?array $result = null, int $days = 0 ): void {
		$settings = $this->settings->all();
		$actions  = self::action_labels();
		$rules    = $draft ? $draft['rules'] : $this->settings->rules();
		if ( $draft ) {
			$settings['defaults']   = $draft['defaults'];
			$settings['rate_limit'] = $draft['rate_limit'];
		}

		if ( $this->simulator && null !== $result ) {
			$this->render_result( $result );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="agg-policies">';
		echo '<input type="hidden" name="action" value="agent_governance_policies">';
		wp_nonce_field( 'agent_governance_policies' );
		// Enter in a field saves. Without this the first button, "send a test", would take it.
		echo '<button type="submit" class="hidden" tabindex="-1" aria-hidden="true">' . esc_html__( 'Save policies', 'agent-governance' ) . '</button>';

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Rules', 'agent-governance' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The first matching rule wins, checking rules for the agent first, then its user, then its role, then every agent. Patterns match ability names or REST routes: * matches anything. Start a pattern with a method to match only REST calls with it, such as "DELETE /wp/v2/*".', 'agent-governance' ) . '</p>';

		echo '<table class="widefat agg-stack agg-rules"><thead><tr><th scope="col">' . esc_html__( 'Applies to', 'agent-governance' ) . '</th><th scope="col">' . esc_html__( 'Which one', 'agent-governance' ) . '</th><th scope="col">' . esc_html__( 'Ability or route', 'agent-governance' ) . '</th><th scope="col">' . esc_html__( 'Then', 'agent-governance' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Remove', 'agent-governance' ) . '</span></th></tr></thead><tbody>';
		$index = 0;
		foreach ( $rules as $rule ) {
			$this->rule_row( $index++, $rule );
		}
		$this->rule_row( $index, array(), true );
		echo '</tbody></table>';
		echo '<p><button type="button" class="button agg-add-rule">' . esc_html__( 'Add rule', 'agent-governance' ) . '</button></p>';

		$identities = $this->log->identities();
		if ( $identities ) {
			echo '<details class="agg-known"><summary>' . esc_html__( 'Agents seen so far', 'agent-governance' ) . '</summary><table class="widefat striped"><tbody>';
			foreach ( $identities as $key => $label ) {
				$subject = 0 === strpos( $key, 'app:' ) ? substr( $key, 4 ) : $key;
				printf( '<tr><td>%1$s</td><td><code>%2$s</code></td></tr>', esc_html( $label ), esc_html( $subject ) );
			}
			echo '</tbody></table></details>';
		}

		echo '</section><section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'When no rule matches', 'agent-governance' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		$kinds = array(
			'readonly'    => __( 'Read-only calls', 'agent-governance' ),
			'write'       => __( 'Writes', 'agent-governance' ),
			'destructive' => __( 'Destructive calls', 'agent-governance' ),
		);
		$hints = array(
			'readonly'    => __( 'Abilities marked read-only, and REST GET requests.', 'agent-governance' ),
			'write'       => __( 'Other abilities, and REST POST, PUT and PATCH requests.', 'agent-governance' ),
			'destructive' => __( 'Abilities marked destructive, and REST DELETE requests.', 'agent-governance' ),
		);
		foreach ( $kinds as $kind => $label ) {
			printf( '<tr><th scope="row"><label for="agg-default-%1$s">%2$s</label></th><td><select id="agg-default-%1$s" name="defaults[%1$s]">', esc_attr( $kind ), esc_html( $label ) );
			foreach ( $actions as $value => $text ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $value, $settings['defaults'][ $kind ], false ), esc_html( $text ) );
			}
			printf( '</select><p class="description">%s</p></td></tr>', esc_html( $hints[ $kind ] ) );
		}
		echo '</tbody></table></section>';

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Limits and notifications', 'agent-governance' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="agg-rate">%1$s</label></th><td><input type="number" min="0" max="100000" id="agg-rate" name="rate_limit" value="%2$d" class="small-text"> %3$s<p class="description">%4$s</p></td></tr>',
			esc_html__( 'Rate limit', 'agent-governance' ),
			(int) $settings['rate_limit'],
			esc_html__( 'calls per minute, per agent', 'agent-governance' ),
			esc_html__( '0 turns the limit off. Calls over the limit get HTTP 429.', 'agent-governance' )
		);
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="email" value="1"%2$s> %3$s</label><br><label><input type="checkbox" name="notice" value="1"%4$s> %5$s</label></td></tr>',
			esc_html__( 'Tell approvers', 'agent-governance' ),
			checked( $settings['email'], true, false ),
			esc_html__( 'Email them when a call is held', 'agent-governance' ),
			checked( $settings['notice'], true, false ),
			esc_html__( 'Show a notice in wp-admin while calls wait', 'agent-governance' )
		);
		$this->render_delivery( $settings );
		printf(
			'<tr><th scope="row"><label for="agg-retention">%1$s</label></th><td><input type="number" min="1" max="3650" id="agg-retention" name="retention_days" value="%2$d" class="small-text"> %3$s</td></tr>',
			esc_html__( 'Keep the log', 'agent-governance' ),
			(int) $settings['retention_days'],
			esc_html__( 'days', 'agent-governance' )
		);
		echo '</tbody></table></section>';

		if ( $this->simulator ) {
			$this->render_try( $days );
		}

		submit_button( __( 'Save policies', 'agent-governance' ) );
		echo '</form>';
	}

	/**
	 * The rows for the webhook, bursts and reminders.
	 *
	 * @param array $settings Saved settings.
	 */
	private function render_delivery( array $settings ): void {
		printf(
			'<tr><th scope="row"><label for="agg-webhook">%1$s</label></th><td><input type="url" id="agg-webhook" name="webhook_url" value="%2$s" class="regular-text code" dir="ltr" autocomplete="off" aria-describedby="agg-webhook-help"> <select name="webhook_format" aria-label="%3$s"><option value="json"%4$s>%5$s</option><option value="slack"%6$s>%7$s</option></select> <button type="submit" name="send_test" value="1" class="button">%8$s</button><p class="description" id="agg-webhook-help">%9$s</p>%10$s</td></tr>',
			esc_html__( 'Webhook', 'agent-governance' ),
			esc_attr( (string) $settings['webhook_url'] ),
			esc_attr__( 'Webhook format', 'agent-governance' ),
			selected( 'json', $settings['webhook_format'], false ),
			esc_html__( 'JSON', 'agent-governance' ),
			selected( 'slack', $settings['webhook_format'], false ),
			esc_html__( 'Slack', 'agent-governance' ),
			esc_html__( 'Save and send a test', 'agent-governance' ),
			esc_html__( 'Posts waiting requests, with the dry-run summary and a link, to this address. Leave it empty to turn it off. It shows details only for things you could open yourself. Deciding always happens in wp-admin, after you sign in.', 'agent-governance' ),
			$this->webhook_status() // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in webhook_status().
		);
		printf(
			'<tr><th scope="row"><label for="agg-batch">%1$s</label></th><td><input type="number" min="0" max="60" id="agg-batch" name="batch_minutes" value="%2$d" class="small-text"> %3$s<p class="description">%4$s</p></td></tr>',
			esc_html__( 'Group a burst', 'agent-governance' ),
			(int) $settings['batch_minutes'],
			esc_html__( 'minutes', 'agent-governance' ),
			esc_html__( 'The first request goes out at once. Requests that arrive within this time of the last message are sent together. 0 sends each one at once.', 'agent-governance' )
		);
		printf(
			'<tr><th scope="row"><label for="agg-reminder">%1$s</label></th><td><input type="number" min="0" max="168" id="agg-reminder" name="reminder_hours" value="%2$d" class="small-text"> %3$s<p class="description">%4$s</p></td></tr>',
			esc_html__( 'Remind approvers', 'agent-governance' ),
			(int) $settings['reminder_hours'],
			esc_html__( 'hours before a request expires', 'agent-governance' ),
			esc_html__( 'Each person can turn reminders off on their own profile. 0 turns reminders off for everyone.', 'agent-governance' )
		);
	}

	/**
	 * How the last webhook message went.
	 *
	 * @return string HTML, escaped.
	 */
	private function webhook_status(): string {
		$status = Webhook::status();
		if ( ! $status ) {
			return '';
		}

		$when = AxleUi::relative_time( $status['time'] );
		if ( $status['ok'] ) {
			return '<p class="description"><span class="axle-badge axle-badge--good">' . esc_html__( 'Delivered', 'agent-governance' ) . '</span> ' . $when . '</p>';
		}

		return '<p class="description"><span class="axle-badge axle-badge--bad">' . esc_html__( 'Failed', 'agent-governance' ) . '</span> ' . $when . ' ' . esc_html( $status['message'] ) . ' ' . esc_html__( 'Check the address, then send a test.', 'agent-governance' ) . '</p>';
	}

	/**
	 * The "Try this policy" controls, inside the form so they read what is typed in it.
	 *
	 * @param int $days Days asked for last time.
	 */
	private function render_try( int $days ): void {
		echo '<section class="axle-card agg-try" id="agg-try"><h2 class="axle-card__title">' . esc_html__( 'Try this policy', 'agent-governance' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Replays the calls agents made in the last days against the rules and settings above, as they are now in this form, and shows what would change compared with the policies that are live today. Nothing is saved and no call is run.', 'agent-governance' ) . '</p>';
		printf(
			'<div class="axle-toolbar"><label for="agg-try-days">%1$s</label><input type="number" min="1" max="365" id="agg-try-days" name="try_days" value="%2$d" class="small-text"> <button type="submit" class="button agg-try-button" name="agg_try" value="1" formaction="%3$s">%4$s</button><span class="spinner agg-try-spinner" aria-hidden="true"></span></div>',
			esc_html__( 'Days of history', 'agent-governance' ),
			(int) ( $days > 0 ? $days : Simulator::days() ),
			esc_url( Page::url( 'policies' ) . '#agg-try-result' ),
			esc_html__( 'Try this policy', 'agent-governance' )
		);
		echo '</section>';
	}

	/**
	 * What the draft would have done, per agent.
	 *
	 * @param array $result From Simulator::run().
	 */
	private function render_result( array $result ): void {
		$summary = Simulator::summary( $result );
		$days    = (int) $summary['days'];

		echo '<section class="axle-card agg-try-result" id="agg-try-result" role="region" tabindex="-1" aria-labelledby="agg-try-title">';
		echo '<h2 class="axle-card__title" id="agg-try-title">' . esc_html__( 'What this policy would have done', 'agent-governance' ) . '</h2>';

		if ( ! $summary['calls'] ) {
			echo '<div class="axle-empty"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( 'No agent calls in the last %d day, so there is nothing to replay yet. Once agents have made calls, try the policy again.', 'No agent calls in the last %d days, so there is nothing to replay yet. Once agents have made calls, try the policy again.', $days, 'agent-governance' ),
					$days
				)
			) . '</p></div></section>';
			return;
		}

		$sentence = sprintf(
			/* translators: 1: number of calls, 2: number of days. */
			_n( 'Replayed %1$d call from the last %2$d day. Nothing was saved.', 'Replayed %1$d calls from the last %2$d days. Nothing was saved.', (int) $summary['calls'], 'agent-governance' ),
			(int) $summary['calls'],
			$days
		);
		printf( '<p class="agg-try-summary" data-agg-speak="%1$s">%1$s</p>', esc_attr( $sentence ) );

		if ( $summary['truncated'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The log has more calls in this window than one replay reads, so only the newest are included.', 'agent-governance' ) . '</p></div>';
		}
		if ( $summary['partial'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The replay stopped early to keep the screen responsive, so the numbers cover the oldest calls only. Try fewer days.', 'agent-governance' ) . '</p></div>';
		}

		if ( ! $summary['changes'] ) {
			echo '<p><strong>' . esc_html__( 'This policy would not have changed what happened to any of these calls.', 'agent-governance' ) . '</strong></p>';
		} else {
			echo '<ul class="agg-try-changes">';
			foreach ( $summary['changes'] as $change ) {
				echo '<li>' . esc_html( (string) $change['summary'] ) . '</li>';
			}
			echo '</ul>';
		}

		$columns = array(
			'allow'            => __( 'Allowed', 'agent-governance' ),
			'require_approval' => __( 'Held for approval', 'agent-governance' ),
			'deny'             => __( 'Refused', 'agent-governance' ),
			'rate_limit'       => __( 'Rate limited', 'agent-governance' ),
		);

		printf( '<div class="axle-scroll" tabindex="0" role="region" aria-label="%s">', esc_attr__( 'Calls per agent', 'agent-governance' ) );
		echo '<table class="widefat striped agg-table agg-try-table"><thead><tr><th scope="col">' . esc_html__( 'Agent', 'agent-governance' ) . '</th><th scope="col">' . esc_html__( 'Calls', 'agent-governance' ) . '</th>';
		foreach ( $columns as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $summary['agents'] as $agent ) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$d</td>', esc_html( (string) $agent['label'] ), (int) $agent['calls'] );
			foreach ( array_keys( $columns ) as $outcome ) {
				$now  = (int) ( $agent['baseline'][ $outcome ] ?? 0 );
				$then = (int) ( $agent['policy'][ $outcome ] ?? 0 );
				echo '<td>';
				if ( $now === $then ) {
					echo (int) $then;
				} else {
					printf(
						'<div class="axle-diff"><del><span class="screen-reader-text">%3$s</span> %1$d</del><ins><span class="screen-reader-text">%4$s</span> %2$d</ins></div>',
						(int) $now,
						(int) $then,
						esc_html__( 'Live today:', 'agent-governance' ),
						esc_html__( 'With this policy:', 'agent-governance' )
					);
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		foreach ( $summary['agents'] as $agent ) {
			if ( ! $agent['changes'] ) {
				continue;
			}

			printf( '<details class="agg-try-examples"><summary>%s</summary>', esc_html( sprintf( /* translators: %s: agent name. */ __( 'Example calls for %s', 'agent-governance' ), (string) $agent['label'] ) ) );
			foreach ( $agent['changes'] as $change ) {
				echo '<p>' . esc_html( (string) $change['summary'] ) . '</p><ul>';
				foreach ( $change['examples'] as $example ) {
					printf( '<li><code dir="ltr">%1$s</code> %2$s</li>', esc_html( (string) $example['call'] ), AxleUi::relative_time( (int) strtotime( (string) $example['time'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi.
				}
				echo '</ul>';
			}
			echo '</details>';
		}

		echo '<p class="description">' . esc_html__( 'The replay uses the same decision code as live calls. It leaves out the pause and anything the agent_governance_policy filter decides in code.', 'agent-governance' ) . '</p>';
		echo '</section>';
	}

	/**
	 * Labels of the actions.
	 *
	 * @return array<string, string>
	 */
	public static function action_labels(): array {
		return array(
			Settings::ALLOW            => __( 'Allow and log', 'agent-governance' ),
			Settings::REQUIRE_APPROVAL => __( 'Hold for approval', 'agent-governance' ),
			Settings::DENY             => __( 'Deny', 'agent-governance' ),
		);
	}

	/**
	 * One row of the rules table.
	 *
	 * @param int   $index    Row number.
	 * @param array $rule     Rule, empty for a new one.
	 * @param bool  $template Whether this is the empty row new rules start from.
	 */
	private function rule_row( int $index, array $rule, bool $template = false ): void {
		$scopes = array(
			'default' => __( 'Every agent', 'agent-governance' ),
			'agent'   => __( 'Agent (application password)', 'agent-governance' ),
			'user'    => __( 'User', 'agent-governance' ),
			'role'    => __( 'Role', 'agent-governance' ),
		);
		$name   = 'rules[' . $index . ']';

		printf( '<tr class="agg-rule%1$s" data-index="%2$d"><td data-colname="%5$s"><input type="hidden" name="%3$s[id]" value="%4$s"><select name="%3$s[scope]" aria-label="%5$s">', $template ? ' agg-rule--new' : '', (int) $index, esc_attr( $name ), esc_attr( (string) ( $rule['id'] ?? '' ) ), esc_attr__( 'Applies to', 'agent-governance' ) );
		foreach ( $scopes as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $value, $rule['scope'] ?? 'default', false ), esc_html( $label ) );
		}
		printf(
			'</select></td><td data-colname="%4$s"><input type="text" name="%1$s[subject]" value="%2$s" placeholder="%3$s" aria-label="%4$s" class="regular-text agg-subject"></td><td data-colname="%7$s"><input type="text" name="%1$s[pattern]" value="%5$s" placeholder="%6$s" aria-label="%7$s" class="regular-text code" dir="ltr"></td><td data-colname="%8$s"><select name="%1$s[action]" aria-label="%8$s">',
			esc_attr( $name ),
			esc_attr( (string) ( $rule['subject'] ?? '' ) ),
			esc_attr__( 'UUID, user or role', 'agent-governance' ),
			esc_attr__( 'Which one', 'agent-governance' ),
			esc_attr( (string) ( $rule['pattern'] ?? '' ) ),
			esc_attr__( 'my-plugin/delete-*', 'agent-governance' ),
			esc_attr__( 'Ability or route', 'agent-governance' ),
			esc_attr__( 'Then', 'agent-governance' )
		);
		foreach ( self::action_labels() as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $value, $rule['action'] ?? Settings::REQUIRE_APPROVAL, false ), esc_html( $label ) );
		}
		printf(
			'</select></td><td><label class="agg-remove"><input type="checkbox" name="%1$s[remove]" value="1"> %2$s</label></td></tr>',
			esc_attr( $name ),
			esc_html__( 'Remove', 'agent-governance' )
		);
	}
}
