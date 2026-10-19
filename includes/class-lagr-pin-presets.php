<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presets for the Pinterest pin of a repo (1000×1500 by default — Pinterest's
 * 2:3). The background half (canvas, filters, tint, gradient, mask, logo,
 * border, output) keeps the field names of the infographic presets in
 * llm-agent-articles, because LAGR_Image_Composer feeds them straight into the
 * same pipeline; the content half is the pin's own: header, stats, languages,
 * three blocks, footer.
 */
class LAGR_Pin_Presets {

	const OPTION_KEY = 'lagr_pin_presets';

	public static function background_modes() {
		return array(
			'solid'  => 'Сплошной цвет (+ градиент)',
			'image'  => 'Картинка из медиабиблиотеки',
		);
	}

	public static function block_layouts() {
		return array(
			'stack' => 'Маркер над текстом',
			'side'  => 'Маркер слева от текста',
		);
	}

	public static function badge_styles() {
		return array(
			'circle' => 'Номер в круге',
			'square' => 'Номер в квадрате',
			'plain'  => 'Номер без подложки',
			'bullet' => 'Точка-маркер',
			'none'   => 'Без маркера',
		);
	}

	public static function title_modes() {
		return array(
			'repo' => 'Только имя репозитория (автор — отдельной строкой)',
			'full' => 'owner/repo целиком',
		);
	}

	public static function number_formats() {
		return array(
			'short' => 'Коротко: 12.3k',
			'full'  => 'Полностью: 12 345',
		);
	}

	public static function positions() {
		return array(
			'top-left'      => 'Сверху слева',
			'top-center'    => 'Сверху по центру',
			'top-right'     => 'Сверху справа',
			'middle-left'   => 'По центру слева',
			'middle-center' => 'По центру',
			'middle-right'  => 'По центру справа',
			'bottom-left'   => 'Снизу слева',
			'bottom-center' => 'Снизу по центру',
			'bottom-right'  => 'Снизу справа',
		);
	}

	public static function gradient_directions() {
		return array(
			'to-bottom' => 'Сверху вниз',
			'to-top'    => 'Снизу вверх',
			'to-right'  => 'Слева направо',
			'to-left'   => 'Справа налево',
		);
	}

	public static function fit_modes() {
		return array(
			'cover'   => 'Заполнить и обрезать',
			'contain' => 'Вписать целиком (с полями)',
		);
	}

	public static function mask_modes() {
		return array(
			'stretch' => 'Растянуть на всё полотно',
			'tile'    => 'Замостить',
			'center'  => 'По центру, как есть',
		);
	}

	public static function aligns() {
		return array(
			'left'   => 'по левому краю',
			'center' => 'по центру',
			'right'  => 'по правому краю',
		);
	}

