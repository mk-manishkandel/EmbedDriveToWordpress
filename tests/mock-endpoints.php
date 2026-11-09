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
add_filter( 'embed_drive_update_key', static fn( $key ) => get_option( 'embed_drive_test_update_key' ) ?: $key );
add_filter( 'embed_drive_rate_limit', static fn( $limit ) => (int) get_option( 'embed_drive_test_rate', 0 ) ?: $limit );
add_filter( 'http_request_host_is_external', '__return_true' );
add_filter( 'http_request_args', static function ( $args ) {
	$args['reject_unsafe_urls'] = false;

	return $args;
} );

// Stand in for GitHub. The API refuses (as it does when rate limited) and the release page still answers.
add_filter(
	'pre_http_request',
	static function ( $response, $args, $url ) {
		// Stand in for a release's files, when checking signatures.
		$signed = get_option( 'embed_drive_test_signed' );
		if ( is_array( $signed ) && false !== strpos( $url, '/releases/download/' ) ) {
			$is_signature = str_ends_with( $url, '.sig' );
			$body         = $is_signature ? (string) $signed['sig'] : base64_decode( $signed['zip'] );
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $body );
			}

			return [
				'headers'  => [],
				'body'     => $body,
				'response' => [ 'code' => $is_signature && '' === $signed['sig'] ? 404 : 200, 'message' => '' ],
				'cookies'  => [],
				'filename' => $args['filename'] ?? null,
			];
		}
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
			if ( 'no_sig' === $mode ) {
				return $reply( 403 );
			}
			if ( in_array( $mode, [ 'offsite', 'api_ok' ], true ) ) {
				$host = 'offsite' === $mode ? 'evil.example.test' : 'github.com';

				return [
					'body'     => wp_json_encode(
						[
							'tag_name' => 'v9.9.7',
							'assets'   => [
								[
									'name'                 => 'embed-drive.zip',
									'browser_download_url' => "https://$host/" . EMBED_DRIVE_REPO . '/releases/download/v9.9.7/embed-drive.zip',
								],
								[ 'name' => 'embed-drive.zip.sig' ],
							],
						]
					),
				] + $reply( 200 );
			}

			return $reply( 403 );
		}
		if ( str_ends_with( $url, '/releases/latest' ) ) {
			return 'down' === $mode ? $reply( 500 ) : $reply( 302, [ 'location' => 'https://github.com/o/r/releases/tag/v9.9.8' ] );
		}

		if ( 'no_sig' === $mode && str_ends_with( $url, '.sig' ) ) {
			return $reply( 404 );
		}

		return 'no_asset' === $mode ? $reply( 404 ) : $reply( 302, [ 'location' => 'https://objects.example.test/f' ] );
	},
	10,
	3
);

