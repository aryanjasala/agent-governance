# Reference

## How a call becomes an agent call

A call counts as an agent's when it comes through:

- the MCP adapter: its REST route (`/mcp/...`), or any MCP tool call, which covers `wp mcp-adapter serve` over STDIO
- the Abilities REST API (`/wp-abilities/v1/...`), whoever signs in
- any REST request signed in with an application password

Everything else, such as a person saving a post in wp-admin or code calling an ability from PHP, passes straight through and is not logged. `agent_governance_is_agent_request` changes that.

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
3. Over the **rate limit** (60 calls a minute per agent by default, refused calls and polling not counted), HTTP 429.
4. The first matching **rule** of the most specific scope: the agent (application password), then its user, then its role, then every agent.
5. The **default** for the kind of call.

`agent_governance_policy` then has the last word on every call, except that it cannot let a write through while agents are paused.

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

Rules are saved under **Tools > Agent Governance > Policies** or with `wp agent-governance add-rule`, and can be added in code with `agent_governance_rules`.

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

Approvers (the `agent_governance_approve` capability, given to administrators on activation) get an email and a notice in wp-admin, a count on the menu, and the request with its preview under **Tools > Agent Governance**.

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

Abilities supply their own through `agent_governance_dry_run`:

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
| `agent_governance_agent_routes` | filter | `( array $routes )`. REST route prefix => label of the routes that make a call an agent call. Default `/mcp/` and `/wp-abilities/v1/` |
| `agent_governance_policy` | filter | `( array $decision, array $call, array $identity )`. The final decision: `action` (`allow`, `deny`, `require_approval`), `reason`, `rule`. Invalid actions are ignored, and a pause still refuses writes |
| `agent_governance_default_rules` | filter | `( array $defaults )`. Action per kind: `readonly`, `write`, `destructive`. Invalid values keep the setting |
| `agent_governance_rules` | filter | `( array[] $rules )`. Add rules in code: `scope`, `subject`, `pattern`, `action`. Invalid rules are dropped |
| `agent_governance_approver_capability` | filter | `( string $capability )`. Who may approve, reject, roll back and see the log. Default `agent_governance_approve` |
| `agent_governance_approver_roles` | filter | `( string[] $roles )`. Roles given the approver capability on activation. Default `administrator` |
| `agent_governance_settings_capability` | filter | `( string $capability )`. Who may change policies and pause agents. Default `manage_options` |
| `agent_governance_rate_limit` | filter | `( int $per_minute, string $identity )`. 0 turns it off. Default from the setting, 60 |
| `agent_governance_request_ttl` | filter | `( int $seconds )`. How long a held call waits. Default one day, 60 seconds to 30 days |
| `agent_governance_redact_keys` | filter | `( string[] $keys )`. Input keys whose values never reach the log, matched as a word of the key: password, pass, pwd, secret, token, api_key, auth, cookie, nonce and more |
| `agent_governance_payload_limit` | filter | `( int $bytes, string $context )`. Most bytes stored per `input`, `result` (16384) or `dry_run` (65536). Bigger values become a marked preview |
| `agent_governance_retention_days` | filter | `( int $days )`. Days log entries and decided requests are kept. Default 30 |
| `agent_governance_dry_run` | filter | `( array\|null $preview, array $call )`. Your own preview: `summary`, `changes` (`field`, `before`, `after`), optional `object` (`type`, `id`, `label`, `url`) |
| `agent_governance_notify_recipients` | filter | `( string[] $emails, array $request )`. Who gets the email about a held call. Default every approver, at most 50 |
| `agent_governance_notification_email` | filter | `( array\|false $email, array $request )`. `to`, `subject`, `message`, `headers`. False skips it |
| `agent_governance_notice_message` | filter | `( string $message, int $count )`. The wp-admin notice about waiting calls |
| `agent_governance_pending_message` | filter | `( string $message, int $id, array $call )`. What the agent reads when its call is held. Keep the request ID in it |
| `agent_governance_denied_message` | filter | `( string $message, array $call, array $decision )`. What the agent reads when its call is refused by a rule, the pause or the rate limit |
| `agent_governance_session_gap` | filter | `( int $seconds )`. Quiet time that starts a new session. Default 1800 |
| `agent_governance_list_per_page` | filter | `( int $per_page )`. Rows per page on the screens. Default 30 |
| `agent_governance_dispatch_abilities` | filter | `( string[] $abilities )`. Abilities that only run other abilities. Default `mcp-adapter/execute-ability` |
| `agent_governance_passthrough_routes` | filter | `( string[] $routes )`. REST routes the REST check leaves to what runs inside them. Default the agent routes and `/batch/v1` |
| `agent_governance_untracked_post_types` | filter | `( string[] $types )`. Post types sessions do not record. Default `revision`, `customize_changeset`, `oembed_cache`, `user_request` |
| `agent_governance_client_ip` | filter | `( string $ip )`. The IP address stored with each call. Return an empty string to store none |
| `agent_governance_undo_url` | filter | `( string $url )`. Where to undo changes a rollback cannot. Default Activity Log with Undo's screen when it is active |
| `agent_governance_call_logged` | action | `( array $row )`. An agent call was written to the log |
| `agent_governance_call_denied` | action | `( array $call, array $identity, array $decision )`. A call was refused |
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
| `agent-governance/decide-request` | Destructive. `id`, `decision` (`approve` or `reject`), `reason`. Refused through application passwords and MCP | an approver signed in as themselves |

