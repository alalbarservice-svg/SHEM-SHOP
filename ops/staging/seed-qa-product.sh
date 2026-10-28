#!/bin/bash
# Creates the e2e QA product qa-shem-knit-20261009 on the LOCAL SHEM staging only (never run against a live site).
# Source of truth: repo fixture WP-PLUGIN/shem-shimi-commerce/tests/fixtures/shem-menswear-catalogue.json (product id qa-shem-knit-20261009:
# variable product, sizes M and L, SKU QA-OCT09-KNIT, regular 219 / sale 179, variants QA-OCT09-KNIT-M and -L, stock 10 each).
# Method: the plugin's own Scc_Core::upsert_product_from_legacy (same code path as `wp scc import`), run quietly (no cross-store event).
# The WordPress post ids (product 58, variations 59 and 60 on the outgoing staging) are NOT stable: a new staging gets other ids.
# The tests find the variation by the meta _scc_legacy_variant_id, and this script prints the ids it finds.
#
# usage: seed-qa-product.sh              create when missing, otherwise only verify (no write)
#        seed-qa-product.sh --reset      force-rewrite to the fixture baseline (stock 10/10), then verify (WRITES to live staging)
#        seed-qa-product.sh --dry-run    report would_create / would_update / unchanged, write nothing
#        seed-qa-product.sh --selftest   prove "create from scratch" on a SCRATCH CLONE database: clone wp_shem, delete the product
#                                        there, create it, verify, drop the clone. Live wp_shem is checksum-compared before and after.
# env:   W (wp-cli wrapper, default ./wps.sh), C (SHEM container, default shem-staging-shem-1), FIX (fixture path)
# exit:  0 ok, 2 fixture or guard problem, 3 verification mismatch
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
W="${W:-$HERE/wps.sh}"; C="${C:-shem-staging-shem-1}"; DBC="${DBC:-shem-staging-db-1}"
FIX="${FIX:-$HERE/../../WP-PLUGIN/shem-shimi-commerce/tests/fixtures/shem-menswear-catalogue.json}"
case "${1:-}" in ''|--create) MODE=create ;; --reset) MODE=reset ;; --dry-run) MODE=dry ;; --selftest) MODE=selftest ;; *) echo "usage: $0 [--reset|--dry-run|--selftest]"; exit 2 ;; esac

[ -f "$FIX" ] || { echo "FAIL fixture not found: $FIX"; exit 2; }
URL="$($W shem option get siteurl)"
case "$URL" in http://localhost:*|http://127.0.0.1:*) ;; *) echo "FAIL refusing to run: siteurl is $URL (local staging only)"; exit 2 ;; esac
[ "$($W shem eval 'echo class_exists("Scc_Core") && function_exists("wc_get_product") ? "y" : "n";')" = "y" ] || { echo "FAIL plugin shem-shimi-commerce or WooCommerce not active on SHEM"; exit 2; }

