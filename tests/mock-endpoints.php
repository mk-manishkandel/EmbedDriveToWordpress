<?php
/**
 * Plugin Name: Embed Drive test wiring
 * Description: Test-only must-use plugin. Points the plugin at the mock Google server.
 */

// Playground's workers can briefly see the plugin as inactive just after boot, so load it directly.
// WordPress skips it later because it uses include_once on this same path.
require_once WP_PLUGIN_DIR . '/embed-drive/embed-drive.php';

$embed_drive_mock = 'http://127.0.0.1:' . ( getenv( 'MOCK_PORT' ) ?: 9400 );

add_filter(
	'embed_drive_google_endpoint',
	static function ( $url, $name ) use ( $embed_drive_mock ) {
		$map = [
			'auth'     => '/auth',
			'token'    => '/token',
			'revoke'   => '/revoke',
			'userinfo' => '/userinfo',
			'drive'    => '/drive/v3',
		];

		return $embed_drive_mock . $map[ $name ];
	},
	10,
	2
);
// Show the real cause of a fatal error in test output.
add_filter( 'wp_php_error_message', static fn( $message, $error ) => $message . ' PHP: ' . wp_json_encode( $error ), 10, 2 );
add_filter( 'embed_drive_relay_url', static fn() => (string) get_option( 'embed_drive_test_relay', '' ) );
add_filter( 'embed_drive_thumbnail_hosts', static fn() => [ '127.0.0.1' ] );
add_filter( 'embed_drive_allow_http_thumbnails', '__return_true' );
add_filter( 'embed_drive_cache_ttl', static fn() => 0 );
add_filter( 'http_request_host_is_external', '__return_true' );
add_filter( 'http_request_args', static function ( $args ) {
	$args['reject_unsafe_urls'] = false;

	return $args;
} );

