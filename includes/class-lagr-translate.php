<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Yandex Cloud Translate client (v2 REST API).
 *
 * Translates the rendered README HTML to the target language with format=HTML,
 * so Yandex translates the text nodes and leaves the markup (links, code, tables)
 * intact. Reuses the Yandex API Key / Folder ID already configured for the
 * Foundation Models provider — the same cloud credentials work for Translate as
 * long as the service account has the translate.user role.
 *
 * A single request is capped at 10 000 Unicode characters, so long READMEs are
 * split on block-element boundaries and translated chunk by chunk.
 *
 * @see https://yandex.cloud/docs/translate/api-ref/Translation/translate
 */
class LAGR_Translate {

	const ENDPOINT  = 'https://translate.api.cloud.yandex.net/translate/v2/translate';
	const MAX_CHARS = 9000; // headroom under the 10 000-char per-request limit.

	/** Credentials present? (Reuses the Yandex Foundation Models key/folder.) */
	public static function available() {
		return '' !== (string) LAGR_Settings::get( 'yandex_api_key' )
			&& '' !== (string) LAGR_Settings::get( 'yandex_folder_id' );
	}

	/** Translation turned on in settings AND credentials present. */
	public static function enabled() {
		return (bool) LAGR_Settings::get( 'translate_readme' ) && self::available();
	}

	/**
	 * Translate an HTML fragment. Returns '' on any failure (so the caller keeps
	 * the original) — a partial translation is never stored.
	 *
	 * @param string $html   HTML to translate.
	 * @param string $target Target language code (default from settings / 'ru').
	 * @param string $source Source language code, '' = autodetect.
	 */
	public static function translate_html( $html, $target = '', $source = '' ) {
		$html = (string) $html;
		if ( '' === trim( $html ) || ! self::available() ) {
			return '';
		}
		$target = '' !== $target ? $target : (string) LAGR_Settings::get( 'translate_target_lang', 'ru' );
		$source = '' !== $source ? $source : (string) LAGR_Settings::get( 'translate_source_lang', '' );
		$target = $target ? $target : 'ru';

		$out = '';
		foreach ( self::chunk_html( $html, self::MAX_CHARS ) as $chunk ) {
			$translated = self::request_translate( $chunk, $target, $source );
			if ( null === $translated ) {
				return ''; // give up wholesale — don't mix translated and original.
			}
			$out .= $translated;
		}
		return $out;
	}

	/** One Translate API call for a single chunk; null on error. */
	private static function request_translate( $text, $target, $source ) {
		$body = array(
			'folderId'           => (string) LAGR_Settings::get( 'yandex_folder_id' ),
			'texts'              => array( $text ),
			'targetLanguageCode' => $target,
			'format'             => 'HTML',
		);
		if ( '' !== (string) $source ) {
			$body['sourceLanguageCode'] = $source;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => 'Api-Key ' . LAGR_Settings::get( 'yandex_api_key' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			LAGR_Log::add( 'Перевод README: ошибка запроса к Yandex Translate — ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $data['translations'][0]['text'] ) ) {
			$msg = is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : wp_remote_retrieve_body( $response );
			LAGR_Log::add( 'Перевод README: Yandex Translate вернул ' . $code . ' — ' . mb_substr( (string) $msg, 0, 300 ) );
			return null;
		}

		return (string) $data['translations'][0]['text'];
	}

	/**
	 * Split HTML into chunks under $max chars, breaking only after a block
	 * element's closing tag so each chunk is self-contained, valid markup.
	 */
	private static function chunk_html( $html, $max ) {
		if ( mb_strlen( $html ) <= $max ) {
			return array( $html );
		}

		$parts = preg_split(
			'/(?<=<\/(?:p|div|pre|ul|ol|li|table|thead|tbody|tr|h[1-6]|blockquote|section|article|details|dl|figure)>)/i',
			$html
		);

		$chunks = array();
		$buf    = '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '' !== $buf && mb_strlen( $buf ) + mb_strlen( $part ) > $max ) {
				$chunks[] = $buf;
				$buf      = '';
			}
			$buf .= $part;
			// A single block larger than the limit has to go on its own.
			if ( mb_strlen( $buf ) > $max ) {
				$chunks[] = $buf;
				$buf      = '';
			}
		}
		if ( '' !== $buf ) {
			$chunks[] = $buf;
		}
		return $chunks;
	}
}
