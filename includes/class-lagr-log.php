<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rolling human-readable log (last ~200 entries) stored in one option —
 * so the admin can see what the scheduler/collector did without SSH.
 * Section 12.5 of the plan.
 */
class LAGR_Log {

	const OPTION_KEY = 'lagr_log';
	const MAX_ENTRIES = 200;

	public static function init() {
		// Nothing to hook — read/write on demand.
	}

	public static function add( $message ) {
		$entries   = get_option( self::OPTION_KEY, array() );
		$entries[] = array(
			'time'    => time(),
			'message' => $message,
		);
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION_KEY, $entries, false );

		// Also append to the plain file log, useful for the direct-PHP-CLI trigger mode.
		$upload_dir = wp_upload_dir();
		$log_file   = trailingslashit( $upload_dir['basedir'] ) . 'github-repos-cron.log';
		$line       = '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . $message . "\n";
		@file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
	}

	public static function get_recent( $limit = 200 ) {
		$entries = get_option( self::OPTION_KEY, array() );
		return array_slice( array_reverse( $entries ), 0, $limit );
	}

	public static function clear() {
		delete_option( self::OPTION_KEY );
	}
}
