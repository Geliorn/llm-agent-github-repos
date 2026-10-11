( function( blocks, element, blockEditor ) {
	'use strict';
	var el = element.createElement;

	blocks.registerBlockType( 'lagr/related-posts', {
		edit: function() {
			var blockProps = blockEditor.useBlockProps();
			return el( 'div', blockProps, el( 'em', null, 'Похожие репозитории (по repo_topic, вычисляется на странице записи)' ) );
		},
		save: function() {
			return null;
		}
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor );
