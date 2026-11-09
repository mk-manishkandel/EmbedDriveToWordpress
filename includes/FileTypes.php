<?php
/**
 * How each Drive file type is previewed and downloaded.
 *
 * Inline display is an allowlist. Anything else is only sent as an attachment, because a
 * Drive file rendered inline runs on this site's origin: an HTML or SVG file would be XSS.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class FileTypes {

	/** Google-native types have no bytes of their own and must be exported. */
	const EXPORTS = [
		'application/vnd.google-apps.document'     => [ 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx' ],
		'application/vnd.google-apps.spreadsheet'  => [ 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx' ],
		'application/vnd.google-apps.presentation' => [ 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'pptx' ],
		'application/vnd.google-apps.drawing'      => [ 'application/pdf', 'pdf' ],
	];

	const INLINE = [
		'image/jpeg'      => 'image',
		'image/png'       => 'image',
		'image/gif'       => 'image',
		'image/webp'      => 'image',
		'image/avif'      => 'image',
		'application/pdf' => 'pdf',
		'video/mp4'       => 'video',
		'video/webm'      => 'video',
		'video/ogg'       => 'video',
		'audio/mpeg'      => 'audio',
		'audio/ogg'       => 'audio',
		'audio/wav'       => 'audio',
		'audio/x-wav'     => 'audio',
		'audio/aac'       => 'audio',
		'audio/mp4'       => 'audio',
		'audio/flac'      => 'audio',
		'video/quicktime' => 'video',
		'video/x-m4v'     => 'video',
		'audio/mp3'       => 'audio',
		'audio/x-m4a'     => 'audio',
		'audio/x-flac'    => 'audio',
		'text/plain'      => 'text',
		'text/csv'        => 'text',
		'text/markdown'   => 'text',
		'application/json' => 'text',
	];

	/** Browsers can't show these, so they're drawn in a sandboxed frame or by Google. Sent only as attachments. */
	const VIEWER = [
		'application/msword',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/vnd.ms-excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'application/vnd.ms-powerpoint',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'application/vnd.oasis.opendocument.text',
		'application/vnd.oasis.opendocument.spreadsheet',
		'application/vnd.oasis.opendocument.presentation',
		'application/rtf',
		'text/rtf',
		'image/tiff',
		'image/svg+xml',
		'image/vnd.adobe.photoshop',
		'application/vnd.ms-xpsdocument',
	];

	/**
	 * Pictures every browser can show. These are all a gallery shows.
	 *
	 * @return string[]
	 */
	public static function photo_types(): array {
		return array_keys( array_filter( self::INLINE, static fn( $kind ) => 'image' === $kind ) );
	}

	public static function is_photo( string $mime ): bool {
		return in_array( $mime, self::photo_types(), true );
	}

	public static function is_native( string $mime ): bool {
		return isset( self::EXPORTS[ $mime ] );
	}

	/**
	 * Unsupported Google types (Forms, Sites, Maps) can't be exported at all.
	 */
	public static function is_downloadable( string $mime ): bool {
		return self::is_native( $mime ) || 0 !== strpos( $mime, 'application/vnd.google-apps.' );
	}

	/**
	 * @return string image, pdf, video, audio, text, viewer or none.
	 */
	public static function preview_kind( string $mime ): string {
		if ( self::is_native( $mime ) ) {
			return 'pdf'; // Exported to PDF for viewing.
		}

		return self::INLINE[ $mime ] ?? ( in_array( $mime, self::VIEWER, true ) ? 'viewer' : 'none' );
	}

	/**
	 * Whether a browser may show this file directly, on this site's origin.
	 */
	public static function can_inline( string $mime ): bool {
		return self::is_native( $mime ) || isset( self::INLINE[ $mime ] );
	}

	/**
	 * What to request from Drive and how to label it.
	 *
	 * @return array{export: ?string, mime: string, ext: string}
	 */
	public static function delivery( string $mime, bool $inline ): array {
		if ( ! self::is_native( $mime ) ) {
			// Browsers download some text types even when told to show them.
			$shown = $inline && 'text' === self::preview_kind( $mime ) ? 'text/plain; charset=utf-8' : $mime;

			return [ 'export' => null, 'mime' => $shown, 'ext' => '' ];
		}
		[ $export, $ext ] = $inline ? [ 'application/pdf', 'pdf' ] : self::EXPORTS[ $mime ];

		return [ 'export' => $export, 'mime' => $export, 'ext' => $ext ];
	}

	public static function filename( string $name, string $ext ): string {
		$name = trim( preg_replace( '/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $name ) ?? '', " .\t" );
		if ( '' === $name ) {
			$name = 'file';
		}
		if ( '' !== $ext && strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) !== $ext ) {
			$name .= '.' . $ext;
		}

		return $name;
	}
}
