<?php
/**
 * Google sign-in, through the relay or the site's own Google client. PKCE is used for both.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class OAuth {

	const SCOPES = [
		'https://www.googleapis.com/auth/drive.readonly',
		'https://www.googleapis.com/auth/userinfo.email',
		'https://www.googleapis.com/auth/userinfo.profile',
	];

	const ENDPOINTS = [
		'auth'     => 'https://accounts.google.com/o/oauth2/v2/auth',
		'token'    => 'https://oauth2.googleapis.com/token',
		'revoke'   => 'https://oauth2.googleapis.com/revoke',
		'userinfo' => 'https://www.googleapis.com/oauth2/v3/userinfo',
		'drive'    => 'https://www.googleapis.com/drive/v3',
	];

	public static function boot(): void {
		add_action( 'admin_post_embed_drive_oauth_start', [ self::class, 'start' ] );
		add_action( 'admin_post_embed_drive_oauth_callback', [ self::class, 'callback' ] );
	}

	public static function endpoint( string $name ): string {
		return (string) apply_filters( 'embed_drive_google_endpoint', self::ENDPOINTS[ $name ], $name );
	}

	public static function callback_url(): string {
		return admin_url( 'admin-post.php?action=embed_drive_oauth_callback' );
	}

	/**
	 * Send the admin to Google (directly, or by way of the relay's confirmation screen).
	 */
	public static function start(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect Google accounts.', 'embed-drive' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'embed_drive_oauth_start' );

		$via = isset( $_GET['via'] ) && 'own' === $_GET['via'] ? 'own' : 'relay';
		if ( 'own' === $via ? ! Settings::has_own_app() : '' === Settings::relay_url() ) {
			self::finish( 'error', __( 'Add your Google app\'s client ID and secret first.', 'embed-drive' ) );
		}

		$state    = wp_generate_password( 32, false );
		$verifier = Crypto::b64url( random_bytes( 48 ) );
		$now_user = get_current_user_id();
		set_transient( "embed_drive_oauth_{$now_user}_{$state}", [ 'verifier' => $verifier, 'via' => $via ], 15 * MINUTE_IN_SECONDS );

		$challenge = Crypto::b64url( hash( 'sha256', $verifier, true ) );

		if ( 'own' === $via ) {
			$url = add_query_arg(
				array_map(
					'rawurlencode',
					[
						'client_id'             => (string) Settings::get( 'client_id' ),
						'redirect_uri'          => self::callback_url(),
						'response_type'         => 'code',
						'scope'                 => implode( ' ', self::SCOPES ),
						'access_type'           => 'offline',
						'prompt'                => 'consent',
						'state'                 => $state,
						'code_challenge'        => $challenge,
						'code_challenge_method' => 'S256',
					]
				),
				self::endpoint( 'auth' )
			);
		} else {
			$url = add_query_arg(
				array_map(
					'rawurlencode',
					[
						'action'         => 'start',
						'return_url'     => self::callback_url(),
						'state'          => $state,
						'code_challenge' => $challenge,
					]
				),
				Settings::relay_url()
			);
		}

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External by design.
		exit;
	}

	/**
	 * Google (or the relay) sends the admin back here with an authorization code.
	 */
	public static function callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The one-time state value below is the CSRF check.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to connect Google accounts.', 'embed-drive' ), '', [ 'response' => 403 ] );
		}

		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$key   = 'embed_drive_oauth_' . get_current_user_id() . '_' . $state;
		$saved = '' === $state ? false : get_transient( $key );
		if ( ! is_array( $saved ) ) {
			self::finish( 'error', __( 'That sign-in attempt expired or was not started here. Please try again.', 'embed-drive' ) );
		}
		delete_transient( $key );

		if ( ! empty( $_GET['error'] ) || empty( $_GET['code'] ) ) {
			self::finish( 'error', __( 'Google did not grant access.', 'embed-drive' ) );
		}

		$code = (string) wp_unslash( $_GET['code'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Opaque, sent only to the token endpoint.
		// phpcs:enable

		$token = 'own' === $saved['via']
			? self::post_google(
				[
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'code_verifier' => $saved['verifier'],
					'redirect_uri'  => self::callback_url(),
					'client_id'     => (string) Settings::get( 'client_id' ),
					'client_secret' => Settings::client_secret(),
				]
			)
			: self::post_relay(
				[
					'action'        => 'exchange',
					'code'          => $code,
					'code_verifier' => $saved['verifier'],
				]
			);

		if ( is_wp_error( $token ) ) {
			self::finish( 'error', $token->get_error_message() );
		}

		if ( empty( $token['refresh_token'] ) ) {
			self::finish( 'error', __( 'Google did not return long-term access. Please remove this app from your Google account permissions and try again.', 'embed-drive' ) );
		}

		// Google lets people untick individual permissions on the consent screen.
		if ( false === strpos( (string) ( $token['scope'] ?? '' ), 'auth/drive.readonly' ) ) {
			self::finish( 'error', __( 'Please tick the Google Drive permission on the consent screen. Without it the plugin cannot read your files.', 'embed-drive' ) );
		}

		$profile = wp_remote_get(
			self::endpoint( 'userinfo' ),
			[
				'timeout' => 15,
				'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
			]
		);
		$user    = is_wp_error( $profile ) ? null : json_decode( wp_remote_retrieve_body( $profile ), true );
		if ( empty( $user['sub'] ) ) {
			self::finish( 'error', __( 'Could not read the Google account details.', 'embed-drive' ) );
		}

		Accounts::save_connected( $user, $token, $saved['via'] );

		self::finish( 'connected', (string) ( $user['email'] ?? '' ) );
	}

	/**
	 * Get a fresh access token.
	 *
	 * @return array|WP_Error Google's token response. Error code `invalid_grant` means access was lost for good.
	 */
	public static function refresh( string $refresh_token, string $via ) {
		if ( 'own' === $via ) {
			return self::post_google(
				[
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
					'client_id'     => (string) Settings::get( 'client_id' ),
					'client_secret' => Settings::client_secret(),
				]
			);
		}

		return self::post_relay(
			[
				'action'        => 'refresh',
				'refresh_token' => $refresh_token,
			]
		);
	}

	public static function revoke( string $token ): void {
		wp_remote_post(
			self::endpoint( 'revoke' ),
			[
				'timeout' => 10,
				'body'    => [ 'token' => $token ],
			]
		);
	}

	private static function post_google( array $body ) {
		return self::parse_token_response( wp_remote_post( self::endpoint( 'token' ), [ 'timeout' => 20, 'body' => $body ] ) );
	}

	private static function post_relay( array $body ) {
		$relay = Settings::relay_url();
		if ( '' === $relay ) {
			return new WP_Error( 'no_relay', __( 'No sign-in relay is configured.', 'embed-drive' ) );
		}

		return self::parse_token_response( wp_remote_post( $relay, [ 'timeout' => 20, 'body' => $body ] ) );
	}

	private static function parse_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'network', __( 'Could not reach the sign-in service.', 'embed-drive' ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bad_response', __( 'The sign-in service returned an unexpected response.', 'embed-drive' ) );
		}
		if ( ! empty( $data['access_token'] ) ) {
			return $data;
		}
		$code = (string) ( $data['error'] ?? 'token_error' );

		return new WP_Error( $code, sprintf( /* translators: %s: error code from Google */ __( 'Google refused the request (%s).', 'embed-drive' ), $code ) );
	}

	/**
	 * @return never
	 */
	private static function finish( string $result, string $detail ): void {
		set_transient( 'embed_drive_notice_' . get_current_user_id(), [ $result, $detail ], MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=embed-drive' ) );
		exit;
	}
}
