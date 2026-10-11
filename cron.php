<?php
/**
 * Standalone cron entry point — bypasses WP-CLI entirely, loads wp-load.php
 * directly. Third fallback trigger option from the plan (section 12.1),
 * for hosts where the site can't curl itself or where WP-CLI's own PHP
 * binary resolution is unreliable.
 *
 * Usage: php /path/to/wp-content/plugins/{plugin}/cron.php
 */

require_once __DIR__ . '/../../../wp-load.php';

if ( 'cli' !== php_sapi_name() ) {
	// Hit directly over HTTP instead of via the home-URL query param — still require the secret.
	$provided = isset( $_GET['github_repos_cron'] ) ? (string) $_GET['github_repos_cron'] : '';
	$secret   = (string) LAGR_Settings::get( 'cron_secret' );

	if ( '' === $secret || ! hash_equals( $secret, $provided ) ) {
		status_header( 403 );
		exit( 'forbidden' );
	}
	header( 'Content-Type: text/plain' );
}

LAGR_Scheduler::run_tick();
echo "OK\n";
