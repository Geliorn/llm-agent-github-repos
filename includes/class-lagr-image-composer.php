<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GD primitives for composed images: canvas, background pipeline (crop,
 * filters, tint, gradient, PNG mask), logo, text measuring and wrapping,
 * rounded plates, file output.
 *
 * Ported from llm-agent-articles (LAA_Image_Composer, 0.3.x) as a copy, not a
 * dependency — each plugin of the network stays installable on its own. The
 * cover-specific caption layout was left out; LAGR_Pin_Composer extends this
 * class with the Pinterest pin layout.
 *
 * GD only, deliberately: GD with FreeType is present on every host this
 * network runs on and does all of it.
 */
class LAGR_Image_Composer {

	/** GD alpha is inverted and 0..127, not 0..255. */
	const GD_OPAQUE      = 0;
	const GD_TRANSPARENT = 127;

	/** Reference glyphs for a stable baseline across lines of different content. */
	const METRIC_SAMPLE = 'ХудЁjgy';

	public static function available() {
		return extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' );
	}

	public static function text_available() {
		return self::available() && function_exists( 'imagettftext' );
	}

	// --------------------------------------------------------------- canvas

	protected static function new_canvas( $width, $height, $background ) {
		$canvas = imagecreatetruecolor( $width, $height );
		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );

