#!/bin/bash
# SHEM DOK - test fixtures for the LOCAL staging only. Never run against a live site.
# usage: staging-fixtures.sh setup|teardown
set -e
W=/home/claude/wps.sh; C=shem-staging-shem-1
HERE="$(cd "$(dirname "$0")" && pwd)"
case "$1" in
 setup)
  docker cp "$HERE/e2e-mailcap.php" $C:/var/www/html/wp-content/mu-plugins/e2e-mailcap.php
  docker exec $C chown www-data:www-data /var/www/html/wp-content/mu-plugins/e2e-mailcap.php
  $W shem option update shem_e2e_mailcap 1 >/dev/null
  $W shem user get buyer1 >/dev/null 2>&1 || $W shem user create buyer1 buyer1@example.test --role=customer --user_pass="$(openssl rand -hex 12)" >/dev/null
  $W shem user get shopmgr >/dev/null 2>&1 || $W shem user create shopmgr mgr@example.test --role=shop_manager --user_pass="$(openssl rand -hex 12)" >/dev/null
  $W shem option update woocommerce_coming_soon no >/dev/null
  umask 077
  $W shem config get SHEM_HOME_PAYMENT_SECRET > /home/claude/.stg-home-secret
  $W shem user application-password create localadmin e2e --porcelain | tail -1 > /home/claude/.stg-apppass
  echo "setup done" ;;
 teardown)
  docker exec $C rm -f /var/www/html/wp-content/mu-plugins/e2e-mailcap.php
  $W shem option delete shem_e2e_mailcap shem_e2e_mails >/dev/null 2>&1 || true
  $W shem user delete buyer1 shopmgr --yes >/dev/null 2>&1 || true
  $W shem user application-password delete localadmin --all >/dev/null 2>&1 || true
  rm -f /home/claude/.stg-apppass /home/claude/.stg-home-secret /home/claude/.stg-login-buyer1 /home/claude/.stg-login-shopmgr /home/claude/.stg-pw-buyer1 /home/claude/.stg-pw-mgr
  echo "teardown done (woocommerce_coming_soon left unchanged)" ;;
 *) echo "usage: $0 setup|teardown"; exit 1 ;;
esac
