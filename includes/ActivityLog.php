<?php
/**
 * Activity log, stored only in this site's database. Visitor IP addresses are never stored.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class ActivityLog {

	const SCHEMA = 1;

	private static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'embed_drive_log';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				event varchar(20) NOT NULL,
				account varchar(64) NOT NULL DEFAULT '',
				file_id varchar(128) NOT NULL DEFAULT '',
				detail varchar(255) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY event (event)
			) $charset;"
		);
		update_option( 'embed_drive_log_schema', self::SCHEMA, false );
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( 'embed_drive_log_schema' ) !== self::SCHEMA ) {
			self::install();
		}
	}

	/**
	 * @param string $event  preview, download, zip or search.
	 * @param string $detail File name, or the search term.
	 */
	public static function record( string $event, string $account, string $file_id, string $detail ): void {
		if ( ! Settings::get( 'log_enabled' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			[
				'created_at' => current_time( 'mysql', true ),
				'event'      => $event,
				'account'    => $account,
				'file_id'    => $file_id,
				'detail'     => mb_substr( $detail, 0, 255 ),
				// Visitor routes send no REST nonce, so WordPress has reset the current user by now.
				'user_id'    => get_current_user_id() ?: (int) wp_validate_auth_cookie( '', 'logged_in' ),
			]
		);
	}

	public static function purge(): void {
		global $wpdb;
		$days  = max( 1, (int) Settings::get( 'log_days' ) );
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The plugin's own table.
	}

	/**
	 * @return array{rows: array, total: int}
	 */
	public static function page( string $event, int $page, int $per_page ): array {
		global $wpdb;
		$table = self::table();
		$where = '' === $event ? '1=1' : $wpdb->prepare( 'event = %s', $event );

		// The table name is the plugin's own, built from $wpdb->prefix.
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, max( 0, $page - 1 ) * $per_page ),
			ARRAY_A
		);
		// phpcs:enable

		return [ 'rows' => (array) $rows, 'total' => $total ];
	}

	/**
	 * @return array Event totals and the most-downloaded files in the last 30 days.
	 */
	public static function summary(): array {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		// The table name is the plugin's own, built from $wpdb->prefix.
		// phpcs:disable WordPress.DB, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$counts = $wpdb->get_results( $wpdb->prepare( "SELECT event, COUNT(*) AS total FROM $table WHERE created_at >= %s GROUP BY event", $since ), ARRAY_A );
		$top    = $wpdb->get_results( $wpdb->prepare( "SELECT detail, COUNT(*) AS total FROM $table WHERE created_at >= %s AND event = 'download' GROUP BY file_id, detail ORDER BY total DESC LIMIT 10", $since ), ARRAY_A );
		// phpcs:enable

		return [
			'counts' => wp_list_pluck( (array) $counts, 'total', 'event' ),
			'top'    => (array) $top,
		];
	}
}
