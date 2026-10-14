<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional WP-CLI surface (section 12.1, option 3 in the plan) — useful on
 * hosts where SSH is available, but no longer the required/primary path.
 */
class LAGR_CLI {

	public static function register() {
		WP_CLI::add_command( 'github-repos', __CLASS__ );
	}

	/**
	 * Run the tick once (same logic as WP-Cron / the HTTP endpoint).
	 *
	 * ## EXAMPLES
	 *     wp github-repos tick
	 */
	public function tick( $args, $assoc_args ) {
		LAGR_Scheduler::run_tick();
		WP_CLI::success( 'Tick complete.' );
	}

	/**
	 * Run one rule right now, ignoring its interval.
	 *
	 * ## OPTIONS
	 * --rule=<id>
	 * : Post ID of the github_repo_rule to run.
	 *
	 * ## EXAMPLES
	 *     wp github-repos run --rule=123
	 */
	public function run( $args, $assoc_args ) {
		$rule_id = isset( $assoc_args['rule'] ) ? (int) $assoc_args['rule'] : 0;
		if ( ! $rule_id || 'github_repo_rule' !== get_post_type( $rule_id ) ) {
			WP_CLI::error( 'Pass --rule=<post_id> of an existing github_repo_rule.' );
		}
		LAGR_Collector::run_rule( $rule_id );
		WP_CLI::success( 'Rule run complete — see the admin log for details.' );
	}

	/**
	 * List all rules with their enabled state and interval.
	 *
	 * ## EXAMPLES
	 *     wp github-repos list-rules
	 */
	public function list_rules( $args, $assoc_args ) {
		$rules = get_posts(
			array(
				'post_type'      => 'github_repo_rule',
				'posts_per_page' => -1,
			)
		);

		$items = array();
		foreach ( $rules as $r ) {
			$items[] = array(
				'ID'           => $r->ID,
				'title'        => $r->post_title,
				'enabled'      => get_post_meta( $r->ID, '_lagr_enabled', true ) ? 'yes' : 'no',
				'interval_min' => get_post_meta( $r->ID, '_lagr_interval_minutes', true ),
				'last_run'     => get_post_meta( $r->ID, '_lagr_last_run_at', true ) ? gmdate( 'Y-m-d H:i:s', (int) get_post_meta( $r->ID, '_lagr_last_run_at', true ) ) : '—',
			);
		}

		WP_CLI\Utils\format_items( 'table', $items, array( 'ID', 'title', 'enabled', 'interval_min', 'last_run' ) );
	}

	/**
	 * Re-render stored READMEs as clean GitHub HTML, and optionally translate.
	 *
	 * Cleans up repos collected before GitHub-HTML rendering (fixes broken
	 * links/code/tables) and/or backfills Yandex translations.
	 *
	 * ## OPTIONS
	 *
	 * [--translate]
	 * : Also translate via Yandex Cloud (needs the Yandex key/folder; forces it
	 *   on regardless of the "Переводить README" setting).
	 *
	 * [--only-translate]
	 * : Translate the already-stored HTML without re-fetching from GitHub.
	 *
	 * [--limit=<n>]
	 * : Process at most N repos. Default: all.
	 *
	 * [--post=<id>]
	 * : Process a single repo post id instead of all.
	 *
	 * ## EXAMPLES
	 *     wp github-repos rebuild_readmes
	 *     wp github-repos rebuild_readmes --translate
	 *     wp github-repos rebuild_readmes --only-translate --limit=10
	 */
	public function rebuild_readmes( $args, $assoc_args ) {
		$force_translate = isset( $assoc_args['translate'] ) || isset( $assoc_args['only-translate'] ) ? true : null;
		$only_translate  = isset( $assoc_args['only-translate'] );
		$limit           = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : -1;
		$single          = isset( $assoc_args['post'] ) ? (int) $assoc_args['post'] : 0;

		if ( $force_translate && ! LAGR_Translate::available() ) {
			WP_CLI::error( 'Перевод запрошен, но не заданы Yandex API Key / Folder ID в настройках плагина.' );
		}

		if ( $single ) {
			$ids = array( $single );
		} else {
			$ids = get_posts(
				array(
					'post_type'      => 'github_repo',
					'post_status'    => 'any',
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'meta_key'       => '_gh_full_name', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				)
			);
		}
		if ( empty( $ids ) ) {
			WP_CLI::warning( 'Нет репозиториев для обработки.' );
			return;
		}

		$client = $only_translate ? null : new LAGR_GitHub_Client();
		$ok     = 0;
		$fail   = 0;
		foreach ( $ids as $id ) {
			$r = LAGR_Collector::rebuild_post_readme( $id, $client, $force_translate, $only_translate );
			if ( $r['ok'] ) {
				++$ok;
				WP_CLI::log( sprintf( '#%d %s — %s', $id, get_post_meta( $id, '_gh_full_name', true ), $r['msg'] ) );
			} else {
				++$fail;
				WP_CLI::warning( sprintf( '#%d — %s', $id, $r['msg'] ) );
			}
			if ( ! $only_translate ) {
				usleep( 200000 ); // 0.2s — gentle on the GitHub API.
			}
		}
		WP_CLI::success( sprintf( 'Готово: %d обработано, %d пропущено.', $ok, $fail ) );
	}
}

