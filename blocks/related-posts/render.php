<?php
/**
 * @var array $attributes Block attributes (limit).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_the_ID();
if ( ! $post_id ) {
	return;
}

$limit   = isset( $attributes['limit'] ) ? absint( $attributes['limit'] ) : 3;
$related = LAGR_Related::get_related( $post_id, $limit ?: 3 );
if ( empty( $related ) ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'lagr-related' ) );

echo '<div ' . $wrapper_attributes . '>';
foreach ( $related as $related_post ) {
	$avg   = LAGR_Ratings::get_average( $related_post->ID );
	$count = LAGR_Ratings::get_count( $related_post->ID );

	echo '<a class="lagr-related-card" href="' . esc_url( get_permalink( $related_post ) ) . '">';
	echo '<span class="lagr-related-title">' . esc_html( get_the_title( $related_post ) ) . '</span>';
	if ( $count > 0 ) {
		printf(
			'<span class="lagr-related-rating">♡ <b>%1$s</b> (%2$d)</span>',
			esc_html( number_format_i18n( $avg, 1 ) ),
			$count
		);
	}
	echo '</a>';
}
echo '</div>';
