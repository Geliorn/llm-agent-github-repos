<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly vocabulary regulator (plan section 7). Runs inside the existing
 * 10-minute lagr_tick_event behind a "last run" guard — no second cron.
 *
 * Signals: mass ($term->count), breadth (how many post types carry the term —
 * the main "task vs technology" test), weekly count snapshots, co-occurrence
 * (synonym detector), emptiness. The demand signal (search/views) is out of
 * scope by decision of 2026-10-14.
 *
 * Hard limits: automation never creates a public page, never deletes a term
 * that has records, never deletes an approved term outright; every action is
 * logged with its reason.
 */
class LAGR_Tag_Regulator {

	const LAST_RUN_OPTION  = 'lagr_tag_regulator_last';
	const STATE_OPTION     = 'lagr_tag_regulator_state';
	const SUMMARY_OPTION   = 'lagr_tag_regulator_summary';
	const SNAPSHOTS_OPTION = 'lagr_tag_snapshots';
	const COOC_TRANSIENT   = 'lagr_tag_cooccurrence';

	const META_EMPTY_SINCE = '_tag_empty_since';
	const META_SMALL_SINCE = '_tag_small_since';

	const CANDIDATE_TTL_DAYS = 90; // empty candidate older than this → deleted
	const EMPTY_DAYS         = 60; // approved, populated once, empty this long → candidate
	const SMALL_DAYS         = 90; // approved below floor this long → merge proposal
	const REDUNDANCY         = 0.85;
	const REDUNDANCY_RATIO   = 3;  // A must be N times smaller than B

	/** Called from LAGR_Scheduler::run_tick(). */
	public static function maybe_run() {
		if ( ! LAGR_Settings::get( 'tags_regulator_enabled' ) ) {
			return;
		}
		$last = (int) get_option( self::LAST_RUN_OPTION, 0 );
		if ( $last && ( time() - $last ) < WEEK_IN_SECONDS ) {
			return;
		}
		update_option( self::LAST_RUN_OPTION, time(), false ); // guard first: a crash must not re-run every tick
		self::run();
	}

