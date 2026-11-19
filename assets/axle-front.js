/**
 * Axle front-end bar: pads the page by the bars' real heights and moves
 * block themes' sticky headers below a top bar. Loaded by AxleUi::enqueue_front().
 * Generated from _dev/repo-template. Do not edit it in a plugin.
 */
( function () {
	if ( window.axleFrontReady ) {
		return;
	}
	window.axleFrontReady = true;

	var html = document.documentElement;

	function adminBarHeight() {
		var bar = document.getElementById( 'wpadminbar' );
		if ( ! bar || 'fixed' !== window.getComputedStyle( bar ).position ) {
			return 0;
		}
		return bar.offsetHeight;
	}

	function measure() {
		var top = 0;
		var bottom = 0;
		var admin = adminBarHeight();

		document.querySelectorAll( '.axle-front-bar' ).forEach( function ( bar ) {
			if ( bar.hidden || 'none' === window.getComputedStyle( bar ).display ) {
				return;
			}
			if ( bar.classList.contains( 'axle-front-bar--top' ) ) {
				top += bar.offsetHeight;
			} else {
				bottom += bar.offsetHeight;
			}
		} );

		html.style.setProperty( '--axle-admin-bar', admin + 'px' );
		html.style.setProperty( '--axle-front-bar-top', top + 'px' );
		html.style.setProperty( '--axle-front-bar-bottom', bottom + 'px' );
		html.classList.toggle( 'axle-has-front-bar-top', top > 0 );
		html.classList.toggle( 'axle-has-front-bar-bottom', bottom > 0 );

		if ( top > 0 ) {
			// Block themes place sticky headers at this offset.
			html.style.setProperty( '--wp-admin--admin-bar--height', admin + top + 'px' );
		}
	}

	function start() {
		measure();
		if ( 'ResizeObserver' in window ) {
			var observer = new window.ResizeObserver( measure );
			document.querySelectorAll( '.axle-front-bar' ).forEach( function ( bar ) {
				observer.observe( bar );
			} );
		}
		window.addEventListener( 'resize', measure );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	window.axleFront = { measure: measure };
}() );
