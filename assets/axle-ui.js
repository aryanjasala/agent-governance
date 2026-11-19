/**
 * Axle shared screen helpers. Loaded by AxleUi::enqueue().
 * window.axleUi.relativeTime( date ): "just now", "5 minutes ago", "in 2 hours"
 *   (lower case, so it reads well mid-sentence). date: a Date, a Unix timestamp
 *   in seconds or milliseconds, or an ISO string; a string without a zone is read as UTC.
 * window.axleUi.speak( text ): announces a live result to screen readers.
 * Generated from _dev/repo-template. Do not edit it in a plugin.
 */
( function () {
	if ( window.axleUi ) {
		return;
	}

	var l10n = window.axleUiL10n || { justNow: 'just now' };
	var units = [
		[ 'year', 31536000 ],
		[ 'month', 2592000 ],
		[ 'week', 604800 ],
		[ 'day', 86400 ],
		[ 'hour', 3600 ],
		[ 'minute', 60 ],
	];

	function toTime( date ) {
		if ( date instanceof Date ) {
			return date.getTime();
		}
		if ( 'number' === typeof date ) {
			return date < 1e12 ? date * 1000 : date;
		}
		var text = String( date || '' );
		if ( /^\d+$/.test( text ) ) {
			return toTime( Number( text ) );
		}
		if ( text && ! /(Z|[+-]\d\d:?\d\d)$/.test( text ) ) {
			text = text.replace( ' ', 'T' ) + 'Z';
		}
		return Date.parse( text );
	}

	function relativeTime( date ) {
		var time = toTime( date );
		if ( isNaN( time ) ) {
			return '';
		}
		var diff = ( time - Date.now() ) / 1000;
		var abs = Math.abs( diff );
		if ( abs < 60 ) {
			return l10n.justNow;
		}
		for ( var i = 0; i < units.length; i++ ) {
			if ( abs >= units[ i ][ 1 ] ) {
				var value = Math.round( diff / units[ i ][ 1 ] );
				try {
					return new Intl.RelativeTimeFormat( document.documentElement.lang || undefined, { numeric: 'always' } ).format( value, units[ i ][ 0 ] );
				} catch ( error ) {
					var count = Math.abs( value ) + ' ' + units[ i ][ 0 ] + ( 1 === Math.abs( value ) ? '' : 's' );
					return value < 0 ? count + ' ago' : 'in ' + count;
				}
			}
		}
		return l10n.justNow;
	}

	function speak( text ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( String( text ) );
		}
	}

	window.axleUi = { relativeTime: relativeTime, speak: speak };
}() );
