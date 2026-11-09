<?php
/**
 * Plugin Name:       Embed Drive
 * Plugin URI:        https://github.com/mk-manishkandel/EmbedDriveToWordpress
 * Description:       Show Google Drive folders and files on your site: file browser, search, single-file embeds and downloads. Open source, no licence keys, no upsells.
 * Version:           0.4.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Manish Kandel
 * Author URI:        https://github.com/mk-manishkandel
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       embed-drive
 * Domain Path:       /languages
 * Update URI:        https://github.com/mk-manishkandel/EmbedDriveToWordpress
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

define( 'EMBED_DRIVE_VERSION', '0.4.1' );
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
