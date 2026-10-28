#!/bin/bash
# usage: backup-staging.sh <label>  -> dumps wp_shem and wp_shimi, verifies each (gzip -t, table count vs live, restore into scratch DB + row counts)
set -euo pipefail
L=${1:?label}; B=/home/claude/backups-staging; ts=$(date +%m%d-%H%M)
set -a; . /home/claude/staging/.env; set +a
D="docker exec -i shem-staging-db-1"; M="mariadb -uroot -p$DB_ROOT"
for s in shem shimi; do
  F=$B/$L-$s-$ts.sql.gz
  $D mariadb-dump -uroot -p"$DB_ROOT" --single-transaction --routines --triggers "wp_$s" | gzip > "$F"
  gzip -t "$F"
  live=$($D $M -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='wp_$s'")
  dumped=$(zcat "$F" | grep -c '^CREATE TABLE')
  $D $M -e "DROP DATABASE IF EXISTS vfy_tmp; CREATE DATABASE vfy_tmp"
  zcat "$F" | $D $M vfy_tmp
  mism=0
  for t in $($D $M -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='wp_$s'"); do
    a=$($D $M -N -e "SELECT COUNT(*) FROM wp_$s.\`$t\`"); b=$($D $M -N -e "SELECT COUNT(*) FROM vfy_tmp.\`$t\`")
    [ "$a" = "$b" ] || { echo "ROWS DIFFER $t live=$a dump=$b"; mism=$((mism+1)); }
  done
  $D $M -e "DROP DATABASE vfy_tmp"
  echo "$s: file=$(basename $F) size=$(stat -c %s $F) tables live=$live dump=$dumped row-mismatch=$mism"
  [ "$live" = "$dumped" ] && [ "$mism" = 0 ] || { echo "BACKUP VERIFY FAILED $s"; exit 1; }
done
echo BACKUP_OK