/**
 * Task vocabulary (post_tag): seed, migration, regulator, queue operations.
 * `wp github-repos tags <command>`.
 */
class LAGR_Tags_CLI {

	public static function register() {
		WP_CLI::add_command( 'github-repos tags', __CLASS__ );
	}

	/**
	 * Create/update the 84 approved terms from lagr-vocabulary-seed.php. Idempotent.
	 *
	 * [--dry-run]
	 * : Only count.
	 *
	 * ## EXAMPLES
	 *     wp github-repos tags seed
	 */
	public function seed( $args, $assoc_args ) {
		$r = LAGR_Vocabulary::seed( isset( $assoc_args['dry-run'] ) );
		foreach ( $r['conflicts'] as $c ) {
			WP_CLI::warning( $c );
		}
		WP_CLI::success( sprintf( 'Создано %d, обновлено %d, разделов с алиасами %d.', $r['created'], $r['updated'], $r['sections'] ) );
	}

	/**
	 * Migrate the legacy post_tag pile. Dry-run unless --apply.
	 *
	 * [--apply]
	 * : Actually do it (irreversible).
	 *
	 * [--yes]
	 * : Skip the confirmation with --apply.
	 *
	 * ## EXAMPLES
	 *     wp github-repos tags migrate
	 *     wp github-repos tags migrate --apply
	 */
	public function migrate( $args, $assoc_args ) {
		$apply = isset( $assoc_args['apply'] );
		if ( $apply ) {
			WP_CLI::confirm( 'Миграция необратима. Применить?', $assoc_args );
		}
		$r = $apply ? LAGR_Tag_Migrate::apply() : LAGR_Tag_Migrate::plan();
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		$items = array();
		foreach ( LAGR_Tag_Migrate::report_rows( $r ) as $label => $b ) {
			$items[] = array(
				'what'     => $label,
				'terms'    => $b['terms'],
				'records'  => $b['records'],
				'examples' => implode( ', ', array_slice( $b['examples'], 0, 6 ) ),
			);
		}
		WP_CLI::log( sprintf( 'Старых меток без статуса: %d', $r['legacy_total'] ) );
		WP_CLI\Utils\format_items( 'table', $items, array( 'what', 'terms', 'records', 'examples' ) );
		WP_CLI::log( sprintf( 'Словарь: создать/создано %d, обновить/обновлено %d', $r['seed']['created'] ?? 0, $r['seed']['updated'] ?? 0 ) );
		foreach ( array_merge( (array) ( $r['seed']['conflicts'] ?? array() ), $r['errors'] ) as $e ) {
			WP_CLI::warning( $e );
		}
		$apply ? WP_CLI::success( 'Миграция применена, регулятор включён.' ) : WP_CLI::success( 'Dry-run. Применить: --apply' );
	}

	/**
	 * Run the weekly regulator now, ignoring the weekly guard.
	 *
	 * ## EXAMPLES
	 *     wp github-repos tags regulate
	 */
	public function regulate( $args, $assoc_args ) {
		delete_transient( LAGR_Tag_Regulator::COOC_TRANSIENT );
		update_option( LAGR_Tag_Regulator::LAST_RUN_OPTION, time(), false );
		WP_CLI::success( LAGR_Tag_Regulator::summary_text( LAGR_Tag_Regulator::run() ) );
	}

