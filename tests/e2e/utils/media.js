// Helpers for the README and WordPress.org media: the screenshots in .wordpress-org/
// and the demo GIF in .github/media/. Both run on demand against this plugin's own
// Docker site (npm run env:start), where it is the only active plugin, so nothing
// from other plugins ends up in the pictures.
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE_URL, STORAGE_STATE } = require( './config' );
const { wp } = require( './wp-cli' );

const ROOT = path.join( __dirname, '..', '..', '..' );

/**
 * Makes the test site look like a real one: a site name instead of "E2E", no
 * welcome panel, and no Site Health count in the menu.
 *
 * @param {string} name Site name shown in the admin bar.
 */
function prepareSite( name ) {
	wp( 'option', 'update', 'blogname', name );
	wp( 'user', 'meta', 'update', 'admin', 'show_welcome_panel', '0' );
	wp( 'transient', 'delete', 'health-check-site-status-result' );
	try {
		wp( 'cron', 'event', 'unschedule', 'wp_site_health_scheduled_check' );
	} catch ( error ) {
		// Not scheduled yet.
	}
}

/**
 * Saves .wordpress-org/screenshot-N.png with no focus ring or hover left over.
 *
 * @param {import('@playwright/test').Page} page    Page.
 * @param {number}                           number  Screenshot number from readme.txt.
 * @param {Object}                           options Extra page.screenshot() options.
 */
async function shot( page, number, options = {} ) {
	if ( ! options.keepHover ) {
		// Park the mouse in the empty bottom-right corner. The top-left corner would open the W menu.
		const { width, height } = page.viewportSize();
		await page.mouse.move( width - 2, height - 2 );
	}
	await page.evaluate( () => document.activeElement && document.activeElement.blur() );
	// Notices and modals fade in.
	await page.waitForTimeout( 600 );
	const { keepHover, ...rest } = options; // eslint-disable-line no-unused-vars
	await page.screenshot( { path: path.join( ROOT, '.wordpress-org', `screenshot-${ number }.png` ), ...rest } );
}

// A pointer that follows the mouse, so the GIF shows where each click lands.
// Headless Chrome draws no cursor of its own.
const CURSOR = `( () => {
	const KEY = 'demo-cursor';
	if ( window.self !== window.top ) {
		// The block editor canvas is a same-origin iframe: pass its mouse moves up.
		const forward = ( type ) => ( event ) => {
			try {
				const rect = window.frameElement.getBoundingClientRect();
				window.parent.postMessage( { [ KEY ]: type, x: event.clientX + rect.left, y: event.clientY + rect.top }, '*' );
			} catch ( error ) {}
		};
		document.addEventListener( 'mousemove', forward( 'move' ), true );
		document.addEventListener( 'mousedown', forward( 'down' ), true );
		return;
	}
	const install = () => {
		const style = document.createElement( 'style' );
		style.textContent = '#' + KEY + '{position:fixed;left:0;top:0;z-index:2147483647;pointer-events:none;width:26px;height:26px}' +
			'.' + KEY + '-ring{position:fixed;z-index:2147483646;pointer-events:none;width:36px;height:36px;margin:-18px 0 0 -18px;border-radius:50%;background:rgba(56,88,233,.35);animation:' + KEY + '-ring .45s ease-out forwards}' +
			'@keyframes ' + KEY + '-ring{from{transform:scale(.3);opacity:1}to{transform:scale(1.4);opacity:0}}';
		document.documentElement.appendChild( style );
		const cursor = document.createElement( 'div' );
		cursor.id = KEY;
		cursor.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26"><path d="M5 3l14 8.2-6.1 1.5L9.6 19z" fill="#1e1e1e" stroke="#fff" stroke-width="1.6" stroke-linejoin="round"/></svg>';
		document.documentElement.appendChild( cursor );
		const move = ( x, y ) => {
			cursor.style.transform = 'translate(' + ( x - 5 ) + 'px,' + ( y - 3 ) + 'px)';
			try {
				sessionStorage.setItem( KEY, JSON.stringify( [ x, y ] ) );
			} catch ( error ) {}
		};
		const ring = ( x, y ) => {
			const dot = document.createElement( 'div' );
			dot.className = KEY + '-ring';
			dot.style.left = x + 'px';
			dot.style.top = y + 'px';
			document.documentElement.appendChild( dot );
			setTimeout( () => dot.remove(), 500 );
		};
		let last = [ -100, -100 ];
		try {
			last = JSON.parse( sessionStorage.getItem( KEY ) ) || last;
		} catch ( error ) {}
		move( last[ 0 ], last[ 1 ] );
		document.addEventListener( 'mousemove', ( event ) => move( event.clientX, event.clientY ), true );
		document.addEventListener( 'mousedown', ( event ) => ring( event.clientX, event.clientY ), true );
		window.addEventListener( 'message', ( event ) => {
			if ( event.data && event.data[ KEY ] === 'move' ) {
				move( event.data.x, event.data.y );
			} else if ( event.data && event.data[ KEY ] === 'down' ) {
				ring( event.data.x, event.data.y );
			}
		} );
	};
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', install );
	} else {
		install();
	}
} )();`;

