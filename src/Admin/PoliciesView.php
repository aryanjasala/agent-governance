<?php
/**
 * The Policies tab.
 *
 * @package AryanX\AgentGovernance
 */

namespace AryanX\AgentGovernance\Admin;

use AryanX\AgentGovernance\Log;
use AryanX\AgentGovernance\Settings;

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
	 * Sets up the tab.
	 *
	 * @param Settings $settings Settings.
	 * @param Log      $log      The audit log.
	 */
	public function __construct( Settings $settings, Log $log ) {
		$this->settings = $settings;
		$this->log      = $log;
	}

	/**
	 * Renders the form.
	 */
	public function render(): void {
		$settings = $this->settings->all();
		$actions  = self::action_labels();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="agg-policies">';
		echo '<input type="hidden" name="action" value="agent_governance_policies">';
		wp_nonce_field( 'agent_governance_policies' );

		echo '<h2>' . esc_html__( 'Rules', 'agent-governance' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The first matching rule wins, checking rules for the agent first, then its user, then its role, then every agent. Patterns match ability names or REST routes: * matches anything. Start a pattern with a method to match only REST calls with it, such as "DELETE /wp/v2/*".', 'agent-governance' ) . '</p>';

		echo '<table class="widefat agg-rules"><thead><tr><th scope="col">' . esc_html__( 'Applies to', 'agent-governance' ) . '</th><th scope="col">' . esc_html__( 'Which one', 'agent-governance' ) . '</th><th scope="col">' . esc_html__( 'Ability or route', 'agent-governance' ) . '</th><th scope="col">' . esc_html__( 'Then', 'agent-governance' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Remove', 'agent-governance' ) . '</span></th></tr></thead><tbody>';
		$index = 0;
		foreach ( $this->settings->rules() as $rule ) {
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

		echo '<h2>' . esc_html__( 'When no rule matches', 'agent-governance' ) . '</h2><table class="form-table" role="presentation"><tbody>';
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
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Limits and notifications', 'agent-governance' ) . '</h2><table class="form-table" role="presentation"><tbody>';
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
		printf(
			'<tr><th scope="row"><label for="agg-retention">%1$s</label></th><td><input type="number" min="1" max="3650" id="agg-retention" name="retention_days" value="%2$d" class="small-text"> %3$s</td></tr>',
			esc_html__( 'Keep the log', 'agent-governance' ),
			(int) $settings['retention_days'],
			esc_html__( 'days', 'agent-governance' )
		);
		echo '</tbody></table>';

		submit_button( __( 'Save policies', 'agent-governance' ) );
		echo '</form>';
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

		printf( '<tr class="agg-rule%1$s" data-index="%2$d"><td><input type="hidden" name="%3$s[id]" value="%4$s"><select name="%3$s[scope]" aria-label="%5$s">', $template ? ' agg-rule--new' : '', (int) $index, esc_attr( $name ), esc_attr( (string) ( $rule['id'] ?? '' ) ), esc_attr__( 'Applies to', 'agent-governance' ) );
		foreach ( $scopes as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), selected( $value, $rule['scope'] ?? 'default', false ), esc_html( $label ) );
		}
		printf(
			'</select></td><td><input type="text" name="%1$s[subject]" value="%2$s" placeholder="%3$s" aria-label="%4$s" class="regular-text agg-subject"></td><td><input type="text" name="%1$s[pattern]" value="%5$s" placeholder="%6$s" aria-label="%7$s" class="regular-text code"></td><td><select name="%1$s[action]" aria-label="%8$s">',
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