// Which modules render while a real page is being drawn, beside a post by a low-level author.
add_action(
	'template_redirect',
	static function () {
		if ( empty( $_GET['ed_context_probe'] ) ) {
			return;
		}
		$code    = '[embed_drive mode="files" folder="public"]';
		$author  = wp_insert_user( [ 'user_login' => 'p' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
		$make    = static fn( $fields = [] ) => get_post( wp_insert_post( $fields + [ 'post_author' => $author, 'post_title' => 'p', 'post_status' => 'publish', 'post_content' => 'plain words' ] ) );
		$shown   = static fn( $html ) => false !== strpos( (string) $html, 'data-embed-drive' );
		$on      = static function ( $post, callable $draw ) use ( $shown ) {
			$GLOBALS['post'] = $post;
			$html            = $draw();
			unset( $GLOBALS['post'] );

			return $shown( $html );
		};
		$plain   = $make();
		$meta    = $make();
		update_post_meta( $meta->ID, '_builder_layout', [ 'html' => $code ] );
		$own     = $make( [ 'post_content' => $code ] );
		$block   = $make( [ 'post_content' => '<!-- wp:embed-drive/module {"folder":"public"} /-->' ] );
		add_filter( 'comment_text', 'do_shortcode' );
		$admin   = get_post( wp_insert_post( [ 'post_author' => 1, 'post_title' => 'a', 'post_status' => 'publish', 'post_content' => 'x' ] ) );

		wp_send_json(
			[
				'template_shortcode'  => $on( $plain, static fn() => do_shortcode( $code ) ),
				'template_block'      => $on( $plain, static fn() => do_blocks( '<!-- wp:embed-drive/module {"folder":"public"} /-->' ) ),
				'content_filter'      => $on( $plain, static fn() => apply_filters( 'the_content', $code ) ),
				'own_content'         => $on( $own, static fn() => do_shortcode( $own->post_content ) ),
				'own_block'           => $on( $block, static fn() => do_blocks( $block->post_content ) ),
				'in_custom_field'     => $on( $meta, static fn() => do_shortcode( $code ) ),
				'in_excerpt'          => $on( $make( [ 'post_excerpt' => $code ] ), static fn() => do_shortcode( $code ) ),
				'synced_pattern'      => $on( $make( [ 'post_content' => '<!-- wp:block {"ref":1} /-->' ] ), static fn() => do_shortcode( $code ) ),
				'comment_low_author'  => $on( $plain, static fn() => apply_filters( 'comment_text', $code, null, [] ) ),
				'comment_admin_post'  => $on( $admin, static fn() => apply_filters( 'comment_text', $code, null, [] ) ),
				'admin_post_content'  => $on( $admin, static fn() => apply_filters( 'the_content', $code ) ),
			]
		);
	}
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
							// This route is a REST request with no post, which the plugin refuses for visitors.
							add_filter( 'embed_drive_author_may_embed', '__return_true' );

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
						case 'pattern_page':
							// A synced pattern made (and maybe last changed) by one role, placed by an Editor.
							$person  = static fn( $role ) => wp_insert_user( [ 'user_login' => 'p' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'role' => $role ] );
							$pattern = static function ( $role, $content, $last = '' ) use ( $person ) {
								$id = wp_insert_post( [ 'post_type' => 'wp_block', 'post_title' => 'Pattern', 'post_status' => 'publish', 'post_author' => $person( $role ), 'post_content' => $content ] );
								$last && update_post_meta( $id, '_edit_last', $person( $last ) );

								return $id;
							};
							$inner   = $pattern( (string) $r->get_param( 'made_by' ), (string) $r->get_param( 'code' ), (string) $r->get_param( 'changed_by' ) );
							$ref     = $r->get_param( 'wrapped' ) ? $pattern( 'editor', '<!-- wp:block {"ref":' . $inner . '} /-->' ) : $inner;
							$post    = wp_insert_post( [ 'post_title' => 'Pattern page', 'post_status' => 'publish', 'post_author' => $person( 'editor' ), 'post_content' => '<!-- wp:block {"ref":' . $ref . '} /-->' ] );

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
						case 'health':
							if ( $r->get_param( 'reset' ) ) {
								delete_option( 'embed_drive_health' );
								EmbedDrive\Drive::flush_cache();
							}
							$health = (array) get_option( 'embed_drive_health', [] );
							// Moves the clock back, so the once-a-minute limit doesn't slow the tests.
							$age = (int) $r->get_param( 'age' );
							if ( $age ) {
								foreach ( $health as $key => $entry ) {
									if ( '_latest' === $key ) {
										$health[ $key ]['time'] -= $age;
									} else {
										$health[ $key ]['last']  -= $age;
										$health[ $key ]['first'] -= $age;
									}
								}
								update_option( 'embed_drive_health', $health, false );
							}
							unset( $health['_latest'] );

							return (object) $health;
						case 'health_latest':
							return [ 'latest' => EmbedDrive\Health::latest() ];
						case 'privacy':
							$make  = static fn( $name ) => wp_insert_user( [ 'user_login' => $name . wp_rand(), 'user_email' => $name . wp_rand() . '@example.com', 'user_pass' => wp_generate_password() ] );
							$alice = $make( 'alice' );
							$bob   = $make( 'bob' );
							EmbedDrive\Settings::update( [ 'log_enabled' => true ] );
							foreach ( [ [ $alice, 'download', 'alice-file.pdf' ], [ $alice, 'search', 'alice secret term' ], [ $bob, 'download', 'bob-file.pdf' ] ] as [ $user, $event, $detail ] ) {
								wp_set_current_user( $user );
								EmbedDrive\ActivityLog::record( $event, 'acct', 'f1', $detail );
							}
							wp_set_current_user( 0 );
							$email     = get_userdata( $alice )->user_email;
							$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
							$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] );
							$export    = call_user_func( $exporters['embed-drive']['callback'], $email, 1 );
							$nobody    = call_user_func( $exporters['embed-drive']['callback'], 'nobody@example.com', 1 );
							$erased    = call_user_func( $erasers['embed-drive']['callback'], $email, 1 );
							$after     = call_user_func( $exporters['embed-drive']['callback'], $email, 1 );
							$bob_left  = call_user_func( $exporters['embed-drive']['callback'], get_userdata( $bob )->user_email, 1 );

							return compact( 'export', 'nobody', 'erased', 'after', 'bob_left' );
						case 'two_copies':
							$active = (array) get_option( 'active_plugins', [] );
							defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'filefolio/embed-drive.php' );
							$kept = [];
							foreach ( [ 'filefolio', 'embed-drive' ] as $folder ) {
								include WP_PLUGIN_DIR . '/' . $folder . '/uninstall.php';
								$kept[ $folder ] = false !== get_option( 'embed_drive_accounts' ) && false !== get_option( 'embed_drive_settings' );
							}

							return [
								'both_active' => in_array( 'embed-drive/embed-drive.php', $active, true ) && in_array( 'filefolio/embed-drive.php', $active, true ),
								'running'     => EMBED_DRIVE_FILE,
								'kept'        => $kept,
							];
						case 'health_summary':
							global $embed_drive_mails;
							$embed_drive_mails = [];
							$mail_works        = (bool) ( $r->get_param( 'mail_works' ) ?? true );
							add_filter(
								'pre_wp_mail',
								static function ( $short, $atts ) use ( $mail_works ) {
									global $embed_drive_mails;
									$embed_drive_mails[] = $atts;
									return $mail_works;
								},
								10,
								2
							);
							do_action( EmbedDrive\Plugin::CRON_DAILY );

							$health = (array) get_option( 'embed_drive_health', [] );
							unset( $health['_latest'] );

							return [ 'mails' => $embed_drive_mails, 'health' => (object) $health ];
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
						case 'attack_contributor':
							// A low-level account asks WordPress's block preview for a folder of its choosing.
							$user = wp_insert_user( [ 'user_login' => 'c' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => (string) ( $r->get_param( 'role' ) ?: 'contributor' ) ] );
							wp_set_current_user( $user );
							$request = new WP_REST_Request( 'GET', '/wp/v2/block-renderer/embed-drive/module' );
							$request->set_param( 'context', 'edit' );
							if ( $r->get_param( 'own_post' ) ) {
								$request->set_param( 'post_id', wp_insert_post( [ 'post_author' => $user, 'post_title' => 'mine', 'post_content' => 'nothing here' ] ) );
							}
							$request->set_param( 'attributes', [ 'mode' => 'files', 'folder' => 'secret' ] );
							$response = rest_do_request( $request );
							$html     = (string) ( $response->get_data()['rendered'] ?? '' );
							wp_set_current_user( 0 );
							preg_match( '/data-embed-drive="([^"]+)"/', $html, $found );
							$config = $found ? json_decode( html_entity_decode( $found[1] ), true ) : [];

							return [ 'status' => $response->get_status(), 'token' => $config['token'] ?? null, 'html' => substr( $html, 0, 120 ) ];
						case 'repo':
							return [ 'repo' => EMBED_DRIVE_REPO ];
						case 'verify_package':
							require_once ABSPATH . 'wp-admin/includes/file.php';
							require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
							update_option( 'embed_drive_test_update_key', (string) $r->get_param( 'key' ) );
							update_option( 'embed_drive_test_signed', [ 'zip' => (string) $r->get_param( 'zip' ), 'sig' => (string) $r->get_param( 'sig' ) ] );
							$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
							$upgrader->init();
							$result = apply_filters( 'upgrader_pre_download', false, (string) $r->get_param( 'package' ), $upgrader, [ 'plugin' => (string) ( $r->get_param( 'plugin' ) ?? plugin_basename( EMBED_DRIVE_FILE ) ) ] );
							delete_option( 'embed_drive_test_update_key' );
							delete_option( 'embed_drive_test_signed' );
							$bytes = is_string( $result ) ? base64_encode( (string) file_get_contents( $result ) ) : null;
							if ( is_string( $result ) ) {
								wp_delete_file( $result );
							}

							return [ 'reply' => is_wp_error( $result ) ? $result->get_error_code() : ( is_string( $result ) ? 'file' : $result ), 'bytes' => $bytes ];
						case 'role_probe':
							$user = wp_insert_user( [ 'user_login' => 'r' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => (string) $r->get_param( 'role' ) ] );
							wp_set_current_user( $user );
							$account = (string) EmbedDrive\Accounts::default_id();
							$browse  = new WP_REST_Request( 'GET', '/embed-drive/v1/admin/browse' );
							$browse->set_param( 'account', $account );
							$result = [
								'browse'     => rest_do_request( $browse )->get_status(),
								'embed'      => EmbedDrive\Modules::current_user_can_embed(),
								'settings'   => current_user_can( 'manage_options' ),
							];
							wp_set_current_user( 0 );

							return $result;
						case 'widget_probe':
							$author = wp_insert_user( [ 'user_login' => 'w' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author' ] );
							$post   = wp_insert_post( [ 'post_author' => $author, 'post_title' => 'w', 'post_status' => 'publish', 'post_content' => 'x' ] );
							$GLOBALS['post'] = get_post( $post );
							$code = '[embed_drive mode="files" folder="public"]';
							do_action( 'dynamic_sidebar_before', 'test', true );
							$widget = do_shortcode( $code );
							$shown_post = apply_filters( 'the_content', $code );
							do_action( 'dynamic_sidebar_after', 'test', true );
							$after = do_shortcode( $code );
							unset( $GLOBALS['post'] );

							return [ 'in_widget' => '' !== $widget, 'post_in_widget' => false !== strpos( $shown_post, 'data-embed-drive' ), 'in_author_content' => '' !== $after ];
						case 'set_rate':
							update_option( 'embed_drive_test_rate', (int) $r->get_param( 'limit' ) );

							return [ 'ok' => true ];
						case 'reset_links':
							EmbedDrive\Modules::reset_links();

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
