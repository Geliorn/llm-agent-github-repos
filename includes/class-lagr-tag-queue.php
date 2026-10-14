<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operations of the "Теги: заявки" queue (plan section 6): approve, reject,
 * merge (with preview), merged-term chains for the 301, plus the merge
 * proposals the regulator files for a human to confirm.
 *
 * A candidate is already attached to its records, so approving is a single
 * meta switch — no re-tagging, no second write path that can fail halfway.
 */
class LAGR_Tag_Queue {

	const PROPOSALS_OPTION = 'lagr_tag_merge_proposals';
	const MAX_CHAIN        = 5;

	// --------------------------------------------------------------- approve

	/** @return true|WP_Error */
	public static function approve( $term_id, $definition = '' ) {
		$term = get_term( (int) $term_id, LAGR_Vocabulary::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'lagr_no_term', 'Термин не найден.' );
		}
		if ( LAGR_Vocabulary::STATUS_MERGED === LAGR_Vocabulary::status( $term->term_id ) ) {
			return new WP_Error( 'lagr_merged', 'Термин склеен с другим — утверждать нечего.' );
		}
		// One term — one axis.
		if ( LAGR_Vocabulary::lookup_section( $term->name ) || LAGR_Vocabulary::lookup_section( $term->slug ) ) {
			return new WP_Error( 'lagr_section', sprintf( '«%s» — имя раздела (repo_topic), в словаре задач его быть не может. Склейте или отклоните.', $term->name ) );
		}
		$owner = LAGR_Vocabulary::lookup( $term->name );
		if ( $owner && $owner !== (int) $term->term_id ) {
			$o = get_term( $owner );
			return new WP_Error( 'lagr_alias', sprintf( '«%s» уже разрешается в «%s» — используйте склейку.', $term->name, $o ? $o->name : $owner ) );
		}

