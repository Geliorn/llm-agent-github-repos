<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! LAGR_Settings::integration_enabled( 'filters' ) ) {
	return;
}

$facets = array(
	'repo_topic' => 'Темы',
);
if ( LAGR_Settings::get( 'collect_language' ) ) {
	$facets['repo_language'] = 'Язык';
}
if ( LAGR_Settings::get( 'collect_license' ) ) {
	$facets['repo_license'] = 'Лицензия';
}
$facets['post_tag'] = 'Теги';

// Skip the facet matching the taxonomy we're already on — the URL itself
// is that filter, showing it again in the sidebar would just duplicate it.
$current_taxonomy = is_tax() ? get_queried_object()->taxonomy ?? '' : '';

// A repo can easily carry 15-20 GitHub topics/tags — a flat checkbox list
// of every one of them (found live: 100+ for "Теги") is unusable. Past this
// many options, render the facet as a native <select multiple> listbox
// instead — scrollable, searchable via the keyboard, no JS needed.
// A plain variable, not a class/file-level const: this file is require()'d
// fresh by WP's block renderer on every occurrence of the block, and a
// top-level `const` would fatal with "already defined" if it ever rendered
// more than once in a request.
$collapse_threshold = 8;

$star_options = array(
	100   => '100+',
	1000  => '1 000+',
	10000 => '10 000+',
	50000 => '50 000+',
);

$base_url    = LAGR_Filters::base_url();
$min_stars   = LAGR_Filters::min_stars();
$wrapper_attributes = get_block_wrapper_attributes( array( 'class' => 'lagr-filters' ) );

echo '<form ' . $wrapper_attributes . ' method="get" action="' . esc_url( $base_url ) . '">';

foreach ( $facets as $taxonomy => $label ) {
	if ( $taxonomy === $current_taxonomy ) {
		continue;
	}
	$param  = LAGR_Filters::TAXONOMY_PARAMS[ $taxonomy ];
	$terms  = LAGR_Filters::facet_counts( $taxonomy );
	if ( empty( $terms ) ) {
		continue;
	}
	$selected = LAGR_Filters::selected( $param );

	if ( count( $terms ) > $collapse_threshold ) {
		$select_id = 'lagr-filter-' . $param;
		echo '<div class="lagr-filter-group">';
		printf( '<label class="lagr-filter-select-label" for="%1$s">%2$s</label>', esc_attr( $select_id ), esc_html( $label ) );
		printf( '<select class="lagr-filter-multiselect" id="%1$s" name="%2$s[]" multiple size="8">', esc_attr( $select_id ), esc_attr( $param ) );
		foreach ( $terms as $term ) {
			printf(
				'<option value="%1$s"%2$s>%3$s (%4$d)</option>',
				esc_attr( $term->slug ),
				in_array( $term->slug, $selected, true ) ? ' selected' : '',
				esc_html( $term->name ),
				(int) $term->cnt
			);
		}
		echo '</select>';
		echo '</div>';
		continue;
	}

	echo '<fieldset class="lagr-filter-group">';
	printf( '<legend>%s</legend>', esc_html( $label ) );
	foreach ( $terms as $term ) {
		$checked_id = 'lagr-filter-' . $param . '-' . $term->term_id;
		printf(
			'<label class="lagr-filter-option" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s"%4$s> %5$s <span class="lagr-filter-count">%6$d</span></label>',
			esc_attr( $checked_id ),
			esc_attr( $param ),
			esc_attr( $term->slug ),
			in_array( $term->slug, $selected, true ) ? ' checked' : '',
			esc_html( $term->name ),
			(int) $term->cnt
		);
	}
	echo '</fieldset>';
}

echo '<fieldset class="lagr-filter-group">';
echo '<legend>Звёзды GitHub</legend>';
echo '<select name="' . esc_attr( LAGR_Filters::STARS_PARAM ) . '" class="lagr-filter-select">';
echo '<option value="0">Любое количество</option>';
foreach ( $star_options as $value => $option_label ) {
	printf(
		'<option value="%1$d"%2$s>от %3$s</option>',
		$value,
		selected( $min_stars, $value, false ),
		esc_html( $option_label )
	);
}
echo '</select>';
echo '</fieldset>';

echo '<button type="submit" class="lagr-filter-apply">Применить</button>';
if ( LAGR_Filters::is_active() ) {
	echo '<a class="lagr-filter-reset" href="' . esc_url( $base_url ) . '">Сбросить</a>';
}

echo '</form>';
