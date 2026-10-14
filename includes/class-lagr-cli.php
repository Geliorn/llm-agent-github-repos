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

	/**
	 * Re-generate the description, FAQ and SEO meta of already collected repos
	 * with the current prompt (lead, features, use cases, 3 FAQ questions,
	 * meta title/description). One GitHub request (README) + one LLM request
	 * per repo. Hand-edited short_description / install_command are kept.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : Process a single repo post id instead of all.
	 *
	 * [--limit=<n>]
	 * : Process at most N repos. Default: all.
	 *
	 * [--missing]
	 * : Only repos that have no LLM FAQ yet (collected before 0.8.0).
	 *
	 * ## EXAMPLES
	 *     wp github-repos regenerate --post=123
	 *     wp github-repos regenerate --missing --limit=20
	 */
	public function regenerate( $args, $assoc_args ) {
		if ( ! LAGR_Settings::get( 'llm_enabled' ) ) {
			WP_CLI::error( 'Генерация LLM выключена в настройках плагина.' );
		}
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : -1;
		$single = isset( $assoc_args['post'] ) ? (int) $assoc_args['post'] : 0;

		if ( $single ) {
			$ids = array( $single );
		} else {
			$query = array(
				'post_type'      => 'github_repo',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_key'       => '_gh_full_name', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			);
			if ( isset( $assoc_args['missing'] ) ) {
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				$query['meta_query'] = array(
					'relation' => 'OR',
					array(
						'key'     => '_gh_faq_json',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_gh_faq_json',
						'value' => '',
					),
				);
			}
			$ids = get_posts( $query );
		}
		if ( empty( $ids ) ) {
			WP_CLI::warning( 'Нет репозиториев для обработки.' );
			return;
		}

		$client = new LAGR_GitHub_Client();
		$ok     = 0;
		$fail   = 0;
		foreach ( $ids as $id ) {
			$r = LAGR_Collector::regenerate_description( $id, $client );
			if ( $r['ok'] ) {
				++$ok;
				WP_CLI::log( sprintf( '#%d %s — %s', $id, get_post_meta( $id, '_gh_full_name', true ), $r['msg'] ) );
			} else {
				++$fail;
				WP_CLI::warning( sprintf( '#%d — %s', $id, $r['msg'] ) );
			}
			usleep( 200000 ); // 0.2s — gentle on the GitHub API.
		}
		WP_CLI::success( sprintf( 'Готово: %d обновлено, %d пропущено.', $ok, $fail ) );
	}
}

LAGR_CLI::register();
