<?php
/** Channel unit/integration tests inside WordPress (staging). See harness.php. */
require __DIR__ . '/harness.php';
use_docs(); cleanup();
$site = defined('SCC_ROLE') ? SCC_ROLE : '?';
echo "site role constant: $site; peer configured: " . (Scc_Channel::peer_url() !== '' ? 'yes' : 'no') . "; secret configured: " . (Scc_Channel::secret() !== '' ? 'yes' : 'no') . "\n";

/* ======================================================================= 1. stock: reserve / release / commit (SHEM, source role) */
section('1. reservations and holds (port of reserveStock / releaseStock / stockView)');
as_home(false, function () {
    mkp('t_channel_s1', ['stock' => 2, 'sizeStock' => ['M' => 2], 'price' => 80]);
    Scc_Channel_Stock::reserve('shimi:t_channel_a', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 2, 'priceCents' => 8000]]);
    ok('reserve 2 of 2 succeeds', true);
    eq('hold row stored as held', Scc_Channel_Stock::rows('shimi:t_channel_a')[0]['state'], 'held');
    $v = Scc_Channel_Stock::view(Scc_Channel_Catalog::get('t_channel_s1'));
    eq('available stock after hold is 0', $v['stock'], 0);
    eq('available size stock after hold is 0', $v['sizeStock']['M'], 0);
    eq('stored stock untouched by a hold', pget('t_channel_s1')['stock'], 2);
    throws('second buyer cannot take a held unit', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_b', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 1]]), 'המלאי השתנה או אינו מספיק. רעננו את הסל.', 409);
    eq('failed reserve leaves no rows', count(Scc_Channel_Stock::rows('shimi:t_channel_b')), 0);
    Scc_Channel_Stock::release('shimi:t_channel_a');
    eq('release frees the stock', Scc_Channel_Stock::view(Scc_Channel_Catalog::get('t_channel_s1'))['stock'], 2);
    eq('released state', Scc_Channel_Stock::rows('shimi:t_channel_a')[0]['state'], 'released');
    Scc_Channel_Stock::release('shimi:t_channel_a'); ok('release is idempotent', true);
    Scc_Channel_Stock::reserve('shimi:t_channel_b', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 1]]);
    ok('after release another buyer can reserve', true);
    // idempotent re-reserve of the same cart; different cart under the same id
    Scc_Channel_Stock::reserve('shimi:t_channel_b', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 1]]);
    eq('same id + same cart is a no-op (still one row, qty 1)', (int) Scc_Channel_Stock::rows('shimi:t_channel_b')[0]['qty'], 1);
    throws('same id + different cart rejected', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_b', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 2]]), 'מזהה הזמנה כבר משמש לסל אחר.', 409);
    Scc_Channel_Stock::reserve('shimi:t_channel_b', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 1]]);
    // split lines of one product spelled differently still equal
    Scc_Channel_Stock::reserve('shimi:t_channel_split', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 1]]);
    Scc_Channel_Stock::reserve('shimi:t_channel_split', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 1]]);
    eq('two holds total 2 -> stock 0 available', Scc_Channel_Stock::view(Scc_Channel_Catalog::get('t_channel_s1'))['stock'], 0);
    Scc_Channel_Stock::release('shimi:t_channel_b'); Scc_Channel_Stock::release('shimi:t_channel_split');

    section('1b. reserve validation (same Hebrew errors as the worker)');
    mkp('t_channel_arch', ['archived' => true]); mkp('t_channel_off', ['active' => false]);
    throws('archived product', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_v1', [['productId' => 't_channel_arch', 'variantId' => '', 'size' => 'M', 'qty' => 1]]), 'מוצר אינו זמין.');
    throws('inactive product', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_v2', [['productId' => 't_channel_off', 'variantId' => '', 'size' => 'M', 'qty' => 1]]), 'מוצר אינו זמין.');
    throws('unknown product', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_v3', [['productId' => 't_channel_nope', 'variantId' => '', 'size' => 'M', 'qty' => 1]]), 'מוצר אינו זמין.');
    foreach ([0, 101, 1.5, -1] as $q) throws('qty ' . $q . ' invalid', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_v4', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => $q]]), 'כמות אינה תקינה.');
    throws('size not offered', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_v5', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'XL', 'qty' => 1]]), 'גרסת מוצר אינה תואמת.');
    throws('price changed', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_v6', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 1, 'priceCents' => 7999]]), 'מחיר השתנה. יש לרענן את הסל.');
    Scc_Channel_Stock::reserve('shimi:t_channel_v7', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 1, 'priceCents' => 8000]]); ok('matching priceCents accepted', true); Scc_Channel_Stock::release('shimi:t_channel_v7');
    Scc_Channel_Stock::reserve('shimi:t_channel_empty', []); ok('empty item list is a no-op', count(Scc_Channel_Stock::rows('shimi:t_channel_empty')) === 0);

    section('1c. variants: per-variant and total guards');
    mkp('t_channel_vv', ['stock' => 10, 'sizes' => ['M', 'L'], 'sizeStock' => ['M' => 6, 'L' => 4], 'variants' => [['id' => 't_channel_vm', 'size' => 'M', 'color' => 'black', 'sku' => 'VV-M', 'stock' => 6], ['id' => 't_channel_vl', 'size' => 'L', 'color' => 'black', 'sku' => 'VV-L', 'stock' => 4]]]);
    throws('L has only 4', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_w1', [['productId' => 't_channel_vv', 'variantId' => 't_channel_vl', 'size' => 'L', 'qty' => 5]]), 'המלאי השתנה או אינו מספיק. רעננו את הסל.');
    throws('variant/size mismatch', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_w2', [['productId' => 't_channel_vv', 'variantId' => 't_channel_vl', 'size' => 'M', 'qty' => 1]]), 'גרסת מוצר אינה תואמת.');
    throws('variant required when product has variants', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_w3', [['productId' => 't_channel_vv', 'variantId' => '', 'size' => 'M', 'qty' => 1]]), 'גרסת מוצר אינה תואמת.');
    Scc_Channel_Stock::reserve('shimi:t_channel_w4', [['productId' => 't_channel_vv', 'variantId' => 't_channel_vm', 'size' => 'M', 'qty' => 6], ['productId' => 't_channel_vv', 'variantId' => 't_channel_vl', 'size' => 'L', 'qty' => 4]]);
    ok('reserve every unit of both variants', true);
    $view = Scc_Channel_Stock::view(Scc_Channel_Catalog::get('t_channel_vv'));
    eq('variant M available 0', $view['variants'][0]['stock'], 0); eq('variant L available 0', $view['variants'][1]['stock'], 0); eq('total available 0', $view['stock'], 0);
    throws('nothing left for a third buyer', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_w5', [['productId' => 't_channel_vv', 'variantId' => 't_channel_vm', 'size' => 'M', 'qty' => 1]]));
    Scc_Channel_Stock::release('shimi:t_channel_w4');

    section('1d. 20 minute expiry');
    Scc_Channel_Stock::reserve('shimi:t_channel_x1', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 2]]);
    $exp = (int) Scc_Channel_Stock::rows('shimi:t_channel_x1')[0]['expires_at'];
    ok('hold lasts 20 minutes', abs($exp - (Scc_Channel::ts() + 1200)) <= 2, $exp - Scc_Channel::ts());
    $GLOBALS['t_time'] = 1199; eq('still held at 19m59s', Scc_Channel_Stock::view(Scc_Channel_Catalog::get('t_channel_s1'))['stock'], 0);
    $GLOBALS['t_time'] = 1201;
    eq('expired hold no longer reduces availability', Scc_Channel_Stock::view(Scc_Channel_Catalog::get('t_channel_s1'))['stock'], 2);
    throws('re-reserving an expired id is refused', fn() => Scc_Channel_Stock::reserve('shimi:t_channel_x1', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 2]]), 'שמירת המלאי פגה. יש להתחיל הזמנה חדשה.');
    Scc_Channel_Stock::reserve('shimi:t_channel_x2', [['productId' => 't_channel_s1', 'variantId' => '', 'size' => 'M', 'qty' => 2]]); ok('another buyer can take the expired units', true);
    throws('commit of an expired hold needs a payment review', fn() => Scc_Channel_Stock::commit('shimi:t_channel_x1'), 'נדרש בירור תשלום: שמירת המלאי פגה.');
    eq('expire_due flags the stale hold', Scc_Channel_Stock::expire_due() >= 1, true);
    eq('state expired', Scc_Channel_Stock::rows('shimi:t_channel_x1')[0]['state'], 'expired');
    $GLOBALS['t_time'] = 0; Scc_Channel_Stock::release('shimi:t_channel_x2');

    section('1e. commit: stock leaves exactly once');
    mkp('t_channel_c1', ['stock' => 4, 'sizeStock' => ['M' => 4]]);
    Scc_Channel_Stock::reserve('shimi:t_channel_c1', [['productId' => 't_channel_c1', 'variantId' => '', 'size' => 'M', 'qty' => 1]]);
    eq('commit ok', Scc_Channel_Stock::commit('shimi:t_channel_c1'), ['ok' => true]);
    eq('stock 4 -> 3', pget('t_channel_c1')['stock'], 3); eq('size stock 4 -> 3', pget('t_channel_c1')['sizeStock']['M'], 3);
    eq('commit replay is reported as duplicate', Scc_Channel_Stock::commit('shimi:t_channel_c1'), ['ok' => true, 'duplicate' => true]);
    eq('replay did not decrement again', pget('t_channel_c1')['stock'], 3);
    eq('exactly one ledger row', (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM ' . Scc_Channel_Tx::t('ledger') . ' WHERE ref=%s', 'shimi:t_channel_c1')), 1);
    eq('ledger delta -1', (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT delta FROM ' . Scc_Channel_Tx::t('ledger') . ' WHERE ref=%s', 'shimi:t_channel_c1')), -1);
    eq('reservation committed', Scc_Channel_Stock::rows('shimi:t_channel_c1')[0]['state'], 'committed');
    eq('commit after commit still no stock change (3 units)', Scc_Channel_Stock::view(Scc_Channel_Catalog::get('t_channel_c1'))['stock'], 3);
    throws('commit of unknown reservation', fn() => Scc_Channel_Stock::commit('shimi:t_channel_unknown'), 'שמירת מלאי לא נמצאה.');
    Scc_Channel_Stock::reserve('shimi:t_channel_c2', [['productId' => 't_channel_c1', 'variantId' => '', 'size' => 'M', 'qty' => 2]]);
    Scc_Docs::put('products', 't_channel_c1', array_merge(pget('t_channel_c1'), ['stock' => 1, 'sizeStock' => ['M' => 1]]));
    throws('commit with less physical stock than the hold', fn() => Scc_Channel_Stock::commit('shimi:t_channel_c2'), 'נדרש בירור מלאי.');
    eq('failed commit changes nothing', pget('t_channel_c1')['stock'], 1); eq('hold still held', Scc_Channel_Stock::rows('shimi:t_channel_c2')[0]['state'], 'held');
    Scc_Channel_Stock::release('shimi:t_channel_c2');
    // other holds must still fit after the decrement (stockGuards)
    Scc_Docs::put('products', 't_channel_c1', array_merge(pget('t_channel_c1'), ['stock' => 2, 'sizeStock' => ['M' => 2]]));
    Scc_Channel_Stock::reserve('shimi:t_channel_g1', [['productId' => 't_channel_c1', 'variantId' => '', 'size' => 'M', 'qty' => 1]]);
    Scc_Channel_Stock::reserve('shimi:t_channel_g2', [['productId' => 't_channel_c1', 'variantId' => '', 'size' => 'M', 'qty' => 1]]);
    Scc_Docs::put('products', 't_channel_c1', array_merge(pget('t_channel_c1'), ['stock' => 1, 'sizeStock' => ['M' => 1]]));
    throws('commit that would leave another hold uncovered', fn() => Scc_Channel_Stock::commit('shimi:t_channel_g1'), 'נדרש בירור מלאי.');
    Scc_Channel_Stock::release('shimi:t_channel_g1'); Scc_Channel_Stock::release('shimi:t_channel_g2');
    ok('write_allowed: stock above holds', Scc_Channel_Stock::write_allowed('t_channel_c1', 5));
    Scc_Channel_Stock::reserve('shimi:t_channel_g3', [['productId' => 't_channel_c1', 'variantId' => '', 'size' => 'M', 'qty' => 1]]);
    ok('write_allowed: stock below holds is refused', !Scc_Channel_Stock::write_allowed('t_channel_c1', 0));
    Scc_Channel_Stock::release('shimi:t_channel_g3');

    section('1f. Woo-owned orders close holds without a second decrement');
    mkp('t_channel_w', ['stock' => 3, 'sizeStock' => ['M' => 3]]);
    Scc_Channel_Stock::reserve('t_channel_ord1', [['productId' => 't_channel_w', 'variantId' => '', 'size' => 'M', 'qty' => 1]]);
    eq('close_holds_for_woo flips one hold', Scc_Channel_Stock::close_holds_for_woo('t_channel_ord1', 'unit'), 1);
    eq('second call closes nothing', Scc_Channel_Stock::close_holds_for_woo('t_channel_ord1', 'unit'), 0);
    eq('stock untouched by the channel (Woo owns it)', pget('t_channel_w')['stock'], 3);
    eq('ledger has the informational wc row once', (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM ' . Scc_Channel_Tx::t('ledger') . " WHERE ref=%s AND applied_by='woocommerce'", 't_channel_ord1')), 1);
});
finish();
