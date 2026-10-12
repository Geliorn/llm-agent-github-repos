<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One HTTP client for all four OpenAI-compatible chat/completions providers
 * (Yandex, DeepSeek, Timeweb TWC AI, OpenRouter) — only the preset from
 * LAGR_Settings::llm_provider_config() differs. Section 8 of the plan.
 */
class LAGR_LLM_Client {

	/** Built-in default — used when the admin "Промт генерации" field is left empty. */
	const DEFAULT_SYSTEM_PROMPT = <<<PROMPT
Ты пишешь описание GitHub-репозитория для карточки каталога на сайте.
Аудитория — разработчики, которые выбирают инструмент для своей задачи.

Верни СТРОГО JSON-объект (без markdown-обёртки, без пояснений вокруг) с полями:
{
  "short_description": "1-2 предложения, до 200 символов, что это и для кого",
  "full_description": "2-4 связных абзаца без заголовков и без маркированных списков — что за инструмент, чем отличается от аналогов, когда пригодится",
  "tags": ["1-3 свободных тега по смыслу/назначению, на русском или английском — как уместнее"],
  "install_command": "одна строка — основная команда установки/запуска из README (например npx -y ..., pip install ..., npm i ..., docker run ...); если в README её явно нет — пустая строка"
}

Ограничения:
— Без эмодзи, без штампов («уникальный», «революционный», «мощный», «легко», «просто»)
— Не придумывай цифры, факты и примеры, которых нет в предоставленных данных
— full_description пиши как будто объясняешь коллеге, без воды
— Если авторские topics репозитория уже покрывают суть — tags может быть короче или пустым
— install_command бери дословно из README (без markdown-разметки и без комментариев); если явной команды нет — оставь пустую строку, не придумывай
— Язык: русский
PROMPT;

	/** Separate prompt for README translation (keeps the HTML, translates only prose). %s = target language. */
	const TRANSLATE_SYSTEM_PROMPT = <<<PROMPT
Ты — переводчик технической документации (README) с сохранением HTML-разметки.
Переведи присланный HTML-фрагмент на язык: %s.

Правила:
— Сохраняй ВСЕ HTML-теги, атрибуты и структуру без изменений.
— НЕ переводи и не изменяй содержимое внутри <code> и <pre>, а также команды, пути, имена пакетов и функций, URL, флаги, названия продуктов и идентификаторы кода.
— Переводи только естественный текст: абзацы, заголовки, подписи, пункты списков, ячейки таблиц, осмысленные alt/title.
— Не добавляй и не убирай содержимое, не оборачивай ответ в markdown-блок.
— Верни ТОЛЬКО переведённый HTML, без каких-либо пояснений до или после.
PROMPT;

	/**
	 * Translate a README HTML fragment via the configured chat/completions
	 * provider — a separate request with a dedicated translation prompt, used
	 * when the translation engine is "llm" (e.g. while Yandex Translate is down).
	 * HTML is split on block boundaries so each request stays within output
	 * limits. Returns '' on any failure (caller keeps the original).
	 *
	 * @param string $html        HTML to translate.
	 * @param string $target_lang Target language code (default 'ru').
	 * @param string $source_lang Unused for the LLM path (autodetected by the model).
	 */
	public function translate_html( $html, $target_lang = 'ru', $source_lang = '' ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return '';
		}

		$provider = LAGR_Settings::get( 'llm_provider', 'deepseek' );
		$config   = LAGR_Settings::llm_provider_config( $provider );
		$system   = sprintf( self::TRANSLATE_SYSTEM_PROMPT, $this->lang_name( $target_lang ) );

		$out = '';
		foreach ( LAGR_Translate::chunk_html( $html, 3500 ) as $chunk ) {
			$payload = array(
				'model'       => $config['model'],
				'max_tokens'  => 4096,
				'temperature' => 0.2,
				'messages'    => array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
					array(
						'role'    => 'user',
						'content' => $chunk,
					),
				),
			);

