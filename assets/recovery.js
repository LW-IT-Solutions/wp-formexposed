/**
 * formexposed - visitor recovery guidance (Formexposed_Journey::assets, window.formexposedRecovery).
 *
 * After an offline submit or a Contact Form 7 mail failure, shows a note under the form with a way back to
 * the still filled-in form and, if set, an e-mail address. Keeps the existing form; reads no input values,
 * stores nothing, never resends by itself.
 */
( function () {
	'use strict';

	var settings = window.formexposedRecovery;
	if ( ! settings ) {
		return;
	}

	var text = settings.text || {};
	var attached = new WeakSet();
	var states = new WeakMap();
	var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	/** Shows the note for a form: "offline", "failed" or "unknown" (no answer from Contact Form 7). */
	function show( form, kind ) {
		var state = states.get( form );
		if ( ! state ) {
			return;
		}
		clearTimeout( state.timer );
		if ( ! state.box ) {
			state.box = document.createElement( 'div' );
			state.box.setAttribute( 'role', 'status' );
			state.box.setAttribute( 'aria-live', 'polite' );
			state.box.style.cssText = 'padding:16px;margin:12px 0;border:2px solid #b45309;border-radius:8px;background:#fff7ed;color:#431407;font:16px/1.5 sans-serif';
			form.after( state.box );
		}
		state.box.hidden = false;
		state.box.replaceChildren();

		var message = document.createElement( 'p' );
		if ( kind === 'offline' ) {
			message.textContent = text.offline;
		} else if ( kind === 'unknown' ) {
			message.textContent = text.unknown;
		} else {
			message.textContent = text.failed;
		}
		state.box.append( message );

		if ( kind !== 'unknown' ) {
			var kept = document.createElement( 'p' );
			kept.textContent = text.kept;
			state.box.append( kept );
		}

		var back = document.createElement( 'button' );
		back.type = 'button';
		back.textContent = text.back;
		back.onclick = function () {
			var first = form.querySelector( 'input:not([type=hidden]):not([type=password]),textarea,select,button' );
			if ( first ) {
				first.focus();
			}
			form.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		};
		state.box.append( back );

		if ( EMAIL.test( settings.email || '' ) ) {
			var mail = document.createElement( 'a' );
			mail.href = 'mailto:' + settings.email;
			mail.textContent = text.mail;
			mail.style.marginLeft = '12px';
			state.box.append( mail );
		}
	}

	function attach( form ) {
		if ( attached.has( form ) || form.querySelector( 'input[type=password]' ) ) {
			return;
		}
		attached.add( form );
		states.set( form, { box: null, timer: null } );
		form.addEventListener( 'submit', function ( event ) {
			var state = states.get( form );
			if ( navigator.onLine === false ) {
				event.preventDefault();
				event.stopImmediatePropagation();
				show( form, 'offline' );
				return;
			}
			if ( state.box ) {
				state.box.hidden = true;
			}
			// Contact Form 7 has explicit completion events. A timeout is uncertainty, never an invitation to duplicate a lead.
			clearTimeout( state.timer );
			if ( form.classList.contains( 'wpcf7-form' ) ) {
				state.timer = setTimeout( function () {
					show( form, 'unknown' );
					state.box.hidden = false;
				}, 30000 );
			}
		}, true );
	}

	/** Calls fn for the form an event belongs to (the event target or the forms inside it). */
	function formsOf( event, fn ) {
		var target = event.target;
		if ( ! target || ! target.querySelectorAll ) {
			return;
		}
		if ( target.tagName === 'FORM' ) {
			fn( target );
		} else {
			target.querySelectorAll( 'form' ).forEach( fn );
		}
	}

	[ 'wpcf7mailfailed', 'formexposed:submissionfailed' ].forEach( function ( type ) {
		document.addEventListener( type, function ( event ) {
			formsOf( event, function ( form ) {
				attach( form );
				show( form, 'failed' );
				states.get( form ).box.hidden = false;
			} );
		} );
	} );

	[ 'wpcf7mailsent', 'wpcf7spam', 'wpcf7invalid' ].forEach( function ( type ) {
		document.addEventListener( type, function ( event ) {
			formsOf( event, function ( form ) {
				var state = states.get( form );
				if ( state ) {
					clearTimeout( state.timer );
					if ( state.box ) {
						state.box.remove();
					}
					state.box = null;
				}
			} );
		} );
	} );

	function scan() {
		Array.from( document.forms ).slice( 0, 20 ).forEach( attach );
	}

	scan();
	var rescanTimer;
	new MutationObserver( function () {
		clearTimeout( rescanTimer );
		rescanTimer = setTimeout( scan, 300 );
	} ).observe( document.documentElement, { subtree: true, childList: true } );
}() );
