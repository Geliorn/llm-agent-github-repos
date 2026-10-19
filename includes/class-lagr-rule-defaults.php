<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Seeds example rules once on activation (section 3 of the plan) — all
 * topics found across both reference bots, only the 3 requested ones enabled.
 * Pure data, fully editable/deletable afterwards — never re-applied by code.
 */
class LAGR_Rule_Defaults {

	const SEEDED_OPTION = 'lagr_rules_seeded';

	public static function enabled_topics() {
		return array(
			'ai-agent'     => 50,
			'agent-skills' => 10,
			'mcp'          => 30,
		);
	}

	public static function disabled_topics() {
		return array(
			// AI/agent ecosystem — from git_collector.py build_presets().
			'llm'                   => 50,
			'autonomous-agent'      => 50,
			'generative-ai'         => 50,
			'rag'                   => 50,
			'agent-memory'          => 30,
			'skills'                => 30,
			'coding-agent'          => 30,
			'ai-assistant'          => 30,
			'code-generation'       => 30,
			'local-ai'              => 30,
			'ollama'                => 30,
			'langchain'             => 50,
			'fine-tuning'           => 50,
			'ai-workflow'           => 50,
			'multimodal'            => 100,
			'machine-learning'      => 100,
			'ai'                    => 1000,
			'automation'            => 200,
			'codex'                 => 100,
			'claude-code'           => 30,
			'claude'                => 30,
			'anthropic'             => 30,
			// Wider topics — from digest_topics in git-digest-bot.
			'self-hosted'           => 50,
			'wordpress'             => 50,
			'cms'                   => 50,
			'headless-cms'          => 30,
			'static-site-generator' => 30,
			'website-builder'       => 30,
			'woocommerce'           => 30,
			'ecommerce'             => 50,
			'ssl'                   => 30,
			'firewall'              => 50,
			'malware-scanner'       => 20,
			'waf'                   => 20,
			'caching'               => 50,
			'cdn'                   => 50,
			'seo'                   => 50,
			'dns'                   => 100,
		);
	}

	public static function seed_once() {
		if ( get_option( self::SEEDED_OPTION ) ) {
			return;
		}

		// All seeded rules start DISABLED — a fresh install shouldn't collect
		// content until the owner reviews the rules and enables them.
		self::create_rule( 'AI-агенты', 'ai-agent', 50, false );
		self::create_rule( 'Agent Skills', 'agent-skills', 10, false );
		self::create_rule( 'MCP', 'mcp', 30, false );

		foreach ( self::disabled_topics() as $topic => $stars ) {
			self::create_rule( self::label_for_topic( $topic ), $topic, $stars, false );
		}

		update_option( self::SEEDED_OPTION, time(), false );
	}

	private static function label_for_topic( $topic ) {
		return ucwords( str_replace( '-', ' ', $topic ) );
	}

	private static function create_rule( $title, $topic, $stars, $enabled ) {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'github_repo_rule',
				'post_title'  => $title,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return;
		}

		$meta = array(
			'_lagr_topics'              => $topic,
			'_lagr_stars_operator'      => '>',
			'_lagr_stars_value'         => $stars,
			'_lagr_freshness_days'      => 365,
			'_lagr_exclude_forks'       => 1,
			'_lagr_exclude_archived'    => 1,
			'_lagr_require_description' => 1,
			'_lagr_min_size_kb'         => 10,
			'_lagr_sort_by'             => 'updated',
			'_lagr_limit'               => 100,
			'_lagr_raw_query'           => '',
			'_lagr_target_post_type'    => 'github_repo',
			'_lagr_publish_mode'        => 'draft',
			'_lagr_enabled'             => $enabled ? 1 : 0,
			'_lagr_interval_minutes'    => 1440, // 24h — sensible default, editable per rule.
			'_lagr_last_run_at'         => 0,
			'_lagr_running_since'       => 0,
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
