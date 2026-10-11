/**
 * Copy-to-clipboard for the "Установка" tab's clone command. Delegated click
 * so it covers any .lagr-copy on the page. Tries the async Clipboard API, then
 * falls back to a temporary textarea + execCommand for contexts where the
 * Clipboard API is blocked; degrades to a selectable <code> if both fail.
 */
( function() {
	'use strict';

	function flash( btn ) {
		if ( ! btn.getAttribute( 'data-label' ) ) {
			btn.setAttribute( 'data-label', btn.textContent );
		}
		btn.textContent = 'Скопировано';
		btn.classList.add( 'is-copied' );
		setTimeout( function() {
			btn.textContent = btn.getAttribute( 'data-label' );
			btn.classList.remove( 'is-copied' );
		}, 1500 );
	}

	function fallbackCopy( text ) {
		try {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'absolute';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			var ok = document.execCommand( 'copy' );
			document.body.removeChild( ta );
			return ok;
		} catch ( e ) {
			return false;
		}
	}

	document.addEventListener( 'click', function( event ) {
		var btn = event.target.closest( '.lagr-copy' );
		if ( ! btn ) {
			return;
		}
		var text = btn.getAttribute( 'data-copy' ) || '';
		if ( ! text ) {
			return;
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then(
				function() { flash( btn ); },
				function() { if ( fallbackCopy( text ) ) { flash( btn ); } }
			);
		} else if ( fallbackCopy( text ) ) {
			flash( btn );
		}
	} );
} )();
