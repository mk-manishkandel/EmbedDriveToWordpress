<?php
/**
 * Plugin updates from GitHub Releases, using WordPress's own Update URI mechanism.
 * The only data sent is an ordinary request to public GitHub pages.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class Updater {

	const ASSET = 'embed-drive.zip';
	const CACHE = 'embed_drive_latest_release';

	public static function boot(): void {
		add_filter( 'update_plugins_github.com', [ self::class, 'check' ], 10, 3 );
		add_filter( 'plugins_api', [ self::class, 'details' ], 10, 3 );
		// Runs before WordPress's own check on this screen, which is also hooked here.
		add_action( 'load-update-core.php', [ self::class, 'check_again' ], 1 );
	}

	/**
	 * Make Dashboard > Updates > Check again ask GitHub now. Without this, WordPress reuses
	 * a check made in the last minute and this plugin reuses its saved answer.
	 */
	public static function check_again(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Core's link has no nonce; this only triggers a fresh check.
		if ( empty( $_GET['force-check'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		delete_site_transient( self::CACHE );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * @param array|false $update Existing update data.
	 * @param array       $plugin_data Header data of the plugin being checked.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( EMBED_DRIVE_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::latest();
		if ( ! $release ) {
			return $update;
		}

		return [
			'icons'        => [
				'svg' => EMBED_DRIVE_URL . 'assets/icon/icon.svg',
				'2x'  => EMBED_DRIVE_URL . 'assets/icon/icon-256x256.png',
				'1x'  => EMBED_DRIVE_URL . 'assets/icon/icon-128x128.png',
			],
			'id'           => 'https://github.com/' . EMBED_DRIVE_REPO,
			'slug'         => 'embed-drive',
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires_php' => '8.0',
		];
	}

	/**
	 * Fill the "View details" popup on the Plugins screen.
	 *
	 * @param false|object|array $result Existing result.
	 * @param string             $action API action.
	 * @param object             $args   API arguments.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || 'embed-drive' !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::latest();
		if ( ! $release ) {
			return $result;
		}

		return (object) [
			'name'          => 'Embed Drive',
			'slug'          => 'embed-drive',
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/mk-manishkandel">Manish Kandel</a>',
			'homepage'      => 'https://github.com/' . EMBED_DRIVE_REPO,
			'requires_php'  => '8.0',
			'download_link' => $release['package'],
			'sections'      => [
				'changelog' => wpautop( esc_html( $release['notes'] ) ),
			],
		];
	}

	/**
	 * @return array|null
	 */
	private static function latest(): ?array {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached['version'] ? $cached : null;
		}

		$release = self::from_api() ?? self::from_release_page();
		if ( ! $release ) {
			set_site_transient( self::CACHE, [ 'version' => '' ], 15 * MINUTE_IN_SECONDS );

			return null;
		}
		set_site_transient( self::CACHE, $release, HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * @return array|null
	 */
	private static function from_api(): ?array {
		$response = wp_remote_get(
			apply_filters( 'embed_drive_release_api', 'https://api.github.com/repos/' . EMBED_DRIVE_REPO . '/releases/latest' ),
			[
				'timeout' => 10,
				'headers' => [ 'Accept' => 'application/vnd.github+json' ],
			]
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$package = '';
		foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
			if ( self::ASSET === ( $asset['name'] ?? '' ) ) {
				$package = (string) $asset['browser_download_url'];
			}
		}
		// WordPress installs whatever this names, so it may only be a file released from this repository.
		$version = ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' );
		$release = 'https://github.com/' . EMBED_DRIVE_REPO . '/releases/';
		if ( $release . 'download/v' . $version . '/' . self::ASSET !== $package || ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}

		return [
			'version' => $version,
			'url'     => $release . 'tag/v' . $version,
			'package' => $package,
			'notes'   => (string) ( $data['body'] ?? '' ),
		];
	}

	/**
	 * Shared hosts often run out of GitHub's API allowance (60 requests an hour per IP address).
	 * The public release page has no such limit, and redirects to the latest release's tag.
	 *
	 * @return array|null
	 */
	private static function from_release_page(): ?array {
		$base     = 'https://github.com/' . EMBED_DRIVE_REPO . '/releases';
		$response = wp_remote_get( $base . '/latest', [ 'timeout' => 10, 'redirection' => 0 ] );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$url = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( ! preg_match( '#/releases/tag/v?(\d+\.\d+\.\d+)$#', $url, $match ) ) {
			return null;
		}

		// GitHub answers 302 when the file exists, sending the download to its file storage.
		$package = $base . '/download/v' . $match[1] . '/' . self::ASSET;
		$file    = wp_remote_head( $package, [ 'timeout' => 10, 'redirection' => 0 ] );
		if ( is_wp_error( $file ) || ! in_array( (int) wp_remote_retrieve_response_code( $file ), [ 200, 302 ], true ) ) {
			return null;
		}

		return [
			'version' => $match[1],
			'url'     => $url,
			'package' => $package,
			'notes'   => 'Release notes: ' . $url,
		];
	}
}
