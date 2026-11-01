<?php
/**
 * Admin screens: accounts and settings, shortcode builder, activity log.
 *
 * @package EmbedDrive
 */

namespace EmbedDrive;

defined( 'ABSPATH' ) || exit;

final class Admin {

	const PER_PAGE = 50;

	public static function boot(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_embed_drive_save', [ self::class, 'save' ] );
		add_action( 'admin_post_embed_drive_disconnect', [ self::class, 'disconnect' ] );
		add_action( 'admin_post_embed_drive_flush', [ self::class, 'flush' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
		add_action( 'admin_notices', [ self::class, 'lost_access_notice' ] );
	}

	public static function menu(): void {
		add_menu_page( 'Embed Drive', 'Embed Drive', 'edit_others_posts', 'embed-drive-builder', [ self::class, 'builder_page' ], 'dashicons-cloud', 81 );
		add_submenu_page( 'embed-drive-builder', __( 'Shortcode Builder', 'embed-drive' ), __( 'Shortcode Builder', 'embed-drive' ), 'edit_others_posts', 'embed-drive-builder', [ self::class, 'builder_page' ] );
		add_submenu_page( 'embed-drive-builder', __( 'Accounts & Settings', 'embed-drive' ), __( 'Accounts & Settings', 'embed-drive' ), 'manage_options', 'embed-drive', [ self::class, 'settings_page' ] );
		add_submenu_page( 'embed-drive-builder', __( 'Activity', 'embed-drive' ), __( 'Activity', 'embed-drive' ), 'manage_options', 'embed-drive-activity', [ self::class, 'activity_page' ] );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'embed-drive' ) ) {
			return;
		}
		wp_enqueue_style( 'embed-drive-admin' );
		if ( false !== strpos( $hook, 'embed-drive-builder' ) ) {
			wp_enqueue_script( 'embed-drive-builder', EMBED_DRIVE_URL . 'assets/js/builder.js', [ 'embed-drive-picker' ], EMBED_DRIVE_VERSION, true );
		}
	}