	/** Thresholds: settings as a base, adjusted by the size corridor. */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'floor'           => (int) LAGR_Settings::get( 'tags_floor', 3 ),
				'ready_threshold' => (int) LAGR_Settings::get( 'tags_ready_threshold', 5 ),
			)
		);
	}

	/** @return array Summary counters (also stored for the admin notice). */
	public static function run() {
		$state   = self::state();
		$now     = time();
		$summary = array(
			'ready'      => 0,
			'high'       => 0,
			'approved'   => 0,
			'deleted'    => 0,
			'demoted'    => 0,
			'proposals'  => 0,
			'emptied'    => 0,
			'floor'      => $state['floor'],
			'ready_at'   => $state['ready_threshold'],
			'size'       => 0,
			'time'       => $now,
		);

		$types = self::type_breadth();
		self::snapshot();

		// ---- candidates: priority, auto-approve (opt-in), expiry
		foreach ( LAGR_Vocabulary::terms_by_status( LAGR_Vocabulary::STATUS_CANDIDATE ) as $term ) {
			$count   = (int) $term->count;
			$breadth = (int) ( $types[ $term->term_id ] ?? 0 );

			if ( 0 === $count ) {
				$first = (int) get_term_meta( $term->term_id, LAGR_Vocabulary::META_FIRST_SEEN, true );
				if ( $first && ( $now - $first ) > self::CANDIDATE_TTL_DAYS * DAY_IN_SECONDS ) {
					wp_delete_term( $term->term_id, LAGR_Vocabulary::TAXONOMY );
					LAGR_Log::add( sprintf( 'Регулятор: пустой кандидат «%s» старше %d дней удалён', $term->name, self::CANDIDATE_TTL_DAYS ) );
					++$summary['deleted'];
				}
				continue;
			}

			if ( LAGR_Settings::get( 'tags_auto_approve' ) && $count >= (int) LAGR_Settings::get( 'tags_auto_approve_count', 15 ) && $breadth >= 2 ) {
				$r = LAGR_Tag_Queue::approve( $term->term_id );
				if ( true === $r ) {
					LAGR_Log::add( sprintf( 'Регулятор: «%s» утверждён автоматически (%d записей, %d типов)', $term->name, $count, $breadth ) );
					++$summary['approved'];
					continue;
				}
			}

			$priority = '';
			if ( $count >= 3 && $breadth >= 2 ) {
				$priority = 'high';
				++$summary['high'];
			} elseif ( $count >= $state['ready_threshold'] ) {
				$priority = 'ready';
				++$summary['ready'];
			}
			update_term_meta( $term->term_id, LAGR_Vocabulary::META_PRIORITY, $priority );
		}

		// ---- approved: emptiness, small terms, redundancy
		$approved = LAGR_Vocabulary::approved_terms();
		$by_id    = array();
		foreach ( $approved as $t ) {
			$by_id[ (int) $t->term_id ] = $t;
		}
		$cooc = self::cooccurrence( array_keys( $by_id ) );

		foreach ( $approved as $term ) {
			$id    = (int) $term->term_id;
			$count = (int) $term->count;
			if ( $count > 0 ) {
				update_term_meta( $id, LAGR_Vocabulary::META_WAS_POPULATED, 1 );
			}

			// Empty — only for terms that once had records: fresh terms for
			// future articles/contractors wait for content as long as needed.
			if ( 0 === $count && get_term_meta( $id, LAGR_Vocabulary::META_WAS_POPULATED, true ) ) {
				$since = (int) get_term_meta( $id, self::META_EMPTY_SINCE, true );
				if ( ! $since ) {
					update_term_meta( $id, self::META_EMPTY_SINCE, $now );
					++$summary['emptied'];
				} elseif ( ( $now - $since ) > self::EMPTY_DAYS * DAY_IN_SECONDS ) {
					LAGR_Vocabulary::set_status( $id, LAGR_Vocabulary::STATUS_CANDIDATE );
					update_term_meta( $id, LAGR_Vocabulary::META_FIRST_SEEN, $now ); // restarts the candidate TTL
					delete_term_meta( $id, self::META_EMPTY_SINCE );
					LAGR_Log::add( sprintf( 'Регулятор: «%s» пуст %d дней — переведён в кандидаты', $term->name, self::EMPTY_DAYS ) );
					++$summary['demoted'];
				}
				continue;
			}
			delete_term_meta( $id, self::META_EMPTY_SINCE );

			// Redundancy: ≥85% of A's records also carry B, and B is much larger.
			// Small terms are the floor rule's business: one record is trivially "contained" in any other tag.
			$best = $count >= $state['floor'] ? self::redundant_with( $id, $count, $cooc, $by_id ) : null;
			if ( $best ) {
				LAGR_Tag_Queue::add_proposal( $id, $best['id'], sprintf( '%d%% записей уже несут «%s»', round( $best['share'] * 100 ), $by_id[ $best['id'] ]->name ) );
				++$summary['proposals'];
				continue;
			}

			// Small for too long: propose merging into the most co-occurring larger term.
			if ( $count > 0 && $count < $state['floor'] ) {
				$since = (int) get_term_meta( $id, self::META_SMALL_SINCE, true );
				if ( ! $since ) {
					update_term_meta( $id, self::META_SMALL_SINCE, $now );
				} elseif ( ( $now - $since ) > self::SMALL_DAYS * DAY_IN_SECONDS ) {
					$target = self::most_cooccurring_larger( $id, $count, $cooc, $by_id );
					if ( $target ) {
						LAGR_Tag_Queue::add_proposal( $id, $target, sprintf( 'меньше %d записей больше %d дней', $state['floor'], self::SMALL_DAYS ) );
						++$summary['proposals'];
					}
				}
			} else {
				delete_term_meta( $id, self::META_SMALL_SINCE );
			}
		}

		// ---- size corridor: thresholds follow one target instead of guessed numbers
		$size            = count( LAGR_Vocabulary::approved_terms() );
		$summary['size'] = $size;
		$min             = (int) LAGR_Settings::get( 'tags_corridor_min', 60 );
		$max             = (int) LAGR_Settings::get( 'tags_corridor_max', 90 );
		if ( $size > $max ) {
			++$state['floor'];
		} elseif ( $size < $min ) {
			$state['ready_threshold'] = max( 2, $state['ready_threshold'] - 1 );
			$state['floor']           = max( (int) LAGR_Settings::get( 'tags_floor', 3 ), $state['floor'] - 1 );
		}
		update_option( self::STATE_OPTION, $state, false );
		$summary['floor']    = $state['floor'];
		$summary['ready_at'] = $state['ready_threshold'];

		update_option( self::SUMMARY_OPTION, $summary, false );
		LAGR_Log::add( 'Регулятор тегов: ' . self::summary_text( $summary ) );
		return $summary;
	}

	public static function summary_text( array $s ) {
		return sprintf(
			'%d кандидатов готовы к ревью (%d с высоким приоритетом) · %d склеек предложено · %d терминов опустело · %d в кандидаты · %d удалено · %d утверждено авто · словарь %d (порог %d)',
			$s['ready'] + $s['high'],
			$s['high'],
			$s['proposals'],
			$s['emptied'],
			$s['demoted'],
			$s['deleted'],
			$s['approved'],
			$s['size'],
			$s['floor']
		);
	}

	public static function last_summary() {
		$s = get_option( self::SUMMARY_OPTION, array() );
		return is_array( $s ) && $s ? $s : null;
	}

	// --------------------------------------------------------------- signals

	/** term_id => number of distinct post types carrying it (published records only). */
	public static function type_breadth() {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT tt.term_id, COUNT(DISTINCT p.post_type) AS types
				FROM {$wpdb->term_taxonomy} tt
				JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_status = 'publish'
				WHERE tt.taxonomy = %s
				GROUP BY tt.term_id",
				LAGR_Vocabulary::TAXONOMY
			)
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->term_id ] = (int) $r->types;
		}
		return $out;
	}

	/** One row per term per week; 13 weeks kept. Growth vs. a burnt-out trend. */
	public static function snapshot() {
		$all   = get_option( self::SNAPSHOTS_OPTION, array() );
		$all   = is_array( $all ) ? $all : array();
		$week  = gmdate( 'o-\WW' );
		$cur   = array();
		$terms = get_terms(
			array(
				'taxonomy'               => LAGR_Vocabulary::TAXONOMY,
				'hide_empty'             => false,
				'lagr_unfiltered'        => true,
				'update_term_meta_cache' => false,
			)
		);
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$cur[ (int) $t->term_id ] = (int) $t->count;
		}
		$all[ $week ] = $cur;
		ksort( $all );
		$all = array_slice( $all, -13, null, true );
		update_option( self::SNAPSHOTS_OPTION, $all, false );
		return $all;
	}

	/** Count delta over the last N weeks of snapshots (0 when unknown). */
	public static function trend( $term_id, $weeks = 4 ) {
		$all = get_option( self::SNAPSHOTS_OPTION, array() );
		if ( ! is_array( $all ) || count( $all ) < 2 ) {
			return 0;
		}
		$rows  = array_values( array_slice( $all, -( $weeks + 1 ), null, true ) );
		$first = (int) ( $rows[0][ $term_id ] ?? 0 );
		$last  = (int) ( end( $rows )[ $term_id ] ?? 0 );
		return $last - $first;
	}

	/**
	 * Pairwise co-occurrence among approved terms — the one heavy query
	 * (self-join on term_relationships), cached for a week.
	 *
	 * @return array [a_id][b_id] => records carrying both.
	 */
	public static function cooccurrence( array $term_ids ) {
		$cached = get_transient( self::COOC_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out = array();
		if ( $term_ids ) {
			global $wpdb;
			$ids  = implode( ',', array_map( 'intval', $term_ids ) );
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT a.term_id AS a, b.term_id AS b, COUNT(*) AS n
				FROM {$wpdb->term_relationships} ra
				JOIN {$wpdb->term_taxonomy} a ON a.term_taxonomy_id = ra.term_taxonomy_id AND a.term_id IN ($ids)
				JOIN {$wpdb->term_relationships} rb ON rb.object_id = ra.object_id AND rb.term_taxonomy_id <> ra.term_taxonomy_id
				JOIN {$wpdb->term_taxonomy} b ON b.term_taxonomy_id = rb.term_taxonomy_id AND b.term_id IN ($ids)
				GROUP BY a.term_id, b.term_id"
			);
			foreach ( (array) $rows as $r ) {
				$out[ (int) $r->a ][ (int) $r->b ] = (int) $r->n;
			}
		}
		set_transient( self::COOC_TRANSIENT, $out, WEEK_IN_SECONDS - HOUR_IN_SECONDS );
		return $out;
	}

	private static function redundant_with( $id, $count, array $cooc, array $by_id ) {
		if ( $count < 1 || empty( $cooc[ $id ] ) ) {
			return null;
		}
		$best = null;
		foreach ( $cooc[ $id ] as $other => $both ) {
			if ( ! isset( $by_id[ $other ] ) ) {
				continue;
			}
			$share = $both / $count;
			if ( $share >= self::REDUNDANCY && (int) $by_id[ $other ]->count >= $count * self::REDUNDANCY_RATIO && ( ! $best || $share > $best['share'] ) ) {
				$best = array(
					'id'    => (int) $other,
					'share' => $share,
				);
			}
		}
		return $best;
	}

	private static function most_cooccurring_larger( $id, $count, array $cooc, array $by_id ) {
		$best   = 0;
		$best_n = 0;
		foreach ( (array) ( $cooc[ $id ] ?? array() ) as $other => $both ) {
			if ( isset( $by_id[ $other ] ) && (int) $by_id[ $other ]->count > $count && $both > $best_n ) {
				$best   = (int) $other;
				$best_n = $both;
			}
		}
		return $best;
	}
}