	/** Every field with its default — the form, the sanitiser and the composer all read this. */
	public static function schema() {
		return array(
			'name'                 => 'Новый пресет',

			// colours: the preset's own hex fields, or the theme's palette roles
			// (LAGR_Theme_Palette) — the active one or rotated per pin.
			'palette_source'       => 'preset',
			'palette_layout'       => 'accent',

			// canvas — Pinterest's recommended 2:3.
			'width'                => 1000,
			'height'               => 1500,
			'background'           => '#0d1117',
			'format'               => 'jpg',
			'quality'              => 90,
			'padding'              => 64,

			// background
			'background_mode'      => 'solid',
			'background_id'        => 0,
			'fit'                  => 'cover',
			'focal'                => 'middle-center',
			'grayscale'            => 0,
			'brightness'           => 0,
			'contrast'             => 0,
			'blur'                 => 0,
			'colorize_hex'         => '',
			'colorize_strength'    => 0,
			'tint_hex'             => '#0d1117',
			'tint_alpha'           => 0,
			'gradient_enabled'     => 1,
			'gradient_direction'   => 'to-bottom',
			'gradient_from_hex'    => '#161b22',
			'gradient_from_alpha'  => 0,
			'gradient_to_hex'      => '#1f6feb',
			'gradient_to_alpha'    => 22,
			'mask_id'              => 0,
			'mask_mode'            => 'stretch',
			'mask_alpha'           => 100,

			// header — text only: author line, repo name
			'owner_enabled'        => 1,
			'owner_prefix'         => '',
			'owner_size'           => 30,
			'owner_color'          => '#8b949e',
			'title_mode'           => 'repo',
			'title_size'           => 72,
			'title_min_size'       => 40,
			'title_max_lines'      => 2,
			'title_line_height'    => 1.12,
			'title_color'          => '#f0f6fc',
			'title_align'          => 'left',
			'header_gap'           => 14,
			'title_gap'            => 34,

			// stats chips
			'stats_enabled'        => 1,
			'stats_label_stars'    => 'stars',
			'stats_label_forks'    => 'forks',
			'stats_number_format'  => 'short',
			'stats_size'           => 30,
			'stats_color'          => '#f0f6fc',
			'stats_label_color'    => '#8b949e',
			'stats_icon_color'     => '#e3b341',
			'stats_plate_enabled'  => 1,
			'stats_plate_color'    => '#21262d',
			'stats_plate_alpha'    => 100,
			'stats_plate_radius'   => 14,
			'stats_plate_padding'  => 16,
			'stats_spacing'        => 16,
			'stats_gap'            => 30,

			// languages
			'langs_enabled'        => 1,
			'langs_max'            => 3,
			'langs_min_percent'    => 1,
			'langs_other_label'    => 'Other',
			'langs_bar_height'     => 14,
			'langs_bar_radius'     => 7,
			'langs_track_color'    => '#30363d',
			'langs_size'           => 26,
			'langs_color'          => '#c9d1d9',
			'langs_legend_gap'     => 14,
			'langs_gap'            => 40,

			// three blocks
			'label_what'           => 'Что это?',
			'label_does'           => 'Что это делает?',
			'label_problem'        => 'Какую проблему решает?',
			'block_layout'         => 'stack',
			'gap_y'                => 24,
			'plate_enabled'        => 1,
			'plate_color'          => '#161b22',
			'plate_alpha'          => 100,
			'plate_radius'         => 22,
			'plate_padding'        => 30,
			'plate_border_width'   => 2,
			'plate_border_color'   => '#30363d',
			'badge_style'          => 'none',
			'badge_size'           => 44,
			'badge_bg'             => '#1f6feb',
			'badge_color'          => '#ffffff',
			'badge_gap'            => 14,
			'label_size'           => 36,
			'label_color'          => '#58a6ff',
			'label_uppercase'      => 0,
			'label_gap'            => 12,
			'text_size'            => 32,
			'text_min_size'        => 22,
			'text_color'           => '#c9d1d9',
			'text_line_height'     => 1.4,

			// footer — {site} {repo} {owner} {url}
			'footer_enabled'       => 1,
			'footer_text'          => '{site}',
			'footer_size'          => 26,
			'footer_color'         => '#8b949e',
			'footer_align'         => 'center',
			'footer_gap'           => 28,

			// logo
			'logo_id'              => 0,
			'logo_position'        => 'bottom-right',
			'logo_width'           => 14,
			'logo_alpha'           => 100,
			'logo_offset_x'        => 64,
			'logo_offset_y'        => 56,

			// frame
			'border_width'         => 0,
			'border_inset'         => 0,
			'border_color'         => '#30363d',
			'corner_radius'        => 0,

			// fonts: headings (name, labels, numbers) and body text
			'font_heading'         => '',
			'font'                 => '',
		);
	}

