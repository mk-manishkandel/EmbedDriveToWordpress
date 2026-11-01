=== Embed Drive ===
Tags: google drive, file browser, documents, download, embed
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.2.1
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
