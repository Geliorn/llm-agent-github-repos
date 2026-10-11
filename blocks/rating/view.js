/**
 * Front-end voting behaviour for lagr/rating. Enqueued automatically by WP
 * core on any page that renders the block (block.json's "viewScript") — no
 * manual wp_enqueue_script() needed.
 */
( function() {
	'use strict';

	document.addEventListener( 'click', function( event ) {
		var button = event.target.closest( '.lagr-rating-star' );
		if ( ! button || button.disabled || 'BUTTON' !== button.tagName ) {
			return;
		}

		var wrap = button.closest( '.lagr-rating' );
		if ( ! wrap || wrap.classList.contains( 'lagr-rating-readonly' ) ) {
			return;
		}

		var body = new URLSearchParams();
		body.set( 'action', 'lagr_rate' );
		body.set( 'post_id', wrap.getAttribute( 'data-post-id' ) );
		body.set( 'value', button.getAttribute( 'data-value' ) );
		body.set( 'nonce', wrap.getAttribute( 'data-nonce' ) );

		fetch( wrap.getAttribute( 'data-ajax-url' ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function( r ) { return r.json(); } )
			.then( function( json ) {
				if ( ! json || ! json.success ) {
					return;
				}
				wrap.classList.add( 'lagr-rating-voted' );
				wrap.querySelectorAll( '.lagr-rating-star' ).forEach( function( star ) {
					star.disabled = true;
				} );
				var scoreEl = wrap.querySelector( '.lagr-rating-score' );
				if ( scoreEl ) {
					scoreEl.classList.remove( 'lagr-rating-empty' );
					var avg = Number( json.data.average ).toFixed( 1 ).replace( '.', ',' );
					scoreEl.innerHTML = '<b>' + avg + '</b> / 5 · ' + json.data.count + ' оценок';
				}
			} )
			.catch( function() {} );
	} );
} )();
