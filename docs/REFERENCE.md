# Reference

## How a call becomes an agent call

A call counts as an agent's when it carries a sign of an agent:

- it comes through the MCP adapter: its REST route (`/mcp/...`), or any MCP tool call, which covers `wp mcp-adapter serve` over STDIO
- it is a REST request signed in with an application password, the Abilities REST API (`/wp-abilities/v1/...`) included

A person in a browser, signed in with the login cookie and a REST nonce, is not an agent, even when a screen runs a destructive ability through the Abilities REST API. Everything else, such as a person saving a post in wp-admin or code calling an ability from PHP, passes straight through and is not logged. `agent_governance_is_agent_request` changes that, for example to hold cookie calls to the Abilities API as well:

```php
add_filter( 'agent_governance_is_agent_request', function ( $is_agent, array $signals ) {
	return $is_agent || 0 === strpos( $signals['route'], '/wp-abilities/v1/' );
}, 10, 2 );
```

Each agent has an **identity**: `app:<uuid>` for an application password, so each one is its own agent, or `user:<id>` without one. The label is the application password's name, or the user's login. MCP calls over HTTP also carry their MCP session.

## What happens to a call

| Kind | What counts | Default |
| --- | --- | --- |
| Read-only | Abilities with the `readonly` annotation, REST `GET`, `HEAD` and `OPTIONS` | Allow and log |
| Write | Other abilities, REST `POST`, `PUT` and `PATCH` | Allow and log |
| Destructive | Abilities with the `destructive` annotation, REST `DELETE` | Hold for approval |

The policy engine decides each call in this order:

1. Agent Governance's own abilities always run. Their permission checks guard them.
2. While agents are **paused**, every write is refused with HTTP 423.
3. Over the **rate limit** (60 calls a minute per agent by default, refused calls and polling not counted), HTTP 429 with a `Retry-After` header: the seconds until the oldest counted call leaves the one-minute window.
4. The first matching **rule** of the most specific scope: the agent (application password), then its user, then its role, then every agent.
5. The **default** for the kind of call.

`agent_governance_policy` then has the last word on every call, except that it cannot let a write through while agents are paused.

A call another plugin refuses before Agent Governance sees it, such as a write Site Read-Only Mode stops during a freeze, is still logged, as **refused** with the other plugin's error code. So is an allowed call that another plugin refuses from inside with one of the codes in `agent_governance_refusal_codes`.

### Rules

A rule has a scope (`agent`, `user`, `role` or `default` for every agent), a subject (the application password UUID, a user ID, login or email, or a role), a pattern and an action (`allow`, `deny` or `require_approval`).

Patterns match the ability name or the REST route. `*` matches anything, slashes included, and `?` one character. A pattern can start with HTTP methods, which then only match REST calls with that method:

| Pattern | Matches |
| --- | --- |
| `my-plugin/*` | every ability of my-plugin |
| `core/get-*` | core's read abilities |
| `/wp/v2/users*` | every users route |
| `DELETE /wp/v2/*` | deletes through the core REST API |
| `POST\|PUT /wp/v2/pages/*` | page edits |

Rules are saved under **Tools > AI Agents > Policies** or with `wp agent-governance add-rule`, and can be added in code with `agent_governance_rules`.

### Abilities and REST routes

On WordPress 7.1 and later, abilities are checked in the `wp_pre_execute_ability` filter, before they run. Held calls are only queued when the ability's own input and permission checks would pass, so nothing waits that could never run. On 6.9 and 7.0, which have no such filter, a wrapper around each ability's execute callback checks it, after core's checks. `WP_Filter_Sentinel`, which arrived with the filter, tells the two apart.

REST requests outside the Abilities API are checked in `rest_pre_dispatch`. The Abilities API, the MCP adapter and `/batch/v1` are left to the ability check and to each request inside the batch.

Abilities that only run other abilities, such as the MCP adapter's `mcp-adapter/execute-ability`, are not checked or logged themselves. The ability they run is, as an MCP call.

## Held requests

A held call is stored with its full input and a dry-run preview, and the agent gets:

