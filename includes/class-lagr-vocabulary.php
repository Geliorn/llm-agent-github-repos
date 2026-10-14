<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Task vocabulary for post_tag — the site-wide "what problem does it solve"
 * axis shared by repos, articles and contractors (docs/tag-mapping-plan.md).
 *
 * Term statuses live in term meta:
 *  - approved  — public page, cloud, sitemap;
 *  - candidate — attached to records but invisible on the front end (noindex);
 *  - merged    — invisible, kept only to 301 to its target.
 * A post_tag term with no status at all is a pre-migration legacy term and is
 * treated as visible, so the site looks exactly as before until the migration.
 *
 * Enforcement is global and needs no cooperation from other plugins: any new
 * post_tag from any writer is stamped `candidate` (created_term), listing
 * queries on the front end only return visible terms, candidate archives are
 * noindex. Writers that want proper mapping call resolve()/assign().
 */
class LAGR_Vocabulary {

	const TAXONOMY         = 'post_tag';
	const SECTION_TAXONOMY = 'repo_topic';

	const STATUS_APPROVED  = 'approved';
	const STATUS_CANDIDATE = 'candidate';
	const STATUS_MERGED    = 'merged';

	const META_STATUS           = '_tag_status';
	const META_HASH             = '_tag_hash';
	const META_DEFINITION       = '_tag_definition';
	const META_ALIASES          = '_tag_aliases';
	const META_ALIAS_CANDIDATES = '_tag_alias_candidates';
	const META_PROPOSAL         = '_tag_proposal';
	const META_FIRST_SEEN       = '_tag_first_seen';
	const META_PROPOSED_COUNT   = '_tag_proposed_count';
	const META_REVIEWED_AT      = '_tag_reviewed_at';
	const META_MERGED_INTO      = '_tag_merged_into';
	const META_MERGED_INTO_TAX  = '_tag_merged_into_tax';
	const META_WAS_POPULATED    = '_tag_was_populated';
	const META_PRIORITY         = '_tag_priority';
	const META_GROUP            = '_tag_group';
	const META_BLOCKED          = '_tag_blocked';

	const BLOCKLIST_OPTION = 'lagr_tag_blocklist';
	const DAY_LIMIT_OPTION = 'lagr_tag_candidates_day';
	const STATS_OPTION     = 'lagr_tag_resolve_stats';
	const SEEDED_OPTION    = 'lagr_tag_vocabulary_seeded';

	/** @var array|null Lazily built hash => term_id index of approved terms (names, slugs, aliases). */
	private static $index = null;

	/** @var array|null hash => repo_topic term_id (section names, slugs, aliases). */
	private static $section_index = null;

	/** @var int New candidates created in the current collector run. */
	private static $run_created = 0;

	/** @var bool Set while this class itself inserts terms, so created_term doesn't re-stamp. */
	private static $inserting = false;

	/** @var int[] Blocklisted terms some other writer created this request — removed on shutdown. */
	private static $blocked_new = array();

	public static function init() {
		add_action( 'created_term', array( __CLASS__, 'on_created_term' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'flush_index' ) );
		add_action( 'delete_term', array( __CLASS__, 'flush_index' ) );

		// Invisibility — explicit points, see plan section 4.
		add_filter( 'get_terms_args', array( __CLASS__, 'filter_listing_args' ), 10, 2 );
		add_filter( 'get_the_terms', array( __CLASS__, 'filter_post_terms' ), 10, 3 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'filter_sitemap_args' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_merged' ), 1 );

		// llm-agent-articles extension points (no hard dependency either way).
		add_filter( 'laa_resolve_tags', array( __CLASS__, 'filter_article_tags' ), 10, 4 );
		add_filter( 'laa_tag_vocabulary', array( __CLASS__, 'filter_article_vocabulary' ) );
	}

	// ------------------------------------------------------------ normalise

	/** Case, punctuation and whitespace don't make "AI-Agents" and "ai agents" two terms. */
	public static function normalize( $name ) {
		$norm = mb_strtolower( trim( wp_specialchars_decode( (string) $name ) ) );
		$norm = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $norm );
		return trim( preg_replace( '/\s+/u', ' ', $norm ) );
	}

	public static function hash( $name ) {
		return md5( self::normalize( $name ) );
	}

	/** Latin slug for a (possibly Cyrillic) proposed name — URLs stay readable. */
	public static function slugify( $name ) {
		static $map = array(
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z',
			'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
			'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
			'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
		);
		return sanitize_title( strtr( self::normalize( $name ), $map ) );
	}

	// ---------------------------------------------------------------- status

