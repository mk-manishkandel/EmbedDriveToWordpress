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
	 * How much of a file is fetched from Google per request. Large files and videos are passed on
	 * piece by piece, so the visitor gets the first bytes quickly and the site never holds more than
	 * one piece in memory.
	 */
	private static function piece_size(): int {
		return max( 262144, (int) apply_filters( 'embed_drive_stream_piece_size', 8 * 1048576 ) );
	}

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
		$export   = null !== $delivery['export'];
		$url      = $export
			? $base . '/export?' . http_build_query( [ 'mimeType' => $delivery['export'] ], '', '&', PHP_QUERY_RFC3986 )
			: $base . '?alt=media&supportsAllDrives=true';

		// Google limits exports to 10 MB and can't send part of one, so an export is a single request.
		if ( $export ) {
			$response = self::request( $account, $url, '', true );

			return is_wp_error( $response ) ? $response : self::pass_on( $response, $on_headers, $on_chunk );
		}

		// What the visitor asked for: from $first to $last (null for the end), or the final $suffix bytes.
		$asked  = '' !== $range;
		$first  = 0;
		$last   = null;
		$suffix = null;
		if ( preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
			if ( '' === $m[1] ) {
				$suffix = (int) $m[2];
			} else {
				$first = (int) $m[1];
				$last  = '' === $m[2] ? null : (int) $m[2];
			}
		} else {
			$asked = false;
		}

		$piece = self::piece_size();
		$want  = null === $suffix ? $first . '-' . ( null === $last ? $first + $piece - 1 : min( $last, $first + $piece - 1 ) ) : '-' . min( $suffix, $piece );
		$reply = self::request( $account, $url, 'bytes=' . $want, true );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$status = (int) wp_remote_retrieve_response_code( $reply );

		// An empty file has no bytes to ask for, so Google says the range can't be met.
		if ( 416 === $status && ! $asked ) {
			$reply = self::request( $account, $url, '', true );

			return is_wp_error( $reply ) ? $reply : self::pass_on( $reply, $on_headers, $on_chunk );
		}
		$got = self::content_range( $reply );
		if ( 206 !== $status || null === $got ) {
			return self::pass_on( $reply, $on_headers, $on_chunk ); // The whole file, or an error.
		}

		[ $from, $to, $total ] = $got;
		if ( null !== $suffix ) {
			$first = max( 0, $total - $suffix );
			if ( $suffix > $piece ) {
				// The final bytes are more than one piece, so start again from where they begin.
				return self::fetch( $account, $file, $inline, 'bytes=' . $first . '-', $on_headers, $on_chunk );
			}
		}
		$last = null === $last ? $total - 1 : min( $last, $total - 1 );

		$on_headers(
			$asked ? 206 : 200,
			$asked
				? [ 'content-length' => (string) ( $last - $first + 1 ), 'content-range' => "bytes $first-$last/$total" ]
				: [ 'content-length' => (string) $total ]
		);
		$on_chunk( (string) wp_remote_retrieve_body( $reply ) );
		unset( $reply );

		for ( $next = $to + 1; $next <= $last && $next > $from; $next = $to + 1 ) {
			$reply = self::request( $account, $url, 'bytes=' . $next . '-' . min( $last, $next + $piece - 1 ), false );
			$got   = is_wp_error( $reply ) ? null : self::content_range( $reply );
			if ( null === $got || 206 !== (int) wp_remote_retrieve_response_code( $reply ) || $got[0] !== $next ) {
				return new WP_Error( 'network', __( 'Could not reach Google Drive.', 'filefolio' ) );
			}
			[ $from, $to ] = $got;
			$on_chunk( (string) wp_remote_retrieve_body( $reply ) );
			unset( $reply );
		}

		return $asked ? 206 : 200;
	}

	/**
	 * One request to Drive, signing in again once if Google no longer accepts the access token.
	 *
	 * @param bool $first Whether this opens the transfer; only then is a failure noted for the health email.
	 * @return array|WP_Error
	 */
	private static function request( string $account, string $url, string $range, bool $first ) {
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$token = Accounts::access_token( $account, $attempt > 0 );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$headers = [ 'Authorization' => 'Bearer ' . $token ];
			if ( '' !== $range ) {
				$headers['Range'] = $range;
			}

			$response = self::get_with_bearer(
				$url,
				[
					'timeout' => 120,
					'headers' => $headers,
				]
			);
			if ( is_wp_error( $response ) ) {
				Health::record( 'network' );

				return new WP_Error( 'network', __( 'Could not reach Google Drive.', 'filefolio' ) );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 401 !== $status ) {
				// Google refuses to export anything over 10 MB, which is about the file and not the connection.
				if ( $first && ! ( 403 === $status && false !== strpos( $url, '/export?' ) ) ) {
					Health::record_status( $status );
				}

				return $response;
			}
		}
		Health::record_status( 401 );

		return $response;
	}

	/**
	 * Passes a single response on as it is.
	 */
	private static function pass_on( array $response, callable $on_headers, callable $on_chunk ): int {
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$headers = array_map( static fn( $value ) => is_array( $value ) ? implode( ', ', $value ) : (string) $value, wp_remote_retrieve_headers( $response )->getAll() );
		$on_headers( $status, $headers );
		if ( $status >= 200 && $status < 300 ) {
			$on_chunk( (string) wp_remote_retrieve_body( $response ) );
		}

		return $status;
	}

	/**
	 * The first byte, last byte and total size from a partial response's Content-Range header.
	 *
	 * @return int[]|null
	 */
	private static function content_range( array $response ): ?array {
		if ( ! preg_match( '#^bytes (\d+)-(\d+)/(\d+)$#', trim( (string) wp_remote_retrieve_header( $response, 'content-range' ) ), $m ) ) {
			return null;
		}

		return [ (int) $m[1], (int) $m[2], (int) $m[3] ];
	}

	/**
	 * Whether the account's bearer token may be sent to this URL.
	 */
	public static function is_google_url( string $url ): bool {
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$https = 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) || (bool) apply_filters( 'embed_drive_allow_http_thumbnails', false );
		// Not assets: this is the allowlist of hosts the server may send the site owner's Google token to
		// when it fetches a Drive thumbnail on their behalf. The image is then passed through this site,
		// so visitors' browsers never load anything from these hosts.
		// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
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
			$response = wp_safe_remote_get( $url, $args );
			$status   = (int) wp_remote_retrieve_response_code( $response );
			if ( is_wp_error( $response ) || ! in_array( $status, [ 301, 302, 303, 307, 308 ], true ) ) {
				return $response;
			}
			$url = \WP_Http::make_absolute_url( (string) wp_remote_retrieve_header( $response, 'location' ), $url );
			if ( ! self::is_google_url( $url ) ) {
				break;
			}
		}

		return new WP_Error( 'unsafe_redirect', __( 'Could not reach Google Drive.', 'filefolio' ) );
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
			? new WP_Error( 'export_too_large', __( 'This Google document is too large for Google to export.', 'filefolio' ), [ 'status' => 502 ] )
			: new WP_Error( 'upstream', __( 'Google Drive would not provide this file.', 'filefolio' ), [ 'status' => 502 ] );
	}

	public static function prepare_output(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		// Large files take longer to pass on than PHP's usual time limit allows.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Often disabled on shared hosts.
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