```json
{
  "code": "agent_governance_pending",
  "message": "This call needs approval from a site administrator before it runs. It was saved as request #12. ...",
  "data": { "status": 202, "request_id": 12, "poll": { "ability": "agent-governance/get-request", "input": { "id": 12 } } }
}
```

Through MCP only the message gets through, so it names the request.

Approvers (the `agent_governance_approve` capability, given to administrators on activation) get an email and a notice in wp-admin, a count on the menu, and the request with its preview under **Tools > AI Agents**. See [Telling approvers](#telling-approvers).

A site is never left without an approver. If something takes `agent_governance_approve` off every role, such as a role clean-up tool, anyone with `manage_options` approves until a role has it again (`agent_governance_fallback_capability`). People who manage options see a notice with a **Give it back to Administrator** button, Site Health reports it as critical, and `wp agent-governance restore-capability` does the same from the command line.

- **Approve and run** runs the call right away as the user the agent signed in as: the current user is switched for the call and back after. Abilities run through the Abilities API again, so their checks still apply. REST calls are sent again with `rest_do_request()`. The request becomes `executed`, or `failed` with the error, and stores the result.
- **Reject** stores your reason, which the agent sees.
- A request nobody decides **expires** after a day.

Once a request is decided, its input is stored with secrets removed.

Deciding a request is refused to any caller signed in with an application password or coming through MCP, so an agent cannot approve its own request, or another agent's.

### Dry-run previews

The preview shows a summary and each field before and after, computed without saving anything. Built in for REST writes to:

- posts, pages and any post type in the REST API: title, content, excerpt, status, slug, author, parent, order, comments, date, password (masked), featured image and terms. Trash or permanent delete for `DELETE`
- terms: name, slug, description, parent, and how many posts use a term being deleted
- users: name, email, URL, roles and a password change (never shown). Deletes and who gets the content
- settings (`/wp/v2/settings`)
- plugins: activate, deactivate or delete

Statuses and roles show by their names, such as "Published" and "Administrator".

An ability without a preview of its own shows its label and what it says it does, such as `Runs "Reset to theme": Removes Site Editor changes to a template part.`, next to the input (`agent_governance_ability_preview`). Abilities supply a real preview through `agent_governance_dry_run`:

```php
add_filter( 'agent_governance_dry_run', function ( $preview, array $call ) {
	if ( 'my-shop/refund-order' !== $call['target'] ) {
		return $preview;
	}
	$order = wc_get_order( (int) $call['input']['order_id'] );
	return array(
		'summary' => sprintf( 'Refunds %s to %s.', $order->get_formatted_order_total(), $order->get_billing_email() ),
		'changes' => array(
			array( 'field' => 'Status', 'before' => $order->get_status(), 'after' => 'refunded' ),
		),
	);
}, 10, 2 );
```

## Telling approvers

When a call is held, approvers hear about it by email and, if you set one, by webhook. A message holds the agent, the call, the dry-run summary with secrets removed, when the request expires and a link. The link opens the request in wp-admin. You sign in if you are not, read the preview and confirm. Nothing is approved from a message.

- **Bursts.** The first request after a quiet spell goes out at once. Requests that arrive within the group time (5 minutes by default) of the last message wait and go out together as one email per approver and one webhook message. Requests decided in the meantime are left out. `0` sends each one at once.
- **Reminders.** The hourly clean-up reminds approvers once about each request that expires within 4 hours. A request waits a day by default. The setting is in hours; `0` turns reminders off. A lead longer than half the waiting time counts as half of it.
- **Per person.** On their own profile, each approver turns off the email, the reminder and the notice in wp-admin. `wp agent-governance notify-user <user>` does the same, and the `agent-governance/get-notification-settings` ability reads them. Agents cannot change them.
- **Who is told.** Only approvers who could open what the call touches, as in [Who may decide a request](#who-may-decide-a-request). The webhook has no person, so it shows details only for objects the user who saved it could open. For other requests it carries the request ID and the link. Nobody who is not an approver is told anything.
- **Webhook.** Set the address on **Tools > AI Agents > Policies**, pick JSON or Slack, and use **Save and send a test**. The address must be `http` or `https`, and WordPress refuses private network addresses. The last result shows next to the field, and Site Health warns while the webhook fails. Email is not affected when it does.

The JSON body:

```json
{
  "event": "requests_held",
  "site": "Example",
  "site_url": "https://example.com",
  "queue": "https://example.com/wp-admin/tools.php?page=agent-governance&tab=requests",
  "count": 1,
  "requests": [
    {
      "id": 12,
      "agent": "Shop assistant (maria)",
      "kind": "destructive",
      "review_url": "https://example.com/wp-admin/tools.php?page=agent-governance&tab=requests&request=12",
      "expires_at": "2026-11-14T10:00:00+00:00",
      "method": "DELETE",
      "target": "/wp/v2/posts/42",
      "summary": "Moves the Post \"Spring sale\" to the trash.",
      "changes": [ { "field": "Status", "before": "publish", "after": "trash" } ]
    }
  ]
}
```

`event` is `requests_expiring` for a reminder. The Slack format sends `text` and `blocks` that Slack and the services that copy its format accept. `changes` has at most 5 entries, each value cut at 120 characters, and values of secret-looking fields are `[redacted]`.

## Sessions and rollback

Calls of one agent with no gap longer than 30 minutes form a session (`agent_governance_session_gap`). MCP calls over HTTP are grouped by their MCP session instead. An approved call belongs to the session of the call it came from.

While an agent call runs, every post it changes is recorded against the call:

- before a post is updated or trashed, its current state is saved as a revision (the same revision WordPress would compare against) and that revision's ID is recorded, with the status. Post types without revisions get a copy of the title, content and excerpt
- posts it creates are recorded
- posts it deletes permanently are recorded as not reversible

**Roll back session** first shows the plan, then restores each edited post to the revision saved before the agent's first change in the session, with its old status, and moves posts the agent created to the trash. Anything else the session wrote is listed as "not reversible here", with a link to Activity Log with Undo when it is active. Rolled back calls are marked, so a second rollback does nothing.

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `agent_governance_is_agent_request` | filter | `( bool $is_agent, array $signals )`. Whether a call is an agent's. `$signals`: `user_id`, `app_password` (`uuid`, `name`, `user_id`, or null), `route`, `method`, `mcp_session`, `mcp` |
| `agent_governance_identity` | filter | `( array $identity, array $signals )`. `key` (max 191 characters), `label`, `user_id`, `via`, `session`. A result without a key is ignored |
| `agent_governance_agent_routes` | filter | `( array $routes )`. REST route prefix => label of the routes that make a call an agent call, whoever signs in. Default `/mcp/` |
| `agent_governance_policy` | filter | `( array $decision, array $call, array $identity )`. The final decision: `action` (`allow`, `deny`, `require_approval`), `reason`, `rule`. Invalid actions are ignored, and a pause still refuses writes |
| `agent_governance_default_rules` | filter | `( array $defaults )`. Action per kind: `readonly`, `write`, `destructive`. Invalid values keep the setting |
| `agent_governance_rules` | filter | `( array[] $rules )`. Add rules in code: `scope`, `subject`, `pattern`, `action`. Invalid rules are dropped |
| `agent_governance_simulation_days` | filter | `( int $days )`. Days of history a simulation replays. Default 30, 1 to 365 |
| `agent_governance_simulation_max_calls` | filter | `( int $calls )`. Most logged calls one replay reads, the newest kept. Default 20000, 100 to 200000 |
| `agent_governance_simulation_examples` | filter | `( int $examples )`. Example calls kept per agent and kind of change. Default 3, 0 to 10 |
| `agent_governance_simulation_seconds` | filter | `( int $seconds )`. Seconds a replay may run before it stops with a partial result. Default 20 |
| `agent_governance_policy_simulated` | action | `( array $result, array $policies, int $days )`. After a replay. Nothing was changed |
| `agent_governance_suggestion_threshold` | filter | `( int $times )`. Approvals, with no rejection, before an allow rule is suggested. Default 5, at least 2 |
| `agent_governance_suggestions_limit` | filter | `( int $limit )`. Suggestions shown at once. Default 3 |
| `agent_governance_suggestions` | filter | `( array[] $suggestions )`. The suggested rules: `key`, `identity`, `agent`, `pattern`, `approved`, `rule` |
| `agent_governance_suggestion_accepted` | action | `( array $rule, array $suggestion, int $user_id )`. Someone accepted a suggested rule |
| `agent_governance_suggestion_dismissed` | action | `( string $key, int $user_id )`. Someone hid a suggestion |
| `agent_governance_can_review_request` | filter | `( bool $allowed, array $request, int $user_id )`. Whether a user may see what a request touches and decide it |
| `agent_governance_show_admin_bar` | filter | `( bool $show )`. Whether approvers see the waiting count in the admin bar. Default true |
| `agent_governance_evidence` | filter | `( array $payload, string $session )`. The evidence file before it is signed |
| `agent_governance_evidence_key` | filter | `( string $key )`. The key evidence is signed with. Default derived from the authentication salt |
| `agent_governance_evidence_exported` | action | `( string $session, int $calls )`. After an evidence file was made |
| `agent_governance_approver_capability` | filter | `( string $capability )`. Who may approve, reject, roll back and see the log. Default `agent_governance_approve` |
| `agent_governance_approver_roles` | filter | `( string[] $roles )`. Roles given the approver capability on activation and by the "Give it back" button. Default `administrator` |
| `agent_governance_fallback_capability` | filter | `( string $capability )`. Who approves while no role has the approver capability. Default `manage_options`. An empty value keeps the default |
| `agent_governance_remove_capability_on_deactivate` | filter | `( bool $remove )`. Whether deactivating takes the approver capability off every role. Default true. Activating gives it back |
| `agent_governance_no_approver_message` | filter | `( string $message )`. The wp-admin notice while no role has the approver capability |
| `agent_governance_settings_capability` | filter | `( string $capability )`. Who may change policies and pause agents. Default `manage_options` |
| `agent_governance_rate_limit` | filter | `( int $per_minute, string $identity )`. 0 turns it off. Default from the setting, 60 |
| `agent_governance_request_ttl` | filter | `( int $seconds )`. How long a held call waits. Default one day, 60 seconds to 30 days |
| `agent_governance_redact_keys` | filter | `( string[] $keys )`. Input keys whose values never reach the log, matched as a word of the key: password, pass, pwd, secret, token, api_key, auth, cookie, nonce and more |
| `agent_governance_payload_limit` | filter | `( int $bytes, string $context )`. Most bytes stored per `input`, `result` (16384) or `dry_run` (65536). Bigger values become a marked preview |
| `agent_governance_retention_days` | filter | `( int $days )`. Days log entries and decided requests are kept. Default 30 |
| `agent_governance_dry_run` | filter | `( array\|null $preview, array $call )`. Your own preview: `summary`, `changes` (`field`, `before`, `after`), optional `object` (`type`, `id`, `label`, `url`) |
| `agent_governance_ability_preview` | filter | `( array $preview, string $name, mixed $input )`. The preview of an ability without its own: `supported` (false) and `summary` from the ability's label and description |
| `agent_governance_notify_recipients` | filter | `( string[] $emails, array $request, string $kind )`. Who gets the email about a held call or a reminder (`held` or `reminder`). Default every approver who could open the call's object and has not turned the email off, at most 50 |
| `agent_governance_notification_email` | filter | `( array\|false $email, array $request, array $requests, string $kind )`. `to`, `subject`, `message`, `headers`. False skips it. `$requests` holds every request in the email, more than one for a burst |
| `agent_governance_batch_window` | filter | `( int $seconds )`. How long a burst of held requests is grouped into one message. Default from the setting, 5 minutes. 0 to 1 hour |
| `agent_governance_reminder_lead` | filter | `( int $seconds )`. How long before a request expires approvers are reminded. Default from the setting, 4 hours. 0 turns it off |
| `agent_governance_webhook_url` | filter | `( string $url )`. The webhook address, for example from a constant |
| `agent_governance_webhook_payload` | filter | `( array $payload, array $requests, string $kind )`. The body before it is sent as JSON. `$kind` is `held` or `reminder` |
| `agent_governance_webhook_args` | filter | `( array $args, string $kind )`. The `wp_safe_remote_post()` arguments, for a header such as a token |
| `agent_governance_default_preferences` | filter | `( array $defaults )`. What `email`, `reminder` and `notice` are for a person who has not chosen. Default all true |
| `agent_governance_notification_queued` | action | `( array $request, int $window )`. A held request waits for the next message because one just went out |
| `agent_governance_approvers_notified` | action | `( array $requests, string $kind, bool $sent )`. After approvers were told, by email or webhook |
| `agent_governance_reminder_sent` | action | `( array $requests )`. After approvers were reminded about requests that expire soon |
| `agent_governance_webhook_sent` | action | `( bool $ok, array $requests, string $kind )`. After a webhook message was sent, and whether the receiver accepted it |
| `agent_governance_preferences_saved` | action | `( int $user_id, array $new, array $old )`. A person changed their notification settings |
| `agent_governance_notice_message` | filter | `( string $message, int $count )`. The wp-admin notice about waiting calls |
| `agent_governance_pending_message` | filter | `( string $message, int $id, array $call )`. What the agent reads when its call is held. Keep the request ID in it |
| `agent_governance_denied_message` | filter | `( string $message, array $call, array $decision )`. What the agent reads when its call is refused by a rule, the pause or the rate limit |
| `agent_governance_session_gap` | filter | `( int $seconds )`. Quiet time that starts a new session. Default 1800 |
| `agent_governance_list_per_page` | filter | `( int $per_page )`. Rows per page on the screens. Default 30 |
| `agent_governance_menu_title` | filter | `( string $title )`. The menu label under Tools. Default "AI Agents", short enough to fit on one line with the count. An empty value keeps the default |
| `agent_governance_via_labels` | filter | `( array $labels )`. How a call came in, as shown on the screens: `app-password` => "Application password", `mcp` => "MCP", `custom` => "Custom code" |
| `agent_governance_result_labels` | filter | `( array $labels )`. Result codes in words on the Activity tab, such as `agent_governance_pending` => "Waiting for approval". Other codes show as they are |
| `agent_governance_refusal_codes` | filter | `( string[] $codes )`. Error codes that mean another plugin refused an allowed call from inside it, so it is logged as refused. Default `site_read_only_mode` |
| `agent_governance_dispatch_abilities` | filter | `( string[] $abilities )`. Abilities that only run other abilities. Default `mcp-adapter/execute-ability` |
| `agent_governance_passthrough_routes` | filter | `( string[] $routes )`. REST routes the REST check leaves to what runs inside them. Default the agent routes, `/wp-abilities/v1/` and `/batch/v1` |
| `agent_governance_untracked_post_types` | filter | `( string[] $types )`. Post types sessions do not record. Default `revision`, `customize_changeset`, `oembed_cache`, `user_request` |
| `agent_governance_client_ip` | filter | `( string $ip )`. The IP address stored with each call. Return an empty string to store none |
| `agent_governance_undo_url` | filter | `( string $url )`. Where to undo changes a rollback cannot. Default Activity Log with Undo's screen when it is active |
| `agent_governance_call_logged` | action | `( array $row )`. An agent call was written to the log |
| `agent_governance_call_denied` | action | `( array $call, array $identity, array $decision )`. A call was refused by a rule, the pause or the rate limit |
| `agent_governance_call_refused` | action | `( array $call, array $identity, WP_Error $error )`. Another plugin refused an agent call before it ran, such as Site Read-Only Mode during a freeze |
| `agent_governance_request_held` | action | `( array $request, array $call, array $decision )`. A call was held for approval |
| `agent_governance_request_approved` | action | `( array $request, int $approver_id )`. Before an approved call runs |
| `agent_governance_request_rejected` | action | `( array $request, int $approver_id, string $reason )`. A held call was rejected |
| `agent_governance_request_executed` | action | `( array $request, mixed $result )`. An approved call ran. `status` is `executed` or `failed` |
| `agent_governance_request_expired` | action | `( array $request )`. A held call expired without a decision |
| `agent_governance_paused` | action | `( int $user_id )`. Agent writes were paused |
| `agent_governance_resumed` | action | `( int $user_id )`. Agent writes were allowed again |
| `agent_governance_session_rolled_back` | action | `( string $scope, array $result, array $plan )`. A session (or an agent's calls since a time) was rolled back. `$result`: `restored`, `trashed`, `failed`, `skipped` |
| `agent_governance_settings_saved` | action | `( array $settings, array $old )`. The settings changed |
| `agent_governance_rules_saved` | action | `( array[] $rules, array[] $old )`. The saved rules changed |
| `agent_governance_cleaned_up` | action | `( array $result )`. After the hourly clean-up: `expired`, `log`, `requests` |
| `agent_governance_capability_restored` | action | `( string[] $roles, int $user_id )`. The approver capability was given back, from the notice or WP-CLI |
| `agent_governance_capability_removed` | action | `( string[] $roles )`. Deactivating took the approver capability off these roles |

`$call` is `source` (`ability` or `rest`), `target` (the ability name or REST route), `method`, `kind` (`readonly`, `write`, `destructive`) and `input`.

### JavaScript hooks

The screen's script reads these through `wp.hooks` on each use, so scripts loaded later can add them.

| Hook | Type | Use |
| --- | --- | --- |
| `agentGovernance.confirmMessage` | filter | `( message, action, button )`. The question before approve, reject, rollback or pause. Return an empty string to skip it |
| `agentGovernance.confirmed` | action | `( action, button )`. The person confirmed |
| `agentGovernance.ruleAdded` | action | `( row )`. A row was added to the rules table |

### Examples

```php
// Hold every write by agents of contributors and authors, not just deletes.
add_filter( 'agent_governance_policy', function ( $decision, $call, $identity ) {
	$user = get_userdata( $identity['user_id'] );
	if ( $user && array_intersect( array( 'contributor', 'author' ), $user->roles ) && 'readonly' !== $call['kind'] && 'allow' === $decision['action'] ) {
		$decision['action'] = 'require_approval';
		$decision['reason'] = 'junior_role';
	}
	return $decision;
}, 10, 3 );

// Send held calls to a Slack channel as well.
add_action( 'agent_governance_request_held', function ( $request ) {
	wp_remote_post( MY_SLACK_WEBHOOK, array( 'body' => wp_json_encode( array( 'text' => sprintf( 'Agent request #%d: %s %s', $request['id'], $request['method'], $request['target'] ) ) ) ) );
} );

// Night shift: no agent writes between 22:00 and 06:00 site time.
add_filter( 'agent_governance_default_rules', function ( $defaults ) {
	$hour = (int) wp_date( 'G' );
	if ( $hour >= 22 || $hour < 6 ) {
		$defaults['write'] = 'require_approval';
	}
	return $defaults;
} );
```

## Abilities

On WordPress 6.9 and later, Agent Governance registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability.

| Ability | Does | Needs |
| --- | --- | --- |
| `agent-governance/get-request` | Read-only. A held request by `id`: `status` (pending, approved, rejected, expired, executed, failed), `message`, `reason`, `preview`, `expires` and `result` | the user who made it, or an approver |
| `agent-governance/list-my-requests` | Read-only. The calling agent's held requests (its application password, or else its user), newest first. `status`, `limit` | any signed-in user |
| `agent-governance/list-pending` | Read-only. Requests waiting for a decision. `limit` | an approver |
| `agent-governance/get-notification-settings` | Read-only. The signed-in approver's own `email`, `reminder` and `notice` settings, and the site's: email, whether a webhook is set (never its address), format, group minutes and reminder hours | an approver |
| `agent-governance/decide-request` | Destructive. `id`, `decision` (`approve` or `reject`), `reason`. Refused through application passwords and MCP | an approver signed in as themselves |
| `agent-governance/simulate-policy` | Read-only. Replays the log against a draft: `days`, `rules`, `defaults`, `rate_limit`, `without_rule`. Per agent: counts, changes and example calls. Saves nothing | someone who may change policies |
| `agent-governance/export-session-evidence` | Read-only. The signed evidence file of a session by `session` | an approver |

Run them over REST with `GET .../get-request/run?input[id]=12` and `POST .../decide-request/run` with `{ "input": { "id": 12, "decision": "reject", "reason": "Not this page." } }`.

## WP-CLI

```sh
wp agent-governance pending [--format=<format>]
wp agent-governance show <id> [--format=json|yaml]
wp agent-governance approve <id>                      # runs it as the agent's user
wp agent-governance reject <id> [--reason=<reason>]
wp agent-governance log [--identity=<agent>] [--status=allowed|denied|held|executed|failed|refused] [--session=<session>] [--limit=<n>] [--format=<format>]
wp agent-governance sessions [--identity=<agent>] [--format=<format>]
wp agent-governance rollback <agent> [--since=<when>] [--session=<session>] [--dry-run] [--yes]
wp agent-governance pause
wp agent-governance resume
wp agent-governance status [--format=<format>]
wp agent-governance rules [--format=<format>]
wp agent-governance add-rule <pattern> <allow|deny|require_approval> [--scope=default|agent|user|role] [--subject=<subject>]
wp agent-governance delete-rule <id>
wp agent-governance simulate [--policy=<file>] [--without-rule=<id>] [--days=<n>] [--format=<format>]   # try a draft on past calls, saves nothing
wp agent-governance evidence <session> [--file=<file>]   # signed JSON evidence for a session
wp agent-governance verify-evidence <file>               # check the signature
wp agent-governance settings [--rate-limit=<n>] [--retention-days=<n>] [--email=on|off] [--notice=on|off] [--webhook-url=<url>] [--webhook-format=json|slack] [--batch-minutes=<n>] [--reminder-hours=<n>] [--readonly=<action>] [--write=<action>] [--destructive=<action>]
wp agent-governance notify-test                       # send a test message to the webhook
wp agent-governance notify-user <user> [--email=on|off] [--reminder=on|off] [--notice=on|off] [--format=<format>]
wp agent-governance remind                            # remind about requests close to expiring now
wp agent-governance cleanup                           # expire overdue requests and purge old log rows now
wp agent-governance restore-capability                # give the approver capability back to Administrator
```

`<agent>` is an application password UUID, `app:<uuid>`, `user:<id>` or a user login. `--since` takes anything `strtotime()` reads, such as `"2 hours ago"`, and defaults to the start of the agent's latest session.

## Trying a policy

On the **Policies** tab, **Try this policy** replays the calls in the audit log (30 days by default, at most 20,000 calls, the newest if there are more) against the rules, defaults and rate limit as they are in the form, and against the policies live today. It shows per agent how many calls would be allowed, held for approval, refused or rate limited, which of them change ("14 calls that ran would have waited for approval") and example calls behind each change. Nothing is saved and no call is run. The form keeps the draft, so **Save policies** saves exactly what was tried.

The replay calls `Policy::resolve()`, the same function live calls use, so the two cannot drift apart. The rate limit is counted over the replayed calls, one minute at a time. The replay leaves out the pause, and anything the `agent_governance_policy` filter decides in code. Rules defined in code with `agent_governance_rules` stay in the draft. Calls that ran after approval, calls another plugin refused and this plugin's own abilities are not replayed.

```sh
wp agent-governance simulate --policy=tighter.json --days=14
wp agent-governance simulate --without-rule=3fa9c1d2 --format=json
```

The policy file is JSON with `rules`, `defaults` and `rate_limit`. Anything left out keeps its live value. The same replay is the read-only ability `agent-governance/simulate-policy`, for people who may change policies.

### Suggested rules

When the same agent's same kind of call (an ability name, or a REST method and route with ID segments turned into `*`) has been approved 5 times in the last 30 days and never rejected, and it still waits for approval, the **Requests** tab suggests an allow rule for that agent. The card shows the replay of the rule over the same days. **Always allow it** adds the rule as the first rule and shows an **Undo** link. **Not now** hides the suggestion for you. Suggestions are only shown to people who may change policies, and nothing is applied by itself.

## Who may decide a request

The approver capability opens the queue. Seeing a preview, an input or a result, and approving or rejecting, also needs access to what the call touches: `edit_post` for the post, `edit_term` for the term, `edit_user` for the user, `manage_options` for settings, `activate_plugins` or `delete_plugins` for plugins, `create_users` and the post type's `create_posts` for new items. An approver without it sees a notice instead, is not emailed the summary, and `get-request`, `list-pending` and `decide-request` leave the request out or refuse it with `agent_governance_forbidden`. Rolling back a session works the same way: posts the person may not edit are left alone and the session stays open. WP-CLI, which has no signed-in person, is trusted. `agent_governance_can_review_request` adds checks for the objects of your own abilities.

## Evidence

On a session's review screen, **Download signed evidence** gives a JSON file: the calls, what was decided for each, who approved, how long it took and when it was rolled back. Inputs and results are left out. The file carries an HMAC-SHA256 made with a key only this site has (`agent_governance_evidence_key` changes it), so `wp agent-governance verify-evidence <file>` can say whether the file changed. The ability `agent-governance/export-session-evidence` returns the same file.

## Data

| Where | Contents |
| --- | --- |
| `{prefix}agent_governance_log` table | One row per agent call: time, identity, agent name, user, session, how it came in, ability or route, method, kind, outcome, reason, result, request ID, duration, IP address, input (secrets removed, cut at 16 KB), the posts it changed, and when it was rolled back |
| `{prefix}agent_governance_requests` table | One row per held call: time, identity, agent, user, session, ability or route, method, input (full until decided, then with secrets removed), status, who decided and when, reason, result and the dry-run preview |
| `agent_governance_settings` option | Pause, rate limit, retention, notifications and the defaults per kind |
| `agent_governance_rules` option | Saved rules |
| `agent_governance_outbox`, `agent_governance_last_notified`, `agent_governance_reminded` and `agent_governance_webhook_status` options | Requests waiting for the end of a burst, when the last message went out, requests already reminded about, and how the last webhook message went. Not loaded on every request |
| `agent_governance_notify` user meta | One person's email, reminder and notice settings |
| `agent_governance_db_version` option | Table version. Removed on deactivation, so the next activation sets the capability up again |
| `agent_governance_approve` capability | Given to administrators on activation. Taken off every role on deactivation and on uninstall, so no role keeps it while the plugin is off |

The `agent_governance_flush_notifications` event sends a burst once its group time is over. The `agent_governance_cleanup` cron event runs hourly, reminds approvers about requests close to expiring, and it expires requests that waited too long and deletes log rows and decided requests older than the retention period, 1000 of each per run. The count of waiting requests is cached in the object cache until a request changes.

Uninstalling drops both tables, deletes the options and user meta, removes the capability and the cron event, on every site of a network.

## Privacy

The log and the queue hold user IDs, IP addresses and what agents sent, which can be personal data. So:

- secret-looking input keys are removed before anything reaches the log, and from a held request once it is decided
- **Tools > Export Personal Data** lists a user's agent calls and held requests, and their notification settings
- **Tools > Erase Personal Data** removes the user, IP address and input from their calls and requests, keeping a record that the calls happened, and rejects their pending requests
- the webhook gets no secrets and, for objects its owner could not open, no details
- **Settings > Privacy** gets suggested text
- log entries are deleted after 30 days (`agent_governance_retention_days`), and `agent_governance_client_ip` can keep IP addresses out entirely

## Limits

- XML-RPC calls with an application password are not checked. Agents use the REST API and the Abilities API.
- Agents that sign in some other way, such as a JWT or OAuth plugin, are not recognised by default. Mark their requests with `agent_governance_is_agent_request`.
- Only post changes can be rolled back here. Options, terms, users, menus and anything deleted permanently are listed as not reversible.
- A held REST call is sent again with the same route, query and JSON body. Calls that upload files are not replayed with the file.
- The rate limit counts calls in the log, so it is exact per site but costs a small indexed query per agent call.
- A webhook has one address for the whole site and no sign-in, so it cannot tell who reads the channel. Use a private channel, and keep in mind that details are shown for what the person who saved it could open.
- Burst messages and reminders go out when WP-Cron runs. On a site with cron turned off, run it from the system.
- The IP address is the one PHP sees. Behind a proxy that does not pass the visitor's address through, it is the proxy's; use `agent_governance_client_ip` to read a trusted header.
