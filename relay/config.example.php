<?php
// Copy to config.php. Never commit config.php: it holds your Google client secret.

return [
	'client_id'     => 'YOUR_CLIENT_ID.apps.googleusercontent.com',
	'client_secret' => 'YOUR_CLIENT_SECRET',

	// The public URL of this script. Add this exact URL to the OAuth client's authorized redirect URIs.
	'redirect_uri'  => 'https://example.com/embed-drive-relay/index.php',

	// A long random value used to protect sign-in links. Generate one with: openssl rand -hex 32
	'state_secret'  => 'CHANGE_ME',
];
