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
	echo '<a class="lagr-related-card" href="' . esc_url( get_permalink( $related_post ) ) . '">';
	echo '<span class="lagr-related-title">' . esc_html( get_the_title( $related_post ) ) . '</span>';
	if ( LAGR_Settings::integration_enabled( 'ratings' ) ) {
		echo '<span class="lagr-related-rating">' . LAGR_Ratings::line_html( $related_post->ID, 12 ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	}
	echo '</a>';
}
echo '</div>';
