=== FileFolio – File Browser, Photo Gallery & Embeds for Google Drive ===
Contributors: kandelmanish
Tags: google drive, file browser, documents, download, embed
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.5.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show Google Drive folders, photo galleries, file previews, search and downloads on your site with a block or shortcode. Read-only, no upsells.

== Description ==

FileFolio shows your Google Drive content on your WordPress site: a file browser, photo galleries, previews of documents and photos, search, and single-file embeds, buttons and links. It is open source and has no licence checks, upsells or tracking.

FileFolio was called Embed Drive before it joined the WordPress.org directory. It is not made by or affiliated with Google.

* **File browser**: browse a folder with list or grid view, breadcrumbs and sorting.
* **Photo gallery**: the photos in a folder as square tiles, with subfolders as albums and a full-screen viewer.
* **Previews**: images, PDFs, Word and Excel files, audio, video, text and Google Docs.
* **Search**: search a folder and its subfolders.
* **Single files**: embed one file, or add a download button or link.
* **Downloads**: single files, or several as one ZIP.
* **Multiple Google accounts**.
* **Activity log**: previews, downloads and searches, stored only in your database. Works with WordPress's tools for exporting or erasing a person's data.
* **Email alerts** if a Google account stops working, or if requests to Google keep failing. The settings screen also shows the last problem reaching Google.
* **Gutenberg block and shortcode builder**.

= Your files stay private =

FileFolio asks Google for read-only access. Files are passed through your site, so you don't need to make them public in Drive. A visitor can only reach the folder or file you chose for that module, not the rest of your Drive.

Anyone who can see the page can see the files in its module. FileFolio does not restrict modules by role or password, so don't point one at a folder you want to keep private.

= Source code =

