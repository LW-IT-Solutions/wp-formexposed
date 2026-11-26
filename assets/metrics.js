/**
 * formexposed - optional form counters (Formexposed_Form_Metrics::assets, window.formexposedMetrics).
 *
 * Counts per form: seen, started, invalid, attempted and the Contact Form 7 results. Never reads input
 * values or field names; an optional field event names only the position of the field (1-30) and the
 * kind of validation error. Sends to this site's REST route formexposed/v1/events, at most 50 events
 * per page view, without cookies.
 */
( function () {
	'use strict';

	var settings = window.formexposedMetrics;
	if ( ! settings || ! window.crypto || ! window.crypto.subtle ) {
		return;
	}

	var attached = new WeakSet();
	var usageBySelector = new Map();
	var sentEvents = 0;
	var FIELD_SELECTOR = 'input:not([type=hidden]):not([type=password]):not([type=file]):not([type=submit]):not([type=button]):not([type=reset]),textarea,select';

	/** The kind of a validation error, from the browser's ValidityState. */
	function errorCategory( validity ) {
		if ( validity.valueMissing ) {
			return 'required';
		}
		if ( validity.typeMismatch ) {
			return 'type';
		}
		if ( validity.patternMismatch ) {
			return 'pattern';
		}
		if ( validity.rangeUnderflow || validity.rangeOverflow || validity.stepMismatch ) {
			return 'range';
		}
		if ( validity.tooShort || validity.tooLong ) {
			return 'length';
		}
		if ( validity.badInput ) {
			return 'bad_input';
		}
		return 'custom';
	}

	/** A form's key: SHA-256 of the page path and a structural selector, so no form name leaves the page. */
	function formKey( selector ) {
		var data = new TextEncoder().encode( location.pathname + '|' + selector );
		return window.crypto.subtle.digest( 'SHA-256', data ).then( function ( buffer ) {
			return Array.from( new Uint8Array( buffer ) ).map( function ( byte ) {
				return byte.toString( 16 ).padStart( 2, '0' );
			} ).join( '' );
		} );
	}

	function attach( form, index ) {
		if ( attached.has( form ) || form.querySelector( 'input[type=password]' ) ) {
			return;
		}
		attached.add( form );

		var selector = form.id ? '#' + CSS.escape( form.id ) : 'form:nth-of-type(' + ( index + 1 ) + ')';
		var used = usageBySelector.get( selector ) || {};
		usageBySelector.set( selector, used );
		var fieldsReported = new WeakSet();
		var keyPromise = formKey( selector );

		/** Sends one event once per page view. */
		function emit( event, details ) {
			var token = event + ( details ? ':' + details.position : '' );
			if ( used[ token ] || sentEvents >= 50 ) {
				return;
			}
			used[ token ] = true;
			sentEvents++;
			keyPromise.then( function ( key ) {
				var body = Object.assign( { token: settings.token, key: key, event: event }, details || {} );
				fetch( settings.url, {
					method: 'POST',
					credentials: 'omit',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( body ),
					keepalive: true
				} ).catch( function () {} );
			} );
		}

		if ( window.IntersectionObserver ) {
			var observer = new IntersectionObserver( function ( entries ) {
				var visible = entries.some( function ( entry ) {
					return entry.isIntersecting;
				} );
				if ( visible ) {
					emit( 'seen' );
					observer.disconnect();
				}
			} );
			observer.observe( form );
		}

		form.addEventListener( 'input', function () {
			emit( 'started' );
		}, { once: true } );

		form.addEventListener( 'invalid', function ( event ) {
			emit( 'invalid' );
			if ( ! settings.fields || fieldsReported.has( event.target ) ) {
				return;
			}
			var fields = Array.from( form.querySelectorAll( FIELD_SELECTOR ) ).slice( 0, 30 );
			var position = fields.indexOf( event.target ) + 1;
			if ( position < 1 || ! event.target.validity ) {
				return;
			}
			fieldsReported.add( event.target );
			emit( 'field_invalid', { position: position, category: errorCategory( event.target.validity ) } );
		}, true );

		form.addEventListener( 'submit', function () {
			emit( 'attempted' );
		}, true );

		document.addEventListener( 'wpcf7mailsent', function ( event ) {
			if ( event.target === form || event.target.contains( form ) ) {
				emit( 'accepted_client' );
			}
		} );
		document.addEventListener( 'wpcf7mailfailed', function ( event ) {
			if ( event.target === form || event.target.contains( form ) ) {
				emit( 'mail_failed_client' );
			}
		} );
	}

	function scan() {
		Array.from( document.forms ).slice( 0, 20 ).forEach( attach );
	}

	scan();
	var rescanTimer;
	new MutationObserver( function () {
		clearTimeout( rescanTimer );
		rescanTimer = setTimeout( scan, 300 );
	} ).observe( document.documentElement, { childList: true, subtree: true } );
}() );
