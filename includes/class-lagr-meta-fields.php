<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers post meta (register_post_meta -> show_in_rest, needed for
 * Block Bindings in templates) and renders the plain-PHP meta boxes for
 * both CPTs. No ACF dependency by design.
 */
class LAGR_Meta_Fields {

	/** Meta keys shown/edited on a github_repo post. */
	public static function repo_meta_keys() {
		return array(
			'_gh_full_name'          => 'string',
			'_gh_url'                => 'string',
			'_gh_stars'              => 'integer',
			'_gh_forks'              => 'integer',
			'_gh_watchers'           => 'integer',
			'_gh_open_issues'        => 'integer',
			'_gh_language'           => 'string',
			'_gh_homepage'           => 'string',
			'_gh_license'            => 'string',
			'_gh_created_at'         => 'string',
			'_gh_pushed_at'          => 'string',
			'_gh_updated_at'         => 'string',
			'_gh_default_branch'     => 'string',
			'_gh_size_kb'            => 'integer',
			'_gh_owner_login'        => 'string',
			'_gh_owner_type'         => 'string',
			'_gh_owner_avatar'       => 'string',
			'short_description'      => 'string',
			'install_command'        => 'string',
			'_gh_score'              => 'number',
			'_gh_needs_review'       => 'boolean',
			'_gh_rule_id'            => 'integer',
			'_gh_contributors_count' => 'integer',
			'_gh_latest_release_tag' => 'string',
			'_gh_latest_release_at'  => 'string',
			'_gh_languages_json'     => 'string',
			'_gh_top_files_json'     => 'string',
			'_gh_generated_by_llm'   => 'boolean',
		);
	}

