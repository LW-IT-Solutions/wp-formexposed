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

	/* ------------------------------------------------------------------ 1.12.0 */

	var still = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var numberFormat = window.Intl && Intl.NumberFormat ? new Intl.NumberFormat( document.documentElement.lang || undefined ) : null;
	function fill( text, a, b ) {
		return String( text || '' ).replace( '%1$d', a ).replace( '%2$d', b );
	}
	function whenSeen( node, run ) {
		if ( ! ( 'IntersectionObserver' in window ) ) {
			run();
			return;
		}
		var seen = new IntersectionObserver( function ( entries ) {
			if ( entries[ 0 ].isIntersecting ) {
				seen.disconnect();
				run();
			}
		}, { threshold: 0.2 } );
		seen.observe( node );
	}

	// Counters count up and the risk ring draws itself once (the final values are in the page).
	if ( ! still && window.requestAnimationFrame ) {
		Array.prototype.forEach.call( document.querySelectorAll( '.formexposed-wrap [data-fx-count]' ), function ( node ) {
			var target = parseInt( node.getAttribute( 'data-fx-count' ), 10 );
			var final = node.textContent;
			if ( ! target || target < 10 ) {
				return;
			}
			node.textContent = numberFormat ? numberFormat.format( 0 ) : '0';
			whenSeen( node, function () {
				var start = null;
				function step( now ) {
					start = start || now;
					var p = Math.min( 1, ( now - start ) / 800 );
					var v = Math.round( target * ( 1 - Math.pow( 1 - p, 3 ) ) );
					node.textContent = p < 1 ? ( numberFormat ? numberFormat.format( v ) : String( v ) ) : final;
					if ( p < 1 ) {
						window.requestAnimationFrame( step );
					}
				}
				window.requestAnimationFrame( step );
			} );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.formexposed-wrap [data-fx-ring], .formexposed-wrap .fx-health' ), function ( ring ) {
			ring.classList.add( 'is-waiting' );
			whenSeen( ring, function () {
				ring.classList.remove( 'is-waiting' );
				ring.classList.add( 'is-drawing' );
			} );
		} );
	}

	// Chart of the last 14 days: a tip names the reasons of a day; the reason list switches reasons on and off.
	var chart = document.querySelector( '.formexposed-wrap [data-fx-chart]' );
	if ( chart ) {
		var bars = chart.querySelectorAll( '.fx-bar' );
		var tip = document.createElement( 'div' );
		tip.className = 'fx-tip';
		tip.setAttribute( 'aria-hidden', 'true' );
		tip.hidden = true;
		chart.style.position = 'relative';
		chart.appendChild( tip );
		var off = {};
		function showTip( bar ) {
			tip.textContent = '';
			var head = document.createElement( 'strong' );
			head.textContent = bar.getAttribute( 'aria-label' );
			tip.appendChild( head );
			Array.prototype.forEach.call( bar.querySelectorAll( '.fx-seg' ), function ( seg ) {
				if ( seg.hidden ) {
					return;
				}
				var line = document.createElement( 'span' );
				var dot = document.createElement( 'i' );
				dot.className = 'fx-dot ' + seg.className.replace( 'fx-seg', '' ).trim();
				line.appendChild( dot );
				line.appendChild( document.createTextNode( seg.getAttribute( 'data-fx-label' ) + ': ' + seg.getAttribute( 'data-fx-n' ) ) );
				tip.appendChild( line );
			} );
			tip.hidden = false;
			var box = chart.getBoundingClientRect();
			var r = bar.getBoundingClientRect();
			var left = r.left - box.left + r.width / 2 - tip.offsetWidth / 2;
			tip.style.left = Math.max( 0, Math.min( box.width - tip.offsetWidth, left ) ) + 'px';
			tip.style.bottom = ( box.bottom - r.top + 8 ) + 'px';
			bar.classList.add( 'is-active' );
		}
		function hideTip( bar ) {
			tip.hidden = true;
			bar.classList.remove( 'is-active' );
		}
		Array.prototype.forEach.call( bars, function ( bar ) {
			bar.removeAttribute( 'title' ); // the tip replaces the browser tooltip
			bar.addEventListener( 'mouseenter', function () {
				showTip( bar );
			} );
			bar.addEventListener( 'focus', function () {
				showTip( bar );
			} );
			bar.addEventListener( 'mouseleave', function () {
				hideTip( bar );
			} );
			bar.addEventListener( 'blur', function () {
				hideTip( bar );
			} );
		} );
		chart.addEventListener( 'keydown', function ( event ) {
			var list = Array.prototype.slice.call( bars );
			var at = list.indexOf( document.activeElement );
			if ( at < 0 || ( 'ArrowLeft' !== event.key && 'ArrowRight' !== event.key ) ) {
				return;
			}
			event.preventDefault();
			list[ Math.max( 0, Math.min( list.length - 1, at + ( 'ArrowRight' === event.key ? 1 : -1 ) ) ) ].focus();
		} );
		function redraw() {
			var sums = Array.prototype.map.call( bars, function ( bar ) {
				var sum = 0;
				Array.prototype.forEach.call( bar.querySelectorAll( '.fx-seg' ), function ( seg ) {
					seg.hidden = !! off[ seg.getAttribute( 'data-fx-reason' ) ];
					sum += seg.hidden ? 0 : parseInt( seg.getAttribute( 'data-fx-n' ), 10 );
				} );
				return sum;
			} );
			var max = Math.max.apply( null, sums.concat( [ 1 ] ) );
			Array.prototype.forEach.call( bars, function ( bar, i ) {
				bar.style.height = Math.max( 2, Math.round( sums[ i ] / max * 100 ) ) + '%';
				bar.classList.toggle( 'is-zero', ! sums[ i ] );
			} );
		}
		var list = document.querySelector( '.formexposed-wrap .fx-reasons' );
		if ( list ) {
			list.setAttribute( 'role', 'group' );
			list.setAttribute( 'aria-label', texts.chartReasons || '' );
			Array.prototype.forEach.call( list.querySelectorAll( 'li[data-fx-reason]' ), function ( li ) {
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'fx-reason-toggle';
				button.setAttribute( 'aria-pressed', 'true' );
				while ( li.firstChild ) {
					button.appendChild( li.firstChild );
				}
				li.appendChild( button );
				button.addEventListener( 'click', function () {
					var key = li.getAttribute( 'data-fx-reason' );
					off[ key ] = ! off[ key ];
					button.setAttribute( 'aria-pressed', off[ key ] ? 'false' : 'true' );
					li.classList.toggle( 'is-off', !! off[ key ] );
					redraw();
				} );
			} );
		}
	}

	// Quarantine: the reasons as filters, a search with marked hits, "/" for the search.
	var qtools = document.querySelector( '.formexposed-wrap [data-fx-qtools]' );
	if ( qtools ) {
		var qsearch = qtools.querySelector( '[data-fx-qsearch]' );
		var qchips = qtools.querySelectorAll( '[data-fx-qreason]' );
		var qshown = qtools.querySelector( '[data-fx-qshown]' );
		var qnomatch = document.querySelector( '.formexposed-wrap [data-fx-qnomatch]' );
		var qrows = document.querySelectorAll( '.formexposed-wrap tr[data-fx-qrow]' );
		var qreason = '';
		qtools.hidden = false;
		function unmark( row ) {
			Array.prototype.forEach.call( row.querySelectorAll( 'mark.fx-hit' ), function ( m ) {
				m.parentNode.replaceChild( document.createTextNode( m.textContent ), m );
			} );
			row.normalize();
		}
		function markIn( node, term ) {
			var walker = document.createTreeWalker( node, NodeFilter.SHOW_TEXT );
			var hits = [];
			while ( walker.nextNode() ) {
				if ( walker.currentNode.nodeValue.toLowerCase().indexOf( term ) >= 0 ) {
					hits.push( walker.currentNode );
				}
			}
			hits.forEach( function ( text ) {
				var at = text.nodeValue.toLowerCase().indexOf( term );
				var hit = text.splitText( at );
				hit.splitText( term.length );
				var m = document.createElement( 'mark' );
				m.className = 'fx-hit';
				m.textContent = hit.nodeValue;
				hit.parentNode.replaceChild( m, hit );
			} );
		}
		function qfilter() {
			var term = qsearch.value.trim().toLowerCase();
			var n = 0;
			Array.prototype.forEach.call( qrows, function ( row ) {
				unmark( row );
				var content = row.cells[ 1 ];
				var details = content ? content.querySelector( 'details' ) : null;
				var hit = ( ! qreason || row.getAttribute( 'data-fx-reason' ) === qreason ) && ( ! term || ( content && content.textContent.toLowerCase().indexOf( term ) >= 0 ) );
				row.hidden = ! hit;
				n += hit ? 1 : 0;
				if ( hit && term && content ) {
					markIn( content, term );
					if ( details && details.querySelector( 'dl mark' ) ) {
						details.open = true;
					}
				}
			} );
			qnomatch.hidden = n > 0;
			qshown.textContent = term || qreason ? fill( texts.shown, n, qrows.length ) : '';
		}
		qsearch.addEventListener( 'input', qfilter );
		qsearch.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && qsearch.value ) {
				event.preventDefault();
				qsearch.value = '';
				qfilter();
			}
		} );
		Array.prototype.forEach.call( qchips, function ( chip ) {
			chip.addEventListener( 'click', function () {
				qreason = chip.getAttribute( 'data-fx-qreason' );
				Array.prototype.forEach.call( qchips, function ( other ) {
					other.setAttribute( 'aria-pressed', other === chip ? 'true' : 'false' );
				} );
				qfilter();
			} );
		} );
		qnomatch.querySelector( '[data-fx-qreset]' ).addEventListener( 'click', function () {
			qsearch.value = '';
			qchips[ 0 ].click();
			qsearch.focus();
		} );
		document.addEventListener( 'keydown', function ( event ) {
			var target = event.target || {};
			if ( '/' === event.key && ! event.ctrlKey && ! event.metaKey && ! event.altKey && ! /^(INPUT|TEXTAREA|SELECT)$/.test( target.tagName || '' ) && ! target.isContentEditable && ! document.querySelector( '.formexposed-dialog' ) ) {
				event.preventDefault();
				qsearch.focus();
			}
		} );
	}

	// Timeline dots and jump links: the target row or card lights up briefly (the link works without this).
	function flash( id ) {
		var target = id && document.getElementById( id );
		if ( ! target ) {
			return;
		}
		if ( target.hidden && qtools ) {
			qsearch.value = '';
			qchips[ 0 ].click();
		}
		target.classList.remove( 'fx-flash' );
		void target.offsetWidth; // restart the animation
		target.classList.add( 'fx-flash' );
	}
	document.addEventListener( 'click', function ( event ) {
		var link = event.target && event.target.closest ? event.target.closest( '.formexposed-wrap a.fx-tl-dot, .formexposed-wrap a[data-fx-jump]' ) : null;
		if ( ! link ) {
			return;
		}
		var id = link.getAttribute( 'href' ).slice( 1 );
		var target = document.getElementById( id );
		if ( ! target ) {
			return;
		}
		event.preventDefault();
		flash( id );
		target.scrollIntoView( { behavior: still ? 'auto' : 'smooth', block: 'center' } );
		if ( target.hasAttribute( 'tabindex' ) ) {
			target.focus( { preventScroll: true } );
		} else {
			var first = target.querySelector( 'a, button, summary' );
			if ( first ) {
				first.focus( { preventScroll: true } );
			}
		}
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + id );
		}
	} );
	if ( window.location.hash ) {
		flash( window.location.hash.slice( 1 ) );
	}

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
