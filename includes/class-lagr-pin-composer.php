<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the Pinterest pin of a repo, top to bottom:
 *   author line · repo name · stars/forks chips · language bar + legend ·
 *   three blocks ("Что это?" / "Что это делает?" / "Какую проблему решает?") ·
 *   footer.
 *
 * The block grid is the infographic layout of llm-agent-articles
 * (LAA_Infographic_Composer), ported as a copy, with its one rule intact:
 * every block shares a single font size. Per-block autofit would be easy and
 * would look broken. A block that doesn't fit even at the minimum size is
 * reported in `overflow` — the caller has the model rewrite it shorter; the
 * text is never cut.
 */
class LAGR_Pin_Composer extends LAGR_Image_Composer {

	const ABSOLUTE_MIN_TEXT_SIZE = 11;

	/** GitHub linguist colours for the languages that actually show up in this catalog. */
	const LANGUAGE_COLORS = array(
		'TypeScript'       => '#3178c6',
		'JavaScript'       => '#f1e05a',
		'Python'           => '#3572A5',
		'Rust'             => '#dea584',
		'Go'               => '#00ADD8',
		'Java'             => '#b07219',
		'Kotlin'           => '#A97BFF',
		'Swift'            => '#F05138',
		'C'                => '#555555',
		'C++'              => '#f34b7d',
		'C#'               => '#178600',
		'PHP'              => '#4F5D95',
		'Ruby'             => '#701516',
		'Dart'             => '#00B4AB',
		'Shell'            => '#89e051',
		'PowerShell'       => '#012456',
		'HTML'             => '#e34c26',
		'CSS'              => '#663399',
		'SCSS'             => '#c6538c',
		'Vue'              => '#41b883',
		'Svelte'           => '#ff3e00',
		'Astro'            => '#ff5a03',
		'Lua'              => '#000080',
		'Zig'              => '#ec915c',
		'Elixir'           => '#6e4a7e',
		'Scala'            => '#c22d40',
		'Haskell'          => '#5e5086',
		'Jupyter Notebook' => '#DA5B0B',
		'MDX'              => '#fcb32c',
		'Dockerfile'       => '#384d54',
		'Makefile'         => '#427819',
		'Nix'              => '#7e7eff',
		'Objective-C'      => '#438eff',
		'R'                => '#198CE7',
		'Julia'            => '#a270ba',
		'Clojure'          => '#db5855',
		'OCaml'            => '#ef7a08',
		'Solidity'         => '#AA6746',
		'HCL'              => '#844FBA',
		'TeX'              => '#3D6117',
		'Batchfile'        => '#C1F12E',
		'Perl'             => '#0298c3',
		'Erlang'           => '#B83998',
		'Groovy'           => '#4298b8',
		'V'                => '#4f87c4',
		'Nim'              => '#ffc200',
		'Crystal'          => '#000100',
		'Gleam'            => '#ffaff3',
		'MoonBit'          => '#b92381',
	);

	/** @var array|null Footer box reserved before the blocks are fitted. */
	protected static $footer_box = null;

	/**
	 * @param array  $preset
	 * @param array  $data {owner, name, full_name, url, stars, forks, languages: [[name, percent]], blocks: [text, text, text]}
	 * @param string $out_path
	 * @return array{ok:bool,path:string,mime:string,error:string,overflow:array,text_size:int}
	 */
	public static function compose( array $preset, array $data, $out_path ) {
		if ( ! self::available() ) {
			return self::result( false, '', '', 'Расширение GD недоступно' );
		}
		if ( ! self::text_available() ) {
			return self::result( false, '', '', 'GD собран без FreeType — текст на картинку нанести нельзя' );
		}

		$preset = wp_parse_args( $preset, LAGR_Pin_Presets::schema() );
		$data   = self::normalise_data( $data );
		$fonts  = self::fonts( $preset );
		if ( '' === $fonts['body'] ) {
			return self::result( false, '', '', 'Не найден TTF-шрифт — загрузите его на экране «Pinterest-картинки»' );
		}
		$items = self::block_items( $preset, $data );
		if ( count( $items ) < 3 ) {
			return self::result( false, '', '', 'Нет текстов для трёх блоков' );
		}

		$width  = (int) $preset['width'];
		$height = (int) $preset['height'];
		$canvas = self::new_canvas( $width, $height, $preset['background'] );
		self::draw_background( $canvas, $preset );

		$area = self::content_area( $preset );
		if ( $area['w'] < 200 || $area['h'] < 300 ) {
			self::destroy_image( $canvas );
			return self::result( false, '', '', 'Поля съели всё полотно — уменьшите отступ' );
		}

		$area = self::reserve_footer( $preset, $fonts, $data, $area );
		$area = self::draw_header( $canvas, $preset, $fonts, $data, $area );
		$area = self::draw_stats( $canvas, $preset, $fonts, $data, $area );
		$area = self::draw_languages( $canvas, $preset, $fonts, $data, $area );

		$ip  = self::block_preset( $preset );
		$fit = self::fit_items( $ip, $fonts, $items, $area );
		if ( ! $fit['ok'] && empty( $fit['cells'] ) ) {
			self::destroy_image( $canvas );
			self::$footer_box = null;
			return self::result( false, '', '', $fit['error'] );
		}

		self::draw_items( $canvas, $ip, $fonts, $items, $fit );
		self::draw_footer( $canvas, $preset, $fonts, $data );
		self::apply_logo( $canvas, $preset );
		self::apply_border( $canvas, $preset );
		self::apply_corner_radius( $canvas, $preset );

		$saved = self::save( $canvas, $preset, $out_path );
		self::destroy_image( $canvas );

		if ( ! $saved['ok'] ) {
			return self::result( false, '', '', $saved['error'] );
		}
		return self::result( true, $saved['path'], $saved['mime'], '', $fit['overflow'], $fit['text_size'] );
	}

