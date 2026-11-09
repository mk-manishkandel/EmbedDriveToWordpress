<?php
/**
 * Builds a ZIP of selected files and folders as a stream.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ZipDownload {

	/** Google won't export more than this, so it is the upper bound for a native document. */
	const EXPORT_ESTIMATE = 10485760;

	/**
	 * Expand a selection into archive entries, checking every item and the limits.
	 *
	 * @return array|WP_Error Entries of `path` and `file`.
	 */
	public static function collect( array $module, array $ids ) {
		$drive     = new Drive( $module['a'] );
		$max_files = max( 1, (int) Settings::get( 'zip_max_files' ) );
		$max_bytes = min( ZipWriter::MAX_BYTES, max( 1, (int) Settings::get( 'zip_max_mb' ) ) * MB_IN_BYTES );
		$state     = [ 'entries' => [], 'bytes' => 0, 'names' => [] ];

		// Each item costs Google API calls, so turn away an oversized selection before making any.
		$ids = array_unique( $ids );
		if ( count( $ids ) > $max_files ) {
			return self::too_large( $max_files, $max_bytes );
		}

		foreach ( $ids as $id ) {
			$path = $drive->path_from_root( $module['r'], $id );
			if ( is_wp_error( $path ) || ! $path ) {
				return new WP_Error( 'not_found', __( 'File not found.', 'filefolio' ), [ 'status' => 404 ] );
			}
			$error = self::add( $drive, end( $path ), '', $state, $max_files, $max_bytes, 0 );
			if ( $error ) {
				return $error;
			}
		}

		if ( ! $state['entries'] ) {
			return new WP_Error( 'empty', __( 'There are no files to download in this selection.', 'filefolio' ), [ 'status' => 400 ] );
		}

		return $state['entries'];
	}

	private static function too_large( int $max_files, int $max_bytes ): WP_Error {
		return new WP_Error(
			'too_large',
			sprintf(
				/* translators: 1: number of files, 2: size in MB */
				__( 'That selection is too big to download as a ZIP. The limit is %1$d files or %2$d MB.', 'filefolio' ),
				$max_files,
				(int) ( $max_bytes / MB_IN_BYTES )
			),
			[ 'status' => 413 ]
		);
	}

	private static function add( Drive $drive, array $file, string $prefix, array &$state, int $max_files, int $max_bytes, int $depth ): ?WP_Error {
		if ( Drive::is_folder( $file ) ) {
			if ( $depth >= Drive::MAX_DEPTH ) {
				return null;
			}
			$folder = self::unique( $prefix . FileTypes::filename( (string) $file['name'], '' ), $state['names'] ) . '/';
			$token  = '';
			do {
				$page = $drive->list_children( $file['id'], 'name', 'asc', $token, 200 );
				if ( is_wp_error( $page ) ) {
					return new WP_Error( 'unavailable', __( 'This content is not available right now.', 'filefolio' ), [ 'status' => 502 ] );
				}
				foreach ( (array) ( $page['files'] ?? [] ) as $child ) {
					$error = self::add( $drive, $child, $folder, $state, $max_files, $max_bytes, $depth + 1 );
					if ( $error ) {
						return $error;
					}
				}
				$token = (string) ( $page['nextPageToken'] ?? '' );
			} while ( '' !== $token );

			return null;
		}

		$mime = (string) $file['mimeType'];
		if ( ! FileTypes::is_downloadable( $mime ) ) {
			return null; // Forms, Sites and the like have nothing to download.
		}

		$state['bytes'] += FileTypes::is_native( $mime ) ? self::EXPORT_ESTIMATE : (int) ( $file['size'] ?? 0 );
		if ( count( $state['entries'] ) >= $max_files || $state['bytes'] > $max_bytes ) {
			return self::too_large( $max_files, $max_bytes );
		}

		$ext                = FileTypes::delivery( $mime, false )['ext'];
		$state['entries'][] = [
			'path' => self::unique( $prefix . FileTypes::filename( (string) $file['name'], $ext ), $state['names'] ),
			'file' => $file,
		];

		return null;
	}

	/**
	 * Drive allows duplicate names in a folder; a ZIP should not.
	 */
	private static function unique( string $path, array &$names ): string {
		$candidate = $path;
		$extension = pathinfo( $path, PATHINFO_EXTENSION );
		$stem      = '' === $extension ? $path : substr( $path, 0, -strlen( $extension ) - 1 );
		for ( $n = 2; isset( $names[ strtolower( $candidate ) ] ); $n++ ) {
			$candidate = $stem . " ($n)" . ( '' === $extension ? '' : ".$extension" );
		}
		$names[ strtolower( $candidate ) ] = true;

		return $candidate;
	}

	/**
	 * @return void Sends the archive as the response.
	 */
	public static function stream( string $account, array $entries ): void {
		Stream::prepare_output();
		status_header( 200 );
		header( 'Content-Type: application/zip' );
		header( 'X-Content-Type-Options: nosniff' );
		header( Stream::disposition( 'attachment', 'drive-files-' . gmdate( 'Y-m-d' ) . '.zip' ) );

		$zip    = new ZipWriter(
			static function ( string $bytes ) {
				echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary archive body.
				flush();
			}
		);
		$failed = [];

		foreach ( $entries as $entry ) {
			$started = false;
			$file    = $entry['file'];
			$mtime   = (int) strtotime( (string) ( $file['modifiedTime'] ?? '' ) ) ?: time();

			$result = Stream::fetch(
				$account,
				$file,
				false,
				'',
				static function ( int $code ) use ( $zip, $entry, $mtime, &$started ) {
					// An entry only starts once Google accepted the request, so failures leave no broken file.
					if ( $code >= 200 && $code < 300 ) {
						$zip->begin_entry( $entry['path'], $mtime );
						$started = true;
					}
				},
				static function ( string $bytes ) use ( $zip ) {
					$zip->write_chunk( $bytes );
				}
			);

			if ( $started ) {
				$zip->end_entry();
			}
			// A transfer cut off partway still closes its entry, so the archive stays readable.
			if ( ! $started || is_wp_error( $result ) ) {
				$failed[] = $entry['path'];
			}
		}

		// Headers are long gone, so failures can only be reported inside the archive.
		if ( $failed ) {
			$zip->begin_entry( 'files-that-could-not-be-downloaded.txt', time() );
			$zip->write_chunk( implode( "\r\n", $failed ) . "\r\n" );
			$zip->end_entry();
		}

		$zip->finish();
	}
}
