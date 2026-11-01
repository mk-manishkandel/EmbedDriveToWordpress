<?php
/**
 * Embed Drive sign-in relay. One file, no database, stores nothing.
 *
 * Google needs one fixed redirect URL and a client secret, which a plugin installed on many
 * different sites can't provide. This script holds the secret and passes the sign-in along:
 *
 *   action=start     Shows which site is asking, then sends the visitor to Google.
 *   (Google returns) Sends the authorization code back to that site.
 *   action=exchange  Site swaps code + PKCE verifier for tokens (server to server).
 *   action=refresh   Site swaps a refresh token for a new access token (server to server).
 *
 * Set up: copy config.example.php to config.php. See README.md.
 *
 * @package EmbedDrive
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Tokens pass through this script, so errors must never be shown and requests must not be logged here.
ini_set( 'display_errors', '0' );

const RELAY_SCOPES = 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile';
const STATE_TTL    = 900;

$config_file = getenv( 'EMBED_DRIVE_RELAY_CONFIG' ) ?: __DIR__ . '/config.php';
if ( ! is_readable( $config_file ) ) {
	http_response_code( 503 );
	exit( 'Relay is not configured.' );
}
$config = require $config_file;

header( 'X-Content-Type-Options: nosniff' );
header( 'X-Frame-Options: DENY' );
header( 'Referrer-Policy: no-referrer' );
header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'" );
header( 'Cache-Control: no-store' );

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ( 'POST' === $_SERVER['REQUEST_METHOD'] && 'exchange' === $action ) {
	relay_exchange( $config );
} elseif ( 'POST' === $_SERVER['REQUEST_METHOD'] && 'refresh' === $action ) {
	relay_refresh( $config );
} elseif ( 'start' === $action ) {
	relay_start( $config );
} elseif ( isset( $_GET['state'] ) ) {
	relay_return( $config );
} else {
	echo 'Embed Drive sign-in relay.';
}

function relay_exchange( array $config ): void {
	$code     = (string) ( $_POST['code'] ?? '' );
	$verifier = (string) ( $_POST['code_verifier'] ?? '' );
	if ( '' === $code || ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) ) {
		relay_json( 400, [ 'error' => 'invalid_request' ] );
	}
	relay_token(
		$config,
		[
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'code_verifier' => $verifier,
			'redirect_uri'  => $config['redirect_uri'],
		]
	);
}

function relay_refresh( array $config ): void {
	$refresh = (string) ( $_POST['refresh_token'] ?? '' );
	if ( '' === $refresh ) {
		relay_json( 400, [ 'error' => 'invalid_request' ] );
	}
	relay_token(
		$config,
		[
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh,
		]
	);
}

/**
 * Anyone can craft a link to this page naming their own site, which would then receive access to
 * the Drive of whoever approves it. So the visitor must see the requesting site and confirm it
 * before Google's consent screen, which would otherwise only show the relay's trusted app name.
 */
