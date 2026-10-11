<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes the collected/generated short description into whichever SEO
 * plugin's own meta field is configured, so the meta description isn't
 * left empty/auto-generated. 'none' skips this entirely — some of the
 * network's 20 sites may run neither Yoast nor Rank Math.
 */
class LAGR_SEO {

	public static function apply_meta_description( $post_id, $description ) {
		$plugin = LAGR_Settings::get( 'seo_plugin', 'none' );
		if ( 'none' === $plugin || empty( $description ) ) {
			return;
		}

		$excerpt = wp_trim_words( wp_strip_all_tags( $description ), 30, '…' );

		switch ( $plugin ) {
			case 'yoast':
				update_post_meta( $post_id, '_yoast_wpseo_metadesc', $excerpt );
				break;
			case 'rankmath':
				update_post_meta( $post_id, 'rank_math_description', $excerpt );
				break;
		}
	}
}
