const { execFileSync } = require( 'child_process' );
const { COMPOSE_DIR } = require( './config' );

/**
 * Runs WP-CLI in the test site's cli container and returns its output.
 *
 * @param {...string} args WP-CLI arguments, e.g. ( 'post', 'list', '--format=ids' ).
 * @return {string} Trimmed standard output.
 */
function wp( ...args ) {
	return execFileSync( 'docker', [ 'compose', 'exec', '-T', 'cli', 'wp', ...args ], {
		cwd: COMPOSE_DIR,
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

/**
 * Runs PHP inside WordPress through `wp eval` and returns its output.
 *
 * @param {string} php PHP code.
 * @return {string} Output.
 */
function wpEval( php ) {
	return wp( 'eval', php );
}

module.exports = { wp, wpEval };
