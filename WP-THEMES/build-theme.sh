#!/bin/bash
# usage: WP-THEMES/build-theme.sh SHEM|SHIMI   (env SCRATCH=dir for the throw-away build copy)
# Builds the unmodified React source in a scratch copy and packages dist/client into the WordPress theme.
set -euo pipefail
BRAND=${1:?SHEM or SHIMI}
R=/home/claude/shem-shop; T=$R/WP-THEMES
case $BRAND in
  SHEM)  SLUG=shem-storefront;  NAME="SHEM Storefront";  ORIGIN="https://shem-menswear.genial-comet-8090.chatgpt.site";;
  SHIMI) SLUG=shimi-storefront; NAME="SHIMI Storefront"; ORIGIN="https://shimi-basics.genial-comet-8090.chatgpt.site";;
  *) echo "unknown brand"; exit 1;;
esac
S=${SCRATCH:-/tmp/theme-build}/$BRAND; OUT=$T/$SLUG
mkdir -p "$S"; rm -rf "$S"; mkdir -p "$S"
tar -C "$R/$BRAND" --exclude=node_modules --exclude=dist -cf - . | tar -C "$S" -xf -
( cd "$S" && npm ci --no-audit --no-fund >/dev/null && npx vite build >/dev/null && node scripts/prepare-admin-share.mjs >/dev/null )
D=$S/dist/client
for f in index admin order-complete; do test -s "$D/$f.html" || { echo "missing $f.html"; exit 1; }; done
mkdir -p "$OUT"
rm -rf "$OUT/shell" "$OUT/public" "$OUT/inc"
mkdir -p "$OUT/shell" "$OUT/public" "$OUT/inc"
for f in index admin order-complete; do cp -p "$D/$f.html" "$OUT/shell/$f.html"; done
( cd "$D" && find . -type f ! -name index.html ! -name admin.html ! -name order-complete.html -print0 | while IFS= read -r -d '' f; do mkdir -p "$OUT/public/$(dirname "$f")"; cp -p "$f" "$OUT/public/$f"; done )
cp "$T/_shared/functions.php" "$T/_shared/index.php" "$T/_shared/404.php" "$OUT/"
cp "$T/_shared/inc/disable-extras.php" "$T/_shared/inc/shell.php" "$T/_shared/inc/static.php" "$OUT/inc/"
cat > "$OUT/inc/config.php" <<PHP
<?php
if (!defined('ABSPATH')) exit;
return ['brand' => '$BRAND', 'legacy_origin' => '$ORIGIN'];
PHP
cat > "$OUT/style.css" <<CSS
/*
Theme Name: $NAME
Description: Serves the existing $BRAND React storefront (same DOM, CSS, fonts and assets as the original build) from WordPress. Commerce API lives in the shem-shimi-commerce plugin. No visual design lives in this file.
Author: SHEM-SHOP migration
Version: 0.1.0
Requires PHP: 8.1
Text Domain: $SLUG
*/
CSS
# byte-for-byte check of everything copied
( cd "$D" && find . -type f ! -name index.html ! -name admin.html ! -name order-complete.html -exec sha256sum {} + | sort -k2 ) > "$S/src.sha"
( cd "$OUT/public" && find . -type f -exec sha256sum {} + | sort -k2 ) > "$S/dst.sha"
diff -q "$S/src.sha" "$S/dst.sha" && echo "$SLUG: $(wc -l < "$S/dst.sha") static files byte-identical to vite output"
cp "$S/src.sha" "$OUT/public.sha256"
