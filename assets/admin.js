/**
 * Tools > Agent Governance: confirmations and the rules table.
 *
 * JS hooks (through wp.hooks, read on each use so later scripts can add them):
 *
 * - filter agentGovernance.confirmMessage ( message, action, button ): the question
 *   asked before approve, reject, rollback or pause. Return an empty string to skip it.
 * - action agentGovernance.confirmed ( action, button ): the person said yes.
 * - action agentGovernance.ruleAdded ( row ): a new row was added to the rules table.
 */
( function () {
	'use strict';

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
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

		// eslint-disable-next-line no-alert
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
			return;
		}

		if ( h ) {
			h.doAction( 'agentGovernance.confirmed', action, button );
		}
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
}() );
