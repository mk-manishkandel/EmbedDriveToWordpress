#!/usr/bin/env bash
# Builds build/embed-drive.zip, the installable plugin. Excludes the relay and everything dev-only.
# With --wordpress-org, builds the copy for the WordPress.org directory instead: WordPress.org
# delivers its own updates and doesn't allow a plugin to update itself, so the GitHub updater is left out.
set -euo pipefail
cd "$(dirname "$0")/.."

rm -rf build
mkdir -p build/embed-drive
cp -R embed-drive.php uninstall.php readme.txt LICENSE includes assets blocks languages build/embed-drive/
find build -name '.*' -type f -delete
if [ "${1:-}" = "--wordpress-org" ]; then
	rm build/embed-drive/includes/Updater.php
	sed -i.bak '/^ \* Update URI:/d' build/embed-drive/embed-drive.php
	sed -i.bak '/^\* \*\*GitHub\*\*/d' build/embed-drive/readme.txt
	rm build/embed-drive/*.bak
	! grep -q 'Update URI\|api.github.com' build/embed-drive/embed-drive.php build/embed-drive/readme.txt
fi
(cd build && zip -qr embed-drive.zip embed-drive)
rm -rf build/embed-drive
echo "Built build/embed-drive.zip${1:+ ($1)}"
