<?php
/**
 * Live two-site scenario helper (staging only). Usage:  ./wps.sh shem|shimi eval-file wp-content/plugins/shem-shimi-commerce/tests/channel/live/live-php.php <command> [args]
 * Real WooCommerce products (legacy ids t-channel-live-*), real HTTP between the sites. The channel secret is read from wp-config and is never printed:
 * `sign` prints only a timestamp and a signature that is valid for 5 minutes for that exact method+path+body.
 */
if (!defined('ABSPATH')) exit;
$cmd = $args[0] ?? '';
$out = function ($v) { echo '@@' . wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"; };
$ids = array_merge(['t-channel-live-a', 't-channel-live-big', 't-channel-live-h', 't-channel-live-dl'], array_map(fn($i) => 't-channel-live-r' . $i, range(1, 12)));
$wc_by_legacy = function (string $legacy) { $q = get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_scc_legacy_id', 'meta_value' => $legacy]); return $q ? (int) $q[0] : 0; };
$mk = function (string $legacy, int $stock, string $sku, string $price = '100') use ($wc_by_legacy) {
    $old = $wc_by_legacy($legacy); if ($old) wp_delete_post($old, true);
    $p = new WC_Product_Simple(); $p->set_name('t_channel live ' . $legacy); $p->set_regular_price($price); $p->set_manage_stock(true); $p->set_stock_quantity($stock); $p->set_status('publish'); $p->set_sku($sku);
    $id = $p->save(); update_post_meta($id, '_scc_legacy_id', $legacy); return $id;
};
$view = function (string $legacy) {
    $p = Scc_Channel_Catalog::get($legacy, true); if (!$p) return null;
    $v = Scc_Channel_Stock::view($p);
    return ['id' => $legacy, 'price' => $p['price'], 'sku' => $p['sku'] ?? '', 'stock_woo' => $p['stock'], 'stock_sellable' => $v['stock'], 'link' => Scc_Channel_Links::get($legacy)];
};
global $wpdb;
switch ($cmd) {
    case 'role': $out(['role' => Scc_Channel::role(), 'peer' => Scc_Channel::peer_url(), 'secret_configured' => Scc_Channel::secret() !== '']); break;
    case 'setup-source':
        $mk('t-channel-live-a', 3, 'T-CHANNEL-LIVE-A', '100'); $mk('t-channel-live-big', 5, 'T-CHANNEL-LIVE-BIG');
        for ($i = 1; $i <= 12; $i++) $mk('t-channel-live-r' . $i, 1, 'T-CHANNEL-LIVE-R' . $i);
        $out(['created' => 14]); break;
    case 'setup-home': $mk('t-channel-live-h', 0, 'T-HOME-OLD', '1'); $out(['created' => 1]); break;
    case 'link':
        $r = Scc_Channel_Links::link('t-channel-live-h', ['sourceUrl' => rtrim(Scc_Channel::peer_url(), '/') . '/api/channel/source/t-channel-live-a', 'variantMap' => []]);
        $out(['linked' => true, 'card' => $view('t-channel-live-h')]); break;
    case 'show': $out($view($args[1])); break;
    case 'set-price':
        $p = wc_get_product($wc_by_legacy($args[1])); $p->set_regular_price($args[2]); $p->save(); $out(['price' => $args[2]]); break;
    case 'set-stock':
        wc_update_product_stock(wc_get_product($wc_by_legacy($args[1])), (int) $args[2], 'set'); do_action('woocommerce_update_product', $wc_by_legacy($args[1]), wc_get_product($wc_by_legacy($args[1]))); $out(['stock' => (int) $args[2]]); break;
    case 'sign': {      // sign <path> <raw body>  -> ts and signature (secret stays inside)
        $ts = (string) Scc_Channel::ts(); $out(['ts' => $ts, 'sig' => Scc_Channel::sign(Scc_Channel::secret(), implode('.', [$ts, 'POST', $args[1], $args[2]]))]); break; }
    case 'sign-many': {   // sign-many <path> <file with one raw body per line> -> JSON list
        $res = []; foreach (file($args[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) { $ts = (string) Scc_Channel::ts(); $res[] = ['body' => $raw, 'ts' => $ts, 'sig' => Scc_Channel::sign(Scc_Channel::secret(), implode('.', [$ts, 'POST', $args[1], $raw]))]; }
        $out($res); break; }
    case 'holds': {     // holds <legacy id>
        $t = Scc_Channel_Tx::t('reservations'); $rows = $wpdb->get_results($wpdb->prepare("SELECT state,SUM(qty) q,COUNT(DISTINCT reservation_id) n FROM $t WHERE product_id=%s GROUP BY state", $args[1]), ARRAY_A);
        $led = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Scc_Channel_Tx::t('ledger') . ' WHERE product_id=%s', $args[1]));
        $out(['by_state' => $rows, 'ledger_rows' => $led, 'product' => $view($args[1])]); break; }
    case 'outbox': { $like = '%' . $wpdb->esc_like($args[1] ?? 't-channel-live') . '%';
        $out($wpdb->get_results($wpdb->prepare('SELECT event_id,kind,state,attempts,max_attempts,last_status,last_error FROM ' . Scc_Channel_Outbox::t() . ' WHERE event_id LIKE %s OR payload LIKE %s ORDER BY created_at', $like, $like), ARRAY_A)); break; }
    case 'hydrate': { $p = Scc_Channel_Catalog::get($args[1], true); $h = Scc_Channel_Links::hydrate([$p])[0]; $out(['id' => $args[1], 'price' => $h['price'], 'sku' => $h['sku'] ?? '', 'stock' => $h['stock'], 'syncUnavailable' => $h['syncUnavailable'] ?? false]); break; }
    case 'flush': $out(['events' => Scc_Channel_Events::flush()]); break;
    case 'sweep': $out(Scc_Channel_Outbox::sweep()); break;
    case 'deadletter': {   // peer really unreachable (connection refused on a closed port), exponential backoff, dead letter, then admin retry against the real peer
        $real = Scc_Channel::peer_url(); $t0 = Scc_Channel::ts(); $log = [];
        $p = wc_get_product($wc_by_legacy('t-channel-live-a')); $p->set_regular_price((string) ($args[1] ?? '77')); $p->save(); $evs = Scc_Channel_Events::flush();
        $eid = $evs[0] ?? ''; if ($eid === '') { $out(['error' => 'no event emitted']); break; }
        add_filter('scc_channel_peer_url', fn() => preg_replace('#^(https?://[^/:]+).*$#', '$1:9', $real));
        $off = 0; add_filter('scc_channel_time', function ($t) use (&$off) { return $t + $off; });
        for ($i = 1; $i <= 9; $i++) {
            $state = Scc_Channel_Outbox::deliver($eid);
            $row = $wpdb->get_row($wpdb->prepare('SELECT state,attempts,next_at,last_status,last_error FROM ' . Scc_Channel_Outbox::t() . ' WHERE event_id=%s', $eid), ARRAY_A);
            $log[] = ['try' => $i, 'result' => $state, 'state' => $row['state'], 'attempts' => (int) $row['attempts'], 'backoff_s' => $state === 'retry' ? (int) $row['next_at'] - Scc_Channel::ts() : null, 'status' => (int) $row['last_status'], 'error' => $row['last_error']];
            if ($row['state'] === 'dead') break;
            $off += 4000;
        }
        $out(['event' => $eid, 'log' => $log, 'link_status' => Scc_Channel_Links::get('t-channel-live-a')['status'] ?? null]); break; }
    case 'admin-retry': $out(['requeued' => Scc_Channel_Outbox::retry($args[1]), 'delivered' => Scc_Channel_Outbox::deliver($args[1], true), 'link_status' => Scc_Channel_Links::get('t-channel-live-a')['status'] ?? null]); break;
    case 'cleanup': {
        $n = 0; foreach ($ids as $l) { $w = $wc_by_legacy($l); if ($w) { wp_delete_post($w, true); $n++; } }
        $like = 't-channel-live-%'; $lk = 't-channel-live-%';
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Docs::t('product_links') . ' WHERE id LIKE %s', $like));
        foreach (['reservations' => 'reservation_id', 'ledger' => 'product_id', 'locks' => 'product_id', 'link_keys' => 'legacy_id', 'inbox' => 'event_id'] as $t => $col) $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Channel_Tx::t($t) . " WHERE $col LIKE %s OR $col LIKE %s", $lk, 'shimi:' . $lk));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Channel_Tx::t('reservations') . ' WHERE product_id LIKE %s', $lk));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Channel_Outbox::t() . ' WHERE event_id LIKE %s OR domain_key LIKE %s OR payload LIKE %s', '%' . $wpdb->esc_like('t-channel-live') . '%', '%' . $wpdb->esc_like('t-channel-live') . '%', '%' . $wpdb->esc_like('t-channel-live') . '%'));
        $out(['products_deleted' => $n]); break; }
    default: $out(['error' => 'unknown command']);
}
