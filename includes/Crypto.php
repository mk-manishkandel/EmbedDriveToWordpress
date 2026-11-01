<?php
/**
 * Encryption for stored credentials and signing for module tokens.
 *
 * Keys derive from the site's WordPress salts. Rotating the salts means accounts must sign in
 * again and cached pages must be cleared. Define EMBED_DRIVE_KEY in wp-config.php to avoid that.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class Crypto {

	private static function key( string $purpose ): string {
		$material = defined( 'EMBED_DRIVE_KEY' ) ? (string) EMBED_DRIVE_KEY : wp_salt( 'auth' );

		return hash_hkdf( 'sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'embed-drive-v1-' . $purpose );
	}

	public static function encrypt( string $plaintext ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		return base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, self::key( 'encrypt' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * @return string|null Null if the value is corrupt or the key changed.
	 */
	public static function decrypt( string $encoded ): ?string {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			self::key( 'encrypt' )
		);

		return false === $plain ? null : $plain;
	}

	public static function b64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Sign a payload into a URL-safe token.
	 */
	public static function sign( array $payload ): string {
		$body = self::b64url( (string) wp_json_encode( $payload ) );

		return $body . '.' . self::b64url( hash_hmac( 'sha256', $body, self::key( 'token' ), true ) );
	}

	/**
	 * @return array|null The payload, or null if the signature does not match.
	 */
	public static function verify( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$expected = self::b64url( hash_hmac( 'sha256', $parts[0], self::key( 'token' ), true ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}
		$json = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$data = false === $json ? null : json_decode( $json, true );

		return is_array( $data ) ? $data : null;
	}
}
