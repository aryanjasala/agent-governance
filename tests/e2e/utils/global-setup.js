// Installs WordPress if needed, resets the site to a known state, and logs in once.
const fs = require( 'fs' );
const path = require( 'path' );
const { request } = require( '@playwright/test' );
const { SLUG, BASE_URL, USERNAME, PASSWORD, STORAGE_STATE, OPTIONS } = require( './config' );
const { wp } = require( './wp-cli' );

function waitForDatabase() {
	const deadline = Date.now() + 120000;
	for ( ;; ) {
		try {
			wp( 'db', 'check' );
			return;
		} catch ( error ) {
			if ( Date.now() > deadline ) {
				throw new Error( 'The test site database did not come up. Is the Docker environment running (npm run env:start)?' );
			}
			Atomics.wait( new Int32Array( new SharedArrayBuffer( 4 ) ), 0, 0, 2000 );
		}
	}
}

module.exports = async function globalSetup() {
	waitForDatabase();

	try {
		wp( 'core', 'is-installed' );
	} catch ( error ) {
		wp( 'core', 'install', `--url=${ BASE_URL }`, '--title=E2E', `--admin_user=${ USERNAME }`, `--admin_password=${ PASSWORD }`, '--admin_email=admin@example.com', '--skip-email' );
	}

	wp( 'site', 'empty', '--yes' );
	// WP_THEME lets the oldest supported WordPress run the suite with a theme it has.
	wp( 'theme', 'activate', process.env.WP_THEME || 'twentytwentyfive' );
	wp( 'plugin', 'activate', SLUG );
	wp( 'rewrite', 'structure', '/%postname%/', '--hard' );
	for ( const option of OPTIONS ) {
		try {
			wp( 'option', 'delete', option );
		} catch ( error ) {
			// Option did not exist.
		}
	}

	const context = await request.newContext( { baseURL: BASE_URL } );
	// wp-login.php refuses logins unless its test cookie was set first.
	await context.get( '/wp-login.php' );
	const response = await context.post( '/wp-login.php', {
		form: { log: USERNAME, pwd: PASSWORD, rememberme: 'forever', testcookie: '1', 'wp-submit': 'Log In', redirect_to: '/wp-admin/' },
		headers: { Cookie: 'wordpress_test_cookie=WP%20Cookie%20check' },
	} );
	if ( ! response.url().includes( '/wp-admin' ) ) {
		throw new Error( `Login failed, ended on ${ response.url() }` );
	}

	fs.mkdirSync( path.dirname( STORAGE_STATE ), { recursive: true } );
	await context.storageState( { path: STORAGE_STATE } );
	await context.dispose();
};
