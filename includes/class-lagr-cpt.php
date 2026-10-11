<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's own post types and taxonomies in code.
 * No dependency on CPT UI / ACF — this plugin must be self-contained
 * and portable to any WordPress install.
 */
class LAGR_CPT {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'init', array( __CLASS__, 'link_extra_topics_taxonomy' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'include_shared_taxonomies_in_archives' ) );
	}

	/**
	 * Built-in taxonomy archives (/tag/x/, /category/x/) silently default
	 * post_type to 'post' even when other post types are registered for that
	 * taxonomy — unlike a CUSTOM taxonomy archive (is_tax(), e.g. our own
	 * repo_topic), where WP_Query already expands post_type to every
	 * registered object type on its own. Found live: WP_Query(['tag'=>'ai'])
	 * returned 0 posts while the exact same query with post_type=>'any'
	 * returned 15 — the term relationship was correct, WP's own default
	 * just excluded github_repo. Without this, sharing post_tag with
	 * github_repo (the "extra topics taxonomy" setting) looks like it works
	 * (terms/counts are right) but /tag/x/ never actually shows a repo.
	 */
	public static function include_shared_taxonomies_in_archives( $query ) {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( ! $query->is_tag() && ! $query->is_category() ) {
			return;
		}
		if ( '' !== $query->get( 'post_type' ) ) {
			return; // Already explicit — don't override a deliberate choice.
		}

		$taxonomy = $query->is_tag() ? 'post_tag' : 'category';
		$tax_obj  = get_taxonomy( $taxonomy );
		if ( $tax_obj && count( (array) $tax_obj->object_type ) > 1 ) {
			$query->set( 'post_type', $tax_obj->object_type );
		}
	}

	/**
	 * Whatever taxonomy the "extra topics" setting points to (post_tag by
	 * default) needs to actually be attached to github_repo, or term
	 * archives/queries won't include it. Runs every request — this is a
	 * function call, not a persistent DB setting, so it must live here
	 * rather than in a one-off script, and has nothing to do with the theme.
	 */
	public static function link_extra_topics_taxonomy() {
		$taxonomy = LAGR_Settings::get( 'extra_topics_taxonomy', 'post_tag' );
		if ( 'none' === $taxonomy || 'repo_topic' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}
		if ( ! is_object_in_taxonomy( 'github_repo', $taxonomy ) ) {
			register_taxonomy_for_object_type( $taxonomy, 'github_repo' );
		}
	}

	public static function register_post_types() {

		register_post_type(
			'github_repo',
			array(
				'label'        => 'GitHub-репозитории',
				'labels'       => array(
					'name'          => 'GitHub-репозитории',
					'singular_name' => 'GitHub-репозиторий',
					'add_new_item'  => 'Добавить репозиторий',
					'edit_item'     => 'Репозиторий',
					'search_items'  => 'Поиск репозиториев',
					'all_items'     => 'Все репозитории',
				),
				'public'       => true,
				'has_archive'  => true,
				'rewrite'      => array( 'slug' => 'repos' ),
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'thumbnail', 'revisions' ),
				'menu_icon'    => 'dashicons-star-half',
				'show_in_menu' => 'lagr-main',
			)
		);

		register_post_type(
			'github_repo_rule',
			array(
				'label'        => 'Правила сбора',
				'labels'       => array(
					'name'          => 'Правила сбора',
					'singular_name' => 'Правило сбора',
					'add_new_item'  => 'Добавить правило',
					'edit_item'     => 'Правило сбора',
					'search_items'  => 'Поиск правил',
					'all_items'     => 'Все правила',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'menu_icon'    => 'dashicons-filter',
				'show_in_menu' => 'lagr-main',
			)
		);
	}

	public static function register_taxonomies() {

		register_taxonomy(
			'repo_topic',
			array( 'github_repo' ),
			array(
				'label'        => 'Темы (топики)',
				'hierarchical' => false,
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'repo-topic' ),
			)
		);

		register_taxonomy(
			'repo_language',
			array( 'github_repo' ),
			array(
				'label'        => 'Язык',
				'hierarchical' => false,
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'repo-language' ),
			)
		);

		register_taxonomy(
			'repo_license',
			array( 'github_repo' ),
			array(
				'label'        => 'Лицензия',
				'hierarchical' => false,
				'public'       => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'repo-license' ),
			)
		);
	}
}
