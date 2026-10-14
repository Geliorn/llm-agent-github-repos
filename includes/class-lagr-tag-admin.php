<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin side of the task vocabulary: "Словарь задач" (overview, seed,
 * migration, settings, blocklist) and "Теги: заявки" (candidate queue,
 * regulator proposals, merge preview) under the GitHub-репозитории menu,
 * plus status/definition/aliases on the regular post_tag edit screen.
 *
 * Names deliberately differ from the existing "Очередь модерации" — that one
 * queues repos, this one queues tags.
 */
class LAGR_Tag_Admin {

	const VOCAB_SLUG = 'lagr-tag-vocabulary';
	const QUEUE_SLUG = 'lagr-tag-queue';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'handle_queue_actions' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		foreach ( array( 'merge', 'seed', 'migrate', 'save_settings', 'unblock', 'proposal', 'regulate' ) as $a ) {
			add_action( 'admin_post_lagr_tag_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}

		// Regular post_tag screens: status column, fields, a pointer to the vocabulary.
		add_action( 'post_tag_edit_form_fields', array( __CLASS__, 'term_fields' ) );
		add_action( 'edited_post_tag', array( __CLASS__, 'save_term_fields' ) );
		add_filter( 'manage_edit-post_tag_columns', array( __CLASS__, 'term_columns' ) );
		add_filter( 'manage_post_tag_custom_column', array( __CLASS__, 'term_column' ), 10, 3 );
	}

	public static function register_menu() {
		add_submenu_page( LAGR_Admin::MAIN_SLUG, 'Словарь задач', 'Словарь задач', 'manage_options', self::VOCAB_SLUG, array( __CLASS__, 'render_vocabulary_page' ) );
		$counts = count( LAGR_Vocabulary::terms_by_status( LAGR_Vocabulary::STATUS_CANDIDATE, array( 'fields' => 'ids', 'meta_query' => array( array( 'key' => LAGR_Vocabulary::META_PRIORITY, 'value' => array( 'high', 'ready' ), 'compare' => 'IN' ) ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$label  = 'Теги: заявки' . ( $counts ? ' <span class="awaiting-mod">' . (int) $counts . '</span>' : '' );
		add_submenu_page( LAGR_Admin::MAIN_SLUG, 'Теги: заявки', $label, 'manage_options', self::QUEUE_SLUG, array( __CLASS__, 'render_queue_page' ) );
	}

	// --------------------------------------------------------------- notices

	private static function notice_key() {
		return 'lagr_tag_notice_' . get_current_user_id();
	}

	public static function flash( $message, $type = 'success' ) {
		set_transient(
			self::notice_key(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	public static function render_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		if ( 'edit-post_tag' === $screen->id ) {
			printf(
				'<div class="notice notice-info"><p>Метки — это <strong>словарь задач</strong> сайта: новые метки появляются кандидатами и не видны на сайте до утверждения. <a class="button button-small" href="%s">Словарь задач</a> <a class="button button-small" href="%s">Теги: заявки</a></p></div>',
				esc_url( admin_url( 'admin.php?page=' . self::VOCAB_SLUG ) ),
				esc_url( admin_url( 'admin.php?page=' . self::QUEUE_SLUG ) )
			);
		}
		if ( false === strpos( (string) $screen->id, self::VOCAB_SLUG ) && false === strpos( (string) $screen->id, self::QUEUE_SLUG ) ) {
			return;
		}
		$n = get_transient( self::notice_key() );
		if ( $n ) {
			delete_transient( self::notice_key() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n['type'] ), wp_kses_post( $n['message'] ) );
		}
	}

	private static function back( $slug, array $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $slug ) ) );
		exit;
	}

	private static function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
	}

	// ------------------------------------------------------------- shared UI

	/** Dropdown of approved terms grouped by vocabulary group. */
	public static function target_select( $name, $selected = 0 ) {
		$groups = array();
		foreach ( LAGR_Vocabulary::approved_terms() as $t ) {
			$g              = (string) get_term_meta( $t->term_id, LAGR_Vocabulary::META_GROUP, true ) ?: 'Без группы';
			$groups[ $g ][] = $t;
		}
		echo '<select name="' . esc_attr( $name ) . '"><option value="0">— цель склейки —</option>';
		foreach ( $groups as $g => $terms ) {
			echo '<optgroup label="' . esc_attr( $g ) . '">';
			foreach ( $terms as $t ) {
				printf( '<option value="%d" %s>%s (%d)</option>', (int) $t->term_id, selected( (int) $selected, (int) $t->term_id, false ), esc_html( $t->name ), (int) $t->count );
			}
			echo '</optgroup>';
		}
		echo '</select>';
	}

	// ----------------------------------------------------------------- queue

	/** Row and bulk actions of the queue table — handled before output so we can redirect. */
	public static function handle_queue_actions() {
		if ( ( $_GET['page'] ?? '' ) !== self::QUEUE_SLUG ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		// Row actions.
		if ( ! empty( $_GET['lagr_do'] ) && ! empty( $_GET['term_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::require_cap();
			$id = (int) $_GET['term_id'];
			check_admin_referer( 'lagr_tag_row_' . $id );
			$r = 'approve' === $_GET['lagr_do'] ? LAGR_Tag_Queue::approve( $id ) : LAGR_Tag_Queue::reject( $id );
			is_wp_error( $r ) ? self::flash( $r->get_error_message(), 'error' ) : self::flash( 'approve' === $_GET['lagr_do'] ? 'Термин утверждён — страница появилась на сайте.' : 'Термин отклонён и добавлен в blocklist.' );
			self::back( self::QUEUE_SLUG );
		}

		// Bulk actions (WP_List_Table form).
		$action = sanitize_key( $_GET['action'] ?? '-1' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( '-1' === $action || '' === $action ) {
			$action = sanitize_key( $_GET['action2'] ?? '-1' ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$ids = array_filter( array_map( 'intval', (array) ( $_GET['term_ids'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! in_array( $action, array( 'approve', 'reject', 'merge' ), true ) || ! $ids || ! empty( $_GET['lagr_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		self::require_cap();
		check_admin_referer( 'bulk-lagr_tags' );

		if ( 'merge' === $action ) {
			// Merge never happens straight from the table — preview first.
			self::back(
				self::QUEUE_SLUG,
				array(
					'lagr_view' => 'merge',
					'term_ids'  => $ids,
					'target'    => (int) ( $_GET['merge_target'] ?? 0 ),
				)
			);
		}

		$ok   = 0;
		$errs = array();
		foreach ( $ids as $id ) {
			$r = 'approve' === $action ? LAGR_Tag_Queue::approve( $id ) : LAGR_Tag_Queue::reject( $id );
			is_wp_error( $r ) ? $errs[] = $r->get_error_message() : ++$ok;
		}
		self::flash( sprintf( '%s: %d.', 'approve' === $action ? 'Утверждено' : 'Отклонено', $ok ) . ( $errs ? '<br>' . implode( '<br>', array_map( 'esc_html', $errs ) ) : '' ), $errs ? 'warning' : 'success' );
		self::back( self::QUEUE_SLUG );
	}

	public static function render_queue_page() {
		if ( 'merge' === ( $_GET['lagr_view'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::render_merge_preview();
			return;
		}

		require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-tag-list-table.php';
		$table = new LAGR_Tag_List_Table();
		$table->prepare_items();

		echo '<div class="wrap"><h1 class="wp-heading-inline">Теги: заявки</h1>';
		echo '<p class="description">Кандидаты уже проставлены своим записям, но не видны на сайте (noindex, нет в облаке и sitemap). «Утвердить» — одно переключение: страница появится сразу. «Склеить» переносит записи и делает имя кандидата алиасом цели — в следующий раз такой топик разрешится без модели. «Отклонить» удаляет термин и вносит имя в blocklist.</p>';

		$summary = LAGR_Tag_Regulator::last_summary();
		if ( $summary ) {
			printf( '<div class="notice notice-info inline"><p><strong>Регулятор, %s:</strong> %s</p></div>', esc_html( wp_date( 'd.m.Y H:i', $summary['time'] ) ), esc_html( LAGR_Tag_Regulator::summary_text( $summary ) ) );
		}

		self::render_proposals();

		$base = admin_url( 'admin.php?page=' . self::QUEUE_SLUG );
		$cur  = sanitize_key( $_GET['priority'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<ul class="subsubsub">';
		foreach ( array( '' => 'Все', 'high' => 'Высокий приоритет', 'ready' => 'Готовы к ревью' ) as $k => $label ) {
			printf( '<li><a href="%s" %s>%s</a>%s</li>', esc_url( $k ? add_query_arg( 'priority', $k, $base ) : $base ), $cur === $k ? 'class="current"' : '', esc_html( $label ), 'ready' === $k ? '' : ' | ' );
		}
		echo '</ul>';

		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::QUEUE_SLUG ) . '">';
		$table->search_box( 'Найти', 'lagr-tag' );
		$table->display();
		echo '</form></div>';
	}

	private static function render_proposals() {
		$proposals = LAGR_Tag_Queue::proposals();
		if ( ! $proposals ) {
			return;
		}
		echo '<h2>Предложения регулятора: склейки</h2><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Источник</th><th>Цель</th><th>Причина</th><th></th></tr></thead><tbody>';
		foreach ( $proposals as $p ) {
			$s = get_term( (int) $p['source'] );
			$t = get_term( (int) $p['target'] );
			if ( ! $s || is_wp_error( $s ) || ! $t || is_wp_error( $t ) ) {
				LAGR_Tag_Queue::drop_proposals_for( (int) $p['source'] );
				continue;
			}
			$preview = add_query_arg(
				array(
					'lagr_view' => 'merge',
					'term_ids'  => array( $s->term_id ),
					'target'    => $t->term_id,
				),
				admin_url( 'admin.php?page=' . self::QUEUE_SLUG )
			);
			$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=lagr_tag_proposal&source=' . $s->term_id ), 'lagr_tag_proposal' );
			printf(
				'<tr><td>%s (%d)</td><td>%s (%d)</td><td>%s</td><td><a class="button button-small button-primary" href="%s">Посмотреть склейку</a> <a class="button button-small" href="%s">Не склеивать</a></td></tr>',
				esc_html( $s->name ),
				(int) $s->count,
				esc_html( $t->name ),
				(int) $t->count,
				esc_html( $p['reason'] ),
				esc_url( $preview ),
				esc_url( $dismiss )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_merge_preview() {
		$ids    = array_filter( array_map( 'intval', (array) ( $_GET['term_ids'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$target = (int) ( $_GET['target'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$back   = admin_url( 'admin.php?page=' . self::QUEUE_SLUG );

		echo '<div class="wrap"><h1>Склейка тегов</h1>';
		if ( ! $target ) {
			echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::QUEUE_SLUG ) . '"><input type="hidden" name="lagr_view" value="merge">';
			foreach ( $ids as $id ) {
				echo '<input type="hidden" name="term_ids[]" value="' . (int) $id . '">';
			}
			echo '<p>Выберите, во что склеить: ';
			self::target_select( 'target' );
			echo ' <button class="button button-primary">Показать последствия</button></p></form></div>';
			return;
		}

		echo '<p><strong>Расклейки нет</strong> — решение необратимо, поэтому сначала последствия.</p>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Источник</th><th>Цель</th><th>Записей переедет</th><th>Уже несут цель</th><th>Новые алиасы цели</th><th>Источник после</th></tr></thead><tbody>';
		$valid = array();
		foreach ( $ids as $id ) {
			$p = LAGR_Tag_Queue::preview( $id, $target );
			if ( is_wp_error( $p ) ) {
				printf( '<tr><td colspan="6">#%d — %s</td></tr>', (int) $id, esc_html( $p->get_error_message() ) );
				continue;
			}
			$valid[] = $id;
			printf(
				'<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
				esc_html( $p['source']->name ),
				esc_html( $p['target']->name ),
				(int) $p['move'],
				(int) $p['already'],
				esc_html( implode( ', ', $p['aliases'] ) ?: '—' ),
				'deleted' === $p['source_after'] ? 'удаляется (страницы не было)' : 'остаётся как merged, 301 на цель'
			);
		}
		echo '</tbody></table>';

		if ( $valid ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px">';
			wp_nonce_field( 'lagr_tag_merge' );
			echo '<input type="hidden" name="action" value="lagr_tag_merge"><input type="hidden" name="target" value="' . (int) $target . '">';
			foreach ( $valid as $id ) {
				echo '<input type="hidden" name="term_ids[]" value="' . (int) $id . '">';
			}
			submit_button( 'Склеить', 'primary', 'submit', false );
			echo ' <a class="button" href="' . esc_url( $back ) . '">Отмена</a></form>';
		} else {
			echo '<p><a class="button" href="' . esc_url( $back ) . '">Назад</a></p>';
		}
		echo '</div>';
	}

	public static function handle_merge() {
		self::require_cap();
		check_admin_referer( 'lagr_tag_merge' );
		$ids = array_filter( array_map( 'intval', (array) ( $_POST['term_ids'] ?? array() ) ) );
		$r   = LAGR_Tag_Queue::merge_many( $ids, (int) ( $_POST['target'] ?? 0 ) );
		self::flash( sprintf( 'Склеено: %d.', $r['done'] ) . ( $r['errors'] ? '<br>' . implode( '<br>', array_map( 'esc_html', $r['errors'] ) ) : '' ), $r['errors'] ? 'warning' : 'success' );
		self::back( self::QUEUE_SLUG );
	}

	public static function handle_proposal() {
		self::require_cap();
		check_admin_referer( 'lagr_tag_proposal' );
		LAGR_Tag_Queue::drop_proposals_for( (int) ( $_GET['source'] ?? 0 ) );
		self::flash( 'Предложение снято.' );
		self::back( self::QUEUE_SLUG );
	}

	// ------------------------------------------------------------ vocabulary

	public static function render_vocabulary_page() {
		$counts = LAGR_Vocabulary::counts();
		$s      = LAGR_Settings::get_all();
		echo '<div class="wrap"><h1>Словарь задач</h1>';
		echo '<p class="description">Сквозная ось «какую задачу решает» для репозиториев, статей и поставщиков (таксономия <code>post_tag</code>). Разделы каталога (<code>repo_topic</code>) — отдельная ось: один термин живёт только в одной.</p>';

		printf(
			'<p style="font-size:15px"><strong>%d</strong> разделов · <strong>%d</strong> задач утверждено · <strong>%d</strong> кандидатов <a class="button button-small" href="%s">Теги: заявки</a> <a class="button button-small" href="%s">Все метки</a></p>',
			(int) $counts['sections'],
			(int) $counts['approved'],
			(int) $counts['candidates'],
			esc_url( admin_url( 'admin.php?page=' . self::QUEUE_SLUG ) ),
			esc_url( admin_url( 'edit-tags.php?taxonomy=post_tag' ) )
		);

		$stats = LAGR_Vocabulary::stats();
		if ( $stats ) {
			echo '<p><strong>Размечено без модели (только алиасы):</strong> ';
			$parts = array();
			foreach ( array_slice( $stats, -6, null, true ) as $week => $row ) {
				$parts[] = sprintf( '%s — %d%% из %d', esc_html( $week ), $row['total'] ? round( 100 * $row['step1'] / $row['total'] ) : 0, (int) $row['total'] );
			}
			echo implode( ' · ', $parts ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		// ---- seed + migration
		echo '<h2>Заведение словаря и миграция</h2>';
		if ( ! LAGR_Vocabulary::is_seeded() ) {
			echo '<p>Словарь ещё не заведён. Кнопка создаёт 84 утверждённые метки с определениями и алиасами из <code>includes/lagr-vocabulary-seed.php</code> и заводит алиасы разделов на <code>repo_topic</code>. Старые метки не трогает — для них миграция ниже.</p>';
		} else {
			printf( '<p>Словарь заведён %s. Повторный запуск досоздаёт недостающие термины и алиасы, ничего не перезаписывая.</p>', esc_html( wp_date( 'd.m.Y H:i', (int) get_option( LAGR_Vocabulary::SEEDED_OPTION ) ) ) );
		}
		self::post_button( 'lagr_tag_seed', LAGR_Vocabulary::is_seeded() ? 'Досеять словарь из файла' : 'Завести словарь (84 термина)', ! LAGR_Vocabulary::is_seeded() );

		if ( LAGR_Tag_Migrate::is_done() ) {
			printf( '<p style="margin-top:14px">Миграция старых меток применена %s. Регулятор включён.</p>', esc_html( wp_date( 'd.m.Y H:i', (int) get_option( LAGR_Tag_Migrate::DONE_OPTION ) ) ) );
		}
		$legacy = count( LAGR_Tag_Migrate::legacy_terms() );
		printf( '<p style="margin-top:14px">Старых меток без статуса: <strong>%d</strong>. ', (int) $legacy );
		printf( '<a class="button" href="%s">Посчитать миграцию (dry-run)</a></p>', esc_url( admin_url( 'admin.php?page=' . self::VOCAB_SLUG . '&dry=1' ) ) );

		if ( ! empty( $_GET['dry'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$r = LAGR_Tag_Migrate::plan();
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Что</th><th>Терминов</th><th>Привязок</th><th>Примеры</th></tr></thead><tbody>';
			foreach ( LAGR_Tag_Migrate::report_rows( $r ) as $label => $b ) {
				printf( '<tr><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>', esc_html( $label ), (int) $b['terms'], (int) $b['records'], esc_html( implode( ', ', $b['examples'] ) ) );
			}
			printf( '<tr><td>Словарь задач</td><td colspan="3">создать %d, обновить %d</td></tr>', (int) ( $r['seed']['created'] ?? 0 ), (int) ( $r['seed']['updated'] ?? 0 ) );
			foreach ( (array) ( $r['seed']['conflicts'] ?? array() ) as $c ) {
				printf( '<tr><td colspan="4" style="color:#b32d2e">%s</td></tr>', esc_html( $c ) );
			}
			echo '</tbody></table><p class="description">Склеенные старые метки остаются как <code>merged</code> и отдают 301 на новый термин или раздел — их страницы были публичными. После применения включается регулятор.</p>';
			self::post_button( 'lagr_tag_migrate', 'Применить миграцию', true, 'Миграция необратима. Применить?' );
		}

		// ---- approved list
		echo '<h2 style="margin-top:30px">Утверждённые задачи</h2>';
		$breadth = LAGR_Tag_Regulator::type_breadth();
		$rows    = array();
		foreach ( LAGR_Vocabulary::approved_terms() as $t ) {
			$rows[ (string) get_term_meta( $t->term_id, LAGR_Vocabulary::META_GROUP, true ) ?: 'Без группы' ][] = $t;
		}
		echo '<table class="widefat striped"><thead><tr><th>Термин</th><th>Слаг</th><th>Определение</th><th>Алиасов</th><th>Записей</th><th>Типов</th></tr></thead><tbody>';
		foreach ( $rows as $group => $terms ) {
			printf( '<tr><th colspan="6" style="background:#f0f0f1">%s</th></tr>', esc_html( $group ) );
			foreach ( $terms as $t ) {
				printf(
					'<tr><td><a href="%s">%s</a></td><td><code>%s</code></td><td>%s</td><td title="%s">%d</td><td>%d</td><td>%d</td></tr>',
					esc_url( get_edit_term_link( $t->term_id, LAGR_Vocabulary::TAXONOMY ) ),
					esc_html( $t->name ),
					esc_html( $t->slug ),
					esc_html( get_term_meta( $t->term_id, LAGR_Vocabulary::META_DEFINITION, true ) ),
					esc_attr( implode( ', ', LAGR_Vocabulary::aliases( $t->term_id ) ) ),
					count( LAGR_Vocabulary::aliases( $t->term_id ) ),
					(int) $t->count,
					(int) ( $breadth[ $t->term_id ] ?? 0 )
				);
			}
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">Пока пусто.</td></tr>';
		}
		echo '</tbody></table>';

		// ---- regulator
		echo '<h2 style="margin-top:30px">Регулятор</h2>';
		$summary = LAGR_Tag_Regulator::last_summary();
		echo '<p>' . ( $summary ? esc_html( wp_date( 'd.m.Y H:i', $summary['time'] ) . ': ' . LAGR_Tag_Regulator::summary_text( $summary ) ) : 'Ещё не запускался.' ) . '</p>';
		self::post_button( 'lagr_tag_regulate', 'Запустить регулятор сейчас' );

		// ---- settings
		echo '<h2 style="margin-top:30px">Настройки</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'lagr_tag_save_settings' );
		echo '<input type="hidden" name="action" value="lagr_tag_save_settings"><table class="form-table">';
		$num = function ( $key, $label, $hint ) use ( $s ) {
			printf( '<tr><th>%s</th><td><input type="number" min="0" name="%s" value="%s" style="width:100px"> <span class="description">%s</span></td></tr>', esc_html( $label ), esc_attr( $key ), esc_attr( $s[ $key ] ), esc_html( $hint ) );
		};
		printf(
			'<tr><th>Нераспознанные топики без модели</th><td><select name="tags_unresolved_policy"><option value="candidate" %s>в кандидаты (невидимы, сигнал сохраняется)</option><option value="drop" %s>отбрасывать</option></select></td></tr>',
			selected( $s['tags_unresolved_policy'], 'candidate', false ),
			selected( $s['tags_unresolved_policy'], 'drop', false )
		);
		$num( 'tags_candidates_per_run', 'Новых кандидатов за прогон', 'лимит на один запуск правила; 0 — без лимита' );
		$num( 'tags_candidates_per_day', 'Новых кандидатов в сутки', 'сверх лимита — не создаются, пишется в журнал' );
		$num( 'tags_alias_threshold', 'Порог авто-алиаса', 'сколько раз модель сопоставила строку с термином, прежде чем строка станет алиасом' );
		$num( 'tags_ready_threshold', 'Кандидат «готов к ревью» от', 'записей (регулятор снижает порог, если словарь меньше коридора)' );
		$num( 'tags_floor', 'Нижний порог утверждённого', 'записей; меньше 90 дней — предложение склейки' );
		$num( 'tags_corridor_min', 'Коридор словаря: от', 'утверждённых терминов' );
		$num( 'tags_corridor_max', 'Коридор словаря: до', 'утверждённых терминов' );
		printf( '<tr><th>Авто-утверждение</th><td><label><input type="checkbox" name="tags_auto_approve" value="1" %s> утверждать кандидата без человека, если записей ≥ </label><input type="number" min="1" name="tags_auto_approve_count" value="%s" style="width:80px"> и он есть у ≥ 2 типов записей</td></tr>', checked( $s['tags_auto_approve'], 1, false ), esc_attr( $s['tags_auto_approve_count'] ) );
		printf( '<tr><th>Регулятор</th><td><label><input type="checkbox" name="tags_regulator_enabled" value="1" %s> еженедельный прогон внутри тика (включается миграцией)</label></td></tr>', checked( $s['tags_regulator_enabled'], 1, false ) );
		echo '</table>';
		submit_button( 'Сохранить' );
		echo '</form>';

		// ---- blocklist
		$block = LAGR_Vocabulary::blocklist();
		echo '<h2 style="margin-top:30px">Blocklist (' . count( $block ) . ')</h2>';
		if ( $block ) {
			echo '<p>';
			foreach ( $block as $hash => $name ) {
				printf(
					'<span style="display:inline-block;margin:0 6px 6px 0;padding:2px 8px;background:#f0f0f1;border-radius:3px">%s <a href="%s" title="Убрать из blocklist" style="text-decoration:none">×</a></span>',
					esc_html( $name ),
					esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lagr_tag_unblock&hash=' . $hash ), 'lagr_tag_unblock' ) )
				);
			}
			echo '</p>';
		} else {
			echo '<p>Пусто.</p>';
		}
		echo '</div>';
	}

	private static function post_button( $action, $label, $primary = false, $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px"' . ( $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) . '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		submit_button( $label, $primary ? 'primary' : 'secondary', 'submit', false );
		echo '</form>';
	}

	public static function handle_seed() {
		self::require_cap();
		check_admin_referer( 'lagr_tag_seed' );
		$r = LAGR_Vocabulary::seed();
		self::flash( sprintf( 'Словарь: создано %d, обновлено %d, разделов с алиасами %d.', $r['created'], $r['updated'], $r['sections'] ) . ( $r['conflicts'] ? '<br>' . implode( '<br>', array_map( 'esc_html', $r['conflicts'] ) ) : '' ), $r['conflicts'] ? 'warning' : 'success' );
		self::back( self::VOCAB_SLUG );
	}

	public static function handle_migrate() {
		self::require_cap();
		check_admin_referer( 'lagr_tag_migrate' );
		$r = LAGR_Tag_Migrate::apply();
		if ( is_wp_error( $r ) ) {
			self::flash( $r->get_error_message(), 'error' );
		} else {
			self::flash( 'Миграция применена: ' . esc_html( LAGR_Tag_Migrate::report_text( $r ) ) . ( $r['errors'] ? '<br>' . implode( '<br>', array_map( 'esc_html', array_slice( $r['errors'], 0, 20 ) ) ) : '' ), $r['errors'] ? 'warning' : 'success' );
		}
		self::back( self::VOCAB_SLUG );
	}

	public static function handle_regulate() {
		self::require_cap();
		check_admin_referer( 'lagr_tag_regulate' );
		delete_transient( LAGR_Tag_Regulator::COOC_TRANSIENT );
		update_option( LAGR_Tag_Regulator::LAST_RUN_OPTION, time(), false );
		$s = LAGR_Tag_Regulator::run();
		self::flash( esc_html( LAGR_Tag_Regulator::summary_text( $s ) ) );
		self::back( self::VOCAB_SLUG );
	}

	public static function handle_unblock() {
		self::require_cap();
		check_admin_referer( 'lagr_tag_unblock' );
		LAGR_Vocabulary::unblock( sanitize_key( $_GET['hash'] ?? '' ) );
		self::flash( 'Убрано из blocklist.' );
		self::back( self::VOCAB_SLUG );
	}

	public static function handle_save_settings() {
		self::require_cap();
		check_admin_referer( 'lagr_tag_save_settings' );
		$values = array(
			'tags_unresolved_policy' => 'drop' === ( $_POST['tags_unresolved_policy'] ?? '' ) ? 'drop' : 'candidate',
			'tags_auto_approve'      => isset( $_POST['tags_auto_approve'] ) ? 1 : 0,
			'tags_regulator_enabled' => isset( $_POST['tags_regulator_enabled'] ) ? 1 : 0,
		);
		foreach ( array( 'tags_candidates_per_run', 'tags_candidates_per_day', 'tags_alias_threshold', 'tags_ready_threshold', 'tags_floor', 'tags_corridor_min', 'tags_corridor_max', 'tags_auto_approve_count' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$values[ $key ] = max( 0, (int) $_POST[ $key ] );
			}
		}
		LAGR_Settings::update( $values );
		// Base thresholds changed — let the corridor start again from them.
		delete_option( LAGR_Tag_Regulator::STATE_OPTION );
		self::flash( 'Настройки словаря сохранены.' );
		self::back( self::VOCAB_SLUG );
	}

	// ------------------------------------------------- post_tag edit screen

	public static function term_fields( $term ) {
		$status  = LAGR_Vocabulary::status( $term->term_id );
		$labels  = array(
			''                                => 'без статуса (старая метка, видна до миграции)',
			LAGR_Vocabulary::STATUS_APPROVED  => 'утверждён — публичная страница',
			LAGR_Vocabulary::STATUS_CANDIDATE => 'кандидат — скрыт с сайта',
			LAGR_Vocabulary::STATUS_MERGED    => 'склеен — 301 на цель',
		);
		$proposal = get_term_meta( $term->term_id, LAGR_Vocabulary::META_PROPOSAL, true );
		wp_nonce_field( 'lagr_tag_term_' . $term->term_id, 'lagr_tag_term_nonce' );
		?>
		<tr class="form-field">
			<th scope="row">Статус в словаре</th>
			<td>
				<?php if ( LAGR_Vocabulary::STATUS_MERGED === $status ) : ?>
					<?php $t = LAGR_Tag_Queue::final_target( $term->term_id ); ?>
					<p>Склеен → <?php echo $t ? '<a href="' . esc_url( get_edit_term_link( $t->term_id, $t->taxonomy ) ) . '">' . esc_html( $t->name ) . '</a>' : '—'; ?></p>
				<?php else : ?>
					<select name="lagr_tag_status">
						<?php foreach ( array( LAGR_Vocabulary::STATUS_APPROVED, LAGR_Vocabulary::STATUS_CANDIDATE ) as $st ) : ?>
							<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $status, $st ); ?>><?php echo esc_html( $labels[ $st ] ); ?></option>
						<?php endforeach; ?>
						<?php if ( '' === $status ) : ?>
							<option value="" selected><?php echo esc_html( $labels[''] ); ?></option>
						<?php endif; ?>
					</select>
					<p class="description">Склейка и отклонение — в «Теги: заявки».</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="lagr_tag_definition">Определение задачи</label></th>
			<td><input type="text" id="lagr_tag_definition" name="lagr_tag_definition" value="<?php echo esc_attr( get_term_meta( $term->term_id, LAGR_Vocabulary::META_DEFINITION, true ) ); ?>">
			<p class="description">Одна строка — часть промпта модели: по ней она решает, подходит ли термин.</p></td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="lagr_tag_aliases">Алиасы</label></th>
			<td><textarea id="lagr_tag_aliases" name="lagr_tag_aliases" rows="4"><?php echo esc_textarea( implode( "\n", LAGR_Vocabulary::aliases( $term->term_id ) ) ); ?></textarea>
			<p class="description">По одному в строке. Строки, которые разрешаются в этот термин без вызова модели (регистр и пунктуация не важны).</p></td>
		</tr>
		<?php if ( is_array( $proposal ) && ! empty( $proposal['why'] ) ) : ?>
			<tr class="form-field"><th scope="row">Обоснование модели</th><td><?php echo esc_html( $proposal['why'] ); ?><?php echo ! empty( $proposal['closest_existing'] ) ? ' — ближайший: <code>' . esc_html( $proposal['closest_existing'] ) . '</code>' : ''; ?></td></tr>
		<?php endif; ?>
		<?php
	}

	public static function save_term_fields( $term_id ) {
		if ( ! isset( $_POST['lagr_tag_term_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['lagr_tag_term_nonce'] ), 'lagr_tag_term_' . $term_id ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		if ( isset( $_POST['lagr_tag_definition'] ) ) {
			update_term_meta( $term_id, LAGR_Vocabulary::META_DEFINITION, sanitize_text_field( wp_unslash( $_POST['lagr_tag_definition'] ) ) );
		}
		if ( isset( $_POST['lagr_tag_aliases'] ) ) {
			$lines = array_filter( array_map( 'sanitize_text_field', preg_split( '/[\r\n,]+/', (string) wp_unslash( $_POST['lagr_tag_aliases'] ) ) ) );
			delete_term_meta( $term_id, LAGR_Vocabulary::META_ALIASES );
			LAGR_Vocabulary::add_aliases( $term_id, $lines );
		}
		$new = sanitize_key( $_POST['lagr_tag_status'] ?? '' );
		$old = LAGR_Vocabulary::status( $term_id );
		if ( $new && $new !== $old && LAGR_Vocabulary::STATUS_MERGED !== $old ) {
			if ( LAGR_Vocabulary::STATUS_APPROVED === $new ) {
				$r = LAGR_Tag_Queue::approve( $term_id );
				if ( is_wp_error( $r ) ) {
					self::flash( $r->get_error_message(), 'error' );
				}
			} elseif ( LAGR_Vocabulary::STATUS_CANDIDATE === $new ) {
				LAGR_Vocabulary::set_status( $term_id, LAGR_Vocabulary::STATUS_CANDIDATE );
				LAGR_Log::add( sprintf( 'Теги: #%d вручную переведён в кандидаты', $term_id ) );
			}
		}
		$term = get_term( $term_id );
		if ( $term && ! is_wp_error( $term ) ) {
			update_term_meta( $term_id, LAGR_Vocabulary::META_HASH, LAGR_Vocabulary::hash( $term->name ) );
		}
		LAGR_Vocabulary::flush_index();
	}

	public static function term_columns( $columns ) {
		$columns['lagr_status'] = 'Словарь';
		return $columns;
	}

	public static function term_column( $content, $column, $term_id ) {
		if ( 'lagr_status' !== $column ) {
			return $content;
		}
		$map = array(
			LAGR_Vocabulary::STATUS_APPROVED  => '✅ утверждён',
			LAGR_Vocabulary::STATUS_CANDIDATE => '⏳ кандидат',
			LAGR_Vocabulary::STATUS_MERGED    => '↪ склеен',
		);
		return esc_html( $map[ LAGR_Vocabulary::status( $term_id ) ] ?? '— старая' );
	}
}
