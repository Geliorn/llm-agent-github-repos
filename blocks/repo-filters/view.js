/**
 * Auto-submit on change — a plain GET form works without any JS at all
 * (the "Применить" button covers that), this just removes the extra click.
 * Not for .lagr-filter-multiselect: picking several tags there takes
 * several separate `change` events (one per click), so auto-submitting
 * would reload the page after the very first pick and drop the rest —
 * that field relies on the "Применить" button instead.
 */
( function() {
	'use strict';

	document.addEventListener( 'change', function( event ) {
		var field = event.target.closest( '.lagr-filters input[type="checkbox"], .lagr-filters select:not(.lagr-filter-multiselect)' );
		if ( ! field ) {
			return;
		}
		var form = field.closest( 'form' );
		if ( form ) {
			form.submit();
		}
	} );
} )();