	public static function status( $term_id ) {
		return (string) get_term_meta( (int) $term_id, self::META_STATUS, true );
	}

	public static function is_approved( $term_id ) {
		return self::STATUS_APPROVED === self::status( $term_id );
	}

	/** Hidden on the front end: candidates and merged. Legacy (no status) stays visible. */
	public static function is_hidden( $term_id ) {
		return in_array( self::status( $term_id ), array( self::STATUS_CANDIDATE, self::STATUS_MERGED ), true );
	}

	public static function set_status( $term_id, $status ) {
		update_term_meta( (int) $term_id, self::META_STATUS, $status );
		self::flush_index();
	}

	/** @return WP_Term[] */
	public static function terms_by_status( $status, array $extra = array() ) {
		$terms = get_terms(
			array_merge(
				array(
					'taxonomy'        => self::TAXONOMY,
					'hide_empty'      => false,
					'meta_key'        => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'      => $status, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'lagr_unfiltered' => true,
				),
				$extra
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/** @return WP_Term[] */
	public static function approved_terms() {
		return self::terms_by_status( self::STATUS_APPROVED, array( 'orderby' => 'name' ) );
	}

	/** slug => "Name: definition" for every approved term, grouped — the prompt vocabulary. */
	public static function definitions() {
		$groups = array();
		foreach ( self::approved_terms() as $term ) {
			$def = (string) get_term_meta( $term->term_id, self::META_DEFINITION, true );
			$groups[ (string) get_term_meta( $term->term_id, self::META_GROUP, true ) ][ $term->slug ] = $term->name . ( '' !== $def ? ': ' . $def : '' );
		}
		return $groups ? array_merge( ...array_values( $groups ) ) : array();
	}

	public static function aliases( $term_id ) {
		$a = get_term_meta( (int) $term_id, self::META_ALIASES, true );
		return is_array( $a ) ? array_values( $a ) : array();
	}

	/** Adds aliases (raw strings, deduped by hash). Returns the ones actually added. */
	public static function add_aliases( $term_id, array $strings ) {
		$current = self::aliases( $term_id );
		$seen    = array();
		foreach ( $current as $a ) {
			$seen[ self::hash( $a ) ] = true;
		}
		$added = array();
		foreach ( $strings as $s ) {
			$s = trim( (string) $s );
			$h = self::hash( $s );
			if ( '' === self::normalize( $s ) || isset( $seen[ $h ] ) ) {
				continue;
			}
			$seen[ $h ] = true;
			$current[]  = $s;
			$added[]    = $s;
		}
		if ( $added ) {
			update_term_meta( (int) $term_id, self::META_ALIASES, $current );
			self::flush_index();
		}
		return $added;
	}

	// --------------------------------------------------------------- indexes

	public static function flush_index() {
		self::$index         = null;
		self::$section_index = null;
	}

	/** hash => term_id over approved names, slugs and aliases. ~400 entries — cheap per request. */
	private static function index() {
		if ( null === self::$index ) {
			self::$index = array();
			foreach ( self::approved_terms() as $term ) {
				foreach ( array_merge( array( $term->name, $term->slug ), self::aliases( $term->term_id ) ) as $s ) {
					$h = self::hash( $s );
					if ( ! isset( self::$index[ $h ] ) ) {
						self::$index[ $h ] = (int) $term->term_id;
					}
				}
			}
		}
		return self::$index;
	}

	/** hash => repo_topic term_id. Aliases of sections live in the same `_tag_aliases` key. */
	private static function section_index() {
		if ( null === self::$section_index ) {
			self::$section_index = array();
			if ( taxonomy_exists( self::SECTION_TAXONOMY ) ) {
				$terms = get_terms(
					array(
						'taxonomy'   => self::SECTION_TAXONOMY,
						'hide_empty' => false,
					)
				);
				foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
					foreach ( array_merge( array( $term->name, $term->slug ), self::aliases( $term->term_id ) ) as $s ) {
						self::$section_index[ self::hash( $s ) ] = (int) $term->term_id;
					}
				}
			}
		}
		return self::$section_index;
	}

	/** Approved term id a raw string resolves to, 0 if none. */
	public static function lookup( $raw ) {
		$index = self::index();
		return $index[ self::hash( $raw ) ] ?? 0;
	}

	/** repo_topic term id the string names (section axis), 0 if none. */
	public static function lookup_section( $raw ) {
		$index = self::section_index();
		return $index[ self::hash( $raw ) ] ?? 0;
	}

	/** Any non-approved post_tag term with this normalised name (candidate first). */
	public static function find_by_hash( $hash, $status = null ) {
		$args = array(
			'taxonomy'        => self::TAXONOMY,
			'hide_empty'      => false,
			'number'          => 1,
			'lagr_unfiltered' => true,
			'meta_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::META_HASH,
					'value' => $hash,
				),
			),
		);
		if ( $status ) {
			$args['meta_query'][] = array(
				'key'   => self::META_STATUS,
				'value' => $status,
			);
		}
		$terms = get_terms( $args );
		return ( ! is_wp_error( $terms ) && $terms ) ? $terms[0] : null;
	}

