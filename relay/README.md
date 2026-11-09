# FileFolio sign-in relay (optional)

**Most people don't need this.** The plugin's normal setup is for each site owner to use their own Google app. The relay is for someone managing sites for others who wants one shared Google app.

Google needs one fixed redirect URL and a client secret, which a plugin installed on many different sites can't provide. This single PHP file gives those sites a one-click "Sign in with Google" using your Google app.

It has no database and stores nothing. It holds the client secret and passes the sign-in along. The relay isn't part of the plugin ZIP; host it separately.

**Sites that use your relay trust you.** Their tokens pass through this script during sign-in and every refresh (about hourly while in use). Keep it patched, and don't add logging that captures request bodies.

## Set up

You need HTTPS and PHP 8.0+ with cURL. A subfolder of an existing website is fine.

1. In the [Google Cloud console](https://console.cloud.google.com/), create a project and enable the **Google Drive API**.
2. Set up the OAuth consent screen. Add the scopes `drive.readonly`, `userinfo.email` and `userinfo.profile`. **Publish the app**: in Testing mode, sign-ins expire after 7 days.
3. Create an **OAuth client ID** of type *Web application*. Add the public URL of the relay's `index.php` as an authorized redirect URI.
4. Upload `index.php` and `config.example.php`. Copy `config.example.php` to `config.php` and fill it in. Generate `state_secret` with `openssl rand -hex 32`.
5. Check that the relay's URL in a browser shows `FileFolio sign-in relay.`
6. In each WordPress site's `wp-config.php`, add `define( 'EMBED_DRIVE_RELAY_URL', 'https://example.com/embed-drive-relay/index.php' );`.

## Google's limits

`drive.readonly` is a **restricted** scope. Until Google verifies your app:

- People see an "unverified app" warning when signing in.
- Your Google project can only ever get **100 new users**. This counts everyone on every site using your relay, over the project's whole lifetime, and can't be reset.

Verification for restricted scopes requires a security assessment and may not be available for this kind of app. Check [Google's current rules](https://developers.google.com/workspace/drive/api/guides/api-specific-auth) before relying on it.

If you run out, sites can still connect with their own Google app, from the plugin's settings screen. That path has no shared limit.

## How it stays safe

- **Phishing:** anyone can craft a link naming their own site, which would then get access to the Drive of whoever approves it. So the relay first shows which site is asking and warns people to continue only if it's theirs. That screen is the main defence, so don't remove it.
- **Stolen codes:** PKCE means a code is useless without the verifier, which only the requesting site holds.
- **Tampering:** the return address is signed and expires after 15 minutes.
- **Return addresses:** only HTTPS URLs ending in the plugin's own callback are accepted.

Consider adding a rate limit for this script at your web server or CDN. Keep `config.php` out of version control and unreachable as a download (PHP executes it, so it returns nothing if requested).
