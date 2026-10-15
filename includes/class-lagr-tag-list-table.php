<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * "Теги: заявки" — candidates straight from get_terms(status = candidate).
 * No CPT/own table: the "how many records asked for it" column is
 * $term->count, which WordPress keeps right by itself.
 */
class LAGR_Tag_List_Table extends WP_List_Table {

	/** @var array term_id => distinct post types */
	private $breadth = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'lagr_tag',
				'plural'   => 'lagr_tags',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox">',
			'name'     => 'Термин',
			'count'    => 'Записей',
			'types'    => 'Типов записей',
			'priority' => 'Приоритет',
			'why'      => 'Обоснование модели',
			'closest'  => 'Ближайший существующий',
			'proposed' => 'Заявок',
			'first'    => 'Первая заявка',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'name'  => array( 'name', false ),
			'count' => array( 'count', true ),
			'first' => array( 'first', false ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'approve' => 'Утвердить',
			'reject'  => 'Отклонить (в blocklist)',
			'merge'   => 'Склеить в… (выберите цель)',
		);
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions">';
		LAGR_Tag_Admin::target_select( 'merge_target', 0 );
		echo '</div>';
	}

	public function prepare_items() {
		$per_page = 50;
		$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$orderby  = sanitize_key( $_GET['orderby'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$order    = 'asc' === strtolower( (string) ( $_GET['order'] ?? '' ) ) ? 'ASC' : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification
		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$filter   = sanitize_key( $_GET['priority'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification

		$terms = LAGR_Vocabulary::terms_by_status( LAGR_Vocabulary::STATUS_CANDIDATE, $search ? array( 'search' => $search ) : array() );
		if ( in_array( $filter, array( 'high', 'ready' ), true ) ) {
			$terms = array_values(
				array_filter(
					$terms,
					function ( $t ) use ( $filter ) {
						return get_term_meta( $t->term_id, LAGR_Vocabulary::META_PRIORITY, true ) === $filter;
					}
				)
			);
		}
		$this->breadth = LAGR_Tag_Regulator::type_breadth();

		// Default order is the queue priority: high → ready → count.
		$rank = array(
			'high'  => 2,
			'ready' => 1,
		);
		usort(
			$terms,
			function ( $a, $b ) use ( $orderby, $order, $rank ) {
				switch ( $orderby ) {
					case 'name':
						$cmp = strcmp( $a->name, $b->name );
						break;
					case 'count':
						$cmp = $a->count <=> $b->count;
						break;
					case 'first':
						$cmp = (int) get_term_meta( $a->term_id, LAGR_Vocabulary::META_FIRST_SEEN, true ) <=> (int) get_term_meta( $b->term_id, LAGR_Vocabulary::META_FIRST_SEEN, true );
						break;
					default:
						$pa  = $rank[ get_term_meta( $a->term_id, LAGR_Vocabulary::META_PRIORITY, true ) ] ?? 0;
						$pb  = $rank[ get_term_meta( $b->term_id, LAGR_Vocabulary::META_PRIORITY, true ) ] ?? 0;
						$cmp = ( $pa <=> $pb ) ?: ( $a->count <=> $b->count );
						return -$cmp;
				}
				return 'ASC' === $order ? $cmp : -$cmp;
			}
		);

		$total       = count( $terms );
		$this->items = array_slice( $terms, ( $paged - 1 ) * $per_page, $per_page );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	protected function column_cb( $term ) {
		return sprintf( '<input type="checkbox" name="term_ids[]" value="%d">', (int) $term->term_id );
	}

	protected function column_name( $term ) {
		$base    = admin_url( 'admin.php?page=' . LAGR_Tag_Admin::QUEUE_SLUG );
		$actions = array(
			'approve' => sprintf( '<a href="%s">Утвердить</a>', esc_url( wp_nonce_url( add_query_arg( array( 'lagr_do' => 'approve', 'term_id' => $term->term_id ), $base ), 'lagr_tag_row_' . $term->term_id ) ) ),
			'merge'   => sprintf( '<a href="%s">Склеить…</a>', esc_url( add_query_arg( array( 'lagr_view' => 'merge', 'term_ids' => array( $term->term_id ) ), $base ) ) ),
			'reject'  => sprintf( '<a href="%s" onclick="return confirm(\'Термин будет удалён, имя — в blocklist. Продолжить?\')">Отклонить</a>', esc_url( wp_nonce_url( add_query_arg( array( 'lagr_do' => 'reject', 'term_id' => $term->term_id ), $base ), 'lagr_tag_row_' . $term->term_id ) ) ),
			'edit'    => sprintf( '<a href="%s">Изменить</a>', esc_url( get_edit_term_link( $term->term_id, LAGR_Vocabulary::TAXONOMY ) ) ),
		);
		return sprintf( '<strong>%s</strong> <code>%s</code>%s', esc_html( $term->name ), esc_html( $term->slug ), $this->row_actions( $actions ) );
	}

	protected function column_count( $term ) {
		return (int) $term->count;
	}

	protected function column_types( $term ) {
		return (int) ( $this->breadth[ $term->term_id ] ?? 0 );
	}

	protected function column_priority( $term ) {
		$p = get_term_meta( $term->term_id, LAGR_Vocabulary::META_PRIORITY, true );
		if ( 'high' === $p ) {
			return '<span style="color:#b32d2e;font-weight:600">высокий</span>';
		}
		return 'ready' === $p ? '<span style="color:#007017">готов к ревью</span>' : '—';
	}

	private function proposal( $term ) {
		$p = get_term_meta( $term->term_id, LAGR_Vocabulary::META_PROPOSAL, true );
		return is_array( $p ) ? $p : array();
	}

	protected function column_why( $term ) {
		$p = $this->proposal( $term );
		return esc_html( $p['why'] ?? '' ) . ( ! empty( $p['source'] ) ? ' <span class="description">(' . esc_html( $p['source'] ) . ')</span>' : '' );
	}

	protected function column_closest( $term ) {
		$p  = $this->proposal( $term );
		$id = ! empty( $p['closest_existing'] ) ? LAGR_Vocabulary::lookup( $p['closest_existing'] ) : 0;
		if ( ! $id ) {
			return esc_html( $p['closest_existing'] ?? '—' );
		}
		$target = get_term( $id );
		$url    = add_query_arg(
			array(
				'page'      => LAGR_Tag_Admin::QUEUE_SLUG,
				'lagr_view' => 'merge',
				'term_ids'  => array( $term->term_id ),
				'target'    => $id,
			),
			admin_url( 'admin.php' )
		);
		return sprintf( '%s <a href="%s" class="button button-small">склеить сюда</a>', esc_html( $target->name ), esc_url( $url ) );
	}

	protected function column_proposed( $term ) {
		return (int) get_term_meta( $term->term_id, LAGR_Vocabulary::META_PROPOSED_COUNT, true );
	}

	protected function column_first( $term ) {
		$ts = (int) get_term_meta( $term->term_id, LAGR_Vocabulary::META_FIRST_SEEN, true );
		return $ts ? esc_html( wp_date( 'd.m.Y', $ts ) ) : '—';
	}

	public function no_items() {
		echo 'Заявок нет.';
	}
}
