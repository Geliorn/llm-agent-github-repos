<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's block patterns — the PHP implementation of the
 * approved mockups in docs/mockups/github-repo-pattern-*.html. Patterns are
 * insertable starting points only: once dropped into a template/page via
 * the Site Editor they become regular, freely editable blocks (see the
 * README's "known limitations" — this file does not assign patterns to
 * templates automatically, that's a separate, deliberate editorial step).
 *
 * Styling follows the earlier design decision: no hardcoded theme colors,
 * every block inherits the active theme's theme.json — except the handful
 * of semantic colors we decided to keep fixed everywhere (topic pill blue,
 * heart-rating red), which live in assets/css/patterns.css via className,
 * never inline style JSON.
 */
class LAGR_Patterns {

	const CATEGORY = 'lagr-repos';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'query_loop_block_query_vars', array( __CLASS__, 'filter_score_order' ), 10, 2 );
	}

	public static function enqueue_assets() {
		wp_enqueue_style( 'lagr-patterns', LAGR_PLUGIN_URL . 'assets/css/patterns.css', array(), LAGR_VERSION );
	}

	/**
	 * The stock Query Loop block has no "order by custom meta" option in its
	 * inspector — core's build_query_vars_from_query_block() only maps a fixed
	 * allowlist of keys. We smuggle a custom `lagrOrderBy: "score"` key through
	 * the query attribute (preserved as-is in block context) and catch it here.
	 */
	public static function filter_score_order( $query_vars, $block ) {
		$q = $block->context['query'] ?? array();
		if ( 'github_repo' === ( $q['postType'] ?? '' ) && 'score' === ( $q['lagrOrderBy'] ?? '' ) ) {
			$query_vars['meta_key'] = '_gh_score';
			$query_vars['orderby']  = 'meta_value_num';
			$query_vars['order']    = 'DESC';
		}
		return $query_vars;
	}

	public static function register() {
		register_block_pattern_category( self::CATEGORY, array( 'label' => __( 'GitHub Repo Collector', 'lagr' ) ) );

		register_block_pattern(
			'lagr/repo-card',
			array(
				'title'       => 'GitHub-репозиторий: карточка',
				'description' => 'Одна карточка репозитория — использовать как содержимое Post Template внутри блока «Цикл запроса» (Query Loop) для github_repo.',
				'categories'  => array( self::CATEGORY ),
				'blockTypes'  => array( 'core/post-template' ),
				'content'     => self::card_markup(),
			)
		);

		register_block_pattern(
			'lagr/archive-grid',
			array(
				'title'       => 'GitHub-репозитории: сетка архива',
				'description' => 'Сетка карточек для архива темы/языка/лицензии — наследует запрос архива (repo_topic, repo_language, repo_license) + пагинация.',
				'categories'  => array( self::CATEGORY ),
				'content'     => self::archive_grid_markup(),
			)
		);

		register_block_pattern(
			'lagr/homepage-tiles-top',
			array(
				'title'       => 'GitHub-репозитории: топ по теме (плитка)',
				'description' => 'Вставляемый блок для главной — 6 карточек, отсортированных по score (звёзды + форки × вес). Тему меняете в инспекторе блока «Цикл запроса» после вставки.',
				'categories'  => array( self::CATEGORY ),
				'content'     => self::homepage_tiles_markup( 'score' ),
			)
		);

		register_block_pattern(
			'lagr/homepage-tiles-new',
			array(
				'title'       => 'GitHub-репозитории: новые поступления (плитка)',
				'description' => 'Вставляемый блок для главной — 6 самых свежих карточек (по дате добавления). Тему меняете в инспекторе блока «Цикл запроса» после вставки.',
				'categories'  => array( self::CATEGORY ),
				'content'     => self::homepage_tiles_markup( 'date' ),
			)
		);

		register_block_pattern(
			'lagr/tag-and-topic-cloud',
			array(
				'title'       => 'GitHub-репозитории: облако тем и тегов',
				'description' => 'Два блока «Облако меток» рядом — курируемые темы (repo_topic) и свободные теги (post_tag).',
				'categories'  => array( self::CATEGORY ),
				'content'     => self::tag_cloud_markup(),
			)
		);

		register_block_pattern(
			'lagr/hero-search',
			array(
				'title'       => 'GitHub-репозитории: поиск + популярные темы',
				'description' => 'Полноширинный блок поиска (работает через встроенный поиск WP / Relevanssi, если активен) с быстрыми ссылками на популярные темы.',
				'categories'  => array( self::CATEGORY ),
				'content'     => self::hero_search_markup(),
			)
		);

		register_block_pattern(
			'lagr/single-repo-layout',
			array(
				'title'       => 'GitHub-репозиторий: страница записи',
				'description' => 'Полная разметка одиночной записи github_repo — шапка, рейтинг, статистика, описание + боковая колонка с данными GitHub, похожие записи.',
				'categories'  => array( self::CATEGORY ),
				'content'     => self::single_repo_layout_markup(),
			)
		);
	}

	// ------------------------------------------------------------- helpers

	private static function bound_paragraph( $meta_key, $placeholder = '', $font_size = 'small', $class = '' ) {
		$class_json = $class ? ',"className":"' . esc_attr( $class ) . '"' : '';
		$class_attr = $class ? ' ' . esc_attr( $class ) : '';
		return sprintf(
			'<!-- wp:paragraph {"fontSize":"%1$s"%4$s,"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"%2$s"}}}}} --><p class="has-%1$s-font-size%5$s">%3$s</p><!-- /wp:paragraph -->',
			esc_attr( $font_size ),
			esc_attr( $meta_key ),
			esc_html( $placeholder ),
			$class_json,
			$class_attr
		);
	}

	/** Same shape as bound_paragraph(), but through the `lagr/count` binding source (LAGR_Blocks::format_count()) instead of a raw meta echo — "203223" -> "203K". */
	private static function bound_count_paragraph( $meta_key, $font_size = 'small' ) {
		return sprintf(
			'<!-- wp:paragraph {"fontSize":"%1$s","metadata":{"bindings":{"content":{"source":"lagr/count","args":{"key":"%2$s"}}}}} --><p class="has-%1$s-font-size">0</p><!-- /wp:paragraph -->',
			esc_attr( $font_size ),
			esc_attr( $meta_key )
		);
	}

	/** An ISO-date meta (e.g. _gh_pushed_at) formatted to "d.m.Y" via the `lagr/date` binding source. */
	private static function bound_date_paragraph( $meta_key, $font_size = 'small' ) {
		return sprintf(
			'<!-- wp:paragraph {"className":"lagr-about-value","fontSize":"%1$s","metadata":{"bindings":{"content":{"source":"lagr/date","args":{"key":"%2$s"}}}}} --><p class="lagr-about-value has-%1$s-font-size">—</p><!-- /wp:paragraph -->',
			esc_attr( $font_size ),
			esc_attr( $meta_key )
		);
	}

	private static function static_paragraph( $text, $font_size = 'small' ) {
		return sprintf(
			'<!-- wp:paragraph {"fontSize":"%1$s"} --><p class="has-%1$s-font-size">%2$s</p><!-- /wp:paragraph -->',
			esc_attr( $font_size ),
			esc_html( $text )
		);
	}

	private static function icon_stat( $icon, $meta_key ) {
		// blockGap isn't set here — it inherits the theme's global 1.25rem
		// blockGap, which reads fine between paragraphs/headings but left an
		// icon and its number looking like two unrelated pieces of text
		// instead of one "★ 203K" stat. className carries a small, fixed gap
		// instead (see .lagr-icon-stat in patterns.css).
		return '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"},"className":"lagr-icon-stat"} --><div class="wp-block-group lagr-icon-stat">'
			. self::static_paragraph( $icon )
			. self::bound_count_paragraph( $meta_key )
			. '</div><!-- /wp:group -->';
	}

	/**
	 * Ratings widget — own block (`lagr/rating`, class-lagr-ratings.php),
	 * replacing WP-PostRatings as a dependency (decided 2026-09-04). Still
	 * gated behind the "ratings" setting, now a plain feature on/off switch
	 * rather than "is the third-party plugin active".
	 *
	 * Historical note (why this is a dynamic BLOCK, not a shortcode): the
	 * very first version of this used to render via `[ratings]`, which broke
	 * inside a Query Loop — `core/shortcode` doesn't expand its text at
	 * render time, it just wraps it in `wpautop()`; the actual `do_shortcode()`
	 * pass happens later, once, over the whole assembled page, by which point
	 * `wp_reset_postdata()` had already run — every card showed the SAME
	 * (wrong) post's rating. A dynamic block renders synchronously during
	 * `core/post-template`'s per-item pass, same as `core/post-title`, so it
	 * always sees the correct post — same reasoning applies to `lagr/rating`.
	 */
	private static function ratings_block( $className = '', $interactive = true ) {
		if ( ! LAGR_Settings::integration_enabled( 'ratings' ) ) {
			return '';
		}
		$attrs = array(
			'size'        => $className ? 'large' : 'small',
			'interactive' => $interactive,
		);
		if ( $className ) {
			$attrs['className'] = $className;
		}
		return '<!-- wp:lagr/rating ' . wp_json_encode( $attrs ) . ' /-->';
	}

	/** "Похожие репозитории" — own block (`lagr/related-posts`), replacing the Contextual Related Posts shortcode (same Query Loop reasoning as ratings_block() above). */
	private static function related_posts_block() {
		if ( ! LAGR_Settings::integration_enabled( 'related_posts' ) ) {
			return '';
		}
		return '<!-- wp:heading {"level":3} --><h3>Похожие репозитории</h3><!-- /wp:heading --><!-- wp:lagr/related-posts /-->';
	}

	// -------------------------------------------------------------- card

	/**
	 * Main content on the network is Agent Skills / MCP servers — not
	 * generally catalogued by programming language or license the way a
	 * general code directory would be. Off by default (see LAGR_Settings);
	 * gates both the taxonomy assignment (class-lagr-collector.php) and
	 * this display in patterns, so an unused facet doesn't linger as an
	 * empty pill/heading in the layout.
	 */
	private static function language_term_block( $class = 'lagr-plain-term' ) {
		if ( ! LAGR_Settings::get( 'collect_language' ) ) {
			return '';
		}
		return '<!-- wp:post-terms {"term":"repo_language","className":"' . esc_attr( $class ) . '","fontSize":"small"} /-->';
	}

	private static function license_term_block( $class = 'lagr-plain-term' ) {
		if ( ! LAGR_Settings::get( 'collect_license' ) ) {
			return '';
		}
		return '<!-- wp:post-terms {"term":"repo_license","className":"' . esc_attr( $class ) . '","fontSize":"small"} /-->';
	}

	private static function card_markup() {
		// Read-only on a card — voting only happens on the repo's own single
		// page (lagr/single-repo-layout), never from a Query Loop listing.
		$ratings = self::ratings_block( '', false );

		return '<!-- wp:group {"className":"lagr-card","layout":{"type":"constrained"}} -->
<div class="wp-block-group lagr-card">

<!-- wp:group {"className":"lagr-avatar","layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group lagr-avatar">
<!-- wp:image {"width":20,"height":20,"metadata":{"bindings":{"url":{"source":"core/post-meta","args":{"key":"_gh_owner_avatar"}}}}} -->
<figure class="wp-block-image is-resized"><img src="" alt="" width="20" height="20"/></figure>
<!-- /wp:image -->
' . self::bound_paragraph( '_gh_owner_login', 'owner' ) . '
</div>
<!-- /wp:group -->

<!-- wp:post-title {"isLink":true,"fontSize":"medium"} /-->

' . self::bound_paragraph( 'short_description', 'Описание репозитория', 'small', 'lagr-card-desc' ) . '

<!-- wp:post-terms {"term":"repo_topic","separator":" ","className":"lagr-topic-pill","fontSize":"small"} /-->

<!-- wp:post-terms {"term":"post_tag","separator":" ","className":"lagr-tag-pill lagr-tag-pill-clamped","fontSize":"small"} /-->

' . $ratings . '

<!-- wp:group {"className":"lagr-card-stats","layout":{"type":"flex","flexWrap":"wrap"}} -->
<div class="wp-block-group lagr-card-stats">
' . self::language_term_block() . '
' . self::icon_stat( '★', '_gh_stars' ) . '
' . self::icon_stat( '⑂', '_gh_forks' ) . '
<!-- wp:post-date {"displayType":"modified","fontSize":"small"} /-->
</div>
<!-- /wp:group -->

<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} -->
<div class="wp-block-group">
' . self::license_term_block() . '
<!-- wp:read-more {"content":"Подробно →","fontSize":"small","textColor":"contrast","style":{"border":{"radius":"999px","width":"1px","color":"var:preset|color|light"},"spacing":{"padding":{"top":"0.35em","bottom":"0.35em","left":"1em","right":"1em"}},"typography":{"textDecoration":"none"}}} /-->
</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->';
	}

	// ---------------------------------------------------------- archive

	private static function archive_grid_markup() {
		$query = wp_json_encode(
			array(
				'perPage'  => 12,
				'pages'    => 0,
				'offset'   => 0,
				'postType' => 'github_repo',
				'order'    => 'desc',
				'orderBy'  => 'date',
				'inherit'  => true,
			)
		);

		return '<!-- wp:query {"queryId":1,"query":' . $query . ',"displayLayout":{"type":"flex","columns":3},"className":"lagr-tile-grid-wrap"} -->
<div class="wp-block-query">
<!-- wp:post-template {"className":"lagr-tile-grid"} -->
' . self::card_markup() . '
<!-- /wp:post-template -->

<!-- wp:query-pagination -->

<!-- wp:query-pagination-previous /-->
<!-- wp:query-pagination-numbers /-->
<!-- wp:query-pagination-next /-->
<!-- /wp:query-pagination -->

<!-- wp:query-no-results -->
<!-- wp:paragraph --><p>По этому запросу пока ничего не собрано.</p><!-- /wp:paragraph -->
<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->';
	}

	// --------------------------------------------------------- homepage

	private static function homepage_tiles_markup( $order_mode ) {
		$query_args = array(
			'perPage'  => 6,
			'pages'    => 1,
			'offset'   => 0,
			'postType' => 'github_repo',
			'order'    => 'desc',
			'orderBy'  => 'date',
			'inherit'  => false,
		);
		if ( 'score' === $order_mode ) {
			$query_args['lagrOrderBy'] = 'score'; // caught by filter_score_order(), see above.
		}
		$query = wp_json_encode( $query_args );

		return '<!-- wp:query {"query":' . $query . ',"align":"wide","displayLayout":{"type":"flex","columns":3},"className":"lagr-tile-grid-wrap"} -->
<div class="wp-block-query alignwide">
<!-- wp:post-template {"className":"lagr-tile-grid"} -->
' . self::card_markup() . '
<!-- /wp:post-template -->
</div>
<!-- /wp:query -->';
	}

	// ------------------------------------------------------------ cloud

	private static function tag_cloud_markup() {
		return '<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":4} --><h4>Темы</h4><!-- /wp:heading -->
<!-- wp:tag-cloud {"taxonomy":"repo_topic","numberOfTags":20,"showTagCounts":true,"className":"lagr-topic-tag-cloud"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:heading {"level":4} --><h4>Теги</h4><!-- /wp:heading -->
<!-- wp:tag-cloud {"taxonomy":"post_tag","numberOfTags":20,"showTagCounts":true,"className":"lagr-topic-tag-cloud"} /-->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->';
	}

	// -------------------------------------------------------------- hero

	/**
	 * Popular-topics quick links use core/tag-cloud (a real dynamic block,
	 * rendered fresh on every request) rather than pre-fetching terms with
	 * get_terms() into a static string here — this pattern's content gets
	 * baked once into a wp_template row when installed into a real template
	 * (see the "install patterns into templates" step), so anything computed
	 * in PHP at registration time would freeze at whatever it was when that
	 * happened, never reflecting which topics are popular later on.
	 */
	private static function hero_search_markup() {
		return '<!-- wp:group {"align":"full","className":"lagr-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull lagr-hero">
<!-- wp:heading {"textAlign":"center","level":1} --><h1 class="has-text-align-center">Каталог AI-агентов, MCP и Agent Skills</h1><!-- /wp:heading -->
<!-- wp:paragraph {"align":"center"} --><p class="has-text-align-center">Репозитории с GitHub, отобранные и обновляемые автоматически по настраиваемым темам.</p><!-- /wp:paragraph -->
<!-- wp:search {"label":"","placeholder":"Поиск","buttonText":"Искать","buttonPosition":"button-inside","buttonUseIcon":true,"align":"center","width":70,"widthUnit":"%"} /-->
<!-- wp:tag-cloud {"taxonomy":"repo_topic","numberOfTags":6,"showTagCounts":false,"smallestFontSize":"13px","largestFontSize":"13px","className":"lagr-hero-topics"} /-->
</div>
<!-- /wp:group -->';
	}

	// ------------------------------------------------------------ single

	/** One "label — value" row in the single page's "О репозитории" sidebar card. */
	private static function about_row( $label, $value_markup ) {
		return '<!-- wp:group {"className":"lagr-about-row","layout":{"type":"flex","justifyContent":"space-between","flexWrap":"nowrap"}} -->'
			. '<div class="wp-block-group lagr-about-row">'
			. '<!-- wp:paragraph {"className":"lagr-about-label","fontSize":"small"} --><p class="lagr-about-label has-small-font-size">' . esc_html( $label ) . '</p><!-- /wp:paragraph -->'
			. $value_markup
			. '</div><!-- /wp:group -->';
	}

	private static function single_repo_layout_markup() {
		$ratings = self::ratings_block( 'lagr-single-rating' );

		// Related repos as a clearly separated section (own divider + spacing),
		// not just another block bumping up against the columns above.
		$related = self::related_posts_block();
		if ( '' !== $related ) {
			$related = '<!-- wp:group {"className":"lagr-related-section","style":{"spacing":{"margin":{"top":"var:preset|spacing|70"},"padding":{"top":"var:preset|spacing|60"}},"border":{"top":{"color":"var:preset|color|light","width":"1px"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group lagr-related-section" style="border-top-color:var(--wp--preset--color--light);border-top-width:1px;margin-top:var(--wp--preset--spacing--70);padding-top:var(--wp--preset--spacing--60)">' . $related . '</div>
<!-- /wp:group -->';
		}

		$breadcrumbs = '<!-- wp:lagr/repo-breadcrumbs /-->';

		// Hero: identity + one-line description + topics + rating + primary CTAs.
		$hero = '<!-- wp:group {"className":"lagr-single-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-group lagr-single-hero">

<!-- wp:group {"className":"lagr-single-id","layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group lagr-single-id">
<!-- wp:image {"width":56,"height":56,"className":"lagr-single-avatar","metadata":{"bindings":{"url":{"source":"core/post-meta","args":{"key":"_gh_owner_avatar"}}}}} -->
<figure class="wp-block-image is-resized lagr-single-avatar"><img src="" alt="" width="56" height="56"/></figure>
<!-- /wp:image -->
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:post-title {"level":1} /-->
' . self::bound_paragraph( '_gh_owner_login', 'owner' ) . '
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

' . self::bound_paragraph( 'short_description', 'Описание репозитория', 'medium', 'lagr-single-desc' ) . '

<!-- wp:post-terms {"term":"repo_topic","separator":" ","className":"lagr-topic-pill","fontSize":"small"} /-->

<!-- wp:group {"className":"lagr-single-bar","layout":{"type":"flex","justifyContent":"space-between","flexWrap":"wrap"}} -->
<div class="wp-block-group lagr-single-bar">
' . $ratings . '
<!-- wp:buttons {"className":"lagr-single-cta"} -->
<div class="wp-block-buttons lagr-single-cta">
<!-- wp:button {"metadata":{"bindings":{"url":{"source":"core/post-meta","args":{"key":"_gh_url"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noreferrer noopener">Открыть на GitHub →</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"is-style-outline","metadata":{"bindings":{"url":{"source":"core/post-meta","args":{"key":"_gh_homepage"}}}}} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#" target="_blank" rel="noreferrer noopener">Сайт проекта</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->';

		// Sidebar "О репозитории" card — all the facts in one structured place.
		$language_row = '';
		if ( LAGR_Settings::get( 'collect_language' ) ) {
			$language_row = self::about_row( 'Язык', '<!-- wp:post-terms {"term":"repo_language","className":"lagr-about-value lagr-plain-term","fontSize":"small"} /-->' );
		}
		$license_row = '';
		if ( LAGR_Settings::get( 'collect_license' ) ) {
			$license_row = self::about_row( 'Лицензия', '<!-- wp:post-terms {"term":"repo_license","className":"lagr-about-value lagr-plain-term","fontSize":"small"} /-->' );
		}

		$about = '<!-- wp:group {"className":"lagr-single-about","layout":{"type":"constrained"}} -->
<div class="wp-block-group lagr-single-about">
<!-- wp:heading {"level":3} --><h3>О репозитории</h3><!-- /wp:heading -->
' . $language_row
			. $license_row
			. self::about_row( 'Звёзды', self::bound_count_paragraph( '_gh_stars' ) )
			. self::about_row( 'Форки', self::bound_count_paragraph( '_gh_forks' ) )
			. self::about_row( 'Открытые issues', self::bound_count_paragraph( '_gh_open_issues' ) )
			. self::about_row( 'Контрибьюторы', self::bound_count_paragraph( '_gh_contributors_count' ) )
			. self::about_row( 'Создан', self::bound_date_paragraph( '_gh_created_at' ) )
			. self::about_row( 'Обновлён', self::bound_date_paragraph( '_gh_pushed_at' ) )
			. '
<!-- wp:heading {"level":4} --><h4>Теги</h4><!-- /wp:heading -->
<!-- wp:post-terms {"term":"post_tag","separator":" ","className":"lagr-tag-pill","fontSize":"small"} /-->
</div>
<!-- /wp:group -->';

		return $breadcrumbs . '

' . $hero . '

<!-- wp:columns -->
<div class="wp-block-columns">
<!-- wp:column {"width":"64%"} -->
<div class="wp-block-column" style="flex-basis:64%">
<!-- wp:lagr/repo-content /-->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"36%"} -->
<div class="wp-block-column" style="flex-basis:36%">
' . $about . '
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

' . $related . '';
	}
}
