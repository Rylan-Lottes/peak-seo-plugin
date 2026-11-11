#!/usr/bin/env bash
# Builds an installable plugin zip: dist/peak-seo-<version>.zip
# Uses `zip` when installed, otherwise Python (tools/build_zip.py).
set -euo pipefail
cd "$(dirname "$0")/.."

if ! command -v zip >/dev/null 2>&1; then
	for py in python3 python py; do
		if command -v "$py" >/dev/null 2>&1 && "$py" -c 'import sys; sys.exit(sys.version_info < (3,))' 2>/dev/null; then
			exec "$py" tools/build_zip.py
		fi
	done
	echo "Neither zip nor Python 3 is installed." >&2
	exit 1
fi

VERSION=$(grep -m1 "Version:" peak-seo/peak-seo.php | awk '{print $NF}' | tr -d '\r')
mkdir -p dist
rm -f "dist/peak-seo-${VERSION}.zip"
zip -rq "dist/peak-seo-${VERSION}.zip" peak-seo -x '*.DS_Store'
echo "dist/peak-seo-${VERSION}.zip"
