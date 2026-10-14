<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three ways to trigger the same tick (section 12 of the plan):
 *  1) WP-Cron custom 10-minute schedule (default, zero setup).
 *  2) HTTP endpoint guarded by a secret (?github_repos_cron=...), for an
 *     external cron-ping service or a real crontab with curl/wget.
 *  3) WP-CLI (`wp github-repos tick`), see class-lagr-cli.php.
 * All three end up calling run_tick() — the due-rule logic lives in one place.
 */
class LAGR_Scheduler {

	const CRON_HOOK              = 'lagr_tick_event';
	const TICK_RUNNING_TRANSIENT = 'lagr_tick_running';
	const ENDPOINT_THROTTLE      = 'lagr_endpoint_throttle';
	const HTTP_QUERY_PARAM       = 'github_repos_cron';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_tick' ) );
		add_action( 'init', array( __CLASS__, 'maybe_handle_http_trigger' ), 5 );
		// Self-heal: ensure the recurring tick is scheduled whenever the plugin
		// is active. activate() alone is not enough — an in-place update (files
		// replaced without re-activation), a missed activation hook or a cleared
		// cron entry would otherwise leave the tick unscheduled and silent.
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/** Schedule the recurring tick if it isn't already (cheap autoloaded check). */
	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'lagr_10_minutes', self::CRON_HOOK );
		}
	}

	public static function add_schedule( $schedules ) {
		$schedules['lagr_10_minutes'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'Каждые 10 минут (GitHub Repo Collector)',
		);
		return $schedules;
	}

	public static function activate() {
		self::ensure_scheduled();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Handles `?github_repos_cron=SECRET`. Deliberately checks the raw
	 * superglobal instead of a registered query var — simpler, and avoids
	 * any interaction with rewrite rules.
	 */
	public static function maybe_handle_http_trigger() {
		if ( ! isset( $_GET[ self::HTTP_QUERY_PARAM ] ) ) {
			return;
		}

		$provided = (string) $_GET[ self::HTTP_QUERY_PARAM ]; // phpcs:ignore WordPress.Security.NonceVerification
		$secret   = (string) LAGR_Settings::get( 'cron_secret' );

		if ( '' === $secret || ! hash_equals( $secret, $provided ) ) {
			status_header( 403 );
			exit( 'forbidden' );
		}

		// Endpoint-level throttle (plan 12.1.1) — even a valid secret can't fire faster than once a minute.
		if ( get_transient( self::ENDPOINT_THROTTLE ) ) {
			exit( 'throttled' );
		}
		set_transient( self::ENDPOINT_THROTTLE, 1, MINUTE_IN_SECONDS );

		self::run_tick();
		exit( 'OK' );
	}

	public static function get_trigger_url() {
		$secret = LAGR_Settings::get( 'cron_secret' );
		return add_query_arg( self::HTTP_QUERY_PARAM, $secret, home_url( '/' ) );
	}

	public static function get_cron_php_path() {
		return LAGR_PLUGIN_DIR . 'cron.php';
	}

	/** The three ready-to-copy commands shown in the admin settings screen (plan 12.7). */
	public static function get_trigger_commands() {
		$url  = self::get_trigger_url();
		$path = self::get_cron_php_path();
		return array(
			'curl' => 'curl "' . $url . '"',
			'wget' => 'wget -O /dev/null "' . $url . '"',
			'php'  => 'php ' . $path,
		);
	}

	/**
	 * Shared tick logic (section 12.3 of the plan) — runs every rule whose
	 * own interval has elapsed. Guarded by a ~9 minute self-lock so two
	 * overlapping trigger sources can't run it twice at once.
	 */
	public static function run_tick() {
		if ( get_transient( self::TICK_RUNNING_TRANSIENT ) ) {
			return;
		}
		set_transient( self::TICK_RUNNING_TRANSIENT, 1, 9 * MINUTE_IN_SECONDS );

		try {
			$rule_ids = get_posts(
				array(
					'post_type'      => 'github_repo_rule',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'meta_key'       => '_lagr_enabled',
					'meta_value'     => '1',
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			$client = new LAGR_GitHub_Client();

			foreach ( $rule_ids as $rule_id ) {
				if ( $client->budget_exhausted() ) {
					LAGR_Log::add( 'Тик остановлен досрочно — бюджет GitHub API исчерпан на этот час' );
					break;
				}

				$interval = max( 10, (int) get_post_meta( $rule_id, '_lagr_interval_minutes', true ) );
				$last_run = (int) get_post_meta( $rule_id, '_lagr_last_run_at', true );

				if ( $last_run && ( time() - $last_run ) < $interval * MINUTE_IN_SECONDS ) {
					continue; // not due yet
				}

				LAGR_Collector::run_rule( $rule_id );
			}

			// Weekly vocabulary regulator — guarded inside, no second cron.
			LAGR_Tag_Regulator::maybe_run();
		} finally {
			delete_transient( self::TICK_RUNNING_TRANSIENT );
		}
	}
}
