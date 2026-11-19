/**
 * Axle "ask at the wall": any REST error whose data carries `axle_wall`
 * ({ plugin, capability, message, action_label, action_url | request_route, request_data })
 * becomes a notice with the action, in the block editor and in wp-admin.
 * Loaded by AxleUi::enqueue_wall(). Every Axle plugin shares the one middleware.
 * Generated from _dev/repo-template. Do not edit it in a plugin.
 */
( function ( wp ) {
	if ( ! wp || ! wp.apiFetch || window.axleWallReady ) {
		return;
	}
	window.axleWallReady = true;

	var l10n = window.axleWallL10n || { sent: 'Request sent.', failed: 'The request could not be sent. Try again.' };

	function inEditor() {
		return Boolean( document.querySelector( '.block-editor, .edit-post-layout, .edit-site, .editor-editor-interface' ) );
	}

	function notices() {
		return wp.data && wp.data.dispatch && wp.data.dispatch( 'core/notices' );
	}

	function speak( text ) {
		if ( wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( String( text ) );
		}
	}

	function adminNotice( text, tone, action ) {
		var wrap = document.querySelector( '#wpbody-content .wrap' ) || document.querySelector( '#wpbody-content' );
		if ( ! wrap ) {
			return;
		}
		var box = document.createElement( 'div' );
		box.className = 'notice notice-' + tone + ' axle-wall-notice';
		var p = document.createElement( 'p' );
		p.appendChild( document.createTextNode( text + ' ' ) );
		if ( action ) {
			p.appendChild( action );
		}
		box.appendChild( p );
		var heading = wrap.querySelector( 'h1' );
		if ( heading && heading.parentNode === wrap ) {
			heading.insertAdjacentElement( 'afterend', box );
		} else {
			wrap.insertBefore( box, wrap.firstChild );
		}
		speak( text );
	}

	function done( text, ok ) {
		if ( inEditor() && notices() ) {
			notices()[ ok ? 'createSuccessNotice' : 'createErrorNotice' ]( text, { type: 'snackbar' } );
		} else {
			adminNotice( text, ok ? 'success' : 'error' );
		}
		speak( text );
	}

	function request( wall ) {
		return wp.apiFetch( { path: '/' + wall.request_route, method: 'POST', data: wall.request_data || {} } ).then(
			function ( response ) {
				done( ( response && response.message ) || l10n.sent, true );
			},
			function ( error ) {
				done( ( error && error.message ) || l10n.failed, false );
			}
		);
	}

	function show( wall ) {
		var label = wall.action_label;
		var hasUrl = label && wall.action_url;
		var hasRoute = label && wall.request_route;

		if ( inEditor() && notices() ) {
			var actions = [];
			if ( hasUrl ) {
				actions.push( { label: label, url: wall.action_url } );
			} else if ( hasRoute ) {
				actions.push( { label: label, onClick: function () { request( wall ); } } );
			}
			notices().createWarningNotice( wall.message, { id: 'axle-wall-' + ( wall.plugin || 'axle' ), actions: actions, isDismissible: true } );
			speak( wall.message );
			return;
		}

		var action = null;
		if ( hasUrl ) {
			action = document.createElement( 'a' );
			action.className = 'button button-primary';
			action.href = wall.action_url;
			action.textContent = label;
		} else if ( hasRoute ) {
			action = document.createElement( 'button' );
			action.type = 'button';
			action.className = 'button button-primary';
			action.textContent = label;
			action.addEventListener( 'click', function () {
				action.disabled = true;
				request( wall ).then( function () {
					action.disabled = false;
				} );
			} );
		}
		adminNotice( wall.message, 'warning', action );
	}

	wp.apiFetch.use( function ( options, next ) {
		return next( options ).catch( function ( error ) {
			var wall = error && error.data && error.data.axle_wall;
			if ( wall && wall.message ) {
				show( wall );
			}
			throw error;
		} );
	} );

	window.axleWall = { show: show };
}( window.wp ) );
