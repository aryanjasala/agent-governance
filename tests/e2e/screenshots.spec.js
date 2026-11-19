// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp, openEditor, appPasswordClient } = require( './utils' );
const { STORAGE_STATE } = require( './utils/config' );
const { prepareSite, shot } = require( './utils/media' );
const { removePeople, seedActivity } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=agent-governance';

test.describe( 'Screenshots', () => {
	let context;
	let page;
	let pageRequest;

	test.beforeAll( async ( { browser } ) => {
		test.setTimeout( 120_000 );
		prepareSite( 'Bean There Coffee' );
		( { pageRequest } = await seedActivity() );

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

	test( '6: an agent change, in the editor', async () => {
		const id = Number( wp( 'post', 'create', '--post_title=Spring sale: 20% off every bag', '--post_content=Our spring sale runs until 31 March.', '--post_status=publish', '--porcelain' ) );
		const agent = await appPasswordClient( 'maria', 'Content assistant' );
		await agent.call( 'POST', `/wp/v2/posts/${ id }`, { content: 'Our spring sale runs until 7 April, and every bag ships free.' } );
		await openEditor( page, id );
		const panel = page.locator( '.agg-editor-panel' );
		await expect( panel ).toContainText( 'changed this' );
		await panel.getByRole( 'button', { name: 'View changes' } ).click();
		await expect( panel.locator( '.agg-editor-diff__side--after' ).first() ).toBeVisible();
		await panel.getByRole( 'heading', { name: 'Agent changes' } ).scrollIntoViewIfNeeded();
		await page.locator( '.interface-complementary-area' ).evaluate( ( el ) => el.scrollTo( 0, el.scrollHeight ) );
		await shot( page, 6 );
		await agent.dispose();
		wp( 'post', 'delete', String( id ), '--force' );
	} );
} );
