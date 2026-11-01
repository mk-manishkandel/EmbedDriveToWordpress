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

	const GLYPHS = [
		'folder'   => 'M3 7a2 2 0 0 1 2-2h4.2l2 2.2H19a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
		'file'     => 'M7 3h7l5 5v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM14 3v5h5',
		'search'   => 'M10.5 4a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zM15.5 15.5L20 20',
		'download' => 'M12 4v11M7.5 11l4.5 4.5 4.5-4.5M5 20h14',
		'link'     => 'M10 14a4 4 0 0 0 6 .4l3-3a4 4 0 0 0-5.7-5.7l-1.3 1.3M14 10a4 4 0 0 0-6-.4l-3 3a4 4 0 0 0 5.7 5.7l1.3-1.3',
		'eye'      => 'M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12zM12 9.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z',
		'zip'      => 'M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM12 3v2M12 7v2M12 11v2M10.5 15h3v3h-3z',
		'tick'     => 'M5 12.5l4.5 4.5L19 7.5',
		'alert'    => 'M12 4l9 16H3zM12 10v4.5M12 17.2v.01',
		'chevron'  => 'M10 7l5 5-5 5',
	];

	const ICON_PATH = 'M2 5.5A1.5 1.5 0 0 1 3.5 4h4.2l1.8 2h7A1.5 1.5 0 0 1 18 7.5v8a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 2 15.5zM7.7 8.6 4.8 11.5l2.9 2.9 1-1-1.9-1.9 1.9-1.9zM12.3 8.6l-1 1 1.9 1.9-1.9 1.9 1 1 2.9-2.9z';

	const MENU_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="%s"/></svg>';

	public static function boot(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_embed_drive_save', [ self::class, 'save' ] );
		add_action( 'admin_post_embed_drive_disconnect', [ self::class, 'disconnect' ] );
		add_action( 'admin_post_embed_drive_flush', [ self::class, 'flush' ] );
		add_action( 'admin_post_embed_drive_reset_links', [ self::class, 'reset_links' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ] );
		add_action( 'admin_notices', [ self::class, 'lost_access_notice' ] );
	}

	public static function menu(): void {
		add_menu_page( 'Embed Drive', 'Embed Drive', Modules::embed_capability(), 'embed-drive-builder', [ self::class, 'builder_page' ], 'data:image/svg+xml;base64,' . base64_encode( sprintf( self::MENU_ICON, self::ICON_PATH ) ), 81 );
		add_submenu_page( 'embed-drive-builder', __( 'Shortcode Builder', 'embed-drive' ), __( 'Shortcode Builder', 'embed-drive' ), Modules::embed_capability(), 'embed-drive-builder', [ self::class, 'builder_page' ] );
		add_submenu_page( 'embed-drive-builder', __( 'Accounts & Settings', 'embed-drive' ), __( 'Accounts & Settings', 'embed-drive' ), 'manage_options', 'embed-drive', [ self::class, 'settings_page' ] );
		add_submenu_page( 'embed-drive-builder', __( 'Activity', 'embed-drive' ), __( 'Activity', 'embed-drive' ), 'manage_options', 'embed-drive-activity', [ self::class, 'activity_page' ] );
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'embed-drive' ) ) {
			return;
		}
		wp_enqueue_style( 'embed-drive-admin' );
		wp_enqueue_script( 'embed-drive-admin', EMBED_DRIVE_URL . 'assets/js/admin.js', [ 'wp-i18n' ], EMBED_DRIVE_VERSION, true );
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
		$accounts = Accounts::all();
		$start    = static fn( string $via ) => wp_nonce_url( admin_url( 'admin-post.php?action=embed_drive_oauth_start&via=' . $via ), 'embed_drive_oauth_start' );
		self::open_page( 'embed-drive', __( 'Accounts & Settings', 'embed-drive' ) );
		self::print_flash();
		?>
		<section class="ed-card">
			<h2 class="ed-card__title"><?php esc_html_e( 'Google accounts', 'embed-drive' ); ?></h2>
			<p class="ed-hint"><?php esc_html_e( 'Embed Drive asks for read-only access. It cannot change or delete anything in your Drive.', 'embed-drive' ); ?></p>
			<?php if ( $accounts ) : ?>
				<ul class="ed-accounts">
				<?php foreach ( $accounts as $account ) : ?>
					<li class="ed-account">
						<span class="ed-account__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( (string) ( $account['name'] ?: $account['email'] ), 0, 1 ) ); ?></span>
						<span class="ed-account__who">
							<strong><?php echo esc_html( $account['name'] ); ?></strong>
							<span><?php echo esc_html( $account['email'] ); ?></span>
						</span>
						<span class="ed-account__via"><?php echo 'own' === $account['via'] ? esc_html__( 'Your Google app', 'embed-drive' ) : esc_html__( 'Sign-in relay', 'embed-drive' ); ?></span>
						<?php if ( 'ok' === $account['status'] ) : ?>
							<span class="ed-pill ed-pill--good"><?php self::glyph( 'tick' ); ?><?php esc_html_e( 'Connected', 'embed-drive' ); ?></span>
						<?php else : ?>
							<span class="ed-pill ed-pill--bad"><?php self::glyph( 'alert' ); ?><?php esc_html_e( 'Access lost: sign in again', 'embed-drive' ); ?></span>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Disconnect this account? Modules that use it will stop showing files.', 'embed-drive' ); ?>">
							<input type="hidden" name="action" value="embed_drive_disconnect">
							<input type="hidden" name="account" value="<?php echo esc_attr( $account['id'] ); ?>">
							<?php wp_nonce_field( 'embed_drive_disconnect' ); ?>
							<button class="ed-danger-link"><?php esc_html_e( 'Disconnect', 'embed-drive' ); ?></button>
						</form>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="ed-none"><?php esc_html_e( 'No accounts are connected yet.', 'embed-drive' ); ?></p>
			<?php endif; ?>

			<?php if ( $has_app || '' !== $relay ) : ?>
				<p class="ed-actions">
					<?php if ( $has_app ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $start( 'own' ) ); ?>"><?php esc_html_e( 'Sign in with Google', 'embed-drive' ); ?></a>
					<?php endif; ?>
					<?php if ( '' !== $relay ) : ?>
						<a class="button" href="<?php echo esc_url( $start( 'relay' ) ); ?>"><?php esc_html_e( 'Sign in through the relay', 'embed-drive' ); ?></a>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p class="ed-callout"><?php esc_html_e( 'To connect your account, first create your own free Google app using the steps below, then sign in. You only do this once.', 'embed-drive' ); ?></p>
			<?php endif; ?>
		</section>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ed-stack">
			<input type="hidden" name="action" value="embed_drive_save">
			<?php wp_nonce_field( 'embed_drive_save' ); ?>

			<details class="embed-drive-advanced" <?php echo $has_app ? '' : 'open'; ?>>
				<summary>
					<?php self::glyph( 'chevron' ); ?>
					<span class="ed-card__title"><?php esc_html_e( 'Your Google app', 'embed-drive' ); ?></span>
					<?php if ( $has_app ) : ?>
						<span class="ed-pill ed-pill--good"><?php self::glyph( 'tick' ); ?><?php esc_html_e( 'Set up', 'embed-drive' ); ?></span>
					<?php endif; ?>
				</summary>
				<div class="ed-details">
					<p><?php esc_html_e( 'Your site talks to Google through an app you own, so your sign-in never passes through anyone else. It is free and takes about 10 minutes.', 'embed-drive' ); ?></p>
					<ol class="ed-steps">
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
						<li>
							<?php esc_html_e( 'Under Data Access, add the scope below. For an External app, publish it under Audience: in Testing mode your sign-in expires after 7 days.', 'embed-drive' ); ?>
							<?php self::copyable( 'https://www.googleapis.com/auth/drive.readonly' ); ?>
						</li>
						<li>
							<?php esc_html_e( 'Under Clients, create an OAuth client of type "Web application" and add this as an authorized redirect URI:', 'embed-drive' ); ?>
							<?php self::copyable( OAuth::callback_url() ); ?>
						</li>
						<li><?php esc_html_e( 'Paste its client ID and secret here and save. Then click Sign in with Google above.', 'embed-drive' ); ?></li>
					</ol>
					<p class="ed-hint"><?php esc_html_e( 'An External app shows "Google hasn\'t verified this app" when you sign in. As it is your own app, that is expected: choose Advanced, then continue to your app. Google allows up to 100 people to do this per app.', 'embed-drive' ); ?></p>
					<div class="ed-fields">
						<div class="ed-field">
							<label for="ed-client-id"><?php esc_html_e( 'Client ID', 'embed-drive' ); ?></label>
							<input id="ed-client-id" name="client_id" type="text" autocomplete="off" value="<?php echo esc_attr( $settings['client_id'] ); ?>">
						</div>
						<div class="ed-field">
							<label for="ed-client-secret"><?php esc_html_e( 'Client secret', 'embed-drive' ); ?></label>
							<input id="ed-client-secret" name="client_secret" type="password" autocomplete="new-password" placeholder="<?php echo '' !== $settings['client_secret'] ? esc_attr__( 'Saved. Leave empty to keep it.', 'embed-drive' ) : ''; ?>">
						</div>
					</div>
				</div>
			</details>

			<div class="ed-grid">
				<section class="ed-card">
					<h2 class="ed-card__title"><?php esc_html_e( 'Downloads', 'embed-drive' ); ?></h2>
					<div class="ed-fields">
						<div class="ed-field">
							<label for="ed-zip-mb"><?php esc_html_e( 'Largest ZIP (MB)', 'embed-drive' ); ?></label>
							<input id="ed-zip-mb" name="zip_max_mb" type="number" min="1" max="4000" value="<?php echo esc_attr( $settings['zip_max_mb'] ); ?>">
						</div>
						<div class="ed-field">
							<label for="ed-zip-files"><?php esc_html_e( 'Most files in a ZIP', 'embed-drive' ); ?></label>
							<input id="ed-zip-files" name="zip_max_files" type="number" min="1" max="65000" value="<?php echo esc_attr( $settings['zip_max_files'] ); ?>">
						</div>
					</div>
				</section>

				<section class="ed-card">
					<h2 class="ed-card__title"><?php esc_html_e( 'Appearance', 'embed-drive' ); ?></h2>
					<div class="ed-field">
						<label for="ed-min-height"><?php esc_html_e( 'File browser minimum height (px)', 'embed-drive' ); ?></label>
						<input id="ed-min-height" name="min_height" type="number" min="0" max="3000" step="10" value="<?php echo esc_attr( $settings['min_height'] ); ?>">
						<p class="ed-hint"><?php esc_html_e( 'Keeps the file browser at least this tall, even with few files. Use 0 for no minimum. Set a different height for one file browser with min_height="500" in its shortcode, or in its block options.', 'embed-drive' ); ?></p>
					</div>
				</section>

				<section class="ed-card">
					<h2 class="ed-card__title"><?php esc_html_e( 'Activity log', 'embed-drive' ); ?></h2>
					<label class="ed-toggle"><input class="ed-switch" name="log_enabled" type="checkbox" value="1" <?php checked( $settings['log_enabled'] ); ?>> <?php esc_html_e( 'Record previews, downloads and searches', 'embed-drive' ); ?></label>
					<p class="ed-hint"><?php esc_html_e( 'Stored only in your database. Records the WordPress user if logged in. Search terms are stored; IP addresses are not.', 'embed-drive' ); ?></p>
					<div class="ed-field">
						<label for="ed-log-days"><?php esc_html_e( 'Keep entries for (days)', 'embed-drive' ); ?></label>
						<input id="ed-log-days" name="log_days" type="number" min="1" max="3650" value="<?php echo esc_attr( $settings['log_days'] ); ?>">
					</div>
				</section>

				<section class="ed-card">
					<h2 class="ed-card__title"><?php esc_html_e( 'Notifications', 'embed-drive' ); ?></h2>
					<div class="ed-field">
						<label for="ed-email"><?php esc_html_e( 'Lost-access email', 'embed-drive' ); ?></label>
						<input id="ed-email" name="notify_email" type="email" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" value="<?php echo esc_attr( $settings['notify_email'] ); ?>">
						<p class="ed-hint"><?php esc_html_e( 'Sent once if a Google account stops working. Leave empty to use the site admin email.', 'embed-drive' ); ?></p>
					</div>
				</section>
			</div>

			<div class="ed-savebar">
				<button class="button button-primary"><?php esc_html_e( 'Save Changes', 'embed-drive' ); ?></button>
			</div>
		</form>

		<section class="ed-card">
			<h2 class="ed-card__title"><?php esc_html_e( 'Maintenance', 'embed-drive' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ed-row">
				<input type="hidden" name="action" value="embed_drive_flush">
				<?php wp_nonce_field( 'embed_drive_flush' ); ?>
				<div>
					<strong><?php esc_html_e( 'File cache', 'embed-drive' ); ?></strong>
					<p class="ed-hint"><?php esc_html_e( 'Drive changes appear on your site within 5 minutes. Use this to see them straight away.', 'embed-drive' ); ?></p>
				</div>
				<button class="button"><?php esc_html_e( 'Clear file cache', 'embed-drive' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ed-row" onsubmit="return confirm(this.dataset.confirm)" data-confirm="<?php esc_attr_e( 'Reset all visitor links? Your pages keep working, but clear any page cache afterwards.', 'embed-drive' ); ?>">
				<input type="hidden" name="action" value="embed_drive_reset_links">
				<?php wp_nonce_field( 'embed_drive_reset_links' ); ?>
				<div>
					<strong><?php esc_html_e( 'Visitor links', 'embed-drive' ); ?></strong>
					<p class="ed-hint"><?php esc_html_e( 'Links to your files never expire. Reset them if one was shared where it should not be, or after you remove a module. Your pages keep working, but clear any page cache afterwards.', 'embed-drive' ); ?></p>
				</div>
				<button class="button"><?php esc_html_e( 'Reset visitor links', 'embed-drive' ); ?></button>
			</form>
		</section>
		<?php
		self::close_page();
	}

	public static function builder_page(): void {
		$modules = [
			'files'  => [ 'folder', __( 'File browser', 'embed-drive' ), __( 'Browse a folder', 'embed-drive' ) ],
			'search' => [ 'search', __( 'Search box', 'embed-drive' ), __( 'Search a folder', 'embed-drive' ) ],
			'embed'  => [ 'file', __( 'Embed one file', 'embed-drive' ), __( 'Show it on the page', 'embed-drive' ) ],
			'button' => [ 'download', __( 'Download button', 'embed-drive' ), __( 'A button for one file', 'embed-drive' ) ],
			'link'   => [ 'link', __( 'Download link', 'embed-drive' ), __( 'A text link for one file', 'embed-drive' ) ],
		];
		self::open_page( 'embed-drive-builder', __( 'Shortcode Builder', 'embed-drive' ) );
		?>
		<?php if ( ! Accounts::all() ) : ?>
			<section class="ed-card ed-empty">
				<?php self::glyph( 'folder' ); ?>
				<p><?php esc_html_e( 'Connect a Google account first.', 'embed-drive' ); ?></p>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=embed-drive' ) ); ?>"><?php esc_html_e( 'Go to Accounts & Settings', 'embed-drive' ); ?></a>
				<?php endif; ?>
			</section>
		<?php else : ?>
			<form id="embed-drive-builder" class="embed-drive-builder ed-builder" onsubmit="return false">
				<div class="ed-stack">
					<fieldset class="ed-card">
						<legend class="ed-card__title"><?php esc_html_e( 'Module', 'embed-drive' ); ?></legend>
						<div class="ed-choices">
							<?php foreach ( $modules as $value => [ $glyph, $title, $note ] ) : ?>
								<label class="ed-choice">
									<input type="radio" name="mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( 'files', $value ); ?>>
									<?php self::glyph( $glyph ); ?>
									<strong><?php echo esc_html( $title ); ?></strong>
									<small><?php echo esc_html( $note ); ?></small>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>

					<section class="ed-card">
						<h2 class="ed-card__title"><?php esc_html_e( 'Drive location', 'embed-drive' ); ?></h2>
						<div class="ed-field" id="ed-account-field">
							<label for="ed-account"><?php esc_html_e( 'Google account', 'embed-drive' ); ?></label>
							<select id="ed-account" name="account"></select>
						</div>
						<div class="ed-target">
							<?php self::glyph( 'folder' ); ?>
							<span id="ed-target"></span>
							<button type="button" class="button" id="ed-pick"><?php esc_html_e( 'Choose…', 'embed-drive' ); ?></button>
						</div>
					</section>

					<section class="ed-card">
						<h2 class="ed-card__title"><?php esc_html_e( 'Options', 'embed-drive' ); ?></h2>
						<div class="ed-fields">
							<div class="ed-field" data-for="files">
								<label for="ed-view"><?php esc_html_e( 'Layout', 'embed-drive' ); ?></label>
								<select id="ed-view" name="view">
									<option value="grid"><?php esc_html_e( 'Grid', 'embed-drive' ); ?></option>
									<option value="list"><?php esc_html_e( 'List', 'embed-drive' ); ?></option>
								</select>
							</div>
							<div class="ed-field" data-for="files">
								<label for="ed-sort"><?php esc_html_e( 'Sort by', 'embed-drive' ); ?></label>
								<div class="ed-inline">
									<select id="ed-sort" name="sort">
										<option value="name"><?php esc_html_e( 'Name', 'embed-drive' ); ?></option>
										<option value="modified"><?php esc_html_e( 'Modified', 'embed-drive' ); ?></option>
										<option value="size"><?php esc_html_e( 'Size', 'embed-drive' ); ?></option>
									</select>
									<select name="order" aria-label="<?php esc_attr_e( 'Direction', 'embed-drive' ); ?>">
										<option value="asc"><?php esc_html_e( 'Ascending', 'embed-drive' ); ?></option>
										<option value="desc"><?php esc_html_e( 'Descending', 'embed-drive' ); ?></option>
									</select>
								</div>
							</div>
							<div class="ed-field" data-for="files">
								<label for="ed-min"><?php esc_html_e( 'Minimum height (px)', 'embed-drive' ); ?></label>
								<input id="ed-min" name="min_height" type="number" min="0" max="3000" step="10" placeholder="<?php esc_attr_e( 'From Settings', 'embed-drive' ); ?>">
							</div>
							<div class="ed-field" data-for="button link">
								<label for="ed-label"><?php esc_html_e( 'Label', 'embed-drive' ); ?></label>
								<input id="ed-label" name="label" type="text">
							</div>
							<div class="ed-field" data-for="embed">
								<label for="ed-height"><?php esc_html_e( 'Height for documents (px)', 'embed-drive' ); ?></label>
								<input id="ed-height" name="height" type="number" min="100" max="3000" value="600">
							</div>
						</div>
						<div class="ed-toggles" data-for="files search embed">
							<label class="ed-toggle" data-for="files search embed"><input class="ed-switch" type="checkbox" name="download" checked> <?php esc_html_e( 'Allow downloads', 'embed-drive' ); ?></label>
							<label class="ed-toggle" data-for="files"><input class="ed-switch" type="checkbox" name="zip" checked> <?php esc_html_e( 'Allow ZIP downloads', 'embed-drive' ); ?></label>
							<label class="ed-toggle" data-for="files"><input class="ed-switch" type="checkbox" name="search" checked> <?php esc_html_e( 'Show search box', 'embed-drive' ); ?></label>
							<label class="ed-toggle" data-for="files search"><input class="ed-switch" type="checkbox" name="preview" checked> <?php esc_html_e( 'Allow previews', 'embed-drive' ); ?></label>
						</div>
					</section>
				</div>

				<aside class="ed-card ed-result">
					<h2 class="ed-card__title"><?php esc_html_e( 'Your shortcode', 'embed-drive' ); ?></h2>
					<textarea id="ed-output" rows="5" readonly placeholder="<?php esc_attr_e( 'Choose a Drive location to get your shortcode.', 'embed-drive' ); ?>"></textarea>
					<button type="button" class="button button-primary" id="ed-copy" aria-live="polite" disabled><?php esc_html_e( 'Copy shortcode', 'embed-drive' ); ?></button>
					<p class="ed-hint"><?php esc_html_e( 'Paste it into any page or post. In the block editor, you can use the Embed Drive block instead.', 'embed-drive' ); ?></p>
				</aside>
			</form>
		<?php endif; ?>
		<?php
		self::close_page();
	}

	public static function activity_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$events = [
			'preview'  => __( 'Preview', 'embed-drive' ),
			'download' => __( 'Download', 'embed-drive' ),
			'zip'      => __( 'ZIP', 'embed-drive' ),
			'search'   => __( 'Search', 'embed-drive' ),
		];
		$event  = isset( $_GET['event'] ) && is_string( $_GET['event'] ) && isset( $events[ $_GET['event'] ] ) ? sanitize_key( $_GET['event'] ) : '';
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable

		$data    = ActivityLog::page( $event, $page, self::PER_PAGE );
		$summary = ActivityLog::summary();
		$base    = admin_url( 'admin.php?page=embed-drive-activity' );
		$tiles   = [
			'preview'  => [ 'eye', __( 'Previews', 'embed-drive' ) ],
			'download' => [ 'download', __( 'Downloads', 'embed-drive' ) ],
			'zip'      => [ 'zip', __( 'ZIP downloads', 'embed-drive' ) ],
			'search'   => [ 'search', __( 'Searches', 'embed-drive' ) ],
		];
		$glyphs  = [ 'preview' => 'eye', 'download' => 'download', 'zip' => 'zip', 'search' => 'search' ];
		self::open_page( 'embed-drive-activity', __( 'Activity', 'embed-drive' ) );
		?>
		<?php if ( ! Settings::get( 'log_enabled' ) ) : ?>
			<p class="ed-callout"><?php esc_html_e( 'Logging is turned off, so new activity is not being recorded.', 'embed-drive' ); ?></p>
		<?php endif; ?>

		<section class="ed-card">
			<h2 class="ed-card__title"><?php esc_html_e( 'Last 30 days', 'embed-drive' ); ?></h2>
			<ul class="ed-stats">
				<?php foreach ( $tiles as $key => [ $glyph, $label ] ) : ?>
					<li>
						<?php self::glyph( $glyph ); ?>
						<strong><?php echo esc_html( number_format_i18n( (int) ( $summary['counts'][ $key ] ?? 0 ) ) ); ?></strong>
						<span><?php echo esc_html( $label ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<?php if ( $summary['top'] ) : ?>
			<section class="ed-card">
				<h2 class="ed-card__title"><?php esc_html_e( 'Most downloaded', 'embed-drive' ); ?></h2>
				<ol class="ed-rank">
					<?php foreach ( $summary['top'] as $row ) : ?>
						<li>
							<?php self::glyph( 'file' ); ?>
							<span><?php echo esc_html( $row['detail'] ); ?></span>
							<strong><?php echo esc_html( number_format_i18n( (int) $row['total'] ) ); ?></strong>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>
		<?php endif; ?>

		<section class="ed-card">
			<div class="ed-card__head">
				<h2 class="ed-card__title"><?php esc_html_e( 'All activity', 'embed-drive' ); ?></h2>
				<nav class="ed-filters" aria-label="<?php esc_attr_e( 'Filter activity', 'embed-drive' ); ?>">
					<a href="<?php echo esc_url( $base ); ?>" <?php echo '' === $event ? 'aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'embed-drive' ); ?></a>
					<?php foreach ( $events as $key => $label ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'event', $key, $base ) ); ?>" <?php echo $key === $event ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>
			</div>
			<?php if ( ! $data['rows'] ) : ?>
				<div class="ed-empty">
					<?php self::glyph( 'file' ); ?>
					<p><?php esc_html_e( 'Nothing recorded yet.', 'embed-drive' ); ?></p>
				</div>
			<?php else : ?>
				<div class="ed-table-wrap">
					<table class="ed-table">
						<thead><tr><th><?php esc_html_e( 'When', 'embed-drive' ); ?></th><th><?php esc_html_e( 'Event', 'embed-drive' ); ?></th><th><?php esc_html_e( 'File or search', 'embed-drive' ); ?></th><th><?php esc_html_e( 'User', 'embed-drive' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $data['rows'] as $row ) : ?>
							<?php $user = $row['user_id'] ? get_userdata( (int) $row['user_id'] ) : false; ?>
							<tr>
								<td class="ed-nowrap"><?php echo esc_html( get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></td>
								<td><span class="ed-event"><?php self::glyph( $glyphs[ $row['event'] ] ?? 'file' ); ?><?php echo esc_html( $events[ $row['event'] ] ?? $row['event'] ); ?></span></td>
								<td class="ed-wrap"><?php echo esc_html( $row['detail'] ); ?></td>
								<td class="ed-muted"><?php echo esc_html( $user ? $user->user_login : __( 'Visitor', 'embed-drive' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="ed-pages">
					<?php
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
					?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		self::close_page();
	}

	/**
	 * The frame every screen shares, built like the file browser: a toolbar over a panel of cards.
	 */
	private static function open_page( string $current, string $title ): void {
		$tabs = [ 'embed-drive-builder' => [ __( 'Shortcode Builder', 'embed-drive' ), Modules::embed_capability() ], 'embed-drive' => [ __( 'Accounts & Settings', 'embed-drive' ), 'manage_options' ], 'embed-drive-activity' => [ __( 'Activity', 'embed-drive' ), 'manage_options' ] ];
		$tabs = array_filter( $tabs, static fn( $tab ) => current_user_can( $tab[1] ) );
		?>
		<div class="wrap embed-drive-admin">
			<hr class="wp-header-end">
			<div class="ed-panel">
				<header class="ed-bar">
					<img src="<?php echo esc_url( EMBED_DRIVE_URL . 'assets/icon/icon.svg' ); ?>" width="28" height="28" alt="">
					<h1><?php echo esc_html( $title ); ?></h1>
					<?php if ( count( $tabs ) > 1 ) : ?>
						<nav class="ed-tabs" aria-label="<?php esc_attr_e( 'Embed Drive', 'embed-drive' ); ?>">
							<?php foreach ( $tabs as $slug => [ $label ] ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" <?php echo $slug === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
							<?php endforeach; ?>
						</nav>
					<?php endif; ?>
				</header>
				<div class="ed-body">
		<?php
	}

	private static function close_page(): void {
		echo '</div></div></div>';
	}

	private static function glyph( string $name ): void {
		printf( '<svg class="ed-glyph" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="%s"/></svg>', esc_attr( self::GLYPHS[ $name ] ?? '' ) );
	}

	/**
	 * A value people must paste into Google's console, with a button that copies it.
	 */
	private static function copyable( string $value ): void {
		printf(
			'<span class="ed-copy"><code>%s</code><button type="button" class="button" data-ed-copy>%s</button></span>',
			esc_html( $value ),
			esc_html__( 'Copy', 'embed-drive' )
		);
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
			'min_height'    => min( 3000, absint( $_POST['min_height'] ?? 0 ) ),
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

	public static function reset_links(): void {
		self::guard( 'embed_drive_reset_links' );
		Modules::reset_links();
		self::back( 'links_reset', '' );
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
			'links_reset'  => __( 'Visitor links reset. If your site uses a page cache, clear it now.', 'embed-drive' ),
			'error'        => $detail,
		];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $result ? 'error' : 'success',
			esc_html( $messages[ $result ] ?? '' )
		);
	}
}
