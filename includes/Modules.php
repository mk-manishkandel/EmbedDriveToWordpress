<?php
/**
 * The [embed_drive] shortcode and block rendering.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class Modules {

	const MODES = [ 'files', 'gallery', 'search', 'embed', 'button', 'link' ];

	/** Raising this cancels every visitor link made before it. */
	const TOKEN_VERSION = 2;

	/** How many widget areas are being drawn right now. */
	private static int $sidebars = 0;

	public static function register(): void {
		wp_register_style( 'embed-drive', EMBED_DRIVE_URL . 'assets/css/embed-drive.css', [], EMBED_DRIVE_VERSION );
		wp_register_script( 'embed-drive', EMBED_DRIVE_URL . 'assets/js/embed-drive.js', [], EMBED_DRIVE_VERSION, true );
		wp_localize_script(
			'embed-drive',
			'EmbedDriveConfig',
			[
				'rest'   => rest_url( Rest::NS . '/' ),
				'viewer' => EMBED_DRIVE_URL . 'assets/viewer/viewer.html?v=' . EMBED_DRIVE_VERSION,
				'i18n' => [
					'loading'   => __( 'Loading…', 'filefolio' ),
					'empty'     => __( 'This folder is empty.', 'filefolio' ),
					'noResults' => __( 'No files found.', 'filefolio' ),
					'error'     => __( 'Something went wrong. Please try again.', 'filefolio' ),
					'search'    => __( 'Search files', 'filefolio' ),
					'clear'     => __( 'Clear search', 'filefolio' ),
					'truncated' => __( 'Some results may be missing. Try a more specific search.', 'filefolio' ),
					'name'      => __( 'Name', 'filefolio' ),
					'modified'  => __( 'Modified', 'filefolio' ),
					'size'      => __( 'Size', 'filefolio' ),
					'sort'      => __( 'Sort by', 'filefolio' ),
					'listView'  => __( 'List view', 'filefolio' ),
					'gridView'  => __( 'Grid view', 'filefolio' ),
					'download'  => __( 'Download', 'filefolio' ),
					'preview'   => __( 'Preview', 'filefolio' ),
					'close'     => __( 'Close', 'filefolio' ),
					'more'      => __( 'Load more', 'filefolio' ),
					'home'      => __( 'Go back to the start folder', 'filefolio' ),
					'refresh'   => __( 'Refresh', 'filefolio' ),
					'actions'   => __( 'More actions', 'filefolio' ),
					'results'   => __( 'Search results', 'filefolio' ),
					'ascending' => __( 'Ascending', 'filefolio' ),
					'descending' => __( 'Descending', 'filefolio' ),
					'selectAll' => __( 'Select all', 'filefolio' ),
					'selectNone' => __( 'Deselect all', 'filefolio' ),
					'top'       => __( 'Scroll to top', 'filefolio' ),
					'previous'  => __( 'Previous file', 'filefolio' ),
					'next'      => __( 'Next file', 'filefolio' ),
					'firstPage' => __( 'Only the first page can be shown here. Download the file to see all of it.', 'filefolio' ),
					'noPreview' => __( 'A preview is not available for this file. Please download it instead.', 'filefolio' ),
					'zip'       => __( 'Download selected', 'filefolio' ),
					'preparing' => __( 'Preparing ZIP…', 'filefolio' ),
					'select'    => __( 'Select', 'filefolio' ),
					'crumbs'    => __( 'Folder path', 'filefolio' ),
					'noPhotos'  => __( 'There are no photos here.', 'filefolio' ),
					'photo'     => __( 'Open photo', 'filefolio' ),
				],
			]
		);

		wp_register_script( 'embed-drive-picker', EMBED_DRIVE_URL . 'assets/js/picker.js', [ 'wp-api-fetch', 'wp-i18n' ], EMBED_DRIVE_VERSION, true );
		wp_register_style( 'embed-drive-admin', EMBED_DRIVE_URL . 'assets/css/admin.css', [], EMBED_DRIVE_VERSION );
		wp_add_inline_script( 'embed-drive-picker', 'window.EmbedDriveAccounts = ' . wp_json_encode( self::account_choices() ) . ';', 'before' );

		// The picker dialog opens in the editor's own document.
		add_action( 'enqueue_block_editor_assets', static fn() => wp_enqueue_style( 'embed-drive-admin' ) );

		add_action( 'dynamic_sidebar_before', static function () {
			self::$sidebars++;
		} );
		add_action( 'dynamic_sidebar_after', static function () {
			self::$sidebars--;
		} );

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

	public static function embed_capability(): string {
		return (string) apply_filters( 'embed_drive_embed_capability', 'edit_others_posts' );
	}

	/**
	 * @param array $attributes Block attributes.
	 */
	public static function render_block( array $attributes ): string {
		$html = self::render( $attributes );

		// The wrapper carries the block's alignment and any extra classes chosen in the editor.
		return '' === $html ? '' : '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
	}

	/**
	 * @param array|string $atts Shortcode attributes.
	 */
	public static function render( $atts ): string {
		// Anyone who can write a post could otherwise publish any file in the connected Drive.
		if ( ! self::author_may_embed() ) {
			return self::admin_notice( __( 'FileFolio: this content is hidden because the author of this post is not allowed to embed Drive files.', 'filefolio' ) );
		}

		$a = shortcode_atts(
			[
				'mode'     => 'files',
				'folder'   => '',
				'file'     => '',
				'account'  => '',
				'view'     => 'grid',
				'sort'     => 'name',
				'order'    => 'asc',
				'download' => null,
				'zip'      => 'yes',
				'search'   => 'yes',
				'preview'  => 'yes',
				'label'    => '',
				'height'   => '600',
				'min_height' => '',
			],
			(array) $atts,
			'embed_drive'
		);

		$mode = in_array( $a['mode'], self::MODES, true ) ? $a['mode'] : 'files';
		$kind = in_array( $mode, [ 'files', 'gallery', 'search' ], true ) ? 'folder' : 'file';

		$target = Rest::clean_id( 'folder' === $kind ? (string) $a['folder'] : (string) $a['file'] );
		if ( '' === $target ) {
			return self::admin_notice(
				'folder' === $kind
					? __( 'FileFolio: choose a folder for this module.', 'filefolio' )
					: __( 'FileFolio: choose a file for this module.', 'filefolio' )
			);
		}

		$account = (string) $a['account'] ?: Accounts::default_id();
		if ( ! Accounts::get( $account ) ) {
			return self::admin_notice( __( 'FileFolio: the Google account for this module is not connected.', 'filefolio' ) );
		}

		// Embeds keep their download link off unless asked for; everything else defaults to on.
		$download = null === $a['download'] ? 'embed' !== $mode : self::truthy( $a['download'] );

		$token = Crypto::sign(
			[
				'v' => self::token_version(),
				'a' => $account,
				'r' => $target,
				'm' => $mode,
				'd' => in_array( $mode, [ 'button', 'link' ], true ) || $download,
				'z' => 'files' === $mode && $download && self::truthy( $a['zip'] ),
				's' => 'search' === $mode || ( 'files' === $mode && self::truthy( $a['search'] ) ),
				'p' => in_array( $mode, [ 'embed', 'gallery' ], true ) || self::truthy( $a['preview'] ),
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

		// A module's own value wins over the one in Settings. Either way, 0 means no minimum.
		$min_height = (int) $a['min_height'] ?: (int) Settings::get( 'min_height' );
		$min_height = 'search' !== $mode ? max( 0, min( 3000, $min_height ) ) : 0;

		$config = [
			'token'    => $token,
			'mode'     => $mode,
			'view'     => 'list' === $a['view'] ? 'list' : 'grid',
			'sort'     => in_array( $a['sort'], array_keys( Drive::SORTS ), true ) ? $a['sort'] : 'name',
			'order'    => 'desc' === $a['order'] ? 'desc' : 'asc',
			'search'   => 'search' === $mode || ( 'files' === $mode && self::truthy( $a['search'] ) ),
			'zip'      => 'files' === $mode && $download && self::truthy( $a['zip'] ),
		];

		return sprintf(
			'<div class="embed-drive"%s data-embed-drive="%s"><noscript>%s</noscript></div>',
			$min_height ? sprintf( ' style="--ed-min-height:%dpx"', $min_height ) : '',
			esc_attr( (string) wp_json_encode( $config ) ),
			'gallery' === $mode ? esc_html__( 'Please enable JavaScript to see these photos.', 'filefolio' ) : esc_html__( 'Please enable JavaScript to browse these files.', 'filefolio' )
		);
	}

	private static function render_single( string $mode, string $token, string $account, string $id, array $a, bool $download ): string {
		$file = ( new Drive( $account ) )->get_file( $id );
		if ( is_wp_error( $file ) ) {
			return self::admin_notice( __( 'FileFolio: this file could not be loaded from Google Drive.', 'filefolio' ) )
				. '<p class="embed-drive-unavailable">' . esc_html__( 'This content is not available right now.', 'filefolio' ) . '</p>';
		}
		if ( Drive::is_folder( $file ) ) {
			return self::admin_notice( __( 'FileFolio: this module needs a file, not a folder.', 'filefolio' ) );
		}

		$name         = (string) $file['name'];
		$id           = (string) $file['id'];
		$download_url = Rest::file_url( $token, $id, 'attachment' );

		if ( 'link' === $mode ) {
			return sprintf( '<a class="embed-drive-link" href="%s" rel="nofollow">%s</a>', esc_url( $download_url ), esc_html( $a['label'] ?: $name ) );
		}
		if ( 'button' === $mode ) {
			return sprintf( '<a class="embed-drive-button" href="%s" rel="nofollow">%s</a>', esc_url( $download_url ), esc_html( $a['label'] ?: sprintf( /* translators: %s: file name */ __( 'Download %s', 'filefolio' ), $name ) ) );
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
			case 'viewer':
				if ( Drive::is_link_shared( $file ) ) {
					$body = sprintf( '<iframe src="%s" title="%s" height="%d" loading="lazy"></iframe>', esc_url( 'https://drive.google.com/file/d/' . rawurlencode( $id ) . '/preview' ), esc_attr( $name ), $height );
				} elseif ( ! empty( $file['thumbnailLink'] ) ) {
					$body = sprintf( '<img src="%s" alt="%s" loading="lazy" />', esc_url( add_query_arg( [ 'm' => $token, 'id' => $id, 'large' => 1 ], rest_url( Rest::NS . '/thumb' ) ) ), esc_attr( $name ) );
				} else {
					$body = '<p>' . esc_html__( 'This file type cannot be shown on the page.', 'filefolio' ) . '</p>';
				}
				break;
			case 'pdf':
			case 'text':
				$body = sprintf( '<iframe src="%s" title="%s" height="%d" loading="lazy"></iframe>', $inline, esc_attr( $name ), $height );
				break;
			default:
				$body = '<p>' . esc_html__( 'This file type cannot be shown on the page.', 'filefolio' ) . '</p>';
				if ( ! $download ) {
					$body .= sprintf( '<p>%s</p>', esc_html( $name ) );
				}
		}

		$footer = $download
			? sprintf( '<p class="embed-drive-embed-download"><a href="%s" rel="nofollow">%s</a></p>', esc_url( $download_url ), esc_html( sprintf( /* translators: %s: file name */ __( 'Download %s', 'filefolio' ), $name ) ) )
			: '';

		return '<figure class="embed-drive-embed">' . $body . $footer . '</figure>';
	}

	public static function token_version(): int {
		return self::TOKEN_VERSION + (int) get_option( 'embed_drive_link_reset', 0 );
	}

	/**
	 * Cancel every visitor link. Pages get new ones the next time they render.
	 */
	public static function reset_links(): void {
		update_option( 'embed_drive_link_reset', 1 + (int) get_option( 'embed_drive_link_reset', 0 ) );
	}

	/**
	 * Widgets and templates render with no post, but so do the block preview and similar requests,
	 * where the text comes from whoever is asking. That person must then be allowed to embed.
	 *
	 * A widget or theme template belongs to whoever manages the site, not to the author of the post
	 * it appears beside. It is only trusted beside a low-level author's post when the page itself is
	 * being drawn, nothing in that post mentions a module, and it isn't that post's own content.
	 */
	private static function author_may_embed(): bool {
		$post      = get_post();
		$requested = is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		$content   = doing_filter( 'the_content' ) || doing_filter( 'get_the_excerpt' );
		if ( doing_filter( 'comment_text' ) ) {
			$allowed = false; // Anyone can write a comment, so a site that runs shortcodes in them must not expose Drive.
		} elseif ( self::$sidebars > 0 && ! $content ) {
			$allowed = true;
		} elseif ( ! $post ) {
			$allowed = ! $requested || self::current_user_can_embed();
		} elseif ( user_can( (int) $post->post_author, self::embed_capability() ) ) {
			$allowed = true;
		} else {
			$allowed = ! $requested && ! $content && ! self::post_mentions_module( $post );
		}

		return (bool) apply_filters( 'embed_drive_author_may_embed', $allowed, $post );
	}

	/**
	 * Whether a post could be the source of a module: directly, through a page builder that keeps
	 * its layout in custom fields, or through a synced pattern its author also controls.
	 */
	private static function post_mentions_module( \WP_Post $post ): bool {
		$text = $post->post_content . "\n" . $post->post_excerpt;
		foreach ( (array) get_post_meta( $post->ID ) as $values ) {
			$text .= "\n" . implode( "\n", array_map( 'strval', (array) $values ) );
		}

		return false !== stripos( $text, 'embed_drive' ) || false !== stripos( $text, 'embed-drive' ) || false !== stripos( $text, 'wp:block' );
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
