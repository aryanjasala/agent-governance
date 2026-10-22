// Demo content for the screenshots and the demo GIF: a small shop where two AI
// agents work through application passwords, one as the admin and one as an editor.
const { wp, wpEval } = require( './utils' );
const { BASE_URL } = require( './utils/config' );

const sql = ( query ) => wp( 'db', 'query', query );

/** Empties the plugin's tables and settings. */
function clearGovernance() {
	sql( 'TRUNCATE TABLE wp_agent_governance_log' );
	sql( 'TRUNCATE TABLE wp_agent_governance_requests' );
	for ( const name of [ 'agent_governance_settings', 'agent_governance_rules' ] ) {
		try {
			wp( 'option', 'delete', name );
		} catch ( error ) {
			// Not set.
		}
	}
}

/** Application passwords need HTTPS unless the fixture allows them over plain HTTP. */
function installFixture() {
	wpEval( 'wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . "/agent-governance/tests/e2e/fixtures/agent-governance-fixture.php", WPMU_PLUGIN_DIR . "/agent-governance-fixture.php" );' );
}

/** Creates the editor the second agent signs in as. */
function createPeople() {
	try {
		wp( 'user', 'get', 'maria', '--field=ID' );
	} catch ( error ) {
		wp( 'user', 'create', 'maria', 'maria@example.com', '--role=editor', '--display_name=Maria Chen', '--user_pass=demo-password-123' );
	}
}

/** Removes the editor again. */
function removePeople() {
	try {
		wp( 'user', 'delete', 'maria', '--yes', '--reassign=1' );
	} catch ( error ) {
		// Already gone.
	}
}

/**
 * A REST client signed in with a new application password.
 *
 * @param {import('@playwright/test').APIRequest} pw   Playwright's request API.
 * @param {string}                                 user User login.
 * @param {string}                                 name Application password name.
 */
async function agent( pw, user, name ) {
	try {
		wp( 'user', 'application-password', 'delete', user, '--all' );
	} catch ( error ) {
		// None yet.
	}
	const password = wp( 'user', 'application-password', 'create', user, name, '--porcelain' );
	const uuid = wp( 'user', 'application-password', 'list', user, `--name=${ name }`, '--field=uuid' );
	const context = await pw.newContext( {
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: { Authorization: 'Basic ' + Buffer.from( `${ user }:${ password }` ).toString( 'base64' ) },
	} );
	const call = async ( method, route, data ) => {
		const [ path, query ] = route.split( '?' );
		const response = await context.fetch( `/index.php?rest_route=${ encodeURIComponent( path ) }${ query ? '&' + query : '' }`, {
			method,
			headers: data ? { 'Content-Type': 'application/json' } : {},
			data: data ? JSON.stringify( data ) : undefined,
		} );
		return { status: response.status(), body: await response.json().catch( () => null ) };
	};

	return { uuid, context, call };
}

/** Shop pages and posts. */
function createContent() {
	const page = ( title, content ) => Number( wp( 'post', 'create', '--post_type=page', `--post_title=${ title }`, `--post_content=${ content }`, '--post_status=publish', '--porcelain' ) );
	const post = ( title, content ) => Number( wp( 'post', 'create', `--post_title=${ title }`, `--post_content=${ content }`, '--post_status=publish', '--porcelain' ) );

	const promotions = Number( wp( 'term', 'create', 'category', 'Promotions', '--porcelain' ) );
	const ids = {
		about: page( 'About us', 'We roast coffee in small batches in Pune and ship it fresh across India.' ),
		shipping: page( 'Shipping and returns', 'Orders ship within 2 working days. Returns are free within 14 days.' ),
		spring: post( 'Spring sale: 20% off every bag', 'Our spring sale runs until 31 March.' ),
		holiday: post( 'Holiday opening hours', 'We are closed on 25 December and 1 January.' ),
		promotions,
	};
	wp( 'post', 'term', 'set', String( ids.spring ), 'category', String( promotions ), '--by=id' );
	wp( 'post', 'term', 'set', String( ids.holiday ), 'category', String( promotions ), '--by=id' );
	return ids;
}

/** Rules for the Policies screen and the held requests. */
function createRules( shopUuid ) {
	wp( 'agent-governance', 'add-rule', 'POST|PUT /wp/v2/pages/*', 'require_approval', '--scope=role', '--subject=editor' );
	wp( 'agent-governance', 'add-rule', '/wp/v2/users*', 'deny' );
	wp( 'agent-governance', 'add-rule', 'core/*', 'allow', '--scope=agent', `--subject=${ shopUuid }` );
	wp( 'agent-governance', 'settings', '--rate-limit=60' );
}

module.exports = { clearGovernance, installFixture, createPeople, removePeople, agent, createContent, createRules };
