<?php
/**
 * The [embed_drive] shortcode and block rendering.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class Modules {

	const MODES = [ 'files', 'search', 'embed', 'button', 'link' ];

	public static function register(): void {
		wp_register_style( 'embed-drive', EMBED_DRIVE_URL . 'assets/css/embed-drive.css', [], EMBED_DRIVE_VERSION );
		wp_register_script( 'embed-drive', EMBED_DRIVE_URL . 'assets/js/embed-drive.js', [], EMBED_DRIVE_VERSION, true );
		wp_localize_script(
			'embed-drive',
			'EmbedDriveConfig',
			[
				'rest' => rest_url( Rest::NS . '/' ),
				'i18n' => [
					'loading'   => __( 'Loading…', 'embed-drive' ),
					'empty'     => __( 'This folder is empty.', 'embed-drive' ),
					'noResults' => __( 'No files found.', 'embed-drive' ),
					'error'     => __( 'Something went wrong. Please try again.', 'embed-drive' ),
					'search'    => __( 'Search files', 'embed-drive' ),
					'clear'     => __( 'Clear search', 'embed-drive' ),
					'truncated' => __( 'Some results may be missing. Try a more specific search.', 'embed-drive' ),
					'name'      => __( 'Name', 'embed-drive' ),
					'modified'  => __( 'Modified', 'embed-drive' ),
					'size'      => __( 'Size', 'embed-drive' ),
					'sort'      => __( 'Sort by', 'embed-drive' ),
					'listView'  => __( 'List view', 'embed-drive' ),
					'gridView'  => __( 'Grid view', 'embed-drive' ),
					'download'  => __( 'Download', 'embed-drive' ),
					'preview'   => __( 'Preview', 'embed-drive' ),
					'close'     => __( 'Close', 'embed-drive' ),
					'more'      => __( 'Load more', 'embed-drive' ),
					'zip'       => __( 'Download selected as ZIP', 'embed-drive' ),
					'preparing' => __( 'Preparing ZIP…', 'embed-drive' ),
					'select'    => __( 'Select', 'embed-drive' ),
					'crumbs'    => __( 'Folder path', 'embed-drive' ),
				],
			]
		);

		wp_register_script( 'embed-drive-picker', EMBED_DRIVE_URL . 'assets/js/picker.js', [ 'wp-api-fetch', 'wp-i18n' ], EMBED_DRIVE_VERSION, true );
		wp_register_style( 'embed-drive-admin', EMBED_DRIVE_URL . 'assets/css/admin.css', [], EMBED_DRIVE_VERSION );
		wp_add_inline_script( 'embed-drive-picker', 'window.EmbedDriveAccounts = ' . wp_json_encode( self::account_choices() ) . ';', 'before' );

		// The picker dialog opens in the editor's own document.
		add_action( 'enqueue_block_editor_assets', static fn() => wp_enqueue_style( 'embed-drive-admin' ) );

		add_shortcode( 'embed_drive', [ self::class, 'render' ] );
		register_block_type( EMBED_DRIVE_DIR . 'blocks/module', [ 'render_callback' => [ self::class, 'render_block' ] ] );
	}

	/**
	 * Accounts offered in the block and builder, only for people allowed to embed.
	 */
	private static function account_choices(): array {
		if ( ! is_admin() || ! self::current_user_can_embed() ) {
			return [];
		}

		return array_values(
			array_map(
				static fn( $a ) => [
					'id'    => $a['id'],
					'email' => $a['email'],
				],
				Accounts::all()
			)
		);
	}

	public static function current_user_can_embed(): bool {
		return current_user_can( self::embed_capability() );
	}

	private static function embed_capability(): string {
		return (string) apply_filters( 'embed_drive_embed_capability', 'edit_others_posts' );
	}

	/**
	 * @param array $attributes Block attributes.
	 */
	public static function render_block( array $attributes ): string {
		return self::render( $attributes );
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		// Anyone who can write a post could otherwise publish any file in the connected Drive.
		if ( ! self::author_may_embed() ) {
			return self::admin_notice( __( 'Embed Drive: this content is hidden because the author of this post is not allowed to embed Drive files.', 'embed-drive' ) );
		}

		$a = shortcode_atts(
			[
				'mode'     => 'files',
				'folder'   => '',
				'file'     => '',
				'account'  => '',
				'view'     => 'list',
				'sort'     => 'name',
				'order'    => 'asc',
				'download' => null,
				'zip'      => 'yes',
				'search'   => 'yes',
				'preview'  => 'yes',
				'label'    => '',
				'height'   => '600',
			],
			(array) $atts,
			'embed_drive'
		);

		$mode = in_array( $a['mode'], self::MODES, true ) ? $a['mode'] : 'files';
		$kind = in_array( $mode, [ 'files', 'search' ], true ) ? 'folder' : 'file';

		$target = Rest::clean_id( 'folder' === $kind ? (string) $a['folder'] : (string) $a['file'] );
		if ( '' === $target ) {
			return self::admin_notice(
				'folder' === $kind
					? __( 'Embed Drive: choose a folder for this module.', 'embed-drive' )
					: __( 'Embed Drive: choose a file for this module.', 'embed-drive' )
			);
		}

		$account = (string) $a['account'] ?: Accounts::default_id();
		if ( ! Accounts::get( $account ) ) {
			return self::admin_notice( __( 'Embed Drive: the Google account for this module is not connected.', 'embed-drive' ) );
		}

		// Embeds keep their download link off unless asked for; everything else defaults to on.
		$download = null === $a['download'] ? 'embed' !== $mode : self::truthy( $a['download'] );

		$token = Crypto::sign(
			[
				'v' => 1,
				'a' => $account,
				'r' => $target,
				'm' => $mode,
				'd' => in_array( $mode, [ 'button', 'link' ], true ) || $download,
				'z' => 'files' === $mode && $download && self::truthy( $a['zip'] ),
				's' => 'search' === $mode || ( 'files' === $mode && self::truthy( $a['search'] ) ),
				'p' => 'embed' === $mode || self::truthy( $a['preview'] ),
			]
		);

		wp_enqueue_style( 'embed-drive' );

		if ( 'folder' === $kind ) {
			return self::render_app( $mode, $token, $a, $download );
		}

		return self::render_single( $mode, $token, $account, $target, $a, $download );
	}

	private static function render_app( string $mode, string $token, array $a, bool $download ): string {
		wp_enqueue_script( 'embed-drive' );

		$config = [
			'token'    => $token,
			'mode'     => $mode,
			'view'     => 'grid' === $a['view'] ? 'grid' : 'list',
			'sort'     => in_array( $a['sort'], array_keys( Drive::SORTS ), true ) ? $a['sort'] : 'name',
			'order'    => 'desc' === $a['order'] ? 'desc' : 'asc',
			'search'   => 'search' === $mode || self::truthy( $a['search'] ),
			'zip'      => 'files' === $mode && $download && self::truthy( $a['zip'] ),
		];

		return sprintf(
			'<div class="embed-drive" data-embed-drive="%s"><noscript>%s</noscript></div>',
			esc_attr( (string) wp_json_encode( $config ) ),
			esc_html__( 'Please enable JavaScript to browse these files.', 'embed-drive' )
		);
	}

	private static function render_single( string $mode, string $token, string $account, string $id, array $a, bool $download ): string {
		$file = ( new Drive( $account ) )->get_file( $id );
		if ( is_wp_error( $file ) ) {
			return self::admin_notice( __( 'Embed Drive: this file could not be loaded from Google Drive.', 'embed-drive' ) )
				. '<p class="embed-drive-unavailable">' . esc_html__( 'This content is not available right now.', 'embed-drive' ) . '</p>';
		}
		if ( Drive::is_folder( $file ) ) {
			return self::admin_notice( __( 'Embed Drive: this module needs a file, not a folder.', 'embed-drive' ) );
		}

		$name         = (string) $file['name'];
		$id           = (string) $file['id'];
		$download_url = Rest::file_url( $token, $id, 'attachment' );

		if ( 'link' === $mode ) {
			return sprintf( '<a class="embed-drive-link" href="%s" rel="nofollow">%s</a>', esc_url( $download_url ), esc_html( $a['label'] ?: $name ) );
		}
		if ( 'button' === $mode ) {
			return sprintf( '<a class="embed-drive-button" href="%s" rel="nofollow">%s</a>', esc_url( $download_url ), esc_html( $a['label'] ?: sprintf( /* translators: %s: file name */ __( 'Download %s', 'embed-drive' ), $name ) ) );
		}

		$inline = esc_url( Rest::file_url( $token, $id, 'inline' ) );
		$height = max( 100, min( 3000, (int) $a['height'] ) );

		switch ( FileTypes::preview_kind( (string) $file['mimeType'] ) ) {
			case 'image':
				$body = sprintf( '<img src="%s" alt="%s" loading="lazy" />', $inline, esc_attr( $name ) );
				break;
			case 'video':
				$body = sprintf( '<video src="%s" controls preload="metadata" aria-label="%s"></video>', $inline, esc_attr( $name ) );
				break;
			case 'audio':
				$body = sprintf( '<audio src="%s" controls preload="metadata" aria-label="%s"></audio>', $inline, esc_attr( $name ) );
				break;
			case 'pdf':
			case 'text':
				$body = sprintf( '<iframe src="%s" title="%s" height="%d" loading="lazy"></iframe>', $inline, esc_attr( $name ), $height );
				break;
			default:
				$body = '<p>' . esc_html__( 'This file type cannot be shown on the page.', 'embed-drive' ) . '</p>';
				if ( ! $download ) {
					$body .= sprintf( '<p>%s</p>', esc_html( $name ) );
				}
		}

		$footer = $download
			? sprintf( '<p class="embed-drive-embed-download"><a href="%s" rel="nofollow">%s</a></p>', esc_url( $download_url ), esc_html( sprintf( /* translators: %s: file name */ __( 'Download %s', 'embed-drive' ), $name ) ) )
			: '';

		return '<figure class="embed-drive-embed">' . $body . $footer . '</figure>';
	}

	private static function author_may_embed(): bool {
		$post    = get_post();
		$allowed = ! $post || user_can( (int) $post->post_author, self::embed_capability() );

		return (bool) apply_filters( 'embed_drive_author_may_embed', $allowed, $post );
	}

	private static function truthy( $value ): bool {
		return true === $value || in_array( strtolower( (string) $value ), [ 'yes', '1', 'true', 'on' ], true );
	}

	/**
	 * Setup problems are only worth showing to someone who can fix them.
	 */
	private static function admin_notice( string $message ): string {
		if ( ! self::current_user_can_embed() ) {
			return '';
		}

		return '<p class="embed-drive-notice">' . esc_html( $message ) . '</p>';
	}
}
