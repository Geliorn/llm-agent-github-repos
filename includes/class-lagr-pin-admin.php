<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin for Pinterest pins: the "Pinterest-картинки" screen (settings, fonts,
 * preset editor with live preview — the infographic screen of
 * llm-agent-articles, ported), a metabox on the repo edit screen, a row
 * action and a bulk action on the repo list.
 */
class LAGR_Pin_Admin {

	const SLUG       = 'lagr-pin';
	const BULK_LIMIT = 20;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		foreach ( array( 'save_preset', 'delete_preset', 'save_settings', 'upload_font', 'generate' ) as $a ) {
			add_action( 'admin_post_lagr_pin_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'wp_ajax_lagr_pin_preview', array( __CLASS__, 'ajax_preview' ) );

		add_action( 'add_meta_boxes_github_repo', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_github_repo', array( __CLASS__, 'save_metabox' ), 20, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-github_repo', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-github_repo', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function register_menu() {
		add_submenu_page( LAGR_Admin::MAIN_SLUG, 'Pinterest-картинки', 'Pinterest-картинки', 'manage_options', self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function enqueue_assets( $hook ) {
		$is_page = false !== strpos( (string) $hook, self::SLUG );
		$is_repo = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && 'github_repo' === get_post_type();
		if ( ! $is_page && ! $is_repo ) {
			return;
		}
		wp_enqueue_style( 'lagr-pin-admin', LAGR_PLUGIN_URL . 'assets/css/lagr-pin-admin.css', array(), LAGR_VERSION );
		if ( $is_page ) {
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_script( 'lagr-pin', LAGR_PLUGIN_URL . 'assets/js/lagr-pin.js', array( 'jquery', 'wp-color-picker' ), LAGR_VERSION, true );
			wp_localize_script(
				'lagr-pin',
				'lagrPin',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'lagr_pin_preview' ),
				)
			);
		}
	}

	// --------------------------------------------------------------- helpers

	private static function require_cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Недостаточно прав.' );
		}
	}

	private static function back( array $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	private static function options( array $choices, $selected ) {
		foreach ( $choices as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( (string) $selected, (string) $value, false ), esc_html( $label ) );
		}
	}

	private static function num( array $p, $key, $label = '', $width = 70, $suffix = '' ) {
		printf( '%s <input type="number" name="%s" value="%s" style="width:%dpx">%s ', esc_html( $label ), esc_attr( $key ), esc_attr( $p[ $key ] ), (int) $width, esc_html( $suffix ) );
	}

	private static function text( array $p, $key, $label = '', $width = 90, $placeholder = '' ) {
		printf( '%s <input type="text" name="%s" value="%s" style="width:%dpx" placeholder="%s"> ', esc_html( $label ), esc_attr( $key ), esc_attr( $p[ $key ] ), (int) $width, esc_attr( $placeholder ) );
	}

	private static function check( array $p, $key, $label ) {
		printf( '<label><input type="checkbox" name="%s" value="1" %s> %s</label> ', esc_attr( $key ), checked( $p[ $key ], 1, false ), esc_html( $label ) );
	}

	private static function select( array $p, $key, array $choices, $label = '' ) {
		echo esc_html( $label ) . ' <select name="' . esc_attr( $key ) . '">';
		self::options( $choices, $p[ $key ] );
		echo '</select> ';
	}

	private static function media_field( $name, $value, $button_label ) {
		$thumb = $value ? wp_get_attachment_image( $value, array( 60, 60 ) ) : '';
		printf(
			'<span class="lagr-media"><input type="hidden" name="%1$s" value="%2$d"><span class="lagr-media-thumb">%3$s</span> <button type="button" class="button lagr-media-pick">%4$s</button> <button type="button" class="button-link lagr-media-clear">убрать</button></span>',
			esc_attr( $name ),
			(int) $value,
			$thumb, // wp_get_attachment_image() returns escaped markup
			esc_html( $button_label )
		);
	}

