<?php
/**
 * WordPress's personal data tools. The activity log is the only place the plugin stores anything
 * about a person: what a logged-in user previewed, downloaded or searched for.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class Privacy {

	const PER_PAGE = 500;

	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_eraser' ] );
		add_action( 'admin_init', [ self::class, 'policy_text' ] );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['embed-drive'] = [
			'exporter_friendly_name' => __( 'FileFolio activity log', 'filefolio' ),
			'callback'               => [ self::class, 'export' ],
		];

		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['embed-drive'] = [
			'eraser_friendly_name' => __( 'FileFolio activity log', 'filefolio' ),
			'callback'             => [ self::class, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * @return array{data: array, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [ 'data' => [], 'done' => true ];
		}

		$events = [
			'preview'  => __( 'Previewed', 'filefolio' ),
			'download' => __( 'Downloaded', 'filefolio' ),
			'zip'      => __( 'Downloaded a ZIP', 'filefolio' ),
			'search'   => __( 'Searched', 'filefolio' ),
		];
		$rows   = ActivityLog::for_user( (int) $user->ID, max( 1, $page ), self::PER_PAGE );
		$data   = [];
		foreach ( $rows as $row ) {
			$data[] = [
				'group_id'    => 'embed-drive-activity',
				'group_label' => __( 'FileFolio activity', 'filefolio' ),
				'item_id'     => 'embed-drive-activity-' . $row['id'],
				'data'        => [
					[ 'name' => __( 'Date (UTC)', 'filefolio' ), 'value' => $row['created_at'] ],
					[ 'name' => __( 'Action', 'filefolio' ), 'value' => $events[ $row['event'] ] ?? $row['event'] ],
					[ 'name' => __( 'File or search', 'filefolio' ), 'value' => $row['detail'] ],
				],
			];
		}

		return [ 'data' => $data, 'done' => count( $rows ) < self::PER_PAGE ];
	}

	/**
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$user    = get_user_by( 'email', $email );
		$removed = $user ? ActivityLog::erase_user( (int) $user->ID ) : 0;

		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * Suggested wording for the site's privacy policy, under Settings > Privacy.
	 */
	public static function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			'FileFolio',
			wp_kses_post(
				wpautop(
					__( 'This site shows files from Google Drive. The files are passed on through this site, so your browser does not connect to Google for them, except for previews of files that are already shared publicly on Google Drive.', 'filefolio' )
					. "\n\n"
					. __( 'If the activity log is turned on, this site records when a file is previewed or downloaded and what is searched for, with the date and, if you are logged in, your user account. Your IP address is not recorded. Entries are deleted automatically after the number of days the site owner chooses.', 'filefolio' )
				)
			)
		);
	}
}
