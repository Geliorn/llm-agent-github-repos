<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-off migration of the legacy post_tag pile (plan section 10). Dry-run by
 * default: plan() only counts; apply() is a separate, explicit action (admin
 * button or `wp github-repos tags migrate --apply`). Never runs on its own.
 *
 * Legacy = a post_tag term without `_tag_status`. Order:
 *  1. legacy terms with count = 0 — deleted (orphans of re-collection);
 *  2. the vocabulary is seeded (84 approved terms, section aliases);
 *  3. section names (mcp-server, ai-agents, skills…) — records get the
 *     repo_topic section if missing, the tag is kept as `merged` → 301 to the
 *     section archive;
 *  4. too-general names and languages/platforms — blocklisted and deleted;
 *  5. alias matches — records move to the canonical term; the old term stays
 *     `merged` (301): legacy tag pages were public and may have traffic;
 *  6. everything else (incl. products/ecosystems, decision of 2026-10-14) —
 *     candidate: records kept, out of navigation and the index.
 */
class LAGR_Tag_Migrate {

	const DONE_OPTION = 'lagr_tag_migrated';
	const LOCK        = 'lagr_tag_migrate_running';

	/** Dry run: what would happen, in numbers. */
	public static function plan() {
		return self::process( true );
	}

	public static function apply() {
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'lagr_locked', 'Миграция уже выполняется.' );
		}
		set_transient( self::LOCK, 1, HOUR_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		ignore_user_abort( true );
		try {
			$report = self::process( false );
		} finally {
			delete_transient( self::LOCK );
		}
		update_option( self::DONE_OPTION, time(), false );
		// The regulator is meant to start only after the migration — otherwise it
		// would diligently regulate the legacy pile itself.
		LAGR_Settings::update( array( 'tags_regulator_enabled' => 1 ) );
		LAGR_Log::add( 'Миграция тегов применена: ' . self::report_text( $report ) );
		return $report;
	}

	public static function is_done() {
		return (bool) get_option( self::DONE_OPTION );
	}

	private static function bucket() {
		return array(
			'terms'    => 0,
			'records'  => 0,
			'examples' => array(),
		);
	}

	private static function add( array &$report, $key, WP_Term $term ) {
		++$report[ $key ]['terms'];
		$report[ $key ]['records'] += (int) $term->count;
		if ( count( $report[ $key ]['examples'] ) < 12 ) {
			$report[ $key ]['examples'][] = $term->name . ' (' . (int) $term->count . ')';
		}
	}

	private static function process( $dry ) {
		$seed   = LAGR_Vocabulary::seed_data();
		$report = array(
			'legacy_total' => 0,
			'empty'        => self::bucket(),
			'sections'     => self::bucket(),
			'blocklist'    => self::bucket(),
			'tech'         => self::bucket(),
			'aliased'      => self::bucket(),
			'ecosystem'    => self::bucket(),
			'candidates'   => self::bucket(),
			'seed'         => array(),
			'section_adds' => 0,
			'errors'       => array(),
		);

		// Hash maps from the seed itself, so the dry run is right before seeding too.
		$section_hash = array();
		foreach ( $seed['sections'] as $slug => $aliases ) {
			foreach ( array_merge( array( $slug ), $aliases ) as $a ) {
				$section_hash[ LAGR_Vocabulary::hash( $a ) ] = $slug;
			}
		}
		$vocab_hash = array();
		foreach ( $seed['groups'] as $group ) {
			foreach ( $group['terms'] as $row ) {
				foreach ( array_merge( array( $row[0], $row[1] ), $row[3] ) as $a ) {
					$vocab_hash[ LAGR_Vocabulary::hash( $a ) ] = $row[0];
				}
			}
		}
		$block_hash = array_fill_keys( array_map( array( 'LAGR_Vocabulary', 'hash' ), $seed['blocklist'] ), true );
		$tech_hash  = array_fill_keys( array_map( array( 'LAGR_Vocabulary', 'hash' ), $seed['tech'] ), true );
		$eco_hash   = array_fill_keys( array_map( array( 'LAGR_Vocabulary', 'hash' ), $seed['ecosystem'] ), true );
		$seed_slugs = array_fill_keys( array_values( $vocab_hash ), true );

		$legacy = self::legacy_terms();
		$report['legacy_total'] = count( $legacy );

		// 1. Empty legacy terms. A legacy term owning a seed slug is kept: seeding converts it in place.
		$rest = array();
		foreach ( $legacy as $term ) {
			if ( 0 === (int) $term->count && ! isset( $seed_slugs[ $term->slug ] ) ) {
				self::add( $report, 'empty', $term );
				if ( ! $dry ) {
					wp_delete_term( $term->term_id, LAGR_Vocabulary::TAXONOMY );
				}
				continue;
			}
			$rest[] = $term;
		}

		// 2. Seed. The general/tech lists go to the blocklist as a whole, not
		// only the names that happen to exist now — otherwise `llm` comes back
		// with the next repo that carries it.
		$report['seed'] = LAGR_Vocabulary::seed( $dry );
		if ( ! $dry ) {
			foreach ( array_merge( $seed['blocklist'], $seed['tech'] ) as $name ) {
				LAGR_Vocabulary::block( $name );
			}
		}

		// 3–6.
		foreach ( $rest as $term ) {
			if ( ! $dry && '' !== LAGR_Vocabulary::status( $term->term_id ) ) {
				continue; // converted in place by the seed
			}
			if ( $dry && isset( $seed_slugs[ $term->slug ] ) ) {
				continue; // will be converted in place by the seed
			}
			$h = LAGR_Vocabulary::hash( $term->name );
			$s = LAGR_Vocabulary::hash( $term->slug );

			$section_slug = $section_hash[ $h ] ?? ( $section_hash[ $s ] ?? '' );
			if ( $section_slug ) {
				self::add( $report, 'sections', $term );
				if ( ! $dry ) {
					$section = get_term_by( 'slug', $section_slug, LAGR_Vocabulary::SECTION_TAXONOMY );
					if ( $section ) {
						$before = (int) $section->count;
						$r      = LAGR_Tag_Queue::merge(
							$term->term_id,
							$section->term_id,
							array(
								'target_taxonomy' => LAGR_Vocabulary::SECTION_TAXONOMY,
								'keep_redirect'   => true,
								'quiet'           => true,
							)
						);
						if ( is_wp_error( $r ) ) {
							$report['errors'][] = $term->name . ': ' . $r->get_error_message();
						} else {
							$after                   = get_term( $section->term_id );
							$report['section_adds'] += max( 0, (int) $after->count - $before );
						}
					} else {
						$report['errors'][] = sprintf( '%s: раздел «%s» не найден в repo_topic — оставлен кандидатом', $term->name, $section_slug );
						LAGR_Vocabulary::set_status( $term->term_id, LAGR_Vocabulary::STATUS_CANDIDATE );
					}
				}
				continue;
			}

			$general = isset( $block_hash[ $h ] ) || isset( $block_hash[ $s ] );
			$tech    = isset( $tech_hash[ $h ] ) || isset( $tech_hash[ $s ] );
			if ( $general || $tech ) {
				self::add( $report, $general ? 'blocklist' : 'tech', $term );
				if ( ! $dry ) {
					LAGR_Vocabulary::block( $term->name );
					wp_delete_term( $term->term_id, LAGR_Vocabulary::TAXONOMY );
				}
				continue;
			}

			$target_slug = $vocab_hash[ $h ] ?? ( $vocab_hash[ $s ] ?? '' );
			if ( $target_slug ) {
				self::add( $report, 'aliased', $term );
				if ( ! $dry ) {
					$target = get_term_by( 'slug', $target_slug, LAGR_Vocabulary::TAXONOMY );
					$r      = $target ? LAGR_Tag_Queue::merge(
						$term->term_id,
						$target->term_id,
						array(
							'keep_redirect' => true,
							'quiet'         => true,
						)
					) : new WP_Error( 'lagr_no_target', 'нет термина ' . $target_slug );
					if ( is_wp_error( $r ) ) {
						$report['errors'][] = $term->name . ': ' . $r->get_error_message();
					}
				}
				continue;
			}

			self::add( $report, ( isset( $eco_hash[ $h ] ) || isset( $eco_hash[ $s ] ) ) ? 'ecosystem' : 'candidates', $term );
			if ( ! $dry ) {
				update_term_meta( $term->term_id, LAGR_Vocabulary::META_STATUS, LAGR_Vocabulary::STATUS_CANDIDATE );
				update_term_meta( $term->term_id, LAGR_Vocabulary::META_HASH, $h );
				if ( '' === (string) get_term_meta( $term->term_id, LAGR_Vocabulary::META_FIRST_SEEN, true ) ) {
					update_term_meta( $term->term_id, LAGR_Vocabulary::META_FIRST_SEEN, time() );
				}
			}
		}

		if ( ! $dry ) {
			LAGR_Vocabulary::flush_index();
			delete_transient( LAGR_Tag_Regulator::COOC_TRANSIENT );
		}
		return $report;
	}

	/** @return WP_Term[] post_tag terms without a status. */
	public static function legacy_terms() {
		$terms = get_terms(
			array(
				'taxonomy'        => LAGR_Vocabulary::TAXONOMY,
				'hide_empty'      => false,
				'lagr_unfiltered' => true,
				'meta_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => LAGR_Vocabulary::META_STATUS,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/** Human-readable rows: label => bucket. */
	public static function report_rows( array $r ) {
		return array(
			'Пустые (count = 0) — удалить'                         => $r['empty'],
			'Имена разделов → repo_topic, 301 на раздел'           => $r['sections'],
			'Слишком общие → blocklist, удалить'                   => $r['blocklist'],
			'Языки, фреймворки, платформы → blocklist, удалить'    => $r['tech'],
			'Совпали с алиасом → перенос в термин словаря, 301'    => $r['aliased'],
			'Продукты и экосистемы → кандидаты'                    => $r['ecosystem'],
			'Остальное → кандидаты (записи сохраняются, скрыты)'   => $r['candidates'],
		);
	}

	public static function report_text( array $r ) {
		$parts = array( sprintf( 'legacy-терминов %d', $r['legacy_total'] ) );
		foreach ( self::report_rows( $r ) as $label => $b ) {
			$parts[] = sprintf( '%s: %d терм./%d привязок', $label, $b['terms'], $b['records'] );
		}
		$parts[] = sprintf( 'словарь: создано %d, обновлено %d', $r['seed']['created'] ?? 0, $r['seed']['updated'] ?? 0 );
		if ( $r['section_adds'] ) {
			$parts[] = sprintf( 'разделов проставлено записям: %d', $r['section_adds'] );
		}
		if ( $r['errors'] ) {
			$parts[] = 'ошибок: ' . count( $r['errors'] );
		}
		return implode( ' · ', $parts );
	}
}