The full source, including the unminified code of the bundled preview libraries and the script that builds them (`bin/build-viewer-libs.sh`), is on [GitHub](https://github.com/mk-manishkandel/EmbedDriveToWordpress). The libraries and their versions are listed in `assets/viewer/lib/README.md`.

= External services =

This plugin connects to these services:

* **Google** (Google Drive API and Google sign-in), to read the Drive content you choose. Used only after you connect an account. [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy).
* **A sign-in relay**, only if you add one in wp-config.php. It sees your authorization codes and tokens while passing them on. See the relay's owner for its terms.
* **Google Drive's file preview** (drive.google.com), in the visitor's browser, only for files already shared as "anyone with the link".
* **GitHub** (api.github.com and github.com), to check for plugin updates. Sends no site data beyond an ordinary web request. [Terms](https://docs.github.com/site-policy/github-terms/github-terms-of-service), [Privacy](https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement).

== Installation ==

1. In WordPress, go to Plugins > Add New and search for FileFolio.
2. Install and activate it. (It can also be uploaded as `embed-drive.zip` from the [GitHub releases](https://github.com/mk-manishkandel/EmbedDriveToWordpress/releases/latest).)
3. Go to FileFolio > Accounts & Settings, create your own free Google app using the steps shown there, and sign in. You only do this once per site.
4. Add the FileFolio block to a page, or build a shortcode under FileFolio > Shortcode Builder.

== Frequently Asked Questions ==

= Who can add modules? =

Editors and administrators. A module in a post written by a lower role, such as an Author, does not display, because it could otherwise publish any file in the connected Drive.

= Why are shortcuts not shown? =

A Drive shortcut can point to a file outside the chosen folder, so shortcuts are hidden.

= Why doesn't a Drive change show up right away? =

File lists are cached for 5 minutes. Use Clear file cache in the settings to refresh sooner.

== Screenshots ==

1. The file browser, with folders, document thumbnails and photos.
2. A photo gallery, with a subfolder shown as an album.
3. The full-screen viewer. Use the arrow keys or swipe to move between photos and files.
4. The shortcode builder.
5. Accounts and settings, including the connection status.

== Changelog ==

= 0.5.2 =
* Security: a synced pattern only shows FileFolio content if the person who made it and the last person to change it are both allowed to embed Drive files (Editors and above by default). Before, an Author's pattern placed on an Editor's page would show.

= 0.5.1 =
* Renamed from Embed Drive to FileFolio. Your shortcodes, blocks, settings and Google sign-in stay as they are.
* If the GitHub copy and the WordPress.org copy are both installed, only one runs and the admin screens say so. Deleting either copy keeps your settings.

= 0.5.0 =
* New: photo gallery module. It shows only the photos in a folder, and its visitor link can't reach anything else, not even other files in the same folder.
* New: the settings screen shows the most recent problem reaching Google.
* New: the activity log works with WordPress's personal data export and erase tools, and suggests text for your privacy policy.
* Fixed: the block's wide and full width alignment now shows on the page.

= 0.4.1 =
* Added the WordPress.org contributor name. No changes to how the plugin works.

= 0.4.0 =
* Prepared for the WordPress.org plugin directory. Each release now also includes `embed-drive-wordpress-org.zip`, the copy for WordPress.org, which leaves out the GitHub updater.
* Translations now load the standard WordPress way.

= 0.3.9 =
* New: a daily email summary if requests to Google keep failing, for example during an outage or when a Google limit is reached. It is on by default and can be turned off under Notifications.

= 0.3.8 =
* Security: updated PDF.js to 4.10.38, which fixes a flaw that a malicious PDF could use to run code. Previews were already sandboxed against it.
* Updated the Word preview library to 0.4.1.
* Fixed: a module in a theme template disappeared on posts written by an Author or Contributor.
* Shortcodes in comments can no longer show Drive files, even on sites where another plugin turns on shortcodes in comments.

= 0.3.7 =
* Fixed: a module in a widget disappeared on posts written by an Author or Contributor.
* Fixed: the settings screen could crash on hosts without PHP's mbstring extension.
* Fixed: the Activity screen could crash on a malformed filter address.
* Fixed: Copy buttons did nothing when the browser refused clipboard access.
* The admin menu now follows the embed_drive_embed_capability filter.
* Ready for PHP 8.5.

= 0.3.6 =
* The admin screens now use the full width of the page.

= 0.3.5 =
* New look for the Shortcode Builder, Accounts & Settings and Activity screens, matching the file browser on your site.
* The Shortcode Builder shows modules as cards, keeps your shortcode in view and can set a minimum height.
* Copy buttons for the values you paste into Google's console, and a confirmation before resetting visitor links.

= 0.3.4 =
* The key that signs updates has been replaced. If you have 0.3.3, install this version by hand: download embed-drive.zip from the releases page, then use Plugins > Add New > Upload Plugin. Later updates install normally.

= 0.3.3 =
* Updates are now signed. From this version on, a site refuses an update that doesn't carry the project's signature, even if it appears on the project's GitHub page.

= 0.3.2 =
Security release. Please update, then clear any page cache: links on cached pages stop working until it is cleared.
* Fixed: a logged-in Contributor or Author could use the block preview to get a link to any folder in the connected Drive.
* Fixed: a file in Google Drive's trash could still be downloaded through an existing link.
* Fixed: modules with previews off still served the large picture of a file.
* The account's Google token can no longer follow a redirect away from Google.
* Updates are only accepted from this project's own GitHub releases.
* Previews refuse to run outside their sandbox, so another site can't use them to run a document's script.
* Files and thumbnails are now rate limited per visitor, and oversized ZIP selections are refused sooner.
* New button in Settings to cancel every visitor link.
* New plugin icon.

= 0.3.1 =
* Theme button styles, such as colored backgrounds, no longer show through in the file browser.
* New setting: a minimum height for the file browser, with an override per module.
* Previews no longer depend on Google fetching files from your site, which could show "No preview available". Word, Excel and PDF files are drawn in the browser. Files shared as "anyone with the link" use Google Drive's own preview, for nearly any file type. Other files show a picture of the first page.

= 0.3.0 =
* New front-end design: toolbar with icon buttons, folder and thumbnail cards, placeholders while loading, smooth transitions. Grid is now the default layout.
* Full-screen preview that moves between files with arrows, keyboard or swipe.
* Word, Excel, PowerPoint and similar files now preview on the page, through Google's document viewer. So do PDFs on phones. Other files with a Google thumbnail show a large picture.
* CSV and other text files show on the page instead of downloading.
* Load more no longer repeats files when a page cache or CDN replays an earlier answer.
* Modules with downloads turned off no longer hand out files through the preview link.

= 0.2.1 =
* Check again under Dashboard > Updates now really asks GitHub, instead of reusing a saved answer.
* Updates are still found when GitHub's API refuses the request, which is common on shared hosting.
* New releases show up sooner: the saved answer now lasts 1 hour, not 6.

= 0.2.0 =
* Each site owner now connects with their own Google app, with a step-by-step guide on the settings screen.
* The relay field is removed from settings. A relay can still be set with the EMBED_DRIVE_RELAY_URL constant.
* You now get the lost-access email if your Google app is deleted or its secret changes.

= 0.1.0 =
* First release.
