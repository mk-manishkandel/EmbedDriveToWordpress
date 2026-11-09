<?php
/**
 * Plugin Name:       FileFolio – File Browser, Photo Gallery & Embeds for Google Drive
 * Plugin URI:        https://github.com/mk-manishkandel/EmbedDriveToWordpress
 * Description:       Show Google Drive folders, photo galleries, file previews, search and downloads on your site with a block or shortcode. Read-only, no upsells.
 * Version:           0.5.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Manish Kandel
 * Author URI:        https://github.com/mk-manishkandel
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       filefolio
 * Domain Path:       /languages
 * Update URI:        https://github.com/mk-manishkandel/EmbedDriveToWordpress
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

// Two copies can be installed at once while a site switches between the GitHub and WordPress.org
// copies, which live in different folders. Only the first one loaded runs.
if ( defined( 'EMBED_DRIVE_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'FileFolio is installed twice. Deactivate and delete the older copy; your settings are kept.', 'filefolio' ) . '</p></div>';
		}
	);

	return;
}

define( 'EMBED_DRIVE_VERSION', '0.5.2' );
define( 'EMBED_DRIVE_FILE', __FILE__ );
define( 'EMBED_DRIVE_DIR', plugin_dir_path( __FILE__ ) );
define( 'EMBED_DRIVE_URL', plugin_dir_url( __FILE__ ) );
define( 'EMBED_DRIVE_REPO', 'mk-manishkandel/EmbedDriveToWordpress' );

spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$file = EMBED_DRIVE_DIR . 'includes/' . substr( $class, strlen( __NAMESPACE__ ) + 1 ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );

Plugin::boot();
