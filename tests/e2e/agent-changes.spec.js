// Agent changes in the editor: who changed this post, what, and Undo for this post only;
// requests held on the post with Approve and Reject; and the per-approver counts.
const { test, expect, wp, wpEval, openEditor, logInAs, appPasswordClient, expectNoSeriousA11yViolations } = require( './utils' );

const SCREEN = '/wp-admin/tools.php?page=agent-governance';
const PASSWORD = 'carol-password-123';

const sql = ( query ) =>
	wp( 'db', 'query', query, '--skip-column-names' )
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => line.split( '\t' ) );
const one = ( query ) => ( sql( query )[ 0 ] || [] )[ 0 ];
const post = ( id, field ) => wp( 'post', 'get', String( id ), `--field=${ field }` );
const newPost = ( title, content = '', extra = [] ) => Number( wp( 'post', 'create', `--post_title=${ title }`, `--post_content=${ content }`, '--post_status=publish', '--porcelain', ...extra ) );
const succeeds = ( ...args ) => {
	try {
		wp( ...args );
		return true;
	} catch ( error ) {
		return false;
	}
};

const made = { posts: [], bots: [] };

async function bot( name, user = 'admin' ) {
	const client = await appPasswordClient( user, name );
	made.bots.push( { client, user } );
	return client;
}

function reset() {
	for ( const name of [ 'agent_governance_settings', 'agent_governance_rules', 'agent_governance_outbox', 'agent_governance_last_notified', 'agent_governance_reminded' ] ) {
		succeeds( 'option', 'delete', name );
	}
	wp( 'agent-governance', 'settings', '--rate-limit=0' );
	wp( 'db', 'query', 'TRUNCATE TABLE wp_agent_governance_log' );
	wp( 'db', 'query', 'TRUNCATE TABLE wp_agent_governance_requests' );
	wp( 'db', 'query', 'TRUNCATE TABLE wp_agent_governance_posts' );
}

/** The document sidebar, open. */
async function showSidebar( page ) {
	const panel = page.locator( '.agg-editor-panel' );
	if ( ! ( await panel.isVisible().catch( () => false ) ) ) {
		const settings = page.getByRole( 'button', { name: 'Settings', exact: true } );
		if ( ( await settings.count() ) && 'false' === ( await settings.first().getAttribute( 'aria-pressed' ) ) ) {
			await settings.first().click();
		}
	}
	return panel;
}

