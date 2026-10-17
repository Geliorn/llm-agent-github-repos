( function( blocks, element, blockEditor ) {
	'use strict';
	var el = element.createElement;

	blocks.registerBlockType( 'lagr/rating', {
		edit: function() {
			var blockProps = blockEditor.useBlockProps();
			return el( 'p', blockProps, el( 'em', null, '★ Рейтинг репозитория (собственный виджет, голосование — на реальной странице)' ) );
		},
		// Dynamic block — actual markup comes from render.php on the server.
		save: function() {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor );
