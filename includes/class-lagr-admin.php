<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu: moderation queue (default landing page), settings
 * (GitHub token, LLM providers, optional fields, cron trigger commands),
 * and the human-readable log. The CPT screens (github_repo, github_repo_rule)
 * attach themselves to the same top-level menu via show_in_menu.
 */
class LAGR_Admin {

	const SETTINGS_SLUG = 'lagr-settings';
	const LOG_SLUG      = 'lagr-log';
	const MAIN_SLUG     = 'lagr-main';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_lagr_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_lagr_regenerate_secret', array( __CLASS__, 'handle_regenerate_secret' ) );
		add_action( 'admin_post_lagr_publish_repo', array( __CLASS__, 'handle_publish_repo' ) );
		add_action( 'admin_post_lagr_run_rule_now', array( __CLASS__, 'handle_run_rule_now' ) );
		add_filter( 'manage_github_repo_posts_columns', array( __CLASS__, 'repo_list_columns' ) );
		add_action( 'manage_github_repo_posts_custom_column', array( __CLASS__, 'repo_list_column' ), 10, 2 );
		add_filter( 'manage_github_repo_rule_posts_columns', array( __CLASS__, 'rule_list_columns' ) );
		add_action( 'manage_github_repo_rule_posts_custom_column', array( __CLASS__, 'rule_list_column' ), 10, 2 );
	}

	/** Rule list: add Вкл/выкл, Интервал, Режим публикации columns after the title. */
	public static function rule_list_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['lagr_enabled']  = 'Вкл/выкл';
				$new['lagr_interval'] = 'Интервал';
				$new['lagr_publish']  = 'Режим публикации';
			}
		}
		return $new;
	}

	public static function rule_list_column( $column, $post_id ) {
		switch ( $column ) {
			case 'lagr_enabled':
				echo get_post_meta( $post_id, '_lagr_enabled', true ) ? '✅ вкл' : '— выкл';
				break;
			case 'lagr_interval':
				$minutes = (int) get_post_meta( $post_id, '_lagr_interval_minutes', true );
				echo $minutes ? esc_html( $minutes . ' мин' ) : '—';
				break;
			case 'lagr_publish':
				echo 'publish' === get_post_meta( $post_id, '_lagr_publish_mode', true ) ? 'Сразу публиковать' : 'Черновик на модерацию';
				break;
		}
	}

	/**
	 * Add a "Создан на GitHub" column to the repo list. The built-in "Дата"
	 * column now shows the site publication date (post_date), so the GitHub
	 * creation date gets its own column from the _gh_created_at meta.
	 */
	public static function repo_list_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['gh_created'] = 'Создан на GitHub';
			}
		}
		return $new;
	}

	public static function repo_list_column( $column, $post_id ) {
		if ( 'gh_created' !== $column ) {
			return;
		}
		$raw = (string) get_post_meta( $post_id, '_gh_created_at', true );
		$ts  = $raw ? strtotime( $raw ) : 0;
		echo $ts ? esc_html( wp_date( 'd.m.Y', $ts ) ) : '—';
	}

	public static function register_menu() {
		add_menu_page(
			'GitHub Repo Collector',
			'GitHub-репозитории',
			'manage_options',
			self::MAIN_SLUG,
			array( __CLASS__, 'render_queue_page' ),
			'dashicons-star-half',
			25
		);
		// Relabels the auto-added first submenu (same slug as parent) to something meaningful.
		add_submenu_page( self::MAIN_SLUG, 'Очередь модерации', 'Очередь модерации', 'manage_options', self::MAIN_SLUG, array( __CLASS__, 'render_queue_page' ) );
		add_submenu_page( self::MAIN_SLUG, 'Настройки', 'Настройки', 'manage_options', self::SETTINGS_SLUG, array( __CLASS__, 'render_settings_page' ) );
		add_submenu_page( self::MAIN_SLUG, 'Журнал', 'Журнал', 'manage_options', self::LOG_SLUG, array( __CLASS__, 'render_log_page' ) );
	}

	// ------------------------------------------------------------ moderation

	public static function render_queue_page() {
		$rules = get_posts(
			array(
				'post_type'      => 'github_repo_rule',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$drafts = get_posts(
			array(
				'post_type'      => 'github_repo',
				'post_status'    => array( 'draft', 'pending' ),
				'posts_per_page' => 100,
				'meta_key'       => '_gh_score',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
			)
		);

		$needs_review = get_posts(
			array(
				'post_type'      => 'github_repo',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'meta_key'       => '_gh_needs_review',
				'meta_value'     => '1',
			)
		);

		echo '<div class="wrap"><h1>GitHub Repo Collector</h1>';

		echo '<h2>Правила сбора</h2><table class="widefat striped"><thead><tr><th>Правило</th><th>Включено</th><th>Интервал</th><th>Последний запуск</th><th>Итог</th><th></th></tr></thead><tbody>';
		foreach ( $rules as $rule ) {
			$enabled  = get_post_meta( $rule->ID, '_lagr_enabled', true );
			$interval = (int) get_post_meta( $rule->ID, '_lagr_interval_minutes', true );
			$last_run = (int) get_post_meta( $rule->ID, '_lagr_last_run_at', true );
			$summary  = get_post_meta( $rule->ID, '_lagr_last_run_summary', true );
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$d мин</td><td>%5$s</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_url( get_edit_post_link( $rule->ID ) ),
				esc_html( $rule->post_title ),
				$enabled ? '✅' : '—',
				$interval,
				$last_run ? esc_html( human_time_diff( $last_run ) . ' назад' ) : '—',
				esc_html( $summary ),
				self::run_now_button( $rule->ID )
			);
		}
		echo '</tbody></table>';

		echo '<h2 style="margin-top:30px">Очередь модерации (черновики, по score)</h2><table class="widefat striped"><thead><tr><th>Репозиторий</th><th>Score</th><th>★</th><th>Форки</th><th>Описание</th><th></th></tr></thead><tbody>';
		foreach ( $drafts as $post ) {
			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$d</td><td>%5$d</td><td>%6$s</td><td>%7$s</td></tr>',
				esc_url( get_edit_post_link( $post->ID ) ),
				esc_html( $post->post_title ),
				esc_html( get_post_meta( $post->ID, '_gh_score', true ) ),
				(int) get_post_meta( $post->ID, '_gh_stars', true ),
				(int) get_post_meta( $post->ID, '_gh_forks', true ),
				esc_html( wp_trim_words( get_post_meta( $post->ID, 'short_description', true ), 12 ) ),
				self::publish_button( $post->ID )
			);
		}
		if ( empty( $drafts ) ) {
			echo '<tr><td colspan="6">Пока пусто.</td></tr>';
		}
		echo '</tbody></table>';

		if ( ! empty( $needs_review ) ) {
			echo '<h2 style="margin-top:30px">Требуют проверки (данные источника изменились)</h2><ul>';
			foreach ( $needs_review as $post ) {
				printf( '<li><a href="%1$s">%2$s</a></li>', esc_url( get_edit_post_link( $post->ID ) ), esc_html( $post->post_title ) );
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	private static function run_now_button( $rule_id ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=lagr_run_rule_now&rule_id=' . $rule_id ), 'lagr_run_rule_now_' . $rule_id );
		return '<a class="button button-small" href="' . esc_url( $url ) . '">Запустить сейчас</a>';
	}

	private static function publish_button( $post_id ) {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=lagr_publish_repo&post_id=' . $post_id ), 'lagr_publish_repo_' . $post_id );
		return '<a class="button button-small button-primary" href="' . esc_url( $url ) . '">Опубликовать</a>';
	}

	public static function handle_publish_repo() {
		$post_id = (int) ( $_GET['post_id'] ?? 0 );
		check_admin_referer( 'lagr_publish_repo_' . $post_id );
		if ( current_user_can( 'publish_post', $post_id ) ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MAIN_SLUG ) );
		exit;
	}

	public static function handle_run_rule_now() {
		$rule_id = (int) ( $_GET['rule_id'] ?? 0 );
		check_admin_referer( 'lagr_run_rule_now_' . $rule_id );
		if ( current_user_can( 'manage_options' ) ) {
			LAGR_Collector::run_rule( $rule_id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MAIN_SLUG ) );
		exit;
	}

	// -------------------------------------------------------------- settings

	public static function render_settings_page() {
		$s        = LAGR_Settings::get_all();
		$commands = LAGR_Scheduler::get_trigger_commands();
		?>
		<div class="wrap">
			<h1>Настройки — GitHub Repo Collector</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p>Настройки сохранены.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'lagr_save_settings' ); ?>
				<input type="hidden" name="action" value="lagr_save_settings">

				<h2>GitHub</h2>
				<table class="form-table">
					<tr><th>Personal Access Token</th><td><input type="password" name="github_token" value="<?php echo esc_attr( $s['github_token'] ); ?>" style="width:400px"> <span class="description">Достаточно read-only, без специальных прав — только публичные данные.</span></td></tr>
					<tr><th>Бюджет запросов в час</th><td><input type="number" name="gh_budget_threshold" value="<?php echo esc_attr( $s['gh_budget_threshold'] ); ?>"> <span class="description">из 5000/час лимита GitHub, дефолт 4500</span></td></tr>
				</table>

				<h2>Опциональные поля (каждое — доп. запрос к GitHub на репозиторий)</h2>
				<table class="form-table">
					<tr><th>Контрибьюторы</th><td><label><input type="checkbox" name="optional_contributors" value="1" <?php checked( $s['optional_contributors'] ); ?>> собирать число контрибьюторов</label></td></tr>
					<tr><th>Релизы</th><td><label><input type="checkbox" name="optional_releases" value="1" <?php checked( $s['optional_releases'] ); ?>> собирать последний релиз (тег+дата)</label></td></tr>
					<tr><th>Языки</th><td><label><input type="checkbox" name="optional_languages" value="1" <?php checked( $s['optional_languages'] ); ?>> собирать разбивку по языкам (%)</label></td></tr>
					<tr><th>Файлы</th><td><label><input type="checkbox" name="optional_top_files" value="1" <?php checked( $s['optional_top_files'] ); ?>> собирать верхнеуровневую структуру файлов</label></td></tr>
				</table>

				<h2>Язык и лицензия репозитория</h2>
				<p class="description">Основной язык и SPDX-лицензия приходят бесплатно вместе с базовыми данными репозитория (без доп. запроса к GitHub) — эти переключатели решают только, заводить ли под них таксономию (`repo_language`/`repo_license`) и показывать ли их в паттернах. Выключено по умолчанию: для Agent Skills и MCP-серверов язык/лицензия обычно не главный признак каталогизации.</p>
				<table class="form-table">
					<tr><th>Язык</th><td><label><input type="checkbox" name="collect_language" value="1" <?php checked( $s['collect_language'] ); ?>> собирать основной язык репозитория и показывать его в карточках/на странице записи</label></td></tr>
					<tr><th>Лицензия</th><td><label><input type="checkbox" name="collect_license" value="1" <?php checked( $s['collect_license'] ); ?>> собирать лицензию репозитория и показывать её в карточках/на странице записи</label></td></tr>
				</table>

				<h2>README</h2>
				<p class="description">README сохраняется и показывается отдельной вкладкой на странице репозитория. Выключите, чтобы оставить только AI-описание — README при этом всё равно используется как вход для генерации описания, но не сохраняется и не показывается отдельной вкладкой.</p>
				<table class="form-table">
					<tr><th>Собирать README</th><td><label><input type="checkbox" name="collect_readme" value="1" <?php checked( $s['collect_readme'] ); ?>> сохранять README и показывать его отдельной вкладкой</label></td></tr>
				</table>

				<h2>Топики репозитория</h2>
				<table class="form-table">
					<tr>
						<th>Куда девать «остальные» топики</th>
						<td>
							<select name="extra_topics_taxonomy">
								<?php
								$builtin = array(
									'post_tag'   => 'Теги (post_tag) — рекомендуется, общий словарь с блогом',
									'repo_topic' => 'repo_topic — всё в одну таксономию (старое поведение)',
									'none'       => 'Не добавлять — только топик правила',
								);
								foreach ( $builtin as $val => $label ) :
									?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['extra_topics_taxonomy'], $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
								<?php
								$other_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
								foreach ( $other_taxonomies as $tax ) :
									if ( isset( $builtin[ $tax->name ] ) ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $tax->name ); ?>" <?php selected( $s['extra_topics_taxonomy'], $tax->name ); ?>><?php echo esc_html( $tax->labels->name . ' (' . $tax->name . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Топик(и), указанные в самом правиле сбора, всегда попадают в <code>repo_topic</code> — это курируемый словарь. Здесь решаете, куда девать остальные родные топики репозитория с GitHub (плюс теги от LLM, если она включена): в общие теги сайта, туда же в <code>repo_topic</code>, в любую другую зарегистрированную таксономию, или не добавлять вовсе.</p>
						</td>
					</tr>
				</table>

				<h2>Интеграции с другими плагинами</h2>
				<p class="description">Не на каждом из сайтов сети обязательно будет тот же набор плагинов — эти переключатели позволяют шаблонам/паттернам плагина адаптироваться без правки кода.</p>
				<table class="form-table">
					<tr>
						<th>SEO-плагин</th>
						<td>
							<select name="seo_plugin">
								<?php foreach ( array( 'yoast' => 'Yoast SEO', 'rankmath' => 'Rank Math', 'none' => 'Не использовать' ) as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['seo_plugin'], $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Сгенерированное краткое описание репозитория автоматически пишется в meta description выбранного плагина при сборе.</p>
						</td>
					</tr>
					<tr><th>Рейтинг сайта</th><td><label><input type="checkbox" name="integration_ratings" value="1" <?php checked( $s['integration_ratings'] ); ?>> показывать рейтинг (♡) в паттернах — свой виджет, без стороннего плагина</label></td></tr>
					<tr><th>Похожие репозитории</th><td><label><input type="checkbox" name="integration_related_posts" value="1" <?php checked( $s['integration_related_posts'] ); ?>> блок «Похожие репозитории» на странице записи — свой код по repo_topic, без стороннего плагина</label></td></tr>
					<tr><th>Фильтры архива</th><td><label><input type="checkbox" name="integration_filters" value="1" <?php checked( $s['integration_filters'] ); ?>> боковая панель фильтров на архиве (lagr/repo-filters) — свой код, без стороннего плагина</label></td></tr>
					<tr><th>WP External Links</th><td><label><input type="checkbox" name="integration_external_links" value="1" <?php checked( $s['integration_external_links'] ); ?>> считать, что внешние ссылки уже получают target=_blank/nofollow автоматически</label></td></tr>
					<tr><th>Relevanssi</th><td><label><input type="checkbox" name="integration_relevanssi" value="1" <?php checked( $s['integration_relevanssi'] ); ?>> использовать для поиска вместо встроенного поиска WordPress</label></td></tr>
				</table>

				<h2>Скоринг</h2>
				<table class="form-table">
					<tr><th>Вес форков</th><td><input type="number" step="0.1" name="score_forks_weight" value="<?php echo esc_attr( $s['score_forks_weight'] ); ?>"> <span class="description">score = звёзды + форки × вес</span></td></tr>
				</table>

				<h2>Генерация описаний через LLM</h2>
				<table class="form-table">
					<tr><th>Включить LLM</th><td><label><input type="checkbox" name="llm_enabled" value="1" <?php checked( $s['llm_enabled'] ); ?>> без этого используется description репозитория + README как есть</label></td></tr>
					<tr><th>Провайдер</th><td>
						<select name="llm_provider">
							<?php foreach ( array( 'yandex' => 'Yandex Foundation Models', 'deepseek' => 'DeepSeek напрямую', 'qwen' => 'Qwen (Alibaba DashScope)', 'timeweb' => 'Timeweb (TWC AI API)', 'openrouter' => 'OpenRouter' ) as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['llm_provider'], $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
				</table>

				<h3>Параметры генерации описаний</h3>
				<table class="form-table">
					<tr><th>Промт генерации</th><td>
						<textarea name="llm_system_prompt" rows="14" style="width:100%;max-width:820px;font-family:monospace"><?php echo esc_textarea( LAGR_LLM_Client::active_system_prompt() ); ?></textarea>
						<p class="description">Активный системный промт для генерации описаний (предзаполнен базовым — редактируйте под себя). Модель должна возвращать <strong>строго JSON</strong>. Обязательны <code>short_description</code> и <code>full_description</code>; остальные поля необязательны, но без них карточка беднее: <code>lead</code>, <code>features</code>, <code>use_cases</code> (структура вкладки «Описание»), <code>tasks_answer</code> и <code>faq</code> (блок «Частые вопросы» и разметка FAQPage), <code>meta_title</code>, <code>meta_description</code> (поля SEO-плагина), <code>tags</code>, <code>install_command</code>. Уже собранные карточки обновляет <code>wp github-repos regenerate</code>.</p>
					</td></tr>
					<tr><th>Длина полного описания (max_tokens)</th><td><input type="number" name="llm_max_tokens" value="<?php echo esc_attr( $s['llm_max_tokens'] ); ?>" min="100" step="50" style="width:120px"> <span class="description">потолок длины всего JSON-ответа. Слишком малое значение обрежет JSON и генерация не пройдёт. Со встроенным промтом действует минимум <?php echo (int) LAGR_LLM_Client::MIN_TOKENS_STRUCTURED; ?> — ответ включает FAQ и SEO-поля.</span></td></tr>
					<tr><th>Лимит короткого описания, символов</th><td><input type="number" name="llm_short_limit" value="<?php echo esc_attr( $s['llm_short_limit'] ); ?>" min="1" step="10" style="width:120px"> <span class="description">жёсткая обрезка short_description</span></td></tr>
					<tr><th>README на вход модели, символов</th><td><input type="number" name="llm_readme_chars" value="<?php echo esc_attr( $s['llm_readme_chars'] ); ?>" min="0" step="500" style="width:120px"> <span class="description">сколько README подаётся в промт</span></td></tr>
				</table>

				<h3>Yandex Foundation Models</h3>
				<table class="form-table">
					<tr><th>API Key</th><td><input type="password" name="yandex_api_key" value="<?php echo esc_attr( $s['yandex_api_key'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Folder ID</th><td><input type="text" name="yandex_folder_id" value="<?php echo esc_attr( $s['yandex_folder_id'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Модель</th><td><input type="text" name="yandex_model" value="<?php echo esc_attr( $s['yandex_model'] ); ?>" style="width:400px"></td></tr>
				</table>

				<h3>DeepSeek напрямую</h3>
				<table class="form-table">
					<tr><th>API Key</th><td><input type="password" name="deepseek_api_key" value="<?php echo esc_attr( $s['deepseek_api_key'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Модель</th><td><input type="text" name="deepseek_model" value="<?php echo esc_attr( $s['deepseek_model'] ); ?>" style="width:400px"></td></tr>
				</table>

				<h3>Qwen (Alibaba DashScope)</h3>
				<table class="form-table">
					<tr><th>API Key</th><td><input type="password" name="qwen_api_key" value="<?php echo esc_attr( $s['qwen_api_key'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Модель</th><td><input type="text" name="qwen_model" value="<?php echo esc_attr( $s['qwen_model'] ); ?>" style="width:400px"> <span class="description">напр. qwen-plus, qwen-max, qwen-turbo</span></td></tr>
					<tr><th>Base URL</th><td><input type="text" name="qwen_base_url" value="<?php echo esc_attr( $s['qwen_base_url'] ); ?>" style="width:400px"> <span class="description">compatible-mode: междунар. <code>https://dashscope-intl.aliyuncs.com/compatible-mode/v1</code> или КНР <code>https://dashscope.aliyuncs.com/compatible-mode/v1</code></span></td></tr>
				</table>

				<h3>Timeweb (TWC AI API)</h3>
				<table class="form-table">
					<tr><th>Agent Access ID</th><td><input type="text" name="timeweb_agent_id" value="<?php echo esc_attr( $s['timeweb_agent_id'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Token</th><td><input type="password" name="timeweb_token" value="<?php echo esc_attr( $s['timeweb_token'] ); ?>" style="width:400px"></td></tr>
					<tr><th>x-proxy-source</th><td><input type="text" name="timeweb_proxy_source" value="<?php echo esc_attr( $s['timeweb_proxy_source'] ); ?>" style="width:400px"></td></tr>
				</table>

				<h3>OpenRouter</h3>
				<table class="form-table">
					<tr><th>API Key</th><td><input type="password" name="openrouter_api_key" value="<?php echo esc_attr( $s['openrouter_api_key'] ); ?>" style="width:400px"></td></tr>
					<tr><th>Модель</th><td><input type="text" name="openrouter_model" value="<?php echo esc_attr( $s['openrouter_model'] ); ?>" style="width:400px"> <span class="description">например deepseek/deepseek-chat</span></td></tr>
				</table>

				<h2 style="margin-top:30px">Перевод README</h2>
				<p class="description" style="margin-bottom:10px">Переводит текст README на нужный язык, сохраняя разметку (ссылки, код, таблицы). Два движка:<br>
				&bull; <strong>Нейросеть (LLM)</strong> — отдельный запрос с промтом к выбранному провайдеру из раздела «Генерация описаний» выше (DeepSeek/Yandex/Timeweb/OpenRouter). Работает, даже когда Yandex Translate недоступен.<br>
				&bull; <strong>Yandex Cloud Translate</strong> — использует тот же API Key и Folder ID, что провайдер «Yandex Foundation Models» (у сервис-аккаунта нужна роль <code>translate.user</code>), тарифицируется посимвольно.<br>
				Существующие записи — командой <code>wp github-repos rebuild_readmes --translate</code>.</p>
				<table class="form-table">
					<tr><th>Переводить README</th><td><label><input type="checkbox" name="translate_readme" value="1" <?php checked( $s['translate_readme'] ); ?>> переводить README при сборе репозиториев</label></td></tr>
					<tr><th>Движок перевода</th><td>
						<select name="translate_engine">
							<?php foreach ( array( 'llm' => 'Нейросеть (LLM-провайдер)', 'yandex' => 'Yandex Cloud Translate' ) as $val => $label ) : ?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['translate_engine'], $val ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td></tr>
					<tr><th>Язык перевода</th><td><input type="text" name="translate_target_lang" value="<?php echo esc_attr( $s['translate_target_lang'] ); ?>" style="width:120px"> <span class="description">код языка, напр. <code>ru</code></span></td></tr>
					<tr><th>Исходный язык</th><td><input type="text" name="translate_source_lang" value="<?php echo esc_attr( $s['translate_source_lang'] ); ?>" style="width:120px"> <span class="description">пусто — автоопределение (для LLM — всегда авто)</span></td></tr>
				</table>

				<?php submit_button( 'Сохранить настройки' ); ?>
			</form>

			<h2 style="margin-top:40px">Расписание сбора (cron)</h2>
			<p>По умолчанию работает WP-Cron (раз в 10 минут, зависит от посещаемости сайта). Для надёжности — один из вариантов ниже, привязанных к секретному токену.</p>

			<table class="form-table">
				<tr><th>Секретный токен</th><td>
					<code><?php echo esc_html( $s['cron_secret'] ); ?></code>
					<a class="button" style="margin-left:10px" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lagr_regenerate_secret' ), 'lagr_regenerate_secret' ) ); ?>" onclick="return confirm('Старый URL перестанет работать. Продолжить?')">Сгенерировать заново</a>
				</td></tr>
			</table>

			<p><strong>Вариант 1 — curl (обычно проще всего, для стороннего cron-сервиса или своего crontab):</strong></p>
			<textarea readonly style="width:100%;height:2.5em" onclick="this.select()"><?php echo esc_textarea( $commands['curl'] ); ?></textarea>

			<p><strong>Вариант 2 — wget (если на хостинге нет curl):</strong></p>
			<textarea readonly style="width:100%;height:2.5em" onclick="this.select()"><?php echo esc_textarea( $commands['wget'] ); ?></textarea>

			<p><strong>Вариант 3 — прямой PHP CLI (если сайт не может сходить сам к себе по HTTP):</strong></p>
			<textarea readonly style="width:100%;height:2.5em" onclick="this.select()"><?php echo esc_textarea( $commands['php'] ); ?></textarea>

			<p class="description">Любой из вариантов — раз в 10 минут в crontab/панели хостинга/стороннем cron-сервисе (например cron-job.org для варианта 1, без доступа к серверу).</p>
		</div>
		<?php
	}

	public static function handle_save_settings() {
		check_admin_referer( 'lagr_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}

		$checkboxes = array(
			'optional_contributors', 'optional_releases', 'optional_languages', 'optional_top_files', 'llm_enabled',
			'collect_language', 'collect_license', 'collect_readme', 'translate_readme',
			'integration_ratings', 'integration_related_posts', 'integration_filters',
			'integration_external_links', 'integration_relevanssi',
		);
		$text_fields = array(
			'github_token', 'gh_budget_threshold', 'score_forks_weight', 'llm_provider',
			'yandex_api_key', 'yandex_folder_id', 'yandex_model',
			'translate_engine', 'translate_target_lang', 'translate_source_lang',
			'deepseek_api_key', 'deepseek_model',
			'qwen_api_key', 'qwen_model', 'qwen_base_url',
			'timeweb_agent_id', 'timeweb_token', 'timeweb_proxy_source',
			'openrouter_api_key', 'openrouter_model',
			'extra_topics_taxonomy', 'seo_plugin',
			'llm_max_tokens', 'llm_short_limit', 'llm_readme_chars',
		);

		$values = array();
		foreach ( $text_fields as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$values[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		// Multiline fields keep their newlines (sanitize_text_field would flatten them).
		if ( isset( $_POST['llm_system_prompt'] ) ) {
			$values['llm_system_prompt'] = sanitize_textarea_field( wp_unslash( $_POST['llm_system_prompt'] ) );
		}
		foreach ( $checkboxes as $key ) {
			$values[ $key ] = isset( $_POST[ $key ] ) ? 1 : 0;
		}

		LAGR_Settings::update( $values );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&saved=1' ) );
		exit;
	}

	public static function handle_regenerate_secret() {
		check_admin_referer( 'lagr_regenerate_secret' );
		if ( current_user_can( 'manage_options' ) ) {
			LAGR_Settings::regenerate_secret();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&saved=1' ) );
		exit;
	}

	// ------------------------------------------------------------------ log

	public static function render_log_page() {
		echo '<div class="wrap"><h1>Журнал</h1><table class="widefat striped"><thead><tr><th style="width:180px">Время</th><th>Сообщение</th></tr></thead><tbody>';
		foreach ( LAGR_Log::get_recent( 200 ) as $entry ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td></tr>',
				esc_html( gmdate( 'Y-m-d H:i:s', $entry['time'] ) ),
				esc_html( $entry['message'] )
			);
		}
		echo '</tbody></table></div>';
	}
}
