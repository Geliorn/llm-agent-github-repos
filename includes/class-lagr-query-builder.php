<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a github_repo_rule's fields into a GitHub Search API query string,
 * or passes through the raw/advanced override untouched (section 3 of the plan).
 */
class LAGR_Query_Builder {

	public static function build_from_rule( $rule_id ) {
		$raw = trim( (string) get_post_meta( $rule_id, '_lagr_raw_query', true ) );
		if ( '' !== $raw ) {
			return $raw;
		}

		$topics = array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $rule_id, '_lagr_topics', true ) ) ) );
		$parts  = array();

		foreach ( $topics as $topic ) {
			$parts[] = 'topic:' . $topic;
		}

		$operator = get_post_meta( $rule_id, '_lagr_stars_operator', true ) ?: '>';
		$stars    = get_post_meta( $rule_id, '_lagr_stars_value', true );
		if ( '' !== $stars && null !== $stars ) {
			$parts[] = 'stars:' . $operator . (int) $stars;
		}

		$freshness_days = (int) get_post_meta( $rule_id, '_lagr_freshness_days', true );
		if ( $freshness_days > 0 ) {
			$since   = gmdate( 'Y-m-d', time() - $freshness_days * DAY_IN_SECONDS );
			$parts[] = 'pushed:>' . $since;
		}

		if ( get_post_meta( $rule_id, '_lagr_exclude_forks', true ) ) {
			$parts[] = 'fork:false';
		}
		if ( get_post_meta( $rule_id, '_lagr_exclude_archived', true ) ) {
			$parts[] = 'archived:false';
		}

		$min_size_kb = get_post_meta( $rule_id, '_lagr_min_size_kb', true );
		if ( '' !== $min_size_kb && (int) $min_size_kb > 0 ) {
			$parts[] = 'size:>=' . (int) $min_size_kb;
		}

		return implode( ' ', $parts );
	}
}
