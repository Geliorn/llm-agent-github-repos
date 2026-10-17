/**
 * Front-end voting behaviour for lagr/rating. Enqueued automatically by WP
 * core on any page that renders the block (block.json's "viewScript") — no
 * manual wp_enqueue_script() needed. Hover previews the score on the stars,
 * leaving the stars goes back to the current (rounded) average, a click votes.
 */
( function() {
	'use strict';

	function plural( n, a, b, c ) {
		var m10 = n % 10, m100 = n % 100;
		if ( m100 >= 11 && m100 <= 14 ) { return c; }
		if ( 1 === m10 ) { return a; }
		if ( m10 >= 2 && m10 <= 4 ) { return b; }
		return c;
	}

	function paint( wrap, value ) {
		wrap.querySelectorAll( '.lagr-rating-star' ).forEach( function( star, i ) {
			star.classList.toggle( 'is-on', i < value );
		} );
	}

	function live( el ) {
		var wrap = el && el.closest( '.lagr-rating' );
		return wrap && ! wrap.classList.contains( 'lagr-rating-readonly' ) && ! wrap.classList.contains( 'lagr-rating-voted' ) ? wrap : null;
	}

	document.addEventListener( 'mouseover', function( event ) {
		var star = event.target.closest( '.lagr-rating-star' );
		var wrap = live( star );
		if ( wrap ) {
			paint( wrap, Number( star.getAttribute( 'data-value' ) ) );
		}
	} );
	document.addEventListener( 'mouseout', function( event ) {
		var group = event.target.closest( '.lagr-rating-stars' );
		var wrap = live( group );
		if ( wrap && ! group.contains( event.relatedTarget ) ) {
			paint( wrap, Number( wrap.getAttribute( 'data-filled' ) ) || 0 );
		}
	} );

	document.addEventListener( 'click', function( event ) {
		var button = event.target.closest( '.lagr-rating-star' );
		if ( ! button || button.disabled || 'BUTTON' !== button.tagName ) {
			return;
		}

		var wrap = live( button );
		if ( ! wrap ) {
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
				var avg = Number( json.data.average );
				var count = Number( json.data.count );
				wrap.classList.add( 'lagr-rating-voted' );
				wrap.setAttribute( 'data-filled', Math.round( avg ) );
				paint( wrap, Math.round( avg ) );
				wrap.querySelectorAll( '.lagr-rating-star' ).forEach( function( star ) {
					star.disabled = true;
				} );
				var scoreEl = wrap.querySelector( '.lagr-rating-score' );
				if ( scoreEl ) {
					scoreEl.classList.remove( 'lagr-rating-empty' );
					scoreEl.innerHTML = '<b>' + avg.toFixed( 1 ).replace( '.', ',' ) + '</b> · ' + count + ' ' + plural( count, 'оценка', 'оценки', 'оценок' );
				}
			} )
			.catch( function() {} );
	} );
} )();
