// Accessibility checks with axe-core, limited to what this plugin draws.
const { expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

// WCAG 2.0, 2.1 and 2.2, levels A and AA.
const TAGS = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

// Only these fail a test. Moderate and minor findings are printed, not failed.
const FAILING = [ 'serious', 'critical' ];

// wp-admin's own chrome: the menu, toolbar, footer, Screen Options and update
// nags. Core markup is not this plugin's to fix. axe still checks an element
// inside these when a screen names it in `include`, such as the plugin's own
// toolbar item.
const CORE = [ '#adminmenumain', '#wpadminbar', '#wpfooter', '#screen-meta', '#screen-meta-links', '.update-nag' ];

/**
 * Formats axe results as one readable block per rule.
 *
 * @param {Object[]} violations axe violations.
 * @return {string} Report.
 */
function report( violations ) {
	return violations
		.map( ( violation ) => {
			const nodes = violation.nodes.map( ( node ) => {
				const summary = ( node.failureSummary || '' ).split( '\n' ).map( ( line ) => `        ${ line.trim() }` ).join( '\n' );
				return `    - ${ node.target.join( ' >>> ' ) }\n      ${ node.html.replace( /\s+/g, ' ' ).slice( 0, 200 ) }\n${ summary }`;
			} );
			return `[${ violation.impact }] ${ violation.id }: ${ violation.help }\n  ${ violation.helpUrl }\n${ nodes.join( '\n' ) }`;
		} )
		.join( '\n\n' );
}

/**
 * Fails the test when axe finds a serious or critical problem in this plugin's
 * own markup on the current page. Moderate and minor findings are only printed.
 *
 * @param {import('@playwright/test').Page} page    Page to scan, already showing the screen.
 * @param {Object}                           context What to scan:
 *                                                   - name: label for the report.
 *                                                   - include: selectors for this plugin's own elements (default: the whole page).
 *                                                   - exclude: more selectors to leave out, on top of wp-admin's menu, footer and toolbar.
 *                                                   - disableRules: axe rule IDs to skip, each with a reason in a11y-screens.json.
 */
async function expectNoSeriousA11yViolations( page, context = {} ) {
	const { name = page.url(), include = [], exclude = [], disableRules = [] } = context;

	let builder = new AxeBuilder( { page } ).withTags( TAGS );
	for ( const selector of include ) {
		builder = builder.include( selector );
	}
	for ( const selector of [ ...CORE, ...exclude ] ) {
		builder = builder.exclude( selector );
	}
	if ( disableRules.length ) {
		builder = builder.disableRules( disableRules );
	}

	if ( include.length ) {
		// axe throws when no element matches the context. Say which selector is wrong instead.
		for ( const selector of include ) {
			expect( await page.locator( selector ).count(), `${ name }: nothing on the page matches "${ selector }"` ).toBeGreaterThan( 0 );
		}
	}

	const results = await builder.analyze();
	const failing = results.violations.filter( ( violation ) => FAILING.includes( violation.impact ) );
	const other = results.violations.filter( ( violation ) => ! FAILING.includes( violation.impact ) );

	if ( other.length ) {
		console.log( `${ name }: ${ other.length } moderate or minor accessibility finding(s), not failing:\n${ report( other ) }\n` ); // eslint-disable-line no-console
	}

	expect( failing.length, `${ name }: serious or critical accessibility violations:\n\n${ report( failing ) }\n` ).toBe( 0 );
}

module.exports = { expectNoSeriousA11yViolations };
