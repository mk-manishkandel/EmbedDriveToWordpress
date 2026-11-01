<?php
/**
 * Plugin settings.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPTION = 'embed_drive_settings';

	const DEFAULTS = [
		'client_id'      => '',
		'client_secret'  => '', // Encrypted.
		'log_enabled'    => true,
		'log_days'       => 90,
		'zip_max_mb'     => 1024,
		'zip_max_files'  => 200,
		'notify_email'   => '',
	];

	public static function all(): array {
		return array_merge( self::DEFAULTS, (array) get_option( self::OPTION, [] ) );
	}

	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	public static function update( array $values ): void {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	/**
	 * An optional sign-in relay, for a site owner who runs one. It is set in code rather than
	 * in the settings screen, since sign-ins and tokens pass through whatever it points to.
	 */
	public static function relay_url(): string {
		$url = defined( 'EMBED_DRIVE_RELAY_URL' ) ? (string) EMBED_DRIVE_RELAY_URL : '';

		return (string) apply_filters( 'embed_drive_relay_url', $url );
	}

	public static function client_secret(): string {
		$stored = (string) self::get( 'client_secret' );

		return '' === $stored ? '' : (string) Crypto::decrypt( $stored );
	}

	public static function has_own_app(): bool {
		return '' !== (string) self::get( 'client_id' ) && '' !== self::client_secret();
	}

	public static function notify_email(): string {
		$email = (string) self::get( 'notify_email' );

		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}
}
