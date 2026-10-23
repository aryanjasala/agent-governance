// Signing in as someone other than the stored admin session: through the login
// form, or as a REST client with an application password.
const { request, expect } = require( '@playwright/test' );
const { BASE_URL, PASSWORD } = require( './config' );
const { wp } = require( './wp-cli' );

const NO_COOKIES = { cookies: [], origins: [] };

/**
 * Signs in through wp-login.php on a page.
 *
 * wp-login.php moves focus to the username field 200 ms after it loads. Typing
 * before that timer fires can land the password in the username field, so this
 * waits for it first.
 *
 * It resolves once the page after the form has loaded, whether the login worked
 * or not, so tests can check a refused login too (page.url(), #login_error).
 *
 * @param {import('@playwright/test').Page} page     Page, usually in a context with no cookies.
 * @param {string}                           username User login.
 * @param {string}                           password Password. Default: the test admin's.
 */
async function logIn( page, username, password = PASSWORD ) {
	await page.goto( '/wp-login.php' );
	await page
		.waitForFunction( () => document.activeElement && [ 'user_login', 'user_pass' ].includes( document.activeElement.id ), null, { timeout: 3000 } )
		.catch( () => null );
	await page.locator( '#user_login' ).fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await expect( page.locator( '#user_login' ) ).toHaveValue( username );
	// The next page only loads once the login request has finished on the server.
	await Promise.all( [ page.waitForEvent( 'framenavigated', ( frame ) => frame === page.mainFrame() ), page.locator( '#wp-submit' ).click() ] );
	await page.waitForLoadState( 'domcontentloaded' );
}

/**
 * Signs a user in from a new browser context that starts with no cookies.
 *
 * @param {import('@playwright/test').Browser} browser  Browser.
 * @param {string}                             username User login.
 * @param {string}                             password Password. Default: the test admin's.
 * @param {Object}                             options  Extra browser.newContext() options, such as userAgent.
 * @return {Promise<{context: import('@playwright/test').BrowserContext, page: import('@playwright/test').Page}>} Close the context when done.
 */
async function logInAs( browser, username, password = PASSWORD, options = {} ) {
	const context = await browser.newContext( { baseURL: BASE_URL, ...options, storageState: NO_COOKIES } );
	const page = await context.newPage();
	await logIn( page, username, password );
	return { context, page };
}

/**
 * A REST client that signs in with a new application password, and only with it.
 *
 * Its request context carries no cookies. With the admin's login cookie as well,
 * WordPress picks the cookie, finds no REST nonce and answers 401.
 *
 * WordPress only offers application passwords over HTTPS or on a site whose
 * environment type is "local". On the plain HTTP test site, a must-use plugin
 * fixture has to add: add_filter( 'wp_is_application_passwords_available', '__return_true' );
 *
 * @param {string} username User login.
 * @param {string} name     Application password name. An older one with the same name is replaced.
 * @return {Promise<{uuid: string, password: string, context: import('@playwright/test').APIRequestContext, call: Function, dispose: Function}>}
 *         call( method, route, data ) resolves to { status, body, headers }; the route may carry a query string.
 *         dispose() closes the context and deletes the application password.
 */
async function appPasswordClient( username, name ) {
	const existing = wp( 'user', 'application-password', 'list', username, `--name=${ name }`, '--field=uuid' );
	for ( const uuid of existing.split( '\n' ).filter( Boolean ) ) {
		wp( 'user', 'application-password', 'delete', username, uuid );
	}
	const password = wp( 'user', 'application-password', 'create', username, name, '--porcelain' );
	const uuid = wp( 'user', 'application-password', 'list', username, `--name=${ name }`, '--field=uuid' );

	const context = await request.newContext( {
		baseURL: BASE_URL,
		storageState: NO_COOKIES,
		extraHTTPHeaders: { Authorization: 'Basic ' + Buffer.from( `${ username }:${ password }` ).toString( 'base64' ) },
	} );

	const call = async ( method, route, data ) => {
		const [ path, query ] = route.split( '?' );
		const response = await context.fetch( `/index.php?rest_route=${ encodeURIComponent( path ) }${ query ? '&' + query : '' }`, {
			method,
			headers: data ? { 'Content-Type': 'application/json' } : {},
			data: data ? JSON.stringify( data ) : undefined,
		} );
		return { status: response.status(), body: await response.json().catch( () => null ), headers: response.headers() };
	};

	const dispose = async () => {
		await context.dispose();
		try {
			wp( 'user', 'application-password', 'delete', username, uuid );
		} catch ( error ) {
			// Already gone.
		}
	};

	return { uuid, password, context, call, dispose };
}

module.exports = { logIn, logInAs, appPasswordClient };
