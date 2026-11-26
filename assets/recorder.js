/**
 * formexposed - visual journey recorder for site administrators (Formexposed_Journey::assets,
 * window.formexposedRecorder).
 *
 * Opened by formexposed.com in a new window with a one-time code in the address fragment. Records up to
 * five clicks on links or safe "open" buttons as CSS selectors and, when the administrator selects the form,
 * hands the selectors back to the formexposed.com window with postMessage. Only selectors and public paths;
 * never input values. Typing and submitting are blocked while recording.
 */
( function () {
	'use strict';

	var settings = window.formexposedRecorder;
	if ( ! settings || window.formexposedRecorderActive ) {
		return;
	}
	window.formexposedRecorderActive = true;

	var text = settings.text || {};
	var STORAGE_KEY = 'formexposed_recorder';
	var MAX_STEPS = 5;
	var LIFETIME_MS = 15 * 60 * 1000;
	var DANGEROUS = /(logout|log-out|signout|delete|remove|unsubscribe|checkout|add-to-cart|payment|purchase)/i;

	var fragment = new URLSearchParams( location.hash.slice( 1 ) );
	var nonce = fragment.get( 'fe_nonce' );
	var data = null;
	try {
		data = JSON.parse( sessionStorage.getItem( STORAGE_KEY ) || 'null' );
	} catch ( ignore ) {
		data = null;
	}

	// A new recording starts with the one-time code in the fragment; it is removed from the address at once.
	if ( nonce && /^[a-f0-9]{48}$/.test( nonce ) ) {
		data = {
			nonce: nonce,
			entry_url: location.origin + location.pathname,
			steps: [],
			expires: Date.now() + LIFETIME_MS
		};
		sessionStorage.setItem( STORAGE_KEY, JSON.stringify( data ) );
		history.replaceState( null, '', location.pathname + location.search );
	}
	if ( ! data || ! data.nonce || data.expires < Date.now() || ! window.opener ) {
		sessionStorage.removeItem( STORAGE_KEY );
		return;
	}

	var expiryTimer = setTimeout( stop, Math.max( 0, data.expires - Date.now() ) );
	var selecting = false;

	// The toolbar lives in a shadow root so the page's styles cannot change it.
	var host = document.createElement( 'div' );
	host.id = 'formexposed-recorder-toolbar';
	host.style.cssText = 'position:fixed;z-index:2147483647;bottom:12px;left:12px;right:12px';
	document.body.append( host );
	var shadow = host.attachShadow( { mode: 'open' } );
	var box = document.createElement( 'div' );
	box.style.cssText = 'background:#111827;color:white;padding:18px;border:2px solid #fb923c;border-radius:12px;font:16px/1.5 sans-serif';
	shadow.append( box );

	var title = document.createElement( 'strong' );
	title.textContent = text.title;
	var info = document.createElement( 'p' );
	info.textContent = text.intro;
	box.append( title, info );

	var count = document.createElement( 'span' );
	var pickButton = document.createElement( 'button' );
	var stopButton = document.createElement( 'button' );
	pickButton.textContent = text.pick;
	stopButton.textContent = text.stop;
	[ pickButton, stopButton ].forEach( function ( button ) {
		button.type = 'button';
		button.style.cssText = 'padding:8px;margin:8px;font:inherit;cursor:pointer';
		box.append( button );
	} );
	box.append( count );

	function showCount() {
		count.textContent = text.steps + ' ' + data.steps.length;
	}
	showCount();

	pickButton.onclick = function () {
		selecting = true;
		pickButton.style.outline = '3px solid #fb923c';
	};

	function stop() {
		clearTimeout( expiryTimer );
		sessionStorage.removeItem( STORAGE_KEY );
		host.remove();
		document.removeEventListener( 'click', onClick, true );
		document.removeEventListener( 'submit', block, true );
		document.removeEventListener( 'beforeinput', block, true );
		window.formexposedRecorderActive = false;
		var url = new URL( location.href );
		url.searchParams.delete( 'formexposed_record' );
		history.replaceState( null, '', url.pathname + url.search );
	}
	stopButton.onclick = stop;

	function block( event ) {
		event.preventDefault();
		event.stopImmediatePropagation();
	}
	document.addEventListener( 'submit', block, true );
	document.addEventListener( 'beforeinput', block, true );

	/** A selector that finds exactly this element (at most 200 characters), or ''. */
	function uniqueSelector( element ) {
		if ( element.id ) {
			var byId = '#' + CSS.escape( element.id );
			if ( document.querySelectorAll( byId ).length === 1 && byId.length <= 200 ) {
				return byId;
			}
		}
		var attributes = [ 'data-testid', 'aria-controls' ];
		for ( var i = 0; i < attributes.length; i++ ) {
			var value = element.getAttribute( attributes[ i ] );
			if ( value && value.length < 80 ) {
				var byAttribute = element.tagName.toLowerCase() + '[' + attributes[ i ] + '="' + CSS.escape( value ) + '"]';
				if ( byAttribute.length <= 200 && document.querySelectorAll( byAttribute ).length === 1 ) {
					return byAttribute;
				}
			}
		}
		var parts = [];
		var current = element;
		while ( current && current.nodeType === 1 && current !== document.body ) {
			var tag = current.tagName.toLowerCase();
			var sameTag = Array.from( current.parentElement.children ).filter( function ( sibling ) {
				return sibling.tagName === current.tagName;
			} );
			parts.unshift( tag + ':nth-of-type(' + ( sameTag.indexOf( current ) + 1 ) + ')' );
			var path = 'body > ' + parts.join( ' > ' );
			if ( path.length <= 200 && document.querySelectorAll( path ).length === 1 ) {
				return path;
			}
			current = current.parentElement;
		}
		return '';
	}

	/** The form was selected: hand everything back to the formexposed.com window and stop. */
	function selectForm( event ) {
		block( event );
		var form = event.target.closest( 'form' );
		if ( ! form ) {
			return;
		}
		var formSelector = uniqueSelector( form );
		if ( ! formSelector ) {
			info.textContent = text.unclear;
			return;
		}
		window.opener.postMessage( {
			type: 'formexposed-recording',
			nonce: data.nonce,
			page_url: location.origin + location.pathname,
			entry_url: data.entry_url,
			form_selector: formSelector,
			client_selector: form.id ? '#' + CSS.escape( form.id ) : 'form:nth-of-type(' + ( Array.from( document.forms ).indexOf( form ) + 1 ) + ')',
			steps: data.steps
		}, settings.origin );
		info.textContent = text.done;
		stop();
	}

	function onClick( event ) {
		if ( event.composedPath().includes( host ) ) {
			return;
		}
		if ( selecting ) {
			selectForm( event );
			return;
		}
		var element = event.target.closest( 'a,button,[role=button]' );
		if ( ! element ) {
			block( event );
			info.textContent = text.notStored;
			return;
		}
		var url = element.tagName === 'A' ? new URL( element.href, location.href ) : null;
		var unsafe = element.closest( 'form' ) ||
			element.matches( 'button:not([type]),button[type=submit]' ) ||
			( url && ( url.origin !== location.origin || DANGEROUS.test( url.pathname + url.search ) ) ) ||
			DANGEROUS.test( element.id + ' ' + ( element.getAttribute( 'data-action' ) || '' ) );
		if ( unsafe ) {
			block( event );
			info.textContent = text.notStored;
			return;
		}
		if ( data.steps.length >= MAX_STEPS ) {
			block( event );
			info.textContent = text.maxSteps;
			return;
		}
		var stepSelector = uniqueSelector( element );
		if ( ! stepSelector ) {
			block( event );
			info.textContent = text.unclear;
			return;
		}
		data.steps.push( stepSelector );
		sessionStorage.setItem( STORAGE_KEY, JSON.stringify( data ) );
		showCount();
		// The next page of the journey keeps recording.
		if ( url && url.protocol === 'https:' && url.pathname !== location.pathname ) {
			url.searchParams.set( 'formexposed_record', '1' );
			element.href = url.href;
		}
	}
	document.addEventListener( 'click', onClick, true );
}() );
