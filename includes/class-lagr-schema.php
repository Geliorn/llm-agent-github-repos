<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-LD for a single repo page: SoftwareSourceCode (the repo itself),
 * FAQPage (the questions from LAGR_FAQ) and BreadcrumbList.
 *
 * With Yoast active, everything goes INTO Yoast's own @graph instead of a
 * second <script> beside it: two disconnected graphs describing one page
 * (two WebPage nodes, two breadcrumb trails) is exactly what the validators
 * flag. Yoast already emits WebPage and BreadcrumbList, so we only add the
 * SoftwareSourceCode and Question nodes, mark the WebPage as FAQPage (the
 * same way Yoast's own FAQ block does) and align its breadcrumb trail with
 * the visible one from lagr/repo-breadcrumbs. Without Yoast we print one
 * self-contained graph ourselves.
 */
class LAGR_Schema {

	public static function init() {
		add_filter( 'wpseo_schema_graph', array( __CLASS__, 'yoast_graph' ), 10, 2 );
		add_filter( 'wpseo_breadcrumb_links', array( __CLASS__, 'yoast_breadcrumbs' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_standalone' ), 30 );
	}

	private static function yoast_active() {
		return defined( 'WPSEO_VERSION' );
	}

	private static function current_repo_id() {
		if ( ! is_singular( 'github_repo' ) ) {
			return 0;
		}
		return (int) get_queried_object_id();
	}

	/**
	 * @param array  $graph   Yoast's schema pieces.
	 * @param object $context Yoast Meta_Tags_Context (canonical, main_schema_id).
	 */
	public static function yoast_graph( $graph, $context ) {
		$post_id = self::current_repo_id();
		if ( ! $post_id || ! is_array( $graph ) ) {
			return $graph;
		}

		$page_url = ! empty( $context->canonical ) ? $context->canonical : get_permalink( $post_id );
		$page_id  = ! empty( $context->main_schema_id ) ? $context->main_schema_id : $page_url;

		$software  = self::software_node( $post_id, $page_url );
		$questions = self::question_nodes( $post_id, $page_url );

		foreach ( $graph as &$piece ) {
			if ( ! is_array( $piece ) || ( $piece['@id'] ?? '' ) !== $page_id ) {
				continue;
			}
			$piece['about'] = array( '@id' => $software['@id'] );
			if ( ! empty( $questions ) ) {
				$types = (array) ( $piece['@type'] ?? 'WebPage' );
				if ( ! in_array( 'FAQPage', $types, true ) ) {
					$types[] = 'FAQPage';
				}
				$piece['@type']      = $types;
				$piece['mainEntity'] = array_map(
					static function ( $q ) {
						return array( '@id' => $q['@id'] );
					},
					$questions
				);
			}
		}
		unset( $piece );

		$graph[] = $software;
		foreach ( $questions as $q ) {
			$graph[] = $q;
		}
		return $graph;
	}

	/**
	 * Yoast's trail for a CPT single is Главная → (archive) → запись; ours on
	 * the page is Каталог → тема → репозиторий. Make Yoast's (which also feeds
	 * its BreadcrumbList) match: Главная → Каталог → тема → репозиторий.
	 */
	public static function yoast_breadcrumbs( $links ) {
		$post_id = self::current_repo_id();
		if ( ! $post_id || ! is_array( $links ) || count( $links ) < 2 ) {
			return $links;
		}
		$home = reset( $links );
		$self = end( $links );

		$trail = array( $home );
		foreach ( self::breadcrumb_trail( $post_id ) as $crumb ) {
			$trail[] = array(
				'url'  => $crumb['url'],
				'text' => $crumb['name'],
			);
		}
		$trail[] = $self;
		return $trail;
	}

	/** Without Yoast: one self-contained @graph in <head>. */
	public static function print_standalone() {
		$post_id = self::current_repo_id();
		if ( ! $post_id || self::yoast_active() ) {
			return;
		}

		$page_url = get_permalink( $post_id );
		$page_id  = $page_url . '#webpage';
		$software = self::software_node( $post_id, $page_url );
		$graph    = array( $software );

		$page = array(
			'@type' => 'WebPage',
			'@id'   => $page_id,
			'url'   => $page_url,
			'name'  => get_the_title( $post_id ),
			'about' => array( '@id' => $software['@id'] ),
		);
		$questions = self::question_nodes( $post_id, $page_url );
		if ( ! empty( $questions ) ) {
			$page['@type']      = array( 'WebPage', 'FAQPage' );
			$page['mainEntity'] = $questions;
		}
		$graph[] = $page;

		$position = 0;
		$items    = array();
		$trail    = array_merge(
			array(
				array(
					'name' => 'Главная',
					'url'  => home_url( '/' ),
				),
			),
			self::breadcrumb_trail( $post_id ),
			array(
				array(
					'name' => get_the_title( $post_id ),
					'url'  => $page_url,
				),
			)
		);
		foreach ( $trail as $crumb ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => ++$position,
				'name'     => $crumb['name'],
				'item'     => $crumb['url'],
			);
		}
		$graph[] = array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $page_url . '#breadcrumb',
			'itemListElement' => $items,
		);

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
		);
		echo '<script type="application/ld+json" class="lagr-schema">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with JSON_HEX_TAG, cannot close the script tag.
	}

	/** Каталог → тема — the middle of the trail, same as lagr/repo-breadcrumbs. */
	private static function breadcrumb_trail( $post_id ) {
		$trail = array();
		$repos = get_post_type_archive_link( 'github_repo' );
		if ( $repos ) {
			$trail[] = array(
				'name' => 'Каталог',
				'url'  => $repos,
			);
		}
		$topics = get_the_terms( $post_id, 'repo_topic' );
		if ( $topics && ! is_wp_error( $topics ) ) {
			$link = get_term_link( $topics[0] );
			if ( ! is_wp_error( $link ) ) {
				$trail[] = array(
					'name' => $topics[0]->name,
					'url'  => $link,
				);
			}
		}
		return $trail;
	}

	private static function software_node( $post_id, $page_url ) {
		$meta = static function ( $key ) use ( $post_id ) {
			return trim( (string) get_post_meta( $post_id, $key, true ) );
		};

		$node = array(
			'@type'          => 'SoftwareSourceCode',
			'@id'            => $page_url . '#software',
			'name'           => LAGR_FAQ::repo_name( $post_id ),
			'description'    => $meta( 'short_description' ),
			'url'            => $page_url,
			// Linked from the WebPage via "about", not mainEntityOfPage: the
			// page's mainEntity is the FAQ list, and two main entities conflict.
			'codeRepository' => $meta( '_gh_url' ),
		);

		$language = $meta( '_gh_language' );
		if ( '' !== $language ) {
			$node['programmingLanguage'] = $language;
		}

		// GitHub reports unrecognised licenses as NOASSERTION — not a license.
		$license = $meta( '_gh_license' );
		if ( '' !== $license && 'NOASSERTION' !== $license ) {
			$node['license'] = 'https://spdx.org/licenses/' . rawurlencode( $license ) . '.html';
		}

		$owner = $meta( '_gh_owner_login' );
		if ( '' !== $owner ) {
			$node['author'] = array(
				'@type' => 'Organization' === $meta( '_gh_owner_type' ) ? 'Organization' : 'Person',
				'name'  => $owner,
				'url'   => 'https://github.com/' . rawurlencode( $owner ),
			);
		}

		$created = $meta( '_gh_created_at' );
		if ( '' !== $created ) {
			$node['dateCreated'] = $created;
		}
		$pushed = $meta( '_gh_pushed_at' );
		if ( '' !== $pushed ) {
			$node['dateModified'] = $pushed;
		}

		$release = $meta( '_gh_latest_release_tag' );
		if ( '' !== $release ) {
			$node['version'] = $release;
		}

		$homepage = $meta( '_gh_homepage' );
		if ( '' !== $homepage ) {
			$node['sameAs'] = array( $homepage );
		}

		$keywords = array();
		foreach ( array( 'repo_topic', LAGR_Settings::get( 'extra_topics_taxonomy', 'post_tag' ) ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_the_terms( $post_id, $tax );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$keywords = array_merge( $keywords, wp_list_pluck( $terms, 'name' ) );
			}
		}
		if ( ! empty( $keywords ) ) {
			$node['keywords'] = implode( ', ', array_unique( $keywords ) );
		}

		return array_filter(
			$node,
			static function ( $v ) {
				return '' !== $v && array() !== $v;
			}
		);
	}

	private static function question_nodes( $post_id, $page_url ) {
		$lang  = get_bloginfo( 'language' );
		$nodes = array();
		foreach ( LAGR_FAQ::items( $post_id ) as $i => $item ) {
			$nodes[] = array(
				'@type'          => 'Question',
				'@id'            => $page_url . '#faq-' . ( $i + 1 ),
				'position'       => $i + 1,
				'url'            => $page_url . '#faq-' . ( $i + 1 ),
				'name'           => $item['question'],
				'answerCount'    => 1,
				'acceptedAnswer' => array(
					'@type'      => 'Answer',
					'text'       => $item['answer_text'],
					'inLanguage' => $lang,
				),
				'inLanguage'     => $lang,
			);
		}
		return $nodes;
	}
}
