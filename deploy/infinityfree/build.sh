#!/usr/bin/env bash
# Build the folder to upload to shared PHP hosting (InfinityFree, Byet.host).
#
#   deploy/infinityfree/build.sh [output-dir]      (default: build/htdocs)
#
# The repository keeps the web root (public/) and the code (src/) side by
# side. Shared hosting has one folder (htdocs), so this script lays them out
# as htdocs/ + htdocs/src/ and rewrites the require paths to match
# ('/../src/' -> '/src/' for top-level pages, '/../../src/' -> '/../src/' for
# /admin). It never touches your working tree and never includes secrets:
# you add hosting_config.php yourself (see docs/DEPLOYMENT.md).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${1:-$ROOT/build/htdocs}"

rm -rf "$OUT"
mkdir -p "$OUT/src" "$OUT/database" "$OUT/uploads"

# Web root: pages, built assets. No Docker helper scripts, no user uploads.
cp -r "$ROOT/public/." "$OUT/"
rm -rf "$OUT/docker" "$OUT/uploads"/* "$OUT/robots.txt"
mkdir -p "$OUT/uploads"

# Code and migrations.
cp -r "$ROOT/src/includes" "$ROOT/src/cli" "$ROOT/src/vendor" "$OUT/src/"
cp "$ROOT/deploy/infinityfree/install.sql" "$ROOT/deploy/infinityfree/037_utf8mb4_japanese.sql" "$OUT/database/"

# Apache rules (the repo ships them as .txt so they are not live here).
cp "$ROOT/deploy/infinityfree/htaccess.txt"         "$OUT/.htaccess"
cp "$ROOT/deploy/infinityfree/src.htaccess.txt"     "$OUT/src/.htaccess"
cp "$ROOT/deploy/infinityfree/src.htaccess.txt"     "$OUT/database/.htaccess"
cp "$ROOT/deploy/infinityfree/uploads.htaccess.txt" "$OUT/uploads/.htaccess"
cp "$ROOT/deploy/infinityfree/robots.txt"           "$OUT/robots.txt"

# Rewrite require paths for the flat layout.
find "$OUT" -maxdepth 1 -name '*.php' -print0 | xargs -0 sed -i "s#__DIR__ \. '/\.\./src/#__DIR__ . '/src/#g"
find "$OUT/admin" -name '*.php' -print0      | xargs -0 sed -i "s#'/\.\./\.\./src/#'/../src/#g"
# header.php finds built assets next to src/ instead of under public/.
sed -i "s#/\.\./\.\./public/assets/#/../../assets/#" "$OUT/src/includes/header.php"

echo "Built $OUT"
echo "Next: copy hosting_config.example.php to hosting_config.php, fill it in, upload both to htdocs/."
