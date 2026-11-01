<?php
/**
 * Plugin updates from GitHub Releases, using WordPress's own Update URI mechanism.
 * The only data sent is an ordinary request to the public GitHub API.
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

		$empty    = [ 'version' => '' ];
		$response = wp_remote_get(
			apply_filters( 'embed_drive_release_api', 'https://api.github.com/repos/' . EMBED_DRIVE_REPO . '/releases/latest' ),
			[
				'timeout' => 10,
				'headers' => [ 'Accept' => 'application/vnd.github+json' ],
			]
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::CACHE, $empty, HOUR_IN_SECONDS );

			return null;
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$package = '';
		foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
			if ( self::ASSET === ( $asset['name'] ?? '' ) ) {
				$package = (string) $asset['browser_download_url'];
			}
		}
		if ( '' === $package ) {
			set_site_transient( self::CACHE, $empty, HOUR_IN_SECONDS );

			return null;
		}

		$release = [
			'version' => ltrim( (string) ( $data['tag_name'] ?? '' ), 'vV' ),
			'url'     => (string) ( $data['html_url'] ?? '' ),
			'package' => $package,
			'notes'   => (string) ( $data['body'] ?? '' ),
		];
		set_site_transient( self::CACHE, $release, 6 * HOUR_IN_SECONDS );

		return $release;
	}
}
