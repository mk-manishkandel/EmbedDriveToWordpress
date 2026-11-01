<?php
/**
 * Read-only Google Drive API client with short-lived caching.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Drive {

	const FOLDER_MIME   = 'application/vnd.google-apps.folder';
	const SHORTCUT_MIME = 'application/vnd.google-apps.shortcut';
	const FIELDS        = 'id,name,mimeType,size,modifiedTime,thumbnailLink,parents,permissionIds,trashed';
	const MAX_DEPTH     = 64;
	const SORTS         = [
		'name'     => 'name',
		'modified' => 'modifiedTime',
		'size'     => 'quotaBytesUsed',
	];

	private string $account;

	public function __construct( string $account ) {
		$this->account = $account;
	}

	/**
	 * @return array|WP_Error
	 */
	public function get_file( string $id ) {
		$file = $this->remember(
			"file:$id",
			fn() => $this->request( '/files/' . rawurlencode( $id ), [ 'fields' => self::FIELDS ] )
		);

		// Google still answers for a file in the trash, but its owner has taken it away.
		return ! is_wp_error( $file ) && ! empty( $file['trashed'] )
			? new WP_Error( 'not_found', __( 'File not found.', 'embed-drive' ), [ 'status' => 404 ] )
			: $file;
	}

	/**
	 * @return array|WP_Error `files` and `nextPageToken`.
	 */
	public function list_children( string $folder, string $sort = 'name', string $direction = 'asc', string $page_token = '', int $page_size = 100, bool $folders_only = false ) {
		$field = self::SORTS[ $sort ] ?? 'name';
		$order = 'folder,' . $field . ( 'desc' === $direction ? ' desc' : '' );
		if ( 'name' === $field ) {
			$order = 'folder,name_natural' . ( 'desc' === $direction ? ' desc' : '' );
		}

		$query = sprintf( "'%s' in parents and %s", self::escape( $folder ), self::base_filter() );
		if ( $folders_only ) {
			$query .= " and mimeType = '" . self::FOLDER_MIME . "'";
		}

		return $this->remember(
			'list:' . md5( $query . $order . $page_token . $page_size ),
			fn() => $this->request(
				'/files',
				[
					'q'         => $query,
					'orderBy'   => $order,
					'pageSize'  => $page_size,
					'pageToken' => $page_token,
					'fields'    => 'nextPageToken,files(' . self::FIELDS . ')',
				]
			)
		);
	}

	/**
	 * Search a folder and its subfolders. The Drive API can only filter on direct parents,
	 * so this walks the tree first and queries the folders in batches.
	 *
	 * @return array|WP_Error `files` and `truncated`.
	 */
	public function search_tree( string $root, string $term, int $limit = 50 ) {
		$tree = $this->folder_tree( $root );
		if ( is_wp_error( $tree ) ) {
			return $tree;
		}

		$escaped = self::escape( $term );
		$found   = [];
		foreach ( array_chunk( $tree['folders'], 40 ) as $batch ) {
			$parents = implode( ' or ', array_map( static fn( $id ) => "'" . self::escape( $id ) . "' in parents", $batch ) );
			$result  = $this->request(
				'/files',
				[
					'q'        => "($parents) and (name contains '$escaped' or fullText contains '$escaped') and " . self::base_filter(),
					'pageSize' => $limit,
					'fields'   => 'files(' . self::FIELDS . ')',
				]
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( (array) ( $result['files'] ?? [] ) as $file ) {
				$found[ $file['id'] ] = $file;
			}
			if ( count( $found ) >= $limit ) {
				break;
			}
		}

		$files = array_values( $found );
		usort( $files, static fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );

		return [
			'files'     => array_slice( $files, 0, $limit ),
			'truncated' => $tree['truncated'] || count( $files ) > $limit,
		];
	}

	/**
	 * @return array|WP_Error `folders` (ids, including the root) and `truncated`.
	 */
	public function folder_tree( string $root, int $max = 300 ) {
		return $this->remember(
			"tree:$root:$max",
			function () use ( $root, $max ) {
				$folders = [ $root ];
				$queue   = [ $root ];
				while ( $queue ) {
					$current = array_shift( $queue );
					$token   = '';
					do {
						$page = $this->list_children( $current, 'name', 'asc', $token, 200, true );
						if ( is_wp_error( $page ) ) {
							return $page;
						}
						foreach ( (array) ( $page['files'] ?? [] ) as $child ) {
							if ( in_array( $child['id'], $folders, true ) ) {
								continue;
							}
							if ( count( $folders ) >= $max ) {
								return [ 'folders' => $folders, 'truncated' => true ];
							}
							$folders[] = $child['id'];
							$queue[]   = $child['id'];
						}
						$token = (string) ( $page['nextPageToken'] ?? '' );
					} while ( '' !== $token );
				}

				return [ 'folders' => $folders, 'truncated' => false ];
			}
		);
	}

	/**
	 * The chain of folders from $root down to $id, or an empty array if $id is not inside $root.
	 * This is the check that stops a visitor reaching anything outside a module's folder.
	 *
	 * @return array|WP_Error
	 */
	public function path_from_root( string $root, string $id ) {
		$root_file = $this->get_file( $root );
		if ( is_wp_error( $root_file ) ) {
			return $root_file;
		}

		$file = $this->get_file( $id );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		// Drive items can have several parents in older accounts, so try every route upward.
		$stack = [ [ $file, [ $file ] ] ];
		$seen  = [];
		while ( $stack ) {
			[ $node, $path ] = array_pop( $stack );
			if ( $node['id'] === $root_file['id'] ) {
				return array_reverse( $path );
			}
			if ( isset( $seen[ $node['id'] ] ) || count( $path ) > self::MAX_DEPTH ) {
				continue;
			}
			$seen[ $node['id'] ] = true;

			foreach ( (array) ( $node['parents'] ?? [] ) as $parent_id ) {
				$parent = $this->get_file( $parent_id );
				if ( is_wp_error( $parent ) ) {
					continue; // A parent we can't read can't lead to the root.
				}
				$stack[] = [ $parent, array_merge( $path, [ $parent ] ) ];
			}
		}

		return [];
	}

	/**
	 * @return array|WP_Error Top-level locations for the admin folder picker.
	 */
	public function shared_drives() {
		return $this->remember(
			'drives',
			fn() => $this->request( '/drives', [ 'pageSize' => 100, 'fields' => 'drives(id,name)' ], false )
		);
	}

	/**
	 * @return array|WP_Error
	 */
	public function shared_with_me() {
		return $this->remember(
			'shared',
			fn() => $this->request(
				'/files',
				[
					'q'        => 'sharedWithMe = true and ' . self::base_filter(),
					'orderBy'  => 'folder,name_natural',
					'pageSize' => 200,
					'fields'   => 'files(' . self::FIELDS . ')',
				]
			)
		);
	}

	public static function is_folder( array $file ): bool {
		return self::FOLDER_MIME === ( $file['mimeType'] ?? '' );
	}

	/**
	 * Whether anyone with the link can open this file at Google, without signing in.
	 */
	public static function is_link_shared( array $file ): bool {
		return (bool) array_intersect( [ 'anyoneWithLink', 'anyone' ], (array) ( $file['permissionIds'] ?? [] ) );
	}

	public static function flush_cache(): void {
		update_option( 'embed_drive_cache_gen', time(), false );
	}

	private static function escape( string $value ): string {
		return str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value );
	}

	/**
	 * Shortcuts are hidden: they can point outside a module's folder.
	 */
	private static function base_filter(): string {
		return "trashed = false and mimeType != '" . self::SHORTCUT_MIME . "'";
	}

	/**
	 * @return array|WP_Error
	 */
	private function remember( string $key, callable $producer ) {
		$ttl       = (int) apply_filters( 'embed_drive_cache_ttl', 5 * MINUTE_IN_SECONDS );
		$cache_key = 'embed_drive_' . md5( $this->account . '|' . get_option( 'embed_drive_cache_gen', 0 ) . '|' . $key );

		$cached = $ttl > 0 ? get_transient( $cache_key ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$value = $producer();
		if ( ! is_wp_error( $value ) && $ttl > 0 ) {
			set_transient( $cache_key, $value, $ttl );
		}

		return $value;
	}

	/**
	 * @return array|WP_Error
	 */
	private function request( string $path, array $query, bool $all_drives = true ) {
		if ( $all_drives ) {
			$query['supportsAllDrives'] = 'true';
			if ( '/files' === $path ) {
				$query['includeItemsFromAllDrives'] = 'true';
			}
		}
		$query = array_filter( $query, static fn( $v ) => '' !== $v );
		$url   = OAuth::endpoint( 'drive' ) . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$token = Accounts::access_token( $this->account, $attempt > 0 );
			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$response = wp_remote_get(
				$url,
				[
					'timeout' => 20,
					'headers' => [ 'Authorization' => 'Bearer ' . $token ],
				]
			);
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'network', __( 'Could not reach Google Drive.', 'embed-drive' ) );
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 401 !== $status ) {
				break;
			}
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === $status && is_array( $data ) ) {
			return $data;
		}

		return new WP_Error(
			404 === $status ? 'not_found' : 'drive_error',
			404 === $status ? __( 'File not found.', 'embed-drive' ) : __( 'Google Drive returned an error.', 'embed-drive' ),
			[ 'status' => $status ]
		);
	}
}
