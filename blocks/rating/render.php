<?php
/**
 * Server-rendered so it works per-post inside a Query Loop, same reasoning
 * as every other dynamic block in this plugin/partner-catalog.
 *
 * 0.10.2: SVG stars instead of ♡ hearts (Google-snippet look, same as
 * partner-catalog). Cards get the read-only line "4,6 ★★★★★ (12)"; the repo
 * page gets five star buttons (painted with the rounded average, hover
 * previews, a click votes) plus "4,6 · 12 оценок".
 *
 * @var array $attributes Block attributes (size: 'small'|'large', interactive: bool).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_the_ID();
if ( ! $post_id ) {
	return;
}

$average     = LAGR_Ratings::get_average( $post_id );
$count       = LAGR_Ratings::get_count( $post_id );
$voted       = LAGR_Ratings::has_voted( $post_id );
$size        = 'large' === ( $attributes['size'] ?? 'small' ) ? 'large' : 'small';
$interactive = (bool) ( $attributes['interactive'] ?? true );

$class = 'lagr-rating lagr-rating-' . $size . ( $voted ? ' lagr-rating-voted' : '' ) . ( $interactive ? '' : ' lagr-rating-readonly' );

if ( ! $interactive ) {
	// A card's rating is a plain current-score display, not a voting control
	// (no data-post-id/nonce — voting only happens on the repo's own page).
	echo '<div ' . get_block_wrapper_attributes( array( 'class' => $class ) ) . '>' . LAGR_Ratings::line_html( $post_id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	return;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'         => $class,
		'data-post-id'  => $post_id,
		'data-nonce'    => wp_create_nonce( LAGR_Ratings::NONCE_ACTION ),
		'data-ajax-url' => admin_url( 'admin-ajax.php' ),
		'data-filled'   => $count ? (int) round( $average ) : 0,
	)
);

$filled = $count ? (int) round( $average ) : 0;
$star   = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . LAGR_Ratings::STAR_PATH . '"/></svg>';

echo '<div ' . $wrapper_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
echo '<span class="lagr-rating-stars" role="group" aria-label="Оценить репозиторий">';
for ( $i = 1; $i <= 5; $i++ ) {
	printf(
		'<button type="button" class="lagr-rating-star%1$s" data-value="%2$d"%3$s aria-label="Оценка %2$d из 5">%4$s</button>',
		$i <= $filled ? ' is-on' : '',
		$i,
		$voted ? ' disabled' : '',
		$star // phpcs:ignore WordPress.Security.EscapeOutput
	);
}
echo '</span>';

if ( $count > 0 ) {
	printf(
		'<span class="lagr-rating-score"><b>%1$s</b> · %2$d %3$s</span>',
		esc_html( number_format_i18n( $average, 1 ) ),
		$count,
		esc_html( LAGR_Ratings::ru_plural( $count, 'оценка', 'оценки', 'оценок' ) )
	);
} else {
	echo '<span class="lagr-rating-score lagr-rating-empty">пока нет оценок</span>';
}
echo '</div>';
