const path = require( 'path' );

module.exports = {
	SLUG: 'agent-governance',
	// Each plugin has its own port, so its site can run next to the others.
	// WP_PORT moves it (start the site with the same WP_PORT), WP_BASE_URL points the tests elsewhere.
	BASE_URL: process.env.WP_BASE_URL || `http://localhost:${ process.env.WP_PORT || 8906 }`,
	// Test-only credentials for the throwaway Docker site.
	USERNAME: process.env.WP_USERNAME || 'admin',
	PASSWORD: process.env.WP_PASSWORD || 'admin',
	STORAGE_STATE: path.join( __dirname, '..', '.auth', 'admin.json' ),
	// This plugin's own Docker site by default. A shared site can be used by pointing WP_COMPOSE_DIR at its compose folder.
	COMPOSE_DIR: process.env.WP_COMPOSE_DIR || path.join( __dirname, '..', 'env' ),
	// Options the plugin stores, cleared before each run.
	OPTIONS: ['agent_governance_settings', 'agent_governance_rules'],
};
