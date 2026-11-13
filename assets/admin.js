/**
 * Tools > Agent Governance: confirmations, the rules table and live announcements.
 *
 * JS hooks (through wp.hooks, read on each use so later scripts can add them):
 *
 * - filter agentGovernance.confirmMessage ( message, action, button ): what the
 *   in-place confirmation says before approve, reject, rollback or pause. Return
 *   an empty string to skip the confirmation.
 * - action agentGovernance.confirmed ( action, button ): the person confirmed.
 * - action agentGovernance.ruleAdded ( row ): a new row was added to the rules table.
 */
( function () {
	'use strict';

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function speak( text ) {
		if ( text && window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( text, 'polite' );
		}
	}

	/** Words the page printed in the visitor's language, on the wrapper. */
	function word( name, fallback ) {
		const wrap = document.querySelector( '.agg-wrap' );

		return ( wrap && wrap.getAttribute( 'data-agg-' + name ) ) || fallback;
	}

	let confirmCount = 0;

	/** Removes an open confirmation and gives the focus back to the button that asked. */
	function closeConfirm( box, restore ) {
		const button = box.agentGovernanceButton;
		box.remove();
		if ( restore && button ) {
			button.removeAttribute( 'aria-expanded' );
			button.focus();
		}
	}

	/**
	 * Asks in place, under the button, what will change and whether it can be
	 * undone. The button that confirms is the original one, so the form's own
	 * submit does the work.
	 */
	function askInPlace( button, message, action ) {
		const form = button.closest( 'form' ) || button.parentNode;
		const open = document.querySelector( '.axle-confirm[data-agg-for]' );
		if ( open ) {
			closeConfirm( open, false );
		}

		const id = 'agg-confirm-' + ( ++confirmCount );
		const box = document.createElement( 'div' );
		const danger = button.classList.contains( 'axle-button--danger' ) || 'rollback' === action;
		box.className = 'axle-confirm' + ( danger ? ' axle-confirm--danger' : '' );
		box.setAttribute( 'role', 'group' );
		box.setAttribute( 'aria-labelledby', id );
		box.setAttribute( 'data-agg-for', action );
		box.tabIndex = -1;
		box.agentGovernanceButton = button;

		const text = document.createElement( 'p' );
		text.id = id;
		text.textContent = message;
		box.appendChild( text );

		const actions = document.createElement( 'div' );
		actions.className = 'axle-actions';

		const yes = document.createElement( 'button' );
		yes.type = 'button';
		yes.className = 'button ' + ( danger ? 'axle-button--danger' : 'button-primary' );
		yes.textContent = button.textContent.trim();
		const no = document.createElement( 'button' );
		no.type = 'button';
		no.className = 'button';
		no.textContent = word( 'cancel', 'Cancel' );
		actions.appendChild( yes );
		actions.appendChild( no );
		box.appendChild( actions );

		yes.addEventListener( 'click', function () {
			const h = hooks();
			if ( h ) {
				h.doAction( 'agentGovernance.confirmed', action, button );
			}
			closeConfirm( box, false );
			if ( form.requestSubmit ) {
				form.requestSubmit( button );
			} else {
				form.submit();
			}
		} );
		no.addEventListener( 'click', function () {
			closeConfirm( box, true );
		} );
		box.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				closeConfirm( box, true );
			}
		} );

		// The pause button sits in the page header: its question goes under the header instead.
		const header = 'pause' === action ? document.querySelector( '.wp-header-end' ) : null;
		if ( header ) {
			header.after( box );
		} else {
			form.appendChild( box );
		}
		button.setAttribute( 'aria-expanded', 'true' );
		box.focus();
		speak( message );
	}

	document.addEventListener( 'click', function ( event ) {
		const button = event.target.closest( '[data-agg-confirm]' );
		if ( ! button ) {
			return;
		}

		const action = button.getAttribute( 'data-agg-action' ) || '';
		let message = button.getAttribute( 'data-agg-confirm' ) || '';
		const h = hooks();
		if ( h ) {
			message = h.applyFilters( 'agentGovernance.confirmMessage', message, action, button );
		}

		if ( ! message ) {
			if ( h ) {
				h.doAction( 'agentGovernance.confirmed', action, button );
			}
			return;
		}

		event.preventDefault();
		askInPlace( button, message, action );
	} );

	// "Try this policy" can take a few seconds on a busy log: show that it is working.
	document.addEventListener( 'submit', function ( event ) {
		const submitter = event.submitter;
		if ( ! submitter || ! submitter.classList.contains( 'agg-try-button' ) ) {
			return;
		}

		const spinner = submitter.parentNode.querySelector( '.agg-try-spinner' );
		if ( spinner ) {
			spinner.classList.add( 'is-active' );
		}
		submitter.setAttribute( 'aria-busy', 'true' );
		speak( word( 'busy', 'Trying the policy on past calls.' ) );
		// After the browser has read the form, so the button's own name is still sent.
		window.setTimeout( function () {
			submitter.disabled = true;
		}, 0 );
	} );

	function toggleSubject( row ) {
		const scope = row.querySelector( 'select[name$="[scope]"]' );
		const subject = row.querySelector( '.agg-subject' );
		if ( scope && subject ) {
			subject.hidden = 'default' === scope.value;
		}
	}

	document.addEventListener( 'change', function ( event ) {
		const row = event.target.closest( '.agg-rule' );
		if ( row && event.target.matches( 'select[name$="[scope]"]' ) ) {
			toggleSubject( row );
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.closest( '.agg-add-rule' ) ) {
			return;
		}

		const body = document.querySelector( '.agg-rules tbody' );
		const template = body && body.querySelector( '.agg-rule--new' );
		if ( ! template ) {
			return;
		}

		const index = body.querySelectorAll( 'tr' ).length;
		const row = template.cloneNode( true );
		row.setAttribute( 'data-index', String( index ) );
		row.querySelectorAll( '[name]' ).forEach( function ( field ) {
			field.name = field.name.replace( /^rules\[\d+\]/, 'rules[' + index + ']' );
			if ( 'checkbox' === field.type ) {
				field.checked = false;
			} else if ( 'INPUT' === field.tagName ) {
				field.value = '';
			}
		} );
		body.appendChild( row );
		toggleSubject( row );

		const first = row.querySelector( 'input[name$="[pattern]"]' );
		if ( first ) {
			first.focus();
		}

		const h = hooks();
		if ( h ) {
			h.doAction( 'agentGovernance.ruleAdded', row );
		}
	} );

	document.querySelectorAll( '.agg-rule' ).forEach( toggleSubject );

	// Announce what happened after a form, and the result of a policy try.
	document.querySelectorAll( '[data-agg-speak]' ).forEach( function ( node ) {
		speak( node.getAttribute( 'data-agg-speak' ) );
	} );

	// Waiting requests in the browser tab title, on the queue.
	const wrap = document.querySelector( '.agg-wrap[data-agg-pending]' );
	const pending = wrap ? parseInt( wrap.getAttribute( 'data-agg-pending' ), 10 ) : 0;
	if ( pending > 0 && 'requests' === wrap.getAttribute( 'data-agg-tab' ) ) {
		document.title = '(' + pending + ') ' + document.title;
	}
}() );
