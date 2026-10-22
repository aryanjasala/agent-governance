// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { clearGovernance, installFixture, createPeople, removePeople, agent, createContent, createRules } = require( './seed' );

test( 'demo: an agent edit is held, reviewed and approved', async ( { browser, playwright } ) => {
	test.setTimeout( 180_000 );
	prepareSite( 'Bean There Coffee' );
	installFixture();
	clearGovernance();
	createPeople();
	const ids = createContent();

	const shop = await agent( playwright.request, 'admin', 'Shop assistant' );
	const writer = await agent( playwright.request, 'maria', 'Content assistant' );
	createRules( shop.uuid );
	wp( 'agent-governance', 'settings', '--email=off' );

	await shop.call( 'GET', '/wp/v2/pages?per_page=20' );
	await shop.call( 'POST', `/wp/v2/pages/${ ids.shipping }`, { content: 'Orders ship within 1 working day. Returns are free within 30 days.' } );
	await writer.call( 'GET', '/wp/v2/posts' );
	const held = await writer.call( 'POST', `/wp/v2/pages/${ ids.about }`, {
		title: 'About Bean There',
		content: 'We roast coffee in small batches in Pune and ship it within a day of roasting, anywhere in India.',
	} );
	expect( held.status ).toBe( 202 );
	await shop.context.dispose();
	await writer.context.dispose();

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/' );
			await expect( page.locator( '.agg-notice' ) ).toBeVisible();
			await demo.start();
			await demo.pause( 1500 );

			// The notice says an agent is waiting.
			await demo.click( page.locator( '.agg-notice a' ), 300 );
			await expect( page.locator( '.agg-requests tbody tr' ) ).toHaveCount( 1 );
			await demo.pause( 1500 );

			// What would change, before and after.
			await demo.click( page.locator( '.agg-requests .row-title' ).first(), 300 );
			await expect( page.locator( '.agg-diff' ) ).toBeVisible();
			await demo.moveTo( page.locator( '.agg-diff tbody tr' ).first() );
			await demo.pause( 2500 );

			// Approve: it runs now, as Maria.
			await demo.click( page.getByRole( 'button', { name: 'Approve and run' } ), 300 );
			await expect( page.locator( '.notice-success' ) ).toContainText( 'Approved. The call ran.' );
			await demo.pause( 2000 );

			// The log has the held call and the approved one.
			await demo.click( page.getByRole( 'link', { name: 'Activity' } ), 300 );
			await expect( page.locator( '.agg-activity tbody tr' ).first() ).toContainText( 'Executed' );
			await demo.pause( 2500 );
		} );
	} finally {
		wp( 'option', 'delete', 'agent_governance_rules' );
		wp( 'option', 'delete', 'agent_governance_settings' );
		removePeople();
	}

	expect( wp( 'post', 'get', String( ids.about ), '--field=post_title' ) ).toBe( 'About Bean There' );
} );
