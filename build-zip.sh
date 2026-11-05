#!/usr/bin/env bash
# Builds an installable plugin zip: dist/peak-seo-<version>.zip
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(grep -m1 "Version:" peak-seo/peak-seo.php | awk '{print $NF}')
mkdir -p dist
rm -f "dist/peak-seo-${VERSION}.zip"
zip -rq "dist/peak-seo-${VERSION}.zip" peak-seo -x '*.DS_Store'
echo "dist/peak-seo-${VERSION}.zip"
