<?php
/**
 * Notices when Google stops answering properly and emails the site owner a daily summary.
 *
 * Only fixed categories, HTTP statuses and Google's one-word reasons are stored, so tokens, file
 * names and visitor input can't reach the database or the email. Lost accounts have their own email.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class Health {

	const OPTION = 'embed_drive_health';

	// A problem seen in fewer minutes than this is a blip, unless it builds up over the window.
	const MIN_MINUTES = 3;
	const WINDOW      = 7 * DAY_IN_SECONDS;

	const CATEGORIES = [ 'network', 'signin', 'auth', 'refused', 'server' ];

	/**
	 * Note a failure. Counted at most once a minute per category, so an outage on a busy site
	 * means a handful of database writes rather than one for every visitor.
	 */
	public static function record( string $category, string $detail = '' ): void {
		if ( ! in_array( $category, self::CATEGORIES, true ) || ! Settings::get( 'error_email' ) ) {
			return;
		}

		$health = (array) get_option( self::OPTION, [] );
		$now    = time();
		$entry  = (array) ( $health[ $category ] ?? [] );
		if ( $now - (int) ( $entry['last'] ?? 0 ) < MINUTE_IN_SECONDS ) {
			return;
		}

		$health[ $category ] = [
			'minutes' => (int) ( $entry['minutes'] ?? 0 ) + 1,
			'first'   => (int) ( $entry['first'] ?? $now ),
			'last'    => $now,
			'detail'  => $detail,
		];
		update_option( self::OPTION, $health, false );
	}

	/**
	 * Note an answer from Google. Other statuses, such as a missing file, are not a fault in the
	 * connection, and a visitor could cause them on purpose.
	 */
	public static function record_status( int $status, string $reason = '' ): void {
		$category = '';
		if ( 401 === $status ) {
			$category = 'auth';
		} elseif ( 403 === $status || 429 === $status ) {
			$category = 'refused';
		} elseif ( $status >= 500 ) {
			$category = 'server';
		}
		if ( '' === $category ) {
			return;
		}

		$reason = substr( (string) preg_replace( '/[^A-Za-z]/', '', $reason ), 0, 40 );
		self::record( $category, trim( 'HTTP ' . $status . ' ' . $reason ) );
	}

	/**
	 * Send the summary, if there is anything to report. Run daily.
	 */
	public static function send_summary(): void {
		$health = array_intersect_key( (array) get_option( self::OPTION, [] ), array_flip( self::CATEGORIES ) );
		if ( ! $health ) {
			return;
		}
		if ( ! Settings::get( 'error_email' ) ) {
			delete_option( self::OPTION );

			return;
		}

		if ( array_sum( array_column( $health, 'minutes' ) ) < self::MIN_MINUTES ) {
			if ( time() - min( array_column( $health, 'first' ) ) > self::WINDOW ) {
				delete_option( self::OPTION );
			}

			return;
		}

		$lines = [];
		foreach ( $health as $category => $entry ) {
			$lines[] = sprintf(
				/* translators: 1: what went wrong, 2: number of minutes, 3: date and time, 4: technical detail */
				__( "- %1\$s: in %2\$d separate minutes, most recently %3\$s. %4\$s", 'embed-drive' ),
				self::label( (string) $category ),
				(int) $entry['minutes'],
				wp_date( 'j M Y H:i T', (int) $entry['last'] ),
				(string) $entry['detail']
			);
		}

		$sent = wp_mail(
			Settings::notify_email(),
			sprintf( /* translators: %s: site name */ __( '[%s] Embed Drive had trouble with Google Drive', 'embed-drive' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: 1: list of problems, 2: site address, 3: plugin version, 4: settings URL */
				__( "Embed Drive could not always get Drive content from Google, so some visitors may have seen an error instead.\n\n%1\$s\n\nIf this happened once, it was probably a brief outage at Google or your host and needs nothing from you. If this email keeps coming, the problem has not gone away.\n\nSite: %2\$s\nEmbed Drive version: %3\$s\n\nYou can turn this email off in the plugin settings: %4\$s", 'embed-drive' ),
				implode( "\n", $lines ),
				home_url(),
				EMBED_DRIVE_VERSION,
				admin_url( 'admin.php?page=embed-drive' )
			)
		);

		// Try again tomorrow if the site couldn't send it.
		if ( $sent ) {
			delete_option( self::OPTION );
		}
	}

	private static function label( string $category ): string {
		$labels = [
			'network' => __( 'Could not connect to Google', 'embed-drive' ),
			'signin'  => __( 'Could not renew access to Google', 'embed-drive' ),
			'auth'    => __( 'Google did not accept the sign-in', 'embed-drive' ),
			'refused' => __( 'Google refused the request or asked the site to slow down', 'embed-drive' ),
			'server'  => __( 'Google had an internal error', 'embed-drive' ),
		];

		return $labels[ $category ];
	}
}
