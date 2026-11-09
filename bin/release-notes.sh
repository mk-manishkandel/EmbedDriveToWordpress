#!/usr/bin/env bash
# Prints a version's entry from the readme.txt changelog, for the GitHub release notes.
# Usage: bin/release-notes.sh 1.2.3
set -euo pipefail
cd "$(dirname "$0")/.."
awk -v v="= $1 =" '$0 == v { found = 1; next } found && /^= / { exit } found' readme.txt | sed '/^$/d'