	/**
	 * Character budget per block for the prompt — measured on the real layout
	 * (header, stats and languages of a typical repo), not guessed.
	 */
	public static function block_budget( array $preset, array $sample = array() ) {
		$preset = wp_parse_args( $preset, LAGR_Pin_Presets::schema() );
		$fonts  = self::fonts( $preset );
		if ( '' === $fonts['body'] || ! self::text_available() ) {
			return 160;
		}
		$data = self::normalise_data(
			wp_parse_args(
				$sample,
				array(
					'owner'     => 'owner',
					'name'      => 'repository-name',
					'full_name' => 'owner/repository-name',
					'stars'     => 12345,
					'forks'     => 678,
					'languages' => array( array( 'TypeScript', 70 ), array( 'Python', 25 ), array( 'Shell', 5 ) ),
					'blocks'    => array( 'x', 'x', 'x' ),
				)
			)
		);

		$area = self::content_area( $preset );
		$area = self::reserve_footer( $preset, $fonts, $data, $area );
		$area = self::draw_header( null, $preset, $fonts, $data, $area );
		$area = self::draw_stats( null, $preset, $fonts, $data, $area );
		$area = self::draw_languages( null, $preset, $fonts, $data, $area );
		self::$footer_box = null;

		$ip      = self::block_preset( $preset );
		$cell_h  = (int) floor( ( $area['h'] - (int) $ip['gap_y'] * 2 ) / 3 );
		$inner   = $ip['plate_enabled'] ? (int) $ip['plate_padding'] : 0;
		$badge   = 'none' === $ip['badge_style'] ? 0 : (int) $ip['badge_size'] + (int) $ip['badge_gap'];
		$text_w  = $area['w'] - $inner * 2 - ( 'list' === $ip['layout'] ? $badge : 0 );
		$label_h = (int) round( (int) $ip['label_size'] * 1.2 ) + (int) $ip['label_gap'];
		$text_h  = $cell_h - $inner * 2 - $label_h - ( 'list' === $ip['layout'] ? 0 : $badge );

		// At the middle of the size range: the prompt number should be what
		// typically fits, not only what fits at the largest size.
		$size = (int) round( ( (int) $ip['text_size'] + (int) $ip['text_min_size'] ) / 2 );
		return self::estimate_fitting_chars( $fonts['body'], $size, $text_w, $text_h, $ip, array( 'label' => '' ) );
	}

	// -------------------------------------------------------------- inputs

	protected static function fonts( array $preset ) {
		$body = self::resolve_font( $preset['font'], false );
		$head = self::resolve_font( '' !== (string) $preset['font_heading'] ? $preset['font_heading'] : $preset['font'], true );
		return array(
			'body' => $body,
			'head' => $head ? $head : $body,
		);
	}

