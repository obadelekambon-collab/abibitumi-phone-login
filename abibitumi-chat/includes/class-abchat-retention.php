<?php
/**
 * Scheduled cleanup for expired chat data and local attachments.
 *
 * @package AbibitumiChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ABChat_Retention {

	const HOOK = 'abchat_daily_cleanup';

	/**
	 * Register and schedule cleanup.
	 *
	 * @return void
	 */
	public function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		self::schedule();
	}

	/**
	 * Ensure the daily cleanup event exists.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::HOOK );
		}
	}

	/**
	 * Run one bounded cleanup batch when retention is enabled.
	 *
	 * @return array Cleanup result.
	 */
	public static function run() {
		$empty = array( 'conversations' => 0, 'messages' => 0, 'visitors' => 0, 'attachments' => 0 );
		if ( ! ABChat_Settings::get( 'retention_enabled' ) ) {
			return $empty;
		}

		$days   = max( 1, (int) ABChat_Settings::get( 'retention_days', 365 ) );
		$batch  = max( 10, min( 1000, (int) ABChat_Settings::get( 'retention_batch', 100 ) ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );
		$result = ABChat_DB::cleanup_expired_data( $cutoff, $batch );
		$deleted_files = 0;

		foreach ( (array) $result['attachments'] as $url ) {
			if ( self::delete_local_attachment( $url ) ) {
				$deleted_files++;
			}
		}
		$result['attachments'] = $deleted_files;
		$result['ran_at']      = current_time( 'mysql' );
		update_option( 'abchat_last_cleanup', $result, false );
		do_action( 'abchat_retention_cleanup', $result, $cutoff );
		return $result;
	}

	/**
	 * Delete an attachment only when it resolves inside this site's uploads.
	 *
	 * @param string $url Attachment URL.
	 * @return bool
	 */
	public static function delete_local_attachment( $url ) {
		$uploads = wp_upload_dir();
		$baseurl = isset( $uploads['baseurl'] ) ? untrailingslashit( $uploads['baseurl'] ) : '';
		$basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
		if ( '' === $baseurl || '' === $basedir || 0 !== strpos( $url, $baseurl . '/' ) ) {
			return false;
		}

		$relative = rawurldecode( substr( $url, strlen( $baseurl ) + 1 ) );
		$file     = realpath( $basedir . '/' . ltrim( $relative, '/' ) );
		$base     = realpath( $basedir );
		if ( false === $file || false === $base ) {
			return false;
		}
		$file = wp_normalize_path( $file );
		$base = trailingslashit( wp_normalize_path( $base ) );
		if ( 0 !== strpos( $file, $base ) || ! is_file( $file ) ) {
			return false;
		}
		wp_delete_file( $file );
		return ! file_exists( $file );
	}
}
