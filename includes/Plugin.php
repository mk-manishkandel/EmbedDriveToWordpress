<?php
/**
 * Bootstrap and lifecycle.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	const CRON_DAILY = 'embed_drive_daily';

	public static function boot(): void {
		add_action( 'init', [ self::class, 'init' ] );
		add_action( 'rest_api_init', [ Rest::class, 'register' ] );
		add_action( self::CRON_DAILY, [ self::class, 'daily' ] );

		if ( is_admin() ) {
			Admin::boot();
		}
		OAuth::boot();
		Updater::boot();
	}

	public static function init(): void {
		load_plugin_textdomain( 'embed-drive', false, dirname( plugin_basename( EMBED_DRIVE_FILE ) ) . '/languages' );
		ActivityLog::maybe_upgrade();
		Modules::register();

		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	public static function activate(): void {
		ActivityLog::install();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_DAILY );
	}

	/**
	 * Daily housekeeping: notice lost Google access before a visitor does, and trim the log.
	 */
	public static function daily(): void {
		Accounts::check_all();
		ActivityLog::purge();
	}
}