		$rgb = LAGR_Pin_Presets::hex_to_rgb( $background, array( 16, 20, 24 ) );
		$bg  = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::GD_OPAQUE );
		imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, $bg );

		imagealphablending( $canvas, true );
		return $canvas;
	}

	/** Scales the source to cover (and crops by the focal point) or to contain. */
	protected static function draw_source( $canvas, $source, array $preset ) {
		$cw = imagesx( $canvas );
		$ch = imagesy( $canvas );
		$sw = imagesx( $source );
		$sh = imagesy( $source );

		if ( $sw < 1 || $sh < 1 ) {
			return;
		}

		if ( 'contain' === $preset['fit'] ) {
			$scale = min( $cw / $sw, $ch / $sh );
			$dw    = max( 1, (int) round( $sw * $scale ) );
			$dh    = max( 1, (int) round( $sh * $scale ) );
			list( $dx, $dy ) = self::place( $preset['focal'], $cw, $ch, $dw, $dh, 0, 0 );
			imagecopyresampled( $canvas, $source, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh );
			return;
		}

		// cover: scale so both sides fill, then take the crop window the focal point asks for.
		$scale = max( $cw / $sw, $ch / $sh );
		$vis_w = min( $sw, (int) ceil( $cw / $scale ) );
		$vis_h = min( $sh, (int) ceil( $ch / $scale ) );
		list( $sx, $sy ) = self::place( $preset['focal'], $sw, $sh, $vis_w, $vis_h, 0, 0 );

		imagecopyresampled( $canvas, $source, 0, 0, $sx, $sy, $cw, $ch, $vis_w, $vis_h );
	}

	/**
	 * Top-left coordinate for a $iw×$ih box inside a $cw×$ch area, per the
	 * nine-point grid plus an offset. Shared by the crop window, the caption,
	 * the plate and the logo — one place to get the arithmetic right.
	 */
	public static function place( $position, $cw, $ch, $iw, $ih, $offset_x = 0, $offset_y = 0 ) {
		$parts = explode( '-', (string) $position );
		$v     = $parts[0] ?? 'middle';
		$h     = $parts[1] ?? 'center';

		switch ( $h ) {
			case 'left':
				$x = $offset_x;
				break;
			case 'right':
				$x = $cw - $iw - $offset_x;
				break;
			default:
				$x = (int) round( ( $cw - $iw ) / 2 );
		}

		switch ( $v ) {
			case 'top':
				$y = $offset_y;
				break;
			case 'bottom':
				$y = $ch - $ih - $offset_y;
				break;
			default:
				$y = (int) round( ( $ch - $ih ) / 2 );
		}

		return array( (int) $x, (int) $y );
	}

	// -------------------------------------------------------------- filters

	protected static function apply_filters( $canvas, array $preset ) {
		if ( $preset['grayscale'] ) {
			imagefilter( $canvas, IMG_FILTER_GRAYSCALE );
		}
		if ( 0 !== (int) $preset['brightness'] ) {
			imagefilter( $canvas, IMG_FILTER_BRIGHTNESS, (int) $preset['brightness'] );
		}
		if ( 0 !== (int) $preset['contrast'] ) {
			// GD's contrast filter is inverted: a negative value increases contrast.
			imagefilter( $canvas, IMG_FILTER_CONTRAST, (int) $preset['contrast'] );
		}
		for ( $i = 0; $i < (int) $preset['blur']; $i++ ) {
			imagefilter( $canvas, IMG_FILTER_GAUSSIAN_BLUR );
		}
		if ( '' !== $preset['colorize_hex'] && (int) $preset['colorize_strength'] > 0 ) {
			$rgb      = LAGR_Pin_Presets::hex_to_rgb( $preset['colorize_hex'] );
			$strength = (int) $preset['colorize_strength'] / 100;
			// COLORIZE shifts each channel by a signed amount; centring on 128
			// turns "this colour at this strength" into the right shift.
			imagefilter(
				$canvas,
				IMG_FILTER_COLORIZE,
				(int) round( ( $rgb[0] - 128 ) * $strength ),
				(int) round( ( $rgb[1] - 128 ) * $strength ),
				(int) round( ( $rgb[2] - 128 ) * $strength )
			);
		}
	}

	protected static function apply_tint( $canvas, array $preset ) {
		$alpha = (int) $preset['tint_alpha'];
		if ( $alpha < 1 || '' === $preset['tint_hex'] ) {
			return;
		}
		$rgb   = LAGR_Pin_Presets::hex_to_rgb( $preset['tint_hex'] );
		$color = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::alpha_to_gd( $alpha ) );
		imagealphablending( $canvas, true );
		imagefilledrectangle( $canvas, 0, 0, imagesx( $canvas ) - 1, imagesy( $canvas ) - 1, $color );
	}

	protected static function apply_gradient( $canvas, array $preset ) {
		if ( ! $preset['gradient_enabled'] ) {
			return;
		}

		$w = imagesx( $canvas );
		$h = imagesy( $canvas );

		$from     = LAGR_Pin_Presets::hex_to_rgb( $preset['gradient_from_hex'] );
		$to       = LAGR_Pin_Presets::hex_to_rgb( $preset['gradient_to_hex'] );
		$from_a   = (int) $preset['gradient_from_alpha'];
		$to_a     = (int) $preset['gradient_to_alpha'];
		$vertical = in_array( $preset['gradient_direction'], array( 'to-bottom', 'to-top' ), true );
		$reverse  = in_array( $preset['gradient_direction'], array( 'to-top', 'to-left' ), true );

		$steps = $vertical ? $h : $w;
		if ( $steps < 2 ) {
			return;
		}

		imagealphablending( $canvas, true );

		for ( $i = 0; $i < $steps; $i++ ) {
			$t = $i / ( $steps - 1 );
			if ( $reverse ) {
				$t = 1 - $t;
			}

			$r     = (int) round( $from[0] + ( $to[0] - $from[0] ) * $t );
			$g     = (int) round( $from[1] + ( $to[1] - $from[1] ) * $t );
			$b     = (int) round( $from[2] + ( $to[2] - $from[2] ) * $t );
			$alpha = $from_a + ( $to_a - $from_a ) * $t;

			if ( $alpha < 0.5 ) {
				continue;
			}

			$color = imagecolorallocatealpha( $canvas, $r, $g, $b, self::alpha_to_gd( $alpha ) );
			if ( $vertical ) {
				imageline( $canvas, 0, $i, $w - 1, $i, $color );
			} else {
				imageline( $canvas, $i, 0, $i, $h - 1, $color );
			}
		}
	}

	protected static function apply_mask( $canvas, array $preset ) {
		$attachment_id = (int) $preset['mask_id'];
		if ( $attachment_id < 1 ) {
			return;
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return;
		}

		$mask = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore
		if ( ! $mask ) {
			return;
		}

		$w = imagesx( $canvas );
		$h = imagesy( $canvas );

		$layer = imagecreatetruecolor( $w, $h );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefilledrectangle( $layer, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha( $layer, 0, 0, 0, self::GD_TRANSPARENT ) );
		imagealphablending( $layer, true );

		$mw = imagesx( $mask );
		$mh = imagesy( $mask );

		switch ( $preset['mask_mode'] ) {
			case 'tile':
				for ( $y = 0; $y < $h; $y += $mh ) {
					for ( $x = 0; $x < $w; $x += $mw ) {
						imagecopy( $layer, $mask, $x, $y, 0, 0, $mw, $mh );
					}
				}
				break;
			case 'center':
				imagecopy( $layer, $mask, (int) round( ( $w - $mw ) / 2 ), (int) round( ( $h - $mh ) / 2 ), 0, 0, $mw, $mh );
				break;
			default:
				imagecopyresampled( $layer, $mask, 0, 0, 0, 0, $w, $h, $mw, $mh );
		}

		imagedestroy( $mask );

		$alpha = (int) $preset['mask_alpha'];
		if ( $alpha < 100 ) {
			// imagecopymerge would drop the mask's own per-pixel alpha, so the
			// opacity has to be folded into the layer pixel by pixel instead.
			self::scale_layer_alpha( $layer, $alpha / 100 );
		}

		imagealphablending( $canvas, true );
		imagecopy( $canvas, $layer, 0, 0, 0, 0, $w, $h );
		imagedestroy( $layer );
	}

	/** Multiplies every pixel's opacity by $factor (0..1), keeping its own alpha shape. */
	protected static function scale_layer_alpha( $layer, $factor ) {
		$w = imagesx( $layer );
		$h = imagesy( $layer );

		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );

		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$rgba = imagecolorat( $layer, $x, $y );
				$a    = ( $rgba >> 24 ) & 0x7F;
				if ( self::GD_TRANSPARENT === $a ) {
					continue;
				}
				$opacity = ( self::GD_TRANSPARENT - $a ) * $factor;
				$new_a   = (int) round( self::GD_TRANSPARENT - $opacity );
				$color   = imagecolorallocatealpha( $layer, ( $rgba >> 16 ) & 0xFF, ( $rgba >> 8 ) & 0xFF, $rgba & 0xFF, $new_a );
				imagesetpixel( $layer, $x, $y, $color );
			}
		}

		imagealphablending( $layer, true );
	}

	protected static function apply_logo( $canvas, array $preset ) {
		$attachment_id = (int) $preset['logo_id'];
		if ( $attachment_id < 1 ) {
			return;
		}

		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! is_readable( $path ) ) {
			return;
		}

		$logo = @imagecreatefromstring( (string) file_get_contents( $path ) ); // phpcs:ignore
		if ( ! $logo ) {
			return;
		}

		$cw = imagesx( $canvas );
		$ch = imagesy( $canvas );
		$lw = imagesx( $logo );
		$lh = imagesy( $logo );

		$target_w = max( 8, (int) round( $cw * (int) $preset['logo_width'] / 100 ) );
		$target_h = max( 8, (int) round( $lh * ( $target_w / max( 1, $lw ) ) ) );

		$layer = imagecreatetruecolor( $target_w, $target_h );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefilledrectangle( $layer, 0, 0, $target_w - 1, $target_h - 1, imagecolorallocatealpha( $layer, 0, 0, 0, self::GD_TRANSPARENT ) );
		imagecopyresampled( $layer, $logo, 0, 0, 0, 0, $target_w, $target_h, $lw, $lh );
		imagedestroy( $logo );

		$alpha = (int) $preset['logo_alpha'];
		if ( $alpha < 100 ) {
			self::scale_layer_alpha( $layer, $alpha / 100 );
		}

		list( $x, $y ) = self::place( $preset['logo_position'], $cw, $ch, $target_w, $target_h, (int) $preset['logo_offset_x'], (int) $preset['logo_offset_y'] );

		imagealphablending( $canvas, true );
		imagecopy( $canvas, $layer, $x, $y, 0, 0, $target_w, $target_h );
		imagedestroy( $layer );
	}

	// ----------------------------------------------------------------- text

	public static function text_width( $font, $size, $text, $spacing = 0 ) {
		if ( '' === $text ) {
			return 0;
		}
		$bbox = imagettfbbox( $size, 0, $font, $text );
		if ( ! $bbox ) {
			return 0;
		}
		$width = abs( $bbox[2] - $bbox[0] );
		if ( 0 !== (int) $spacing ) {
			$width += (int) $spacing * max( 0, mb_strlen( $text ) - 1 );
		}
		return (int) $width;
	}

	/** Greedy word wrap; a single word wider than the box is split by characters. */
	public static function wrap( $font, $size, $text, $max_width, $spacing = 0 ) {
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$lines = array();
		$line  = '';

		foreach ( (array) $words as $word ) {
			$candidate = '' === $line ? $word : $line . ' ' . $word;
			if ( self::text_width( $font, $size, $candidate, $spacing ) <= $max_width ) {
				$line = $candidate;
				continue;
			}

			if ( '' !== $line ) {
				$lines[] = $line;
				$line    = '';
			}

			if ( self::text_width( $font, $size, $word, $spacing ) <= $max_width ) {
				$line = $word;
				continue;
			}

			$chunk = '';
			foreach ( (array) preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
				if ( self::text_width( $font, $size, $chunk . $char, $spacing ) > $max_width && '' !== $chunk ) {
					$lines[] = $chunk;
					$chunk   = '';
				}
				$chunk .= $char;
			}
			$line = $chunk;
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return empty( $lines ) ? array( '' ) : $lines;
	}

	/**
	 * Font lookup, most specific first: the preset, then anything uploaded to
	 * this plugin's font folder (or the articles plugin's — same uploads, no
	 * code dependency), then the usual system faces. Theme fonts are woff2 and
	 * GD can't read those.
	 */
	public static function resolve_font( $preferred = '', $bold = true ) {
		$candidates = array();
		if ( '' !== (string) $preferred ) {
			$candidates[] = $preferred;
		}
		foreach ( self::uploaded_fonts() as $font ) {
			$candidates[] = $font['path'];
		}

		// Headings want a bold face, running text a regular one.
		$bold_faces    = array(
			'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf',
			'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
			'/System/Library/Fonts/Supplemental/Arial Bold.ttf',
		);
		$regular_faces = array(
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
			'/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
			'/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
			'/System/Library/Fonts/Supplemental/Arial.ttf',
		);
		$candidates = array_merge( $candidates, $bold ? $bold_faces : $regular_faces, $bold ? $regular_faces : $bold_faces );

		foreach ( $candidates as $path ) {
			$path = self::normalise_font_path( $path );
			if ( '' !== $path && is_readable( $path ) ) {
				return $path;
			}
		}

		foreach ( (array) glob( '/usr/share/fonts/truetype/*/*.ttf' ) as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}

	/** Accepts an absolute path or a name inside the plugin's own font folder. */
	private static function normalise_font_path( $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			return '';
		}
		if ( 0 === strpos( $path, '/' ) ) {
			return $path;
		}
		foreach ( self::uploaded_fonts() as $font ) {
			if ( $font['name'] === basename( $path ) ) {
				return $font['path'];
			}
		}
		return trailingslashit( self::fonts_dir() ) . basename( $path );
	}

	public static function fonts_dir() {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'lagr-fonts';
	}

	/** Fonts from our folder first, then the ones uploaded for llm-agent-articles. */
	public static function uploaded_fonts() {
		$upload = wp_upload_dir();
		$out    = array();
		$seen   = array();
		foreach ( array( self::fonts_dir(), trailingslashit( $upload['basedir'] ) . 'laa-fonts' ) as $dir ) {
			foreach ( (array) glob( trailingslashit( $dir ) . '*.{ttf,otf,TTF,OTF}', GLOB_BRACE ) as $path ) {
				if ( ! $path || isset( $seen[ basename( $path ) ] ) ) {
					continue;
				}
				$seen[ basename( $path ) ] = true;
				$out[] = array(
					'name' => basename( $path ),
					'path' => $path,
				);
			}
		}
		return $out;
	}

	// ------------------------------------------------------- frame & output

	protected static function apply_border( $canvas, array $preset ) {
		$width = (int) $preset['border_width'];
		if ( $width < 1 ) {
			return;
		}

		$inset = (int) $preset['border_inset'];
		$rgb   = LAGR_Pin_Presets::hex_to_rgb( $preset['border_color'], array( 255, 255, 255 ) );
		$color = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::GD_OPAQUE );

		$x1 = $inset;
		$y1 = $inset;
		$x2 = imagesx( $canvas ) - 1 - $inset;
		$y2 = imagesy( $canvas ) - 1 - $inset;

		if ( $x2 <= $x1 || $y2 <= $y1 ) {
			return;
		}

		imagealphablending( $canvas, true );
		// imagesetthickness grows the line both ways around the path, which puts
		// half of a thick border outside the canvas — draw nested rectangles instead.
		for ( $i = 0; $i < $width; $i++ ) {
			imagerectangle( $canvas, $x1 + $i, $y1 + $i, $x2 - $i, $y2 - $i, $color );
		}
	}

	protected static function apply_corner_radius( $canvas, array $preset ) {
		$radius = (int) $preset['corner_radius'];
		if ( $radius < 1 ) {
			return;
		}

		$w = imagesx( $canvas );
		$h = imagesy( $canvas );

		// JPEG has no alpha, so there the corners get the background colour
		// rather than transparency.
		if ( 'jpg' === $preset['format'] ) {
			$rgb  = LAGR_Pin_Presets::hex_to_rgb( $preset['background'], array( 255, 255, 255 ) );
			$fill = imagecolorallocatealpha( $canvas, $rgb[0], $rgb[1], $rgb[2], self::GD_OPAQUE );
		} else {
			$fill = imagecolorallocatealpha( $canvas, 0, 0, 0, self::GD_TRANSPARENT );
		}

		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );

		// Each corner's r×r box lies entirely in one quadrant around its arc
		// centre, so "farther than r from the centre" is exactly "outside the
		// rounded shape" — no quadrant test needed.
		$corners = array(
			array( 'box_x' => 0,            'box_y' => 0,            'cx' => $radius,         'cy' => $radius ),
			array( 'box_x' => $w - $radius, 'box_y' => 0,            'cx' => $w - 1 - $radius, 'cy' => $radius ),
			array( 'box_x' => 0,            'box_y' => $h - $radius, 'cx' => $radius,         'cy' => $h - 1 - $radius ),
			array( 'box_x' => $w - $radius, 'box_y' => $h - $radius, 'cx' => $w - 1 - $radius, 'cy' => $h - 1 - $radius ),
		);

		$r2 = $radius * $radius;

		foreach ( $corners as $corner ) {
			for ( $y = 0; $y < $radius; $y++ ) {
				for ( $x = 0; $x < $radius; $x++ ) {
					$px = $corner['box_x'] + $x;
					$py = $corner['box_y'] + $y;
					if ( $px < 0 || $py < 0 || $px >= $w || $py >= $h ) {
						continue;
					}
					$dx = $px - $corner['cx'];
					$dy = $py - $corner['cy'];
					if ( ( $dx * $dx + $dy * $dy ) > $r2 ) {
						imagesetpixel( $canvas, $px, $py, $fill );
					}
				}
			}
		}

		imagealphablending( $canvas, true );
	}

	/** Filled rectangle with rounded corners, drawn on its own layer so the
	 *  overlapping arcs don't blend with each other and darken the corners. */
	protected static function rounded_rect( $canvas, $x1, $y1, $x2, $y2, $radius, array $rgb, $alpha_pct ) {
		$x1 = max( 0, (int) $x1 );
		$y1 = max( 0, (int) $y1 );
		$x2 = min( imagesx( $canvas ) - 1, (int) $x2 );
		$y2 = min( imagesy( $canvas ) - 1, (int) $y2 );

		$w = $x2 - $x1 + 1;
		$h = $y2 - $y1 + 1;
		if ( $w < 2 || $h < 2 ) {
			return;
		}

		$radius = max( 0, min( (int) $radius, (int) floor( min( $w, $h ) / 2 ) ) );

		$layer = imagecreatetruecolor( $w, $h );
		imagealphablending( $layer, false );
		imagesavealpha( $layer, true );
		imagefilledrectangle( $layer, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha( $layer, 0, 0, 0, self::GD_TRANSPARENT ) );

		$gd_alpha = self::alpha_to_gd( $alpha_pct );
		$color    = imagecolorallocatealpha( $layer, $rgb[0], $rgb[1], $rgb[2], $gd_alpha );

		if ( $radius < 1 ) {
			imagefilledrectangle( $layer, 0, 0, $w - 1, $h - 1, $color );
		} else {
			imagefilledrectangle( $layer, $radius, 0, $w - 1 - $radius, $h - 1, $color );
			imagefilledrectangle( $layer, 0, $radius, $w - 1, $h - 1 - $radius, $color );
			$d = $radius * 2;
			imagefilledellipse( $layer, $radius, $radius, $d, $d, $color );
			imagefilledellipse( $layer, $w - 1 - $radius, $radius, $d, $d, $color );
			imagefilledellipse( $layer, $radius, $h - 1 - $radius, $d, $d, $color );
			imagefilledellipse( $layer, $w - 1 - $radius, $h - 1 - $radius, $d, $d, $color );
		}

		imagealphablending( $canvas, true );
		imagecopy( $canvas, $layer, $x1, $y1, 0, 0, $w, $h );
		imagedestroy( $layer );
	}

	protected static function save( $canvas, array $preset, $out_path ) {
		$dir = dirname( $out_path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::fail( 'Не удалось создать каталог для картинки: ' . $dir );
		}

		switch ( $preset['format'] ) {
			case 'png':
				imagesavealpha( $canvas, true );
				$ok   = imagepng( $canvas, $out_path, (int) round( 9 - ( (int) $preset['quality'] / 100 ) * 9 ) );
				$mime = 'image/png';
				break;
			case 'webp':
				if ( ! function_exists( 'imagewebp' ) ) {
					return self::fail( 'GD собран без поддержки WebP — выберите другой формат' );
				}
				imagesavealpha( $canvas, true );
				$ok   = imagewebp( $canvas, $out_path, (int) $preset['quality'] );
				$mime = 'image/webp';
				break;
			default:
				// JPEG can't carry alpha: flatten onto the background first,
				// otherwise rounded corners come out black.
				$flat = imagecreatetruecolor( imagesx( $canvas ), imagesy( $canvas ) );
				$rgb  = LAGR_Pin_Presets::hex_to_rgb( $preset['background'], array( 255, 255, 255 ) );
				imagefilledrectangle( $flat, 0, 0, imagesx( $flat ) - 1, imagesy( $flat ) - 1, imagecolorallocate( $flat, $rgb[0], $rgb[1], $rgb[2] ) );
				imagealphablending( $flat, true );
				imagecopy( $flat, $canvas, 0, 0, 0, 0, imagesx( $canvas ), imagesy( $canvas ) );
				$ok = imagejpeg( $flat, $out_path, (int) $preset['quality'] );
				imagedestroy( $flat );
				$mime = 'image/jpeg';
		}

		if ( ! $ok ) {
			return self::fail( 'Не удалось записать файл картинки: ' . $out_path );
		}

		return array(
			'ok'    => true,
			'path'  => $out_path,
			'mime'  => $mime,
			'error' => '',
		);
	}

	public static function extension_for( $format ) {
		return 'jpg' === $format ? 'jpg' : $format;
	}

	/** Percent opacity (0..100) -> GD alpha (127..0). */
	protected static function alpha_to_gd( $percent ) {
		$percent = max( 0, min( 100, (float) $percent ) );
		return (int) round( self::GD_TRANSPARENT * ( 1 - $percent / 100 ) );
	}

	protected static function fail( $error ) {
		return array(
			'ok'    => false,
			'path'  => '',
			'mime'  => '',
			'error' => $error,
		);
	}
}
