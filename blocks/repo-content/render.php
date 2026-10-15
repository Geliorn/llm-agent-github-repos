<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repo text as tabs — the LLM-generated description and the GitHub README —
 * followed by an always-visible FAQ section (LAGR_FAQ), whose first answer
 * carries the copy-able install command. CSS-only disclosure (hidden radio
 * inputs drive the panels, see .lagr-tabs in patterns.css); only the copy
 * button needs JS (view.js), and it degrades to a selectable command.
 *
 * The FAQ sits outside the tabs on purpose: it is marked up as FAQPage
 * (LAGR_Schema), and the marked-up answers must be on the page itself — an
 * accordion of <details> keeps them in the HTML, a hidden tab panel is
 * further from "visible" than search engines like.
 */

$post_id = get_the_ID();
if ( ! $post_id ) {
	return;
}

$generated = trim( (string) get_post_meta( $post_id, '_gh_generated_html', true ) );

// README display is optional (settings: collect_readme). When off, no README
// tab is shown even for repos that already have one stored — leaving only the
// AI description. Prefer a translated README when present, else the original.
$show_readme = (bool) LAGR_Settings::get( 'collect_readme', 1 );
$readme_tr   = trim( (string) get_post_meta( $post_id, '_gh_readme_html_tr', true ) );
$readme      = $show_readme
	? ( '' !== $readme_tr ? $readme_tr : trim( (string) get_post_meta( $post_id, '_gh_readme_html', true ) ) )
	: '';

// Posts collected before the readme/generated split kept everything in
// post_content — use it as a last resort so the page is never empty.
if ( '' === $readme && '' === $generated ) {
	$readme = trim( (string) get_post_field( 'post_content', $post_id ) );
}

$tabs = array();

// Описание — always present; a muted placeholder until an LLM description exists.
$tabs['desc'] = array(
	'label' => 'Описание',
	'html'  => '' !== $generated ? wp_kses_post( $generated ) : '<p class="lagr-tab-empty">Описания пока нет</p>',
	'has'   => '' !== $generated,
);

if ( '' !== $readme ) {
	$tabs['readme'] = array(
		'label' => 'README',
		// Sanitize with the README allowlist (keeps code/tables/task-lists),
		// not wp_kses_post which would strip those extras.
		'html'  => LAGR_Collector::sanitize_readme_html( $readme ),
		'has'   => true,
	);
}

if ( empty( $tabs ) ) {
	return;
}

// Land the visitor on the first tab that actually has content.
$active = '';
foreach ( $tabs as $id => $tab ) {
	if ( $tab['has'] ) {
		$active = $id;
		break;
	}
}
if ( '' === $active ) {
	$active = (string) array_key_first( $tabs );
}

$uid     = 'lagr-rc-' . (int) $post_id;
$wrapper = get_block_wrapper_attributes( array( 'class' => 'lagr-tabs' ) );

$radios = '';
$nav    = '';
$panels = '';
foreach ( $tabs as $id => $tab ) {
	$input_id = $uid . '-' . $id;
	$radios  .= '<input type="radio" name="' . esc_attr( $uid ) . '" id="' . esc_attr( $input_id ) . '" class="lagr-tabin lagr-tabin-' . esc_attr( $id ) . '"' . ( $id === $active ? ' checked' : '' ) . '>';
	$nav     .= '<label for="' . esc_attr( $input_id ) . '" class="lagr-tab lagr-tab-' . esc_attr( $id ) . '">' . esc_html( $tab['label'] ) . '</label>';
	$panels  .= '<div class="lagr-tabpanel lagr-panel-' . esc_attr( $id ) . '">' . $tab['html'] . '</div>';
}

$faq_html = '';
$faq      = LAGR_FAQ::items( $post_id );
if ( ! empty( $faq ) ) {
	$faq_html = '<section class="lagr-faq" aria-labelledby="' . esc_attr( $uid ) . '-faq">'
		. '<h2 id="' . esc_attr( $uid ) . '-faq" class="lagr-faq-title">Частые вопросы</h2>';
	foreach ( $faq as $i => $item ) {
		// Accordion on native <details>: no JS, and the collapsed answers stay in
		// the HTML, so the FAQPage markup still matches text on the page. The
		// first one (install) starts open. #faq-N — LAGR_Schema links there.
		$faq_html .= '<details class="lagr-faq-item" id="faq-' . ( $i + 1 ) . '"' . ( 0 === $i ? ' open' : '' ) . '>'
			. '<summary class="lagr-faq-q"><h3>' . esc_html( $item['question'] ) . '</h3></summary>'
			. '<div class="lagr-faq-a">' . $item['answer_html'] . '</div></details>';
	}
	$faq_html .= '</section>';
}

// phpcs:ignore WordPress.Security.EscapeOutput -- wrapper pre-escaped; tab labels/ids escaped above; panel HTML is wp_kses_post'd or our own trusted markup; FAQ answers are escaped in LAGR_FAQ.
echo '<div ' . $wrapper . '>' . $radios . '<div class="lagr-tabnav" role="tablist">' . $nav . '</div>' . $panels . $faq_html . '</div>';
