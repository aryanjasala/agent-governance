// Demo content for the screenshots and the demo GIF: a small shop where two AI
// agents work through application passwords, one as the admin and one as an editor.
const { wp, wpEval, appPasswordClient } = require( './utils' );

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
 * A REST client signed in with a new application password, and no cookies.
 * The user's other application passwords are removed first, so the screens
 * only show this agent.
 *
 * @param {string} user User login.
 * @param {string} name Application password name.
 * @return {Promise<Object>} The appPasswordClient() helper's client.
 */
async function agent( user, name ) {
	try {
		wp( 'user', 'application-password', 'delete', user, '--all' );
	} catch ( error ) {
		// None yet.
	}
	return appPasswordClient( user, name );
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

/**
 * A morning of agent work for the screens: rules, a few allowed and denied
 * calls, held requests and a session to roll back.
 *
 * @return {Promise<{ids: Object, pageRequest: number}>} Content IDs and the held page edit.
 */
async function seedActivity() {
	installFixture();
	clearGovernance();
	createPeople();
	const ids = createContent();

	const shop = await agent( 'admin', 'Shop assistant' );
	const writer = await agent( 'maria', 'Content assistant' );
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
	const held = await writer.call( 'POST', `/wp/v2/pages/${ ids.about }`, {
		title: 'About Bean There',
		content: 'We roast coffee in small batches in Pune and ship it within a day of roasting, anywhere in India.',
		status: 'draft',
	} );
	await writer.call( 'DELETE', `/wp/v2/categories/${ ids.promotions }?force=true` );
	await shop.context.dispose();
	await writer.context.dispose();

	return { ids, pageRequest: held.body.data.request_id };
}

/** Undoes seedActivity(): the content, the people, the agents' passwords, the log, the rules and the fixture. */
function unseedActivity() {
	wpEval( `
		$titles = array( 'About us', 'About Bean There', 'Shipping and returns', 'Spring sale: 20% off every bag', 'Holiday opening hours', 'New single origin: Chikmagalur' );
		foreach ( $titles as $title ) {
			foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'title' => $title, 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
				wp_delete_post( $id, true );
			}
		}
		$term = get_term_by( 'name', 'Promotions', 'category' );
		if ( $term ) {
			wp_delete_term( $term->term_id, 'category' );
		}
	` );
	removePeople();
	try {
		wp( 'user', 'application-password', 'delete', 'admin', '--all' );
	} catch ( error ) {
		// None left.
	}
	clearGovernance();
	wpEval( '@unlink( WPMU_PLUGIN_DIR . "/agent-governance-fixture.php" );' );
}

module.exports = { clearGovernance, installFixture, createPeople, removePeople, agent, createContent, createRules, seedActivity, unseedActivity };
