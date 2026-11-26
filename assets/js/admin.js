/**
 * formexposed - settings page (Settings -> formexposed).
 *
 * While the page shows a running check (#formexposed-wait), asks admin-ajax (action
 * formexposed_status) every 5 s and reloads the page once the result is in -
 * at most 60 times. Links with data-formexposed-confirm ask in a small dialog
 * before they delete. The values come from Formexposed::admin_assets()
 * (window.formexposedAdmin).
 */
( function ( config ) {
	'use strict';

	var texts = ( config && config.text ) || {};

	/** A modal confirmation (role dialog, focus kept inside, Enter confirms, Escape cancels). */
	function confirmDialog( message, onConfirm ) {
		var opener = document.activeElement;
		var backdrop = document.createElement( 'div' );
		backdrop.className = 'formexposed-dialog-backdrop';
		backdrop.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:16px';

		var dialog = document.createElement( 'div' );
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', 'formexposed-dialog-text' );
		dialog.style.cssText = 'background:#fff;color:#1d2327;max-width:420px;width:100%;padding:20px 24px;border-radius:4px;box-shadow:0 8px 32px rgba(0,0,0,.3)';

		var text = document.createElement( 'p' );
		text.id = 'formexposed-dialog-text';
		text.style.cssText = 'margin:0 0 16px;font-size:14px';
		text.textContent = message;

		var actions = document.createElement( 'p' );
		actions.style.cssText = 'margin:0;display:flex;gap:8px;justify-content:flex-end';
		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'button';
		cancel.textContent = texts.cancel || 'Cancel';
		var ok = document.createElement( 'button' );
		ok.type = 'button';
		ok.className = 'button button-primary';
		ok.textContent = texts.confirm || 'Delete';
		actions.append( cancel, ok );
		dialog.append( text, actions );
		backdrop.append( dialog );
		document.body.append( backdrop );
		ok.focus();

		function close( confirmed ) {
			document.removeEventListener( 'keydown', onKey, true );
			backdrop.remove();
			if ( opener && opener.focus ) {
				opener.focus();
			}
			if ( confirmed ) {
				onConfirm();
			}
		}
		function onKey( event ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				close( false );
			} else if ( event.key === 'Enter' && document.activeElement !== cancel ) {
				event.preventDefault();
				close( true );
			} else if ( event.key === 'Tab' ) {
				// Keep the focus on the two buttons.
				event.preventDefault();
				( document.activeElement === ok ? cancel : ok ).focus();
			}
		}
		document.addEventListener( 'keydown', onKey, true );
		cancel.addEventListener( 'click', function () {
			close( false );
		} );
		ok.addEventListener( 'click', function () {
			close( true );
		} );
		backdrop.addEventListener( 'click', function ( event ) {
			if ( event.target === backdrop ) {
				close( false );
			}
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest ? event.target.closest( 'a[data-formexposed-confirm]' ) : null;
		if ( ! link || ! link.href ) {
			return;
		}
		event.preventDefault();
		confirmDialog( link.getAttribute( 'data-formexposed-confirm' ), function () {
			window.location.href = link.href;
		} );
	} );

	if ( ! config || ! document.getElementById( 'formexposed-wait' ) ) {
		return; // no check running on this page
	}
	var attempts = 0;
	function tick() {
		if ( attempts++ > 60 ) {
			return;
		}
		var body = new FormData();
		body.append( 'action', 'formexposed_status' );
		body.append( 'nonce', config.nonce );
		fetch( config.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				var status = json && json.data ? json.data.status : '';
				if ( status === 'queued' || status === 'running' ) {
					setTimeout( tick, 5000 );
				} else {
					window.location.reload();
				}
			} )
			.catch( function () {
				setTimeout( tick, 8000 );
			} );
	}
	setTimeout( tick, 5000 );
}( window.formexposedAdmin ) );