			// More retries than the description path: a long README is many
			// chunks, and one transient network timeout would otherwise sink the
			// whole translation.
			$content = $this->call( $config, $payload, 3 );
			if ( ! $content ) {
				LAGR_Log::add( 'Перевод README (LLM): пустой/ошибочный ответ от провайдера ' . $provider );
				return '';
			}
			$out .= $this->strip_code_fence( $content );
		}

		return $out;
	}

	/** Target-language code → human name for the prompt. */
	private function lang_name( $code ) {
		$map  = array(
			'ru' => 'русский',
			'en' => 'английский',
			'de' => 'немецкий',
			'fr' => 'французский',
			'es' => 'испанский',
			'zh' => 'китайский',
			'uk' => 'украинский',
		);
		$code = strtolower( trim( (string) $code ) );
		return $map[ $code ] ?? ( $code ? $code : 'русский' );
	}

	/** Models sometimes wrap output in ```html fences despite instructions — strip them. */
	private function strip_code_fence( $s ) {
		$s = trim( $s );
		if ( 0 === strpos( $s, '```' ) ) {
			$s = preg_replace( '/^```[a-zA-Z]*\n?/', '', $s );
			$s = preg_replace( '/\n?```$/', '', $s );
			$s = trim( $s );
		}
		return $s;
	}

	public function generate_descriptions( array $repo ) {
		$provider = LAGR_Settings::get( 'llm_provider', 'deepseek' );
		$config   = LAGR_Settings::llm_provider_config( $provider );

		$user_prompt = $this->build_user_prompt( $repo );

		// All three are editable in the settings (fall back to the built-ins).
		$system_prompt = trim( (string) LAGR_Settings::get( 'llm_system_prompt', '' ) );
		if ( '' === $system_prompt ) {
			$system_prompt = self::DEFAULT_SYSTEM_PROMPT;
		}
		$max_tokens = max( 100, (int) LAGR_Settings::get( 'llm_max_tokens', 900 ) );

		$payload = array(
			'model'       => $config['model'],
			'max_tokens'  => $max_tokens,
			'temperature' => 0.5,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
		);

		$content = $this->call( $config, $payload, 1 );
		if ( ! $content ) {
			return null;
		}

		$parsed = $this->extract_json( $content );
		if ( ! $parsed || empty( $parsed['short_description'] ) || empty( $parsed['full_description'] ) ) {
			return null;
		}

		return array(
			'short_description' => sanitize_text_field( $parsed['short_description'] ),
			'full_description'  => wp_kses_post( wpautop( $parsed['full_description'] ) ),
			'tags'               => array_map( 'sanitize_text_field', $parsed['tags'] ?? array() ),
			'install_command'   => sanitize_text_field( $parsed['install_command'] ?? '' ),
		);
	}

	private function build_user_prompt( array $repo ) {
		$readme_chars = max( 0, (int) LAGR_Settings::get( 'llm_readme_chars', 3000 ) );
		return sprintf(
			"Репозиторий: %s\nЗвёзд: %d\nФорков: %d\nЯзык: %s\nЛицензия: %s\nHomepage: %s\nАвторские topics: %s\nОписание (из GitHub): %s\n\nREADME (первые %d символов):\n%s",
			$repo['full_name'] ?? '',
			(int) ( $repo['stars'] ?? 0 ),
			(int) ( $repo['forks'] ?? 0 ),
			$repo['language'] ?: '—',
			$repo['license'] ?: '—',
			$repo['homepage'] ?: '—',
			! empty( $repo['topics'] ) ? implode( ', ', (array) $repo['topics'] ) : '—',
			$repo['description'] ?: '—',
			$readme_chars,
			mb_substr( (string) ( $repo['readme'] ?? '' ), 0, $readme_chars )
		);
	}

	private function call( array $config, array $payload, $attempts_left ) {
		$response = wp_remote_post(
			$config['endpoint'],
			array(
				'headers' => array_merge(
					array( 'Content-Type' => 'application/json' ),
					$config['headers']
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( $attempts_left > 0 ) {
				sleep( 2 ); // brief backoff — rides out transient DNS/connect timeouts.
				return $this->call( $config, $payload, $attempts_left - 1 );
			}
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['choices'][0]['message']['content'] ) ) {
			if ( $attempts_left > 0 ) {
				sleep( 2 );
				return $this->call( $config, $payload, $attempts_left - 1 );
			}
			return null;
		}

		return trim( $body['choices'][0]['message']['content'] );
	}

	/** Models sometimes wrap JSON in ```json fences despite instructions — strip before decoding. */
	private function extract_json( $content ) {
		$content = trim( $content );
		if ( 0 === strpos( $content, '```' ) ) {
			$content = preg_replace( '/^```[a-zA-Z]*\n?/', '', $content );
			$content = preg_replace( '/```$/', '', $content );
			$content = trim( $content );
		}
		$decoded = json_decode( $content, true );
		return is_array( $decoded ) ? $decoded : null;
	}
}
