#!/usr/bin/env bash
# Publishes a version to the WordPress.org plugin directory, which is a Subversion repository:
# the WordPress.org copy of the plugin goes to trunk and tags/VERSION, and .wordpress-org/ to assets.
# Usage: bin/deploy-wordpress-org.sh 1.2.3 [repository-url]
# Needs SVN_USERNAME and SVN_PASSWORD, except for a local file:// repository used in tests.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION="$1"
URL="${2:-https://plugins.svn.wordpress.org/filefolio}"
ROOT="$(pwd)"

grep -q "^Stable tag: ${VERSION}$" readme.txt || { echo "readme.txt Stable tag is not ${VERSION}"; exit 1; }

bin/build-zip.sh --wordpress-org
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
unzip -q build/embed-drive.zip -d "$WORK/plugin"

svn checkout -q --depth immediates "$URL" "$WORK/svn"
svn update -q --set-depth infinity "$WORK/svn/trunk" "$WORK/svn/assets"
svn update -q --set-depth immediates "$WORK/svn/tags"
if [ -e "$WORK/svn/tags/$VERSION" ]; then
	echo "tags/$VERSION is already on WordPress.org. Nothing was published."
	exit 1
fi

rsync -a --delete --exclude=.svn "$WORK/plugin/filefolio/" "$WORK/svn/trunk/"
rsync -a --delete --exclude=.svn "$ROOT/.wordpress-org/" "$WORK/svn/assets/"

cd "$WORK/svn"
svn add -q --force trunk assets
svn status | sed -n 's/^! *//p' | while IFS= read -r gone; do svn rm -q "$gone"; done
# Without these, browsers download the images instead of showing them.
for image in assets/*.png; do svn propset -q svn:mime-type image/png "$image"; done
for image in assets/*.svg; do svn propset -q svn:mime-type image/svg+xml "$image"; done
svn copy -q trunk "tags/$VERSION"

auth=()
if [ -n "${SVN_USERNAME:-}" ]; then
	auth=(--username "$SVN_USERNAME" --password "$SVN_PASSWORD" --no-auth-cache --non-interactive)
fi
svn commit -q -m "Release $VERSION" "${auth[@]}"
echo "Published $VERSION to WordPress.org."
