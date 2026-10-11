<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's own dynamic blocks — rating widget and related-posts
 * lookup, both replacing a third-party plugin dependency (see class-lagr-ratings.php) —
 * plus a block bindings source for compact "12.3K"-style counts.
 */
class LAGR_Blocks {

	public static function init() {
		add_filter( 'block_type_metadata', array( __CLASS__, 'set_asset_version' ) );
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'register_bindings' ) );
	}

	public static function register() {
		register_block_type( LAGR_PLUGIN_DIR . 'blocks/rating' );
		register_block_type( LAGR_PLUGIN_DIR . 'blocks/related-posts' );
		register_block_type( LAGR_PLUGIN_DIR . 'blocks/repo-filters' );
		register_block_type( LAGR_PLUGIN_DIR . 'blocks/repo-content' );
		register_block_type( LAGR_PLUGIN_DIR . 'blocks/repo-breadcrumbs' );
	}

	/**
	 * block.json's viewScript/style assets are cache-busted with its own
	 * top-level "version" field, NOT the plugin's LAGR_VERSION — left
	 * unset, WP falls back to a fixed value that never changes across a
	 * redeploy, so a fixed CDN/browser cache can keep serving a stale
	 * view.js indefinitely after we ship a fix (found live: an old
	 * repo-filters/view.js kept auto-submitting the tags multiselect after
	 * a single click, well after the fixed file was already on the
	 * server). Stamp every block.json this plugin registers with
	 * LAGR_VERSION so the existing "bump LAGR_VERSION on deploy" habit
	 * actually busts these too.
	 */
	public static function set_asset_version( $metadata ) {
		if ( isset( $metadata['file'] ) && str_starts_with( $metadata['file'], wp_normalize_path( LAGR_PLUGIN_DIR ) ) ) {
			$metadata['version'] = LAGR_VERSION;
		}
		return $metadata;
	}

	/**
	 * `core/post-meta` only echoes a meta value verbatim — no formatting hook
	 * for turning 203223 into "203K" the way GitHub's own UI does. Rather
	 * than a whole extra dynamic block just to reformat one number, a small
	 * custom binding source does the same job with far less code (same
	 * "paragraph + block bindings" shape every other stat on the card
	 * already uses, e.g. LAGR_Patterns::bound_paragraph()).
	 */
	public static function register_bindings() {
		register_block_bindings_source(
			'lagr/count',
			array(
				'label'              => 'Сокращённое число (K/M)',
				'get_value_callback' => array( __CLASS__, 'get_count_value' ),
			)
		);
		// Formats an ISO-8601 meta value (GitHub dates like _gh_pushed_at) to a
		// readable date. core/post-date can't show these (it only reads the
		// post's own date/modified, and its "modified" display even hides
		// itself when the modified date isn't after publish — which left the
		// single page's "Обновлён" row empty).
		register_block_bindings_source(
			'lagr/date',
			array(
				'label'              => 'Дата из ISO-меты',
				'get_value_callback' => array( __CLASS__, 'get_date_value' ),
			)
		);
	}

	public static function get_count_value( $source_args, $block_instance ) {
		$post_id = $block_instance->context['postId'] ?? get_the_ID();
		$key     = $source_args['key'] ?? '';
		if ( ! $post_id || ! $key ) {
			return '0';
		}
		return self::format_count( (int) get_post_meta( $post_id, $key, true ) );
	}

	public static function get_date_value( $source_args, $block_instance ) {
		$post_id = $block_instance->context['postId'] ?? get_the_ID();
		$key     = $source_args['key'] ?? '';
		if ( ! $post_id || ! $key ) {
			return '';
		}
		$raw = (string) get_post_meta( $post_id, $key, true );
		if ( '' === $raw ) {
			return '';
		}
		$ts = strtotime( $raw );
		return $ts ? wp_date( 'd.m.Y', $ts ) : '';
	}

	/** 203223 -> "203K", 60535 -> "61K", 4200000 -> "4.2M", 842 -> "842". */
	public static function format_count( $n ) {
		if ( $n >= 1000000 ) {
			$value = $n / 1000000;
			$unit  = 'M';
		} elseif ( $n >= 1000 ) {
			$value = $n / 1000;
			$unit  = 'K';
		} else {
			return (string) $n;
		}

		$formatted = number_format_i18n( $value, $value < 10 ? 1 : 0 );
		$formatted = preg_replace( '/([,.]0)$/', '', $formatted ); // trim a bare ".0"/",0"

		return $formatted . $unit;
	}
}
