#!/usr/bin/env bash
# Runs the end-to-end suite on real WordPress (WP Playground) against the mock Google server.
# Usage: tests/run.sh [php-version] [wp-version]. Needs Node 24.18+, unzip and python3.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP_VERSION="${1:-8.0}"
WP_VERSION="${2:-latest}"
WORK="$(mktemp -d)"
trap 'kill $(jobs -p) 2>/dev/null || true; rm -rf "$WORK"' EXIT

mkdir -p "$WORK/relay-test"
cp "$ROOT/relay/index.php" "$WORK/relay-test/index.php"
cat > "$WORK/relay-test/config.php" <<'PHP'
<?php
return [
	'client_id' => 'relay-client', 'client_secret' => 'test-secret',
	'redirect_uri' => 'http://127.0.0.1:9500/relay-test/index.php',
	'state_secret' => 'test-state-secret', 'allow_local' => true,
	'auth_endpoint' => 'http://127.0.0.1:9400/auth', 'token_endpoint' => 'http://127.0.0.1:9400/token',
];
PHP
echo '{"steps":[{"step":"activatePlugin","pluginPath":"embed-drive/embed-drive.php"}]}' > "$WORK/blueprint.json"

node "$ROOT/tests/mock-google.mjs" &
(cd "$WORK" && npx -y @wp-playground/cli@3.1.61 server --port=9500 --php="$PHP_VERSION" --wp="$WP_VERSION" \
	--mount="$ROOT:/wordpress/wp-content/plugins/embed-drive" \
	--mount="$ROOT/tests/mock-endpoints.php:/wordpress/wp-content/mu-plugins/mock-endpoints.php" \
	--mount="$WORK/relay-test:/wordpress/relay-test" \
	--blueprint="$WORK/blueprint.json") > "$WORK/server.log" 2>&1 &

curl -s -o /dev/null --retry 200 --retry-delay 3 --retry-all-errors --max-time 20 http://127.0.0.1:9500/ || { cat "$WORK/server.log"; exit 1; }
node "$ROOT/tests/e2e.mjs" http://127.0.0.1:9500 http://127.0.0.1:9400