	/** Where a preset takes its colours from. */
	public static function palette_sources() {
		return array(
			'preset' => 'Из пресета (поля ниже)',
			'theme'  => 'Из темы — активная палитра сайта',
			'rotate' => 'Из темы — ротация палитр по пинам',
		);
	}

	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return self::seed_presets();
		}
		// Sites seeded before the theme-colour preset existed get it once; a
		// later delete is respected (the flag stays set).
		if ( ! isset( $stored['theme'] ) && ! get_option( 'lagr_pin_theme_preset_added' ) ) {
			$stored['theme'] = self::seed_presets()['theme'];
			update_option( self::OPTION_KEY, $stored, false );
			update_option( 'lagr_pin_theme_preset_added', 1, false );
		}
		$out = array();
		foreach ( $stored as $id => $preset ) {
			$out[ $id ] = wp_parse_args( is_array( $preset ) ? $preset : array(), self::schema() );
		}
		return $out;
	}

	public static function get( $id = '' ) {
		$all = self::get_all();
		if ( '' !== (string) $id && isset( $all[ $id ] ) ) {
			return $all[ $id ];
		}
		$default = (string) LAGR_Settings::get( 'pin_default_preset', 'dark' );
		if ( isset( $all[ $default ] ) ) {
			return $all[ $default ];
		}
		return $all ? reset( $all ) : self::schema();
	}

	public static function save( $id, array $values ) {
		$all = self::get_all();
		$id  = sanitize_key( $id );
		if ( '' === $id ) {
			$id = 'pin-' . substr( md5( microtime( true ) . wp_rand() ), 0, 8 );
		}
		$all[ $id ] = self::sanitize( $values );
		update_option( self::OPTION_KEY, $all, false );
		return $id;
	}

	public static function delete( $id ) {
		$all = self::get_all();
		unset( $all[ $id ] );
		if ( empty( $all ) ) {
			$all = self::seed_presets();
		}
		update_option( self::OPTION_KEY, $all, false );
	}

	public static function checkboxes() {
		return array(
			'grayscale', 'gradient_enabled', 'owner_enabled', 'stats_enabled', 'stats_plate_enabled',
			'langs_enabled', 'plate_enabled', 'label_uppercase', 'footer_enabled',
		);
	}

	public static function sanitize( array $values ) {
		$schema = self::schema();
		$out    = array();

		$ranges = array(
			'width'               => array( 600, 3000 ),
			'height'              => array( 600, 4500 ),
			'quality'             => array( 1, 100 ),
			'padding'             => array( 0, 300 ),
			'background_id'       => array( 0, PHP_INT_MAX ),
			'brightness'          => array( -255, 255 ),
			'contrast'            => array( -100, 100 ),
			'blur'                => array( 0, 20 ),
			'colorize_strength'   => array( 0, 100 ),
			'tint_alpha'          => array( 0, 100 ),
			'gradient_from_alpha' => array( 0, 100 ),
			'gradient_to_alpha'   => array( 0, 100 ),
			'mask_id'             => array( 0, PHP_INT_MAX ),
			'mask_alpha'          => array( 0, 100 ),
			'owner_size'          => array( 8, 120 ),
			'title_size'          => array( 12, 200 ),
			'title_min_size'      => array( 10, 200 ),
			'title_max_lines'     => array( 1, 5 ),
			'header_gap'          => array( 0, 200 ),
			'title_gap'           => array( 0, 300 ),
			'stats_size'          => array( 8, 120 ),
			'stats_plate_alpha'   => array( 0, 100 ),
			'stats_plate_radius'  => array( 0, 80 ),
			'stats_plate_padding' => array( 0, 80 ),
			'stats_spacing'       => array( 0, 200 ),
			'stats_gap'           => array( 0, 300 ),
			'langs_max'           => array( 1, 8 ),
			'langs_min_percent'   => array( 0, 50 ),
			'langs_bar_height'    => array( 0, 80 ),
			'langs_bar_radius'    => array( 0, 40 ),
			'langs_size'          => array( 8, 100 ),
			'langs_legend_gap'    => array( 0, 120 ),
			'langs_gap'           => array( 0, 300 ),
			'gap_y'               => array( 0, 200 ),
			'plate_alpha'         => array( 0, 100 ),
			'plate_radius'        => array( 0, 120 ),
			'plate_padding'       => array( 0, 150 ),
			'plate_border_width'  => array( 0, 12 ),
			'badge_size'          => array( 0, 160 ),
			'badge_gap'           => array( 0, 100 ),
			'label_size'          => array( 8, 140 ),
			'label_gap'           => array( 0, 100 ),
			'text_size'           => array( 8, 120 ),
			'text_min_size'       => array( 6, 120 ),
			'footer_size'         => array( 8, 80 ),
			'footer_gap'          => array( 0, 200 ),
			'logo_id'             => array( 0, PHP_INT_MAX ),
			'logo_width'          => array( 2, 60 ),
			'logo_alpha'          => array( 0, 100 ),
			'logo_offset_x'       => array( 0, 3000 ),
			'logo_offset_y'       => array( 0, 3000 ),
			'border_width'        => array( 0, 60 ),
			'border_inset'        => array( 0, 300 ),
			'corner_radius'       => array( 0, 300 ),
		);

		$colors = array(
			'background', 'colorize_hex', 'tint_hex', 'gradient_from_hex', 'gradient_to_hex', 'owner_color', 'title_color',
			'stats_color', 'stats_label_color', 'stats_icon_color', 'stats_plate_color', 'langs_track_color', 'langs_color',
			'plate_color', 'plate_border_color', 'badge_bg', 'badge_color', 'label_color', 'text_color', 'footer_color', 'border_color',
		);

		$choices = array(
			'palette_source'      => self::palette_sources(),
			'palette_layout'      => LAGR_Theme_Palette::layouts(),
			'background_mode'     => self::background_modes(),
			'block_layout'        => self::block_layouts(),
			'badge_style'         => self::badge_styles(),
			'title_mode'          => self::title_modes(),
			'stats_number_format' => self::number_formats(),
			'fit'                 => self::fit_modes(),
			'focal'               => self::positions(),
			'logo_position'       => self::positions(),
			'gradient_direction'  => self::gradient_directions(),
			'mask_mode'           => self::mask_modes(),
			'title_align'         => self::aligns(),
			'footer_align'        => self::aligns(),
			'format'              => array(
				'jpg'  => 1,
				'png'  => 1,
				'webp' => 1,
			),
		);

		foreach ( $schema as $key => $default ) {
			$value = $values[ $key ] ?? $default;

			if ( in_array( $key, self::checkboxes(), true ) ) {
				$out[ $key ] = $value ? 1 : 0;
			} elseif ( isset( $ranges[ $key ] ) ) {
				$out[ $key ] = max( $ranges[ $key ][0], min( $ranges[ $key ][1], (int) $value ) );
			} elseif ( in_array( $key, $colors, true ) ) {
				$out[ $key ] = self::sanitize_hex( $value, $default );
			} elseif ( isset( $choices[ $key ] ) ) {
				$out[ $key ] = isset( $choices[ $key ][ $value ] ) ? $value : $default;
			} elseif ( in_array( $key, array( 'title_line_height', 'text_line_height' ), true ) ) {
				$out[ $key ] = max( 0.9, min( 2.5, (float) str_replace( ',', '.', (string) $value ) ) );
			} else {
				$out[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		if ( '' === trim( $out['name'] ) ) {
			$out['name'] = 'Без названия';
		}
		$out['text_min_size']  = min( $out['text_min_size'], $out['text_size'] );
		$out['title_min_size'] = min( $out['title_min_size'], $out['title_size'] );
		return $out;
	}

	public static function sanitize_hex( $value, $default = '#000000' ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$clean = sanitize_hex_color( 0 === strpos( $value, '#' ) ? $value : '#' . $value );
		return $clean ? $clean : $default;
	}

	/** @return array{0:int,1:int,2:int} */
	public static function hex_to_rgb( $hex, $fallback = array( 0, 0, 0 ) ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return $fallback;
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** Three starting points: GitHub dark, light, and a bold accent one. */
	public static function seed_presets() {
		$base = self::schema();

		$dark = wp_parse_args( array( 'name' => 'Тёмный (GitHub)' ), $base );

		$light = wp_parse_args(
			array(
				'name'               => 'Светлый',
				'background'         => '#ffffff',
				'gradient_from_hex'  => '#ffffff',
				'gradient_to_hex'    => '#ddf4ff',
				'gradient_to_alpha'  => 60,
				'owner_color'        => '#57606a',
				'title_color'        => '#1f2328',
				'stats_color'        => '#1f2328',
				'stats_label_color'  => '#57606a',
				'stats_icon_color'   => '#bf8700',
				'stats_plate_color'  => '#f6f8fa',
				'langs_track_color'  => '#eaeef2',
				'langs_color'        => '#1f2328',
				'plate_color'        => '#f6f8fa',
				'plate_border_color' => '#d0d7de',
				'label_color'        => '#0969da',
				'text_color'         => '#1f2328',
				'footer_color'       => '#57606a',
				'border_color'       => '#d0d7de',
			),
			$base
		);

		$accent = wp_parse_args(
			array(
				'name'               => 'Акцентный, с номерами',
				'background'         => '#1b1f4b',
				'gradient_from_hex'  => '#1b1f4b',
				'gradient_from_alpha' => 0,
				'gradient_to_hex'    => '#7c3aed',
				'gradient_to_alpha'  => 70,
				'owner_color'        => '#c4b5fd',
				'title_color'        => '#ffffff',
				'stats_plate_color'  => '#ffffff',
				'stats_plate_alpha'  => 14,
				'stats_label_color'  => '#ddd6fe',
				'stats_icon_color'   => '#fde047',
				'langs_track_color'  => '#ffffff',
				'langs_color'        => '#ede9fe',
				'plate_color'        => '#ffffff',
				'plate_alpha'        => 12,
				'plate_border_width' => 0,
				'block_layout'       => 'side',
				'badge_style'        => 'circle',
				'badge_bg'           => '#fde047',
				'badge_color'        => '#1b1f4b',
				'label_color'        => '#fde047',
				'text_color'         => '#ede9fe',
				'footer_color'       => '#c4b5fd',
			),
			$base
		);

		// Theme colours, rotated per pin (layout B — accent fill). Structure is the
		// dark preset's; every colour is replaced from the theme palette at render.
		$theme = wp_parse_args(
			array(
				'name'           => 'Цвета темы (ротация)',
				'palette_source' => 'rotate',
				'palette_layout' => 'accent',
			),
			$dark
		);

		return array(
			'dark'   => $dark,
			'light'  => $light,
			'accent' => $accent,
			'theme'  => $theme,
		);
	}
}
