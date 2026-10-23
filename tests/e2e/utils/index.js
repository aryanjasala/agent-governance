// Test fixtures for the end-to-end tests.
const base = require( '@playwright/test' );
const { wp, wpEval } = require( './wp-cli' );
const { logIn, logInAs, appPasswordClient } = require( './auth' );
const { expectNoSeriousA11yViolations } = require( './a11y' );

const test = base.test.extend( {
	/**
	 * Calls the REST API as the logged-in admin.
	 *
	 * Usage: await rest( 'POST', '/wp/v2/pages', { title: 'x' } )
	 */
	rest: async ( { request }, use ) => {
		const nonce = ( await ( await request.get( '/wp-admin/admin-ajax.php?action=rest-nonce' ) ).text() ).trim();

		await use( async ( method, route, data ) => {
			const [ path, query ] = route.split( '?' );
			const response = await request.fetch( `/index.php?rest_route=${ encodeURIComponent( path ) }${ query ? '&' + query : '' }`, {
				method,
				headers: { 'X-WP-Nonce': nonce },
				data,
			} );
			const body = await response.json().catch( () => null );
			return { status: response.status(), body };
		} );
	},

	wp: async ( {}, use ) => use( wp ), // eslint-disable-line no-empty-pattern
	wpEval: async ( {}, use ) => use( wpEval ), // eslint-disable-line no-empty-pattern
} );

/**
 * Opens the block editor for a post and dismisses the welcome guide.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {number}                           postId Post ID.
 */
async function openEditor( page, postId ) {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page.waitForFunction( () => window.wp && window.wp.data && window.wp.data.select( 'core/editor' ).getCurrentPostId() );
	await page.evaluate( () => {
		window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		window.wp.data.dispatch( 'core/preferences' ).set( 'core', 'welcomeGuide', false );
	} );
	const guide = page.getByRole( 'dialog', { name: /welcome/i } );
	if ( await guide.isVisible().catch( () => false ) ) {
		await page.keyboard.press( 'Escape' );
	}
}

module.exports = { test, expect: base.expect, wp, wpEval, openEditor, logIn, logInAs, appPasswordClient, expectNoSeriousA11yViolations };
