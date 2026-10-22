// End-to-end tests. Start the test site first: npm run env:start
const { defineConfig } = require( '@playwright/test' );
const { BASE_URL, STORAGE_STATE } = require( './tests/e2e/utils/config' );

module.exports = defineConfig( {
	testDir: 'tests/e2e',
	testMatch: '**/*.spec.js',
	// The screenshot and demo specs only run on demand: npm run screenshots, npm run demo
	testIgnore: [ process.env.SCREENSHOTS ? '' : '**/screenshots.spec.js', process.env.DEMO ? '' : '**/demo.spec.js' ].filter( Boolean ),
	// One WordPress site: tests change global state, so they run one at a time.
	workers: 1,
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	expect: { timeout: 10_000 },
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ], [ 'html', { open: 'never' } ] ] : [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	globalSetup: require.resolve( './tests/e2e/utils/global-setup.js' ),
	use: {
		baseURL: BASE_URL,
		storageState: STORAGE_STATE,
		channel: process.env.PW_CHANNEL || 'chrome',
		headless: true,
		viewport: { width: 1440, height: 900 },
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
} );
