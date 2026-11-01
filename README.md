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
2. Connect an account under **Embed Drive > Accounts & Settings**. See [Connecting to Google](#connecting-to-google).
3. Add the **Embed Drive** block to a page, or create a shortcode under **Embed Drive > Shortcode Builder**.

Requires WordPress 6.0+ and PHP 8.0+.

## Connecting to Google

There are two ways to connect, and both need a Google app of some kind:

| | Sign-in relay | Your own Google app |
|---|---|---|
| **Setup per site** | One click | About 10 minutes in Google Cloud |
| **Needs hosting** | Someone runs the [relay](relay/README.md) | No |
| **Google's 100-user limit** | Shared by every site on the relay | Your own |
| **Tokens pass through** | The relay | Only your site |

Google limits unverified apps that read Drive to 100 new users per project, ever, and shows those users a warning. So a single shared relay suits your own sites better than a large public audience. Anyone can use the own-app path, with steps shown in the plugin under **Advanced**.

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
| `view` | `list`, `grid` | `list` | `files` |
| `sort` | `name`, `modified`, `size` | `name` | `files` |
| `order` | `asc`, `desc` | `asc` | `files` |
| `download` | `yes`, `no` | `yes` (`no` for `embed`) | `files`, `search`, `embed` |
| `zip` | `yes`, `no` | `yes` | `files` |
| `search` | `yes`, `no` | `yes` | `files` |
| `preview` | `yes`, `no` | `yes` | `files`, `search` |
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

Found a vulnerability? Please report it privately through [GitHub security advisories](https://github.com/mk-manishkandel/EmbedDriveToWordpress/security/advisories/new).

## Development

There's no build step. Run the tests with Node 20+, `unzip` and `python3`; no PHP install is needed, as they run real WordPress in [WP Playground](https://github.com/WordPress/wordpress-playground) against a mock Google server:

```sh
tests/run.sh          # PHP 8.0, latest WordPress
tests/run.sh 8.4      # A specific PHP version
```

### Releasing an update

1. Update the version in `embed-drive.php` (the header and `EMBED_DRIVE_VERSION`) and `readme.txt` (`Stable tag` and the changelog).
2. Commit, then tag and push:
   ```sh
   git tag v0.2.0 && git push origin main v0.2.0
   ```

GitHub Actions runs the tests, checks that the versions match the tag, builds `embed-drive.zip` and publishes the release. Installed sites normally see the update within a day, or straight away with **Check again** under Dashboard > Updates once the plugin's 6-hour release cache has expired.

## Independence

Embed Drive is an independent project, written from scratch. It isn't affiliated with Google or any commercial Google Drive plugin. Google Drive is a trademark of Google LLC.

## Licence

[GPL-2.0-or-later](LICENSE)
