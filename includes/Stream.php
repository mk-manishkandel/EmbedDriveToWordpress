<?php
/**
 * Streams Drive content to the visitor through this site, so files never need public sharing.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Stream {

	/**
	 * Fetch Drive content, passing bytes to $on_chunk as they arrive.
	 *
	 * @param callable $on_headers fn(int $status, array $headers) called once before the first byte.
	 * @param callable $on_chunk   fn(string $bytes) for each chunk of a 2xx response.
	 * @return int|WP_Error Final HTTP status.
	 */
	public static function fetch( string $account, array $file, bool $inline, string $range, callable $on_headers, callable $on_chunk ) {
		$delivery = FileTypes::delivery( (string) $file['mimeType'], $inline );
		$base     = OAuth::endpoint( 'drive' ) . '/files/' . rawurlencode( $file['id'] );
		$url      = null !== $delivery['export']
			? $base . '/export?' . http_build_query( [ 'mimeType' => $delivery['export'] ], '', '&', PHP_QUERY_RFC3986 )
			: $base . '?alt=media&supportsAllDrives=true';

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$token = Accounts::access_token( $account, $attempt > 0 );
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$transport = function_exists( 'curl_init' ) ? 'curl' : 'wp_http';
			$status    = self::$transport( $url, $token, $range, $on_headers, $on_chunk, 0 === $attempt );
			if ( 401 !== $status ) {
				// Google refuses to export anything over 10 MB, which is about the file and not the connection.
				if ( is_int( $status ) && ! ( 403 === $status && null !== $delivery['export'] ) ) {
					Health::record_status( $status );
				}

				return $status;
			}
		}
		Health::record_status( 401 );

		return 401;
	}

	/**
	 * @return int|WP_Error
	 */
	private static function curl( string $url, string $token, string $range, callable $on_headers, callable $on_chunk, bool $retry_on_401 ) {
		$headers = [];
		$status  = 0;
		$started = false;

		$request = [ 'Authorization: Bearer ' . $token ];
		if ( '' !== $range ) {
			$request[] = 'Range: ' . $range;
		}

		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			[
				CURLOPT_HTTPHEADER     => $request,
				// Older cURL resends the Authorization header to whatever host a redirect names.
				CURLOPT_FOLLOWLOCATION => version_compare( (string) curl_version()['version'], '7.58.0', '>=' ),
				CURLOPT_UNRESTRICTED_AUTH => false,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_LOW_SPEED_LIMIT => 1,
				CURLOPT_LOW_SPEED_TIME => 60,
				CURLOPT_HEADERFUNCTION => static function ( $ch, $line ) use ( &$headers, &$status ) {
					if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $m ) ) {
						$status  = (int) $m[1];
						$headers = []; // A redirect starts a new header block.
					} elseif ( false !== strpos( $line, ':' ) ) {
						[ $name, $value ]                    = explode( ':', $line, 2 );
						$headers[ strtolower( trim( $name ) ) ] = trim( $value );
					}

					return strlen( $line );
				},
				CURLOPT_WRITEFUNCTION  => static function ( $ch, $bytes ) use ( &$headers, &$status, &$started, $on_headers, $on_chunk, $retry_on_401 ) {
					if ( 401 === $status && $retry_on_401 ) {
						return strlen( $bytes ); // Discard the body; the caller refreshes and retries.
					}
					if ( ! $started ) {
						$started = true;
						$on_headers( $status, $headers );
					}
					if ( $status >= 200 && $status < 300 ) {
						$on_chunk( $bytes );
					}

					return strlen( $bytes );
				},
			]
		);
		$ok = curl_exec( $ch );

		if ( ! $started && ! ( 401 === $status && $retry_on_401 ) ) {
			$on_headers( $status, $headers ); // Empty body.
		}

		if ( false === $ok ) {
			// Once bytes are flowing, a stop can just as well be a slow visitor hitting the time limit.
			if ( ! $started ) {
				Health::record( 'network' );
			}

			return new WP_Error( 'network', __( 'Could not reach Google Drive.', 'embed-drive' ) );
		}

		return $status;
	}

	/**
	 * Fallback for hosts without cURL: download to a temporary file, then pass it on.
	 *
	 * @return int|WP_Error
	 */
	private static function wp_http( string $url, string $token, string $range, callable $on_headers, callable $on_chunk, bool $retry_on_401 ) {
		$temp    = wp_tempnam( 'embed-drive' );
		$headers = [ 'Authorization' => 'Bearer ' . $token ];
		if ( '' !== $range ) {
			$headers['Range'] = $range;
		}

		$response = self::get_with_bearer(
			$url,
			[
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $temp,
				'headers'  => $headers,
			]
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $temp );
			Health::record( 'network' );

			return new WP_Error( 'network', __( 'Could not reach Google Drive.', 'embed-drive' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( ! ( 401 === $status && $retry_on_401 ) ) {
			$on_headers( $status, array_map( static fn( $value ) => is_array( $value ) ? implode( ', ', $value ) : (string) $value, wp_remote_retrieve_headers( $response )->getAll() ) );
			if ( $status >= 200 && $status < 300 ) {
				$handle = fopen( $temp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				while ( $handle && ! feof( $handle ) ) {
					$on_chunk( (string) fread( $handle, 1048576 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				}
				if ( $handle ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				}
			}
		}
		wp_delete_file( $temp );

		return $status;
	}

	/**
	 * Whether the account's bearer token may be sent to this URL.
	 */
	public static function is_google_url( string $url ): bool {
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$https = 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) || (bool) apply_filters( 'embed_drive_allow_http_thumbnails', false );
		foreach ( (array) apply_filters( 'embed_drive_thumbnail_hosts', [ 'googleusercontent.com', 'google.com', 'googleapis.com' ] ) as $allowed ) {
			if ( $https && ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * WordPress repeats request headers on a redirect, so a request carrying the bearer token
	 * follows redirects itself and only to Google.
	 *
	 * @return array|WP_Error
	 */
	public static function get_with_bearer( string $url, array $args ) {
		$args['redirection'] = 0;
		for ( $hop = 0; $hop < 4; $hop++ ) {
			$response = wp_remote_get( $url, $args );
			$status   = (int) wp_remote_retrieve_response_code( $response );
			if ( is_wp_error( $response ) || ! in_array( $status, [ 301, 302, 303, 307, 308 ], true ) ) {
				return $response;
			}
			$url = \WP_Http::make_absolute_url( (string) wp_remote_retrieve_header( $response, 'location' ), $url );
			if ( ! self::is_google_url( $url ) ) {
				break;
			}
		}

		return new WP_Error( 'unsafe_redirect', __( 'Could not reach Google Drive.', 'embed-drive' ) );
	}

	/**
	 * Send a file as the whole response. Exits on success; returns only if nothing was sent yet.
	 *
	 * @param callable $on_commit Called once Google has accepted the request and bytes will flow.
	 * @param bool     $typed     Name the real file type on an attachment, for Google's viewer to read.
	 * @return WP_Error
	 */
	public static function send_file( string $account, array $file, bool $inline, string $range, callable $on_commit, bool $typed = false ): WP_Error {
		$mime     = (string) $file['mimeType'];
		$inline   = $inline && FileTypes::can_inline( $mime );
		$delivery = FileTypes::delivery( $mime, $inline );
		$name     = FileTypes::filename( (string) $file['name'], $delivery['ext'] );

		if ( ! preg_match( '/^bytes=\d*-\d*$/', $range ) || null !== $delivery['export'] ) {
			$range = ''; // Single ranges only, and exports aren't seekable.
		}

		self::prepare_output();

		$committed = false;
		$upstream  = 0;
		$result    = self::fetch(
			$account,
			$file,
			$inline,
			$range,
			static function ( int $status, array $headers ) use ( &$committed, &$upstream, $inline, $delivery, $name, $file, $on_commit, $typed ) {
				$upstream = $status;
				if ( $status < 200 || $status >= 300 ) {
					return;
				}
				$committed = true;
				$on_commit();
				status_header( $status );
				self::security_headers( $inline, FileTypes::preview_kind( (string) $file['mimeType'] ) );
				header( 'Content-Type: ' . ( $inline || $typed ? $delivery['mime'] : 'application/octet-stream' ) );
				header( self::disposition( $inline ? 'inline' : 'attachment', $name ) );
				if ( null === $delivery['export'] ) {
					header( 'Accept-Ranges: bytes' );
				}
				foreach ( [ 'content-length' => 'Content-Length', 'content-range' => 'Content-Range' ] as $key => $label ) {
					if ( isset( $headers[ $key ] ) && preg_match( '#^[\w \-/*]+$#', $headers[ $key ] ) ) {
						header( "$label: {$headers[ $key ]}" );
					}
				}
			},
			static function ( string $bytes ) {
				echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file body, served with safe headers.
				flush();
			}
		);

		if ( $committed ) {
			exit;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 416 === $upstream ) {
			status_header( 416 );
			exit;
		}

		// Google refuses to export anything over 10 MB.
		return 403 === $upstream && null !== $delivery['export']
			? new WP_Error( 'export_too_large', __( 'This Google document is too large for Google to export.', 'embed-drive' ), [ 'status' => 502 ] )
			: new WP_Error( 'upstream', __( 'Google Drive would not provide this file.', 'embed-drive' ), [ 'status' => 502 ] );
	}

	public static function prepare_output(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Often disabled on shared hosts.
		}
		ignore_user_abort( false );
		nocache_headers();
	}

	public static function disposition( string $type, string $name ): string {
		$ascii = preg_replace( '/[^\x20-\x7E]|["\\\\]/', '_', $name );

		return sprintf( 'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s', $type, $ascii, rawurlencode( $name ) );
	}

	private static function security_headers( bool $inline, string $kind ): void {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Referrer-Policy: no-referrer' );
		// Browser PDF viewers don't work under a sandbox policy. PDF isn't rendered as this origin's HTML anyway.
		if ( ! $inline || 'pdf' !== $kind ) {
			header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox" );
		}
	}
}
