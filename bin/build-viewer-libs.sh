#!/usr/bin/env bash
# Rebuilds PDF.js and docx-preview in assets/viewer/lib/. Packages are only downloaded and unpacked,
# never installed, so none of their install tools or dependencies run or reach the plugin.
#
# PDF.js ships only as ES modules, which a sandboxed frame can't load without CORS headers from
# the host, so it is bundled into plain scripts. Stay on 4.x: from 5.0 it fetches its image
# decoders at run time, which the sandbox blocks.
set -euo pipefail
PDFJS=4.10.38
DOCX=0.4.1
ESBUILD=0.24.2

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LIB="$ROOT/assets/viewer/lib"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
cd "$WORK"

npm pack --silent "pdfjs-dist@$PDFJS" "docx-preview@$DOCX" > /dev/null
mkdir pdf docx
tar xzf "pdfjs-dist-$PDFJS.tgz" -C pdf
tar xzf "docx-preview-$DOCX.tgz" -C docx

bundle() {
	npx -y "esbuild@$ESBUILD" "$1" --bundle --minify --format=iife --target=es2020 --platform=browser \
		--define:import.meta.url='""' --legal-comments=inline --log-level=warning ${2:+--global-name="$2"} --outfile="$3"
}
bundle pdf/package/legacy/build/pdf.mjs pdfjsLib "$LIB/pdf.min.js"
bundle pdf/package/legacy/build/pdf.worker.mjs '' "$LIB/pdf.worker.min.js"
cp docx/package/dist/docx-preview.min.js "$LIB/docx-preview.min.js"
sed -i.bak '/sourceMappingURL/d' "$LIB/docx-preview.min.js" && rm -f "$LIB/docx-preview.min.js.bak"
