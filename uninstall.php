<?php
/**
 * Removes everything the plugin stored. Google access is not revoked here because
 * the plugin's classes aren't loaded; people can revoke it in their Google account.
 *
 * @package EmbedDrive
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array_keys( (array) get_option( 'embed_drive_accounts', [] ) ) as $embed_drive_account ) {
	delete_option( 'embed_drive_token_' . $embed_drive_account );
}
delete_option( 'embed_drive_accounts' );
delete_option( 'embed_drive_settings' );
delete_option( 'embed_drive_cache_gen' );
delete_option( 'embed_drive_link_reset' );
delete_option( 'embed_drive_log_schema' );
delete_option( 'embed_drive_health' );
delete_site_transient( 'embed_drive_latest_release' );
wp_clear_scheduled_hook( 'embed_drive_daily' );

// phpcs:disable WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}embed_drive_log" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_embed\_drive\_%' OR option_name LIKE '\_transient\_timeout\_embed\_drive\_%'" );
// phpcs:enable