	/** Meta keys for a github_repo_rule post. */
	public static function rule_meta_keys() {
		return array(
			'_lagr_topics'              => 'string',
			'_lagr_stars_operator'      => 'string',
			'_lagr_stars_value'         => 'integer',
			'_lagr_freshness_days'      => 'integer',
			'_lagr_exclude_forks'       => 'boolean',
			'_lagr_exclude_archived'    => 'boolean',
			'_lagr_require_description' => 'boolean',
			'_lagr_min_size_kb'         => 'integer',
			'_lagr_sort_by'             => 'string',
			'_lagr_limit'               => 'integer',
			'_lagr_raw_query'           => 'string',
			'_lagr_target_post_type'    => 'string',
			'_lagr_publish_mode'        => 'string',
			'_lagr_enabled'             => 'boolean',
			'_lagr_interval_minutes'    => 'integer',
			'_lagr_last_run_at'         => 'integer',
			'_lagr_running_since'       => 'integer',
			'_lagr_last_run_summary'    => 'string',
			'_lagr_last_test_result'    => 'string',
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_github_repo', array( __CLASS__, 'save_repo_meta' ) );
		add_action( 'save_post_github_repo_rule', array( __CLASS__, 'save_rule_meta' ), 10, 3 );
		add_filter( 'is_protected_meta', array( __CLASS__, 'unprotect_for_bindings' ), 10, 2 );
	}

	/**
	 * All our meta keys are underscore-prefixed (by convention, to stay out of
	 * the default Custom Fields metabox — we render our own). But WordPress's
	 * Block Bindings post-meta source explicitly refuses "protected" meta
	 * (is_protected_meta() — true for any leading underscore) REGARDLESS of
	 * show_in_rest. Without this filter, every core/post-meta binding in
	 * class-lagr-patterns.php silently falls back to its static placeholder
	 * text — found by comparing actual DB values against live page output.
	 */
	public static function unprotect_for_bindings( $protected, $meta_key ) {
		if ( array_key_exists( $meta_key, self::repo_meta_keys() ) ) {
			return false;
		}
		return $protected;
	}

	public static function register_meta() {
		foreach ( self::repo_meta_keys() as $key => $type ) {
			register_post_meta(
				'github_repo',
				$key,
				array(
					'show_in_rest' => true,
					'single'       => true,
					'type'         => $type,
				)
			);
		}
		foreach ( self::rule_meta_keys() as $key => $type ) {
			register_post_meta(
				'github_repo_rule',
				$key,
				array(
					'show_in_rest' => false,
					'single'       => true,
					'type'         => $type,
				)
			);
		}
	}

	public static function add_meta_boxes() {
		add_meta_box( 'lagr_repo_data', 'Данные GitHub', array( __CLASS__, 'render_repo_box' ), 'github_repo', 'normal', 'high' );
		add_meta_box( 'lagr_rule_data', 'Настройки правила', array( __CLASS__, 'render_rule_box' ), 'github_repo_rule', 'normal', 'high' );
		add_meta_box( 'lagr_rule_status', 'Статус', array( __CLASS__, 'render_rule_status_box' ), 'github_repo_rule', 'side', 'default' );
	}

	private static function field( $post_id, $key, $label, $type = 'text', $extra = array() ) {
		$value = get_post_meta( $post_id, $key, true );
		echo '<p style="margin:10px 0"><label style="display:block;font-weight:600;margin-bottom:3px">' . esc_html( $label ) . '</label>';

		if ( 'checkbox' === $type ) {
			printf(
				'<input type="checkbox" name="%1$s" value="1" %2$s> <span class="description">%3$s</span>',
				esc_attr( $key ),
				checked( (bool) $value, true, false ),
				esc_html( $extra['description'] ?? '' )
			);
		} elseif ( 'select' === $type ) {
			echo '<select name="' . esc_attr( $key ) . '">';
			foreach ( $extra['choices'] as $choice_value => $choice_label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $choice_value ),
					selected( $value, $choice_value, false ),
					esc_html( $choice_label )
				);
			}
			echo '</select>';
		} elseif ( 'textarea' === $type ) {
			printf(
				'<textarea name="%1$s" rows="%2$d" style="width:100%%">%3$s</textarea>',
				esc_attr( $key ),
				intval( $extra['rows'] ?? 3 ),
				esc_textarea( $value )
			);
		} else {
			printf(
				'<input type="%1$s" name="%2$s" value="%3$s" style="width:100%%" %4$s>',
				esc_attr( $type ),
				esc_attr( $key ),
				esc_attr( $value ),
				isset( $extra['step'] ) ? 'step="' . esc_attr( $extra['step'] ) . '"' : ''
			);
		}
		if ( ! empty( $extra['description'] ) && 'checkbox' !== $type ) {
			echo '<span class="description" style="display:block">' . esc_html( $extra['description'] ) . '</span>';
		}
		echo '</p>';
	}

	public static function render_repo_box( $post ) {
		wp_nonce_field( 'lagr_save_repo', 'lagr_repo_nonce' );
		echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px">';
		self::field( $post->ID, '_gh_full_name', 'full_name (owner/repo)', 'text' );
		self::field( $post->ID, 'short_description', 'Короткий анонс', 'textarea' );
		self::field( $post->ID, 'install_command', 'Команда установки', 'text', array( 'description' => 'Напр. npx -y @scope/pkg. Пусто — на странице покажется git clone.' ) );
		self::field( $post->ID, '_gh_stars', 'Звёзды', 'number' );
		self::field( $post->ID, '_gh_forks', 'Форки', 'number' );
		self::field( $post->ID, '_gh_watchers', 'Watchers', 'number' );
		self::field( $post->ID, '_gh_open_issues', 'Открытые issues', 'number' );
		self::field( $post->ID, '_gh_language', 'Язык', 'text' );
		self::field( $post->ID, '_gh_license', 'Лицензия', 'text' );
		self::field( $post->ID, '_gh_homepage', 'Homepage', 'url' );
		self::field( $post->ID, '_gh_default_branch', 'Default branch', 'text' );
		self::field( $post->ID, '_gh_size_kb', 'Размер, КБ', 'number' );
		self::field( $post->ID, '_gh_created_at', 'Создан', 'text' );
		self::field( $post->ID, '_gh_pushed_at', 'Последний push', 'text' );
		self::field( $post->ID, '_gh_updated_at', 'Обновлён', 'text' );
		self::field( $post->ID, '_gh_owner_login', 'Владелец', 'text' );
		self::field( $post->ID, '_gh_owner_type', 'Тип владельца', 'text' );
		self::field( $post->ID, '_gh_score', 'Score', 'number', array( 'step' => '0.01' ) );
		self::field( $post->ID, '_gh_contributors_count', 'Контрибьюторы', 'number' );
		self::field( $post->ID, '_gh_latest_release_tag', 'Последний релиз', 'text' );
		self::field( $post->ID, '_gh_needs_review', 'Есть обновление источника — нужна проверка', 'checkbox' );
		echo '</div>';
		echo '<p class="description">Полный текст (README/сгенерированное описание) — в обычном редакторе записи выше.</p>';
	}

	public static function save_repo_meta( $post_id ) {
		if ( ! isset( $_POST['lagr_repo_nonce'] ) || ! wp_verify_nonce( $_POST['lagr_repo_nonce'], 'lagr_save_repo' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( self::repo_meta_keys() as $key => $type ) {
			if ( 'boolean' === $type ) {
				update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? 1 : 0 );
				continue;
			}
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_POST[ $key ] );
			update_post_meta( $post_id, $key, 'string' === $type ? sanitize_text_field( $raw ) : $raw );
		}
	}

	public static function render_rule_box( $post ) {
		wp_nonce_field( 'lagr_save_rule', 'lagr_rule_nonce' );

		self::field( $post->ID, '_lagr_topics', 'GitHub-топики (через запятую, комбинируются через И)', 'text', array( 'description' => 'Например: skills, gamedev' ) );

		echo '<div style="display:flex;gap:12px;align-items:flex-end">';
		self::field( $post->ID, '_lagr_stars_operator', 'Звёзды — оператор', 'select', array( 'choices' => array( '>' => '>', '>=' => '>=', '=' => '=' ) ) );
		self::field( $post->ID, '_lagr_stars_value', 'Звёзды — число', 'number' );
		echo '</div>';

		self::field( $post->ID, '_lagr_freshness_days', 'Свежесть — обновлялся не позднее N дней (0 = без ограничения)', 'number' );
		self::field( $post->ID, '_lagr_exclude_forks', 'Исключать форки', 'checkbox' );
		self::field( $post->ID, '_lagr_exclude_archived', 'Исключать архивные', 'checkbox' );
		self::field( $post->ID, '_lagr_require_description', 'Требовать описание', 'checkbox' );
		self::field( $post->ID, '_lagr_min_size_kb', 'Мин. размер репозитория, КБ', 'number' );
		self::field( $post->ID, '_lagr_sort_by', 'Сортировка результатов', 'select', array( 'choices' => array( 'updated' => 'updated', 'stars' => 'stars', 'forks' => 'forks' ) ) );
		self::field( $post->ID, '_lagr_limit', 'Лимит за один запуск (макс. 100)', 'number' );
		self::field(
			$post->ID,
			'_lagr_raw_query',
			'Сырой GitHub-запрос (продвинутый режим, необязательно)',
			'text',
			array( 'description' => 'Если заполнено — используется как есть вместо полей выше. Пример: topic:skills topic:gamedev stars:>10 pushed:>2026-01-01' )
		);
		self::field( $post->ID, '_lagr_target_post_type', 'Куда публиковать (post type)', 'text', array( 'description' => 'По умолчанию github_repo. Можно указать существующий CPT сайта, например mcp или skill.' ) );
		self::field( $post->ID, '_lagr_publish_mode', 'Режим публикации', 'select', array( 'choices' => array( 'draft' => 'Черновик на модерацию', 'publish' => 'Сразу публиковать' ) ) );
		self::field( $post->ID, '_lagr_interval_minutes', 'Интервал запуска, минут (мин. 10)', 'number', array( 'description' => '10=10мин, 60=1ч, 1440=24ч, 2880=48ч, 4320=72ч, 8640=144ч' ) );
		self::field( $post->ID, '_lagr_enabled', 'Правило включено', 'checkbox' );

		$test = get_post_meta( $post->ID, '_lagr_last_test_result', true );
		if ( $test ) {
			echo '<div style="margin-top:10px;padding:10px;background:#f0f0f1;border-left:4px solid #2271b1"><strong>Результат последней проверки запроса:</strong> ' . esc_html( $test ) . '</div>';
		}
	}

	public static function render_rule_status_box( $post ) {
		$last_run = (int) get_post_meta( $post->ID, '_lagr_last_run_at', true );
		$summary  = get_post_meta( $post->ID, '_lagr_last_run_summary', true );
		echo '<p><strong>Последний запуск:</strong><br>' . ( $last_run ? esc_html( human_time_diff( $last_run ) . ' назад' ) : 'ещё не запускалось' ) . '</p>';
		if ( $summary ) {
			echo '<p><strong>Итог:</strong><br>' . esc_html( $summary ) . '</p>';
		}
	}

	public static function save_rule_meta( $post_id, $post, $update ) {
		if ( ! isset( $_POST['lagr_rule_nonce'] ) || ! wp_verify_nonce( $_POST['lagr_rule_nonce'], 'lagr_save_rule' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$skip_runtime = array( '_lagr_last_run_at', '_lagr_running_since', '_lagr_last_run_summary', '_lagr_last_test_result' );

		foreach ( self::rule_meta_keys() as $key => $type ) {
			if ( in_array( $key, $skip_runtime, true ) ) {
				continue; // written only by the collector/scheduler, never from the edit form.
			}
			if ( 'boolean' === $type ) {
				update_post_meta( $post_id, $key, isset( $_POST[ $key ] ) ? 1 : 0 );
				continue;
			}
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_POST[ $key ] );
			update_post_meta( $post_id, $key, 'string' === $type ? sanitize_text_field( $raw ) : $raw );
		}

		// Enforce the 10-minute floor decided in the plan (tick itself only runs every 10 min).
		$interval = (int) get_post_meta( $post_id, '_lagr_interval_minutes', true );
		if ( $interval > 0 && $interval < 10 ) {
			update_post_meta( $post_id, '_lagr_interval_minutes', 10 );
		}

		// Validate the effective query against GitHub right on save (decided in the plan, section on raw query validation).
		if ( class_exists( 'LAGR_Query_Builder' ) && class_exists( 'LAGR_GitHub_Client' ) ) {
			$query  = LAGR_Query_Builder::build_from_rule( $post_id );
			$client = new LAGR_GitHub_Client();
			$result = $client->test_search( $query );
			update_post_meta( $post_id, '_lagr_last_test_result', $result['message'] );
		}
	}
}
