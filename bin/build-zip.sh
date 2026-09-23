#!/usr/bin/env bash
#
# Builds a clean, distributable certiva-<version>.zip containing only the
# files needed to run the plugin — no tests, no dev tooling, no OS cruft.
#
# Usage: bin/build-zip.sh
#
# Requires: rsync, zip. If `composer` is on PATH, vendor/ is rebuilt with
# --no-dev -o first so the zip never accidentally ships PHPUnit and friends;
# otherwise the script uses whatever is already in vendor/ and warns you to
# make sure it was built with --no-dev yourself (see README.md).

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="certiva"

cd "$PLUGIN_DIR"

VERSION=$(grep -m1 "Version:" certiva.php | sed -E 's/.*Version:[[:space:]]*([0-9A-Za-z.\-]+).*/\1/')
if [ -z "$VERSION" ]; then
	echo "Could not determine plugin version from certiva.php" >&2
	exit 1
fi

DIST_DIR="$PLUGIN_DIR/dist"
BUILD_DIR="$DIST_DIR/build/$PLUGIN_SLUG"
ZIP_PATH="$DIST_DIR/${PLUGIN_SLUG}-${VERSION}.zip"

echo "Building ${PLUGIN_SLUG} v${VERSION}..."

if command -v composer >/dev/null 2>&1; then
	echo "Rebuilding vendor/ with composer (--no-dev -o)..."
	composer install --no-dev -o --no-interaction
else
	echo "Warning: composer not found on PATH — using vendor/ as-is." >&2
	echo "         Make sure it was built with 'composer install --no-dev -o' (see README.md)." >&2
fi

rm -rf "$DIST_DIR/build"
mkdir -p "$BUILD_DIR"

# Only what the plugin needs at runtime. Everything else (tests, dev
# tooling, build artifacts, VCS/editor/OS files) is left out explicitly
# rather than relying on what happens to be in the working directory.
rsync -a \
	--exclude 'dist' \
	--exclude 'tests' \
	--exclude 'bin' \
	--exclude '.git' \
	--exclude '.gitignore' \
	--exclude '.gitattributes' \
	--exclude '.github' \
	--exclude 'node_modules' \
	--exclude 'phpunit.xml' \
	--exclude 'phpunit.xml.dist' \
	--exclude '.phpunit.result.cache' \
	--exclude 'composer.lock' \
	--exclude '.DS_Store' \
	--exclude '*.log' \
	--exclude '.idea' \
	--exclude '.vscode' \
	./ "$BUILD_DIR/"

find "$BUILD_DIR" -name '.DS_Store' -delete

mkdir -p "$DIST_DIR"
rm -f "$ZIP_PATH"
( cd "$DIST_DIR/build" && zip -rq "$ZIP_PATH" "$PLUGIN_SLUG" )

rm -rf "$DIST_DIR/build"

echo "Built: $ZIP_PATH"
unzip -l "$ZIP_PATH" | tail -5
