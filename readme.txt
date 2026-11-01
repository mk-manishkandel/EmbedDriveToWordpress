=== Embed Drive ===
Tags: google drive, file browser, documents, download, embed
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show Google Drive folders and files on your site: file browser, search, single-file embeds and downloads. No licence keys, no upsells.

== Description ==

Embed Drive shows your Google Drive content on your WordPress site. It is open source and has no licence checks, upsells or tracking.

* **File browser**: browse a folder with list or grid view, breadcrumbs and sorting.
* **Previews**: images, PDFs, audio, video, text and Google Docs.
* **Search**: search a folder and its subfolders.
* **Single files**: embed one file, or add a download button or link.
* **Downloads**: single files, or several as one ZIP.
* **Multiple Google accounts**.
* **Activity log**: previews, downloads and searches, stored only in your database.
* **Email alert** if a Google account stops working.
* **Gutenberg block and shortcode builder**.

= Your files stay private =

Embed Drive asks Google for read-only access. Files are passed through your site, so you don't need to make them public in Drive. A visitor can only reach the folder or file you chose for that module, not the rest of your Drive.

Anyone who can see the page can see the files in its module. Embed Drive does not restrict modules by role or password, so don't point one at a folder you want to keep private.

= External services =

This plugin connects to these services:

* **Google** (Google Drive API and Google sign-in), to read the Drive content you choose. Used only after you connect an account. [Terms](https://policies.google.com/terms), [Privacy](https://policies.google.com/privacy).
* **A sign-in relay**, only if you add one in wp-config.php. It sees your authorization codes and tokens while passing them on. See the relay's owner for its terms.
* **Google Drive's file preview** (drive.google.com), in the visitor's browser, only for files already shared as "anyone with the link".
* **GitHub** (api.github.com and github.com), to check for plugin updates. Sends no site data beyond an ordinary web request. [Terms](https://docs.github.com/site-policy/github-terms/github-terms-of-service), [Privacy](https://docs.github.com/site-policy/privacy-policies/github-general-privacy-statement).

== Installation ==

1. Download `embed-drive.zip` from the [latest release](https://github.com/mk-manishkandel/EmbedDriveToWordpress/releases/latest).
2. In WordPress, go to Plugins > Add New > Upload Plugin, and upload it.
3. Go to Embed Drive > Accounts & Settings, create your own free Google app using the steps shown there, and sign in. You only do this once per site.
4. Add the Embed Drive block to a page, or build a shortcode under Embed Drive > Shortcode Builder.

== Frequently Asked Questions ==

= Who can add modules? =

Editors and administrators. A module in a post written by a lower role, such as an Author, does not display, because it could otherwise publish any file in the connected Drive.

= Why are shortcuts not shown? =

A Drive shortcut can point to a file outside the chosen folder, so shortcuts are hidden.

= Why doesn't a Drive change show up right away? =

File lists are cached for 5 minutes. Use Clear file cache in the settings to refresh sooner.

== Changelog ==

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
