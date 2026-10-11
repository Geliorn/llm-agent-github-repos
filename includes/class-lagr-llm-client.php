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

	const SYSTEM_PROMPT = <<<PROMPT
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

	public function generate_descriptions( array $repo ) {
		$provider = LAGR_Settings::get( 'llm_provider', 'deepseek' );
		$config   = LAGR_Settings::llm_provider_config( $provider );

		$user_prompt = $this->build_user_prompt( $repo );

		$payload = array(
			'model'       => $config['model'],
			'max_tokens'  => 900,
			'temperature' => 0.5,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => self::SYSTEM_PROMPT,
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
		return sprintf(
			"Репозиторий: %s\nЗвёзд: %d\nФорков: %d\nЯзык: %s\nЛицензия: %s\nHomepage: %s\nАвторские topics: %s\nОписание (из GitHub): %s\n\nREADME (первые 3000 символов):\n%s",
			$repo['full_name'] ?? '',
			(int) ( $repo['stars'] ?? 0 ),
			(int) ( $repo['forks'] ?? 0 ),
			$repo['language'] ?: '—',
			$repo['license'] ?: '—',
			$repo['homepage'] ?: '—',
			! empty( $repo['topics'] ) ? implode( ', ', (array) $repo['topics'] ) : '—',
			$repo['description'] ?: '—',
			mb_substr( (string) ( $repo['readme'] ?? '' ), 0, 3000 )
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
			return $attempts_left > 0 ? $this->call( $config, $payload, $attempts_left - 1 ) : null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['choices'][0]['message']['content'] ) ) {
			return $attempts_left > 0 ? $this->call( $config, $payload, $attempts_left - 1 ) : null;
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
