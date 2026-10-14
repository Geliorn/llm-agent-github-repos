/* global jQuery, wp, lagrPin */
( function ( $ ) {
	'use strict';

	/** Media picker for the background, mask and logo fields. */
	$( document ).on( 'click', '.lagr-media-pick', function ( event ) {
		event.preventDefault();
		var $wrap = $( this ).closest( '.lagr-media' );
		var frame = wp.media( {
			title: 'Выберите изображение',
			library: { type: 'image' },
			multiple: false,
			button: { text: 'Использовать' }
		} );
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			var thumb = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
			$wrap.find( 'input[type=hidden]' ).val( attachment.id );
			$wrap.find( '.lagr-media-thumb' ).html( '<img src="' + thumb + '" alt="" width="60" height="60">' );
		} );
		frame.open();
	} );

	$( document ).on( 'click', '.lagr-media-clear', function ( event ) {
		event.preventDefault();
		var $wrap = $( this ).closest( '.lagr-media' );
		$wrap.find( 'input[type=hidden]' ).val( 0 );
		$wrap.find( '.lagr-media-thumb' ).empty();
	} );

	/**
	 * Live preview: posts the form's *current* values, so a change is visible
	 * before saving. The form's own action/nonce are dropped — this goes to
	 * the ajax handler, not to the save handler.
	 */
	$( document ).on( 'click', '#lagr-pin-preview-button', function ( event ) {
		event.preventDefault();
		var $button = $( this );
		var $box = $( '#lagr-pin-preview-box' );
		var fields = $( '#lagr-pin-form' ).serializeArray().filter( function ( field ) {
			return field.name !== 'action' && field.name !== '_wpnonce' && field.name !== '_wp_http_referer';
		} );
		fields.push( { name: 'action', value: 'lagr_pin_preview' } );
		fields.push( { name: 'nonce', value: lagrPin.nonce } );
		fields.push( { name: 'preview_repo', value: $( '#lagr-pin-repo' ).val() } );
		[ 0, 1, 2 ].forEach( function ( i ) {
			fields.push( { name: 'preview_text_' + i, value: $( '#lagr-pin-text-' + i ).val() } );
		} );

		$button.prop( 'disabled', true ).text( 'Собираю…' );
		$box.html( '<p class="description">Собираю картинку…</p>' );

		$.post( lagrPin.ajaxUrl, $.param( fields ) )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					var message = response && response.data ? response.data.message : 'Не удалось собрать картинку.';
					$box.html( '<div class="notice notice-error inline"><p></p></div>' ).find( 'p' ).text( message );
					return;
				}
				$box.empty();
				$( '<img>' ).attr( 'src', response.data.dataUri ).appendTo( $box );
				if ( response.data.notice ) {
					$box.append( $( '<p class="description"></p>' ).text( response.data.notice ) );
				}
			} )
			.fail( function () {
				$box.html( '<div class="notice notice-error inline"><p>Запрос не прошёл.</p></div>' );
			} )
			.always( function () {
				$button.prop( 'disabled', false ).text( 'Предпросмотр' );
			} );
	} );
}( jQuery ) );
