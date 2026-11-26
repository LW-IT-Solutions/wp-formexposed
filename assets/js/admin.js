/**
 * formexposed - settings page (Settings -> formexposed).
 *
 * While the page shows a running check (#formexposed-wait), asks admin-ajax (action
 * formexposed_status) every 5 s and reloads the page once the result is in -
 * at most 60 times. Links with data-formexposed-confirm ask in a small dialog
 * before they delete or switch something off (data-formexposed-ok names the button). The values come from Formexposed::admin_assets()
 * (window.formexposedAdmin).
 */
( function ( config ) {
	'use strict';

	var texts = ( config && config.text ) || {};

	/**
	 * A modal confirmation: role dialog with title and text, the focus stays inside,
	 * Enter confirms, Escape cancels, the focus returns to the link that opened it.
	 */
	function confirmDialog( message, okLabel, onConfirm ) {
		var opener = document.activeElement;
		var backdrop = document.createElement( 'div' );
		backdrop.className = 'formexposed-dialog-backdrop';

		var dialog = document.createElement( 'div' );
		dialog.className = 'formexposed-dialog';
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', 'formexposed-dialog-title' );
		dialog.setAttribute( 'aria-describedby', 'formexposed-dialog-text' );

		var title = document.createElement( 'h2' );
		title.id = 'formexposed-dialog-title';
		var icon = document.createElement( 'span' );
		icon.className = 'dashicons dashicons-warning';
		icon.setAttribute( 'aria-hidden', 'true' );
		title.append( icon, document.createTextNode( texts.title || 'Please confirm' ) );

		var text = document.createElement( 'p' );
		text.id = 'formexposed-dialog-text';
		text.textContent = message;

		var actions = document.createElement( 'p' );
		actions.className = 'fx-dialog-actions';
		var cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'button';
		cancel.textContent = texts.cancel || 'Cancel';
		var ok = document.createElement( 'button' );
		ok.type = 'button';
		ok.className = 'button button-primary is-destructive';
		ok.textContent = okLabel || texts.confirm || 'Delete';
		actions.append( cancel, ok );
		dialog.append( title, text, actions );
		backdrop.append( dialog );
		document.body.append( backdrop );
		cancel.focus();

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
			} else if ( event.key === 'Enter' ) {
				event.preventDefault();
				close( document.activeElement !== cancel );
			} else if ( event.key === 'Tab' ) {
				// Keep the focus inside the dialog.
				var items = [ cancel, ok ];
				var index = items.indexOf( document.activeElement );
				event.preventDefault();
				items[ ( index + ( event.shiftKey ? -1 : 1 ) + items.length ) % items.length ].focus();
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
		confirmDialog( link.getAttribute( 'data-formexposed-confirm' ), link.getAttribute( 'data-formexposed-ok' ), function () {
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
