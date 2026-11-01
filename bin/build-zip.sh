#!/usr/bin/env bash
# Builds build/embed-drive.zip, the installable plugin. Excludes the relay and everything dev-only.
set -euo pipefail
cd "$(dirname "$0")/.."

rm -rf build
mkdir -p build/embed-drive
cp -R embed-drive.php uninstall.php readme.txt LICENSE includes assets blocks languages build/embed-drive/
find build -name '.DS_Store' -delete
(cd build && zip -qr embed-drive.zip embed-drive)
rm -rf build/embed-drive
echo "Built build/embed-drive.zip"