	private static function font_select( array $p, $key ) {
		echo '<select name="' . esc_attr( $key ) . '"><option value="">' . ( 'font' === $key ? 'Определять автоматически' : 'Как основной' ) . '</option>';
		foreach ( LAGR_Image_Composer::uploaded_fonts() as $item ) {
			printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $item['name'] ), selected( basename( (string) $p[ $key ] ), $item['name'], false ) );
		}
		echo '</select>';
	}

	// ---------------------------------------------------------------- screen

	public static function render_page() {
		self::require_cap();
		$presets    = LAGR_Pin_Presets::get_all();
		$current_id = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : (string) array_key_first( $presets ); // phpcs:ignore WordPress.Security.NonceVerification
		$preset     = $presets[ $current_id ] ?? LAGR_Pin_Presets::schema();
		$s          = LAGR_Settings::get_all();
		$font       = LAGR_Image_Composer::resolve_font( $preset['font'] );
		?>
		<div class="wrap lagr-pin-wrap">
			<h1>Pinterest-картинки</h1>
			<?php if ( isset( $_GET['lagr_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-<?php echo 'error' === ( $_GET['lagr_type'] ?? '' ) ? 'error' : 'success'; // phpcs:ignore ?> is-dismissible"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['lagr_notice'] ) ) ); // phpcs:ignore ?></p></div>
			<?php endif; ?>
			<?php if ( ! LAGR_Image_Composer::text_available() ) : ?>
				<div class="notice notice-error"><p>GD без FreeType — текст на картинку нанести нельзя, Pinterest-картинки работать не будут.</p></div>
			<?php elseif ( '' === $font ) : ?>
				<div class="notice notice-warning"><p>Не найден TTF-шрифт — загрузите его ниже, без него картинка не соберётся.</p></div>
			<?php endif; ?>

			<p class="description">Картинка <?php echo (int) $preset['width']; ?>×<?php echo (int) $preset['height']; ?> для каждого репозитория: автор и название, stars/forks, языки и три блока — «Что это?», «Что это делает?», «Какую проблему решает?». Тексты блоков пишет модель в том же запросе, что и описание. Импорт в Pinterest делает отдельный плагин — он берёт картинку из мета <code>_gh_pin_image_id</code> или ловит хук <code>lagr_pin_image_created</code>.</p>

			<div class="lagr-pin-boxes">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lagr-pin-box">
					<h2>Когда собирать</h2>
					<?php wp_nonce_field( 'lagr_pin_save_settings' ); ?>
					<input type="hidden" name="action" value="lagr_pin_save_settings">
					<p><label><input type="checkbox" name="pin_on_collect" value="1" <?php checked( $s['pin_on_collect'] ); ?>> собирать картинку для каждого нового репозитория при сборе</label></p>
					<p><label><input type="checkbox" name="pin_texts_in_prompt" value="1" <?php checked( $s['pin_texts_in_prompt'] ); ?>> просить тексты блоков в запросе описания (без этого — отдельный запрос при сборке картинки)</label></p>
					<p>Пресет по умолчанию: <select name="pin_default_preset"><?php foreach ( $presets as $id => $item ) { printf( '<option value="%s" %s>%s</option>', esc_attr( $id ), selected( $s['pin_default_preset'], $id, false ), esc_html( $item['name'] ) ); } ?></select></p>
					<?php submit_button( 'Сохранить', 'secondary', 'submit', false ); ?>
					<p class="description">Старые записи: массовое действие «Собрать Pinterest-картинки» в списке репозиториев (до <?php echo (int) self::BULK_LIMIT; ?> за раз) или <code>wp github-repos pin --missing</code>.</p>
				</form>

				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lagr-pin-box">
					<h2>Шрифты</h2>
					<?php wp_nonce_field( 'lagr_pin_upload_font' ); ?>
					<input type="hidden" name="action" value="lagr_pin_upload_font">
					<p><input type="file" name="font_file" accept=".ttf,.otf"> <?php submit_button( 'Загрузить TTF/OTF', 'secondary', 'submit', false ); ?></p>
					<p class="description">Нужен шрифт с кириллицей. Сейчас доступны: <?php echo esc_html( implode( ', ', wp_list_pluck( LAGR_Image_Composer::uploaded_fonts(), 'name' ) ) ?: 'нет загруженных' ); ?>; автоматически выбран: <code><?php echo esc_html( $font ? basename( $font ) : '—' ); ?></code>. Шрифты, загруженные для статей, тоже подхватываются.</p>
				</form>
			</div>

			<ul class="subsubsub">
				<?php foreach ( $presets as $id => $item ) : ?>
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&preset=' . $id ) ); ?>" <?php echo $id === $current_id ? 'class="current"' : ''; ?>><?php echo esc_html( $item['name'] ); ?></a> |</li>
				<?php endforeach; ?>
				<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&preset=new' ) ); ?>">+ новый пресет</a></li>
			</ul>
			<div style="clear:both"></div>

			<div class="lagr-pin-layout">
				<div class="lagr-pin-form">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="lagr-pin-form">
						<?php wp_nonce_field( 'lagr_pin_save_preset' ); ?>
						<input type="hidden" name="action" value="lagr_pin_save_preset">
						<input type="hidden" name="preset_id" value="<?php echo esc_attr( 'new' === $current_id ? '' : $current_id ); ?>">
						<?php self::render_fields( $preset ); ?>
						<p>
							<?php submit_button( 'Сохранить пресет', 'primary', 'submit', false ); ?>
							<button type="button" class="button" id="lagr-pin-preview-button">Предпросмотр</button>
							<?php if ( 'new' !== $current_id && count( $presets ) > 1 ) : ?>
								<a class="button lagr-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=lagr_pin_delete_preset&preset_id=' . $current_id ), 'lagr_pin_delete_preset' ) ); ?>" onclick="return confirm('Удалить пресет?')">Удалить</a>
							<?php endif; ?>
						</p>
					</form>
				</div>

				<div class="lagr-pin-preview">
					<h2>Предпросмотр</h2>
					<p><label for="lagr-pin-repo">Репозиторий</label><br>
						<select id="lagr-pin-repo" style="max-width:100%">
							<option value="0">Образец (вымышленный репозиторий)</option>
							<?php
							$repos = get_posts(
								array(
									'post_type'      => 'github_repo',
									'post_status'    => array( 'publish', 'draft', 'pending' ),
									'posts_per_page' => 40,
									'orderby'        => 'date',
									'order'          => 'DESC',
								)
							);
							foreach ( $repos as $repo ) {
								printf( '<option value="%d">%s</option>', (int) $repo->ID, esc_html( $repo->post_title ) );
							}
							?>
						</select>
					</p>
					<p><label>Тексты блоков — пусто: взять сохранённые у репозитория (или образец)</label><br>
						<textarea id="lagr-pin-text-0" rows="2" class="large-text" placeholder="Что это?"></textarea>
						<textarea id="lagr-pin-text-1" rows="2" class="large-text" placeholder="Что это делает?"></textarea>
						<textarea id="lagr-pin-text-2" rows="2" class="large-text" placeholder="Какую проблему решает?"></textarea>
					</p>
					<div id="lagr-pin-preview-box"><p class="description">Нажмите «Предпросмотр», чтобы собрать картинку с текущими настройками формы.</p></div>
					<p class="description">Бюджет на блок при этом пресете: <strong><?php echo (int) LAGR_Pin_Composer::block_budget( $preset ); ?></strong> символов — это число получает модель.</p>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_fields( array $p ) {
		$positions = LAGR_Pin_Presets::positions();
		$aligns    = LAGR_Pin_Presets::aligns();
		?>
		<h2>Полотно</h2>
		<table class="form-table">
			<tr><th>Название</th><td><input type="text" name="name" class="regular-text" value="<?php echo esc_attr( $p['name'] ); ?>"></td></tr>
			<tr><th>Размер</th><td><?php self::num( $p, 'width', '', 90 ); ?>× <?php self::num( $p, 'height', '', 90, 'px' ); ?><p class="description">Pinterest рекомендует 2:3 — 1000×1500.</p></td></tr>
			<tr><th>Поля</th><td><?php self::num( $p, 'padding', '', 80, 'px' ); ?></td></tr>
			<tr><th>Формат</th><td><?php self::select( $p, 'format', array( 'jpg' => 'JPEG', 'png' => 'PNG', 'webp' => 'WebP' ) ); ?><?php self::num( $p, 'quality', 'качество' ); ?></td></tr>
			<tr><th>Шрифты</th><td>заголовки <?php self::font_select( $p, 'font_heading' ); ?> текст <?php self::font_select( $p, 'font' ); ?></td></tr>
		</table>

		<h2>Цвета</h2>
		<table class="form-table">
			<tr><th>Откуда брать цвета</th><td><?php self::select( $p, 'palette_source', LAGR_Pin_Presets::palette_sources() ); ?>
				<p class="description">«Из темы» — все цвета ниже заменяются палитрой темы сайта (текст подбирается по контрасту). При ротации каждый новый пин получает следующую палитру по кругу; она запоминается в записи, и пересборка сохраняет цвет. Палитра попадает в <code>utm_content</code> (например <code>dark-magma</code>).</p></td></tr>
			<tr><th>Раскладка цветов темы</th><td><?php self::select( $p, 'palette_layout', LAGR_Theme_Palette::layouts() ); ?>
				<p class="description">Используется, только когда цвета берутся из темы.</p></td></tr>
		</table>

		<h2>Фон</h2>
		<table class="form-table">
			<tr><th>Чем заполнять</th><td><?php self::select( $p, 'background_mode', LAGR_Pin_Presets::background_modes() ); ?><?php self::text( $p, 'background', 'цвет' ); ?></td></tr>
			<tr><th>Картинка-подложка</th><td>
				<?php self::media_field( 'background_id', (int) $p['background_id'], 'Выбрать фон' ); ?><br>
				<?php self::select( $p, 'fit', LAGR_Pin_Presets::fit_modes() ); ?><?php self::select( $p, 'focal', $positions ); ?><?php self::check( $p, 'grayscale', 'ч/б' ); ?>
				<?php self::num( $p, 'brightness', 'яркость' ); ?><?php self::num( $p, 'contrast', 'контраст' ); ?><?php self::num( $p, 'blur', 'размытие', 60 ); ?>
			</td></tr>
			<tr><th>Тонирование</th><td><?php self::text( $p, 'colorize_hex', '', 90, '#1f6feb' ); ?><?php self::num( $p, 'colorize_strength', 'сила', 60, '%' ); ?></td></tr>
			<tr><th>Заливка цветом</th><td><?php self::text( $p, 'tint_hex' ); ?><?php self::num( $p, 'tint_alpha', 'непрозрачность', 60, '%' ); ?></td></tr>
			<tr><th>Градиент</th><td><?php self::check( $p, 'gradient_enabled', 'включить' ); ?><?php self::select( $p, 'gradient_direction', LAGR_Pin_Presets::gradient_directions() ); ?>
				<?php self::text( $p, 'gradient_from_hex', 'от' ); ?><?php self::num( $p, 'gradient_from_alpha', '', 60, '%' ); ?><?php self::text( $p, 'gradient_to_hex', 'к' ); ?><?php self::num( $p, 'gradient_to_alpha', '', 60, '%' ); ?></td></tr>
			<tr><th>PNG-маска</th><td><?php self::media_field( 'mask_id', (int) $p['mask_id'], 'Выбрать маску' ); ?> <?php self::select( $p, 'mask_mode', LAGR_Pin_Presets::mask_modes() ); ?><?php self::num( $p, 'mask_alpha', '', 60, '%' ); ?></td></tr>
		</table>

		<h2>Шапка: автор и название</h2>
		<table class="form-table">
			<tr><th>Что в заголовке</th><td><?php self::select( $p, 'title_mode', LAGR_Pin_Presets::title_modes() ); ?><?php self::select( $p, 'title_align', $aligns ); ?></td></tr>
			<tr><th>Автор</th><td><?php self::check( $p, 'owner_enabled', 'строка с автором' ); ?><?php self::text( $p, 'owner_prefix', 'префикс', 80, 'by ' ); ?><?php self::num( $p, 'owner_size', 'кегль', 60 ); ?><?php self::text( $p, 'owner_color', 'цвет' ); ?><?php self::num( $p, 'header_gap', 'отступ', 60 ); ?></td></tr>
			<tr><th>Название</th><td><?php self::num( $p, 'title_size', 'кегль', 60 ); ?><?php self::num( $p, 'title_min_size', 'минимум', 60 ); ?><?php self::num( $p, 'title_max_lines', 'строк', 55 ); ?><?php self::text( $p, 'title_line_height', 'интерлиньяж', 55 ); ?><?php self::text( $p, 'title_color', 'цвет' ); ?><?php self::num( $p, 'title_gap', 'отступ снизу', 60 ); ?>
				<p class="description">Имя переносится по «-», «_», «.», не обрезается: если не влезло и на минимуме — займёт ещё строку.</p></td></tr>
		</table>

		<h2>Stars и forks</h2>
		<table class="form-table">
			<tr><th>Показывать</th><td><?php self::check( $p, 'stats_enabled', 'плашки со счётчиками' ); ?><?php self::text( $p, 'stats_label_stars', 'подписи', 80 ); ?><?php self::text( $p, 'stats_label_forks', '', 80 ); ?><?php self::select( $p, 'stats_number_format', LAGR_Pin_Presets::number_formats() ); ?></td></tr>
			<tr><th>Текст</th><td><?php self::num( $p, 'stats_size', 'кегль', 60 ); ?><?php self::text( $p, 'stats_color', 'число' ); ?><?php self::text( $p, 'stats_label_color', 'подпись' ); ?><?php self::text( $p, 'stats_icon_color', 'иконки' ); ?></td></tr>
			<tr><th>Плашка</th><td><?php self::check( $p, 'stats_plate_enabled', 'включить' ); ?><?php self::text( $p, 'stats_plate_color', 'цвет' ); ?><?php self::num( $p, 'stats_plate_alpha', '', 55, '%' ); ?><?php self::num( $p, 'stats_plate_radius', 'скругление', 55 ); ?><?php self::num( $p, 'stats_plate_padding', 'внутр. отступ', 55 ); ?><?php self::num( $p, 'stats_spacing', 'между', 55 ); ?><?php self::num( $p, 'stats_gap', 'отступ снизу', 60 ); ?></td></tr>
		</table>

		<h2>Языки</h2>
		<table class="form-table">
			<tr><th>Показывать</th><td><?php self::check( $p, 'langs_enabled', 'полоса и легенда' ); ?><?php self::num( $p, 'langs_max', 'языков', 55 ); ?><?php self::num( $p, 'langs_min_percent', 'не меньше', 55, '%' ); ?><?php self::text( $p, 'langs_other_label', 'остальные —', 80 ); ?>
				<p class="description">Доли — из разбивки GitHub по языкам (при отсутствии берётся одним запросом к API и сохраняется); если её нет — основной язык 100%. Цвета — как на GitHub.</p></td></tr>
			<tr><th>Полоса</th><td><?php self::num( $p, 'langs_bar_height', 'высота', 55 ); ?><?php self::num( $p, 'langs_bar_radius', 'скругление', 55 ); ?><?php self::text( $p, 'langs_track_color', 'фон полосы' ); ?></td></tr>
			<tr><th>Легенда</th><td><?php self::num( $p, 'langs_size', 'кегль', 55 ); ?><?php self::text( $p, 'langs_color', 'цвет' ); ?><?php self::num( $p, 'langs_legend_gap', 'отступ от полосы', 55 ); ?><?php self::num( $p, 'langs_gap', 'отступ снизу', 60 ); ?></td></tr>
		</table>

		<h2>Три блока</h2>
		<table class="form-table">
			<tr><th>Заголовки блоков</th><td><?php self::text( $p, 'label_what', '', 160 ); ?><?php self::text( $p, 'label_does', '', 180 ); ?><?php self::text( $p, 'label_problem', '', 220 ); ?></td></tr>
			<tr><th>Раскладка</th><td><?php self::select( $p, 'block_layout', LAGR_Pin_Presets::block_layouts() ); ?><?php self::num( $p, 'gap_y', 'зазор', 60 ); ?></td></tr>
			<tr><th>Плашка блока</th><td><?php self::check( $p, 'plate_enabled', 'включить' ); ?><?php self::text( $p, 'plate_color', 'цвет' ); ?><?php self::num( $p, 'plate_alpha', '', 55, '%' ); ?><?php self::num( $p, 'plate_radius', 'скругление', 55 ); ?><?php self::num( $p, 'plate_padding', 'внутр. отступ', 55 ); ?>
				<?php self::num( $p, 'plate_border_width', 'обводка', 55 ); ?><?php self::text( $p, 'plate_border_color' ); ?></td></tr>
			<tr><th>Маркер</th><td><?php self::select( $p, 'badge_style', LAGR_Pin_Presets::badge_styles() ); ?><?php self::num( $p, 'badge_size', 'размер', 55 ); ?><?php self::num( $p, 'badge_gap', 'отступ', 55 ); ?><?php self::text( $p, 'badge_bg', 'фон' ); ?><?php self::text( $p, 'badge_color', 'цифра' ); ?></td></tr>
			<tr><th>Заголовок блока</th><td><?php self::num( $p, 'label_size', 'кегль', 55 ); ?><?php self::text( $p, 'label_color', 'цвет' ); ?><?php self::num( $p, 'label_gap', 'отступ', 55 ); ?><?php self::check( $p, 'label_uppercase', 'ПРОПИСНЫЕ' ); ?></td></tr>
			<tr><th>Текст блока</th><td><?php self::num( $p, 'text_size', 'кегль', 55 ); ?><?php self::num( $p, 'text_min_size', 'минимум', 55 ); ?><?php self::text( $p, 'text_line_height', 'интерлиньяж', 55 ); ?><?php self::text( $p, 'text_color', 'цвет' ); ?>
				<p class="description">Кегль один на все три блока: подбирается по самому длинному и опускается до минимума. Что не влезло и так — модель переписывает короче, текст не обрезается.</p></td></tr>
		</table>

		<h2>Подвал, логотип, рамка</h2>
		<table class="form-table">
			<tr><th>Подвал</th><td><?php self::check( $p, 'footer_enabled', 'показывать' ); ?><?php self::text( $p, 'footer_text', '', 220, '{site}' ); ?><?php self::num( $p, 'footer_size', 'кегль', 55 ); ?><?php self::text( $p, 'footer_color', 'цвет' ); ?><?php self::select( $p, 'footer_align', $aligns ); ?><?php self::num( $p, 'footer_gap', 'отступ', 55 ); ?>
				<p class="description">Подстановки: <code>{site}</code> — домен сайта, <code>{repo}</code> — owner/repo, <code>{owner}</code>, <code>{url}</code> — github.com/owner/repo.</p></td></tr>
			<tr><th>Логотип</th><td><?php self::media_field( 'logo_id', (int) $p['logo_id'], 'Выбрать логотип' ); ?><br><?php self::select( $p, 'logo_position', $positions ); ?><?php self::num( $p, 'logo_width', 'ширина', 55, '%' ); ?><?php self::num( $p, 'logo_alpha', '', 55, '%' ); ?><?php self::num( $p, 'logo_offset_x', 'отступы', 60 ); ?><?php self::num( $p, 'logo_offset_y', '', 60 ); ?></td></tr>
			<tr><th>Рамка</th><td><?php self::num( $p, 'border_width', 'толщина', 55 ); ?><?php self::num( $p, 'border_inset', 'от края', 55 ); ?><?php self::text( $p, 'border_color', 'цвет' ); ?><?php self::num( $p, 'corner_radius', 'скругление углов', 55 ); ?></td></tr>
		</table>
		<?php
	}

	private static function values_from_request( array $request ) {
		$values = array();
		foreach ( LAGR_Pin_Presets::schema() as $key => $default ) {
			if ( in_array( $key, LAGR_Pin_Presets::checkboxes(), true ) ) {
				$values[ $key ] = isset( $request[ $key ] ) ? 1 : 0;
			} elseif ( isset( $request[ $key ] ) ) {
				$values[ $key ] = is_array( $request[ $key ] ) ? '' : wp_unslash( $request[ $key ] );
			}
		}
		return $values;
	}

	public static function handle_save_preset() {
		check_admin_referer( 'lagr_pin_save_preset' );
		self::require_cap();
		$id     = sanitize_key( wp_unslash( $_POST['preset_id'] ?? '' ) );
		$values = self::values_from_request( $_POST );
		if ( '' === $id ) {
			$id = sanitize_title( LAGR_Vocabulary::slugify( $values['name'] ?? '' ) ) ?: 'pin';
			if ( isset( LAGR_Pin_Presets::get_all()[ $id ] ) ) {
				$id .= '-' . substr( md5( (string) microtime( true ) ), 0, 5 );
			}
		}
		$saved = LAGR_Pin_Presets::save( $id, $values );
		self::back(
			array(
				'preset'      => $saved,
				'lagr_notice' => 'Пресет сохранён.',
			)
		);
	}

	public static function handle_delete_preset() {
		check_admin_referer( 'lagr_pin_delete_preset' );
		self::require_cap();
		LAGR_Pin_Presets::delete( sanitize_key( wp_unslash( $_GET['preset_id'] ?? '' ) ) );
		self::back( array( 'lagr_notice' => 'Пресет удалён.' ) );
	}

	public static function handle_save_settings() {
		check_admin_referer( 'lagr_pin_save_settings' );
		self::require_cap();
		$preset = sanitize_key( wp_unslash( $_POST['pin_default_preset'] ?? '' ) );
		LAGR_Settings::update(
			array(
				'pin_on_collect'      => isset( $_POST['pin_on_collect'] ) ? 1 : 0,
				'pin_texts_in_prompt' => isset( $_POST['pin_texts_in_prompt'] ) ? 1 : 0,
				'pin_default_preset'  => isset( LAGR_Pin_Presets::get_all()[ $preset ] ) ? $preset : 'dark',
			)
		);
		self::back( array( 'lagr_notice' => 'Настройки сохранены.' ) );
	}

	public static function handle_upload_font() {
		check_admin_referer( 'lagr_pin_upload_font' );
		self::require_cap();
		$file = $_FILES['font_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$name = $file ? sanitize_file_name( (string) $file['name'] ) : '';
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! preg_match( '/\.(ttf|otf)$/i', $name ) ) {
			self::back(
				array(
					'lagr_notice' => 'Нужен файл .ttf или .otf.',
					'lagr_type'   => 'error',
				)
			);
		}
		// A font that GD can't measure is useless — check before keeping it.
		$probe = function_exists( 'imagettfbbox' ) ? @imagettfbbox( 20, 0, $file['tmp_name'], 'Проверка' ) : false; // phpcs:ignore
		if ( ! $probe ) {
			self::back(
				array(
					'lagr_notice' => 'GD не смог прочитать этот шрифт.',
					'lagr_type'   => 'error',
				)
			);
		}
		$dir = LAGR_Image_Composer::fonts_dir();
		wp_mkdir_p( $dir );
		$ok = @move_uploaded_file( $file['tmp_name'], trailingslashit( $dir ) . $name ); // phpcs:ignore
		self::back(
			array(
				'lagr_notice' => $ok ? 'Шрифт загружен: ' . $name : 'Не удалось сохранить шрифт.',
				'lagr_type'   => $ok ? 'success' : 'error',
			)
		);
	}

	/** Live preview: the form's current values, a real repo or the sample. */
	public static function ajax_preview() {
		check_ajax_referer( 'lagr_pin_preview', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Недостаточно прав.' ) );
		}
		$preset  = LAGR_Pin_Presets::sanitize( self::values_from_request( $_POST ) );
		$repo_id = (int) ( $_POST['preview_repo'] ?? 0 );
		// Show the theme colours the pin would get, without moving the rotation.
		list( $preset, $palette ) = LAGR_Pin_Service::colourise( $preset, $repo_id, false, false );
		$data    = $repo_id && 'github_repo' === get_post_type( $repo_id ) ? LAGR_Pin_Service::data_for( $repo_id, false ) : self::sample_data();
		foreach ( array( 0, 1, 2 ) as $i ) {
			$override = sanitize_text_field( wp_unslash( $_POST[ 'preview_text_' . $i ] ?? '' ) );
			if ( '' !== $override ) {
				$data['blocks'][ $i ] = $override;
			} elseif ( '' === (string) ( $data['blocks'][ $i ] ?? '' ) ) {
				$data['blocks'][ $i ] = self::sample_data()['blocks'][ $i ];
			}
		}

		$tmp    = LAGR_Pin_Service::temp_path( LAGR_Image_Composer::extension_for( $preset['format'] ) );
		$result = LAGR_Pin_Composer::compose( $preset, $data, $tmp );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
		$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore
		@unlink( $tmp ); // phpcs:ignore

		$notice = sprintf( 'Кегль текста блоков: %d px.', $result['text_size'] );
		if ( $palette ) {
			$notice .= sprintf( ' Палитра темы: %s (utm_content=%s).', LAGR_Theme_Palette::get( $palette )['title'], LAGR_Theme_Palette::utm_label( $palette ) );
		}
		if ( $result['overflow'] ) {
			$parts = array();
			foreach ( $result['overflow'] as $item ) {
				$parts[] = sprintf( '№%d (влезает ~%d символов)', $item['index'] + 1, $item['fits_chars'] );
			}
			$notice .= ' Не помещаются: ' . implode( ', ', $parts ) . ' — при сборке такие блоки переписываются моделью.';
		}
		wp_send_json_success(
			array(
				'dataUri' => 'data:' . $result['mime'] . ';base64,' . base64_encode( $bytes ),
				'notice'  => $notice,
			)
		);
	}

	public static function sample_data() {
		return array(
			'owner'     => 'acme-labs',
			'name'      => 'mcp-inbox-agent',
			'full_name' => 'acme-labs/mcp-inbox-agent',
			'url'       => 'github.com/acme-labs/mcp-inbox-agent',
			'stars'     => 12480,
			'forks'     => 932,
			'languages' => array( array( 'TypeScript', 71.4 ), array( 'Python', 22.1 ), array( 'Shell', 4.2 ), array( 'Dockerfile', 2.3 ) ),
			'blocks'    => array(
				'MCP-сервер, который даёт AI-агенту доступ к рабочей почте: чтение, поиск и черновики ответов.',
				'Разбирает входящие, относит письма к категориям, вытаскивает даты и суммы и готовит ответ по шаблону.',
				'Разгребание почты перестаёт съедать утро: рутинные письма закрывает агент, человеку остаются спорные.',
			),
		);
	}

	// --------------------------------------------------------------- metabox

	public static function add_metabox() {
		add_meta_box( 'lagr-pin', 'Pinterest-картинка', array( __CLASS__, 'render_metabox' ), 'github_repo', 'side', 'default' );
	}

	public static function render_metabox( $post ) {
		$image = (int) get_post_meta( $post->ID, LAGR_Pin_Service::META_IMAGE, true );
		$error = (string) get_post_meta( $post->ID, LAGR_Pin_Service::META_ERROR, true );
		$texts = LAGR_Pin_Service::texts( $post->ID );
		$p     = LAGR_Pin_Presets::get();
		wp_nonce_field( 'lagr_pin_metabox', 'lagr_pin_nonce' );

		if ( $image ) {
			printf( '<p><a href="%s" target="_blank">%s</a></p>', esc_url( (string) wp_get_attachment_url( $image ) ), wp_get_attachment_image( $image, 'medium', false, array( 'style' => 'max-width:100%;height:auto' ) ) );
			$at = (int) get_post_meta( $post->ID, LAGR_Pin_Service::META_GENERATED, true );
			echo $at ? '<p class="description">Собрана ' . esc_html( wp_date( 'd.m.Y H:i', $at ) ) . '</p>' : '';
		} else {
			echo '<p class="description">Картинки ещё нет.</p>';
		}
		if ( $error ) {
			echo '<p style="color:#b32d2e">' . esc_html( $error ) . '</p>';
		}
		foreach ( array( $p['label_what'], $p['label_does'], $p['label_problem'] ) as $i => $label ) {
			printf( '<p><label><strong>%s</strong><br><textarea name="lagr_pin_text[%d]" rows="3" style="width:100%%">%s</textarea></label></p>', esc_html( $label ), (int) $i, esc_textarea( $texts[ $i ] ) );
		}
		$base = wp_nonce_url( admin_url( 'admin-post.php?action=lagr_pin_generate&post_id=' . $post->ID ), 'lagr_pin_generate_' . $post->ID );
		echo '<p><select id="lagr-pin-preset" style="max-width:100%"><option value="">Пресет по умолчанию</option>';
		foreach ( LAGR_Pin_Presets::get_all() as $id => $item ) {
			printf( '<option value="%s">%s</option>', esc_attr( $id ), esc_html( $item['name'] ) );
		}
		echo '</select></p>';
		// Links, not submit buttons: in the block editor metaboxes are posted
		// separately and a submit inside one never reaches the server.
		printf(
			'<p><a class="button button-primary lagr-pin-go" href="%1$s">Собрать картинку</a> <a class="button lagr-pin-go" href="%2$s" onclick="return confirm(\'Тексты блоков будут написаны моделью заново. Продолжить?\')">Новые тексты</a></p>',
			esc_url( $base ),
			esc_url( add_query_arg( 'retext', 1, $base ) )
		);
		echo '<p class="description">Правки текстов сначала сохраните кнопкой «Обновить», затем соберите картинку. Тексты не обрезаются: если не влезают, модель перепишет их короче.</p>';
		echo "<script>document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('.lagr-pin-go');if(!a)return;var s=document.getElementById('lagr-pin-preset');if(s&&s.value){a.href+=(a.href.indexOf('?')<0?'?':'&')+'preset='+encodeURIComponent(s.value);}});</script>";
	}

	public static function save_metabox( $post_id, $post ) {
		if ( ! isset( $_POST['lagr_pin_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['lagr_pin_nonce'] ), 'lagr_pin_metabox' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['lagr_pin_text'] ) && is_array( $_POST['lagr_pin_text'] ) ) {
			LAGR_Pin_Service::save_texts( $post_id, array_map( 'wp_unslash', $_POST['lagr_pin_text'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
	}

	// ---------------------------------------------------- list: row and bulk

	public static function row_actions( $actions, $post ) {
		if ( 'github_repo' === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
			$url                  = wp_nonce_url( admin_url( 'admin-post.php?action=lagr_pin_generate&post_id=' . $post->ID ), 'lagr_pin_generate_' . $post->ID );
			$actions['lagr_pin'] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), get_post_meta( $post->ID, LAGR_Pin_Service::META_IMAGE, true ) ? 'Пересобрать pin' : 'Собрать pin' );
			if ( get_post_meta( $post->ID, LAGR_Pin_Service::META_PALETTE, true ) ) {
				$actions['lagr_pin_recolor'] = sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( 'recolor', 1, $url ) ), 'Пересобрать pin с новым цветом' );
			}
		}
		return $actions;
	}

	public static function handle_generate() {
		$post_id = (int) ( $_GET['post_id'] ?? 0 );
		check_admin_referer( 'lagr_pin_generate_' . $post_id );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Недостаточно прав.' );
		}
		$r = LAGR_Pin_Service::create_for_post(
			$post_id,
			array(
				'preset'  => sanitize_key( wp_unslash( $_GET['preset'] ?? '' ) ),
				'retext'  => ! empty( $_GET['retext'] ),
				'recolor' => ! empty( $_GET['recolor'] ),
			)
		);
		set_transient( 'lagr_pin_notice_' . get_current_user_id(), $r['ok'] ? 'Pinterest-картинка собрана: ' . get_the_title( $post_id ) : 'Pinterest-картинка не собрана: ' . $r['error'], 60 );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'edit.php?post_type=github_repo' ) );
		exit;
	}

	public static function bulk_actions( $actions ) {
		$actions['lagr_pin_build']   = 'Собрать Pinterest-картинки';
		$actions['lagr_pin_recolor'] = 'Пересобрать Pinterest-картинки с новым цветом';
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( ! in_array( $action, array( 'lagr_pin_build', 'lagr_pin_recolor' ), true ) ) {
			return $redirect;
		}
		$opts = array( 'recolor' => 'lagr_pin_recolor' === $action );
		$ok   = 0;
		$fail = 0;
		foreach ( array_slice( array_map( 'intval', (array) $ids ), 0, self::BULK_LIMIT ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			LAGR_Pin_Service::create_for_post( $id, $opts )['ok'] ? ++$ok : ++$fail;
		}
		$msg = sprintf( 'Pinterest-картинки: собрано %d, не собрано %d.', $ok, $fail );
		if ( count( (array) $ids ) > self::BULK_LIMIT ) {
			$msg .= sprintf( ' Обработаны первые %d — остальные отдельным заходом или через WP-CLI.', self::BULK_LIMIT );
		}
		set_transient( 'lagr_pin_notice_' . get_current_user_id(), $msg . ( $fail ? ' Причины — в «Журнале».' : '' ), 60 );
		return $redirect;
	}

	public static function notices() {
		$key = 'lagr_pin_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( $msg ) {
			delete_transient( $key );
			$bad = false !== mb_strpos( $msg, 'не собран' ) && false === mb_strpos( $msg, 'не собрано 0' );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $bad ? 'warning' : 'success', esc_html( $msg ) );
		}
	}
}
