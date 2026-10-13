#!/usr/bin/env bash
#
# Builds the distributable plugin zip: dist/sendfleet-<version>.zip
#
#   bash bin/build.sh
#
# The zip contains exactly what WordPress.org receives: everything except the
# paths listed in .distignore. The script refuses to build when the three
# version numbers (plugin header, SENDFLEET_VERSION and readme.txt
# "Stable tag") disagree.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="sendfleet"
cd "$ROOT"

header_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' sendfleet.php | tr -d '\r')"
const_version="$(sed -n "s/^define( 'SENDFLEET_VERSION', '\([^']*\)' );/\1/p" sendfleet.php)"
stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | tr -d '\r')"

if [[ "$header_version" != "$const_version" || "$header_version" != "$stable_tag" ]]; then
	echo "Version mismatch: header=$header_version constant=$const_version stable_tag=$stable_tag" >&2
	exit 1
fi

VERSION="$header_version"
BUILD="$ROOT/build/$SLUG"
DIST="$ROOT/dist"

rm -rf "$ROOT/build" && mkdir -p "$BUILD" "$DIST"

# Copy everything not excluded by .distignore.
excludes=()
while IFS= read -r line || [[ -n "$line" ]]; do
	line="${line%%#*}"
	line="$(echo "$line" | xargs)"
	[[ -z "$line" ]] && continue
	excludes+=("--exclude=$line")
done < .distignore

rsync -a "${excludes[@]}" ./ "$BUILD/"

# Syntax-check what ships.
if command -v php >/dev/null 2>&1; then
	while IFS= read -r -d '' file; do
		php -l "$file" >/dev/null
	done < <(find "$BUILD" -name '*.php' -print0)
fi

rm -f "$DIST/$SLUG-$VERSION.zip"
(cd "$ROOT/build" && zip -qr "$DIST/$SLUG-$VERSION.zip" "$SLUG")

echo "Built $DIST/$SLUG-$VERSION.zip"
(cd "$ROOT/build" && find "$SLUG" -type f | sort)