		LAGR_Vocabulary::set_status( $term->term_id, LAGR_Vocabulary::STATUS_APPROVED );
		update_term_meta( $term->term_id, LAGR_Vocabulary::META_REVIEWED_AT, time() );
		delete_term_meta( $term->term_id, LAGR_Vocabulary::META_PRIORITY );
		if ( '' !== trim( (string) $definition ) ) {
			update_term_meta( $term->term_id, LAGR_Vocabulary::META_DEFINITION, sanitize_text_field( $definition ) );
		}
		if ( $term->count > 0 ) {
			update_term_meta( $term->term_id, LAGR_Vocabulary::META_WAS_POPULATED, 1 );
		}
		self::drop_proposals_for( $term->term_id );
		LAGR_Log::add( sprintf( 'Теги: «%s» утверждён (%d записей) — %s', $term->name, $term->count, self::actor() ) );
		return true;
	}

	// ---------------------------------------------------------------- reject

	/** Deletes the candidate and blocklists its name: the model won't propose it again. */
	public static function reject( $term_id ) {
		$term = get_term( (int) $term_id, LAGR_Vocabulary::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'lagr_no_term', 'Термин не найден.' );
		}
		if ( LAGR_Vocabulary::is_approved( $term->term_id ) ) {
			// An approved term may have external links and rankings: never deleted here.
			return new WP_Error( 'lagr_approved', 'Утверждённый термин не отклоняется — только склеивается.' );
		}
		LAGR_Vocabulary::block( $term->name );
		wp_delete_term( $term->term_id, LAGR_Vocabulary::TAXONOMY );
		self::drop_proposals_for( $term->term_id );
		LAGR_Log::add( sprintf( 'Теги: «%s» отклонён и в blocklist (%d записей потеряли тег) — %s', $term->name, $term->count, self::actor() ) );
		return true;
	}

	// ----------------------------------------------------------------- merge

	/**
	 * What a merge would do — shown before the click, because there is no unmerge.
	 *
	 * @return array|WP_Error {source, target, move, already, aliases[], source_after}
	 */
	public static function preview( $source_id, $target_id, array $opts = array() ) {
		$source = get_term( (int) $source_id, LAGR_Vocabulary::TAXONOMY );
		if ( ! $source || is_wp_error( $source ) ) {
			return new WP_Error( 'lagr_no_source', 'Исходный термин не найден.' );
		}
		$target_tax = $opts['target_taxonomy'] ?? LAGR_Vocabulary::TAXONOMY;
		$target_id  = LAGR_Vocabulary::TAXONOMY === $target_tax ? self::final_id( (int) $target_id ) : (int) $target_id;
		$target     = get_term( $target_id, $target_tax );
		if ( ! $target || is_wp_error( $target ) ) {
			return new WP_Error( 'lagr_no_target', 'Целевой термин не найден.' );
		}
		if ( (int) $target->term_id === (int) $source->term_id && $target_tax === $source->taxonomy ) {
			return new WP_Error( 'lagr_same', 'Нельзя склеить термин сам с собой.' );
		}
		if ( LAGR_Vocabulary::TAXONOMY === $target_tax && empty( $opts['allow_any_target'] ) && ! LAGR_Vocabulary::is_approved( $target->term_id ) ) {
			return new WP_Error( 'lagr_target_status', 'Склеивать можно только в утверждённый термин.' );
		}

		$objects = array_map( 'intval', (array) get_objects_in_term( $source->term_id, LAGR_Vocabulary::TAXONOMY ) );
		$with    = $objects ? array_map( 'intval', (array) get_objects_in_term( $target->term_id, $target_tax ) ) : array();
		$already = count( array_intersect( $objects, $with ) );

		$status       = LAGR_Vocabulary::status( $source->term_id );
		$keep         = array_key_exists( 'keep_redirect', $opts ) ? (bool) $opts['keep_redirect'] : ( LAGR_Vocabulary::STATUS_APPROVED === $status );
		$aliases      = array_merge( LAGR_Vocabulary::aliases( $source->term_id ), array( $source->name, $source->slug ) );
		$have         = array();
		foreach ( array_merge( LAGR_Vocabulary::aliases( $target->term_id ), array( $target->name, $target->slug ) ) as $a ) {
			$have[ LAGR_Vocabulary::hash( $a ) ] = true;
		}
		$new_aliases = array();
		foreach ( $aliases as $a ) {
			$h = LAGR_Vocabulary::hash( $a );
			if ( ! isset( $have[ $h ] ) ) {
				$have[ $h ]    = true;
				$new_aliases[] = $a;
			}
		}

		return array(
			'source'       => $source,
			'target'       => $target,
			'objects'      => $objects,
			'move'         => count( $objects ) - $already,
			'already'      => $already,
			'aliases'      => $new_aliases,
			'source_after' => $keep ? LAGR_Vocabulary::STATUS_MERGED : 'deleted',
		);
	}

	/**
	 * Merge source into target, in the order that matters (plan 6.1): move
	 * records (append target, drop source), move aliases incl. the source's own
	 * name and slug, recount, then retire the source — deleted when it never
	 * had a public page, kept as `merged` (301) when it did.
	 *
	 * @param array $opts target_taxonomy (post_tag|repo_topic), keep_redirect, allow_any_target.
	 * @return array|WP_Error The preview that was applied.
	 */
	public static function merge( $source_id, $target_id, array $opts = array() ) {
		$p = self::preview( $source_id, $target_id, $opts );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$source     = $p['source'];
		$target     = $p['target'];
		$target_tax = $target->taxonomy;

		foreach ( $p['objects'] as $object_id ) {
			// Sections only exist on repos; other records just lose the tag.
			if ( LAGR_Vocabulary::TAXONOMY === $target_tax || is_object_in_taxonomy( get_post_type( $object_id ), $target_tax ) ) {
				wp_set_object_terms( $object_id, array( (int) $target->term_id ), $target_tax, true );
			}
			wp_remove_object_terms( $object_id, array( (int) $source->term_id ), LAGR_Vocabulary::TAXONOMY );
		}

		if ( $p['aliases'] ) {
			LAGR_Vocabulary::add_aliases( $target->term_id, $p['aliases'] );
		}
		// Alias observations travel too, so learning isn't reset by a merge.
		$obs_s = get_term_meta( $source->term_id, LAGR_Vocabulary::META_ALIAS_CANDIDATES, true );
		if ( is_array( $obs_s ) && $obs_s && LAGR_Vocabulary::TAXONOMY === $target_tax ) {
			$obs_t = get_term_meta( $target->term_id, LAGR_Vocabulary::META_ALIAS_CANDIDATES, true );
			$obs_t = is_array( $obs_t ) ? $obs_t : array();
			foreach ( $obs_s as $k => $v ) {
				$obs_t[ $k ] = (int) ( $obs_t[ $k ] ?? 0 ) + (int) $v;
			}
			update_term_meta( $target->term_id, LAGR_Vocabulary::META_ALIAS_CANDIDATES, $obs_t );
		}

		wp_update_term_count( array( (int) $source->term_taxonomy_id ), LAGR_Vocabulary::TAXONOMY );
		wp_update_term_count( array( (int) $target->term_taxonomy_id ), $target_tax );
		if ( LAGR_Vocabulary::TAXONOMY === $target_tax && $p['objects'] ) {
			update_term_meta( $target->term_id, LAGR_Vocabulary::META_WAS_POPULATED, 1 );
		}

		if ( 'deleted' === $p['source_after'] ) {
			wp_delete_term( $source->term_id, LAGR_Vocabulary::TAXONOMY );
		} else {
			update_term_meta( $source->term_id, LAGR_Vocabulary::META_STATUS, LAGR_Vocabulary::STATUS_MERGED );
			update_term_meta( $source->term_id, LAGR_Vocabulary::META_MERGED_INTO, (int) $target->term_id );
			update_term_meta( $source->term_id, LAGR_Vocabulary::META_MERGED_INTO_TAX, $target_tax );
			update_term_meta( $source->term_id, LAGR_Vocabulary::META_HASH, LAGR_Vocabulary::hash( $source->name ) );
		}
		LAGR_Vocabulary::flush_index();
		self::drop_proposals_for( $source->term_id );

		if ( empty( $opts['quiet'] ) ) {
			LAGR_Log::add(
				sprintf(
					'Теги: «%s» → «%s» (%s): записей перенесено %d, уже несли цель %d, алиасов +%d, источник %s — %s',
					$source->name,
					$target->name,
					$target_tax,
					$p['move'],
					$p['already'],
					count( $p['aliases'] ),
					'deleted' === $p['source_after'] ? 'удалён' : 'оставлен для 301',
					self::actor()
				)
			);
		}
		return $p;
	}

	/** Batch: several candidates → one target (typical: four wordings of one task). */
	public static function merge_many( array $source_ids, $target_id ) {
		$done   = 0;
		$errors = array();
		foreach ( $source_ids as $id ) {
			$r = self::merge( (int) $id, (int) $target_id );
			if ( is_wp_error( $r ) ) {
				$errors[] = $r->get_error_message();
			} else {
				++$done;
			}
		}
		return array(
			'done'   => $done,
			'errors' => $errors,
		);
	}

	// ---------------------------------------------------------------- chains

	/** Follow `_tag_merged_into` to the final post_tag term id (depth-limited, cycle-safe). */
	public static function final_id( $term_id ) {
		$target = self::final_target( $term_id );
		return ( $target && LAGR_Vocabulary::TAXONOMY === $target->taxonomy ) ? (int) $target->term_id : (int) $term_id;
	}

	/** @return WP_Term|null The term a merged term should redirect to. */
	public static function final_target( $term_id ) {
		$seen = array();
		$id   = (int) $term_id;
		$tax  = LAGR_Vocabulary::TAXONOMY;
		for ( $i = 0; $i <= self::MAX_CHAIN; $i++ ) {
			if ( isset( $seen[ $tax . ':' . $id ] ) ) {
				return null; // cycle
			}
			$seen[ $tax . ':' . $id ] = true;
			if ( LAGR_Vocabulary::TAXONOMY !== $tax || LAGR_Vocabulary::STATUS_MERGED !== LAGR_Vocabulary::status( $id ) ) {
				$term = get_term( $id, $tax );
				return ( $term && ! is_wp_error( $term ) ) ? $term : null;
			}
			$next = (int) get_term_meta( $id, LAGR_Vocabulary::META_MERGED_INTO, true );
			$tax  = (string) get_term_meta( $id, LAGR_Vocabulary::META_MERGED_INTO_TAX, true ) ?: LAGR_Vocabulary::TAXONOMY;
			if ( ! $next ) {
				return null;
			}
			$id = $next;
		}
		return null;
	}

	// ------------------------------------------------------------ proposals

	public static function proposals() {
		$p = get_option( self::PROPOSALS_OPTION, array() );
		return is_array( $p ) ? $p : array();
	}

	/** Filed by the regulator; a human confirms. Keyed by source, the newest reason wins. */
	public static function add_proposal( $source_id, $target_id, $reason ) {
		$all                       = self::proposals();
		$all[ (int) $source_id ] = array(
			'source'  => (int) $source_id,
			'target'  => (int) $target_id,
			'reason'  => $reason,
			'created' => $all[ (int) $source_id ]['created'] ?? time(),
		);
		update_option( self::PROPOSALS_OPTION, $all, false );
	}

	public static function drop_proposals_for( $term_id ) {
		$all     = self::proposals();
		$changed = false;
		foreach ( $all as $key => $p ) {
			if ( (int) $p['source'] === (int) $term_id || (int) $p['target'] === (int) $term_id ) {
				unset( $all[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::PROPOSALS_OPTION, $all, false );
		}
	}

	private static function actor() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'WP-CLI';
		}
		$user = wp_get_current_user();
		return $user && $user->exists() ? $user->user_login : 'автоматика';
	}
}
