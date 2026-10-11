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