PHP="$(mktemp)"; SCR=""
dbx() { docker exec -i "$DBC" mariadb -uroot -p"$DB_ROOT" "$@"; }
cleanup() {
  rm -f "$PHP"
  if [ -n "$SCR" ]; then dbx -e "DROP DATABASE IF EXISTS \`$SCR\`" >/dev/null 2>&1 || echo "WARN scratch DB $SCR was not dropped; drop it manually"; fi
}
trap cleanup EXIT
cat > "$PHP" <<'EOF'
<?php
// args[0] = create|reset|dry|selftest
$mode = $args[0] ?? 'create';
$LID = 'qa-shem-knit-20261009';
$cat = json_decode((string) file_get_contents('/tmp/qa-catalogue-fixture.json'), true);
$doc = null; foreach (($cat['products'] ?? []) as $p) if (($p['id'] ?? '') === $LID) $doc = $p;
if (!$doc) { fwrite(STDERR, "FAIL fixture has no product $LID\n"); exit(2); }
$expect = ['M' => 10, 'L' => 10, 'sku' => 'QA-OCT09-KNIT', 'regular' => '219', 'sale' => '179'];
global $wpdb;
if ($mode === 'selftest') {
    // hard guard: the destructive step below may only ever run on a scratch clone
    if (in_array(DB_NAME, ['wp_shem', 'wp_shimi'], true) || strpos(DB_NAME, 'scc_seedtest_') !== 0) {
        fwrite(STDERR, 'FAIL selftest refuses to run on database ' . DB_NAME . "\n"); exit(2);
    }
    $old = (int) Scc_Core::find_product_id($LID);
    if ($old) {
        foreach (wc_get_product($old)->get_children() as $c) wp_delete_post($c, true);
        wp_delete_post($old, true);
    }
    if ((int) Scc_Core::find_product_id($LID)) { fwrite(STDERR, "FAIL could not remove product in the clone\n"); exit(2); }
    echo 'SELFTEST db=' . DB_NAME . ' product removed in clone (old id ' . $old . ")\n";
}
$verify = function () use ($LID, $expect) {
    $bad = [];
    $pid = Scc_Core::find_product_id($LID);
    if (!$pid) return ['pid' => 0, 'vars' => [], 'bad' => ['product missing: ' . $LID]];
    $p = wc_get_product($pid);
    if (!$p || !$p->is_type('variable')) $bad[] = 'not a variable product';
    if ($p && $p->get_status() !== 'publish') $bad[] = 'status ' . $p->get_status();
    if ($p && $p->get_sku() !== $expect['sku']) $bad[] = 'sku ' . $p->get_sku();
    $vars = Scc_Core::variation_map($pid);
    foreach (['M', 'L'] as $s) {
        $vid = $vars[$LID . '-' . $s] ?? 0;
        if (!$vid) { $bad[] = 'variation missing: ' . $s; continue; }
        $v = wc_get_product($vid);
        if (!$v->get_manage_stock()) $bad[] = "$s manage_stock off";
        if ((int) $v->get_stock_quantity() !== $expect[$s]) $bad[] = "$s stock " . $v->get_stock_quantity() . ' expected ' . $expect[$s];
        if ($v->get_stock_status() !== 'instock') $bad[] = "$s status " . $v->get_stock_status();
        if ($v->get_regular_price() !== $expect['regular'] || $v->get_sale_price() !== $expect['sale']) $bad[] = "$s price " . $v->get_regular_price() . '/' . $v->get_sale_price();
        if (get_post_meta($vid, '_scc_legacy_variant_id', true) !== $LID . '-' . $s) $bad[] = "$s legacy variant meta";
    }
    return ['pid' => $pid, 'vars' => $vars, 'bad' => $bad];
};
$exists = (int) Scc_Core::find_product_id($LID);
if ($mode === 'dry') {
    $r = Scc_Core::upsert_product_from_legacy($doc, ['dry_run' => true]);
    echo 'DRY-RUN action=' . $r['action'] . " wc_id=" . $r['wc_id'] . "\n"; exit(0);
}
if ($mode === 'create' && $exists) {
    $v = $verify();
    echo "EXISTS product=" . $v['pid'] . ' variations=' . json_encode($v['vars']) . "\n";
    if ($v['bad']) { echo "MISMATCH (not written; use --reset): " . implode('; ', $v['bad']) . "\n"; exit(3); }
    echo "VERIFIED baseline matches (no write)\n"; exit(0);
}
$r = Scc_Core::upsert_product_from_legacy($doc, in_array($mode, ['create', 'selftest'], true) ? [] : ['force' => true]);
echo 'UPSERT action=' . $r['action'] . ' product=' . $r['wc_id'] . ' variations=' . json_encode($r['variations']) . ($r['warnings'] ? ' warnings=' . json_encode($r['warnings']) : '') . "\n";
$v = $verify();
if ($mode === 'selftest' && $r['action'] !== 'created') $v['bad'][] = 'expected action=created, got ' . $r['action'];
if ($v['bad']) echo 'MISMATCH: ' . implode('; ', $v['bad']) . "\n"; else echo "VERIFIED product and both variations match the fixture\n";
exit($v['bad'] ? 3 : 0);
EOF
chmod 644 "$PHP"

if [ "$MODE" = selftest ]; then
  set -a; . /home/claude/staging/.env; set +a    # staging-only DB root password, read only, never printed
  SCR="scc_seedtest_$$"
  sum() { dbx -N -e "CHECKSUM TABLE wp_shem.wp_posts, wp_shem.wp_postmeta, wp_shem.wp_options, wp_shem.wp_term_relationships" | tr '\n' ' '; }
  BEFORE="$(sum)"
  dbx -e "CREATE DATABASE \`$SCR\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON \`$SCR\`.* TO 'wp'@'%'; FLUSH PRIVILEGES;"
  docker exec "$DBC" mariadb-dump -uroot -p"$DB_ROOT" --single-transaction wp_shem | dbx "$SCR"
  export WPS_DB="$SCR"
fi

docker cp "$FIX" "$C:/tmp/qa-catalogue-fixture.json"
docker cp "$PHP" "$C:/tmp/qa-seed.php"
rc=0; $W shem eval-file /tmp/qa-seed.php "$MODE" || rc=$?
docker exec -u 0 "$C" rm -f /tmp/qa-catalogue-fixture.json /tmp/qa-seed.php

if [ "$MODE" = selftest ]; then
  unset WPS_DB
  dbx -e "DROP DATABASE \`$SCR\`" && SCR=""
  AFTER="$(sum)"
  if [ "$BEFORE" = "$AFTER" ]; then echo "LIVE wp_shem UNCHANGED (checksum before = after); scratch DB dropped"
  else echo "FAIL live wp_shem checksum changed during selftest"; echo "before: $BEFORE"; echo "after:  $AFTER"; rc=3; fi
fi
exit $rc
