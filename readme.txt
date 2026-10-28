=== Agent Governance ===
Contributors: aryanjasala
Tags: ai, mcp, abilities, approval, audit log
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hold risky AI agent actions until a person approves them, see what they would change, and roll back an agent's session.

== Description ==

AI agents now work on WordPress sites through the Abilities API, the MCP adapter and application passwords. You can already limit which abilities an agent may call. What is missing is a person in the loop: a way to stop a risky call before it runs, see exactly what it would change, and undo what an agent did in a session.

Agent Governance adds that.

**Knows which calls are agents:**

* Calls through the MCP adapter (over HTTP or `wp mcp-adapter serve`), and any REST request signed in with an application password, the Abilities REST API included.
* Each application password is its own agent, so "Shop assistant" and "Content assistant" get their own rules, limits and log.
* A person in wp-admin is not an agent, even when a screen of another plugin runs abilities in the browser. Those calls run straight away.

**Holds risky calls for approval:**

* By default, read-only calls run, writes run and are logged, and destructive calls (abilities marked destructive, REST DELETE) wait for approval.
* A held call gets HTTP 202 and a request ID. The agent polls the `agent-governance/get-request` ability for the outcome and the result.
* Approvers get an email and a notice, and see the call under Tools > AI Agents with a **dry-run preview**: each field before and after, for posts, pages, terms, users, settings and plugins. Abilities can add their own preview; the rest show what the ability says it does.
* Approve runs the call as the user the agent signed in as. Reject sends the agent your reason. Requests nobody decides expire after a day.
* An agent can never approve its own request: deciding is refused through application passwords and MCP.

**Policies:**

* Rules per agent, per user, per role or for every agent, on ability names and REST routes with `*` patterns: allow, deny or hold for approval.
* A rate limit per agent (60 calls a minute by default, with a `Retry-After` header) and a **Pause** button that refuses every agent write at once, while reads keep working.

**Audit log and rollback:**

* Every agent call: who, what, the input (with passwords, tokens and keys removed), the outcome and how long it took. Calls another plugin refused, such as Axle Read-Only Mode during a freeze, are in the log too.
* Calls are grouped into sessions. **Roll back session** restores each post the agent edited to the revision saved before its first change and moves posts it created to the trash, after showing you the plan. Other changes are listed as not reversible here.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= Why WordPress 6.9 =

Agents act through the Abilities API, which arrived in WordPress 6.9. On 7.1 and later the plugin checks each ability through the `wp_pre_execute_ability` filter; on 6.9 and 7.0 it wraps each ability's callback instead. If the plugin ends up active on an older WordPress, it deactivates itself and says why.

= Privacy =

The log and the queue hold user IDs, IP addresses and what agents sent. Inputs are stored with secret-looking keys removed, except for a held call, which keeps its full input until it is decided so it can run as sent. The plugin takes part in Tools > Export Personal Data and Tools > Erase Personal Data (which keeps a record that calls happened, without the user, IP address or input), adds suggested text to Settings > Privacy, and deletes log entries after 30 days.

= WP-CLI =

* `wp agent-governance pending`, `show <id>`, `approve <id>` and `reject <id> --reason=<reason>` work the approval queue.
* `wp agent-governance log [--identity=<agent>] [--status=<status>]` lists agent calls.
* `wp agent-governance pause` and `resume` stop and restart agent writes.
* `wp agent-governance rollback <agent> [--since=<when>] [--dry-run]` rolls back what an agent changed.
* `wp agent-governance rules`, `add-rule`, `delete-rule`, `settings`, `sessions` and `cleanup` cover the rest.

= For developers =

Every default, limit, list and message has a filter, and every decision fires an action. The final decision for each call goes through `agent_governance_policy`, and abilities supply their dry-run preview through `agent_governance_dry_run`. The full list is in [the reference](https://github.com/aryanjasala/agent-governance/blob/main/docs/REFERENCE.md).

== Frequently Asked Questions ==

= What does the agent see when its call is held? =

An error with the code `agent_governance_pending`, HTTP status 202, the request ID and a message telling it to poll `agent-governance/get-request`. Over MCP, where only the message gets through, the message has the request ID in it.

= Does approving run the call as me? =

No. It runs as the user the agent signed in as, so that user's permissions still apply. The current user is switched for the call and switched back after.

= What can a session rollback undo? =

Posts, pages and other post types: edits go back to the revision saved just before the agent's first change in the session, with the old status, and posts the agent created go to the trash. Options, terms, users and anything deleted permanently are listed as not reversible here. With Activity Log with Undo active, the screen links to it for those.

= What counts as an agent call? =

Calls through the MCP adapter, over HTTP or `wp mcp-adapter serve`, and REST requests signed in with an application password. A person signed in to wp-admin is not an agent, even when a screen calls the Abilities REST API with the login cookie, so their calls run straight away and are not logged. The `agent_governance_is_agent_request` filter changes that, for example for agents that sign in through a JWT or OAuth plugin.

= Does it slow down people using wp-admin? =

No. Calls from people signed in to wp-admin are not agent calls, so they pass straight through. Agent calls cost one or two small database writes for the log.

= What if the approver capability is removed from every role? =

Approvals keep working. Administrators (anyone with `manage_options`) approve until a role has the `agent_governance_approve` capability again, Site Health flags it, and a notice gives it back to Administrator in one click. Deactivating the plugin takes the capability off every role, and activating gives it back.

= Does it work with the MCP adapter's STDIO server? =

Yes. Calls inside an MCP tool call count as agent calls, so `wp mcp-adapter serve` is covered. Without an application password, the agent is the user given with `--user`.

= Does it work on multisite? =

Yes, per site. Each site has its own policies, queue and log.

== Screenshots ==

1. The approval queue: agent calls waiting for a person, with what each would do.
2. A held request with its dry-run preview, before approving or rejecting it.
3. Activity: every agent call and its outcome, with filters.
4. Reviewing an agent session before rolling it back.
5. Policies: rules per agent, user or role, the defaults, the rate limit and notifications.

== Changelog ==

= 1.0.0 =
* First release.