	public static function lost_access_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( Accounts::all() as $account ) {
			if ( 'lost' === $account['status'] ) {
				printf(
					'<div class="notice notice-error"><p>%s <a href="%s">%s</a></p></div>',
					esc_html( sprintf( /* translators: %s: Google account email */ __( 'Embed Drive lost access to %s, so its content is not showing on your site.', 'embed-drive' ), $account['email'] ) ),
					esc_url( admin_url( 'admin.php?page=embed-drive' ) ),
					esc_html__( 'Sign in again', 'embed-drive' )
				);
			}
		}
	}

	public static function settings_page(): void {
		$settings = Settings::all();
		$relay    = Settings::relay_url();
		$has_app  = Settings::has_own_app();
		$start    = static fn( string $via ) => wp_nonce_url( admin_url( 'admin-post.php?action=embed_drive_oauth_start&via=' . $via ), 'embed_drive_oauth_start' );
		?>
		<div class="wrap embed-drive-admin">
			<h1><?php esc_html_e( 'Embed Drive', 'embed-drive' ); ?></h1>
			<?php self::print_flash(); ?>

			<h2><?php esc_html_e( 'Google accounts', 'embed-drive' ); ?></h2>
			<?php if ( Accounts::all() ) : ?>
				<table class="widefat striped embed-drive-accounts">
					<thead><tr><th><?php esc_html_e( 'Account', 'embed-drive' ); ?></th><th><?php esc_html_e( 'Status', 'embed-drive' ); ?></th><th><?php esc_html_e( 'Connected through', 'embed-drive' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( Accounts::all() as $account ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $account['name'] ); ?></strong><br><?php echo esc_html( $account['email'] ); ?></td>
							<td><?php echo 'ok' === $account['status'] ? esc_html__( 'Connected', 'embed-drive' ) : '<strong class="embed-drive-lost">' . esc_html__( 'Access lost: sign in again', 'embed-drive' ) . '</strong>'; ?></td>
							<td><?php echo 'own' === $account['via'] ? esc_html__( 'Your Google app', 'embed-drive' ) : esc_html__( 'Sign-in relay', 'embed-drive' ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Disconnect this account? Modules that use it will stop showing files.', 'embed-drive' ); ?>">
									<input type="hidden" name="action" value="embed_drive_disconnect">
									<input type="hidden" name="account" value="<?php echo esc_attr( $account['id'] ); ?>">
									<?php wp_nonce_field( 'embed_drive_disconnect' ); ?>
									<button class="button-link-delete button-link"><?php esc_html_e( 'Disconnect', 'embed-drive' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No accounts are connected yet.', 'embed-drive' ); ?></p>
			<?php endif; ?>

			<p class="embed-drive-connect">
				<?php if ( $has_app ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $start( 'own' ) ); ?>"><?php esc_html_e( 'Sign in with Google', 'embed-drive' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $relay ) : ?>
					<a class="button" href="<?php echo esc_url( $start( 'relay' ) ); ?>"><?php esc_html_e( 'Sign in through the relay', 'embed-drive' ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( ! $has_app && '' === $relay ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'To connect your account, first create your own free Google app using the steps below, then sign in. You only do this once.', 'embed-drive' ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Embed Drive asks for read-only access. It cannot change or delete anything in your Drive.', 'embed-drive' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="embed_drive_save">
				<?php wp_nonce_field( 'embed_drive_save' ); ?>

				<details class="embed-drive-advanced" <?php echo $has_app ? '' : 'open'; ?>>
					<summary><?php esc_html_e( 'Your Google app', 'embed-drive' ); ?></summary>
					<p><?php esc_html_e( 'Your site talks to Google through an app you own, so your sign-in never passes through anyone else. It is free and takes about 10 minutes.', 'embed-drive' ); ?></p>
					<ol>
						<li>
							<?php
							printf(
								/* translators: %s: link to the Google Cloud console */
								esc_html__( 'Open the %s and create a project.', 'embed-drive' ),
								'<a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">' . esc_html__( 'Google Cloud console', 'embed-drive' ) . '</a>'
							);
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: %s: link to the Google Drive API page */
								esc_html__( 'Enable the %s for that project.', 'embed-drive' ),
								'<a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" rel="noopener">' . esc_html__( 'Google Drive API', 'embed-drive' ) . '</a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Open the OAuth consent screen (Google Auth Platform) and fill in the app name and your email. Choose External, or Internal if your Google Workspace organisation will be the only one using it.', 'embed-drive' ); ?></li>
						<li><?php esc_html_e( 'Under Data Access, add the scope below. For an External app, publish it under Audience: in Testing mode your sign-in expires after 7 days.', 'embed-drive' ); ?><br><code>https://www.googleapis.com/auth/drive.readonly</code></li>
						<li><?php esc_html_e( 'Under Clients, create an OAuth client of type "Web application" and add this as an authorized redirect URI:', 'embed-drive' ); ?><br><code><?php echo esc_html( OAuth::callback_url() ); ?></code></li>
						<li><?php esc_html_e( 'Paste its client ID and secret here and save. Then click Sign in with Google above.', 'embed-drive' ); ?></li>
					</ol>
					<p class="description"><?php esc_html_e( 'An External app shows "Google hasn\'t verified this app" when you sign in. As it is your own app, that is expected: choose Advanced, then continue to your app. Google allows up to 100 people to do this per app.', 'embed-drive' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ed-client-id"><?php esc_html_e( 'Client ID', 'embed-drive' ); ?></label></th>
							<td><input id="ed-client-id" name="client_id" type="text" class="large-text" autocomplete="off" value="<?php echo esc_attr( $settings['client_id'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ed-client-secret"><?php esc_html_e( 'Client secret', 'embed-drive' ); ?></label></th>
							<td><input id="ed-client-secret" name="client_secret" type="password" class="large-text" autocomplete="new-password" placeholder="<?php echo '' !== $settings['client_secret'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'embed-drive' ) : ''; ?>"></td>
						</tr>
					</table>
				</details>

				<h2><?php esc_html_e( 'Downloads', 'embed-drive' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ed-zip-mb"><?php esc_html_e( 'Largest ZIP (MB)', 'embed-drive' ); ?></label></th>
						<td><input id="ed-zip-mb" name="zip_max_mb" type="number" min="1" max="4000" class="small-text" value="<?php echo esc_attr( $settings['zip_max_mb'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ed-zip-files"><?php esc_html_e( 'Most files in a ZIP', 'embed-drive' ); ?></label></th>
						<td><input id="ed-zip-files" name="zip_max_files" type="number" min="1" max="65000" class="small-text" value="<?php echo esc_attr( $settings['zip_max_files'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Activity log', 'embed-drive' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Logging', 'embed-drive' ); ?></th>
						<td><label><input name="log_enabled" type="checkbox" value="1" <?php checked( $settings['log_enabled'] ); ?>> <?php esc_html_e( 'Record previews, downloads and searches', 'embed-drive' ); ?></label>
						<p class="description"><?php esc_html_e( 'Stored only in your database. Records the WordPress user if logged in. Search terms are stored; IP addresses are not.', 'embed-drive' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ed-log-days"><?php esc_html_e( 'Keep entries for (days)', 'embed-drive' ); ?></label></th>
						<td><input id="ed-log-days" name="log_days" type="number" min="1" max="3650" class="small-text" value="<?php echo esc_attr( $settings['log_days'] ); ?>"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Notifications', 'embed-drive' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ed-email"><?php esc_html_e( 'Lost-access email', 'embed-drive' ); ?></label></th>
						<td><input id="ed-email" name="notify_email" type="email" class="regular-text" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" value="<?php echo esc_attr( $settings['notify_email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Sent once if a Google account stops working. Leave empty to use the site admin email.', 'embed-drive' ); ?></p></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="embed_drive_flush">
				<?php wp_nonce_field( 'embed_drive_flush' ); ?>
				<p><button class="button"><?php esc_html_e( 'Clear file cache', 'embed-drive' ); ?></button>
				<span class="description"><?php esc_html_e( 'Drive changes appear on your site within 5 minutes. Use this to see them straight away.', 'embed-drive' ); ?></span></p>
			</form>
		</div>
		<?php
	}

	public static function builder_page(): void {
		?>
		<div class="wrap embed-drive-admin">
			<h1><?php esc_html_e( 'Shortcode Builder', 'embed-drive' ); ?></h1>
			<?php if ( ! Accounts::all() ) : ?>
				<p>
					<?php esc_html_e( 'Connect a Google account first.', 'embed-drive' ); ?>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=embed-drive' ) ); ?>"><?php esc_html_e( 'Go to Accounts & Settings', 'embed-drive' ); ?></a>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<form id="embed-drive-builder" class="embed-drive-builder" onsubmit="return false">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ed-mode"><?php esc_html_e( 'Module', 'embed-drive' ); ?></label></th>
							<td><select id="ed-mode" name="mode">
								<option value="files"><?php esc_html_e( 'File browser', 'embed-drive' ); ?></option>
								<option value="search"><?php esc_html_e( 'Search box', 'embed-drive' ); ?></option>
								<option value="embed"><?php esc_html_e( 'Embed one file', 'embed-drive' ); ?></option>
								<option value="button"><?php esc_html_e( 'Download button', 'embed-drive' ); ?></option>
								<option value="link"><?php esc_html_e( 'Download link', 'embed-drive' ); ?></option>
							</select></td>
						</tr>
						<tr>
							<th scope="row"><label for="ed-account"><?php esc_html_e( 'Google account', 'embed-drive' ); ?></label></th>
							<td><select id="ed-account" name="account"></select></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Drive location', 'embed-drive' ); ?></th>
							<td><button type="button" class="button" id="ed-pick"><?php esc_html_e( 'Choose…', 'embed-drive' ); ?></button> <span id="ed-target"></span></td>
						</tr>
						<tr data-for="files">
							<th scope="row"><label for="ed-view"><?php esc_html_e( 'Layout', 'embed-drive' ); ?></label></th>
							<td><select id="ed-view" name="view">
								<option value="grid"><?php esc_html_e( 'Grid', 'embed-drive' ); ?></option>
								<option value="list"><?php esc_html_e( 'List', 'embed-drive' ); ?></option>
							</select></td>
						</tr>
						<tr data-for="files">
							<th scope="row"><label for="ed-sort"><?php esc_html_e( 'Sort by', 'embed-drive' ); ?></label></th>
							<td><select id="ed-sort" name="sort">
								<option value="name"><?php esc_html_e( 'Name', 'embed-drive' ); ?></option>
								<option value="modified"><?php esc_html_e( 'Modified', 'embed-drive' ); ?></option>
								<option value="size"><?php esc_html_e( 'Size', 'embed-drive' ); ?></option>
							</select>
							<select name="order" aria-label="<?php esc_attr_e( 'Direction', 'embed-drive' ); ?>">
								<option value="asc"><?php esc_html_e( 'Ascending', 'embed-drive' ); ?></option>
								<option value="desc"><?php esc_html_e( 'Descending', 'embed-drive' ); ?></option>
							</select></td>
						</tr>
						<tr data-for="files search embed">
							<th scope="row"><?php esc_html_e( 'Options', 'embed-drive' ); ?></th>
							<td>
								<label data-for="files search embed"><input type="checkbox" name="download" checked> <?php esc_html_e( 'Allow downloads', 'embed-drive' ); ?></label><br data-for="files search embed">
								<label data-for="files"><input type="checkbox" name="zip" checked> <?php esc_html_e( 'Allow ZIP downloads', 'embed-drive' ); ?></label><br data-for="files">
								<label data-for="files"><input type="checkbox" name="search" checked> <?php esc_html_e( 'Show search box', 'embed-drive' ); ?></label><br data-for="files">
								<label data-for="files search"><input type="checkbox" name="preview" checked> <?php esc_html_e( 'Allow previews', 'embed-drive' ); ?></label>
							</td>
						</tr>
						<tr data-for="button link">
							<th scope="row"><label for="ed-label"><?php esc_html_e( 'Label', 'embed-drive' ); ?></label></th>
							<td><input id="ed-label" name="label" type="text" class="regular-text"></td>
						</tr>
						<tr data-for="embed">
							<th scope="row"><label for="ed-height"><?php esc_html_e( 'Height for documents (px)', 'embed-drive' ); ?></label></th>
							<td><input id="ed-height" name="height" type="number" min="100" max="3000" value="600" class="small-text"></td>
						</tr>
					</table>
					<h2><?php esc_html_e( 'Your shortcode', 'embed-drive' ); ?></h2>
					<p><textarea id="ed-output" class="large-text code" rows="3" readonly></textarea></p>
					<p><button type="button" class="button button-primary" id="ed-copy" disabled><?php esc_html_e( 'Copy shortcode', 'embed-drive' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function activity_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$events = [
			'preview'  => __( 'Preview', 'embed-drive' ),
			'download' => __( 'Download', 'embed-drive' ),
			'zip'      => __( 'ZIP', 'embed-drive' ),
			'search'   => __( 'Search', 'embed-drive' ),
		];
		$event  = isset( $_GET['event'], $events[ $_GET['event'] ] ) ? sanitize_key( $_GET['event'] ) : '';
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable

		$data    = ActivityLog::page( $event, $page, self::PER_PAGE );
		$summary = ActivityLog::summary();
		$base    = admin_url( 'admin.php?page=embed-drive-activity' );
		?>
		<div class="wrap embed-drive-admin">
			<h1><?php esc_html_e( 'Activity', 'embed-drive' ); ?></h1>
			<?php if ( ! Settings::get( 'log_enabled' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Logging is turned off, so new activity is not being recorded.', 'embed-drive' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Last 30 days', 'embed-drive' ); ?></h2>
			<ul class="embed-drive-stats">
				<?php foreach ( $events as $key => $label ) : ?>
					<li><strong><?php echo esc_html( number_format_i18n( (int) ( $summary['counts'][ $key ] ?? 0 ) ) ); ?></strong> <?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ul>

			<?php if ( $summary['top'] ) : ?>
				<h2><?php esc_html_e( 'Most downloaded', 'embed-drive' ); ?></h2>
				<table class="widefat striped embed-drive-top">
					<thead><tr><th><?php esc_html_e( 'File', 'embed-drive' ); ?></th><th><?php esc_html_e( 'Downloads', 'embed-drive' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $summary['top'] as $row ) : ?>
						<tr><td><?php echo esc_html( $row['detail'] ); ?></td><td><?php echo esc_html( number_format_i18n( (int) $row['total'] ) ); ?></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'All activity', 'embed-drive' ); ?></h2>
			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $base ); ?>" class="<?php echo '' === $event ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'embed-drive' ); ?></a> |&nbsp;</li>
				<?php foreach ( $events as $key => $label ) : ?>
					<li><a href="<?php echo esc_url( add_query_arg( 'event', $key, $base ) ); ?>" class="<?php echo $key === $event ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a>&nbsp;</li>
				<?php endforeach; ?>
			</ul>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'When', 'embed-drive' ); ?></th><th><?php esc_html_e( 'Event', 'embed-drive' ); ?></th><th><?php esc_html_e( 'File or search', 'embed-drive' ); ?></th><th><?php esc_html_e( 'User', 'embed-drive' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $data['rows'] ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Nothing recorded yet.', 'embed-drive' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $data['rows'] as $row ) : ?>
					<?php $user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : false; ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
						<td><?php echo esc_html( $events[ $row['event'] ] ?? $row['event'] ); ?></td>
						<td><?php echo esc_html( $row['detail'] ); ?></td>
						<td><?php echo esc_html( $user ? $user->user_login : __( 'Visitor', 'embed-drive' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					[
						'base'    => add_query_arg( 'paged', '%#%', '' === $event ? $base : add_query_arg( 'event', $event, $base ) ),
						'format'  => '',
						'current' => $page,
						'total'   => (int) ceil( $data['total'] / self::PER_PAGE ),
					]
				)
			);
			echo '</div></div>';
			?>
		</div>
		<?php
	}

	public static function save(): void {
		self::guard( 'embed_drive_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$secret = isset( $_POST['client_secret'] ) ? trim( (string) wp_unslash( $_POST['client_secret'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A secret must stay byte-exact.
		$values = [
			'client_id'     => sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) ),
			'log_enabled'   => ! empty( $_POST['log_enabled'] ),
			'log_days'      => max( 1, min( 3650, absint( $_POST['log_days'] ?? 90 ) ) ),
			'zip_max_mb'    => max( 1, min( 4000, absint( $_POST['zip_max_mb'] ?? 1024 ) ) ),
			'zip_max_files' => max( 1, min( 65000, absint( $_POST['zip_max_files'] ?? 200 ) ) ),
			'notify_email'  => sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ),
		];
		// phpcs:enable
		if ( '' !== $secret ) {
			$values['client_secret'] = Crypto::encrypt( $secret );
		}

		Settings::update( $values );
		self::back( 'saved', '' );
	}

	public static function disconnect(): void {
		self::guard( 'embed_drive_disconnect' );
		Accounts::disconnect( sanitize_text_field( wp_unslash( $_POST['account'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().
		self::back( 'disconnected', '' );
	}

	public static function flush(): void {
		self::guard( 'embed_drive_flush' );
		Drive::flush_cache();
		self::back( 'flushed', '' );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'embed-drive' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( $action );
	}

	private static function back( string $result, string $detail ): void {
		set_transient( 'embed_drive_notice_' . get_current_user_id(), [ $result, $detail ], MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=embed-drive' ) );
		exit;
	}

	private static function print_flash(): void {
		$key   = 'embed_drive_notice_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $key );

		[ $result, $detail ] = $flash;
		$messages            = [
			/* translators: %s: Google account email */
			'connected'    => sprintf( __( 'Connected %s.', 'embed-drive' ), $detail ),
			'saved'        => __( 'Settings saved.', 'embed-drive' ),
			'disconnected' => __( 'Account disconnected.', 'embed-drive' ),
			'flushed'      => __( 'File cache cleared.', 'embed-drive' ),
			'error'        => $detail,
		];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $result ? 'error' : 'success',
			esc_html( $messages[ $result ] ?? '' )
		);
	}
}