	/**
	 * Re-tag existing records through the vocabulary: their current post_tag
	 * names (and repo topics) go through step 1; with --llm unresolved ones
	 * are shown to the model.
	 *
	 * [<id>...]
	 * : Post IDs. Default: all github_repo posts.
	 *
	 * [--llm]
	 * : Ask the model about strings step 1 couldn't map.
	 *
	 * [--limit=<n>]
	 * : At most N posts.
	 *
	 * ## EXAMPLES
	 *     wp github-repos tags resolve 123 456 --llm
	 */
	public function resolve( $args, $assoc_args ) {
		$ids = array_map( 'intval', $args );
		if ( ! $ids ) {
			$ids = get_posts(
				array(
					'post_type'      => 'github_repo',
					'post_status'    => 'any',
					'posts_per_page' => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : -1,
					'fields'         => 'ids',
				)
			);
		}
		$llm = isset( $assoc_args['llm'] ) ? new LAGR_LLM_Client() : null;
		LAGR_Vocabulary::begin_run();
		foreach ( $ids as $id ) {
			$names = wp_get_object_terms( $id, LAGR_Vocabulary::TAXONOMY, array( 'fields' => 'names' ) );
			$names = is_wp_error( $names ) ? array() : $names;
			$step1 = LAGR_Vocabulary::resolve( $names, $id );
			$block = null;
			if ( $llm && $step1['unresolved'] ) {
				$block = $llm->classify_tags(
					array(
						'full_name'   => get_post_meta( $id, '_gh_full_name', true ) ?: get_the_title( $id ),
						'description' => get_post_meta( $id, 'short_description', true ),
						'topics'      => $names,
						'readme'      => wp_strip_all_tags( get_post_field( 'post_content', $id ) ),
					),
					$step1['unresolved']
				);
			}
			$r = LAGR_Vocabulary::assign( $id, $names, $block, array( 'source' => 'cli', 'unresolved_policy' => 'drop' ) );
			WP_CLI::log( sprintf( '#%d: терминов %d, нераспознано %d, новых кандидатов %d, выучено алиасов %d', $id, count( $r['term_ids'] ), count( $r['unresolved'] ), count( $r['created'] ), count( $r['learned'] ) ) );
		}
		WP_CLI::success( sprintf( 'Обработано %d.', count( $ids ) ) );
	}

	/**
	 * Approve candidates.
	 *
	 * <id>...
	 * : Term IDs.
	 */
	public function approve( $args ) {
		foreach ( $args as $id ) {
			$r = LAGR_Tag_Queue::approve( (int) $id );
			is_wp_error( $r ) ? WP_CLI::warning( "#{$id}: " . $r->get_error_message() ) : WP_CLI::log( "#{$id}: утверждён" );
		}
	}

	/**
	 * Reject candidates (delete + blocklist).
	 *
	 * <id>...
	 * : Term IDs.
	 */
	public function reject( $args ) {
		foreach ( $args as $id ) {
			$r = LAGR_Tag_Queue::reject( (int) $id );
			is_wp_error( $r ) ? WP_CLI::warning( "#{$id}: " . $r->get_error_message() ) : WP_CLI::log( "#{$id}: отклонён" );
		}
	}

	/**
	 * Merge terms into an approved target. Shows the preview and asks first.
	 *
	 * <target>
	 * : Target term ID (approved).
	 *
	 * <source>...
	 * : Source term IDs.
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 */
	public function merge( $args, $assoc_args ) {
		$target = (int) array_shift( $args );
		foreach ( $args as $id ) {
			$p = LAGR_Tag_Queue::preview( (int) $id, $target );
			if ( is_wp_error( $p ) ) {
				WP_CLI::warning( "#{$id}: " . $p->get_error_message() );
				continue;
			}
			WP_CLI::log( sprintf( '«%s» → «%s»: переедет %d, уже несут %d, алиасы +%s, источник: %s', $p['source']->name, $p['target']->name, $p['move'], $p['already'], implode( ', ', $p['aliases'] ) ?: '—', $p['source_after'] ) );
		}
		WP_CLI::confirm( 'Склеить?', $assoc_args );
		$r = LAGR_Tag_Queue::merge_many( $args, $target );
		foreach ( $r['errors'] as $e ) {
			WP_CLI::warning( $e );
		}
		WP_CLI::success( sprintf( 'Склеено: %d.', $r['done'] ) );
	}

	/**
	 * Vocabulary counters.
	 */
	public function stats() {
		$c = LAGR_Vocabulary::counts();
		WP_CLI::log( sprintf( '%d разделов · %d задач · %d кандидатов · blocklist %d', $c['sections'], $c['approved'], $c['candidates'], count( LAGR_Vocabulary::blocklist() ) ) );
		foreach ( LAGR_Vocabulary::stats() as $week => $row ) {
			WP_CLI::log( sprintf( '%s: без модели %d из %d', $week, $row['step1'], $row['total'] ) );
		}
	}
}

LAGR_CLI::register();
LAGR_Tags_CLI::register();
