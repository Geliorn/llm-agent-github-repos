<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One Pinterest pin per repo: data → texts → layout → attachment.
 *
 * Contract for the separate Pinterest import plugin (this plugin never talks
 * to Pinterest itself):
 *   post meta `_gh_pin_image_id`     — attachment id of the current pin (int)
 *   post meta `_gh_pin_texts`        — the three block texts actually drawn
 *   post meta `_gh_pin_generated_at` — unix time of the last build
 *   post meta `_gh_pin_description`  — ready pin description (short description + repo)
 *   action `lagr_pin_image_created( $post_id, $attachment_id, $info )`
 * All meta is registered with show_in_rest, so the importer can also read it
 * over the REST API.
 *
 * When a block doesn't fit, the text is rewritten shorter by the model inside
 * the measured budget (twice at most) — it is never cut. With exactly three
 * fixed blocks there is nothing to drop, so a pin that still doesn't fit is
 * reported, not drawn.
 */
class LAGR_Pin_Service {

	const META_IMAGE     = '_gh_pin_image_id';
	const META_TEXTS     = '_gh_pin_texts';
	const META_GENERATED = '_gh_pin_generated_at';
	const META_PRESET    = '_gh_pin_preset';
	const META_DESC      = '_gh_pin_description';
	const META_ERROR     = '_gh_pin_error';
	const ATTACHMENT_MARK = '_lagr_pin';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
	}

	public static function register_meta() {
		$fields = array(
			self::META_IMAGE     => 'integer',
			self::META_GENERATED => 'integer',
			self::META_PRESET    => 'string',
			self::META_DESC      => 'string',
		);
		foreach ( $fields as $key => $type ) {
			register_post_meta(
				'github_repo',
				$key,
				array(
					'type'          => $type,
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
		register_post_meta(
			'github_repo',
			self::META_TEXTS,
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	public static function texts( $post_id ) {
		$t = get_post_meta( (int) $post_id, self::META_TEXTS, true );
		return is_array( $t ) ? array_values( array_pad( array_slice( array_map( 'strval', $t ), 0, 3 ), 3, '' ) ) : array( '', '', '' );
	}

	public static function save_texts( $post_id, array $texts ) {
		$texts = array_values( array_pad( array_slice( array_map( 'sanitize_text_field', $texts ), 0, 3 ), 3, '' ) );
		update_post_meta( (int) $post_id, self::META_TEXTS, $texts );
	}

	/**
	 * Data for the composer from the repo post. Languages: stored breakdown,
	 * else one GitHub request (cached into meta), else the main language 100%.
	 */
	public static function data_for( $post_id, $fetch_languages = true ) {
		$post_id = (int) $post_id;
		$full    = (string) get_post_meta( $post_id, '_gh_full_name', true );
		if ( '' === $full ) {
			$full = get_the_title( $post_id );
		}
		$parts = explode( '/', $full, 2 );

		$languages = json_decode( (string) get_post_meta( $post_id, '_gh_languages_json', true ), true );
		if ( ( ! is_array( $languages ) || ! $languages ) && $fetch_languages && false !== strpos( $full, '/' ) ) {
			$client    = new LAGR_GitHub_Client();
			$languages = $client->budget_exhausted() ? array() : $client->get_languages( $full );
			if ( $languages ) {
				update_post_meta( $post_id, '_gh_languages_json', wp_json_encode( $languages ) );
			}
		}
		$rows = array();
		if ( is_array( $languages ) && $languages ) {
			$total = array_sum( array_map( 'floatval', $languages ) );
			foreach ( $languages as $name => $bytes ) {
				if ( $total > 0 ) {
					$rows[] = array( (string) $name, (float) $bytes / $total * 100 );
				}
			}
		} elseif ( '' !== (string) get_post_meta( $post_id, '_gh_language', true ) ) {
			$rows[] = array( (string) get_post_meta( $post_id, '_gh_language', true ), 100 );
		}

		return array(
			'owner'     => (string) get_post_meta( $post_id, '_gh_owner_login', true ) ?: ( $parts[0] ?? '' ),
			'name'      => $parts[1] ?? $full,
			'full_name' => $full,
			'url'       => 'github.com/' . $full,
			'stars'     => (int) get_post_meta( $post_id, '_gh_stars', true ),
			'forks'     => (int) get_post_meta( $post_id, '_gh_forks', true ),
			'languages' => $rows,
			'blocks'    => self::texts( $post_id ),
		);
	}

	/** Asks the model for the three texts from what is already stored on the post. */
	public static function generate_texts( $post_id ) {
		if ( ! LAGR_Settings::get( 'llm_enabled' ) ) {
			return new WP_Error( 'lagr_pin_llm', 'Генерация через LLM выключена в настройках — впишите тексты блоков вручную.' );
		}
		$post_id = (int) $post_id;
		$llm     = new LAGR_LLM_Client();
		$texts   = $llm->pin_texts(
			array(
				'full_name'   => get_post_meta( $post_id, '_gh_full_name', true ) ?: get_the_title( $post_id ),
				'stars'       => (int) get_post_meta( $post_id, '_gh_stars', true ),
				'forks'       => (int) get_post_meta( $post_id, '_gh_forks', true ),
				'language'    => get_post_meta( $post_id, '_gh_language', true ),
				'license'     => get_post_meta( $post_id, '_gh_license', true ),
				'homepage'    => get_post_meta( $post_id, '_gh_homepage', true ),
				'topics'      => wp_get_object_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) ),
				'description' => get_post_meta( $post_id, 'short_description', true ) ?: get_post_meta( $post_id, '_gh_source_description', true ),
				'readme'      => wp_strip_all_tags( (string) ( get_post_meta( $post_id, '_gh_generated_html', true ) ?: get_post_field( 'post_content', $post_id ) ) ),
			)
		);
		if ( ! $texts ) {
			return new WP_Error( 'lagr_pin_llm', 'Модель не вернула три текста для блоков.' );
		}
		self::save_texts( $post_id, $texts );
		return $texts;
	}

	/**
	 * @param array $opts preset (id), retext (bool — ask the model again).
	 * @return array{ok:bool,attachment_id:int,error:string,notes:array}
	 */
	public static function create_for_post( $post_id, array $opts = array() ) {
		$post_id = (int) $post_id;
		$notes   = array();
		if ( 'github_repo' !== get_post_type( $post_id ) ) {
			return self::result( false, 0, 'Это не запись github_repo', $notes );
		}

		$texts = self::texts( $post_id );
		if ( ! empty( $opts['retext'] ) || count( array_filter( $texts ) ) < 3 ) {
			$generated = self::generate_texts( $post_id );
			if ( is_wp_error( $generated ) ) {
				return self::fail( $post_id, $generated->get_error_message(), $notes );
			}
			$notes[] = 'Тексты блоков написаны моделью';
		}

		$preset_id = (string) ( $opts['preset'] ?? '' );
		$preset    = LAGR_Pin_Presets::get( $preset_id );
		$data      = self::data_for( $post_id );

		$rendered = self::render_fitting( $preset, $data, $notes );
		if ( ! $rendered['ok'] ) {
			return self::fail( $post_id, $rendered['error'], $notes );
		}
		if ( $rendered['blocks'] !== $data['blocks'] ) {
			self::save_texts( $post_id, $rendered['blocks'] );
		}

		$bytes = (string) file_get_contents( $rendered['path'] ); // phpcs:ignore
		@unlink( $rendered['path'] ); // phpcs:ignore

		$title         = 'Pinterest: ' . $data['full_name'];
		$alt           = $data['full_name'] . ' — ' . implode( ' ', $rendered['blocks'] );
		$attachment_id = self::sideload( $bytes, $post_id, $title, $alt, LAGR_Image_Composer::extension_for( $preset['format'] ), $rendered['mime'] );
		if ( ! $attachment_id ) {
			return self::fail( $post_id, 'Не удалось сохранить картинку в медиабиблиотеку', $notes );
		}

		// The previous pin of this repo goes — one current pin per repo.
		$old = (int) get_post_meta( $post_id, self::META_IMAGE, true );
		if ( $old && $old !== $attachment_id && get_post_meta( $old, self::ATTACHMENT_MARK, true ) ) {
			wp_delete_attachment( $old, true );
		}

		$description = trim( (string) get_post_meta( $post_id, 'short_description', true ) );
		$description = trim( $description . ' — ' . $data['full_name'] . ' на ' . wp_parse_url( home_url(), PHP_URL_HOST ), ' —' );

		update_post_meta( $post_id, self::META_IMAGE, $attachment_id );
		update_post_meta( $post_id, self::META_GENERATED, time() );
		update_post_meta( $post_id, self::META_PRESET, $preset_id ?: (string) LAGR_Settings::get( 'pin_default_preset', 'dark' ) );
		update_post_meta( $post_id, self::META_DESC, $description );
		delete_post_meta( $post_id, self::META_ERROR );

		$info = array(
			'url'         => wp_get_attachment_url( $attachment_id ),
			'link'        => get_permalink( $post_id ),
			'title'       => $data['full_name'],
			'description' => $description,
			'texts'       => $rendered['blocks'],
		);
		do_action( 'lagr_pin_image_created', $post_id, $attachment_id, $info );

		LAGR_Log::add( sprintf( 'Pinterest-картинка для %s собрана (#%d)%s', $data['full_name'], $attachment_id, $notes ? ' — ' . implode( '; ', $notes ) : '' ) );
		return self::result( true, $attachment_id, '', $notes );
	}

	/** Render; on overflow have the model rewrite the offending blocks inside the measured budget. */
	public static function render_fitting( array $preset, array $data, array &$notes ) {
		$tmp = self::temp_path( LAGR_Image_Composer::extension_for( $preset['format'] ) );

		for ( $pass = 0; $pass <= 2; $pass++ ) {
			$result = LAGR_Pin_Composer::compose( $preset, $data, $tmp );
			if ( ! $result['ok'] ) {
				@unlink( $tmp ); // phpcs:ignore
				return array(
					'ok'    => false,
					'error' => $result['error'],
				);
			}
			if ( empty( $result['overflow'] ) ) {
				return array(
					'ok'     => true,
					'path'   => $result['path'],
					'mime'   => $result['mime'],
					'blocks' => $data['blocks'],
				);
			}
			if ( 2 === $pass || ! LAGR_Settings::get( 'llm_enabled' ) ) {
				break;
			}
			$llm       = new LAGR_LLM_Client();
			$rewritten = 0;
			foreach ( $result['overflow'] as $item ) {
				$i     = (int) $item['index'];
				$short = $llm->shorten_text( $data['blocks'][ $i ], (int) $item['fits_chars'] );
				if ( '' !== $short && $short !== $data['blocks'][ $i ] ) {
					$data['blocks'][ $i ] = $short;
					++$rewritten;
				}
			}
			if ( ! $rewritten ) {
				break;
			}
			$notes[] = sprintf( 'Переписано блоков, чтобы влезли: %d', $rewritten );
		}

		@unlink( $tmp ); // phpcs:ignore
		$which = array();
		foreach ( $result['overflow'] as $item ) {
			$which[] = sprintf( '№%d (влезает ~%d символов)', $item['index'] + 1, $item['fits_chars'] );
		}
		return array(
			'ok'    => false,
			'error' => 'Текст не помещается в блок даже после сокращения: ' . implode( ', ', $which ) . '. Сократите вручную или уменьшите минимальный кегль в пресете.',
		);
	}

	public static function temp_path( $extension ) {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'lagr-tmp';
		wp_mkdir_p( $dir );
		return trailingslashit( $dir ) . 'lagr-' . wp_generate_password( 10, false, false ) . '.' . $extension;
	}

	private static function sideload( $bytes, $post_id, $title, $alt, $extension, $mime ) {
		if ( '' === $bytes ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$slug     = sanitize_title( str_replace( '/', '-', (string) get_post_meta( $post_id, '_gh_full_name', true ) ) ) ?: 'repo';
		$uploaded = wp_upload_bits( 'pin-' . $slug . '-' . wp_generate_password( 6, false, false ) . '.' . $extension, null, $bytes );
		if ( ! empty( $uploaded['error'] ) ) {
			LAGR_Log::add( 'Pinterest-картинка: ' . $uploaded['error'] );
			return 0;
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime ?: 'image/jpeg',
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$uploaded['file'],
			$post_id
		);
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return 0;
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', mb_substr( wp_strip_all_tags( $alt ), 0, 480 ) );
		update_post_meta( $attachment_id, self::ATTACHMENT_MARK, 1 );
		return (int) $attachment_id;
	}

	private static function fail( $post_id, $error, array $notes ) {
		update_post_meta( $post_id, self::META_ERROR, $error );
		LAGR_Log::add( sprintf( 'Pinterest-картинка для #%d не собрана: %s', $post_id, $error ) );
		return self::result( false, 0, $error, $notes );
	}

	private static function result( $ok, $attachment_id, $error, array $notes ) {
		return array(
			'ok'            => (bool) $ok,
			'attachment_id' => (int) $attachment_id,
			'error'         => $error,
			'notes'         => $notes,
		);
	}
}
