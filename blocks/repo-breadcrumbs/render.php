<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Breadcrumb trail for a repo page: Каталог → первая тема → название репозитория.
 * Navigation plus internal links the search engines like.
 */

$post_id = get_the_ID();
if ( ! $post_id ) {
	return;
}

$crumbs = array();

$repos_url = get_post_type_archive_link( 'github_repo' );
if ( $repos_url ) {
	$crumbs[] = '<a href="' . esc_url( $repos_url ) . '">Каталог</a>';
}

$topics = get_the_terms( $post_id, 'repo_topic' );
if ( $topics && ! is_wp_error( $topics ) ) {
	$link = get_term_link( $topics[0] );
	if ( ! is_wp_error( $link ) ) {
		$crumbs[] = '<a href="' . esc_url( $link ) . '">' . esc_html( $topics[0]->name ) . '</a>';
	}
}

$crumbs[] = '<span aria-current="page">' . esc_html( get_the_title( $post_id ) ) . '</span>';

$wrapper = get_block_wrapper_attributes( array( 'class' => 'lagr-breadcrumbs' ) );

echo '<nav ' . $wrapper . ' aria-label="Хлебные крошки">' // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped by get_block_wrapper_attributes().
	. implode( '<span class="lagr-breadcrumbs-sep" aria-hidden="true">/</span>', $crumbs ) // phpcs:ignore WordPress.Security.EscapeOutput -- each crumb is escaped above.
	. '</nav>';
