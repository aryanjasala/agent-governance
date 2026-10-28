const { execFileSync } = require( 'child_process' );
const { test, expect, wp, wpEval, appPasswordClient } = require( './utils' );
const { COMPOSE_DIR } = require( './utils/config' );

const SCREEN = '/wp-admin/tools.php?page=agent-governance';
const MCP_ADAPTER = 'https://github.com/WordPress/mcp-adapter/releases/download/v0.7.1/mcp-adapter.zip';
const READ_ONLY_MODE = 'https://github.com/aryanjasala/axle-read-only-mode/releases/download/v3.0.0/axle-read-only-mode.zip';

/** Application passwords this suite made, removed again at the end. */
const madePasswords = new Set();

/** Whether a WP-CLI command succeeds. */
const succeeds = ( ...args ) => {
	try {
		wp( ...args );
		return true;
	} catch ( error ) {
		return false;
	}
};

/** Runs SQL and returns the rows as arrays of strings. */
const sql = ( query ) =>
	wp( 'db', 'query', query, '--skip-column-names' )
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => line.split( '\t' ) );
const one = ( query ) => ( sql( query )[ 0 ] || [] )[ 0 ];
const option = ( name ) => {
	try {
		return JSON.parse( wp( 'option', 'get', name, '--format=json' ) );
	} catch ( error ) {
		return null;
	}
};
const post = ( id, field ) => wp( 'post', 'get', String( id ), `--field=${ field }` );
const newPost = ( title, extra = [] ) => Number( wp( 'post', 'create', `--post_title=${ title }`, '--post_status=publish', '--porcelain', ...extra ) );

/**
 * Creates an application password for admin and returns a REST client signed in with it, and only with it.
 *
 * @param {string} name Application password name.
 */
async function agent( name ) {
	const client = await appPasswordClient( 'admin', name );
	madePasswords.add( client.uuid );
	const call = ( method, route, data, query = '' ) => client.call( method, query ? `${ route }?${ query }` : route, data );

	return {
		uuid: client.uuid,
		identity: `app:${ client.uuid }`,
		context: client.context,
		call,
		ability: ( ability, input, method = 'POST' ) =>
			'GET' === method
				? call( 'GET', `/wp-abilities/v1/abilities/${ ability }/run`, null, Object.entries( input ).map( ( [ k, v ] ) => `input[${ k }]=${ encodeURIComponent( v ) }` ).join( '&' ) )
				: call( method, `/wp-abilities/v1/abilities/${ ability }/run`, { input } ),
		poll: ( id ) => call( 'GET', '/wp-abilities/v1/abilities/agent-governance/get-request/run', null, `input[id]=${ id }` ),
	};
}

/** Back to the defaults: no rules, not paused, no rate limit in the way. */
function reset() {
	for ( const name of [ 'agent_governance_settings', 'agent_governance_rules', 'agg_fixture_mail' ] ) {
		try {
			wp( 'option', 'delete', name );
		} catch ( error ) {
			// Not set.
		}
	}
	wp( 'agent-governance', 'settings', '--rate-limit=0' );
}

