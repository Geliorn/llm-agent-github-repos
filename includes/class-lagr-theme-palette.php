<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Colour palettes of the active block theme, so Pinterest pictures can follow
 * the site's look.
 *
 * The pool is the theme's own theme.json palette ("default") plus every style
 * variation in styles/*.json (slug = file name). Any block theme works; the
 * roles read are base / base-2 / contrast / contrast-2 / light / accent /
 * accent-2 (agent-catalog's slugs), with a luminance-based fallback for themes
 * that name their colours differently.
 *
 * apply() only swaps the preset's COLOURS for palette roles — layout, sizes,
 * opacities and modes stay whatever the preset says. Text colours are picked
 * by contrast, never assumed.
 */
class LAGR_Theme_Palette {

	/** Layouts: how palette roles map onto the picture. */
	public static function layouts() {
		return array(
			'dark'   => 'Тёмный холст в цвете темы',
			'accent' => 'Акцентная заливка + светлые плашки',
		);
	}

	/** @return array<string,array{slug:string,title:string,mode:string,colors:array<string,string>}> */
	public static function all() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();

		$base = self::normalise( self::theme_json_palette() );
		if ( $base ) {
			$cache['default'] = self::entry( 'default', 'Базовая палитра темы', $base );
		}

		foreach ( array_unique( array( get_stylesheet_directory(), get_template_directory() ) ) as $dir ) {
			foreach ( (array) glob( trailingslashit( $dir ) . 'styles/*.json' ) as $file ) {
				$slug = sanitize_key( basename( $file, '.json' ) );
				if ( '' === $slug || isset( $cache[ $slug ] ) ) {
					continue;
				}
				$json   = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore
				$own    = self::normalise( $json['settings']['color']['palette'] ?? array() );
				if ( ! $own ) {
					continue; // typography-only variation
				}
				$colors = self::complete( array_merge( $base, $own ) );
				if ( $colors ) {
					$cache[ $slug ] = self::entry( $slug, (string) ( $json['title'] ?? $slug ), $colors );
				}
			}
		}
		return $cache;
	}

	public static function get( $slug ) {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/** Slug of the palette the site currently shows (an applied variation or the base). */
	public static function active_slug() {
		$live = self::normalise( wp_get_global_settings( array( 'color', 'palette', 'theme' ) ) );
		foreach ( self::all() as $slug => $entry ) {
			if ( isset( $live['accent'], $live['base'] )
				&& strtolower( $live['accent'] ) === strtolower( $entry['colors']['accent'] )
				&& strtolower( $live['base'] ) === strtolower( $entry['colors']['base'] ) ) {
				return $slug;
			}
		}
		return isset( self::all()['default'] ) ? 'default' : (string) array_key_first( self::all() );
	}

	/** Label for utm_content: dark-magma / light-indigo. */
	public static function utm_label( $slug ) {
		$entry = self::get( $slug );
		return $entry ? $entry['mode'] . '-' . $entry['slug'] : '';
	}

	/**
	 * Replace the preset's colours with palette roles.
	 *
	 * @param array  $preset  A LAGR_Pin_Presets preset.
	 * @param array  $palette An entry of all().
	 * @param string $layout  dark | accent.
	 */
	public static function apply( array $preset, array $palette, $layout = 'dark' ) {
		$c     = $palette['colors'];
		$white = '#ffffff';
		$black = '#000000';

		if ( 'accent' === $layout ) {
			$canvas  = $c['accent'];
			$surface = $c['base'];
			$glow    = self::mix( $canvas, $black, 0.5 );
		} else {
			$canvas  = 'dark' === $palette['mode'] ? $c['base'] : self::mix( $c['accent'], $black, 0.78 );
			$surface = self::mix( $canvas, $white, 0.07 );
			$glow    = $c['accent'];
		}

		$ink       = self::best_ink( $canvas, array( $c['base'], $c['contrast'], $white, $black ) );
		$ink_dim   = self::ensure( self::mix( $ink, $canvas, 0.32 ), $canvas, 4.5 );
		$body      = self::best_ink( $surface, array( $c['contrast'], $c['base'], $white, $black ) );
		$body_dim  = self::ensure( self::mix( $body, $surface, 0.3 ), $surface, 4.5 );
		$hilite    = self::ensure( $c['accent'], $surface, 4.5 );
		$border    = self::mix( $surface, $body, 0.14 );
		$badge_ink = self::best_ink( $c['accent'], array( $white, $black, $c['base'], $c['contrast'] ) );

		return array_merge(
			$preset,
			array(
				'background'         => $canvas,
				'tint_hex'           => $canvas,
				'gradient_from_hex'  => $canvas,
				'gradient_to_hex'    => $glow,
				'owner_color'        => $ink_dim,
				'title_color'        => $ink,
				'footer_color'       => $ink_dim,
				'langs_color'        => $ink,
				'langs_track_color'  => self::mix( $canvas, $ink, 0.22 ),
				'stats_plate_color'  => $surface,
				'stats_color'        => $body,
				'stats_label_color'  => $body_dim,
				'stats_icon_color'   => $hilite,
				'plate_color'        => $surface,
				'plate_border_color' => $border,
				'label_color'        => $hilite,
				'text_color'         => $body,
				'badge_bg'           => $c['accent'],
				'badge_color'        => $badge_ink,
				'border_color'       => $border,
			)
		);
	}

	// ------------------------------------------------------------- palette

	private static function theme_json_palette() {
		$settings = WP_Theme_JSON_Resolver::get_theme_data()->get_settings();
		return $settings['color']['palette']['theme'] ?? ( $settings['color']['palette'] ?? array() );
	}

	/** [{slug,color},…] → slug => #hex */
	private static function normalise( $palette ) {
		$out = array();
		foreach ( (array) $palette as $item ) {
			if ( isset( $item['slug'], $item['color'] ) && preg_match( '/^#[0-9a-f]{6}$/i', (string) $item['color'] ) ) {
				$out[ (string) $item['slug'] ] = strtolower( (string) $item['color'] );
			}
		}
		return $out;
	}

	/** Fill missing roles for themes that use other slugs; null when unusable. */
	private static function complete( array $colors ) {
		if ( count( $colors ) < 2 ) {
			return null;
		}
		$values = array_values( $colors );
		if ( ! isset( $colors['base'] ) ) {
			usort( $values, function ( $a, $b ) {
				return self::lum( $b ) <=> self::lum( $a );
			} );
			$colors['base'] = $values[0];
		}
		if ( ! isset( $colors['contrast'] ) ) {
			$colors['contrast'] = self::best_ink( $colors['base'], array_values( $colors ) );
		}
		if ( ! isset( $colors['accent'] ) ) {
			$best = '';
			$sat  = -1;
			foreach ( $colors as $hex ) {
				$s = self::saturation( $hex );
				if ( $s > $sat ) {
					$sat  = $s;
					$best = $hex;
				}
			}
			$colors['accent'] = $best;
		}
		$colors += array(
			'base-2'     => $colors['base'],
			'contrast-2' => self::mix( $colors['contrast'], $colors['base'], 0.35 ),
			'light'      => self::mix( $colors['base'], $colors['contrast'], 0.12 ),
			'accent-2'   => self::mix( $colors['accent'], $colors['base'], 0.85 ),
		);
		return $colors;
	}

	private static function entry( $slug, $title, array $colors ) {
		$colors = self::complete( $colors );
		return array(
			'slug'   => $slug,
			'title'  => $title,
			'mode'   => self::lum( $colors['base'] ) < 0.2 ? 'dark' : 'light',
			'colors' => $colors,
		);
	}

	// ---------------------------------------------------------- colour math

	public static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	public static function hex( array $rgb ) {
		return sprintf( '#%02x%02x%02x', ...array_map( function ( $v ) {
			return max( 0, min( 255, (int) round( $v ) ) );
		}, $rgb ) );
	}

	/** Linear mix: $t = 0 → $a, $t = 1 → $b. */
	public static function mix( $a, $b, $t ) {
		$x = self::rgb( $a );
		$y = self::rgb( $b );
		return self::hex( array( $x[0] + ( $y[0] - $x[0] ) * $t, $x[1] + ( $y[1] - $x[1] ) * $t, $x[2] + ( $y[2] - $x[2] ) * $t ) );
	}

	public static function lum( $hex ) {
		$c = array_map( function ( $v ) {
			$v /= 255;
			return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
		}, self::rgb( $hex ) );
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	public static function contrast( $a, $b ) {
		$l1 = self::lum( $a );
		$l2 = self::lum( $b );
		return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
	}

	/** The candidate that reads best on $bg. */
	public static function best_ink( $bg, array $candidates ) {
		$best  = $candidates[0];
		$ratio = 0;
		foreach ( $candidates as $hex ) {
			$r = self::contrast( $hex, $bg );
			if ( $r > $ratio ) {
				$ratio = $r;
				$best  = $hex;
			}
		}
		return $best;
	}

	/** Push $hex towards white (dark bg) or black (light bg) until it reaches $ratio on $bg. */
	public static function ensure( $hex, $bg, $ratio ) {
		$target = self::lum( $bg ) < 0.4 ? '#ffffff' : '#000000';
		for ( $t = 0; $t <= 1.0001; $t += 0.05 ) {
			$try = self::mix( $hex, $target, $t );
			if ( self::contrast( $try, $bg ) >= $ratio ) {
				return $try;
			}
		}
		return $target;
	}

	private static function saturation( $hex ) {
		$c   = self::rgb( $hex );
		$max = max( $c );
		$min = min( $c );
		return $max ? ( $max - $min ) / $max : 0;
	}
}