function relay_start( array $config ): void {
	$return_url = (string) ( $_GET['return_url'] ?? '' );
	$state      = (string) ( $_GET['state'] ?? '' );
	$challenge  = (string) ( $_GET['code_challenge'] ?? '' );

	if ( ! relay_valid_return_url( $return_url, $config ) || ! preg_match( '/^[A-Za-z0-9]{16,128}$/', $state ) || ! preg_match( '/^[A-Za-z0-9\-_]{43}$/', $challenge ) ) {
		http_response_code( 400 );
		exit( 'Invalid sign-in request.' );
	}

	$packed = relay_seal(
		[
			'r' => $return_url,
			's' => $state,
			'e' => time() + STATE_TTL,
		],
		$config
	);

	$google = ( $config['auth_endpoint'] ?? 'https://accounts.google.com/o/oauth2/v2/auth' ) . '?' . http_build_query(
		[
			'client_id'             => $config['client_id'],
			'redirect_uri'          => $config['redirect_uri'],
			'response_type'         => 'code',
			'scope'                 => RELAY_SCOPES,
			'access_type'           => 'offline',
			'prompt'                => 'consent',
			'state'                 => $packed,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		],
		'',
		'&',
		PHP_QUERY_RFC3986
	);

	$host = htmlspecialchars( (string) parse_url( $return_url, PHP_URL_HOST ), ENT_QUOTES, 'UTF-8' );
	$link = htmlspecialchars( $google, ENT_QUOTES, 'UTF-8' );

	header( 'Content-Type: text/html; charset=utf-8' );
	echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Connect Google Drive</title>
<style>
body{font:16px/1.5 system-ui,sans-serif;margin:0;background:#f4f5f7;color:#1d2327;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
main{background:#fff;border-radius:10px;padding:32px;max-width:440px;box-shadow:0 1px 4px rgba(0,0,0,.12)}
h1{font-size:20px;margin:0 0 12px}
.site{font-size:18px;font-weight:600;word-break:break-all;background:#f4f5f7;padding:10px 12px;border-radius:6px;display:block;margin:12px 0}
a.go{display:inline-block;background:#1a56c4;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:600;margin-top:8px}
small{display:block;color:#555;margin-top:16px}
</style>
</head>
<body>
<main>
<h1>Give this website read access to your Google Drive?</h1>
<p>This site is asking to read all files in your Drive:</p>
<span class="site">{$host}</span>
<p><strong>Only continue if this is your website and you just clicked "Sign in with Google" in its admin area.</strong> If someone sent you this link, close this page.</p>
<a class="go" href="{$link}">Continue to Google</a>
<small>This relay does not store your files or tokens. It only passes the sign-in along.</small>
</main>
</body>
</html>
HTML;
}

function relay_return( array $config ): void {
	$data = relay_open( (string) $_GET['state'], $config );
	if ( ! $data || $data['e'] < time() || ! relay_valid_return_url( $data['r'], $config ) ) {
		http_response_code( 400 );
		exit( 'This sign-in link has expired. Please start again from your website.' );
	}

	$params = [ 'state' => $data['s'] ];
	if ( isset( $_GET['code'] ) ) {
		$params['code'] = (string) $_GET['code'];
	} else {
		$params['error'] = 'access_denied';
	}

	$separator = false === strpos( $data['r'], '?' ) ? '?' : '&';
	header( 'Location: ' . $data['r'] . $separator . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ), true, 302 );
}

/**
 * Only https, plus http for local development, and only the plugin's own callback.
 */
function relay_valid_return_url( string $url, array $config ): bool {
	$parts = parse_url( $url );
	if ( ! $parts || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['fragment'] ) ) {
		return false;
	}
	$local  = in_array( $parts['host'], [ 'localhost', '127.0.0.1', '[::1]' ], true );
	$scheme = $parts['scheme'] ?? '';
	if ( 'https' !== $scheme && ! ( 'http' === $scheme && $local && ! empty( $config['allow_local'] ) ) ) {
		return false;
	}

	return str_ends_with( $parts['path'] ?? '', '/admin-post.php' ) && 'action=embed_drive_oauth_callback' === ( $parts['query'] ?? '' );
}

function relay_token( array $config, array $body ): void {
	$body['client_id']     = $config['client_id'];
	$body['client_secret'] = $config['client_secret'];

	$ch = curl_init( $config['token_endpoint'] ?? 'https://oauth2.googleapis.com/token' );
	curl_setopt_array(
		$ch,
		[
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query( $body, '', '&', PHP_QUERY_RFC3986 ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_HTTPHEADER     => [ 'Content-Type: application/x-www-form-urlencoded' ],
		]
	);
	$response = curl_exec( $ch );
	$status   = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );

	if ( false === $response || 0 === $status ) {
		relay_json( 502, [ 'error' => 'upstream_unavailable' ] );
	}

	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo $response;
	exit;
}

/**
 * @return never
 */
function relay_json( int $status, array $body ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	echo json_encode( $body );
	exit;
}

function relay_seal( array $data, array $config ): string {
	$body = rtrim( strtr( base64_encode( (string) json_encode( $data ) ), '+/', '-_' ), '=' );

	return $body . '.' . hash_hmac( 'sha256', $body, $config['state_secret'] );
}

function relay_open( string $sealed, array $config ): ?array {
	$parts = explode( '.', $sealed );
	if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], $config['state_secret'] ), $parts[1] ) ) {
		return null;
	}
	$data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true );

	return is_array( $data ) ? $data : null;
}