test.describe( 'Agent Governance', () => {
	test.beforeAll( () => {
		// The fixture: throwaway abilities, an email catcher, and application passwords over plain HTTP.
		wpEval( 'wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . "/agent-governance/tests/e2e/fixtures/agent-governance-fixture.php", WPMU_PLUGIN_DIR . "/agent-governance-fixture.php" );' );
		wp( 'db', 'query', 'TRUNCATE TABLE wp_agent_governance_log' );
		wp( 'db', 'query', 'TRUNCATE TABLE wp_agent_governance_requests' );
	} );

	test.beforeEach( () => reset() );

	// Leave the site as the suite found it: no fixture, no application passwords, no settings, and the
	// approver capability on Administrator.
	test.afterAll( () => {
		for ( const name of [ 'agent_governance_settings', 'agent_governance_rules', 'agg_fixture_mail' ] ) {
			succeeds( 'option', 'delete', name );
		}
		for ( const uuid of madePasswords ) {
			succeeds( 'user', 'application-password', 'delete', 'admin', uuid );
		}
		madePasswords.clear();
		wpEval( '@unlink( WPMU_PLUGIN_DIR . "/agent-governance-fixture.php" );' );
		succeeds( 'cap', 'add', 'administrator', 'agent_governance_approve' );
	} );

	test( 'an admin running a destructive ability in the browser is not held', async ( { rest } ) => {
		const id = newPost( 'Mine to trash' );
		const requestsBefore = Number( one( 'SELECT COUNT(*) FROM wp_agent_governance_requests' ) );
		const logBefore = Number( one( 'SELECT COUNT(*) FROM wp_agent_governance_log' ) );

		// The cookie and a REST nonce, the way wp-admin and the other plugins' screens call abilities.
		const response = await rest( 'POST', '/wp-abilities/v1/abilities/agg-fixture/trash-post/run', { input: { id } } );

		expect( response.status ).toBe( 200 );
		expect( response.body ).toEqual( { trashed: true } );
		expect( post( id, 'post_status' ) ).toBe( 'trash' );
		expect( Number( one( 'SELECT COUNT(*) FROM wp_agent_governance_requests' ) ) ).toBe( requestsBefore );
		expect( Number( one( 'SELECT COUNT(*) FROM wp_agent_governance_log' ) ) ).toBe( logBefore );
	} );

	test( 'a destructive ability is held, approved in wp-admin, and the agent gets the result', async ( { page } ) => {
		const bot = await agent( 'approval-bot' );
		const id = newPost( 'Spring sale' );

		const held = await bot.ability( 'agg-fixture/trash-post', { id } );
		expect( held.status ).toBe( 202 );
		expect( held.body.code ).toBe( 'agent_governance_pending' );
		const requestId = held.body.data.request_id;
		expect( held.body.message ).toContain( `request #${ requestId }` );
		expect( held.body.data.poll.ability ).toBe( 'agent-governance/get-request' );

		// Nothing changed yet, the request waits, and the approver got an email.
		expect( post( id, 'post_status' ) ).toBe( 'publish' );
		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ requestId }` ) ).toBe( 'pending' );
		expect( one( `SELECT identity FROM wp_agent_governance_requests WHERE id = ${ requestId }` ) ).toBe( bot.identity );
		expect( option( 'agg_fixture_mail' )[ 0 ].to ).toEqual( [ 'admin@example.com' ] );
		expect( option( 'agg_fixture_mail' )[ 0 ].subject ).toContain( `Agent request #${ requestId } needs approval` );
		expect( ( await bot.poll( requestId ) ).body.status ).toBe( 'pending' );

		// The approver sees the badge, the request and its preview.
		await page.goto( '/wp-admin/' );
		await expect( page.locator( '.agg-notice' ) ).toContainText( 'waiting for your approval' );
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( SCREEN );
		const menu = page.locator( '#adminmenu a[href="tools.php?page=agent-governance"]' );
		await expect( menu.locator( '.pending-count' ) ).toHaveText( '1' );
		await expect( menu ).toContainText( 'AI Agents' );
		// The label and the count fit on one line, like the other Tools items.
		const height = ( await menu.boundingBox() ).height;
		const single = ( await page.locator( '#menu-tools .wp-submenu a[href="tools.php"]' ).boundingBox() ).height;
		expect( height ).toBeLessThanOrEqual( single + 2 );
		await expect( page.locator( '.agg-status-filter' ) ).toContainText( 'Waiting | Executed | Failed', { useInnerText: true } );
		const row = page.locator( `tr[data-request="${ requestId }"]` );
		await expect( row ).toContainText( 'Moves "Spring sale" to the trash.' );
		await expect( row ).toContainText( 'approval-bot (admin)' );
		await row.getByRole( 'link', { name: `#${ requestId }` } ).click();
		await expect( page.locator( '.agg-diff' ) ).toContainText( 'publish' );
		await expect( page.locator( '.agg-diff' ) ).toContainText( 'trash' );
		await expect( page.locator( '.agg-json' ).first() ).toContainText( `"id": ${ id }` );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Approve and run' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Approved. The call ran.' );
		await expect( page.locator( '.agg-request-title .agg-badge' ) ).toHaveText( 'Executed' );
		await expect( page.locator( '.agg-facts' ) ).toContainText( 'Application password' );
		await expect( page.locator( '.agg-facts' ) ).not.toContainText( 'app-password' );
		await expect( page.locator( '.agg-json' ).first() ).toHaveAttribute( 'dir', 'ltr' );

		// It ran as admin, the request has the result, and the log has both steps.
		expect( post( id, 'post_status' ) ).toBe( 'trash' );
		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ requestId }` ) ).toBe( 'executed' );
		expect( one( `SELECT decided_by FROM wp_agent_governance_requests WHERE id = ${ requestId }` ) ).toBe( '1' );
		expect( sql( `SELECT decision FROM wp_agent_governance_log WHERE request_id = ${ requestId } ORDER BY id` ).map( ( r ) => r[ 0 ] ) ).toEqual( [ 'held', 'executed' ] );

		const done = await bot.poll( requestId );
		expect( done.body.status ).toBe( 'executed' );
		expect( done.body.result ).toEqual( { trashed: true } );
		await bot.context.dispose();
	} );

	test( 'an agent cannot approve its own request, and a rejection reaches it with the reason', async ( { page } ) => {
		const bot = await agent( 'eager-bot' );
		const id = newPost( 'Keep me' );

		const held = await bot.call( 'DELETE', `/wp/v2/posts/${ id }` );
		expect( held.status ).toBe( 202 );
		const requestId = held.body.data.request_id;

		const self = await bot.ability( 'agent-governance/decide-request', { id: requestId, decision: 'approve' } );
		expect( self.status ).toBe( 403 );
		expect( self.body.code ).toBe( 'agent_governance_agent_cannot_decide' );
		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ requestId }` ) ).toBe( 'pending' );
		expect( post( id, 'post_status' ) ).toBe( 'publish' );

		await page.goto( `${ SCREEN }&tab=requests&request=${ requestId }` );
		await expect( page.locator( '.agg-summary' ) ).toHaveText( 'Moves the Post "Keep me" to the trash.' );
		await page.locator( '#agg-reason' ).fill( 'This page is still linked from the menu.' );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Reject' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Rejected.' );

		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ requestId }` ) ).toBe( 'rejected' );
		expect( post( id, 'post_status' ) ).toBe( 'publish' );
		const polled = await bot.poll( requestId );
		expect( polled.body.status ).toBe( 'rejected' );
		expect( polled.body.reason ).toBe( 'This page is still linked from the menu.' );
		await bot.context.dispose();
	} );

	test( 'a deny rule from the Policies screen returns 403 for that agent only', async ( { page } ) => {
		const bot = await agent( 'rule-bot' );
		const other = await agent( 'other-bot' );
		const id = newPost( 'Rename me' );

		await page.goto( `${ SCREEN }&tab=policies` );
		const row = page.locator( '.agg-rule' ).last();
		await row.locator( 'select[name$="[scope]"]' ).selectOption( 'agent' );
		await row.locator( 'input[name$="[subject]"]' ).fill( bot.uuid );
		await row.locator( 'input[name$="[pattern]"]' ).fill( 'agg-fixture/rename-*' );
		await row.locator( 'select[name$="[action]"]' ).selectOption( 'deny' );
		await page.getByRole( 'button', { name: 'Save policies' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Policies saved.' );

		const rules = option( 'agent_governance_rules' );
		expect( rules ).toHaveLength( 1 );
		expect( rules[ 0 ] ).toMatchObject( { scope: 'agent', subject: bot.uuid, pattern: 'agg-fixture/rename-*', action: 'deny' } );

		const denied = await bot.ability( 'agg-fixture/rename-post', { id, title: 'Renamed' } );
		expect( denied.status ).toBe( 403 );
		expect( denied.body.code ).toBe( 'agent_governance_denied' );
		expect( post( id, 'post_title' ) ).toBe( 'Rename me' );
		expect( one( `SELECT decision FROM wp_agent_governance_log WHERE identity = '${ bot.identity }' ORDER BY id DESC LIMIT 1` ) ).toBe( 'denied' );

		const allowed = await other.ability( 'agg-fixture/rename-post', { id, title: 'Renamed by the other bot' } );
		expect( allowed.status ).toBe( 200 );
		expect( post( id, 'post_title' ) ).toBe( 'Renamed by the other bot' );

		// Removing the rule from WP-CLI lets the first agent through again.
		wp( 'agent-governance', 'delete-rule', rules[ 0 ].id );
		expect( ( await bot.ability( 'agg-fixture/rename-post', { id, title: 'Back' } ) ).status ).toBe( 200 );
		await bot.context.dispose();
		await other.context.dispose();
	} );

	test( 'the pause refuses agent writes and keeps reads working', async ( { page } ) => {
		const bot = await agent( 'pause-bot' );
		const id = newPost( 'Paused post' );

		await page.goto( SCREEN );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Pause agent writes' } ).click();
		await expect( page.locator( '.agg-paused' ) ).toContainText( 'Agent writes are paused.' );
		expect( option( 'agent_governance_settings' ).paused ).toBe( true );

		const write = await bot.call( 'POST', `/wp/v2/posts/${ id }`, { title: 'Changed while paused' } );
		expect( write.status ).toBe( 423 );
		expect( write.body.code ).toBe( 'agent_governance_paused' );
		expect( post( id, 'post_title' ) ).toBe( 'Paused post' );
		expect( ( await bot.call( 'GET', `/wp/v2/posts/${ id }` ) ).status ).toBe( 200 );
		expect( ( await bot.ability( 'agg-fixture/read-post', { id }, 'GET' ) ).status ).toBe( 200 );

		await page.getByRole( 'button', { name: 'Resume agent writes' } ).click();
		await expect( page.locator( '.agg-paused' ) ).toHaveCount( 0 );
		expect( option( 'agent_governance_settings' ).paused ).toBe( false );
		expect( ( await bot.call( 'POST', `/wp/v2/posts/${ id }`, { title: 'Changed after' } ) ).status ).toBe( 200 );
		expect( post( id, 'post_title' ) ).toBe( 'Changed after' );
		await bot.context.dispose();
	} );

	test( 'the rate limit answers 429 once an agent goes over it', async () => {
		const bot = await agent( 'busy-bot' );
		wp( 'agent-governance', 'settings', '--rate-limit=3' );

		const responses = [];
		for ( let i = 0; i < 4; i++ ) {
			responses.push( await bot.call( 'GET', '/wp/v2/types' ) );
		}

		expect( responses.map( ( r ) => r.status ) ).toEqual( [ 200, 200, 200, 429 ] );
		// Well-behaved clients know when to come back.
		const retry = Number( responses[ 3 ].headers[ 'retry-after' ] );
		expect( retry ).toBeGreaterThanOrEqual( 1 );
		expect( retry ).toBeLessThanOrEqual( 60 );
		expect( responses[ 3 ].body.data.retry_after ).toBe( retry );
		expect( one( `SELECT reason FROM wp_agent_governance_log WHERE identity = '${ bot.identity }' ORDER BY id DESC LIMIT 1` ) ).toBe( 'rate_limit' );
		// Its own abilities still answer, so it can follow a held request.
		expect( ( await bot.poll( 999 ) ).status ).toBe( 404 );
		await bot.context.dispose();
	} );

	test( 'rolling back a session restores the post an agent edited and trashes the one it made', async ( { page } ) => {
		const bot = await agent( 'rollback-bot' );
		const id = newPost( 'Original title', [ '--post_content=Original body' ] );

		expect( ( await bot.call( 'POST', `/wp/v2/posts/${ id }`, { title: 'Agent title', content: 'Agent body' } ) ).status ).toBe( 200 );
		const created = await bot.call( 'POST', '/wp/v2/posts', { title: 'Made by the agent', status: 'draft' } );
		expect( created.status ).toBe( 201 );
		expect( ( await bot.call( 'POST', '/wp/v2/settings', { description: 'Changed by the agent' } ) ).status ).toBe( 200 );
		expect( post( id, 'post_title' ) ).toBe( 'Agent title' );

		await page.goto( `${ SCREEN }&tab=sessions` );
		await page.locator( 'tr', { hasText: 'rollback-bot (admin)' } ).getByRole( 'link', { name: 'Review' } ).click();
		const plan = page.locator( '.agg-plan' );
		await expect( plan ).toContainText( 'Restores the revision saved before the agent changed it' );
		await expect( plan ).toContainText( 'Created by the agent. Moves to the trash.' );
		await expect( plan.locator( '.agg-other' ) ).toContainText( '/wp/v2/settings' );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Roll back session' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Restored 1, moved to the trash 1, failed 0.' );

		expect( post( id, 'post_title' ) ).toBe( 'Original title' );
		expect( post( id, 'post_content' ) ).toBe( 'Original body' );
		expect( post( created.body.id, 'post_status' ) ).toBe( 'trash' );
		expect( Number( one( `SELECT COUNT(*) FROM wp_agent_governance_log WHERE identity = '${ bot.identity }' AND rolled_back IS NOT NULL` ) ) ).toBe( 3 );
		await expect( page.getByRole( 'button', { name: 'Roll back session' } ) ).toHaveCount( 0 );
		await bot.context.dispose();
	} );

	test( 'the Activity tab lists every agent call and filters by outcome', async ( { page } ) => {
		const bot = await agent( 'activity-bot' );
		wp( 'agent-governance', 'add-rule', '/wp/v2/comments*', 'deny', '--scope=agent', `--subject=${ bot.uuid }` );
		await bot.call( 'GET', '/wp/v2/comments' );
		await bot.call( 'GET', '/wp/v2/types' );

		await page.goto( `${ SCREEN }&tab=activity` );
		await page.locator( '#agg-identity' ).selectOption( bot.identity );
		await page.locator( '#agg-decision' ).selectOption( 'denied' );
		await page.getByRole( 'button', { name: 'Filter' } ).click();

		const rows = page.locator( '.agg-activity tbody tr' );
		await expect( rows ).toHaveCount( 1 );
		await expect( rows.first() ).toContainText( '/wp/v2/comments' );
		await expect( rows.first() ).toContainText( 'Denied' );
		await expect( rows.first() ).toContainText( 'Application password' );
		await expect( rows.first() ).toContainText( 'Refused by a rule' );
		await expect( rows.first() ).not.toContainText( 'agent_governance_denied' );
		expect( Number( one( `SELECT COUNT(*) FROM wp_agent_governance_log WHERE identity = '${ bot.identity }'` ) ) ).toBe( 2 );
		expect( JSON.parse( wp( 'agent-governance', 'log', `--identity=${ bot.uuid }`, '--status=denied', '--format=json' ) ) ).toHaveLength( 1 );
		await bot.context.dispose();
	} );

	test( 'an ability with no preview of its own is described, and the approve advice goes once it is decided', async ( { page } ) => {
		const bot = await agent( 'note-bot' );
		const id = newPost( 'With a note', [ '--meta_input={"agg_note":"keep"}' ] );

		const held = await bot.ability( 'agg-fixture/forget-note', { id } );
		expect( held.status ).toBe( 202 );
		const requestId = held.body.data.request_id;
		expect( JSON.parse( wp( 'agent-governance', 'show', String( requestId ), '--format=json' ) ).dry_run.summary ).toBe( 'Runs "Forget a note": Removes the note saved on a post.' );

		await page.goto( SCREEN );
		await expect( page.locator( `tr[data-request="${ requestId }"]` ) ).toContainText( 'Runs "Forget a note": Removes the note saved on a post.' );
		await page.goto( `${ SCREEN }&tab=requests&request=${ requestId }` );
		await expect( page.locator( '.agg-preview h3' ) ).toHaveText( 'What it would change' );
		await expect( page.locator( '.agg-check-input' ) ).toHaveText( 'Check the input below before you approve it.' );

		wp( 'agent-governance', 'reject', String( requestId ), '--reason=Keep the note' );
		await page.reload();
		await expect( page.locator( '.agg-request-title .agg-badge' ) ).toHaveText( 'Rejected' );
		await expect( page.locator( '.agg-preview h3' ) ).toHaveText( 'What it was asked to change' );
		await expect( page.locator( '.agg-preview' ) ).not.toContainText( 'before you approve' );
		expect( wp( 'post', 'meta', 'get', String( id ), 'agg_note' ) ).toBe( 'keep' );
		await bot.context.dispose();
	} );

	test( 'with the approver capability gone from every role, admins still approve and can give it back', async ( { page } ) => {
		const bot = await agent( 'orphan-bot' );
		const id = newPost( 'Orphaned approval' );
		const held = ( await bot.ability( 'agg-fixture/trash-post', { id } ) ).body.data.request_id;

		// What a role clean-up such as Role Right-Sizer's "apply all" used to do.
		wp( 'cap', 'remove', 'administrator', 'agent_governance_approve' );
		expect( wpEval( 'echo get_role( "administrator" )->has_cap( "agent_governance_approve" ) ? "yes" : "no";' ) ).toBe( 'no' );

		await page.goto( `${ SCREEN }&tab=requests&request=${ held }` );
		await expect( page.locator( '.agg-request-title' ) ).toContainText( `Request #${ held }` );
		await expect( page.locator( '.agg-no-approver' ) ).toContainText( 'No role has the agent_governance_approve capability' );
		expect( JSON.parse( wpEval( 'echo wp_json_encode( ( new AryanX\\Axle\\AgentGovernance\\Health\\SiteHealth( new AryanX\\Axle\\AgentGovernance\\Settings(), new AryanX\\Axle\\AgentGovernance\\Requests() ) )->run() );' ) ).status ).toBe( 'critical' );

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Approve and run' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Approved. The call ran.' );
		expect( post( id, 'post_status' ) ).toBe( 'trash' );

		await page.getByRole( 'button', { name: 'Give it back to Administrator' } ).click();
		await expect( page.locator( '.agg-no-approver' ) ).toHaveCount( 0 );
		expect( wpEval( 'echo get_role( "administrator" )->has_cap( "agent_governance_approve" ) ? "yes" : "no";' ) ).toBe( 'yes' );
		await bot.context.dispose();
	} );

	test( 'deactivating takes the approver capability off every role, and activating gives it back', () => {
		wp( 'cap', 'add', 'editor', 'agent_governance_approve' );

		wp( 'plugin', 'deactivate', 'agent-governance' );
		const has = ( role ) => wpEval( `echo get_role( "${ role }" )->has_cap( "agent_governance_approve" ) ? "yes" : "no";` );
		try {
			expect( has( 'administrator' ) ).toBe( 'no' );
			expect( has( 'editor' ) ).toBe( 'no' );
		} finally {
			wp( 'plugin', 'activate', 'agent-governance' );
		}

		expect( has( 'administrator' ) ).toBe( 'yes' );
		expect( has( 'editor' ) ).toBe( 'no' );
		// Tables and settings stay.
		expect( one( "SHOW TABLES LIKE 'wp_agent_governance_log'" ) ).toBe( 'wp_agent_governance_log' );
	} );

	test( 'the screens fit a phone: rule fields and log rows stack', async ( { page } ) => {
		const bot = await agent( 'phone-bot' );
		await bot.call( 'GET', '/wp/v2/types' );
		await page.setViewportSize( { width: 375, height: 800 } );

		await page.goto( `${ SCREEN }&tab=policies` );
		const rules = await page.locator( '.agg-rules' ).boundingBox();
		expect( rules.x + rules.width ).toBeLessThanOrEqual( 375 );
		expect( ( await page.locator( '.agg-rule input[name$="[pattern]"]' ).first().boundingBox() ).width ).toBeGreaterThan( 200 );

		for ( const tab of [ 'activity', 'sessions', 'requests' ] ) {
			await page.goto( `${ SCREEN }&tab=${ tab }` );
			const table = await page.locator( '.agg-table' ).boundingBox();
			expect( table.x + table.width, tab ).toBeLessThanOrEqual( 375 );
		}
		await bot.context.dispose();
	} );

	test( 'JS hooks change the confirmation and see new rule rows', async ( { page } ) => {
		await page.goto( `${ SCREEN }&tab=policies` );
		await page.evaluate( () => {
			window.wp.hooks.addFilter( 'agentGovernance.confirmMessage', 'e2e', ( message, action ) => `${ action }: are you sure?` );
			window.wp.hooks.addAction( 'agentGovernance.ruleAdded', 'e2e', ( row ) => {
				window.aggRuleAdded = row.getAttribute( 'data-index' );
			} );
		} );

		await page.getByRole( 'button', { name: 'Add rule' } ).click();
		expect( await page.evaluate( () => window.aggRuleAdded ) ).toBe( '1' );
		await expect( page.locator( '.agg-rule' ) ).toHaveCount( 2 );

		let asked = '';
		page.once( 'dialog', ( dialog ) => {
			asked = dialog.message();
			dialog.dismiss();
		} );
		await page.getByRole( 'button', { name: 'Pause agent writes' } ).click();
		await expect.poll( () => asked ).toBe( 'pause: are you sure?' );
		expect( option( 'agent_governance_settings' ).paused ).toBe( false );
	} );

	test( 'WP-CLI lists, shows, rejects, approves and expires held requests', async () => {
		const bot = await agent( 'cli-bot' );
		const a = newPost( 'CLI one' );
		const b = newPost( 'CLI two' );
		const c = newPost( 'CLI three' );
		const first = ( await bot.ability( 'agg-fixture/trash-post', { id: a } ) ).body.data.request_id;
		const second = ( await bot.ability( 'agg-fixture/trash-post', { id: b } ) ).body.data.request_id;
		const third = ( await bot.ability( 'agg-fixture/trash-post', { id: c } ) ).body.data.request_id;

		const pending = JSON.parse( wp( 'agent-governance', 'pending', '--format=json' ) ).map( ( r ) => r.id );
		expect( pending ).toEqual( expect.arrayContaining( [ first, second, third ] ) );
		expect( wp( 'agent-governance', 'show', String( first ), '--format=json' ) ).toContain( 'Moves \\"CLI one\\" to the trash.' );

		wp( 'agent-governance', 'reject', String( first ), '--reason=Not today' );
		wp( 'agent-governance', 'approve', String( second ), '--user=admin' );
		expect( one( `SELECT CONCAT(status, '|', reason) FROM wp_agent_governance_requests WHERE id = ${ first }` ) ).toBe( 'rejected|Not today' );
		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ second }` ) ).toBe( 'executed' );
		expect( post( a, 'post_status' ) ).toBe( 'publish' );
		expect( post( b, 'post_status' ) ).toBe( 'trash' );

		// A request nobody decides expires after a day.
		wp( 'db', 'query', `UPDATE wp_agent_governance_requests SET created = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) WHERE id = ${ third }` );
		wp( 'agent-governance', 'cleanup' );
		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ third }` ) ).toBe( 'expired' );
		expect( ( await bot.poll( third ) ).body.status ).toBe( 'expired' );
		expect( post( c, 'post_status' ) ).toBe( 'publish' );
		await bot.context.dispose();
	} );

	test( 'privacy export lists a user’s agent calls and erase keeps them without personal data', async () => {
		const bot = await agent( 'privacy-bot' );
		await bot.call( 'GET', '/wp/v2/types' );

		const exported = JSON.parse( wpEval( 'echo wp_json_encode( call_user_func( apply_filters( "wp_privacy_personal_data_exporters", array() )["agent-governance"]["callback"], "admin@example.com", 1 ) );' ) );
		expect( exported.data.some( ( item ) => item.data.some( ( field ) => field.value === 'GET /wp/v2/types' ) ) ).toBe( true );

		const erased = JSON.parse( wpEval( 'echo wp_json_encode( call_user_func( apply_filters( "wp_privacy_personal_data_erasers", array() )["agent-governance"]["callback"], "admin@example.com", 1 ) );' ) );
		expect( erased.items_retained ).toBe( true );
		expect( Number( one( 'SELECT COUNT(*) FROM wp_agent_governance_log WHERE user_id = 1' ) ) ).toBe( 0 );
		expect( Number( one( `SELECT COUNT(*) FROM wp_agent_governance_log WHERE identity = '${ bot.identity }' AND ip = '' AND input IS NULL` ) ) ).toBeGreaterThan( 0 );
		await bot.context.dispose();
	} );

	test.describe( 'through the MCP adapter', () => {
		let installed = false;
		let wasInstalled = false;
		let wasActive = false;

		test.beforeAll( () => {
			// Use the adapter the site already has, or a known version, and put things back after.
			wasInstalled = succeeds( 'plugin', 'is-installed', 'mcp-adapter' );
			wasActive = wasInstalled && succeeds( 'plugin', 'is-active', 'mcp-adapter' );
			installed = wasInstalled ? succeeds( 'plugin', 'activate', 'mcp-adapter' ) : succeeds( 'plugin', 'install', MCP_ADAPTER, '--activate' );
		} );

		test.afterAll( () => {
			if ( ! wasActive ) {
				succeeds( 'plugin', 'deactivate', 'mcp-adapter' );
			}
			if ( ! wasInstalled ) {
				succeeds( 'plugin', 'delete', 'mcp-adapter' );
			}
		} );

		test( 'a destructive ability called over MCP HTTP is held, with the MCP session', async () => {
			test.skip( ! installed, 'The MCP adapter could not be downloaded.' );
			const bot = await agent( 'mcp-bot' );
			const id = newPost( 'MCP target' );
			const route = '/mcp/mcp-adapter-default-server';
			const rpc = async ( body, session ) => {
				const response = await bot.context.post( `/index.php?rest_route=${ encodeURIComponent( route ) }`, {
					headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', ...( session ? { 'Mcp-Session-Id': session, 'Mcp-Protocol-Version': '2025-06-18' } : {} ) },
					data: JSON.stringify( body ),
				} );
				return { headers: response.headers(), body: await response.json().catch( () => null ) };
			};

			const init = await rpc( { jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e', version: '1' } } } );
			const session = init.headers[ 'mcp-session-id' ];
			expect( session ).toBeTruthy();
			await rpc( { jsonrpc: '2.0', method: 'notifications/initialized' }, session );

			const called = await rpc( { jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'mcp-adapter-execute-ability', arguments: { ability_name: 'agg-fixture/trash-post', parameters: { id } } } }, session );
			const text = called.body.result.content[ 0 ].text;
			expect( text ).toMatch( /request #\d+/ );
			const requestId = Number( text.match( /request #(\d+)/ )[ 1 ] );

			expect( sql( `SELECT via, session, status FROM wp_agent_governance_requests WHERE id = ${ requestId }` )[ 0 ] ).toEqual( [ 'mcp', `mcp:${ session }`, 'pending' ] );
			expect( post( id, 'post_status' ) ).toBe( 'publish' );

			// Approving over MCP is refused too.
			const self = await rpc( { jsonrpc: '2.0', id: 3, method: 'tools/call', params: { name: 'mcp-adapter-execute-ability', arguments: { ability_name: 'agent-governance/decide-request', parameters: { id: requestId, decision: 'approve' } } } }, session );
			expect( JSON.stringify( self.body ) ).toContain( 'An agent cannot approve its own request.' );
			expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ requestId }` ) ).toBe( 'pending' );
			await bot.context.dispose();
		} );

		test( 'a destructive ability called over MCP STDIO is held for the user', async () => {
			test.skip( ! installed, 'The MCP adapter could not be downloaded.' );
			const id = newPost( 'STDIO target' );
			const input = [
				{ jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e', version: '1' } } },
				{ jsonrpc: '2.0', method: 'notifications/initialized' },
				{ jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name: 'mcp-adapter-execute-ability', arguments: { ability_name: 'agg-fixture/trash-post', parameters: { id } } } },
			]
				.map( ( line ) => JSON.stringify( line ) )
				.join( '\n' );

			const output = execFileSync( 'docker', [ 'compose', 'exec', '-T', 'cli', 'wp', 'mcp-adapter', 'serve', '--server=mcp-adapter-default-server', '--user=admin' ], { cwd: COMPOSE_DIR, input: input + '\n', encoding: 'utf8', stdio: [ 'pipe', 'pipe', 'pipe' ] } );

			expect( output ).toMatch( /request #\d+/ );
			const requestId = Number( output.match( /request #(\d+)/ )[ 1 ] );
			expect( sql( `SELECT identity, via, target FROM wp_agent_governance_requests WHERE id = ${ requestId }` )[ 0 ] ).toEqual( [ 'user:1', 'mcp', 'agg-fixture/trash-post' ] );
			expect( post( id, 'post_status' ) ).toBe( 'publish' );
		} );
	} );

	test.describe( 'with Axle Read-Only Mode', () => {
		let installed = false;
		let wasInstalled = false;
		let wasActive = false;

		test.beforeAll( () => {
			wasInstalled = succeeds( 'plugin', 'is-installed', 'axle-read-only-mode' );
			wasActive = wasInstalled && succeeds( 'plugin', 'is-active', 'axle-read-only-mode' );
			installed = wasInstalled ? succeeds( 'plugin', 'activate', 'axle-read-only-mode' ) : succeeds( 'plugin', 'install', READ_ONLY_MODE, '--activate' );
		} );

		test.afterAll( () => {
			succeeds( 'axle-read-only-mode', 'off' );
			if ( ! wasActive ) {
				succeeds( 'plugin', 'deactivate', 'axle-read-only-mode' );
			}
			// Only remove what this suite downloaded; a mounted copy is someone's source.
			if ( ! wasInstalled ) {
				succeeds( 'plugin', 'delete', 'axle-read-only-mode' );
			}
		} );

		test( 'agent calls a freeze refuses are in the audit log as refused', async ( { page } ) => {
			test.skip( ! installed, 'Axle Read-Only Mode could not be downloaded.' );
			const bot = await agent( 'frozen-bot' );
			const id = newPost( 'Frozen post' );

			wp( 'axle-read-only-mode', 'on', '--minutes=0' );
			try {
				const write = await bot.call( 'POST', `/wp/v2/posts/${ id }`, { title: 'Changed while frozen' } );
				expect( write.status ).toBe( 423 );
				const ability = await bot.ability( 'agg-fixture/trash-post', { id } );
				expect( ability.status ).toBe( 423 );
			} finally {
				wp( 'axle-read-only-mode', 'off' );
			}

			expect( post( id, 'post_title' ) ).toBe( 'Frozen post' );
			expect( post( id, 'post_status' ) ).toBe( 'publish' );
			const rows = sql( `SELECT source, target, decision, result FROM wp_agent_governance_log WHERE identity = '${ bot.identity }' ORDER BY id` );
			expect( rows ).toEqual( [
				[ 'rest', `/wp/v2/posts/${ id }`, 'refused', 'axle_read_only_mode' ],
				[ 'ability', 'agg-fixture/trash-post', 'refused', 'axle_read_only_mode' ],
			] );
			expect( Number( one( `SELECT COUNT(*) FROM wp_agent_governance_requests WHERE identity = '${ bot.identity }'` ) ) ).toBe( 0 );
			expect( JSON.parse( wp( 'agent-governance', 'log', `--identity=${ bot.uuid }`, '--status=refused', '--format=json' ) ) ).toHaveLength( 2 );

			await page.goto( `${ SCREEN }&tab=activity&decision=refused&identity=${ encodeURIComponent( bot.identity ) }` );
			const shown = page.locator( '.agg-activity tbody tr' );
			await expect( shown ).toHaveCount( 2 );
			await expect( shown.first() ).toContainText( 'Refused' );
			await expect( shown.first() ).toContainText( 'Axle Read-Only Mode freeze' );
			await bot.context.dispose();
		} );
	} );
} );
