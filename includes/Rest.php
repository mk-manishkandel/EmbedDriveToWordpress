<?php
/**
 * REST API. Visitor routes are public, so that cached pages keep working, and are authorised by the
 * signed module token: it can only ever reach the folder or file an editor chose for that module.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'embed-drive/v1';

	public static function register(): void {
		$public = [
			'/list'        => [ WP_REST_Server::READABLE, 'list_folder' ],
			'/search'      => [ WP_REST_Server::READABLE, 'search' ],
			'/file'        => [ WP_REST_Server::READABLE, 'file' ],
			'/thumb'       => [ WP_REST_Server::READABLE, 'thumb' ],
			'/zip/prepare' => [ WP_REST_Server::CREATABLE, 'zip_prepare' ],
			'/zip'         => [ WP_REST_Server::READABLE, 'zip' ],
		];
		foreach ( $public as $route => [ $methods, $callback ] ) {
			register_rest_route(
				self::NS,
				$route,
				[
					'methods'             => $methods,
					'callback'            => [ self::class, $callback ],
					'permission_callback' => '__return_true',
				]
			);
		}

		register_rest_route(
			self::NS,
			'/admin/browse',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ self::class, 'browse' ],
				'permission_callback' => [ Modules::class, 'current_user_can_embed' ],
			]
		);
	}

	public static function file_url( string $token, string $id, string $disposition ): string {
		return add_query_arg(
			[
				'm'    => $token,
				'id'   => $id,
				'disp' => $disposition,
			],
			rest_url( self::NS . '/file' )
		);
	}

	public static function list_folder( WP_REST_Request $request ) {
		$module = self::module( $request );
		if ( is_wp_error( $module ) ) {
			return $module;
		}
		if ( 'files' !== $module['m'] ) {
			return self::denied();
		}
		if ( ! self::within_rate( 'list', 240 ) ) {
			return self::too_many();
		}

		$drive  = new Drive( $module['a'] );
		$folder = self::id_param( $request, 'folder' ) ?: $module['r'];
		$path   = $drive->path_from_root( $module['r'], $folder );
		if ( is_wp_error( $path ) ) {
			return self::visitor_error( $path );
		}
		if ( ! $path || ! Drive::is_folder( end( $path ) ) ) {
			return self::denied();
		}

		$page = $drive->list_children(
			end( $path )['id'],
			self::text_param( $request, 'sort' ),
			self::text_param( $request, 'order' ),
			substr( self::text_param( $request, 'cursor' ), 0, 4096 )
		);
		if ( is_wp_error( $page ) ) {
			return self::visitor_error( $page );
		}

		return self::uncached( [
			'breadcrumbs' => array_map(
				static fn( $crumb ) => [
					'id'   => $crumb['id'],
					'name' => $crumb['name'],
				],
				$path
			),
			'items'       => array_map( static fn( $file ) => self::item( $file, $module ), (array) ( $page['files'] ?? [] ) ),
			'next'        => (string) ( $page['nextPageToken'] ?? '' ),
		] );
	}

	/**
	 * Listings are already cached here, per page. A page cache or CDN in front of the site could
	 * otherwise answer every request for the next page with the first one.
	 */
	private static function uncached( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	public static function search( WP_REST_Request $request ) {
		$module = self::module( $request );
		if ( is_wp_error( $module ) ) {
			return $module;
		}
		if ( empty( $module['s'] ) ) {
			return self::denied();
		}
		$term = trim( sanitize_text_field( self::text_param( $request, 'q' ) ) );
		if ( mb_strlen( $term ) < 2 ) {
			return new WP_Error( 'short_query', __( 'Please type at least 2 characters.', 'embed-drive' ), [ 'status' => 400 ] );
		}
		if ( ! self::within_rate( 'search', 30 ) ) {
			return self::too_many();
		}

		$result = ( new Drive( $module['a'] ) )->search_tree( $module['r'], mb_substr( $term, 0, 100 ) );
		if ( is_wp_error( $result ) ) {
			return self::visitor_error( $result );
		}
		ActivityLog::record( 'search', $module['a'], '', $term );

		return self::uncached( [
			'items'     => array_map( static fn( $file ) => self::item( $file, $module ), $result['files'] ),
			'truncated' => $result['truncated'],
		] );
	}

	public static function file( WP_REST_Request $request ) {
		$module = self::module( $request );
		if ( is_wp_error( $module ) ) {
			return $module;
		}
		if ( ! self::within_rate( 'file', 600 ) ) {
			return self::too_many();
		}
		$disposition = self::text_param( $request, 'disp' );
		$inline      = 'inline' === $disposition;
		$viewer      = 'viewer' === $disposition;
		if ( $inline || $viewer ? empty( $module['p'] ) : empty( $module['d'] ) ) {
			return self::denied();
		}

		$file = self::locate_file( $module, self::id_param( $request, 'id' ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$mime = (string) $file['mimeType'];
		if ( ! FileTypes::is_downloadable( $mime ) ) {
			return new WP_Error( 'unsupported', __( 'This kind of Google file cannot be downloaded.', 'embed-drive' ), [ 'status' => 415 ] );
		}
		// Previewing must not become a way around a module's download setting.
		if ( empty( $module['d'] ) && ( $viewer ? 'viewer' !== FileTypes::preview_kind( $mime ) : ( $inline && ! FileTypes::can_inline( $mime ) ) ) ) {
			return self::denied();
		}

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) ) : '';

		$error = Stream::send_file(
			$module['a'],
			$file,
			$inline,
			$range,
			static function () use ( $range, $inline, $viewer, $module, $file ) {
				// Video players send many range requests per view; count only the one from the start.
				if ( '' === $range || 0 === strpos( $range, 'bytes=0-' ) ) {
					ActivityLog::record( $inline || $viewer ? 'preview' : 'download', $module['a'], $file['id'], $file['name'] );
				}
			},
			$viewer
		);

		return self::visitor_error( $error );
	}

	public static function thumb( WP_REST_Request $request ) {
		$module = self::module( $request );
		if ( is_wp_error( $module ) ) {
			return $module;
		}
		// The large picture stands in for a preview, so it is only for modules that allow them.
		$large = (bool) $request->get_param( 'large' );
		if ( $large && empty( $module['p'] ) ) {
			return self::denied();
		}
		if ( ! self::within_rate( 'thumb', 1200 ) ) {
			return self::too_many();
		}
		$file = self::locate_file( $module, self::id_param( $request, 'id' ) );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// The account's bearer token goes with this request, so it must only ever go to Google.
		$link = (string) ( $file['thumbnailLink'] ?? '' );
		if ( ! Stream::is_google_url( $link ) ) {
			return new WP_Error( 'no_thumb', __( 'No thumbnail is available.', 'embed-drive' ), [ 'status' => 404 ] );
		}

		$token = Accounts::access_token( $module['a'] );
		if ( is_wp_error( $token ) ) {
			return self::visitor_error( $token );
		}
		// Google's link asks for a small image, too soft for a thumbnail card.
		$response = Stream::get_with_bearer(
			(string) preg_replace( '/=s\d+$/', $large ? '=s1600' : '=s480', $link ),
			[
				'timeout' => 15,
				'headers' => [ 'Authorization' => 'Bearer ' . $token ],
			]
		);
		$type     = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! preg_match( '#^image/(jpeg|png|gif|webp)#', $type ) ) {
			return new WP_Error( 'no_thumb', __( 'No thumbnail is available.', 'embed-drive' ), [ 'status' => 404 ] );
		}

		Stream::prepare_output();
		header_remove( 'Cache-Control' );
		header_remove( 'Expires' );
		header_remove( 'Pragma' );
		header( 'Content-Type: ' . $type );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_remote_retrieve_body( $response ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Image bytes with a checked type.
		exit;
	}

	/**
	 * Check a ZIP request and remember its contents. The download itself is a plain GET
	 * afterwards, so a request that's over a limit gets a clear message rather than a broken file.
	 */
	public static function zip_prepare( WP_REST_Request $request ) {
		$module = self::module( $request );
		if ( is_wp_error( $module ) ) {
			return $module;
		}
		if ( empty( $module['z'] ) || empty( $module['d'] ) ) {
			return self::denied();
		}
		if ( ! self::within_rate( 'zip', 10 ) ) {
			return self::too_many();
		}

		$ids = array_values( array_filter( array_map( [ self::class, 'clean_id' ], (array) $request->get_param( 'ids' ) ) ) );
		if ( ! $ids ) {
			return new WP_Error( 'nothing_selected', __( 'Nothing is selected.', 'embed-drive' ), [ 'status' => 400 ] );
		}

		$entries = ZipDownload::collect( $module, $ids );
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}

		$key = wp_generate_password( 32, false );
		set_transient(
			'embed_drive_zip_' . $key,
			[
				'account' => $module['a'],
				'entries' => $entries,
			],
			10 * MINUTE_IN_SECONDS
		);

		return [ 'url' => add_query_arg( 'key', $key, rest_url( self::NS . '/zip' ) ) ];
	}

	public static function zip( WP_REST_Request $request ) {
		$key  = preg_replace( '/[^A-Za-z0-9]/', '', self::text_param( $request, 'key' ) );
		$job  = '' === $key ? false : get_transient( 'embed_drive_zip_' . $key );
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'expired', __( 'This download link has expired. Please select the files again.', 'embed-drive' ), [ 'status' => 410 ] );
		}
		delete_transient( 'embed_drive_zip_' . $key );

		ActivityLog::record( 'zip', $job['account'], '', sprintf( /* translators: %d: number of files */ _n( '%d file', '%d files', count( $job['entries'] ), 'embed-drive' ), count( $job['entries'] ) ) );
		ZipDownload::stream( $job['account'], $job['entries'] );
		exit;
	}

	public static function browse( WP_REST_Request $request ) {
		$account = self::text_param( $request, 'account' );
		if ( ! Accounts::get( $account ) ) {
			return new WP_Error( 'no_account', __( 'Choose a connected account first.', 'embed-drive' ), [ 'status' => 400 ] );
		}
		$drive  = new Drive( $account );
		$parent = self::text_param( $request, 'parent' );

		if ( '' === $parent ) {
			$items  = [
				[ 'id' => 'root', 'name' => __( 'My Drive', 'embed-drive' ), 'type' => 'folder' ],
				[ 'id' => 'shared', 'name' => __( 'Shared with me', 'embed-drive' ), 'type' => 'virtual' ],
			];
			$drives = $drive->shared_drives();
			if ( ! is_wp_error( $drives ) ) {
				foreach ( (array) ( $drives['drives'] ?? [] ) as $shared ) {
					$items[] = [ 'id' => $shared['id'], 'name' => $shared['name'], 'type' => 'folder' ];
				}
			}

			return [ 'items' => $items ];
		}

		if ( 'shared' === $parent ) {
			$page = $drive->shared_with_me();
		} else {
			$parent = self::clean_id( $parent );
			$folder = '' === $parent ? new WP_Error( 'not_found', __( 'File not found.', 'embed-drive' ), [ 'status' => 404 ] ) : $drive->get_file( $parent );
			if ( is_wp_error( $folder ) ) {
				return $folder;
			}
			$page = $drive->list_children( $folder['id'], 'name', 'asc', self::text_param( $request, 'page' ), 200 );
		}
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		return [
			'items' => array_map(
				static fn( $file ) => [
					'id'   => $file['id'],
					'name' => $file['name'],
					'type' => Drive::is_folder( $file ) ? 'folder' : 'file',
				],
				(array) ( $page['files'] ?? [] )
			),
			'next'  => (string) ( $page['nextPageToken'] ?? '' ),
		];
	}

	/**
	 * Google's picture of the file is the last resort before offering only a download.
	 */
	private static function preview_for( array $file ): string {
		$mime = (string) $file['mimeType'];
		$kind = FileTypes::preview_kind( $mime );
		if ( 'none' !== $kind ) {
			return $kind;
		}
		if ( Drive::is_link_shared( $file ) && FileTypes::is_downloadable( $mime ) ) {
			return 'viewer';
		}

		return empty( $file['thumbnailLink'] ) ? 'none' : 'thumb';
	}

	private static function item( array $file, array $module ): array {
		$mime      = (string) $file['mimeType'];
		$is_folder = Drive::is_folder( $file );

		return [
			'id'       => $file['id'],
			'name'     => $file['name'],
			'type'     => $is_folder ? 'folder' : 'file',
			'mime'     => $mime,
			'size'     => isset( $file['size'] ) ? (int) $file['size'] : null,
			'modified' => (string) ( $file['modifiedTime'] ?? '' ),
			'thumb'    => ! $is_folder && ! empty( $file['thumbnailLink'] ),
			'shared'   => ! $is_folder && Drive::is_link_shared( $file ),
			'preview'  => ( ! $is_folder && ! empty( $module['p'] ) ) ? self::preview_for( $file ) : 'none',
			'download' => ! $is_folder && ! empty( $module['d'] ) && FileTypes::is_downloadable( $mime ),
		];
	}

	/**
	 * @return array|WP_Error The verified module.
	 */
	private static function module( WP_REST_Request $request ) {
		$module = Crypto::verify( self::text_param( $request, 'm' ) );
		if ( ! $module || ! isset( $module['a'], $module['r'], $module['m'] ) || ! Accounts::get( (string) $module['a'] ) ) {
			return self::denied();
		}
		if ( Modules::token_version() !== (int) ( $module['v'] ?? 0 ) ) {
			return self::denied();
		}

		return $module;
	}

	/**
	 * @return array|WP_Error A file inside the module's folder. Folders are rejected.
	 */
	private static function locate_file( array $module, string $id ) {
		if ( '' === $id ) {
			return self::denied();
		}
		$path = ( new Drive( $module['a'] ) )->path_from_root( $module['r'], $id );
		if ( is_wp_error( $path ) ) {
			return self::visitor_error( $path );
		}
		if ( ! $path || Drive::is_folder( end( $path ) ) ) {
			return self::denied();
		}

		return end( $path );
	}

	private static function id_param( WP_REST_Request $request, string $name ): string {
		return self::clean_id( self::text_param( $request, $name ) );
	}

	/**
	 * Query strings can carry arrays where a string is expected.
	 */
	private static function text_param( WP_REST_Request $request, string $name ): string {
		$value = $request->get_param( $name );

		return is_scalar( $value ) ? (string) $value : '';
	}

	public static function clean_id( $id ): string {
		return is_string( $id ) && preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $id ) ? $id : '';
	}

	/**
	 * Fixed-window limit per visitor IP, to protect the Google API quota.
	 */
	private static function within_rate( string $bucket, int $per_minute ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'embed_drive_rl_' . md5( $bucket . '|' . $ip . '|' . floor( time() / MINUTE_IN_SECONDS ) );
		$hit = (int) get_transient( $key ) + 1;
		set_transient( $key, $hit, 2 * MINUTE_IN_SECONDS );

		return $hit <= (int) apply_filters( 'embed_drive_rate_limit', $per_minute, $bucket );
	}

	/**
	 * A missing file and one outside the folder must look identical to a visitor.
	 */
	private static function denied(): WP_Error {
		return new WP_Error( 'not_found', __( 'File not found.', 'embed-drive' ), [ 'status' => 404 ] );
	}

	private static function too_many(): WP_Error {
		return new WP_Error( 'rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'embed-drive' ), [ 'status' => 429 ] );
	}

	private static function visitor_error( WP_Error $error ): WP_Error {
		$code = $error->get_error_code();
		if ( 'not_found' === $code ) {
			return self::denied();
		}
		if ( 'export_too_large' === $code || 'unsupported' === $code ) {
			return $error;
		}

		return new WP_Error( 'unavailable', __( 'This content is not available right now.', 'embed-drive' ), [ 'status' => 502 ] );
	}
}
