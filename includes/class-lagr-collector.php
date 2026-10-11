<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a single github_repo_rule end to end: search, filter, dedupe,
 * score, describe, create posts. Section 3, 6, 7, 8 of the plan.
 */
class LAGR_Collector {

	const STALE_LOCK_SECONDS = 30 * MINUTE_IN_SECONDS; // 3 ticks — see plan 12.4.

	public static function run_rule( $rule_id ) {
		$running_since = (int) get_post_meta( $rule_id, '_lagr_running_since', true );
		if ( $running_since && ( time() - $running_since ) < self::STALE_LOCK_SECONDS ) {
			LAGR_Log::add( "Правило #{$rule_id}: пропущено, предыдущий запуск ещё выполняется" );
			return;
		}

		update_post_meta( $rule_id, '_lagr_running_since', time() );
		try {
			self::do_run( $rule_id );
		} finally {
			update_post_meta( $rule_id, '_lagr_running_since', 0 );
			update_post_meta( $rule_id, '_lagr_last_run_at', time() );
		}
	}

	private static function do_run( $rule_id ) {
		$client = new LAGR_GitHub_Client();
		$title  = get_the_title( $rule_id );

		if ( $client->budget_exhausted() ) {
			LAGR_Log::add( "Правило #{$rule_id} ({$title}): пропущено — бюджет GitHub API исчерпан" );
			update_post_meta( $rule_id, '_lagr_last_run_summary', 'Пропущено: бюджет исчерпан' );
			return;
		}

		$query = LAGR_Query_Builder::build_from_rule( $rule_id );
		if ( empty( $query ) ) {
			LAGR_Log::add( "Правило #{$rule_id} ({$title}): пустой запрос, пропущено" );
			return;
		}

		$sort  = get_post_meta( $rule_id, '_lagr_sort_by', true ) ?: 'updated';
		$limit = (int) get_post_meta( $rule_id, '_lagr_limit', true ) ?: 100;

		$search = $client->search_repositories( $query, $sort, $limit );
		if ( ! $search['ok'] ) {
			LAGR_Log::add( "Правило #{$rule_id} ({$title}): ошибка поиска — " . $search['error'] );
			update_post_meta( $rule_id, '_lagr_last_run_summary', 'Ошибка: ' . $search['error'] );
			return;
		}

		$require_description = (bool) get_post_meta( $rule_id, '_lagr_require_description', true );
		$target_post_type    = get_post_meta( $rule_id, '_lagr_target_post_type', true ) ?: 'github_repo';
		$publish_mode        = get_post_meta( $rule_id, '_lagr_publish_mode', true ) ?: 'draft';
		$rule_topics         = array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $rule_id, '_lagr_topics', true ) ) ) );

		$created = 0;
		$flagged = 0;
		$skipped = 0;

		foreach ( $search['items'] as $item ) {
			if ( $require_description && empty( $item['description'] ) ) {
				++$skipped;
				continue;
			}

			$existing_id = self::find_existing_post( $item['full_name'], $target_post_type );
			if ( $existing_id ) {
				if ( self::flag_if_changed( $existing_id, $item ) ) {
					++$flagged;
				}
				continue;
			}

			if ( $client->budget_exhausted() ) {
				LAGR_Log::add( "Правило #{$rule_id} ({$title}): бюджет исчерпан на середине обработки" );
				break;
			}

			$post_id = self::create_repo_post( $client, $item, $rule_id, $rule_topics, $target_post_type, $publish_mode );
			if ( $post_id ) {
				++$created;
			}
		}

		$summary = sprintf(
			'Найдено %d, создано %d, помечено на проверку %d, пропущено %d',
			count( $search['items'] ),
			$created,
			$flagged,
			$skipped
		);
		update_post_meta( $rule_id, '_lagr_last_run_summary', $summary );
		LAGR_Log::add( "Правило #{$rule_id} ({$title}): {$summary}" );
	}

	private static function find_existing_post( $full_name, $post_type ) {
		$q = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'meta_key'       => '_gh_full_name',
				'meta_value'     => $full_name,
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return $q->posts[0] ?? null;
	}

	/** Non-destructive re-sync policy from the main TZ: flag, never overwrite silently. */
	private static function flag_if_changed( $post_id, $item ) {
		$old_stars = (int) get_post_meta( $post_id, '_gh_stars', true );
		$old_desc  = get_post_meta( $post_id, '_gh_source_description', true );
		$new_stars = (int) ( $item['stargazers_count'] ?? 0 );
		$new_desc  = $item['description'] ?? '';

		if ( $old_stars !== $new_stars || $old_desc !== $new_desc ) {
			update_post_meta( $post_id, '_gh_needs_review', 1 );
			return true;
		}
		return false;
	}

	private static function create_repo_post( LAGR_GitHub_Client $client, array $item, $rule_id, array $rule_topics, $target_post_type, $publish_mode ) {
		$full_name = $item['full_name'];
		$readme    = $client->get_readme( $full_name );

		$repo_data = array(
			'full_name'   => $full_name,
			'stars'       => (int) ( $item['stargazers_count'] ?? 0 ),
			'forks'       => (int) ( $item['forks_count'] ?? 0 ),
			'language'    => $item['language'] ?? '',
			'license'     => $item['license']['spdx_id'] ?? '',
			'homepage'    => $item['homepage'] ?? '',
			'description' => $item['description'] ?? '',
			'topics'      => $item['topics'] ?? array(),
			'readme'      => $readme,
		);

		$score_weight = (float) LAGR_Settings::get( 'score_forks_weight', 3 );
		$score        = $repo_data['stars'] + $repo_data['forks'] * $score_weight;

		// README is stored in its own meta regardless of the LLM path so the
		// single page can show it as a separate tab from the generated text —
		// previously it was only ever folded into post_content (and discarded
		// entirely when the LLM ran), leaving no way to show both. Rendered by
		// GitHub itself (correct links/code/tables), with an optional Yandex
		// translation kept in a sibling meta.
		$readme_html    = self::build_readme_html( $client, $full_name, $readme );
		$readme_html_tr = LAGR_Translate::enabled() ? LAGR_Translate::translate_html( $readme_html ) : '';

		$generated_by_llm = false;
		$generated_html   = '';
		$install_command  = '';
		$llm_result       = null;
		if ( (bool) LAGR_Settings::get( 'llm_enabled' ) ) {
			$llm        = new LAGR_LLM_Client();
			$llm_result = $llm->generate_descriptions( $repo_data );
		}

		if ( $llm_result ) {
			$short_description = $llm_result['short_description'];
			$generated_html    = $llm_result['full_description'];
			$extra_tags         = $llm_result['tags'];
			$install_command    = $llm_result['install_command'] ?? '';
			$generated_by_llm   = true;
		} else {
			$short_description = $repo_data['description']
				? mb_substr( $repo_data['description'], 0, 200 )
				: self::first_readme_line( $readme );
			$extra_tags         = array();
		}

		// Primary post_content stays "best available" (generated when present,
		// otherwise the rendered README) for SEO/excerpt and any template still
		// using core/post-content; the tabs read the two dedicated metas below.
		$full_description = $generated_html ? $generated_html : $readme_html;

		// Use the repo's own dates rather than "now" — core/post-date (incl. "modified")
		// and any date-based sorting/display should reflect GitHub reality, not collection time.
		$post_date     = ! empty( $item['created_at'] ) ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $item['created_at'] ) ) ) : current_time( 'mysql' );
		$post_date_gmt = ! empty( $item['created_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $item['created_at'] ) ) : current_time( 'mysql', true );
		$modified          = ! empty( $item['pushed_at'] ) ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $item['pushed_at'] ) ) ) : $post_date;
		$modified_gmt      = ! empty( $item['pushed_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( $item['pushed_at'] ) ) : $post_date_gmt;

		$post_id = wp_insert_post(
			array(
				'post_type'         => $target_post_type,
				'post_title'        => $full_name,
				'post_content'      => $full_description,
				'post_status'       => ( 'publish' === $publish_mode ) ? 'publish' : 'draft',
				'post_date'         => $post_date,
				'post_date_gmt'     => $post_date_gmt,
				'post_modified'     => $modified,
				'post_modified_gmt' => $modified_gmt,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return null;
		}

		update_post_meta( $post_id, '_gh_full_name', $full_name );
		update_post_meta( $post_id, '_gh_url', 'https://github.com/' . $full_name );
		update_post_meta( $post_id, '_gh_stars', $repo_data['stars'] );
		update_post_meta( $post_id, '_gh_forks', $repo_data['forks'] );
		update_post_meta( $post_id, '_gh_watchers', (int) ( $item['watchers_count'] ?? 0 ) );
		update_post_meta( $post_id, '_gh_open_issues', (int) ( $item['open_issues_count'] ?? 0 ) );
		update_post_meta( $post_id, '_gh_language', $repo_data['language'] );
		update_post_meta( $post_id, '_gh_homepage', $repo_data['homepage'] );
		update_post_meta( $post_id, '_gh_license', $repo_data['license'] );
		update_post_meta( $post_id, '_gh_created_at', $item['created_at'] ?? '' );
		update_post_meta( $post_id, '_gh_pushed_at', $item['pushed_at'] ?? '' );
		update_post_meta( $post_id, '_gh_updated_at', $item['updated_at'] ?? '' );
		update_post_meta( $post_id, '_gh_default_branch', $item['default_branch'] ?? '' );
		update_post_meta( $post_id, '_gh_size_kb', (int) ( $item['size'] ?? 0 ) );
		update_post_meta( $post_id, '_gh_owner_login', $item['owner']['login'] ?? '' );
		update_post_meta( $post_id, '_gh_owner_type', $item['owner']['type'] ?? '' );
		update_post_meta( $post_id, '_gh_owner_avatar', $item['owner']['avatar_url'] ?? '' );
		update_post_meta( $post_id, 'short_description', $short_description );
		update_post_meta( $post_id, '_gh_score', $score );
		update_post_meta( $post_id, '_gh_rule_id', $rule_id );
		update_post_meta( $post_id, '_gh_needs_review', 0 );
		update_post_meta( $post_id, '_gh_source_description', $repo_data['description'] );
		update_post_meta( $post_id, '_gh_generated_by_llm', $generated_by_llm ? 1 : 0 );
		update_post_meta( $post_id, '_gh_readme_html', $readme_html );
		update_post_meta( $post_id, '_gh_readme_html_tr', $readme_html_tr );
		update_post_meta( $post_id, '_gh_generated_html', $generated_html );
		update_post_meta( $post_id, 'install_command', $install_command );

		LAGR_SEO::apply_meta_description( $post_id, $short_description );

		if ( LAGR_Settings::get( 'optional_contributors' ) ) {
			$count = $client->get_contributors_count( $full_name );
			if ( null !== $count ) {
				update_post_meta( $post_id, '_gh_contributors_count', $count );
			}
		}
		if ( LAGR_Settings::get( 'optional_releases' ) ) {
			$release = $client->get_latest_release( $full_name );
			if ( $release ) {
				update_post_meta( $post_id, '_gh_latest_release_tag', $release['tag'] );
				update_post_meta( $post_id, '_gh_latest_release_at', $release['date'] );
			}
		}
		if ( LAGR_Settings::get( 'optional_languages' ) ) {
			update_post_meta( $post_id, '_gh_languages_json', wp_json_encode( $client->get_languages( $full_name ) ) );
		}
		if ( LAGR_Settings::get( 'optional_top_files' ) ) {
			update_post_meta( $post_id, '_gh_top_files_json', wp_json_encode( $client->get_top_level_contents( $full_name ) ) );
		}

		// Rule topic(s) — the curated vocabulary — always go to repo_topic.
		$rule_topics_clean = array_values( array_unique( array_filter( $rule_topics ) ) );
		if ( ! empty( $rule_topics_clean ) ) {
			wp_set_object_terms( $post_id, $rule_topics_clean, 'repo_topic' );
		}

		// Everything else — the repo's own GitHub topics not already covered by the
		// rule, plus any LLM-suggested tags — goes wherever the setting points
		// (post_tag by default; 'repo_topic' re-merges them; 'none' drops them).
		$extra_topics_taxonomy = LAGR_Settings::get( 'extra_topics_taxonomy', 'post_tag' );
		if ( 'none' !== $extra_topics_taxonomy && taxonomy_exists( $extra_topics_taxonomy ) ) {
			$extra_topics = array_values(
				array_unique(
					array_filter(
						array_diff( array_merge( $repo_data['topics'], $extra_tags ), $rule_topics_clean )
					)
				)
			);
			if ( ! empty( $extra_topics ) ) {
				wp_set_object_terms( $post_id, $extra_topics, $extra_topics_taxonomy, true );
			}
		}
		if ( $repo_data['language'] && LAGR_Settings::get( 'collect_language' ) ) {
			wp_set_object_terms( $post_id, array( $repo_data['language'] ), 'repo_language' );
		}
		if ( $repo_data['license'] && LAGR_Settings::get( 'collect_license' ) ) {
			wp_set_object_terms( $post_id, array( $repo_data['license'] ), 'repo_license' );
		}

		return $post_id;
	}

	private static function first_readme_line( $readme ) {
		foreach ( preg_split( '/\r?\n/', trim( (string) $readme ) ) as $line ) {
			$line = trim( strip_tags( $line ), "# \t" );
			if ( '' !== $line && 0 !== strpos( $line, '![' ) ) {
				return mb_substr( $line, 0, 200 );
			}
		}
		return '';
	}

	/**
	 * README as clean HTML: GitHub's own rendering when available (correct
	 * links, fenced/inline code, tables, task lists, reference-style links),
	 * otherwise the minimal fallback parser. Always sanitized before return.
	 */
	public static function build_readme_html( LAGR_GitHub_Client $client, $full_name, $raw_markdown = null ) {
		$html = $client->get_readme_html( $full_name );
		if ( '' !== $html ) {
			return self::sanitize_readme_html( $html );
		}
		if ( null === $raw_markdown ) {
			$raw_markdown = $client->get_readme( $full_name );
		}
		return self::markdown_to_html( $raw_markdown );
	}

	/**
	 * Sanitize GitHub-rendered README HTML. Starts from the core "post"
	 * allowlist (code, pre, tables, lists, headings, links, images, blockquotes)
	 * and adds the extras GitHub emits: task-list checkboxes, <details>/<summary>,
	 * and class/id on the code/highlight elements.
	 */
	public static function sanitize_readme_html( $html ) {
		$allowed            = wp_kses_allowed_html( 'post' );
		$allowed['input']   = array( 'type' => true, 'checked' => true, 'disabled' => true, 'class' => true );
		$allowed['details'] = array( 'class' => true, 'open' => true );
		$allowed['summary'] = array( 'class' => true );
		foreach ( array( 'span', 'code', 'pre', 'div', 'a', 'td', 'th', 'li', 'table' ) as $tag ) {
			if ( ! isset( $allowed[ $tag ] ) || ! is_array( $allowed[ $tag ] ) ) {
				$allowed[ $tag ] = array();
			}
			$allowed[ $tag ]['class'] = true;
			$allowed[ $tag ]['id']    = true;
		}
		return wp_kses( (string) $html, $allowed );
	}

	/**
	 * Re-fetch/re-render one repo's README and optionally (re)translate it,
	 * updating _gh_readme_html / _gh_readme_html_tr. Used by the WP-CLI backfill
	 * to clean up repos collected before GitHub-HTML rendering / translation.
	 *
	 * @param int                     $post_id
	 * @param LAGR_GitHub_Client|null $client         Reused across a batch; built if null.
	 * @param bool|null               $translate      null = follow the setting; true/false = force.
	 * @param bool                    $only_translate Translate the stored HTML without re-fetching.
	 * @return array{ok:bool,msg:string}
	 */
	public static function rebuild_post_readme( $post_id, $client = null, $translate = null, $only_translate = false ) {
		$full_name = (string) get_post_meta( $post_id, '_gh_full_name', true );
		if ( '' === $full_name ) {
			return array( 'ok' => false, 'msg' => 'нет _gh_full_name' );
		}

		if ( $only_translate ) {
			$readme_html = trim( (string) get_post_meta( $post_id, '_gh_readme_html', true ) );
		} else {
			if ( ! $client instanceof LAGR_GitHub_Client ) {
				$client = new LAGR_GitHub_Client();
			}
			$readme_html = self::build_readme_html( $client, $full_name );
			update_post_meta( $post_id, '_gh_readme_html', $readme_html );
		}

		$do_translate = ( null === $translate ) ? LAGR_Translate::enabled() : ( $translate && LAGR_Translate::available() );
		if ( $do_translate && '' !== $readme_html ) {
			$tr = LAGR_Translate::translate_html( $readme_html );
			if ( '' !== $tr ) {
				update_post_meta( $post_id, '_gh_readme_html_tr', $tr );
				return array( 'ok' => true, 'msg' => 'README + перевод' );
			}
			return array( 'ok' => true, 'msg' => 'README (перевод не удался — см. лог)' );
		}
		return array( 'ok' => true, 'msg' => 'README' );
	}

	/** Minimal, dependency-free Markdown → HTML for the no-LLM fallback path. */
	private static function markdown_to_html( $markdown ) {
		$text = (string) $markdown;
		$text = preg_replace( '/^\[!\[.*?\]\(.*?\)\]\(.*?\)\s*$/m', '', $text );        // linked badge images
		$text = preg_replace( '/^!\[.*?\]\(.*?\)\s*$/m', '', $text );                  // bare images
		$text = preg_replace( '/<picture\b[^>]*>.*?<\/picture>/is', '', $text );        // raw HTML logo blocks
		$text = preg_replace( '/<div\b[^>]*align=["\']center["\'][^>]*>.*?<\/div>/is', '', $text ); // centered logo wrappers
		$text = preg_replace( '/<img\b[^>]*>/i', '', $text );                          // any remaining raw <img> tags
		$text = preg_replace( '/<\/?(a|p|br|source)\b[^>]*>/i', '', $text );           // strip other loose raw HTML we don't render
		$text = esc_html( $text );
		$text = preg_replace( '/^#{4,6}\s*(.+)$/m', '<h4>$1</h4>', $text );
		$text = preg_replace( '/^###\s*(.+)$/m', '<h3>$1</h3>', $text );
		$text = preg_replace( '/^##\s*(.+)$/m', '<h2>$1</h2>', $text );
		$text = preg_replace( '/^#\s*(.+)$/m', '<h2>$1</h2>', $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/\[([^\]]+)\]\((https?:\/\/[^\)]+)\)/', '<a href="$2">$1</a>', $text );
		return wpautop( $text );
	}
}
