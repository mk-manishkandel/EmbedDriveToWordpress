<img src="assets/icon/icon-128x128.png" width="64" height="64" alt="">

# Embed Drive

Show Google Drive folders and files on your WordPress site. Open source, with no licence keys, upsells or tracking.

**[Download the latest release](https://github.com/mk-manishkandel/EmbedDriveToWordpress/releases/latest)** (`embed-drive.zip`). After you install it, updates appear on your Plugins screen like any other plugin.

## Features

- File browser with list and grid views, breadcrumbs and sorting
- Previews for images, PDFs, audio, video, text and Google Docs
- Search across a folder and its subfolders
- Single-file embeds, download buttons and links
- Single-file and streaming ZIP downloads
- Multiple Google accounts
- Activity log and an email alert if an account stops working
- Gutenberg block and shortcode builder

## Quick start

1. Upload `embed-drive.zip` under **Plugins > Add New > Upload Plugin**, then activate it.
2. Create your Google app and sign in under **Embed Drive > Accounts & Settings**. See [Connecting to Google](#connecting-to-google).
3. Add the **Embed Drive** block to a page, or create a shortcode under **Embed Drive > Shortcode Builder**.

Requires WordPress 6.0+ and PHP 8.0+.

## Connecting to Google

Each site owner connects with **their own Google app**. Google requires an app for any Drive sign-in. Using your own means your sign-in and tokens go only between your site and Google, and no one has to host anything. It's free and takes about 10 minutes, once per site.

1. In the [Google Cloud console](https://console.cloud.google.com/projectcreate), create a project.
2. Enable the [Google Drive API](https://console.cloud.google.com/apis/library/drive.googleapis.com).
3. Open the OAuth consent screen (Google Auth Platform) and fill in the app name and your email. Choose **External**, or **Internal** if your Google Workspace organisation will be the only one using it.
4. Under **Data Access**, add the `https://www.googleapis.com/auth/drive.readonly` scope. For an External app, **publish it** under **Audience**: in Testing mode your sign-in expires after 7 days.
5. Under **Clients**, create a **Web application** client. Add the redirect URI shown on the plugin's settings screen.
6. Paste the client ID and secret into **Embed Drive > Accounts & Settings**, save, and click **Sign in with Google**.

An External app shows "Google hasn't verified this app" at sign-in. That's expected for your own app: choose **Advanced**, then continue to your app. Google allows up to 100 people to do this per app, which is plenty for one site's owner.

Someone hosting sites for others can run the optional [sign-in relay](relay/README.md) instead, enabled with `EMBED_DRIVE_RELAY_URL` in `wp-config.php`.

## Shortcode

```
[embed_drive mode="files" folder="FOLDER_ID"]
[embed_drive mode="search" folder="FOLDER_ID"]
[embed_drive mode="embed" file="FILE_ID"]
[embed_drive mode="button" file="FILE_ID" label="Get the guide"]
[embed_drive mode="link" file="FILE_ID"]
```

| Attribute | Values | Default | Applies to |
|---|---|---|---|
| `mode` | `files`, `search`, `embed`, `button`, `link` | `files` | All |
| `folder` | Drive folder ID, or `root` for My Drive | | `files`, `search` |
| `file` | Drive file ID | | `embed`, `button`, `link` |
| `account` | Account ID, for multi-account sites | First account | All |
| `view` | `grid`, `list` | `grid` | `files` |
| `sort` | `name`, `modified`, `size` | `name` | `files` |
| `order` | `asc`, `desc` | `asc` | `files` |
| `download` | `yes`, `no` | `yes` (`no` for `embed`) | `files`, `search`, `embed` |
| `zip` | `yes`, `no` | `yes` | `files` |
| `search` | `yes`, `no` | `yes` | `files` |
| `preview` | `yes`, `no` | `yes` | `files`, `search` |
| `min_height` | pixels | the plugin setting | `files` |
| `label` | Text | File name | `button`, `link` |
| `height` | Pixels, for documents | `600` | `embed` |

`download="no"` removes downloads and ZIPs, but previewable files can still be viewed and so saved by a determined visitor.

## Security model

- **Read-only.** The plugin requests `drive.readonly`, so it can't change or delete anything in your Drive. Files are proxied through your site, so they don't need public sharing.
- **Modules are public.** Anyone who can see the page can see its module's files. There are no per-role or password rules, so don't point a module at a private folder.
- **Each module is confined to its folder.** The page holds a signed token naming the folder and options. Every request checks the signature and walks up Drive's parent chain to confirm the item is inside that folder.
- **Editors and above only.** A module in a post by a lower role won't display, as it could otherwise publish any file in the Drive. Change this with the `embed_drive_embed_capability` and `embed_drive_author_may_embed` filters.
- **Credentials are encrypted** in the database with keys derived from your WordPress salts. Rotating the salts means signing in again. Define `EMBED_DRIVE_KEY` in `wp-config.php` to avoid that.
- **Safe inline types only.** HTML, SVG and anything else outside a short allowlist is sent as a download, never rendered on your site's origin.
- **Visitor links don't expire**, so cached pages keep working. **Accounts & Settings > Reset visitor links** cancels them all if one leaks or after you remove a module.
- **Document previews stay sandboxed.** Word, Excel and PDF files are drawn in a frame with no access to your site, and the viewer refuses to run outside it.
- **Updates are signed.** A site installs an update only if it comes from this repository's releases and carries a signature from the maintainer's private key, which is never stored on GitHub. Installing a ZIP by hand skips this check, so only use ZIPs from the releases page.

What this can't protect against: a stolen WordPress admin or Editor login, another vulnerable plugin or theme, or a module pointed at a folder that shouldn't be public. Use two-factor sign-in for those accounts.

Found a vulnerability? Please report it privately through [GitHub security advisories](https://github.com/mk-manishkandel/EmbedDriveToWordpress/security/advisories/new).

## Development

There's no build step. Run the tests with Node 24.18+, `unzip` and `python3`; no PHP install is needed, as they run real WordPress in [WP Playground](https://github.com/WordPress/wordpress-playground) against a mock Google server:

```sh
tests/run.sh          # PHP 8.0, latest WordPress
tests/run.sh 8.4      # A specific PHP version
```

### Releasing an update

1. Update the version in `embed-drive.php` (the header and `EMBED_DRIVE_VERSION`) and `readme.txt` (`Stable tag` and the changelog).
2. Commit, then tag and push:
   ```sh
   git tag v1.2.3 && git push origin main v1.2.3
   ```
3. GitHub Actions runs the tests, checks that the versions match the tag, builds `embed-drive.zip` and creates a **draft** release, which sites can't see yet.
4. Sign and publish it on your own computer, with the private key:
   ```sh
   node bin/sign-release.mjs 1.2.3 ~/embed-drive-signing.pem
   ```

Installed sites normally see the update within a day, or straight away with **Check again** under **Dashboard > Updates**. If the signing key is ever replaced, sites must install the next version by hand, because they only trust the key they already have.

## Independence

Embed Drive is an independent project, written from scratch. It isn't affiliated with Google or any commercial Google Drive plugin. Google Drive is a trademark of Google LLC.

## Licence

[GPL-2.0-or-later](LICENSE)
