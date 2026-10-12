<?php
/**
 * Plugin Name: GitHub Repo Collector
 * Description: Универсальный сборщик подборок GitHub-репозиториев по настраиваемым правилам (топики, звёзды, свежесть), с публикацией карточек на сайте. Самодостаточен — сам регистрирует тип записи и поля, не зависит от ACF/CPT UI.
 * Version: 0.6.0
 * Author: llm-agent.ru
 * Text Domain: lagr
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LAGR_VERSION', '0.6.0' );
define( 'LAGR_PLUGIN_FILE', __FILE__ );
define( 'LAGR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LAGR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-cpt.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-meta-fields.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-settings.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-log.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-github-client.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-llm-client.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-translate.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-query-builder.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-seo.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-collector.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-scheduler.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-admin.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-rule-defaults.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-ratings.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-related.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-blocks.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-patterns.php';
require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-filters.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once LAGR_PLUGIN_DIR . 'includes/class-lagr-cli.php';
}

/**
 * Boot the plugin — every class hooks itself in its own init() method.
 */
function lagr_boot() {
	LAGR_CPT::init();
	LAGR_Meta_Fields::init();
	LAGR_Settings::init();
	LAGR_Log::init();
	LAGR_Scheduler::init();
	LAGR_Admin::init();
	LAGR_Ratings::init();
	LAGR_Blocks::init();
	LAGR_Patterns::init();
	LAGR_Filters::init();
}
add_action( 'plugins_loaded', 'lagr_boot' );

/**
 * Activation: register post types/taxonomies (so rewrite rules flush correctly),
 * seed example rules once, schedule the WP-Cron fallback.
 */
function lagr_activate() {
	LAGR_CPT::register_post_types();
	LAGR_CPT::register_taxonomies();
	LAGR_Rule_Defaults::seed_once();
	LAGR_Scheduler::activate();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'lagr_activate' );

function lagr_deactivate() {
	LAGR_Scheduler::deactivate();
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'lagr_deactivate' );
