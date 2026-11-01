<?php
/**
 * Connected Google accounts. Profile data lives in one option; each account's
 * encrypted tokens live in their own, so refreshing one can't overwrite another.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Accounts {

	const OPTION       = 'embed_drive_accounts';
	const TOKEN_PREFIX = 'embed_drive_token_';

	public static function all(): array {
		return (array) get_option( self::OPTION, [] );
	}

	public static function get( string $id ): ?array {
		return self::all()[ $id ] ?? null;
	}

	/**
	 * The account a module uses when it does not name one.
	 */
	public static function default_id(): string {
		return (string) array_key_first( self::all() );
	}

	public static function save_connected( array $user, array $token, string $via ): string {
		$id = 'g' . preg_replace( '/[^0-9A-Za-z]/', '', (string) $user['sub'] );

		$accounts        = self::all();
		$accounts[ $id ] = [
			'id'      => $id,
			'email'   => sanitize_email( (string) ( $user['email'] ?? '' ) ),
			'name'    => sanitize_text_field( (string) ( $user['name'] ?? '' ) ),
			'picture' => esc_url_raw( (string) ( $user['picture'] ?? '' ) ),
			'via'     => $via,
			'status'  => 'ok',
		];
		update_option( self::OPTION, $accounts, false );

		self::store_token(
			$id,
			[
				'access_token'  => $token['access_token'],
				'refresh_token' => $token['refresh_token'],
				'expires_at'    => time() + (int) ( $token['expires_in'] ?? 3600 ),
			]
		);
		Drive::flush_cache();

		return $id;
	}

	public static function disconnect( string $id ): void {
		$token = self::read_token( $id );
		if ( ! empty( $token['refresh_token'] ) ) {
			OAuth::revoke( $token['refresh_token'] );
		}
		delete_option( self::TOKEN_PREFIX . $id );
		$accounts = self::all();
		unset( $accounts[ $id ] );
		update_option( self::OPTION, $accounts, false );
		Drive::flush_cache();
	}

	/**
	 * @return string|WP_Error A valid access token.
	 */
	public static function access_token( string $id, bool $force_refresh = false ) {
		$account = self::get( $id );
		if ( ! $account ) {
			return new WP_Error( 'no_account', __( 'The Google account for this content is not connected.', 'embed-drive' ) );
		}

		$token = self::read_token( $id );
		if ( ! $token || empty( $token['refresh_token'] ) ) {
			self::mark_lost( $id );

			return new WP_Error( 'invalid_grant', __( 'The stored Google credentials can no longer be read.', 'embed-drive' ) );
		}

		if ( ! $force_refresh && (int) $token['expires_at'] > time() + 60 ) {
			return $token['access_token'];
		}

		// Concurrent refreshes are harmless: Google keeps the refresh token and every access token stays valid.
		$fresh = OAuth::refresh( $token['refresh_token'], (string) $account['via'] );
		if ( is_wp_error( $fresh ) ) {
			// Only a definite rejection counts as lost, so a brief outage doesn't alarm anyone.
			// The client errors mean the owner's Google app was deleted or its secret changed.
			if ( in_array( $fresh->get_error_code(), [ 'invalid_grant', 'unauthorized_client', 'invalid_client', 'deleted_client' ], true ) ) {
				self::mark_lost( $id );
			} else {
				Health::record( 'network' === $fresh->get_error_code() ? 'network' : 'signin' );
			}

			return $fresh;
		}

		$token['access_token'] = $fresh['access_token'];
		$token['expires_at']   = time() + (int) ( $fresh['expires_in'] ?? 3600 );
		if ( ! empty( $fresh['refresh_token'] ) ) {
			$token['refresh_token'] = $fresh['refresh_token'];
		}
		self::store_token( $id, $token );

		if ( 'ok' !== $account['status'] ) {
			self::set_status( $id, 'ok' );
		}

		return $token['access_token'];
	}

	/**
	 * Refresh every account so lost access is noticed and reported.
	 */
	public static function check_all(): void {
		foreach ( array_keys( self::all() ) as $id ) {
			self::access_token( (string) $id, true );
		}
	}

	private static function read_token( string $id ): ?array {
		$stored = get_option( self::TOKEN_PREFIX . $id );
		$plain  = is_string( $stored ) ? Crypto::decrypt( $stored ) : null;
		$token  = null === $plain ? null : json_decode( $plain, true );

		return is_array( $token ) ? $token : null;
	}

	private static function store_token( string $id, array $token ): void {
		update_option( self::TOKEN_PREFIX . $id, Crypto::encrypt( (string) wp_json_encode( $token ) ), false );
	}

	private static function set_status( string $id, string $status ): void {
		$accounts = self::all();
		if ( isset( $accounts[ $id ] ) ) {
			$accounts[ $id ]['status'] = $status;
			update_option( self::OPTION, $accounts, false );
		}
	}

	private static function mark_lost( string $id ): void {
		$account = self::get( $id );
		if ( ! $account || 'lost' === $account['status'] ) {
			return; // Already reported once.
		}
		self::set_status( $id, 'lost' );

		wp_mail(
			Settings::notify_email(),
			sprintf( /* translators: %s: site name */ __( '[%s] Embed Drive lost access to Google Drive', 'embed-drive' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: 1: Google account email, 2: settings URL */
				__( "Embed Drive can no longer reach the Google account %1\$s, so its Drive content is not showing on your site.\n\nSign in again here:\n%2\$s", 'embed-drive' ),
				$account['email'],
				admin_url( 'admin.php?page=embed-drive' )
			)
		);
	}
}
