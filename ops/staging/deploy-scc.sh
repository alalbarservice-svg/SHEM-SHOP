#!/bin/bash
# PHP lint of plugin+themes, then copy plugin and themes into both staging sites (staging only).
set -euo pipefail
R=/home/claude/shem-shop
for f in $(find $R/WP-PLUGIN/shem-shimi-commerce $R/WP-THEMES -name '*.php' -not -path '*/node_modules/*' -not -path '*/tests/*'); do
  docker exec -i shem-staging-shem-1 php -l /dev/stdin < "$f" >/dev/null 2>&1 || { echo "LINT FAIL $f"; exit 1; }
done
for S in shem shimi; do
  C=shem-staging-$S-1
  docker exec -u 0 $C mkdir -p /var/www/html/wp-content/plugins/shem-shimi-commerce /var/www/html/wp-content/themes
  tar -C $R/WP-PLUGIN/shem-shimi-commerce --exclude=tests -cf - . | docker exec -i -u 0 $C tar -C /var/www/html/wp-content/plugins/shem-shimi-commerce -xf -
  tar -C $R/WP-THEMES -cf - shem-storefront shimi-storefront | docker exec -i -u 0 $C tar -C /var/www/html/wp-content/themes -xf -
  docker exec -u 0 $C chown -R 33:33 /var/www/html/wp-content/plugins/shem-shimi-commerce /var/www/html/wp-content/themes
done
echo deployed