Run them over REST with `GET .../get-request/run?input[id]=12` and `POST .../decide-request/run` with `{ "input": { "id": 12, "decision": "reject", "reason": "Not this page." } }`.

## WP-CLI

```sh
wp agent-governance pending [--format=<format>]
wp agent-governance show <id> [--format=json|yaml]
wp agent-governance approve <id>                      # runs it as the agent's user
wp agent-governance reject <id> [--reason=<reason>]
wp agent-governance log [--identity=<agent>] [--status=allowed|denied|held|executed|failed] [--session=<session>] [--limit=<n>] [--format=<format>]
wp agent-governance sessions [--identity=<agent>] [--format=<format>]
wp agent-governance rollback <agent> [--since=<when>] [--session=<session>] [--dry-run] [--yes]
wp agent-governance pause
wp agent-governance resume
wp agent-governance status [--format=<format>]
wp agent-governance rules [--format=<format>]
wp agent-governance add-rule <pattern> <allow|deny|require_approval> [--scope=default|agent|user|role] [--subject=<subject>]
wp agent-governance delete-rule <id>
wp agent-governance settings [--rate-limit=<n>] [--retention-days=<n>] [--email=on|off] [--notice=on|off] [--readonly=<action>] [--write=<action>] [--destructive=<action>]
wp agent-governance cleanup                           # expire overdue requests and purge old log rows now
```

`<agent>` is an application password UUID, `app:<uuid>`, `user:<id>` or a user login. `--since` takes anything `strtotime()` reads, such as `"2 hours ago"`, and defaults to the start of the agent's latest session.

## Data

| Where | Contents |
| --- | --- |
| `{prefix}agent_governance_log` table | One row per agent call: time, identity, agent name, user, session, how it came in, ability or route, method, kind, outcome, reason, result, request ID, duration, IP address, input (secrets removed, cut at 16 KB), the posts it changed, and when it was rolled back |
| `{prefix}agent_governance_requests` table | One row per held call: time, identity, agent, user, session, ability or route, method, input (full until decided, then with secrets removed), status, who decided and when, reason, result and the dry-run preview |
| `agent_governance_settings` option | Pause, rate limit, retention, notifications and the defaults per kind |
| `agent_governance_rules` option | Saved rules |
| `agent_governance_db_version` option | Table version |
| `agent_governance_approve` capability | Given to administrators on activation |

The `agent_governance_cleanup` cron event runs hourly: it expires requests that waited too long and deletes log rows and decided requests older than the retention period, 1000 of each per run. The count of waiting requests is cached in the object cache until a request changes.

Uninstalling drops both tables, deletes the options, removes the capability and the cron event, on every site of a network.

## Privacy

The log and the queue hold user IDs, IP addresses and what agents sent, which can be personal data. So:

- secret-looking input keys are removed before anything reaches the log, and from a held request once it is decided
- **Tools > Export Personal Data** lists a user's agent calls and held requests
- **Tools > Erase Personal Data** removes the user, IP address and input from their calls and requests, keeping a record that the calls happened, and rejects their pending requests
- **Settings > Privacy** gets suggested text
- log entries are deleted after 30 days (`agent_governance_retention_days`), and `agent_governance_client_ip` can keep IP addresses out entirely

## Limits

- XML-RPC calls with an application password are not checked. Agents use the REST API and the Abilities API.
- Only post changes can be rolled back here. Options, terms, users, menus and anything deleted permanently are listed as not reversible.
- A held REST call is sent again with the same route, query and JSON body. Calls that upload files are not replayed with the file.
- The rate limit counts calls in the log, so it is exact per site but costs a small indexed query per agent call.
- The IP address is the one PHP sees. Behind a proxy that does not pass the visitor's address through, it is the proxy's; use `agent_governance_client_ip` to read a trusted header.
