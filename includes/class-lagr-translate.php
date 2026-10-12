<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * README translation façade. Two interchangeable engines (settings:
 * translate_engine):
 *   - "llm"    — a separate chat/completions request with a dedicated prompt
 *                via the configured provider (LAGR_LLM_Client::translate_html);
 *                the default, and the fallback while Yandex Translate is down.
 *   - "yandex" — Yandex Cloud Translate v2 (format=HTML), reusing the Yandex
 *                API Key / Folder ID of the Foundation Models provider (service
 *                account needs the translate.user role).
 *
 * Either way the markup (links, code, tables) is preserved and long READMEs are
 * split on block-element boundaries, translated chunk by chunk. Any failure
 * returns '' so the caller keeps the original — a partial translation is never
 * stored.
 *
 * @see https://yandex.cloud/docs/translate/api-ref/Translation/translate
 */
class LAGR_Translate {

	const ENDPOINT  = 'https://translate.api.cloud.yandex.net/translate/v2/translate';
	const MAX_CHARS = 9000; // headroom under Yandex's 10 000-char per-request limit.

	/** Which engine to use: 'llm' (default) or 'yandex'. */
	public static function engine() {
		$e = (string) LAGR_Settings::get( 'translate_engine', 'llm' );
		return in_array( $e, array( 'llm', 'yandex' ), true ) ? $e : 'llm';
	}

	/** Credentials for the selected engine present? */
	public static function available() {
		return 'yandex' === self::engine() ? self::yandex_available() : self::llm_available();
	}

	/** Translation turned on in settings AND the selected engine is usable. */
	public static function enabled() {
		return (bool) LAGR_Settings::get( 'translate_readme' ) && self::available();
	}

	/** Yandex Cloud Translate creds (shared with the Yandex LLM provider). */
	private static function yandex_available() {
		return '' !== (string) LAGR_Settings::get( 'yandex_api_key' )
			&& '' !== (string) LAGR_Settings::get( 'yandex_folder_id' );
	}

	/** The configured LLM provider has its credentials set. */
	private static function llm_available() {
		switch ( (string) LAGR_Settings::get( 'llm_provider', 'deepseek' ) ) {
			case 'yandex':
				return '' !== (string) LAGR_Settings::get( 'yandex_api_key' )
					&& '' !== (string) LAGR_Settings::get( 'yandex_folder_id' );
			case 'timeweb':
				return '' !== (string) LAGR_Settings::get( 'timeweb_token' )
					&& '' !== (string) LAGR_Settings::get( 'timeweb_agent_id' );
			case 'openrouter':
				return '' !== (string) LAGR_Settings::get( 'openrouter_api_key' );
			case 'deepseek':
			default:
				return '' !== (string) LAGR_Settings::get( 'deepseek_api_key' );
		}
	}

	/**
	 * Translate an HTML fragment via the selected engine. Returns '' on any
	 * failure (caller keeps the original) — a partial translation is never stored.
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

		if ( 'llm' === self::engine() ) {
			$client = new LAGR_LLM_Client();
			return $client->translate_html( $html, $target, $source );
		}
		return self::translate_html_yandex( $html, $target, $source );
	}

	/** Yandex Cloud Translate path — chunked, format=HTML. */
	private static function translate_html_yandex( $html, $target, $source ) {
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
	 * Public so the LLM translation path can reuse it.
	 */
	public static function chunk_html( $html, $max ) {
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
