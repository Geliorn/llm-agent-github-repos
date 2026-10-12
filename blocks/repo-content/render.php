<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repo text as tabs — the LLM-generated description, the GitHub README and an
 * "Установка" tab with a copy-able clone command. CSS-only disclosure (hidden
 * radio inputs drive the panels, see .lagr-tabs in patterns.css); only the
 * copy button needs JS (view.js), and it degrades to a selectable command.
 */

$post_id = get_the_ID();
if ( ! $post_id ) {
	return;
}

$generated = trim( (string) get_post_meta( $post_id, '_gh_generated_html', true ) );
// Prefer the translated README when one exists (translation replaces the
// original in the single README tab); otherwise the original GitHub rendering.
$readme_tr = trim( (string) get_post_meta( $post_id, '_gh_readme_html_tr', true ) );
$readme    = '' !== $readme_tr ? $readme_tr : trim( (string) get_post_meta( $post_id, '_gh_readme_html', true ) );

// Posts collected before the readme/generated split kept everything in
// post_content — fall back to it for the README tab.
if ( '' === $readme && '' === $generated ) {
	$readme = trim( (string) get_post_field( 'post_content', $post_id ) );
}

// Prefer an explicit install command (LLM-extracted from the README, or set
// by an editor); fall back to a generic clone, which is valid for any repo.
$full       = (string) get_post_meta( $post_id, '_gh_full_name', true );
$install    = trim( (string) get_post_meta( $post_id, 'install_command', true ) );
$is_clone   = '' === $install;
if ( $is_clone && '' !== $full ) {
	$install = 'git clone https://github.com/' . $full . '.git';
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

if ( '' !== $install ) {
	$hint         = $is_clone ? 'Клонировать репозиторий:' : 'Установка:';
	$install_html = '<div class="lagr-install">'
		. '<p class="lagr-install-hint">' . esc_html( $hint ) . '</p>'
		. '<div class="lagr-install-cmd"><code>' . esc_html( $install ) . '</code>'
		. '<button type="button" class="lagr-copy" data-copy="' . esc_attr( $install ) . '">Копировать</button></div>'
		. '</div>';
	$tabs['install'] = array(
		'label' => 'Установка',
		'html'  => $install_html,
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

// phpcs:ignore WordPress.Security.EscapeOutput -- wrapper pre-escaped; tab labels/ids escaped above; panel HTML is wp_kses_post'd or our own trusted markup.
echo '<div ' . $wrapper . '>' . $radios . '<div class="lagr-tabnav" role="tablist">' . $nav . '</div>' . $panels . '</div>';
