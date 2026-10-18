<?php
/** Channel tests on REAL WooCommerce objects: catalog backend, stock ownership (exactly once), central commit via outbox, cancel releases. */
require __DIR__ . '/harness.php';
if (!class_exists('WooCommerce')) { echo "WooCommerce missing\n"; return; }
cleanup();
$woo_clean = function () {
    foreach (get_posts(['post_type' => ['product', 'shop_order', 'shop_order_placehold'], 'post_status' => 'any', 'numberposts' => -1, 'meta_query' => [['key' => '_scc_legacy_id', 'value' => 't-channel-', 'compare' => 'LIKE']], 'fields' => 'ids']) as $id) wp_delete_post($id, true);
    foreach (wc_get_orders(['limit' => -1, 'meta_key' => '_scc_legacy_id', 'meta_compare' => 'LIKE', 'meta_value' => 't-channel-', 'return' => 'objects']) as $o) $o->delete(true);
};
$woo_clean();
Scc_Channel_Catalog::use_backend(new Scc_Channel_Catalog_Woo());
$mkwoo = function (string $legacy, int $stock, array $meta = []) {
    $p = new WC_Product_Simple(); $p->set_name('t_channel ' . $legacy); $p->set_regular_price('100'); $p->set_manage_stock(true); $p->set_stock_quantity($stock); $p->set_status('publish'); $p->set_sku(strtoupper($legacy)); $id = $p->save();
    update_post_meta($id, '_scc_legacy_id', $legacy); foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
    return $id;
};
$vw = fn(string $id) => Scc_Channel_Stock::view(Scc_Channel_Catalog::get($id, true));
$stock = fn(int $id) => (int) wc_get_product($id)->get_stock_quantity();
$mkorder = function (int $pid, int $qty, string $legacy) {
    $o = wc_create_order(['status' => 'pending']); $o->add_product(wc_get_product($pid), $qty); $o->calculate_totals(); $o->update_meta_data('_scc_legacy_id', $legacy); $o->set_billing_email('t@example.com'); $o->save(); return $o;
};

section('13. Woo catalog backend (real products)');
$wa = $mkwoo('t-channel-wa', 5);
$c = Scc_Channel_Catalog::get('t-channel-wa');
eq('product read through the Woo backend', [$c['id'] ?? null, (int) ($c['stock'] ?? -1), (float) ($c['price'] ?? 0)], ['t-channel-wa', 5, 100.0]);
eq('active', $c['active'] ?? null, true);
$rev = Scc_Channel_Catalog::sync_revision($c);
wc_update_product_stock(wc_get_product($wa), 3, 'set'); eq('stock change keeps the sync revision (loop guard)', Scc_Channel_Catalog::sync_revision(Scc_Channel_Catalog::get('t-channel-wa', true)), $rev);
$p = wc_get_product($wa); $p->set_regular_price('120'); $p->save();
ok('price change moves the sync revision', Scc_Channel_Catalog::sync_revision(Scc_Channel_Catalog::get('t-channel-wa', true)) !== $rev);
wc_update_product_stock(wc_get_product($wa), 5, 'set');

section('14. Woo-owned line: Woo decrements once, the channel only closes the hold');
$o = $mkorder($wa, 2, 't-channel-ord1');
Scc_Channel_Stock::reserve('t-channel-ord1', [['productId' => 't-channel-wa', 'variantId' => '', 'size' => '', 'qty' => 2]]);
eq('hold does not touch Woo stock', $stock($wa), 5);
$view = $vw('t-channel-wa'); eq('held units are subtracted from what is sellable', (int) ($view['stock'] ?? -1), 3);
$o->payment_complete('tx-1');
eq('Woo reduced stock exactly once', $stock($wa), 3);
$res = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT state FROM ' . Scc_Channel_Tx::t('reservations') . ' WHERE reservation_id=%s LIMIT 1', 't-channel-ord1')); eq('hold closed as committed', $res, 'committed');
Scc_Channel_Orders::on_paid($o->get_id()); Scc_Channel_Orders::on_paid($o->get_id()); do_action('woocommerce_order_status_processing', $o->get_id());
eq('repeated paid hooks do not decrement again', $stock($wa), 3);
wc_maybe_reduce_stock_levels($o->get_id()); eq('wc_maybe_reduce_stock_levels is idempotent via _reduced_stock', $stock($wa), 3);
eq('view after payment: stock 3, no live holds', (int) $vw('t-channel-wa')['stock'], 3);
$o2 = $mkorder($wa, 1, 't-channel-ord2'); Scc_Channel_Stock::reserve('t-channel-ord2', [['productId' => 't-channel-wa', 'variantId' => '', 'size' => '', 'qty' => 1]]);
$o2->update_status('cancelled'); eq('cancel releases the hold, stock untouched', [$stock($wa), (int) $vw('t-channel-wa')['stock']], [3, 3]);
throws('cannot hold more than Woo has', fn() => Scc_Channel_Stock::reserve('t-channel-ord3', [['productId' => 't-channel-wa', 'variantId' => '', 'size' => '', 'qty' => 4]]), null, 409);

