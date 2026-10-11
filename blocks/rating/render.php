<?php
/**
 * Server-rendered so it works per-post inside a Query Loop, same reasoning
 * as every other dynamic block in this plugin/partner-catalog.
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
	// A card's rating is a plain current-score display, not a voting
	// control — one heart + the number, no 1-5 "scale" row and no
	// data-post-id/nonce/ajax-url (nothing for view.js's click handler to
	// act on; voting only happens on the repo's own single page).
	$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => $class ) );
	echo '<div ' . $wrapper_attributes . '>';
	echo '<span class="lagr-rating-star" aria-hidden="true">♡</span>';
	if ( $count > 0 ) {
		printf( '<span class="lagr-rating-score"><b>%s</b></span>', esc_html( number_format_i18n( $average, 1 ) ) );
	} else {
		echo '<span class="lagr-rating-score lagr-rating-empty">—</span>';
	}
	echo '</div>';
	return;
}

$wrapper_attributes = get_block_wrapper_attributes(
	array(
		'class'         => $class,
		'data-post-id'  => $post_id,
		'data-nonce'    => wp_create_nonce( LAGR_Ratings::NONCE_ACTION ),
		'data-ajax-url' => admin_url( 'admin-ajax.php' ),
	)
);

echo '<div ' . $wrapper_attributes . '>';
echo '<span class="lagr-rating-stars" role="group" aria-label="Оценить репозиторий">';
for ( $i = 1; $i <= 5; $i++ ) {
	printf(
		'<button type="button" class="lagr-rating-star" data-value="%1$d"%2$s aria-label="Оценка %1$d из 5">♡</button>',
		$i,
		$voted ? ' disabled' : ''
	);
}
echo '</span>';

if ( $count > 0 ) {
	printf(
		'<span class="lagr-rating-score"><b>%1$s</b> / 5 · %2$d %3$s</span>',
		esc_html( number_format_i18n( $average, 1 ) ),
		$count,
		esc_html( LAGR_Ratings::ru_plural( $count, 'оценка', 'оценки', 'оценок' ) )
	);
} else {
	echo '<span class="lagr-rating-score lagr-rating-empty">пока нет оценок</span>';
}
echo '</div>';
