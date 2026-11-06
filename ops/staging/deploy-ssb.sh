#!/bin/bash
# Deploys ONLY the shem-support-bot plugin to the SHEM staging container (never SHIMI, never production).
set -e
R=/home/claude/shem-shop; C=shem-staging-shem-1; D=/var/www/html/wp-content/plugins/shem-support-bot
for f in $R/WP-PLUGIN/shem-support-bot/shem-support-bot.php $R/WP-PLUGIN/shem-support-bot/includes/*.php; do docker exec -i $C php -l < $f >/dev/null || { echo "LINT FAIL $f"; exit 1; }; done
docker exec -u 0 $C mkdir -p $D
tar -C $R/WP-PLUGIN/shem-support-bot --exclude=tests -cf - . | docker exec -i -u 0 $C tar -C $D -xf -
docker exec -u 0 $C chown -R 33:33 $D
echo SSB_DEPLOYED
