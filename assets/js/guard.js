/**
 * formexposed - protection layer in the browser ("Fix it for me").
 *
 * Equips the forms of WPForms, Gravity Forms, Elementor, Fluent Forms,
 * Formidable, comments, registration, login and the WooCommerce checkout (as
 * switched on) with the hidden fields (Contact Form 7 renders them itself; Ninja
 * Forms gets them as extra data of its submission), copies the interaction proof
 * into a form on its first real interaction and watches the page for forms
 * that come later. With the proof of work on, it fetches a challenge from this
 * site (admin-ajax, action formexposed_pow), solves it in small steps and holds
 * a submit back until the answer is in the field. Nothing is loaded from
 * anywhere else. Settings: window.formexposedGuard (Formexposed_Guard::enqueue).
 */
( function ( config ) {
	'use strict';

	if ( ! config ) {
		return;
	}

	var pow = config.pow;
	// State of the proof of work per form, kept outside the form element.
	var powStates = typeof WeakMap === 'function' ? new WeakMap() : null;
	var interactionEvents = [ 'focusin', 'keydown', 'pointerdown', 'touchstart' ];

	/** The interaction proof the server printed or this script set (data-fe on pages cached before 1.10.4). */
	function proofOf( input ) {
		return input.getAttribute( 'data-formexposed-js' ) || input.getAttribute( 'data-fe' ) || '';
	}

	/** Adds a hidden field unless the form already has one with that name. */
	function addHidden( form, name, value ) {
		if ( form.querySelector( 'input[name="' + name + '"]' ) ) {
			return null;
		}
		var input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		form.appendChild( input );
		return input;
	}

	/** The hidden fields and the trap field of the protection layer. */
	function equip( form ) {
		addHidden( form, 'formexposed_time', config.time );
		addHidden( form, 'formexposed_token', config.token );
		var proof = addHidden( form, 'formexposed_js', '' );
		if ( proof ) {
			proof.setAttribute( 'data-formexposed-js', config.fe );
		}
		if ( pow ) {
			addHidden( form, 'formexposed_pow', '' );
		}
		if ( form.querySelector( 'input[name="formexposed_website"]' ) ) {
			return;
		}
		var wrap = document.createElement( 'span' );
		var label = document.createElement( 'label' );
		var trap = document.createElement( 'input' );
		wrap.className = 'formexposed-hp';
		wrap.setAttribute( 'aria-hidden', 'true' );
		wrap.style.cssText = 'position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important';
		trap.type = 'text';
		trap.name = 'formexposed_website';
		trap.value = '';
		trap.tabIndex = -1;
		trap.setAttribute( 'autocomplete', 'off' );
		label.appendChild( document.createTextNode( config.label ) );
		label.appendChild( trap );
		wrap.appendChild( label );
		form.appendChild( wrap );
	}

	/*
	 * Proof of work: SHA-256 in plain JavaScript, because crypto.subtle exists only on https pages.
	 * ROUND_CONSTANTS are the 64 constants of the SHA-256 standard (FIPS 180-4, section 4.2.2).
	 */
	var ROUND_CONSTANTS = new Int32Array( [
		0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
		0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
		0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
		0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
		0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
		0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
		0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
		0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
	] );
	var schedule = new Int32Array( 64 );

	/** Rotates a 32-bit word right by n bits. */
	function rotr( word, n ) {
		return ( word >>> n ) | ( word << ( 32 - n ) );
	}

	/** SHA-256 of an ASCII string (salt and number), as eight signed 32-bit words. */
	function sha256( text ) {
		var length = text.length;
		var blocks = ( ( length + 8 ) >> 6 ) + 1;
		var message = new Int32Array( blocks << 4 );
		var hash = [ 0x6a09e667, 0xbb67ae85 | 0, 0x3c6ef372, 0xa54ff53a | 0, 0x510e527f, 0x9b05688c | 0, 0x1f83d9ab, 0x5be0cd19 ];
		var i, block, a, b, c, d, e, f, g, h, s0, s1, temp1, temp2;

		for ( i = 0; i < length; i++ ) {
			message[ i >> 2 ] |= text.charCodeAt( i ) << ( 24 - ( ( i & 3 ) << 3 ) );
		}
		message[ length >> 2 ] |= 0x80 << ( 24 - ( ( length & 3 ) << 3 ) );
		message[ ( blocks << 4 ) - 1 ] = length << 3;

		for ( block = 0; block < blocks << 4; block += 16 ) {
			for ( i = 0; i < 16; i++ ) {
				schedule[ i ] = message[ block + i ];
			}
			for ( i = 16; i < 64; i++ ) {
				s0 = rotr( schedule[ i - 15 ], 7 ) ^ rotr( schedule[ i - 15 ], 18 ) ^ ( schedule[ i - 15 ] >>> 3 );
				s1 = rotr( schedule[ i - 2 ], 17 ) ^ rotr( schedule[ i - 2 ], 19 ) ^ ( schedule[ i - 2 ] >>> 10 );
				schedule[ i ] = ( s0 + schedule[ i - 16 ] + s1 + schedule[ i - 7 ] ) | 0;
			}
			a = hash[ 0 ];
			b = hash[ 1 ];
			c = hash[ 2 ];
			d = hash[ 3 ];
			e = hash[ 4 ];
			f = hash[ 5 ];
			g = hash[ 6 ];
			h = hash[ 7 ];
			for ( i = 0; i < 64; i++ ) {
				s1 = rotr( e, 6 ) ^ rotr( e, 11 ) ^ rotr( e, 25 );
				temp1 = ( h + s1 + ( ( e & f ) ^ ( ~e & g ) ) + ROUND_CONSTANTS[ i ] + schedule[ i ] ) | 0;
				s0 = rotr( a, 2 ) ^ rotr( a, 13 ) ^ rotr( a, 22 );
				temp2 = ( s0 + ( ( a & b ) ^ ( a & c ) ^ ( b & c ) ) ) | 0;
				h = g;
				g = f;
				f = e;
				e = ( d + temp1 ) | 0;
				d = c;
				c = b;
				b = a;
				a = ( temp1 + temp2 ) | 0;
			}
			hash[ 0 ] = ( hash[ 0 ] + a ) | 0;
			hash[ 1 ] = ( hash[ 1 ] + b ) | 0;
			hash[ 2 ] = ( hash[ 2 ] + c ) | 0;
			hash[ 3 ] = ( hash[ 3 ] + d ) | 0;
			hash[ 4 ] = ( hash[ 4 ] + e ) | 0;
			hash[ 5 ] = ( hash[ 5 ] + f ) | 0;
			hash[ 6 ] = ( hash[ 6 ] + g ) | 0;
			hash[ 7 ] = ( hash[ 7 ] + h ) | 0;
		}
		return hash;
	}

	/** Searches the number whose hash is the challenge, in small steps so the page stays responsive. */
	function solve( challenge, done ) {
		var target = [];
		var number = 0;
		var max = challenge.maxnumber | 0;
		var i;
		for ( i = 0; i < 8; i++ ) {
			target[ i ] = parseInt( challenge.challenge.substr( i * 8, 8 ), 16 ) | 0;
		}
		( function step() {
			var until = Math.min( number + ( ( pow.chunk | 0 ) || 2000 ), max + 1 );
			var result;
			for ( ; number < until; number++ ) {
				result = sha256( challenge.salt + number );
				if ( result[ 0 ] === target[ 0 ] && result[ 1 ] === target[ 1 ] && result[ 2 ] === target[ 2 ] && result[ 3 ] === target[ 3 ] &&
					result[ 4 ] === target[ 4 ] && result[ 5 ] === target[ 5 ] && result[ 6 ] === target[ 6 ] && result[ 7 ] === target[ 7 ] ) {
					return done( JSON.stringify( {
						algorithm: challenge.algorithm,
						challenge: challenge.challenge,
						number: number,
						salt: challenge.salt,
						signature: challenge.signature
					} ) );
				}
			}
			if ( number > max ) {
				return done( '' );
			}
			setTimeout( step, 0 );
		} )();
	}

	/** The proof-of-work state of a form: ok, busy, go (resubmit now), expires, failed (time) and the waiting submit. */
	function powState( form ) {
		var fresh = { ok: 0, busy: 0, go: 0, expires: 0, failed: 0, then: null };
		if ( powStates ) {
			if ( ! powStates.has( form ) ) {
				powStates.set( form, fresh );
			}
			return powStates.get( form );
		}
		if ( ! form.formexposedPow ) {
			form.formexposedPow = fresh;
		}
		return form.formexposedPow;
	}

	/** Stores the answer (or the failure) and lets a waiting submit go on. */
	function finish( form, answer, startedAt ) {
		var state = powState( form );
		var field = form.querySelector( 'input[name="formexposed_pow"]' );
		var waiting = state.then;
		state.busy = 0;
		state.then = null;
		if ( answer && field ) {
			field.value = answer;
			state.ok = 1;
			state.expires = startedAt + ( pow.ttl - 60 ) * 1000;
		} else {
			state.ok = 0;
			state.failed = Date.now();
		}
		if ( waiting ) {
			waiting();
		}
	}

	/* Send the form again the way the visitor did - never form.submit(): that skips the AJAX handlers. */
	function resend( form, button ) {
		if ( form.requestSubmit ) {
			form.requestSubmit( button && button.form === form ? button : undefined );
			return;
		}
		if ( button && button.form === form && button.click ) {
			button.click();
			return;
		}
		var submit = document.createElement( 'input' );
		submit.type = 'submit';
		submit.style.display = 'none';
		form.appendChild( submit );
		submit.click();
		form.removeChild( submit );
	}

	/** Fetches a challenge from this site and solves it. */
	function startPow( form ) {
		var state = powState( form );
		var startedAt = Date.now();
		if ( state.busy ) {
			return;
		}
		state.busy = 1;
		var request = new XMLHttpRequest();
		request.open( 'POST', pow.url, true );
		request.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		request.onload = function () {
			var challenge = null;
			try {
				challenge = JSON.parse( request.responseText );
			} catch ( ignore ) {
				challenge = null;
			}
			if ( ! challenge || ! challenge.challenge || ! challenge.salt ) {
				return finish( form, '' );
			}
			solve( challenge, function ( answer ) {
				finish( form, answer, startedAt );
			} );
		};
		request.onerror = function () {
			finish( form, '' );
		};
		request.timeout = 15000; // never keep a form waiting forever
		request.ontimeout = function () {
			finish( form, '' );
		};
		request.send( 'action=' + encodeURIComponent( pow.action ) );
	}

	/** Equips a form once and listens for the first real interaction. */
	function arm( form ) {
		if ( form.getAttribute( 'data-formexposed-armed' ) ) {
			return;
		}
		form.setAttribute( 'data-formexposed-armed', '1' );
		if ( ! form.classList.contains( 'wpcf7-form' ) ) {
			equip( form );
		}
		var proven = false;
		function prove() {
			if ( proven ) {
				return;
			}
			proven = true;
			var proof = form.querySelector( 'input[name="formexposed_js"]' );
			if ( proof ) {
				proof.value = proofOf( proof );
			}
		}
		interactionEvents.forEach( function ( type ) {
			form.addEventListener( type, prove, { once: true, passive: true } );
		} );
		if ( pow ) {
			/*
			 * Prepare the answer when a field gets focus or input - not on a touch while scrolling past the form,
			 * and after a failed request not again for 20 s (the submit itself always asks).
			 */
			[ 'focusin', 'input' ].forEach( function ( type ) {
				form.addEventListener( type, function () {
					var state = powState( form );
					if ( ! state.ok && ! state.busy && Date.now() - state.failed > 20000 ) {
						startPow( form );
					}
				}, { passive: true } );
			} );
		}
	}

	function scan() {
		document.querySelectorAll( 'form.wpcf7-form,' + config.sel ).forEach( arm );
	}
	scan();

	/* Ninja Forms (since 1.5.0) sends its own field models, not the HTML form: our values travel as "extra" data. */
	if ( config.nf && window.jQuery ) {
		var ninjaForms = [];
		window.jQuery( document ).on( 'nfFormReady', function ( event, view ) {
			try {
				var id = view && view.model && view.model.get( 'id' );
				var radio = window.Backbone && window.Backbone.Radio;
				var channel = radio && id ? radio.channel( 'form-' + id ) : null;
				if ( ! channel ) {
					return;
				}
				channel.request( 'add:extra', 'formexposed_time', config.time );
				channel.request( 'add:extra', 'formexposed_token', config.token );
				channel.request( 'add:extra', 'formexposed_website', '' );
				ninjaForms.push( { id: String( id ), channel: channel, done: false } );
			} catch ( ignore ) {
				// A Ninja Forms version without this API: the form is checked without the interaction proof.
			}
		} );
		/* The view's own element is replaced while Ninja Forms renders - listen on the page and find the form by its container. */
		interactionEvents.forEach( function ( type ) {
			document.addEventListener( type, function ( event ) {
				var container = event.target && event.target.closest ? event.target.closest( '.nf-form-cont' ) : null;
				var i;
				if ( ! container ) {
					return;
				}
				for ( i = 0; i < ninjaForms.length; i++ ) {
					if ( ! ninjaForms[ i ].done && container.id === 'nf-form-' + ninjaForms[ i ].id + '-cont' ) {
						ninjaForms[ i ].done = true;
						ninjaForms[ i ].channel.request( 'add:extra', 'formexposed_js', config.fe );
					}
				}
			}, { passive: true, capture: true } );
		} );
	}

	if ( pow ) {
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			var state;
			var button;
			if ( ! form || ! form.getAttribute || ! form.getAttribute( 'data-formexposed-armed' ) ) {
				return;
			}
			/* Login, registration and checkout are not held back for the proof of work (not checked there). */
			if ( config.nopow && form.matches && form.matches( config.nopow ) ) {
				return;
			}
			state = powState( form );
			if ( state.go ) {
				state.go = 0;
				return;
			}
			if ( state.ok && Date.now() < state.expires ) {
				state.ok = 0;
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			button = event.submitter;
			form.setAttribute( 'aria-busy', 'true' );
			state.then = function () {
				state.go = 1;
				state.ok = 0;
				form.removeAttribute( 'aria-busy' );
				resend( form, button );
			};
			if ( ! state.busy ) {
				startPow( form );
			}
		}, true );
	}

	if ( window.MutationObserver ) {
		var rescanTimer = null;
		new MutationObserver( function () {
			if ( rescanTimer ) {
				return;
			}
			rescanTimer = setTimeout( function () {
				rescanTimer = null;
				scan();
			}, 150 );
		} ).observe( document.documentElement, { childList: true, subtree: true } );
	}
}( window.formexposedGuard ) );
