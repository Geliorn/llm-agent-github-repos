( function( blocks, element, blockEditor ) {
	'use strict';
	var el = element.createElement;

	blocks.registerBlockType( 'lagr/repo-filters', {
		edit: function() {
			var blockProps = blockEditor.useBlockProps();
			return el( 'p', blockProps, el( 'em', null, '☰ Фильтры архива (собственный виджет, значения — на реальной странице)' ) );
		},
		// Dynamic block — actual markup comes from render.php on the server.
		save: function() {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor );
