<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All plugin-wide settings live in one option array. Rule-specific settings
 * live as post meta on github_repo_rule (see LAGR_Meta_Fields).
 */
class LAGR_Settings {

	const OPTION_KEY = 'lagr_settings';

	public static function defaults() {
		return array(
			'github_token'          => '',
			'gh_budget_threshold'   => 4500,
			'optional_contributors' => 0,
			'optional_releases'     => 0,
			'optional_languages'    => 0,
			'optional_top_files'    => 0,
			// Whether to assign the repo_language / repo_license taxonomy at all —
			// off by default: the network's main content (Agent Skills, MCP servers)
			// isn't meaningfully organised by programming language or license the
			// way a general code catalog would be. `_gh_language`/`_gh_license`
			// meta is still always saved (free — comes with the base Repos API
			// response); this only gates the taxonomy term + its display in patterns.
			'collect_language'      => 0,
			'collect_license'       => 0,
			'llm_enabled'           => 0,
			'llm_provider'          => 'deepseek',
			'yandex_api_key'        => '',
			'yandex_folder_id'      => '',
			'yandex_model'          => 'deepseek-v32/latest',
			// README translation. Off by default. Engine: 'llm' (a separate
			// request to the configured chat provider — default, works while
			// Yandex Translate is down) or 'yandex' (Yandex Cloud Translate,
			// reuses the Yandex API Key / Folder ID above).
			'translate_readme'      => 0,
			'translate_engine'      => 'llm',
			'translate_target_lang' => 'ru',
			'translate_source_lang' => '', // '' = autodetect.
			'deepseek_api_key'      => '',
			'deepseek_model'        => 'deepseek-chat',
			// Qwen (Alibaba DashScope, OpenAI-compatible mode). Base URL is
			// configurable so you can switch the international / mainland host.
			'qwen_api_key'          => '',
			'qwen_model'            => 'qwen-plus',
			'qwen_base_url'         => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
			'timeweb_agent_id'      => '',
			'timeweb_token'         => '',
			'timeweb_proxy_source'  => '',
			'openrouter_api_key'    => '',
			'openrouter_model'      => 'deepseek/deepseek-chat',
			'score_forks_weight'    => 3,
			'cron_secret'           => '',
			// Rule topic(s) always go to repo_topic. Everything else — repo's own GitHub
			// topics plus LLM tags — goes here: 'post_tag' (shared with the blog), 'repo_topic'
			// (old all-in-one behaviour), 'none' (discard), or any other registered taxonomy slug.
			'extra_topics_taxonomy' => 'post_tag',

			// SEO plugin whose own meta field gets the generated description — 'none' skips it.
			'seo_plugin'                    => 'yoast',

			// Feature on/off switches. `ratings`/`related_posts`/`filters` used to
			// mean "is the third-party plugin (WP-PostRatings / Contextual Related
			// Posts / Filter Everything) active" — decided 2026-09-04 (ratings,
			// related posts) and 2026-09-29 (filters) to bring all three in-house
			// as the plugin's own code (class-lagr-ratings.php/class-lagr-related.php/
			// class-lagr-filters.php), so they're now plain feature flags, kept as
			// settings only in case someone wants the markup hidden entirely.
			// External Links/Relevanssi stay genuinely third-party.
			'integration_ratings'        => 1, // own rating widget (lagr/rating)
			'integration_related_posts'  => 1, // own related-posts block (lagr/related-posts)
			'integration_filters'        => 1, // own archive sidebar filters (lagr/repo-filters)
			'integration_external_links' => 1, // WP External Links
			'integration_relevanssi'     => 1, // Relevanssi
		);
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_generate_secret' ) );
	}

	public static function get_all() {
		$stored = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $stored, self::defaults() );
	}

	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return $all[ $key ] ?? $default;
	}

	public static function update( array $values ) {
		$all = self::get_all();
		foreach ( $values as $k => $v ) {
			$all[ $k ] = $v;
		}
		update_option( self::OPTION_KEY, $all );
	}

	public static function maybe_generate_secret() {
		$settings = self::get_all();
		if ( empty( $settings['cron_secret'] ) ) {
			self::regenerate_secret();
		}
	}

	/** Convenience wrapper for template/pattern code: LAGR_Settings::integration_enabled( 'ratings' ). */
	public static function integration_enabled( $name ) {
		return (bool) self::get( 'integration_' . $name, false );
	}

	public static function regenerate_secret() {
		$secret = bin2hex( random_bytes( 16 ) ); // 32 hex chars.
		self::update( array( 'cron_secret' => $secret ) );
		return $secret;
	}

	/**
	 * OpenAI-compatible provider presets — endpoint + auth builder.
	 * All four LLM providers speak the same chat/completions shape;
	 * only base URL, headers and model resolution differ.
	 */
	public static function llm_provider_config( $provider ) {
		$s = self::get_all();

		switch ( $provider ) {
			case 'yandex':
				return array(
					'endpoint' => 'https://ai.api.cloud.yandex.net/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Api-Key ' . $s['yandex_api_key'],
						'x-folder-id'   => $s['yandex_folder_id'],
					),
					'model'    => 'gpt://' . $s['yandex_folder_id'] . '/' . ltrim( $s['yandex_model'], '/' ),
				);
			case 'timeweb':
				return array(
					'endpoint' => 'https://agent.timeweb.cloud/api/v1/cloud-ai/agents/' . rawurlencode( $s['timeweb_agent_id'] ) . '/v1/chat/completions',
					'headers'  => array(
						'Authorization'  => 'Bearer ' . $s['timeweb_token'],
						'x-proxy-source' => $s['timeweb_proxy_source'],
					),
					'model'    => 'gpt-4', // ignored by Timeweb — agent's own model is used.
				);
			case 'openrouter':
				return array(
					'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['openrouter_api_key'],
					),
					'model'    => $s['openrouter_model'],
				);
			case 'qwen':
				return array(
					'endpoint' => rtrim( $s['qwen_base_url'], '/' ) . '/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['qwen_api_key'],
					),
					'model'    => $s['qwen_model'],
				);
			case 'deepseek':
			default:
				return array(
					'endpoint' => 'https://api.deepseek.com/v1/chat/completions',
					'headers'  => array(
						'Authorization' => 'Bearer ' . $s['deepseek_api_key'],
					),
					'model'    => $s['deepseek_model'],
				);
		}
	}
}
