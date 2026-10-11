<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own "related repos" lookup — replaces the Contextual Related Posts
 * shortcode (decided 2026-09-04, see class-lagr-ratings.php docblock for the
 * full reasoning). CRP's generic keyword-matching is a worse fit here than a
 * plain tax_query on repo_topic — the curated, always-exactly-one topic
 * term is a much more precise "similar" signal than title/content overlap.
 */
class LAGR_Related {

	public static function get_related( $post_id, $limit = 3 ) {
		$terms = wp_get_post_terms( $post_id, 'repo_topic', array( 'fields' => 'ids' ) );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'      => 'github_repo',
				'post_status'    => 'publish',
				'post__not_in'   => array( $post_id ),
				'posts_per_page' => $limit,
				'orderby'        => 'meta_value_num',
				'meta_key'       => '_gh_stars', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'          => 'DESC',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'repo_topic',
						'field'    => 'term_id',
						'terms'    => $terms,
					),
				),
			)
		);
	}
}
