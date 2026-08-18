#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
plugin_dir="$repo_root"
output_dir="$repo_root/dist"
slug="rjm-css-advisor"

version="$(awk -F': *' '/^[[:space:]]*\* Version:/ {print $2; exit}' "$plugin_dir/rjm-css-advisor.php")"
if [[ -z "$version" ]]; then
	echo "Unable to read plugin version from $plugin_dir/rjm-css-advisor.php" >&2
	exit 1
fi

archive="$output_dir/$slug-$version.zip"
mkdir -p "$output_dir"
rm -f "$archive"

# Stage under the plugin slug so the zip extracts to a WP-compatible plugin folder.
stage_dir="$(mktemp -d)"
trap 'rm -rf "$stage_dir"' EXIT
mkdir -p "$stage_dir/$slug"
rsync -a --exclude='.git' --exclude='.github' --exclude='dist' --exclude='.DS_Store' \
	"$repo_root/" "$stage_dir/$slug/"

pushd "$stage_dir" >/dev/null
zip -qr "$archive" "$slug"
popd >/dev/null

echo "$archive"