section('15. SHIMI linked line: Woo must not reduce it, SHEM decrements once via the commit outbox');
$ws = $mkwoo('t-channel-ws', 5);                                  // SHEM product (source role runs against the same Woo in this simulation)
$wh = $mkwoo('t-channel-wh', 99);                                 // SHIMI card, linked; its own Woo stock must stay untouched
Scc_Docs::put(Scc_Channel_Links::TABLE, 't-channel-wh', ['id' => 't-channel-wh', 'sourceId' => 't-channel-ws', 'variantMap' => (object) [], 'status' => 'connected']);
Scc_Docs::put(Scc_Channel_Links::TABLE, 't-channel-ws', ['id' => 't-channel-ws', 'homeId' => 't-channel-wh', 'variantMap' => (object) [], 'status' => 'connected']);
$lines = [['productId' => 't-channel-wh', 'variantId' => '', 'size' => '', 'qty' => 2, 'priceCents' => 10000]];
$ho = null;
as_home(true, function () use (&$ho, $mkorder, $wh, $lines) {
    [$central, $local] = Scc_Channel_Orders::split($lines);
    eq('split maps the card to the SHEM product', [count($central), count($local), $central[0]['productId'] ?? ''], [1, 0, 't-channel-ws']);
    $r = Scc_Channel_Orders::reserve_for_order('t-channel-hord1', $lines);
    eq('central hold taken on SHEM', $r['reservationId'], 'shimi:t-channel-hord1');
    $ho = $mkorder($wh, 2, 't-channel-hord1'); Scc_Channel_Orders::mark_order($ho, $r);
});
eq('SHEM sellable stock is reduced by the hold only', (int) as_home(false, fn() => $vw('t-channel-ws')['stock']), 3);
eq('SHEM Woo stock is still 5 while held', $stock($ws), 5);
as_home(true, function () use ($ho) { $ho->payment_complete('tx-2'); });
$ho = wc_get_order($ho->get_id());
eq('SHIMI Woo did NOT reduce the linked card', $stock($wh), 99);
$reduced = 0; foreach ($ho->get_items() as $it) $reduced += (int) $it->get_meta('_reduced_stock', true);
eq('no _reduced_stock on the linked line (no phantom restock later)', $reduced, 0);
$ev = $GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare('SELECT * FROM ' . Scc_Channel_Outbox::t() . ' WHERE event_id=%s', 'commit:shimi:t-channel-hord1'), ARRAY_A);
eq('commit event queued in the outbox on payment', [$ev['state'] ?? '', $ev['path'] ?? ''], ['pending', '/api/channel/commit']);
as_home(true, function () use ($ho) { do_action('woocommerce_payment_complete', $ho->get_id()); do_action('woocommerce_order_status_completed', $ho->get_id()); });
eq('payment hooks fired again enqueue no second commit', (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM ' . Scc_Channel_Outbox::t() . ' WHERE domain_key=%s', 'commit:t-channel-hord1')), 1);
eq('SHEM stock still 5 before the commit is delivered', $stock($ws), 5);
$GLOBALS['t_peer_mode'] = 'down'; as_home(true, fn() => Scc_Channel_Outbox::deliver('commit:shimi:t-channel-hord1'));
eq('peer down: commit waits in retry, order is not lost', $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT state FROM ' . Scc_Channel_Outbox::t() . ' WHERE event_id=%s', 'commit:shimi:t-channel-hord1')), 'retry');
$GLOBALS['t_peer_mode'] = 'sim'; $GLOBALS['t_time'] += 60;   // past the first backoff, still inside the 20 minute hold
$dl = as_home(true, fn() => Scc_Channel_Outbox::deliver('commit:shimi:t-channel-hord1'));
eq('peer back: commit delivered', $dl, 'delivered');
if ($dl !== 'delivered') echo '   DEBUG ' . wp_json_encode($GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare('SELECT state,attempts,last_status,last_error FROM ' . Scc_Channel_Outbox::t() . ' WHERE event_id=%s', 'commit:shimi:t-channel-hord1'), ARRAY_A), JSON_UNESCAPED_UNICODE) . "\n";
eq('SHEM Woo stock decremented exactly once (5 -> 3)', $stock($ws), 3);
eq('SHIMI Woo stock still untouched', $stock($wh), 99);
eq('order remembers the central commit', wc_get_order($ho->get_id())->get_meta('_scc_channel_central_done'), '1');
$raw = Scc_Channel::json(['reservationId' => 'shimi:t-channel-hord1', 'orderId' => 't-channel-hord1']);
[$st, $b] = as_home(true, fn() => peer_call_sim('/api/channel/commit', $raw, signed_headers('/api/channel/commit', $raw)));
eq('duplicate commit replayed to SHEM', [$st, $b['duplicate'] ?? null], [200, true]); eq('still 3 after the replay', $stock($ws), 3);
// cancel path: release event instead of commit
$ho2 = null;
as_home(true, function () use (&$ho2, $mkorder, $wh) {
    $l = [['productId' => 't-channel-wh', 'variantId' => '', 'size' => '', 'qty' => 1, 'priceCents' => 10000]];
    $r = Scc_Channel_Orders::reserve_for_order('t-channel-hord2', $l); $ho2 = $mkorder($wh, 1, 't-channel-hord2'); Scc_Channel_Orders::mark_order($ho2, $r);
});
eq('second order holds 1 unit on SHEM', (int) as_home(false, fn() => $vw('t-channel-ws')['stock']), 2);
as_home(true, fn() => $ho2->update_status('cancelled'));
$ev = $GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare('SELECT state,path FROM ' . Scc_Channel_Outbox::t() . ' WHERE event_id=%s', 'release:shimi:t-channel-hord2'), ARRAY_A);
eq('cancel queues a release', [$ev['state'] ?? '', $ev['path'] ?? ''], ['pending', '/api/channel/release']);
eq('release delivered', as_home(true, fn() => Scc_Channel_Outbox::deliver('release:shimi:t-channel-hord2')), 'delivered');
eq('SHEM hold freed, stock intact', [(int) as_home(false, fn() => $vw('t-channel-ws')['stock']), $stock($ws)], [3, 3]);
// oversell guard through the linked product: only 3 left on SHEM
as_home(true, function () {
    $l = [['productId' => 't-channel-wh', 'variantId' => '', 'size' => '', 'qty' => 2, 'priceCents' => 10000]];
    Scc_Channel_Orders::reserve_for_order('t-channel-hord3', $l);
    try { Scc_Channel_Orders::reserve_for_order('t-channel-hord4', $l); ok('second order of 2 against 3 left must fail', false, 'reserved'); }
    catch (Scc_Http_Exception $e) { ok('second order of 2 against 3 left is refused', $e->status === 409, [$e->status, $e->getMessage()]); }
    eq('refused order left no hold behind', (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM ' . Scc_Channel_Tx::t('reservations') . ' WHERE reservation_id=%s', 'shimi:t-channel-hord4')), 0);
    Scc_Channel_Orders::release_for_order('t-channel-hord3');
});
// Woo catalog: central decrement path is the same code the commit used (backend->decrement); verify the stock never went negative
ok('no Woo stock below zero', $stock($ws) >= 0 && $stock($wa) >= 0);

Scc_Channel_Catalog::reset(); $woo_clean(); cleanup(); finish();