test.describe( 'Agent changes in the editor', () => {
	test.beforeAll( () => {
		wpEval( 'wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . "/agent-governance/tests/e2e/fixtures/agent-governance-fixture.php", WPMU_PLUGIN_DIR . "/agent-governance-fixture.php" );' );
	} );

	test.beforeEach( () => reset() );

	test.afterAll( async () => {
		for ( const { client } of made.bots ) {
			await client.dispose();
		}
		for ( const id of made.posts ) {
			succeeds( 'post', 'delete', String( id ), '--force' );
		}
		succeeds( 'user', 'delete', 'carol', '--yes', '--reassign=1' );
		succeeds( 'cap', 'remove', 'contributor', 'agent_governance_approve' );
		wpEval( '@unlink( WPMU_PLUGIN_DIR . "/agent-governance-fixture.php" );' );
		reset();
	} );

	test( 'the editor says which agent changed the post, shows what, and undoes only this post', async ( { page } ) => {
		const id = newPost( 'Spring sale', 'Original body' );
		const other = newPost( 'Holiday hours', 'Original hours' );
		made.posts.push( id, other );
		const writer = await bot( 'Content bot' );
		expect( ( await writer.call( 'POST', `/wp/v2/posts/${ id }`, { content: 'Agent body' } ) ).status ).toBe( 200 );
		expect( ( await writer.call( 'POST', `/wp/v2/posts/${ other }`, { content: 'Agent hours' } ) ).status ).toBe( 200 );
		expect( one( `SELECT COUNT(*) FROM wp_agent_governance_posts WHERE post_id IN (${ id }, ${ other })` ) ).toBe( '2' );

		await openEditor( page, id );
		const panel = await showSidebar( page );
		await expect( panel ).toContainText( 'Content bot (admin) changed this' );
		await expect( page.locator( '.components-notice' ).filter( { hasText: 'changed this' } ) ).toBeVisible();

		await panel.getByRole( 'button', { name: 'View changes' } ).click();
		await expect( panel.locator( '.agg-editor-diff--x, .agg-editor-diff__side--before' ).first() ).toContainText( 'Original body' );
		await expect( panel.locator( '.agg-editor-diff__side--after' ).first() ).toContainText( 'Agent body' );

		await panel.getByRole( 'button', { name: 'Undo this change' } ).click();
		await panel.getByRole( 'group', { name: 'Confirm undo' } ).getByRole( 'button', { name: 'Undo this change' } ).click();
		await expect( panel ).toContainText( 'was undone' );

		expect( post( id, 'post_content' ) ).toBe( 'Original body' );
		expect( post( other, 'post_content' ) ).toBe( 'Agent hours' );
		expect( one( `SELECT COUNT(*) FROM wp_posts WHERE post_type = 'revision' AND post_parent = ${ id } AND post_content = 'Agent body'` ) ).not.toBe( '0' );
		expect( one( `SELECT undone IS NOT NULL FROM wp_agent_governance_posts WHERE post_id = ${ id }` ) ).toBe( '1' );
	} );

	test( 'a save by a person after the agent warns first, and Undo anyway is explicit', async ( { page } ) => {
		const id = newPost( 'Shipping', 'Original body' );
		made.posts.push( id );
		const writer = await bot( 'Content bot' );
		await writer.call( 'POST', `/wp/v2/posts/${ id }`, { content: 'Agent body' } );
		wp( 'post', 'update', String( id ), '--post_content=Human edit' );
		wp( 'db', 'query', `UPDATE wp_posts SET post_modified_gmt = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE ID = ${ id }` );

		await openEditor( page, id );
		const panel = await showSidebar( page );
		await expect( panel.getByRole( 'note' ) ).toContainText( 'Somebody saved this post after the agent changed it' );
		await panel.getByRole( 'button', { name: 'Undo anyway' } ).click();
		await expect( panel ).toContainText( 'was undone' );
		expect( post( id, 'post_content' ) ).toBe( 'Original body' );
		expect( one( `SELECT COUNT(*) FROM wp_posts WHERE post_type = 'revision' AND post_parent = ${ id } AND post_content = 'Human edit'` ) ).not.toBe( '0' );
	} );

	test( 'a post the agent created is moved to the trash by Undo', async ( { page } ) => {
		const writer = await bot( 'Content bot' );
		const created = await writer.call( 'POST', '/wp/v2/posts', { title: 'Made by an agent', status: 'draft', content: 'Draft text' } );
		const id = created.body.id;
		made.posts.push( id );

		await openEditor( page, id );
		const panel = await showSidebar( page );
		await expect( panel ).toContainText( 'created this post' );
		await panel.getByRole( 'button', { name: 'Undo this change' } ).click();
		await expect( panel.getByRole( 'group', { name: 'Confirm undo' } ) ).toContainText( 'Move this post to the trash?' );
		await panel.getByRole( 'group', { name: 'Confirm undo' } ).getByRole( 'button', { name: 'Undo this change' } ).click();
		await expect( panel ).toContainText( 'was undone' );
		expect( post( id, 'post_status' ) ).toBe( 'trash' );
	} );

	test( 'a post no agent touched has no panel', async ( { page } ) => {
		const id = newPost( 'Untouched', 'Body' );
		made.posts.push( id );
		await openEditor( page, id );
		await page.waitForTimeout( 1500 );
		await expect( page.locator( '.agg-editor-panel' ) ).toHaveCount( 0 );
	} );

	test( 'a request held on the post is approved or rejected right in the editor', async ( { page } ) => {
		wp( 'agent-governance', 'add-rule', 'POST /wp/v2/posts/*', 'require_approval' );
		const id = newPost( 'Press release', 'Body' );
		made.posts.push( id );
		const writer = await bot( 'Content bot' );

		const first = await writer.call( 'POST', `/wp/v2/posts/${ id }`, { title: 'Press release v2' } );
		expect( first.status ).toBe( 202 );
		await openEditor( page, id );
		const panel = await showSidebar( page );
		await expect( panel ).toContainText( 'asked to change this post' );
		await expect( panel ).toContainText( 'Press release v2' );

		await panel.getByRole( 'button', { name: 'Reject', exact: true } ).click();
		await panel.getByLabel( 'Reason, shown to the agent' ).fill( 'Not this week' );
		await panel.getByRole( 'button', { name: 'Reject this call' } ).click();
		await expect( panel ).toHaveCount( 0, { timeout: 10_000 } ).catch( () => null );
		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ first.body.data.request_id }` ) ).toBe( 'rejected' );
		expect( one( `SELECT reason FROM wp_agent_governance_requests WHERE id = ${ first.body.data.request_id }` ) ).toBe( 'Not this week' );

		const second = await writer.call( 'POST', `/wp/v2/posts/${ id }`, { title: 'Press release v3' } );
		await openEditor( page, id );
		const again = await showSidebar( page );
		await again.getByRole( 'button', { name: 'Approve', exact: true } ).click();
		await expect( again.getByRole( 'group', { name: 'Confirm approval' } ) ).toContainText( 'runs the call now' );
		await again.getByRole( 'group', { name: 'Confirm approval' } ).getByRole( 'button', { name: 'Approve and run' } ).click();
		await expect( again.getByRole( 'button', { name: 'Reload the editor' } ) ).toBeVisible();
		expect( post( id, 'post_title' ) ).toBe( 'Press release v3' );
		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ second.body.data.request_id }` ) ).toBe( 'executed' );
	} );

	test( 'editor panel has no serious accessibility problems', async ( { page } ) => {
		wp( 'agent-governance', 'add-rule', 'POST /wp/v2/posts/*', 'require_approval' );
		const id = newPost( 'A11y post', 'Body' );
		made.posts.push( id );
		const writer = await bot( 'Content bot' );
		wp( 'agent-governance', 'settings', '--rate-limit=0' );
		await writer.call( 'POST', `/wp/v2/posts/${ id }`, { title: 'A11y post, new title' } );
		await openEditor( page, id );
		const panel = await showSidebar( page );
		await expect( panel ).toContainText( 'asked to change this post' );
		await panel.getByRole( 'button', { name: 'Reject', exact: true } ).click();
		await expect( panel.getByLabel( 'Reason, shown to the agent' ) ).toBeVisible();
		await expectNoSeriousA11yViolations( page, { name: 'Editor panel', include: [ '.agg-editor-panel' ] } );
	} );

	test( 'a second approver sees a smaller count, no titles, and a 403 where they cannot edit', async ( { browser } ) => {
		wp( 'agent-governance', 'add-rule', 'POST /wp/v2/posts/*', 'require_approval' );
		succeeds( 'user', 'delete', 'carol', '--yes', '--reassign=1' );
		const carol = Number( wp( 'user', 'create', 'carol', 'carol@example.com', '--role=contributor', `--user_pass=${ PASSWORD }`, '--porcelain' ) );
		wp( 'cap', 'add', 'contributor', 'agent_governance_approve' );

		const secret = newPost( 'Secret merger plan', 'Body' );
		const hers = newPost( 'Carol draft', 'Body', [ `--post_author=${ carol }`, '--post_status=draft' ] );
		made.posts.push( secret, hers );
		wp( 'post', 'update', String( hers ), '--post_status=draft' );
		const writer = await bot( 'Content bot' );
		expect( ( await writer.call( 'POST', `/wp/v2/posts/${ secret }`, { title: 'Secret merger plan, edited' } ) ).status ).toBe( 202 );
		expect( ( await writer.call( 'POST', `/wp/v2/posts/${ hers }`, { title: 'Carol draft, edited' } ) ).status ).toBe( 202 );

		// The admin may decide both.
		const adminCtx = await browser.newContext( { storageState: require( './utils/config' ).STORAGE_STATE, viewport: { width: 1280, height: 800 } } );
		const adminPage = await adminCtx.newPage();
		await adminPage.goto( '/wp-admin/index.php' );
		await expect( adminPage.locator( '.agg-notice' ) ).toContainText( '2 AI agent requests are waiting for your approval.' );
		await expect( adminPage.locator( '#wp-admin-bar-agent-governance' ) ).toContainText( '2 agent requests waiting' );
		await adminCtx.close();

		// Carol may decide one of them, and sees only that in every number.
		const { context, page } = await logInAs( browser, 'carol', PASSWORD, { viewport: { width: 1280, height: 800 } } );
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '.agg-notice' ) ).toContainText( '1 AI agent request is waiting for your approval.' );
		await expect( page.locator( '#wp-admin-bar-agent-governance' ) ).toContainText( '1 agent request waiting' );
		await expect( page.locator( '#adminmenu' ) ).toContainText( 'AI Agents' );
		await expect( page.locator( '#adminmenu a[href*="page=agent-governance"] .pending-count' ) ).toHaveText( '1' );
		// Core's own Activity widget lists published titles; what this plugin draws has none of the held ones.
		expect( ( await page.locator( '.agg-notice, #wp-admin-bar-agent-governance, #adminmenu' ).allInnerTexts() ).join( ' ' ) ).not.toContain( 'Secret merger' );

		await page.goto( SCREEN );
		await expect( page.locator( '.nav-tab', { hasText: 'Requests' } ) ).toContainText( 'Requests (1)' );
		await expect( page.locator( '.agg-wrap' ) ).toHaveAttribute( 'data-agg-pending', '1' );
		expect( await page.content() ).not.toContain( 'Secret merger plan' );
		expect( await page.title() ).toContain( '(1)' );

		// The editor's routes answer 403 for the post she cannot edit, and say nothing about it.
		const nonce = ( await ( await page.request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();
		const get = ( route ) => page.request.get( `/index.php?rest_route=${ encodeURIComponent( route ) }`, { headers: { 'X-WP-Nonce': nonce } } );
		const refused = await get( `/agent-governance/v1/posts/${ secret }` );
		expect( refused.status() ).toBe( 403 );
		expect( await refused.text() ).not.toContain( 'Secret merger' );
		const allowed = await get( `/agent-governance/v1/posts/${ hers }` );
		expect( allowed.status() ).toBe( 200 );
		const state = await allowed.json();
		expect( state.requests ).toHaveLength( 1 );

		const undo = await page.request.post( `/index.php?rest_route=${ encodeURIComponent( `/agent-governance/v1/posts/${ secret }/undo` ) }`, { headers: { 'X-WP-Nonce': nonce }, data: { log_id: 0 } } );
		expect( undo.status() ).toBe( 403 );

		// She cannot decide the request on the post she may not edit, even when she knows its number.
		const rows = sql( `SELECT id FROM wp_agent_governance_requests WHERE object_id = ${ secret }` );
		const decide = await page.request.post( `/index.php?rest_route=${ encodeURIComponent( `/agent-governance/v1/requests/${ rows[ 0 ][ 0 ] }/decide` ) }`, { headers: { 'X-WP-Nonce': nonce }, data: { decision: 'approve' } } );
		expect( decide.status() ).toBe( 403 );
		expect( post( secret, 'post_title' ) ).toBe( 'Secret merger plan' );

		// And the list shows no title for it.
		await page.goto( `${ SCREEN }&tab=requests` );
		await expect( page.locator( '.agg-requests' ) ).toContainText( 'You are not allowed to open what this call touches.' );
		await context.close();
	} );

	test( 'approving a call whose application password was revoked is refused, and the request ends', async ( { page } ) => {
		wp( 'agent-governance', 'add-rule', 'POST /wp/v2/posts/*', 'require_approval' );
		const id = newPost( 'Revoked', 'Body' );
		made.posts.push( id );
		const writer = await bot( 'Compromised bot' );
		const held = await writer.call( 'POST', `/wp/v2/posts/${ id }`, { title: 'Revoked, edited' } );
		const requestId = held.body.data.request_id;

		wp( 'user', 'application-password', 'delete', 'admin', writer.uuid );

		await page.goto( `${ SCREEN }&tab=requests&request=${ requestId }` );
		await page.getByRole( 'button', { name: 'Approve and run' } ).click();
		await page.locator( '.axle-confirm' ).getByRole( 'button', { name: 'Approve and run' } ).click();
		await expect( page.locator( '.notice-error' ) ).toContainText( 'application password this agent used was revoked' );
		expect( post( id, 'post_title' ) ).toBe( 'Revoked' );
		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ requestId }` ) ).toBe( 'expired' );
	} );

	test( 'a not-me report from Axle Sessions expires held requests from that persons application passwords', async () => {
		wp( 'agent-governance', 'add-rule', 'POST /wp/v2/posts/*', 'require_approval' );
		const id = newPost( 'Reported', 'Body' );
		made.posts.push( id );
		const writer = await bot( 'Content bot' );
		const held = await writer.call( 'POST', `/wp/v2/posts/${ id }`, { title: 'Reported, edited' } );
		const requestId = held.body.data.request_id;

		// Axle Sessions is not installed here: the same action it fires.
		wpEval( 'do_action( "axle_sessions_not_me_reported", 1, array( "source" => "test" ) );' );

		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ requestId }` ) ).toBe( 'expired' );
		expect( one( `SELECT reason FROM wp_agent_governance_requests WHERE id = ${ requestId }` ) ).toContain( 'not theirs' );

		const again = await writer.call( 'POST', `/wp/v2/posts/${ id }`, { title: 'Reported, again' } );
		wpEval( 'do_action( "axle_sessions_user_signed_out_everywhere", 1, 2, "self" );' );
		expect( one( `SELECT status FROM wp_agent_governance_requests WHERE id = ${ again.body.data.request_id }` ) ).toBe( 'expired' );
	} );

	test( 'WP-CLI shows and undoes the latest change to one post', async () => {
		const id = newPost( 'CLI post', 'Original body' );
		made.posts.push( id );
		const writer = await bot( 'Content bot' );
		await writer.call( 'POST', `/wp/v2/posts/${ id }`, { content: 'Agent body' } );

		const shown = JSON.parse( wp( 'agent-governance', 'post-changes', String( id ), '--format=json' ) );
		expect( shown.agent ).toBe( 'Content bot (admin)' );
		expect( shown.fields[ 0 ].before ).toBe( 'Original body' );

		wp( 'agent-governance', 'undo-change', String( id ), '--yes' );
		expect( post( id, 'post_content' ) ).toBe( 'Original body' );
		expect( succeeds( 'agent-governance', 'undo-change', String( id ), '--yes' ) ).toBe( false );
	} );

	test( 'the abilities show the change, and an agent cannot undo one', async ( { rest } ) => {
		const id = newPost( 'Ability post', 'Original body' );
		made.posts.push( id );
		const writer = await bot( 'Content bot' );
		await writer.call( 'POST', `/wp/v2/posts/${ id }`, { content: 'Agent body' } );

		const shown = await rest( 'GET', `/wp-abilities/v1/abilities/agent-governance/get-post-changes/run?input[post_id]=${ id }` );
		expect( shown.status ).toBe( 200 );
		expect( shown.body.change.agent ).toBe( 'Content bot (admin)' );

		const refused = await writer.call( 'POST', '/wp-abilities/v1/abilities/agent-governance/undo-post-change/run', { input: { post_id: id } } );
		expect( refused.status ).toBe( 403 );
		expect( post( id, 'post_content' ) ).toBe( 'Agent body' );

		const undone = await rest( 'POST', '/wp-abilities/v1/abilities/agent-governance/undo-post-change/run', { input: { post_id: id } } );
		expect( undone.status ).toBe( 200 );
		expect( undone.body ).toEqual( { undone: true, action: 'restored' } );
		expect( post( id, 'post_content' ) ).toBe( 'Original body' );
	} );
} );