	protected static function normalise_data( array $data ) {
		$clean = function ( $s ) {
			return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $s ) ) );
		};
		$full  = $clean( $data['full_name'] ?? '' );
		$parts = explode( '/', $full, 2 );

		$langs = array();
		foreach ( (array) ( $data['languages'] ?? array() ) as $row ) {
			$name = $clean( is_array( $row ) ? ( $row[0] ?? '' ) : '' );
			$pct  = is_array( $row ) ? (float) ( $row[1] ?? 0 ) : 0;
			if ( '' !== $name && $pct > 0 ) {
				$langs[] = array( $name, $pct );
			}
		}

		return array(
			'owner'     => $clean( $data['owner'] ?? ( $parts[0] ?? '' ) ),
			'name'      => $clean( $data['name'] ?? ( $parts[1] ?? $full ) ),
			'full_name' => $full,
			'url'       => $clean( $data['url'] ?? ( $full ? 'github.com/' . $full : '' ) ),
			'stars'     => max( 0, (int) ( $data['stars'] ?? 0 ) ),
			'forks'     => max( 0, (int) ( $data['forks'] ?? 0 ) ),
			'languages' => $langs,
			'blocks'    => array_map( $clean, array_values( (array) ( $data['blocks'] ?? array() ) ) ),
		);
	}

	protected static function block_items( array $preset, array $data ) {
		$labels = array( $preset['label_what'], $preset['label_does'], $preset['label_problem'] );
		$items  = array();
		foreach ( $labels as $i => $label ) {
			$text = $data['blocks'][ $i ] ?? '';
			if ( '' === $text ) {
				return array();
			}
			$items[] = array(
				'label' => trim( (string) $label ),
				'text'  => $text,
			);
		}
		return $items;
	}

	/** The pin's block settings in the vocabulary of the ported infographic grid. */
	protected static function block_preset( array $preset ) {
		return array_merge(
			$preset,
			array(
				'layout'        => 'side' === $preset['block_layout'] ? 'list' : 'grid',
				'columns'       => 1,
				'gap_x'         => 0,
				'label_enabled' => 1,
			)
		);
	}

	protected static function content_area( array $preset ) {
		$padding = (int) $preset['padding'];
		return array(
			'x' => $padding,
			'y' => $padding,
			'w' => (int) $preset['width'] - $padding * 2,
			'h' => (int) $preset['height'] - $padding * 2,
		);
	}

	protected static function shrink( array $area, $used ) {
		return array(
			'x' => $area['x'],
			'y' => $area['y'] + $used,
			'w' => $area['w'],
			'h' => $area['h'] - $used,
		);
	}

	// ---------------------------------------------------------- background

	protected static function draw_background( $canvas, array $preset ) {
		if ( 'image' === $preset['background_mode'] && (int) $preset['background_id'] > 0 ) {
			$path = get_attached_file( (int) $preset['background_id'] );
			if ( $path && is_readable( $path ) ) {
				$source = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore
				if ( $source ) {
					self::draw_source( $canvas, $source, $preset );
					self::destroy_image( $source );
					self::apply_filters( $canvas, $preset );
				}
			}
		}
		self::apply_tint( $canvas, $preset );
		self::apply_gradient( $canvas, $preset );
		self::apply_mask( $canvas, $preset );
	}

	// -------------------------------------------------------------- header

	/**
	 * Author line and repo name. A repo name is one long token
	 * ("awesome-mcp-servers"), so it wraps on - _ . / rather than on spaces;
	 * it is never cut — below the minimum size it simply takes another line.
	 * With $canvas = null only measures.
	 */
	protected static function draw_header( $canvas, array $preset, array $fonts, array $data, array $area ) {
		$used = 0;

		if ( $preset['owner_enabled'] && 'repo' === $preset['title_mode'] && '' !== $data['owner'] ) {
			$size  = (int) $preset['owner_size'];
			$lh    = (int) round( $size * 1.3 );
			$lines = self::wrap( $fonts['body'], $size, trim( $preset['owner_prefix'] . $data['owner'] ), $area['w'] );
			if ( $canvas ) {
				self::draw_lines( $canvas, $lines, $fonts['body'], $size, $lh, $area['x'], $area['y'], $area['w'], self::colour( $canvas, $preset['owner_color'], '#8b949e' ), $preset['title_align'] );
			}
			$used += $lh * count( $lines ) + (int) $preset['header_gap'];
		}

		$title = 'full' === $preset['title_mode'] ? $data['full_name'] : $data['name'];
		if ( '' !== $title ) {
			$size  = (int) $preset['title_size'];
			$min   = (int) $preset['title_min_size'];
			$max   = (int) $preset['title_max_lines'];
			$lines = self::wrap_name( $fonts['head'], $size, $title, $area['w'] );
			while ( count( $lines ) > $max && $size > $min ) {
				$size -= 2;
				$lines = self::wrap_name( $fonts['head'], $size, $title, $area['w'] );
			}
			$lh = (int) round( $size * (float) $preset['title_line_height'] );
			if ( $canvas ) {
				self::draw_lines( $canvas, $lines, $fonts['head'], $size, $lh, $area['x'], $area['y'] + $used, $area['w'], self::colour( $canvas, $preset['title_color'], '#ffffff' ), $preset['title_align'] );
			}
			$used += $lh * count( $lines ) + (int) $preset['title_gap'];
		}

		return self::shrink( $area, $used );
	}

	/** Wraps a name on its own separators; spaces still break as usual. */
	public static function wrap_name( $font, $size, $text, $max_width ) {
		$tokens = preg_split( '/(?<=[-_.\/])|(?=\s)/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		$lines  = array();
		$line   = '';
		foreach ( (array) $tokens as $token ) {
			$candidate = $line . $token;
			if ( '' === $line || self::text_width( $font, $size, rtrim( $candidate ) ) <= $max_width ) {
				$line = $candidate;
				continue;
			}
			$lines[] = rtrim( $line );
			$line    = ltrim( $token );
		}
		if ( '' !== trim( $line ) ) {
			$lines[] = rtrim( $line );
		}
		// A single token wider than the box falls back to character wrapping.
		$out = array();
		foreach ( $lines as $l ) {
			$out = array_merge( $out, self::text_width( $font, $size, $l ) > $max_width ? self::wrap( $font, $size, $l, $max_width ) : array( $l ) );
		}
		return $out ? $out : array( '' );
	}

	// --------------------------------------------------------------- stats

	public static function format_number( $n, $mode = 'short' ) {
		$n = (int) $n;
		if ( 'full' === $mode ) {
			return number_format( $n, 0, '.', ' ' );
		}
		if ( $n < 1000 ) {
			return (string) $n;
		}
		if ( $n < 1000000 ) {
			$v = $n / 1000;
			return ( $v < 100 ? rtrim( rtrim( number_format( $v, 1, '.', '' ), '0' ), '.' ) : (string) round( $v ) ) . 'k';
		}
		$v = $n / 1000000;
		return rtrim( rtrim( number_format( $v, 1, '.', '' ), '0' ), '.' ) . 'M';
	}

	/** Two chips: ★ N stars · fork-icon N forks. */
	protected static function draw_stats( $canvas, array $preset, array $fonts, array $data, array $area ) {
		if ( ! $preset['stats_enabled'] ) {
			return $area;
		}
		$size   = (int) $preset['stats_size'];
		$pad    = $preset['stats_plate_enabled'] ? (int) $preset['stats_plate_padding'] : 0;
		$icon   = (int) round( $size * 0.95 );
		$gap    = (int) round( $size * 0.35 );
		$chip_h = (int) round( $size * 1.35 ) + $pad * 2;

		$chips = array(
			array( 'star', self::format_number( $data['stars'], $preset['stats_number_format'] ), $preset['stats_label_stars'] ),
			array( 'fork', self::format_number( $data['forks'], $preset['stats_number_format'] ), $preset['stats_label_forks'] ),
		);

		$x     = $area['x'];
		$y     = $area['y'];
		$row_h = $chip_h;
		foreach ( $chips as $chip ) {
			list( $type, $number, $label ) = $chip;
			$num_w  = self::text_width( $fonts['head'], $size, $number );
			$lab_w  = '' !== $label ? self::text_width( $fonts['body'], $size, $label ) : 0;
			$chip_w = $pad * 2 + $icon + $gap + $num_w + ( $lab_w ? $gap + $lab_w : 0 );

			if ( $x > $area['x'] && $x + $chip_w > $area['x'] + $area['w'] ) {
				$x      = $area['x'];
				$y     += $chip_h + (int) $preset['stats_spacing'];
				$row_h += $chip_h + (int) $preset['stats_spacing'];
			}

			if ( $canvas ) {
				if ( $preset['stats_plate_enabled'] ) {
					self::rounded_rect( $canvas, $x, $y, $x + $chip_w, $y + $chip_h, (int) $preset['stats_plate_radius'], LAGR_Pin_Presets::hex_to_rgb( $preset['stats_plate_color'] ), (int) $preset['stats_plate_alpha'] );
				}
				$cy = $y + (int) round( $chip_h / 2 );
				self::draw_icon( $canvas, $type, $x + $pad, $cy - (int) round( $icon / 2 ), $icon, LAGR_Pin_Presets::hex_to_rgb( $preset['stats_icon_color'], array( 227, 179, 65 ) ) );
				$tx = $x + $pad + $icon + $gap;
				self::draw_text_centered( $canvas, $fonts['head'], $size, $tx, $cy, $number, self::colour( $canvas, $preset['stats_color'], '#ffffff' ) );
				if ( $lab_w ) {
					self::draw_text_centered( $canvas, $fonts['body'], $size, $tx + $num_w + $gap, $cy, $label, self::colour( $canvas, $preset['stats_label_color'], '#8b949e' ) );
				}
			}
			$x += $chip_w + (int) $preset['stats_spacing'];
		}

		return self::shrink( $area, $row_h + (int) $preset['stats_gap'] );
	}

	/** Text vertically centred on $cy by the font's cap metrics (stable across strings). */
	protected static function draw_text_centered( $canvas, $font, $size, $x, $cy, $text, $color ) {
		$box = imagettfbbox( $size, 0, $font, 'Hk0' );
		$cap = $box ? abs( $box[7] ) - abs( $box[1] ) : (int) round( $size * 0.7 );
		imagealphablending( $canvas, true );
		imagettftext( $canvas, $size, 0, (int) $x, (int) round( $cy + $cap / 2 ), $color, $font, $text );
	}

	/** Vector icons drawn at 4× and downsampled — GD has no anti-aliased polygons. */
	protected static function draw_icon( $canvas, $type, $x, $y, $size, array $rgb ) {
		$s     = 4;
		$big   = $size * $s;
		$layer = imagecreatetruecolor( $big, $big );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefilledrectangle( $layer, 0, 0, $big - 1, $big - 1, imagecolorallocatealpha( $layer, 0, 0, 0, self::GD_TRANSPARENT ) );
		imagealphablending( $layer, true );
		$c = imagecolorallocatealpha( $layer, $rgb[0], $rgb[1], $rgb[2], 0 );

		if ( 'star' === $type ) {
			$cx     = $big / 2;
			$cy     = $big / 2 + $big * 0.04;
			$outer  = $big * 0.5;
			$inner  = $outer * 0.42;
			$points = array();
			for ( $i = 0; $i < 10; $i++ ) {
				$r        = 0 === $i % 2 ? $outer : $inner;
				$a        = deg2rad( -90 + $i * 36 );
				$points[] = (int) round( $cx + $r * cos( $a ) );
				$points[] = (int) round( $cy + $r * sin( $a ) );
			}
			imagefilledpolygon( $layer, $points, $c );
		} else {
			// fork: two nodes on top, one below, joined like a "Y".
			$t  = max( 2, (int) round( $big * 0.11 ) );
			$r  = (int) round( $big * 0.15 );
			$lx = (int) round( $big * 0.25 );
			$rx = (int) round( $big * 0.75 );
			$mx = (int) round( $big * 0.5 );
			$ty = (int) round( $big * 0.2 );
			$by = (int) round( $big * 0.8 );
			$my = (int) round( $big * 0.52 );
			imagesetthickness( $layer, $t );
			imageline( $layer, $lx, $ty, $lx, $my - $r, $c );
			imageline( $layer, $rx, $ty, $rx, $my - $r, $c );
			imageline( $layer, $lx, $my - $r, $mx, $my, $c );
			imageline( $layer, $rx, $my - $r, $mx, $my, $c );
			imageline( $layer, $mx, $my, $mx, $by, $c );
			imagesetthickness( $layer, 1 );
			foreach ( array( array( $lx, $ty ), array( $rx, $ty ), array( $mx, $by ) ) as $node ) {
				imagefilledellipse( $layer, $node[0], $node[1], $r * 2, $r * 2, $c );
			}
		}

		$small = imagecreatetruecolor( $size, $size );
		imagealphablending( $small, false );
		imagesavealpha( $small, true );
		imagefilledrectangle( $small, 0, 0, $size - 1, $size - 1, imagecolorallocatealpha( $small, 0, 0, 0, self::GD_TRANSPARENT ) );
		imagecopyresampled( $small, $layer, 0, 0, 0, 0, $size, $size, $big, $big );
		self::destroy_image( $layer );

		imagealphablending( $canvas, true );
		imagecopy( $canvas, $small, (int) $x, (int) $y, 0, 0, $size, $size );
		self::destroy_image( $small );
	}

	// ----------------------------------------------------------- languages

	/** Top N languages by share; the rest folded into "Other". */
	public static function top_languages( array $languages, $max, $min_percent, $other_label ) {
		usort(
			$languages,
			function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		$out   = array();
		$other = 0;
		foreach ( $languages as $i => $row ) {
			if ( $i < $max && $row[1] >= $min_percent ) {
				$out[] = $row;
			} else {
				$other += $row[1];
			}
		}
		if ( $other >= 0.5 && '' !== trim( (string) $other_label ) ) {
			$out[] = array( $other_label, $other, true );
		}
		return $out;
	}

	/** "Other": halfway between the legend text and the bar track — visible, but clearly not a language. */
	protected static function other_rgb( array $preset ) {
		$a = LAGR_Pin_Presets::hex_to_rgb( $preset['langs_color'], array( 201, 209, 217 ) );
		$b = LAGR_Pin_Presets::hex_to_rgb( $preset['langs_track_color'], array( 48, 54, 61 ) );
		return array( (int) ( ( $a[0] + $b[0] ) / 2 ), (int) ( ( $a[1] + $b[1] ) / 2 ), (int) ( ( $a[2] + $b[2] ) / 2 ) );
	}

	public static function language_color( $name ) {
		if ( isset( self::LANGUAGE_COLORS[ $name ] ) ) {
			return self::LANGUAGE_COLORS[ $name ];
		}
		// Unknown language: a stable colour from the name rather than grey for everything.
		$h = substr( md5( (string) $name ), 0, 6 );
		return '#' . $h;
	}

	protected static function draw_languages( $canvas, array $preset, array $fonts, array $data, array $area ) {
		if ( ! $preset['langs_enabled'] || empty( $data['languages'] ) ) {
			return $area;
		}
		$langs = self::top_languages( $data['languages'], (int) $preset['langs_max'], (float) $preset['langs_min_percent'], $preset['langs_other_label'] );
		$total = array_sum( array_column( $langs, 1 ) );
		if ( $total <= 0 ) {
			return $area;
		}

		$used  = 0;
		$bar_h = (int) $preset['langs_bar_height'];
		if ( $bar_h > 0 ) {
			if ( $canvas ) {
				self::draw_language_bar( $canvas, $preset, $langs, $total, $area['x'], $area['y'], $area['w'], $bar_h );
			}
			$used += $bar_h + (int) $preset['langs_legend_gap'];
		}

		$size  = (int) $preset['langs_size'];
		$lh    = (int) round( $size * 1.45 );
		$dot   = max( 6, (int) round( $size * 0.5 ) );
		$space = (int) round( $size * 1.1 );
		$x     = $area['x'];
		$y     = $area['y'] + $used;
		$rows  = 1;
		foreach ( $langs as $row ) {
			$label = $row[0] . ' ' . self::format_percent( $row[1] / $total * 100 );
			$w     = $dot + (int) round( $size * 0.4 ) + self::text_width( $fonts['body'], $size, $label );
			if ( $x > $area['x'] && $x + $w > $area['x'] + $area['w'] ) {
				$x  = $area['x'];
				$y += $lh;
				++$rows;
			}
			if ( $canvas ) {
				$cy  = $y + (int) round( $lh / 2 );
				$rgb = ! empty( $row[2] ) ? self::other_rgb( $preset ) : LAGR_Pin_Presets::hex_to_rgb( self::language_color( $row[0] ) );
				imagealphablending( $canvas, true );
				imagefilledellipse( $canvas, $x + (int) round( $dot / 2 ), $cy, $dot, $dot, imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], 0 ) );
				self::draw_text_centered( $canvas, $fonts['body'], $size, $x + $dot + (int) round( $size * 0.4 ), $cy, $label, self::colour( $canvas, $preset['langs_color'], '#c9d1d9' ) );
			}
			$x += $w + $space;
		}
		$used += $lh * $rows + (int) $preset['langs_gap'];

		return self::shrink( $area, $used );
	}

	public static function format_percent( $pct ) {
		return ( $pct >= 10 || $pct < 0.95 ? (string) round( $pct, $pct < 0.95 ? 1 : 0 ) : rtrim( rtrim( number_format( $pct, 1, '.', '' ), '0' ), '.' ) ) . '%';
	}

	/** Segmented bar on its own layer, then the layer's corners rounded off. */
	protected static function draw_language_bar( $canvas, array $preset, array $langs, $total, $x, $y, $w, $h ) {
		$layer = imagecreatetruecolor( $w, $h );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		$track = LAGR_Pin_Presets::hex_to_rgb( $preset['langs_track_color'] );
		imagefilledrectangle( $layer, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha( $layer, $track[0], $track[1], $track[2], 0 ) );

		$pos  = 0;
		$last = count( $langs ) - 1;
		foreach ( $langs as $i => $row ) {
			$seg = $i === $last ? $w - $pos : (int) round( $w * $row[1] / $total );
			if ( $seg < 1 ) {
				continue;
			}
			$rgb = ! empty( $row[2] ) ? self::other_rgb( $preset ) : LAGR_Pin_Presets::hex_to_rgb( self::language_color( $row[0] ) );
			imagefilledrectangle( $layer, $pos, 0, min( $w - 1, $pos + $seg - 1 ), $h - 1, imagecolorallocatealpha( $layer, $rgb[0], $rgb[1], $rgb[2], 0 ) );
			$pos += $seg;
		}

		$r = min( (int) $preset['langs_bar_radius'], (int) floor( $h / 2 ) );
		if ( $r > 0 ) {
			$clear = imagecolorallocatealpha( $layer, 0, 0, 0, self::GD_TRANSPARENT );
			$r2    = $r * $r;
			foreach ( array( array( 0, 0, $r, $r ), array( $w - $r, 0, $w - 1 - $r, $r ), array( 0, $h - $r, $r, $h - 1 - $r ), array( $w - $r, $h - $r, $w - 1 - $r, $h - 1 - $r ) ) as $c ) {
				for ( $py = 0; $py < $r; $py++ ) {
					for ( $px = 0; $px < $r; $px++ ) {
						$ax = $c[0] + $px;
						$ay = $c[1] + $py;
						if ( ( $ax - $c[2] ) ** 2 + ( $ay - $c[3] ) ** 2 > $r2 ) {
							imagesetpixel( $layer, $ax, $ay, $clear );
						}
					}
				}
			}
		}

		imagealphablending( $canvas, true );
		imagecopy( $canvas, $layer, (int) $x, (int) $y, 0, 0, $w, $h );
		self::destroy_image( $layer );
	}

	// -------------------------------------------------------------- footer

	public static function footer_text( array $preset, array $data ) {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return trim(
			strtr(
				(string) $preset['footer_text'],
				array(
					'{site}'  => $host,
					'{repo}'  => $data['full_name'],
					'{owner}' => $data['owner'],
					'{url}'   => $data['url'],
				)
			)
		);
	}

	protected static function reserve_footer( array $preset, array $fonts, array $data, array $area ) {
		self::$footer_box = null;
		if ( ! $preset['footer_enabled'] || '' === self::footer_text( $preset, $data ) ) {
			return $area;
		}
		$used             = (int) round( (int) $preset['footer_size'] * 1.3 ) + (int) $preset['footer_gap'];
		self::$footer_box = array(
			'x' => $area['x'],
			'y' => $area['y'] + $area['h'] - $used + (int) $preset['footer_gap'],
			'w' => $area['w'],
		);
		$area['h'] -= $used;
		return $area;
	}

	protected static function draw_footer( $canvas, array $preset, array $fonts, array $data ) {
		if ( ! self::$footer_box ) {
			return;
		}
		$size = (int) $preset['footer_size'];
		$box  = self::$footer_box;
		self::draw_lines( $canvas, array( self::footer_text( $preset, $data ) ), $fonts['body'], $size, (int) round( $size * 1.3 ), $box['x'], $box['y'], $box['w'], self::colour( $canvas, $preset['footer_color'], '#8b949e' ), $preset['footer_align'] );
		self::$footer_box = null;
	}

	// ----------------------------------------- block grid (ported, 1 column)

	/**
	 * Finds the one font size at which every block fits its cell. Blocks that
	 * don't fit even at the minimum are listed in `overflow`.
	 */
	protected static function fit_items( array $preset, array $fonts, array $items, array $area ) {
		$count   = count( $items );
		$columns = 1;
		$rows    = $count;
		$gap_y   = (int) $preset['gap_y'];

		$cell_w = $area['w'];
		$cell_h = (int) floor( ( $area['h'] - $gap_y * ( $rows - 1 ) ) / $rows );

		if ( $cell_w < 80 || $cell_h < 60 ) {
			return array(
				'ok'        => false,
				'cells'     => array(),
				'error'     => sprintf( 'На блок осталось %d×%d px — уменьшите шапку, языки или отступы.', $cell_w, max( 0, $cell_h ) ),
				'overflow'  => array(),
				'text_size' => 0,
			);
		}

		$inner_pad = $preset['plate_enabled'] ? (int) $preset['plate_padding'] : 0;
		$inner_w   = $cell_w - $inner_pad * 2;
		$inner_h   = $cell_h - $inner_pad * 2;

		$is_list  = 'list' === $preset['layout'];
		$max_size = (int) $preset['text_size'];
		$min_size = max( self::ABSOLUTE_MIN_TEXT_SIZE, (int) $preset['text_min_size'] );
		$base     = max( 1, (int) $preset['text_size'] );

		$chosen   = 0;
		$measured = array();
		$metrics  = array();

		for ( $size = $max_size; $size >= $min_size; $size-- ) {
			$ratio      = $size / $base;
			$badge_size = 'none' === $preset['badge_style'] ? 0 : max( 14, (int) round( (int) $preset['badge_size'] * $ratio ) );
			$badge_gap  = 0 === $badge_size ? 0 : max( 5, (int) round( (int) $preset['badge_gap'] * $ratio ) );

			$text_w = $is_list ? $inner_w - $badge_size - $badge_gap : $inner_w;
			$head_h = $is_list ? 0 : ( $badge_size ? $badge_size + $badge_gap : 0 );
			if ( $text_w < 60 ) {
				continue;
			}

			$candidate = self::measure_all( $preset, $fonts, $items, $size, $text_w );
			$needed    = 0;
			foreach ( $candidate as $m ) {
				$needed = max( $needed, $m['height'] );
			}

			$metrics  = compact( 'badge_size', 'badge_gap', 'text_w', 'head_h' );
			$measured = $candidate;
			$chosen   = $size;

			if ( $head_h + $needed <= $inner_h ) {
				break;
			}
		}

		if ( ! $chosen ) {
			return array(
				'ok'        => false,
				'cells'     => array(),
				'error'     => 'Под текст в блоке осталось меньше 60 px — уменьшите маркер или поля.',
				'overflow'  => array(),
				'text_size' => 0,
			);
		}

		$overflow = array();
		foreach ( $measured as $i => $m ) {
			if ( $metrics['head_h'] + $m['height'] > $inner_h ) {
				$overflow[] = array(
					'index'      => $i,
					'text'       => $items[ $i ]['text'],
					'fits_chars' => self::estimate_fitting_chars( $fonts['body'], $chosen, $metrics['text_w'], $inner_h - $metrics['head_h'] - $m['label_h'], $preset, array( 'label' => '' ) ),
				);
			}
		}

		$cells = array();
		foreach ( $items as $i => $item ) {
			$cells[] = array(
				'x'      => $area['x'],
				'y'      => $area['y'] + $i * ( $cell_h + $gap_y ),
				'w'      => $cell_w,
				'h'      => $cell_h,
				'inner'  => $inner_pad,
				'text_w' => $metrics['text_w'],
				'head_h' => $metrics['head_h'],
				'lines'  => $measured[ $i ]['lines'],
				'label'  => $measured[ $i ]['label_lines'],
			);
		}

		return array(
			'ok'         => empty( $overflow ),
			'cells'      => $cells,
			'error'      => '',
			'overflow'   => $overflow,
			'text_size'  => $chosen,
			'is_list'    => $is_list,
			'badge_size' => $metrics['badge_size'],
			'badge_gap'  => $metrics['badge_gap'],
		);
	}

	protected static function label_size( array $preset, $size ) {
		$ratio = (int) $preset['text_size'] > 0 ? $size / (int) $preset['text_size'] : 1;
		return max( 8, (int) round( (int) $preset['label_size'] * $ratio ) );
	}

	protected static function measure_all( array $preset, array $fonts, array $items, $size, $text_w ) {
		$label_size  = self::label_size( $preset, $size );
		$line_height = (int) round( $size * (float) $preset['text_line_height'] );
		$label_lh    = (int) round( $label_size * 1.2 );

		$out = array();
		foreach ( $items as $item ) {
			$label_lines = array();
			$label_h     = 0;
			if ( '' !== $item['label'] ) {
				$label       = $preset['label_uppercase'] ? mb_strtoupper( $item['label'] ) : $item['label'];
				$label_lines = self::wrap( $fonts['head'], $label_size, $label, $text_w );
				$label_h     = $label_lh * count( $label_lines ) + (int) $preset['label_gap'];
			}
			$lines = self::wrap( $fonts['body'], $size, $item['text'], $text_w );
			$out[] = array(
				'lines'       => $lines,
				'label_lines' => $label_lines,
				'label_h'     => $label_h,
				'height'      => $label_h + $line_height * count( $lines ),
			);
		}
		return $out;
	}

	/** Rough character budget for a block that overflowed — fed to the rewrite. */
	protected static function estimate_fitting_chars( $font, $size, $text_w, $available_h, array $preset, array $item ) {
		$line_height = max( 1, (int) round( $size * (float) $preset['text_line_height'] ) );
		$lines       = max( 1, (int) floor( $available_h / $line_height ) );

		// Measured, not guessed: Cyrillic is noticeably wider than Latin at one size.
		$sample = 'строка обычного текста в тезисе';
		$per_px = mb_strlen( $sample ) / max( 1, self::text_width( $font, $size, $sample ) );

		return max( 30, (int) floor( $text_w * $per_px * $lines * 0.92 ) );
	}

	protected static function draw_items( $canvas, array $preset, array $fonts, array $items, array $fit ) {
		$size        = (int) $fit['text_size'];
		$label_size  = self::label_size( $preset, $size );
		$line_height = (int) round( $size * (float) $preset['text_line_height'] );
		$label_lh    = (int) round( $label_size * 1.2 );
		$text_color  = self::colour( $canvas, $preset['text_color'], '#c9d1d9' );
		$label_color = self::colour( $canvas, $preset['label_color'], '#58a6ff' );

		foreach ( $fit['cells'] as $i => $cell ) {
			if ( $preset['plate_enabled'] ) {
				self::rounded_rect( $canvas, $cell['x'], $cell['y'], $cell['x'] + $cell['w'], $cell['y'] + $cell['h'], (int) $preset['plate_radius'], LAGR_Pin_Presets::hex_to_rgb( $preset['plate_color'] ), (int) $preset['plate_alpha'] );
				if ( (int) $preset['plate_border_width'] > 0 ) {
					self::stroke_rounded_rect( $canvas, $cell['x'], $cell['y'], $cell['x'] + $cell['w'], $cell['y'] + $cell['h'], (int) $preset['plate_radius'], (int) $preset['plate_border_width'], LAGR_Pin_Presets::hex_to_rgb( $preset['plate_border_color'] ) );
				}
			}

			$x = $cell['x'] + $cell['inner'];
			$y = $cell['y'] + $cell['inner'];

			// Centre vertically: cells are sized by the tallest block.
			$content_h = $cell['head_h'] + $line_height * count( $cell['lines'] );
			if ( ! empty( $cell['label'] ) ) {
				$content_h += $label_lh * count( $cell['label'] ) + (int) $preset['label_gap'];
			}
			$y += (int) floor( max( 0, ( $cell['h'] - $cell['inner'] * 2 ) - $content_h ) / 2 );

			if ( $fit['badge_size'] > 0 ) {
				self::draw_badge( $canvas, $preset, $fonts['head'], $i + 1, $x, $y, $fit['badge_size'] );
			}

			$text_x = $fit['is_list'] ? $x + $fit['badge_size'] + $fit['badge_gap'] : $x;
			$text_y = $fit['is_list'] ? $y : $y + $cell['head_h'];

			if ( ! empty( $cell['label'] ) ) {
				self::draw_lines( $canvas, $cell['label'], $fonts['head'], $label_size, $label_lh, $text_x, $text_y, $cell['text_w'], $label_color, 'left' );
				$text_y += $label_lh * count( $cell['label'] ) + (int) $preset['label_gap'];
			}
			self::draw_lines( $canvas, $cell['lines'], $fonts['body'], $size, $line_height, $text_x, $text_y, $cell['text_w'], $text_color, 'left' );
		}
	}

	protected static function draw_badge( $canvas, array $preset, $font, $number, $x, $y, $size ) {
		$style = $preset['badge_style'];
		$bg    = LAGR_Pin_Presets::hex_to_rgb( $preset['badge_bg'], array( 31, 111, 235 ) );
		$fg    = self::colour( $canvas, $preset['badge_color'], '#ffffff' );

		imagealphablending( $canvas, true );
		if ( 'bullet' === $style ) {
			$dot = max( 6, (int) round( $size * 0.34 ) );
			imagefilledellipse( $canvas, $x + (int) round( $size / 2 ), $y + (int) round( $size / 2 ), $dot, $dot, imagecolorallocatealpha( $canvas, $bg[0], $bg[1], $bg[2], 0 ) );
			return;
		}
		if ( 'circle' === $style ) {
			imagefilledellipse( $canvas, $x + (int) round( $size / 2 ), $y + (int) round( $size / 2 ), $size, $size, imagecolorallocatealpha( $canvas, $bg[0], $bg[1], $bg[2], 0 ) );
		} elseif ( 'square' === $style ) {
			self::rounded_rect( $canvas, $x, $y, $x + $size, $y + $size, (int) round( $size * 0.25 ), $bg, 100 );
		}

		$glyph = max( 8, (int) round( $size * ( 'plain' === $style ? 0.62 : 0.46 ) ) );
		$color = 'plain' === $style ? imagecolorallocatealpha( $canvas, $bg[0], $bg[1], $bg[2], 0 ) : $fg;
		$label = (string) $number;
		$box   = imagettfbbox( $glyph, 0, $font, $label );
		$tw    = $box ? abs( $box[2] - $box[0] ) : $glyph;
		$ink_h = $box ? abs( $box[7] ) - abs( $box[1] ) : $glyph;
		imagettftext( $canvas, $glyph, 0, $x + (int) round( ( $size - $tw ) / 2 ), $y + (int) round( ( $size + $ink_h ) / 2 ), $color, $font, $label );
	}

	// ------------------------------------------------------------- helpers

	protected static function draw_lines( $canvas, array $lines, $font, $size, $line_height, $x, $y, $max_width, $color, $align = 'left' ) {
		$metrics = imagettfbbox( $size, 0, $font, self::METRIC_SAMPLE );
		$ascent  = $metrics ? abs( $metrics[7] ) : (int) round( $size * 0.8 );
		imagealphablending( $canvas, true );
		foreach ( $lines as $i => $line ) {
			$line_w = self::text_width( $font, $size, $line );
			switch ( $align ) {
				case 'center':
					$lx = $x + (int) round( ( $max_width - $line_w ) / 2 );
					break;
				case 'right':
					$lx = $x + ( $max_width - $line_w );
					break;
				default:
					$lx = $x;
			}
			imagettftext( $canvas, $size, 0, (int) $lx, (int) ( $y + $ascent + $i * $line_height ), $color, $font, $line );
		}
		return $line_height * count( $lines );
	}

	/** Rounded-rect outline, drawn as nested rings so thickness stays inside. */
	protected static function stroke_rounded_rect( $canvas, $x1, $y1, $x2, $y2, $radius, $thickness, array $rgb ) {
		$color = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], 0 );
		imagealphablending( $canvas, true );
		for ( $i = 0; $i < $thickness; $i++ ) {
			$r = max( 0, $radius - $i );
			imageline( $canvas, $x1 + $i + $r, $y1 + $i, $x2 - $i - $r, $y1 + $i, $color );
			imageline( $canvas, $x1 + $i + $r, $y2 - $i, $x2 - $i - $r, $y2 - $i, $color );
			imageline( $canvas, $x1 + $i, $y1 + $i + $r, $x1 + $i, $y2 - $i - $r, $color );
			imageline( $canvas, $x2 - $i, $y1 + $i + $r, $x2 - $i, $y2 - $i - $r, $color );
			if ( $r > 0 ) {
				$d = $r * 2;
				imagearc( $canvas, $x1 + $i + $r, $y1 + $i + $r, $d, $d, 180, 270, $color );
				imagearc( $canvas, $x2 - $i - $r, $y1 + $i + $r, $d, $d, 270, 360, $color );
				imagearc( $canvas, $x1 + $i + $r, $y2 - $i - $r, $d, $d, 90, 180, $color );
				imagearc( $canvas, $x2 - $i - $r, $y2 - $i - $r, $d, $d, 0, 90, $color );
			}
		}
	}

	protected static function colour( $canvas, $hex, $fallback ) {
		$rgb = LAGR_Pin_Presets::hex_to_rgb( $hex, LAGR_Pin_Presets::hex_to_rgb( $fallback ) );
		return imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], 0 );
	}

	protected static function result( $ok, $path, $mime, $error, $overflow = array(), $text_size = 0 ) {
		return array(
			'ok'        => (bool) $ok,
			'path'      => $path,
			'mime'      => $mime,
			'error'     => $error,
			'overflow'  => $overflow,
			'text_size' => (int) $text_size,
		);
	}
}