// Stand in for GitHub. The API refuses (as it does when rate limited) and the release page still answers.
add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) {
		$mode = get_option( 'embed_drive_test_github', '' );
		if ( ! $mode || 0 !== strpos( $url, 'https://' ) || false === strpos( $url, 'github.com' ) ) {
			return $response;
		}
		$reply = static fn( int $code, array $headers = [] ) => [
			'headers'  => $headers,
			'body'     => '',
			'response' => [ 'code' => $code, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
		if ( false !== strpos( $url, 'api.github.com' ) ) {
			return $reply( 403 );
		}
		if ( str_ends_with( $url, '/releases/latest' ) ) {
			return 'down' === $mode ? $reply( 500 ) : $reply( 302, [ 'location' => 'https://github.com/o/r/releases/tag/v9.9.8' ] );
		}

		return 'no_asset' === $mode ? $reply( 404 ) : $reply( 302, [ 'location' => 'https://objects.example.test/f' ] );
	},
	10,
	3
);

// Test-only endpoint to set up state and run PHP paths that have no HTTP route.
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'embed-drive-test/v1',
			'/do',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $r ) {
					switch ( $r->get_param( 'cmd' ) ) {
						case 'connect':
							$id = EmbedDrive\Accounts::save_connected(
								[ 'sub' => '12345', 'email' => 'owner@example.com', 'name' => 'Drive Owner' ],
								[ 'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600 ],
								'own'
							);
							EmbedDrive\Settings::update(
								[
									'client_id'     => 'test-client',
									'client_secret' => EmbedDrive\Crypto::encrypt( 'test-secret' ),
								]
							);

							return [ 'account' => $id ];
						case 'relay':
							update_option( 'embed_drive_test_relay', (string) $r->get_param( 'url' ) );

							return [ 'ok' => true ];
						case 'read_settings':
							return [ 'settings' => EmbedDrive\Settings::all() ];
						case 'settings':
							EmbedDrive\Settings::update( (array) $r->get_param( 'values' ) );

							return [ 'ok' => true ];
						case 'disconnect_all':
							foreach ( array_keys( EmbedDrive\Accounts::all() ) as $id ) {
								EmbedDrive\Accounts::disconnect( (string) $id );
							}

							return [ 'ok' => true ];
						case 'ready':
							return [ 'ok' => true ];
						case 'render':
							return [ 'html' => do_shortcode( (string) $r->get_param( 'code' ) ) ];
						case 'render_as_post':
							$author = wp_insert_user(
								[
									'user_login' => 'u' . wp_generate_password( 8, false ),
									'user_pass'  => wp_generate_password(),
									'role'       => (string) $r->get_param( 'role' ),
								]
							);
							$post   = wp_insert_post(
								[
									'post_title'   => 'Test',
									'post_status'  => 'publish',
									'post_author'  => $author,
									'post_content' => (string) $r->get_param( 'code' ),
								]
							);

							return [ 'url' => get_permalink( $post ) ];
						case 'create_page':
							$post = wp_insert_post(
								[
									'post_type'    => 'page',
									'post_title'   => 'Page',
									'post_status'  => 'publish',
									'post_author'  => 1,
									'post_content' => (string) $r->get_param( 'code' ),
								]
							);

							return [ 'url' => get_permalink( $post ) ];
						case 'forge':
							return [ 'token' => EmbedDrive\Crypto::sign( (array) $r->get_param( 'payload' ) ) ];
						case 'expire_token':
							$id    = EmbedDrive\Accounts::default_id();
							$plain = EmbedDrive\Crypto::decrypt( get_option( 'embed_drive_token_' . $id ) );
							$token = json_decode( $plain, true );

							$token['expires_at'] = time() - 10;
							update_option( 'embed_drive_token_' . $id, EmbedDrive\Crypto::encrypt( wp_json_encode( $token ) ), false );

							return [ 'ok' => true ];
						case 'check_all':
							global $embed_drive_mails;
							$embed_drive_mails = [];
							add_filter(
								'pre_wp_mail',
								static function ( $short, $atts ) {
									global $embed_drive_mails;
									$embed_drive_mails[] = $atts;
									return true;
								},
								10,
								2
							);
							EmbedDrive\Accounts::check_all();

							return [ 'accounts' => EmbedDrive\Accounts::all(), 'mails' => $embed_drive_mails ];
						case 'stored_token':
							return [ 'raw' => get_option( 'embed_drive_token_' . ( EmbedDrive\Accounts::default_id() ?: 'g12345' ) ) ];
						case 'log':
							global $wpdb;

							return $wpdb->get_results( "SELECT event, detail, file_id, user_id FROM {$wpdb->prefix}embed_drive_log ORDER BY id" );
						case 'update_check':
							$data = get_plugin_data( EMBED_DRIVE_FILE );

							return apply_filters( 'update_plugins_github.com', false, $data, plugin_basename( EMBED_DRIVE_FILE ), [] );
						case 'seed_release':
							set_site_transient( 'embed_drive_latest_release', (array) $r->get_param( 'release' ), HOUR_IN_SECONDS );

							return [ 'ok' => true ];
						case 'github_mode':
							update_option( 'embed_drive_test_github', (string) $r->get_param( 'mode' ) );
							delete_site_transient( 'embed_drive_latest_release' );

							return [ 'ok' => true ];
						case 'check_again':
							wp_set_current_user( (int) $r->get_param( 'user' ) );
							$_GET['force-check'] = '1';
							set_site_transient( 'update_plugins', (object) [ 'last_checked' => time() ] );
							do_action( 'load-update-core.php' );
							unset( $_GET['force-check'] );

							return [ 'release' => get_site_transient( 'embed_drive_latest_release' ) ];
						case 'settings_html':
							wp_set_current_user( 1 );
							ob_start();
							EmbedDrive\Admin::settings_page();
							$settings = ob_get_clean();
							ob_start();
							EmbedDrive\Admin::builder_page();
							$builder = ob_get_clean();
							ob_start();
							EmbedDrive\Admin::activity_page();
							$activity = ob_get_clean();

							return compact( 'settings', 'builder', 'activity' );
					}

					return new WP_Error( 'unknown' );
				},
			]
		);
	}
);