/**
 * Records a demo of the plugin and saves it as .github/media/demo.gif.
 *
 * Frames come from Chrome's screencast at twice the viewport size and are scaled
 * down, which keeps admin text sharp. Needs ffmpeg on the PATH.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @param {Function}                           run     async ( page, demo ) => {}, the steps to record.
 * @param {Object}                             options viewport, width (GIF width), fps, hold (seconds on the last frame).
 */
async function recordDemo( browser, run, options = {} ) {
	const viewport = options.viewport || { width: 1280, height: 720 };
	const width = options.width || 1200;
	const fps = options.fps || 12;
	const hold = options.hold || 2.5;
	const output = path.join( ROOT, '.github', 'media', options.file || 'demo.gif' );
	const frames = fs.mkdtempSync( path.join( os.tmpdir(), 'demo-frames-' ) );

	const context = await browser.newContext( { baseURL: BASE_URL, storageState: STORAGE_STATE, viewport, deviceScaleFactor: 2 } );
	await context.addInitScript( CURSOR );
	const page = await context.newPage();
	page.on( 'dialog', ( dialog ) => dialog.accept() );

	const cdp = await context.newCDPSession( page );
	const list = [];
	let recording = false;
	cdp.on( 'Page.screencastFrame', ( { data, metadata, sessionId } ) => {
		cdp.send( 'Page.screencastFrameAck', { sessionId } ).catch( () => {} );
		if ( ! recording ) {
			return;
		}
		const file = path.join( frames, `${ String( list.length ).padStart( 5, '0' ) }.jpg` );
		fs.writeFileSync( file, Buffer.from( data, 'base64' ) );
		list.push( { file, time: metadata.timestamp } );
	} );
	await cdp.send( 'Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: viewport.width * 2, maxHeight: viewport.height * 2 } );

	const demo = {
		/** Starts keeping frames. Call it once the first screen has loaded. */
		start: async () => {
			await page.waitForTimeout( 300 );
			recording = true;
		},
		/** Waits so the viewer can read the screen. */
		pause: ( ms = 1200 ) => page.waitForTimeout( ms ),
		/** Glides the pointer to the middle of an element. */
		moveTo: async ( locator, steps = 30 ) => {
			await locator.scrollIntoViewIfNeeded();
			const box = await locator.boundingBox();
			await page.mouse.move( box.x + box.width / 2, box.y + box.height / 2, { steps } );
		},
		/** Moves to an element, then clicks it. */
		click: async ( locator, after = 700 ) => {
			await demo.moveTo( locator );
			await page.waitForTimeout( 250 );
			await locator.click();
			await page.waitForTimeout( after );
		},
		/** Clicks a field, clears it and types into it at a readable speed. */
		type: async ( locator, text ) => {
			await demo.click( locator, 200 );
			await locator.fill( '' );
			await locator.pressSequentially( text, { delay: 45 } );
			await page.waitForTimeout( 400 );
		},
		/** Picks an option in a select. Headless Chrome draws no open dropdown, so the value just changes. */
		choose: async ( locator, value ) => {
			await demo.moveTo( locator );
			await page.waitForTimeout( 300 );
			await locator.selectOption( value );
			await page.waitForTimeout( 800 );
		},
	};

	try {
		await run( page, demo );
		await page.waitForTimeout( 400 );
	} finally {
		recording = false;
		await cdp.send( 'Page.stopScreencast' ).catch( () => {} );
		await context.close();
	}

	if ( list.length < 2 ) {
		throw new Error( 'No frames were recorded. Did the demo call demo.start()?' );
	}

	// ffmpeg concat list: each frame stays up until the next one arrived.
	const lines = [];
	list.forEach( ( frame, index ) => {
		const next = list[ index + 1 ];
		lines.push( `file '${ frame.file }'`, `duration ${ next ? Math.max( next.time - frame.time, 0.001 ).toFixed( 3 ) : hold }` );
	} );
	lines.push( `file '${ list[ list.length - 1 ].file }'` );
	const concat = path.join( frames, 'frames.txt' );
	fs.writeFileSync( concat, lines.join( '\n' ) );

	fs.mkdirSync( path.dirname( output ), { recursive: true } );
	execFileSync( 'ffmpeg', [
		'-y', '-loglevel', 'error',
		'-f', 'concat', '-safe', '0', '-i', concat,
		'-vf', `fps=${ fps },scale=${ width }:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=256:stats_mode=diff[p];[b][p]paletteuse=dither=sierra2_4a:diff_mode=rectangle`,
		'-loop', '0',
		output,
	] );
	fs.rmSync( frames, { recursive: true, force: true } );
	return output;
}

module.exports = { prepareSite, shot, recordDemo };
