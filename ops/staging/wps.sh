#!/bin/bash
# usage: wps.sh shem|shimi <wp-cli args>   (WP-CLI inside the staging container, as www-data)
S=${1:?shem|shimi}; shift
C=shem-staging-$S-1
if [ "${1:-}" = "db" ] && [ "${2:-}" = "query" ]; then
  # container has no mysql client: run the query in the db container (same DB) with wp-cli-like flags
  Q=$3; shift 3; ARGS=(-B); for a in "$@"; do [ "$a" = "--skip-column-names" ] && ARGS+=(-N); done
  set -a; . /home/claude/staging/.env; set +a
  exec docker exec -i shem-staging-db-1 mariadb -uroot -p"$DB_ROOT" "wp_$S" "${ARGS[@]}" -e "$Q"
fi
docker exec $C test -x /usr/local/bin/wp || { docker cp /home/claude/staging/wp.phar $C:/usr/local/bin/wp && docker exec -u 0 $C chmod 755 /usr/local/bin/wp; }
# WPS_DB (optional): point wp-cli at another database (scratch clone for tests) via WORDPRESS_DB_NAME; the db user needs a grant on it
exec docker exec -u 33 -e HOME=/tmp ${WPS_DB:+-e WORDPRESS_DB_NAME=$WPS_DB} $C wp --path=/var/www/html "$@"
