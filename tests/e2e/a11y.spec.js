// Accessibility checks: visits every screen listed in a11y-screens.json and fails
// on serious or critical axe-core findings in this plugin's own markup.
// The list is this plugin's own file; the fields are described in docs/DEVELOPMENT.md.
const fs = require( 'fs' );
const path = require( 'path' );
const { test, wp, openEditor, logIn } = require( './utils' );
const { expectNoSeriousA11yViolations } = require( './utils/a11y' );

const config = JSON.parse( fs.readFileSync( path.join( __dirname, 'a11y-screens.json' ), 'utf8' ) );
const skip = config.skip || {};

test.describe( 'Accessibility', () => {
	for ( const screen of config.screens || [] ) {
		test( screen.name, async ( { page, browser, rest, request } ) => {
			test.skip( Boolean( skip[ screen.name ] ), skip[ screen.name ] );

			// What setup saves ( "save": "post" ) fills {post} in later fields; {ids.hours} reads a key of a saved object.
			// {theme} is the theme the suite runs with.
			const saved = { theme: process.env.WP_THEME || 'twentytwentyfive' };
			const fill = ( text ) =>
				String( text ).replace( /\{([\w.]+)\}/g, ( match, key ) => {
					const value = key.split( '.' ).reduce( ( object, part ) => ( object && part in Object( object ) ? object[ part ] : undefined ), saved );
					return undefined === value ? match : String( value );
				} );
			// A WP-CLI command as an array, { "wp": [...] }, or a function from a helper file next to this spec:
			// { "js": "seed.js", "call": "seed", "args": [] }. The args "@rest" and "@request" pass those fixtures.
			// "mayFail": true keeps going when the command exits with an error.
			const run = async ( command ) => {
				const step = Array.isArray( command ) ? { wp: command } : command;
				let output;
				try {
					if ( step.js ) {
						const fixtures = { '@rest': rest, '@request': request };
						const args = ( step.args || [] ).map( ( arg ) => ( 'string' === typeof arg && arg in fixtures ? fixtures[ arg ] : JSON.parse( fill( JSON.stringify( arg ) ) ) ) );
						output = await require( path.join( __dirname, step.js ) )[ step.call ]( ...args );
					} else {
						output = wp( ...step.wp.map( fill ) );
					}
				} catch ( error ) {
					if ( ! step.mayFail ) {
						throw error;
					}
				}
				if ( step.save ) {
					saved[ step.save ] = output;
				}
			};

			let visitor;
			let tab = page;
			try {
				for ( const command of screen.setup || [] ) {
					await run( command );
				}

				if ( screen.loggedOut ) {
					visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
					tab = await visitor.newPage();
				}
				if ( 'accept' === screen.dialogs ) {
					tab.on( 'dialog', ( dialog ) => dialog.accept() );
				}
				if ( screen.editor ) {
					await openEditor( tab, Number( fill( screen.editor ) ) );
				} else {
					await tab.goto( fill( screen.url ) );
				}

				for ( const step of screen.steps || [] ) {
					if ( step.logIn ) {
						await logIn( tab, fill( step.logIn ), fill( step.password || '' ) || undefined );
					}
					if ( step.evaluate ) {
						await tab.evaluate( fill( step.evaluate ) );
					}
					if ( step.hover ) {
						await tab.locator( fill( step.hover ) ).first().hover();
					}
					if ( step.click ) {
						await tab.locator( fill( step.click ) ).first().click();
					}
					if ( step.fill ) {
						await tab.locator( fill( step.fill ) ).first().fill( fill( step.value || '' ) );
					}
					if ( step.press ) {
						await tab.keyboard.press( step.press );
					}
					if ( step.waitFor ) {
						await tab.locator( fill( step.waitFor ) ).first().waitFor();
					}
				}

				await expectNoSeriousA11yViolations( tab, {
					name: screen.name,
					include: ( screen.include || config.include || [] ).map( fill ),
					exclude: [ ...( config.exclude || [] ), ...( screen.exclude || [] ) ].map( fill ),
					disableRules: Object.keys( screen.disableRules || {} ),
				} );
			} finally {
				if ( visitor ) {
					await visitor.close();
				}
				for ( const command of screen.cleanup || [] ) {
					try {
						await run( command );
					} catch ( error ) {
						// Cleanup is best effort; the next screen starts from its own setup.
					}
				}
			}
		} );
	}
} );
