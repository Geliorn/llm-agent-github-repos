<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes the collected/generated SEO texts into whichever SEO plugin's own
 * meta fields are configured, so the title, meta description and social
 * preview aren't left empty/auto-generated. 'none' skips this entirely —
 * some of the network's 20 sites may run neither Yoast nor Rank Math.
 * Canonical and the og:/twitter: tags themselves are printed by the SEO
 * plugin from these fields; JSON-LD lives in LAGR_Schema.
 */
class LAGR_SEO {

	/**
	 * @param int    $post_id
	 * @param string $short_description Fallback for the meta description.
	 * @param string $meta_title        LLM-written search title ('' = leave the plugin's template).
	 * @param string $meta_description  LLM-written search snippet ('' = trimmed short description).
	 */
	public static function apply_meta( $post_id, $short_description, $meta_title = '', $meta_description = '' ) {
		$plugin = LAGR_Settings::get( 'seo_plugin', 'none' );
		if ( 'none' === $plugin ) {
			return;
		}

		$description = trim( wp_strip_all_tags( (string) $meta_description ) );
		if ( '' === $description && '' !== trim( (string) $short_description ) ) {
			$description = wp_trim_words( wp_strip_all_tags( $short_description ), 30, '…' );
		}
		$title = trim( wp_strip_all_tags( (string) $meta_title ) );
		$image = has_post_thumbnail( $post_id ) ? '' : self::github_social_image( $post_id );

		$fields = array(
			'yoast'    => array(
				'title'       => '_yoast_wpseo_title',
				'description' => '_yoast_wpseo_metadesc',
				'image'       => array( '_yoast_wpseo_opengraph-image', '_yoast_wpseo_twitter-image' ),
			),
			'rankmath' => array(
				'title'       => 'rank_math_title',
				'description' => 'rank_math_description',
				'image'       => array( 'rank_math_facebook_image' ),
			),
		);
		if ( ! isset( $fields[ $plugin ] ) ) {
			return;
		}
		$keys = $fields[ $plugin ];

		if ( '' !== $title ) {
			update_post_meta( $post_id, $keys['title'], $title );
		}
		if ( '' !== $description ) {
			update_post_meta( $post_id, $keys['description'], $description );
		}
		if ( '' !== $image ) {
			foreach ( $keys['image'] as $key ) {
				update_post_meta( $post_id, $key, $image );
			}
			if ( 'rankmath' === $plugin ) {
				update_post_meta( $post_id, 'rank_math_twitter_use_facebook', 'on' );
			}
		}
	}

	/** Kept for callers of the old API — description only. */
	public static function apply_meta_description( $post_id, $description ) {
		self::apply_meta( $post_id, $description );
	}

	/**
	 * GitHub's own social preview card for the repo (1200×600, name, description,
	 * stars) — repo pages have no featured image, and without og:image a shared
	 * link renders as bare text in messengers and social networks.
	 */
	private static function github_social_image( $post_id ) {
		$full = (string) get_post_meta( $post_id, '_gh_full_name', true );
		if ( ! preg_match( '#^[\w.-]+/[\w.-]+$#', $full ) ) {
			return '';
		}
		return 'https://opengraph.githubassets.com/1/' . $full;
	}
}
