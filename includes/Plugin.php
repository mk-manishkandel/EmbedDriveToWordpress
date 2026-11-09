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
		// The WordPress.org copy leaves the updater out, since WordPress.org delivers its updates.
		if ( class_exists( Updater::class ) ) {
			Updater::boot();
		}
	}

	public static function init(): void {
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
		Health::send_summary();
		ActivityLog::purge();
	}
}
