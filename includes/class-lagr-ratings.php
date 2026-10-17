<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Own site-rating widget for github_repo — replaces WP-PostRatings as a
 * dependency (decided 2026-09-04: keep Filter Everything and Relevanssi as
 * third-party plugins, bring ratings and related-posts in-house since both
 * are genuinely simple to own and this removes two external dependencies
 * per network site). Storage: two plain postmeta counters, incremented via
 * a raw UPDATE (atomic at the DB row level) rather than
 * get_post_meta()+update_post_meta() (read-then-write — two concurrent
 * votes could otherwise both read the same starting count and one vote
 * would be lost). No fraud protection beyond a one-vote-per-browser cookie —
 * same "simple, no defense against abuse" stance already used for the
 * partner-catalog click counter.
 */
class LAGR_Ratings {

	const META_TOTAL = '_lagr_rating_total';
	const META_COUNT = '_lagr_rating_count';
	const POST_TYPE  = 'github_repo';
	const NONCE_ACTION = 'lagr_rate';

	public static function init() {
		add_action( 'wp_ajax_lagr_rate', array( __CLASS__, 'handle_vote' ) );
		add_action( 'wp_ajax_nopriv_lagr_rate', array( __CLASS__, 'handle_vote' ) );
	}

	public static function get_average( $post_id ) {
		$count = self::get_count( $post_id );
		if ( $count < 1 ) {
			return 0.0;
		}
		$total = (int) get_post_meta( $post_id, self::META_TOTAL, true );
		return round( $total / $count, 1 );
	}

	public static function get_count( $post_id ) {
		return (int) get_post_meta( $post_id, self::META_COUNT, true );
	}

	/** Material "star" — the glyph Google draws in rating snippets (same as partner-catalog, duplicated: the plugins stay independent). */
	const STAR_PATH = 'M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z';

	/**
	 * Five SVG stars with a fractional fill measured per star (4,6 fills 60%
	 * of the fifth star). 0 = five grey stars. Replaced the ♡ hearts in 0.10.2.
	 */
	public static function stars_svg( $value, $size = 15 ) {
		$value = max( 0.0, min( 5.0, (float) $value ) );
		$gap   = 2;
		$width = $size * 5 + $gap * 4;
		$paths = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$paths .= sprintf( '<path transform="translate(%1$s 0) scale(%2$s)" d="%3$s"/>', $i * ( $size + $gap ), round( $size / 24, 4 ), self::STAR_PATH );
		}
		$full = (int) floor( $value );
		$clip = $full * ( $size + $gap ) + ( $value - $full ) * $size;
		$id   = wp_unique_id( 'lagr-stars-' );
		return sprintf(
			'<svg class="lagr-stars" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s"><defs><clipPath id="%4$s"><rect width="%5$s" height="%2$d"/></clipPath></defs><g class="lagr-stars-empty">%6$s</g><g class="lagr-stars-full" clip-path="url(#%4$s)">%6$s</g></svg>',
			$width,
			$size,
			esc_attr( $value > 0 ? sprintf( 'Оценка %s из 5', number_format_i18n( $value, 1 ) ) : 'Оценок пока нет' ),
			esc_attr( $id ),
			round( $clip, 2 ),
			$paths
		);
	}

	/**
	 * Read-only line in Google-snippet order: "4,6 ★★★★★ (12)". Stars are
	 * always shown: no votes = five grey stars and nothing else (same rule as
	 * partner-catalog, decided 2026-10-17).
	 */
	public static function line_html( $post_id, $size = 14 ) {
		$count = self::get_count( $post_id );
		if ( $count < 1 ) {
			return '<span class="lagr-rating-line is-empty" title="Оценок пока нет">' . self::stars_svg( 0, $size ) . '</span>';
		}
		$avg = self::get_average( $post_id );
		return '<span class="lagr-rating-line"><span class="lagr-r-val">' . esc_html( number_format_i18n( $avg, 1 ) ) . '</span>'
			. self::stars_svg( $avg, $size ) . '<span class="lagr-r-count">(' . (int) $count . ')</span></span>';
	}

	public static function has_voted( $post_id ) {
		return isset( $_COOKIE[ 'lagr_voted_' . $post_id ] );
	}

	/** Classic 3-form Russian plural (WordPress's own _n() only has 2 forms — wrong for "1 оценка / 3 оценки / 5 оценок"). */
	public static function ru_plural( $n, $one, $few, $many ) {
		$n10  = $n % 10;
		$n100 = $n % 100;
		if ( $n100 >= 11 && $n100 <= 14 ) {
			return $many;
		}
		if ( 1 === $n10 ) {
			return $one;
		}
		if ( $n10 >= 2 && $n10 <= 4 ) {
			return $few;
		}
		return $many;
	}

	public static function handle_vote() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$value   = isset( $_POST['value'] ) ? absint( $_POST['value'] ) : 0;

		if ( ! $post_id || $value < 1 || $value > 5 || self::POST_TYPE !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Некорректный запрос.' ), 400 );
		}

		if ( self::has_voted( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Вы уже оценивали эту запись.' ), 409 );
		}

		global $wpdb;
		add_post_meta( $post_id, self::META_TOTAL, 0, true ); // no-op if the row already exists.
		add_post_meta( $post_id, self::META_COUNT, 0, true );

		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + %d WHERE post_id = %d AND meta_key = %s", $value, $post_id, self::META_TOTAL ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s", $post_id, self::META_COUNT ) );
		clean_post_cache( $post_id );

		setcookie( 'lagr_voted_' . $post_id, '1', time() + YEAR_IN_SECONDS, ( COOKIEPATH ? COOKIEPATH : '/' ), COOKIE_DOMAIN );

		wp_send_json_success(
			array(
				'average' => self::get_average( $post_id ),
				'count'   => self::get_count( $post_id ),
			)
		);
	}
}
