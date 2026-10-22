// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp } = require( './utils' );
const { STORAGE_STATE } = require( './utils/config' );
const { prepareSite, shot } = require( './utils/media' );
const { clearGovernance, installFixture, createPeople, removePeople, agent, createContent, createRules } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=agent-governance';

test.describe( 'Screenshots', () => {
	let context;
	let page;
	let pageRequest;

	test.beforeAll( async ( { browser, playwright } ) => {
		test.setTimeout( 120_000 );
		prepareSite( 'Bean There Coffee' );
		installFixture();
		clearGovernance();
		createPeople();
		const ids = createContent();

		const shop = await agent( playwright.request, 'admin', 'Shop assistant' );
		const writer = await agent( playwright.request, 'maria', 'Content assistant' );
		createRules( shop.uuid );

		// The shop assistant reads, fixes a page, drafts a post and asks to delete an old one.
		await shop.call( 'GET', '/wp-abilities/v1/abilities/core/get-site-info/run' );
		await shop.call( 'GET', '/wp/v2/pages?per_page=20' );
		await shop.call( 'POST', `/wp/v2/pages/${ ids.shipping }`, { content: 'Orders ship within 1 working day. Returns are free within 30 days, and we send a prepaid label.' } );
		await shop.call( 'POST', '/wp/v2/posts', { title: 'New single origin: Chikmagalur', status: 'draft', content: 'Notes of cocoa and orange.' } );
		await shop.call( 'DELETE', `/wp/v2/posts/${ ids.holiday }` );
		await shop.call( 'POST', '/wp/v2/users/1', { email: 'owner@example.com' } );

		// The content assistant edits a page and wants a category gone.
		await writer.call( 'GET', '/wp/v2/posts' );
		pageRequest = ( await writer.call( 'POST', `/wp/v2/pages/${ ids.about }`, {
			title: 'About Bean There',
			content: 'We roast coffee in small batches in Pune and ship it within a day of roasting, anywhere in India.',
			status: 'draft',
		} ) ).body.data.request_id;
		await writer.call( 'DELETE', `/wp/v2/categories/${ ids.promotions }?force=true` );
		await shop.context.dispose();
		await writer.context.dispose();

		context = await browser.newContext( { storageState: STORAGE_STATE, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		page = await context.newPage();
	} );

	test.afterAll( async () => {
		await context.close();
		removePeople();
	} );

	test( '1: the approval queue', async () => {
		await page.goto( SCREEN );
		await expect( page.locator( '.agg-requests tbody tr' ) ).toHaveCount( 3 );
		await shot( page, 1 );
	} );

	test( '2: a held request with its preview', async () => {
		await page.goto( `${ SCREEN }&tab=requests&request=${ pageRequest }` );
		await expect( page.locator( '.agg-diff' ) ).toBeVisible();
		await shot( page, 2, { fullPage: true } );
	} );

	test( '3: the activity log', async () => {
		await page.goto( `${ SCREEN }&tab=activity` );
		await expect( page.locator( '.agg-activity tbody tr' ).first() ).toBeVisible();
		await shot( page, 3 );
	} );

	test( '4: reviewing a session before rolling it back', async () => {
		await page.goto( `${ SCREEN }&tab=sessions` );
		await page.locator( 'tr', { hasText: 'Shop assistant' } ).getByRole( 'link', { name: 'Review' } ).click();
		await expect( page.locator( '.agg-plan' ) ).toContainText( 'Moves to the trash' );
		await shot( page, 4, { fullPage: true } );
	} );

	test( '5: policies', async () => {
		await page.goto( `${ SCREEN }&tab=policies` );
		await expect( page.locator( '.agg-rule' ) ).toHaveCount( 4 );
		await shot( page, 5, { fullPage: true } );
		wp( 'option', 'delete', 'agent_governance_rules' );
	} );
} );