	// ------------------------------------------------------------- blocklist

	public static function blocklist() {
		$list = get_option( self::BLOCKLIST_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function is_blocked( $raw ) {
		return isset( self::blocklist()[ self::hash( $raw ) ] );
	}

	/** Stores hash => readable name (the name is only for the admin screen). */
	public static function block( $raw ) {
		$list                       = self::blocklist();
		$list[ self::hash( $raw ) ] = self::normalize( $raw );
		update_option( self::BLOCKLIST_OPTION, $list, false );
	}

	public static function unblock( $hash ) {
		$list = self::blocklist();
		unset( $list[ $hash ] );
		update_option( self::BLOCKLIST_OPTION, $list, false );
	}

	// ------------------------------------------------------------ resolve

	/** The collector calls this at the start of every rule run (per-run candidate limit). */
	public static function begin_run() {
		self::$run_created = 0;
	}

	/**
	 * Step 1 — deterministic resolution, no LLM (plan 5.1).
	 *
	 * @param string[] $raw_terms Raw strings (GitHub topics, model tags, editor input).
	 * @return array{term_ids:int[], unresolved:string[], sections:int[], dropped:string[]}
	 */
	public static function resolve( array $raw_terms, $post_id = 0, array $context = array() ) {
		$out  = array(
			'term_ids'   => array(),
			'unresolved' => array(),
			'sections'   => array(),
			'dropped'    => array(),
		);
		$seen = array();
		foreach ( $raw_terms as $raw ) {
			$raw = trim( (string) $raw );
			$h   = self::hash( $raw );
			if ( '' === self::normalize( $raw ) || isset( $seen[ $h ] ) ) {
				continue;
			}
			$seen[ $h ] = true;

			$section = self::lookup_section( $raw );
			if ( $section ) {
				$out['sections'][] = $section; // the section axis is set by the rule — no duplicate here
				continue;
			}
			if ( self::is_blocked( $raw ) ) {
				$out['dropped'][] = $raw;
				continue;
			}
			$term_id = self::lookup( $raw );
			if ( $term_id ) {
				$out['term_ids'][] = $term_id;
				continue;
			}
			$out['unresolved'][] = $raw;
		}
		$out['term_ids'] = array_values( array_unique( $out['term_ids'] ) );
		$out['sections'] = array_values( array_unique( $out['sections'] ) );
		return $out;
	}

	/**
	 * Steps 1–3 end to end for one post: deterministic mapping, validation of
	 * the model's answer in PHP, candidates, alias learning, assignment.
	 *
	 * @param array      $raw_terms Raw strings.
	 * @param array|null $llm       Parsed model block {tags[], mapped{raw:slug}, proposed[]} or null (no model ran).
	 * @param array      $context   source (repo|article|cli), unresolved_policy (candidate|drop), model, run_id.
	 * @return array Summary: assigned term ids, created candidates, learned aliases.
	 */
	public static function assign( $post_id, array $raw_terms, $llm = null, array $context = array() ) {
		$context = wp_parse_args(
			$context,
			array(
				'source'            => 'repo',
				'unresolved_policy' => LAGR_Settings::get( 'tags_unresolved_policy', 'candidate' ),
				'model'             => '',
				'run_id'            => '',
			)
		);

		$step1    = self::resolve( $raw_terms, $post_id, $context );
		$term_ids = $step1['term_ids'];
		$created  = array();
		$learned  = array();
		$handled  = array(); // hashes of unresolved strings the model dealt with

		if ( is_array( $llm ) ) {
			// tags — only approved terms survive; anything else is dropped, never created.
			foreach ( (array) ( $llm['tags'] ?? array() ) as $tag ) {
				$id = self::lookup( $tag );
				if ( $id ) {
					$term_ids[] = $id;
				}
			}

			// mapped — the "attach to existing" outcome. Learn it, so the next
			// repo with the same topic resolves at step 1 without the model.
			$unresolved_by_hash = array();
			foreach ( $step1['unresolved'] as $raw ) {
				$unresolved_by_hash[ self::hash( $raw ) ] = $raw;
			}
			foreach ( (array) ( $llm['mapped'] ?? array() ) as $raw => $target ) {
				$h  = self::hash( $raw );
				$id = self::lookup( $target );
				if ( ! $id || ! isset( $unresolved_by_hash[ $h ] ) ) {
					continue;
				}
				$term_ids[]    = $id;
				$handled[ $h ] = true;
				if ( self::observe_alias( $id, $unresolved_by_hash[ $h ] ) ) {
					$learned[] = $unresolved_by_hash[ $h ];
				}
			}

			foreach ( (array) ( $llm['proposed'] ?? array() ) as $p ) {
				if ( ! is_array( $p ) || empty( $p['tag'] ) ) {
					continue;
				}
				$id = self::propose( $p['tag'], $post_id, $p, $context );
				if ( $id > 0 ) {
					$term_ids[] = $id;
					if ( ! self::is_approved( $id ) ) {
						$created[] = $id;
					}
				}
				$handled[ self::hash( $p['tag'] ) ] = true;
			}
			// Unresolved strings the model neither mapped nor proposed: ignored (outcome 3).
		} elseif ( 'candidate' === $context['unresolved_policy'] ) {
			// No model ran: keep the raw signal as invisible candidates (limits apply).
			foreach ( $step1['unresolved'] as $raw ) {
				$id = self::propose( $raw, $post_id, array( 'why' => 'нет вызова модели — топик как есть' ), $context );
				if ( $id > 0 ) {
					$term_ids[] = $id;
					$created[]  = $id;
				}
			}
		}

		$term_ids = array_values( array_unique( array_filter( array_map( 'intval', $term_ids ) ) ) );
		if ( $post_id && $term_ids ) {
			wp_set_object_terms( (int) $post_id, $term_ids, self::TAXONOMY, true );
			foreach ( $term_ids as $id ) {
				if ( self::is_approved( $id ) ) {
					update_term_meta( $id, self::META_WAS_POPULATED, 1 );
				}
			}
		}

		self::record_stats( empty( $step1['unresolved'] ) );

		return array(
			'term_ids'   => $term_ids,
			'unresolved' => $step1['unresolved'],
			'created'    => array_values( array_unique( $created ) ),
			'learned'    => $learned,
			'sections'   => $step1['sections'],
		);
	}

	/**
	 * A proposed tag: approved/alias match → that term; existing candidate →
	 * proposed_count++; otherwise a new candidate (within limits).
	 *
	 * @return int Term id, 0 when nothing was attached.
	 */
	public static function propose( $name, $post_id, array $proposal = array(), array $context = array() ) {
		$name = sanitize_text_field( (string) $name );
		if ( '' === self::normalize( $name ) || self::is_blocked( $name ) || self::lookup_section( $name ) ) {
			return 0;
		}
		$id = self::lookup( $name );
		if ( $id ) {
			return $id;
		}

		$h        = self::hash( $name );
		$existing = self::find_by_hash( $h, self::STATUS_CANDIDATE );
		if ( $existing ) {
			update_term_meta( $existing->term_id, self::META_PROPOSED_COUNT, 1 + (int) get_term_meta( $existing->term_id, self::META_PROPOSED_COUNT, true ) );
			return (int) $existing->term_id;
		}
		if ( self::find_by_hash( $h, self::STATUS_MERGED ) ) {
			return 0; // a merged name should have become an alias; don't resurrect it
		}

		if ( ! self::within_limits() ) {
			LAGR_Log::add( sprintf( 'Теги: лимит новых кандидатов исчерпан — «%s» не создан', $name ) );
			return 0;
		}

		$id = self::insert_term( $name, self::STATUS_CANDIDATE );
		if ( ! $id ) {
			return 0;
		}
		update_term_meta(
			$id,
			self::META_PROPOSAL,
			array(
				'closest_existing' => sanitize_text_field( (string) ( $proposal['closest_existing'] ?? '' ) ),
				'why'              => sanitize_text_field( (string) ( $proposal['why'] ?? '' ) ),
				'model'            => sanitize_text_field( (string) ( $context['model'] ?? '' ) ),
				'run_id'           => sanitize_text_field( (string) ( $context['run_id'] ?? '' ) ),
				'source'           => sanitize_key( (string) ( $context['source'] ?? '' ) ),
				'post_id'          => (int) $post_id,
			)
		);
		update_term_meta( $id, self::META_PROPOSED_COUNT, 1 );
		++self::$run_created;
		self::bump_day_counter();
		return $id;
	}

	/**
	 * Insert a post_tag term with a Latin slug and the given status. If the
	 * slug is taken by a term with a different name, falls back to WP's own
	 * unique-slug suffixing.
	 */
	public static function insert_term( $name, $status, array $args = array() ) {
		self::$inserting = true;
		try {
			$args   = wp_parse_args( $args, array( 'slug' => self::slugify( $name ) ) );
			$result = wp_insert_term( $name, self::TAXONOMY, $args );
			if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() ) {
				unset( $args['slug'] );
				$result = wp_insert_term( $name, self::TAXONOMY, $args );
			}
		} finally {
			self::$inserting = false;
		}
		if ( is_wp_error( $result ) ) {
			LAGR_Log::add( sprintf( 'Теги: не удалось создать «%s» — %s', $name, $result->get_error_message() ) );
			return 0;
		}
		$id = (int) $result['term_id'];
		update_term_meta( $id, self::META_STATUS, $status );
		update_term_meta( $id, self::META_HASH, self::hash( $name ) );
		update_term_meta( $id, self::META_FIRST_SEEN, time() );
		self::flush_index();
		return $id;
	}

	/** Counts a model mapping; at the threshold the string becomes a real alias (plan 5.4). */
	private static function observe_alias( $term_id, $raw ) {
		$threshold = max( 1, (int) LAGR_Settings::get( 'tags_alias_threshold', 3 ) );
		$counts    = get_term_meta( $term_id, self::META_ALIAS_CANDIDATES, true );
		$counts    = is_array( $counts ) ? $counts : array();
		$key       = self::normalize( $raw );
		$counts[ $key ] = 1 + (int) ( $counts[ $key ] ?? 0 );

		$promoted = false;
		if ( $counts[ $key ] >= $threshold ) {
			unset( $counts[ $key ] );
			if ( self::add_aliases( $term_id, array( $raw ) ) ) {
				$term = get_term( $term_id );
				LAGR_Log::add( sprintf( 'Теги: «%s» стал алиасом «%s» (%d совпадений модели)', $raw, $term ? $term->name : $term_id, $threshold ) );
				$promoted = true;
			}
		}
		update_term_meta( $term_id, self::META_ALIAS_CANDIDATES, $counts );
		return $promoted;
	}

	private static function within_limits() {
		$per_run = (int) LAGR_Settings::get( 'tags_candidates_per_run', 20 );
		$per_day = (int) LAGR_Settings::get( 'tags_candidates_per_day', 100 );
		if ( $per_run > 0 && self::$run_created >= $per_run ) {
			return false;
		}
		if ( $per_day > 0 ) {
			$day = get_option( self::DAY_LIMIT_OPTION, array() );
			if ( ( $day['date'] ?? '' ) === gmdate( 'Y-m-d' ) && (int) ( $day['count'] ?? 0 ) >= $per_day ) {
				return false;
			}
		}
		return true;
	}

	private static function bump_day_counter() {
		$day   = get_option( self::DAY_LIMIT_OPTION, array() );
		$today = gmdate( 'Y-m-d' );
		$count = ( ( $day['date'] ?? '' ) === $today ) ? (int) $day['count'] + 1 : 1;
		update_option(
			self::DAY_LIMIT_OPTION,
			array(
				'date'  => $today,
				'count' => $count,
			),
			false
		);
	}

	/** Share of records resolved at step 1 only, per ISO week — the "is it learning" metric. */
	private static function record_stats( $step1_only ) {
		$stats = get_option( self::STATS_OPTION, array() );
		$week  = gmdate( 'o-\WW' );
		if ( ! isset( $stats[ $week ] ) ) {
			$stats[ $week ] = array(
				'step1' => 0,
				'total' => 0,
			);
		}
		++$stats[ $week ]['total'];
		if ( $step1_only ) {
			++$stats[ $week ]['step1'];
		}
		update_option( self::STATS_OPTION, array_slice( $stats, -12, null, true ), false );
	}

	public static function stats() {
		$stats = get_option( self::STATS_OPTION, array() );
		return is_array( $stats ) ? $stats : array();
	}

	// ------------------------------------------------------------ prompt

	/**
	 * Vocabulary block appended to any description prompt (built-in or the
	 * admin's own) — definitions are part of the prompt: without them the
	 * model matches by string similarity, which is what produced 3004 tags.
	 */
	public static function prompt_block( array $unresolved = array() ) {
		$defs = self::definitions();
		if ( ! $defs ) {
			return '';
		}
		$lines = array();
		foreach ( $defs as $slug => $text ) {
			$lines[] = '- ' . $slug . ' — ' . $text;
		}

		$block  = "\n\nТЕГИ ЗАДАЧ. Поле tags из инструкции выше (если оно там есть) заменяется этими правилами.\n";
		$block .= "Тег отвечает на вопрос «какую задачу решает инструмент» — не язык, не платформа, не продукт, не класс инструмента (MCP, AI-агент, Agent Skills — это разделы, не теги).\n";
		$block .= "Словарь (слаг — термин: определение):\n" . implode( "\n", $lines ) . "\n\n";
		$block .= "Добавь в JSON-ответ поля:\n";
		$block .= "  \"tags\": [\"1-4 слага ТОЛЬКО из словаря\"],\n";
		$block .= "  \"mapped\": {\"нераспознанная строка\": \"слаг из словаря\"},\n";
		$block .= "  \"proposed\": [{\"tag\": \"новая задача по-русски\", \"closest_existing\": \"ближайший слаг\", \"why\": \"почему ближайший не подходит\"}]\n";
		if ( $unresolved ) {
			$block .= 'Нераспознанные строки (авторские topics, которых нет в словаре): ' . implode( ', ', array_map( 'sanitize_text_field', $unresolved ) ) . "\n";
			$block .= "Для каждой: если смысл совпадает с термином словаря — укажи в mapped; если это язык, платформа, продукт или слишком общее слово — пропусти.\n";
		}
		$block .= "proposed — только если задача реально не покрыта словарём; closest_existing и why обязательны. Чаще всего proposed пустой.";
		return $block;
	}

	/** Pulls the tag fields out of a parsed model answer, sanitised. */
	public static function llm_block_from( array $parsed ) {
		$mapped = array();
		foreach ( (array) ( $parsed['mapped'] ?? array() ) as $raw => $slug ) {
			if ( is_string( $raw ) && is_string( $slug ) ) {
				$mapped[ sanitize_text_field( $raw ) ] = sanitize_text_field( $slug );
			}
		}
		$proposed = array();
		foreach ( (array) ( $parsed['proposed'] ?? array() ) as $p ) {
			if ( is_array( $p ) && ! empty( $p['tag'] ) && is_string( $p['tag'] ) ) {
				$proposed[] = array(
					'tag'              => sanitize_text_field( $p['tag'] ),
					'closest_existing' => sanitize_text_field( (string) ( $p['closest_existing'] ?? '' ) ),
					'why'              => sanitize_text_field( (string) ( $p['why'] ?? '' ) ),
				);
			}
		}
		return array(
			'tags'     => array_values( array_filter( array_map( 'sanitize_text_field', array_filter( (array) ( $parsed['tags'] ?? array() ), 'is_string' ) ) ) ),
			'mapped'   => $mapped,
			'proposed' => array_slice( $proposed, 0, 3 ),
		);
	}

	// --------------------------------------------------------- enforcement

	/**
	 * Safety net (plan 5.5): every new post_tag from any writer starts as an
	 * invisible candidate. A blocklisted name is removed at shutdown — deleting
	 * inside created_term would leave the caller's wp_set_object_terms()
	 * relating a term that no longer exists.
	 */
	public static function on_created_term( $term_id, $tt_id, $taxonomy ) {
		if ( self::TAXONOMY !== $taxonomy || self::$inserting ) {
			return;
		}
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		update_term_meta( $term_id, self::META_STATUS, self::STATUS_CANDIDATE );
		update_term_meta( $term_id, self::META_HASH, self::hash( $term->name ) );
		update_term_meta( $term_id, self::META_FIRST_SEEN, time() );
		update_term_meta( $term_id, self::META_PROPOSED_COUNT, 1 );

		if ( self::is_blocked( $term->name ) ) {
			update_term_meta( $term_id, self::META_BLOCKED, 1 );
			if ( ! self::$blocked_new ) {
				add_action( 'shutdown', array( __CLASS__, 'purge_blocked_new' ) );
			}
			self::$blocked_new[] = (int) $term_id;
		}
	}

	public static function purge_blocked_new() {
		foreach ( self::$blocked_new as $id ) {
			wp_delete_term( $id, self::TAXONOMY );
		}
		self::$blocked_new = array();
	}

	/** The visibility clause: no status (legacy) or approved. */
	private static function visible_clause() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => self::META_STATUS,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => self::META_STATUS,
				'value' => self::STATUS_APPROVED,
			),
		);
	}

	/**
	 * Only listing queries are filtered (clouds, term grids, menus, tag-box
	 * autocomplete). Lookups (slug/name/include/object_ids) never are — the
	 * term cache they fill is shared with the admin, and core's own
	 * term_exists() goes through get_terms(): filtering it would make
	 * wp_set_object_terms() lose tags (plan 5.6). Cron/CLI are never filtered.
	 */
	private static function should_filter_listing( array $args ) {
		if ( ! empty( $args['lagr_unfiltered'] ) || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		foreach ( array( 'object_ids', 'slug', 'name', 'include', 'term_taxonomy_id', 'name__like' ) as $key ) {
			if ( ! empty( $args[ $key ] ) && ! ( 'name__like' === $key && self::is_tag_search_ajax() ) ) {
				return false;
			}
		}
		if ( wp_doing_ajax() ) {
			return self::is_tag_search_ajax();
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return ! empty( $args['search'] ); // editor tag suggestions only
		}
		return ! is_admin();
	}

	private static function is_tag_search_ajax() {
		return wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'ajax-tag-search' === $_REQUEST['action']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function filter_listing_args( $args, $taxonomies ) {
		if ( ! in_array( self::TAXONOMY, (array) $taxonomies, true ) || ! self::should_filter_listing( (array) $args ) ) {
			return $args;
		}
		$clause             = self::visible_clause();
		$args['meta_query'] = empty( $args['meta_query'] ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			? array( $clause )
			: array(
				'relation' => 'AND',
				$args['meta_query'],
				$clause,
			);
		return $args;
	}

	/** Tag list on cards and single pages (core/post-terms) — display only, the cache is untouched. */
	public static function filter_post_terms( $terms, $post_id, $taxonomy ) {
		if ( self::TAXONOMY !== $taxonomy || ! is_array( $terms ) || ! $terms || ( is_admin() && ! wp_doing_ajax() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $terms;
		}
		update_termmeta_cache( wp_list_pluck( $terms, 'term_id' ) );
		return array_values(
			array_filter(
				$terms,
				function ( $t ) {
					return ! self::is_hidden( $t->term_id );
				}
			)
		);
	}

	public static function filter_sitemap_args( $args, $taxonomy ) {
		if ( self::TAXONOMY === $taxonomy ) {
			$args['meta_query'] = array( self::visible_clause() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return $args;
	}

	/** Candidate archive: noindex, not 404 — if approved tomorrow, the page just appears. */
	public static function filter_robots( $robots ) {
		if ( is_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term && self::is_hidden( $term->term_id ) ) {
				$robots['noindex']  = true;
				$robots['nofollow'] = true;
				unset( $robots['max-image-preview'] );
			}
		}
		return $robots;
	}

	/** Archive of a merged term → 301 to the final target (chains unwound, cycle-safe). */
	public static function maybe_redirect_merged() {
		if ( ! is_tag() ) {
			return;
		}
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term || self::STATUS_MERGED !== self::status( $term->term_id ) ) {
			return;
		}
		$target = LAGR_Tag_Queue::final_target( $term->term_id );
		if ( $target ) {
			$link = get_term_link( $target );
			if ( ! is_wp_error( $link ) ) {
				wp_safe_redirect( $link, 301, 'LAGR' );
				exit;
			}
		}
	}

	// ---------------------------------------------- llm-agent-articles hooks

	/**
	 * `laa_resolve_tags` (LAA_Publisher::apply_terms): returns term ids for the
	 * article. existing_only → approved matches only; llm → unknown tags become
	 * invisible candidates.
	 */
	public static function filter_article_tags( $resolved, $tags, $post_id, $mode = 'llm' ) {
		if ( null !== $resolved || ! is_array( $tags ) ) {
			return $resolved;
		}
		$result = self::assign(
			0,
			$tags,
			null,
			array(
				'source'            => 'article',
				'unresolved_policy' => ( 'existing_only' === $mode ) ? 'drop' : 'candidate',
			)
		);
		return $result['term_ids'];
	}

	/** `laa_tag_vocabulary`: the same definitions for the article outline prompt. */
	public static function filter_article_vocabulary( $block ) {
		$defs = self::definitions();
		if ( ! $defs ) {
			return $block;
		}
		$lines = array();
		foreach ( $defs as $slug => $text ) {
			$lines[] = '- ' . $slug . ' — ' . $text;
		}
		return "Теги статьи (поле tags) — 1-4 слага ТОЛЬКО из этого словаря задач (слаг — термин: определение). Тег отвечает на вопрос «какую задачу решает», а не называет технологию или продукт:\n" . implode( "\n", $lines );
	}

	// ----------------------------------------------------------------- seed

	/**
	 * Creates/updates the 84 approved terms from lagr-vocabulary-seed.php and
	 * puts section aliases on repo_topic. Idempotent; an existing term with
	 * the seed slug is converted in place (its records are kept). Never
	 * overwrites a definition or aliases the editor already changed — only
	 * missing aliases are added.
	 *
	 * @return array{created:int, updated:int, sections:int, conflicts:string[]}
	 */
	public static function seed( $dry_run = false ) {
		$seed   = self::seed_data();
		$report = array(
			'created'   => 0,
			'updated'   => 0,
			'sections'  => 0,
			'conflicts' => array(),
		);

		foreach ( $seed['sections'] as $slug => $aliases ) {
			$section = get_term_by( 'slug', $slug, self::SECTION_TAXONOMY );
			if ( ! $section ) {
				$report['conflicts'][] = sprintf( 'раздел repo_topic «%s» не найден — алиасы раздела не заведены', $slug );
				continue;
			}
			if ( ! $dry_run ) {
				self::add_aliases( $section->term_id, $aliases );
			}
			++$report['sections'];
		}
		self::flush_index();

		foreach ( $seed['groups'] as $group ) {
			foreach ( $group['terms'] as $row ) {
				list( $slug, $name, $definition, $aliases ) = $row;

				if ( self::lookup_section( $name ) || self::lookup_section( $slug ) ) {
					$report['conflicts'][] = sprintf( '«%s» совпадает с разделом repo_topic — пропущен', $slug );
					continue;
				}

				$term = get_term_by( 'slug', $slug, self::TAXONOMY );
				if ( $dry_run ) {
					$term ? ++$report['updated'] : ++$report['created'];
					continue;
				}

				$status = $term ? self::status( $term->term_id ) : '';
				if ( $term && '' !== $status && self::STATUS_APPROVED !== $status ) {
					// Merged or demoted by a human/the regulator — the seed never overrides that.
					$report['conflicts'][] = sprintf( '«%s» в статусе %s — пропущен', $slug, $status );
					continue;
				}

				if ( $term ) {
					$was_seeded = '' !== (string) get_term_meta( $term->term_id, self::META_DEFINITION, true );
					if ( ! $was_seeded && $term->name !== $name ) {
						// Legacy term that happens to own the slug: the old name becomes an alias.
						$aliases[] = $term->name;
						wp_update_term( $term->term_id, self::TAXONOMY, array( 'name' => $name ) );
					}
					$id = (int) $term->term_id;
					if ( ! $was_seeded ) {
						update_term_meta( $id, self::META_DEFINITION, $definition );
					}
					if ( ! $term->count ) {
						delete_term_meta( $id, self::META_WAS_POPULATED );
					}
					++$report['updated'];
				} else {
					$id = self::insert_term( $name, self::STATUS_APPROVED, array( 'slug' => $slug ) );
					if ( ! $id ) {
						$report['conflicts'][] = sprintf( '«%s» не создан', $slug );
						continue;
					}
					update_term_meta( $id, self::META_DEFINITION, $definition );
					++$report['created'];
				}
				update_term_meta( $id, self::META_STATUS, self::STATUS_APPROVED );
				update_term_meta( $id, self::META_HASH, self::hash( $name ) );
				update_term_meta( $id, self::META_GROUP, $group['group'] );
				if ( '' === (string) get_term_meta( $id, self::META_FIRST_SEEN, true ) ) {
					update_term_meta( $id, self::META_FIRST_SEEN, time() );
				}
				self::add_aliases( $id, $aliases );
			}
		}

		if ( ! $dry_run ) {
			update_option( self::SEEDED_OPTION, time(), false );
			self::flush_index();
			LAGR_Log::add( sprintf( 'Словарь задач засеян: создано %d, обновлено %d, разделов с алиасами %d', $report['created'], $report['updated'], $report['sections'] ) );
		}
		return $report;
	}

	public static function seed_data() {
		static $data = null;
		if ( null === $data ) {
			$data = require LAGR_PLUGIN_DIR . 'includes/lagr-vocabulary-seed.php';
		}
		return $data;
	}

	public static function is_seeded() {
		return (bool) get_option( self::SEEDED_OPTION );
	}

	/** "N разделов · M задач" for the catalog stats plaque (theme's Agent_Catalog_Catalog_Stats). */
	public static function counts() {
		$sections = taxonomy_exists( self::SECTION_TAXONOMY ) ? (int) wp_count_terms( array( 'taxonomy' => self::SECTION_TAXONOMY ) ) : 0;
		return array(
			'sections'   => $sections,
			'approved'   => count( self::approved_terms() ),
			'candidates' => count( self::terms_by_status( self::STATUS_CANDIDATE, array( 'fields' => 'ids' ) ) ),
		);
	}
}
