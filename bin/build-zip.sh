#!/usr/bin/env bash
#
# Builds dist/sokolipa-checkout.zip: exactly the files WordPress needs, and nothing
# else (no tests, CI configuration or development tools). This is the zip to upload
# to a store, attach to a GitHub release or send to WordPress.org.
#
set -euo pipefail

cd "$(dirname "$0")/.."

SLUG="sokolipa-checkout"
FILES=(sokolipa-checkout.php uninstall.php readme.txt license.txt includes assets)

rm -rf dist
mkdir -p "dist/$SLUG"
cp -R "${FILES[@]}" "dist/$SLUG/"
find "dist/$SLUG" -name '.DS_Store' -delete

(cd dist && zip -rqX "$SLUG.zip" "$SLUG")
rm -rf "dist/$SLUG"

echo "Built dist/$SLUG.zip"
unzip -l "dist/$SLUG.zip" | tail -n 1
