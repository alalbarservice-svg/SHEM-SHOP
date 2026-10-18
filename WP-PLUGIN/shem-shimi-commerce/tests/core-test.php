<?php
/**
 * Core shop API tests (catalogue, store, collections, club, requests, quote, checkout-home, status/invoice, order totals, upsert).
 * Run on staging only:  /home/claude/wps.sh shem|shimi eval-file wp-content/plugins/shem-shimi-commerce/tests/core-test.php
 * Test data ids start with t_core_ and are removed at the end; changed settings are restored. Mail is blocked (pre_wp_mail),
 * SHEM-DOK auto-issuing is switched off for the run. No charge is possible: the payment gateway only opens an unpaid session.
 */
if (!defined('ABSPATH')) { fwrite(STDERR, "run with wp eval-file\n"); exit(1); }
if (!defined('SCC_ROLE')) { echo "SCC_ROLE missing\n"; exit(1); }
$GLOBALS['T'] = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'sec' => '', 'by' => [], 'fails' => []];
function t_sec(string $s): void { $GLOBALS['T']['sec'] = $s; $GLOBALS['T']['by'][$s] ??= [0, 0]; }
function t_ok($cond, string $name, string $detail = ''): void {
    $T = &$GLOBALS['T'];
    if ($cond) { $T['pass']++; $T['by'][$T['sec']][0]++; return; }
    $T['fail']++; $T['by'][$T['sec']][1]++; $T['fails'][] = '[' . $T['sec'] . '] ' . $name . ($detail !== '' ? ' :: ' . mb_substr($detail, 0, 300) : '');
}
function t_eq($a, $b, string $name): void { t_ok($a === $b, $name, 'got ' . json_encode($a, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($b, JSON_UNESCAPED_UNICODE)); }
function t_skip(string $name): void { $GLOBALS['T']['skip']++; echo "NOT-RUN: $name\n"; }

add_filter('pre_wp_mail', '__return_false');
add_filter('pre_option_shem_dok_settings', function () { return ['auto' => false, 'email_customer' => false]; });
$ROLE = SCC_ROLE; $BRAND = strtoupper($ROLE);
$hp = wp_parse_url(home_url()); $GLOBALS['ORIGIN'] = $hp['scheme'] . '://' . $hp['host'] . (isset($hp['port']) ? ':' . $hp['port'] : '');
$GLOBALS['SAVED'] = []; $GLOBALS['CLEAN'] = ['products' => [], 'orders' => [], 'docs' => []];
global $SAVED, $CLEAN, $ORIGIN, $wpdb;
$_SERVER['HTTP_HOST'] = $hp['host'] . (isset($hp['port']) ? ':' . $hp['port'] : '');

/* ------------------------------------------------------------------ helpers */
function t_routes(): array { static $rp = null; $rp ??= new ReflectionProperty('Scc_Router', 'routes'); return $rp->getValue(); }
function t_call(string $method, string $path, $body = null, array $query = [], array $hdr = [], bool $origin = true): array {
    global $ORIGIN;
    $r = new Scc_Request();
    $r->method = $method; $r->path = $path; $r->query = $query;
    $r->headers = array_merge(($origin ? ['origin' => $ORIGIN] : []), ['content-type' => 'application/json'], array_change_key_case($hdr));
    $r->raw = $body === null ? '' : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
    foreach (t_routes() as [$m, $rx, $h, $auth]) {
        if ($m !== $method && $m !== '*') continue;
        if (!preg_match($rx, $path, $mm)) continue;
        $r->params = $mm;
        try { $res = call_user_func($h, $r); }
        catch (Scc_Http_Exception $e) { $res = Scc_Response::error($e->getMessage(), $e->status); }
        catch (Throwable $e) { return ['status' => 500, 'body' => 'EXC ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), 'json' => null, 'headers' => []]; }
        if (!($res instanceof Scc_Response)) $res = Scc_Response::json($res);
        $s = $res->raw || is_string($res->body) ? (string) $res->body : wp_json_encode($res->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['status' => $res->status, 'body' => $s, 'json' => json_decode($s, true), 'headers' => array_change_key_case($res->headers)];
    }
    return ['status' => 404, 'body' => 'NOROUTE', 'json' => null, 'headers' => []];
}
function t_setting_row(string $key): ?array { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT id,data,updated_at FROM ' . Scc_Docs::t('settings') . ' WHERE id=%s', $key), ARRAY_A) ?: null; }
function t_save_setting(string $key): void { global $SAVED; if (!array_key_exists($key, $SAVED)) $SAVED[$key] = t_setting_row($key); }
function t_restore_settings(): void {
    global $SAVED, $wpdb;
    foreach ($SAVED as $k => $row) {
        if ($row === null) $wpdb->delete(Scc_Docs::t('settings'), ['id' => $k]);
        else $wpdb->query($wpdb->prepare('REPLACE INTO ' . Scc_Docs::t('settings') . ' (id,data,updated_at) VALUES (%s,%s,%s)', $row['id'], $row['data'], $row['updated_at']));
    }
}
function t_set(string $key, $value): void { t_save_setting($key); Scc_Docs::set_setting($key, $value); }
function t_product(string $id, array $o = []): array {
    return array_merge(['id' => $id, 'he' => 'בדיקה ' . $id, 'en' => 'Test ' . $id, 'brand' => 'SHEM', 'cat' => 'clothing', 'sku' => strtoupper($id), 'price' => 199, 'stock' => 100, 'sizes' => ['S', 'M'],
        'color' => 'שחור', 'image' => 'knit', 'imageUrl' => '', 'active' => true], $o);
}
function t_import(array $doc, array $opts = []): array { global $CLEAN; $r = Scc_Core::upsert_product_from_legacy($doc, $opts); $CLEAN['products'][$doc['id']] = true; return $r; }
function t_variants(string $pid, array $sizes, int $stock, string $color = 'שחור'): array { $v = []; foreach ($sizes as $s) $v[] = ['id' => "$pid-$s", 'size' => $s, 'color' => $color, 'sku' => strtoupper("$pid-$s"), 'stock' => $stock]; return $v; }
function t_doc_put(string $table, string $id, array $d): void { global $CLEAN; Scc_Docs::put($table, $id, $d); $CLEAN['docs'][] = [$table, $id]; }
function t_wc_product_stock(string $legacy, string $variant = ''): ?int {
    $pid = Scc_Core::find_product_id($legacy); if (!$pid) return null;
    if ($variant === '') { $p = wc_get_product($pid); return $p ? (int) $p->get_stock_quantity() : null; }
    $vm = Scc_Core::variation_map($pid); return isset($vm[$variant]) ? (int) wc_get_product($vm[$variant])->get_stock_quantity() : null;
}
function t_cart_item(string $id, string $size, int $qty, string $variant = ''): array { return ['id' => $id, 'size' => $size, 'qty' => $qty] + ($variant !== '' ? ['variantId' => $variant] : []); }
function t_draft_order(array $draft, string $id): array { return array_merge($draft, ['id' => $id, 'source' => 'storefront', 'status' => 'new', 'paymentStatus' => 'unpaid', 'paid' => false, 'createdAt' => Scc_Core_Util::now_iso(), 'viewToken' => bin2hex(random_bytes(16))]); }

try {
/* ================================================================== 1. byte match with the live exports */
t_sec('1 export byte-match');
$site = $ROLE === 'shem' ? 'shem-menswear' : 'shimi-basics';
$exp = __DIR__ . '/fixtures/' . $site . '-catalogue.json';
if (!is_file($exp)) { t_skip("export fixture $exp missing"); }
else {
    $raw = file_get_contents($exp); $ex = json_decode($raw, true);
    $sb = strrpos($raw, '],"configured":'); $expectedProducts = substr($raw, strlen('{"products":'), $sb + 1 - strlen('{"products":'));
    $ids = []; $docs = [];
    foreach ($ex['products'] as $i => $p) {
        $map = [$p['id'] => 't_core_' . $p['id']]; if (isset($p['sku'])) $map[$p['sku']] = 't_core_' . $p['sku'];
        foreach (($p['variants'] ?? []) as $v) { $map[$v['id']] = 't_core_' . $v['id']; if (isset($v['sku'])) $map[$v['sku']] = 't_core_' . $v['sku']; }
        $rw = function ($x) use (&$rw, $map) { if (is_array($x)) { foreach ($x as $k => $v) $x[$k] = $rw($v); return $x; } return is_string($x) && isset($map[$x]) ? $map[$x] : $x; };
        $d = $rw($p); $docs[] = $d; $ids[] = $d['id'];
        $res = t_import($d, ['updated_at' => Scc_Core_Util::now_iso(microtime(true) - 2 * $i - 5)]);
        t_ok($res['ok'] && in_array($res['action'], ['created', 'updated', 'unchanged'], true), 'import ' . $p['id'], json_encode($res));
    }
    $out = []; foreach (Scc_Core::hydrated($ids) as $p) { if (($p['active'] ?? null) === false || Scc_Core_Util::truthy($p['archived'] ?? null)) continue; $out[] = Scc_Core_Catalogue::project($p); }
    $ours = str_replace('"t_core_', '"', Scc_Core_Util::enc($out));
    t_ok($ours === $expectedProducts, 'catalogue products bytes equal export (' . count($ex['products']) . ' products)', "len ours=" . strlen($ours) . " exp=" . strlen($expectedProducts) . "\nOURS " . substr($ours, 0, 400) . "\nEXP  " . substr($expectedProducts, 0, 400));
    if ($ours !== $expectedProducts) { $n = min(strlen($ours), strlen($expectedProducts)); for ($i = 0; $i < $n; $i++) if ($ours[$i] !== $expectedProducts[$i]) { echo "FIRST DIFF @$i: ours=" . substr($ours, max(0, $i - 60), 140) . "\n          exp =" . substr($expectedProducts, max(0, $i - 60), 140) . "\n"; break; } }
    // idempotent re-import
    $again = t_import($docs[0], ['updated_at' => Scc_Core_Util::now_iso(microtime(true) - 5)]); t_ok(in_array($again['action'], ['unchanged', 'updated'], true), 're-import ok', json_encode($again));
    $third = Scc_Core::upsert_product_from_legacy($docs[0]); t_eq($third['action'], 'unchanged', 're-import without revision is unchanged');
    // other exports (store / collections / help) shape: nothing to compare against stored data (staging store settings differ) - shape check only
    $st = json_decode(file_get_contents(__DIR__ . '/fixtures/' . $site . '-store.json'), true);
    t_ok(is_array($st) && array_key_exists('operations', $st) && array_key_exists('business', $st), 'export store shape known');
}

/* ================================================================== 2. products: storage, overlay, round trip */
t_sec('2 products');
$knit = t_product('t_core_knit', ['price' => 199, 'stock' => 100, 'sizeStock' => ['S' => 50, 'M' => 50], 'variants' => t_variants('t_core_knit', ['S', 'M'], 50), 'productType' => 'jackets']);
$real = t_product('t_core_real', ['he' => 'פריט "אמיתי" \\ עם / סימנים', 'price' => 199.90, 'previousPrice' => 249.90, 'stock' => 3, 'sizeStock' => ['S' => 1, 'M' => 2], 'sku' => 'T_CORE_REAL', 'homeSku' => 'H1']);
$season = t_product('t_core_season', ['price' => 149, 'previousPrice' => 199, 'stock' => 20, 'sizes' => ['M'], 'sku' => 'T_CORE_SEASON']);
$r1 = t_import($knit); t_ok($r1['ok'] && count($r1['variations']) === 2, 'variable product with 2 variations', json_encode($r1));
$r2 = t_import($real); t_ok($r2['ok'] && $r2['wc_id'] > 0, 'simple product created', json_encode($r2));
$r3 = t_import($season); t_ok($r3['ok'], 'season product');
$pid = Scc_Core::find_product_id('t_core_knit'); $wcp = wc_get_product($pid);
t_ok($wcp && $wcp->is_type('variable'), 'knit is a variable WC product');
t_eq($wcp->get_sku(), 'T_CORE_KNIT', 'SKU preserved');
t_eq(get_post_meta($pid, '_scc_legacy_id', true), 't_core_knit', 'legacy id meta');
$stored = get_post_meta(Scc_Core::find_product_id('t_core_real'), '_scc_doc', true);
t_eq(json_decode($stored, true)['he'], $real['he'], 'quotes/backslashes/slashes survive _scc_doc round trip');
$vm = Scc_Core::variation_map($pid); $v1 = wc_get_product($vm['t_core_knit-M']);
t_ok(count($v1->get_attributes()) >= 1 && $v1->get_stock_quantity() === 50, 'variation attrs + stock 50', json_encode($v1->get_attributes()));
$h = Scc_Core::hydrated(['t_core_real'])[0];
t_eq([$h['price'], $h['previousPrice'], $h['stock'], $h['sizeStock']], [199.9, 249.9, 3, ['S' => 1, 'M' => 2]], 'real product price/previous/stock/sizeStock from WooCommerce');
$h2 = Scc_Core::hydrated(['t_core_knit'])[0];
t_eq([$h2['variants'][0]['stock'], $h2['variants'][1]['stock'], $h2['stock'], $h2['sizeStock']], [50, 50, 100, ['S' => 50, 'M' => 50]], 'variable product stock/sizeStock');
// WooCommerce edit is visible to the storefront
$vm1 = wc_get_product($vm['t_core_knit-S']); $vm1->set_stock_quantity(7); $vm1->save();
$h3 = Scc_Core::hydrated(['t_core_knit'])[0]; t_eq([$h3['variants'][0]['stock'], $h3['sizeStock']['S'], $h3['stock']], [7, 7, 57], 'WC variation stock edit flows to projection');
$vm1->set_stock_quantity(50); $vm1->save();
$cat = Scc_Core_Catalogue::project($h);
t_ok(!array_key_exists('homeSku', $cat), 'homeSku stripped from public projection');
t_eq(Scc_Core::wc_order_id('nope'), 0, 'wc_order_id of unknown is 0');
// inactive / archived disappear
$inactive = $real; $inactive['active'] = false; $inactive['id'] = 't_core_inactive'; $inactive['sku'] = 'T_CORE_INACT'; t_import($inactive);
$names = array_column(Scc_Core_Catalogue::catalogue(), 'id');
t_ok(in_array('t_core_real', $names, true) && !in_array('t_core_inactive', $names, true), 'inactive product absent from catalogue');
$arch = $real; $arch['id'] = 't_core_arch'; $arch['sku'] = 'T_CORE_ARCH'; $arch['archived'] = true; t_import($arch);
t_ok(!in_array('t_core_arch', array_column(Scc_Core_Catalogue::catalogue(), 'id'), true), 'archived product absent from catalogue');
// catalogue route
t_save_setting('catalogueInitialized'); Scc_Docs::set_setting('catalogueInitialized', true);
$c = t_call('GET', '/api/catalogue', null, [], [], false);
t_ok($c['status'] === 200 && $c['json']['configured'] === true, 'GET /api/catalogue configured true', $c['body']);
$mine = array_values(array_filter($c['json']['products'], fn($p) => $p['id'] === 't_core_real'))[0] ?? null;
t_eq($mine['sizeStock']['M'] ?? null, 2, 'catalogue sizeStock.M == 2');
t_eq(array_values(array_filter($c['json']['products'], fn($p) => $p['id'] === 't_core_knit'))[0]['productType'] ?? null, 'jackets', 'productType in catalogue');
t_eq($c['headers']['cache-control'] ?? null, 'no-store', 'catalogue cache-control');
// sizeStock {} stays an object
$emptyss = t_product('t_core_emptyss', ['sizeStock' => new stdClass(), 'sku' => 'T_CORE_EMPTYSS']); $emptyss = json_decode(json_encode($emptyss), true);
t_import($emptyss, ['raw_json' => json_encode(t_product('t_core_emptyss', ['sizeStock' => new stdClass(), 'sku' => 'T_CORE_EMPTYSS']), JSON_UNESCAPED_UNICODE)]);
$cc = t_call('GET', '/api/catalogue', null, [], [], false);
t_ok(str_contains($cc['body'], '"sizeStock":{}'), 'empty sizeStock encodes as {}');

/* ================================================================== 3. quote */
t_sec('3 quote');
t_save_setting('operations'); Scc_Docs::set_setting('operations', ['standardPrice' => 49, 'expressPrice' => 199]); /* pinned so the expected totals do not depend on the store's own prices */
$q = fn(array $body) => t_call('POST', '/api/quote', $body);
$two = $q(['items' => [t_cart_item('t_core_knit', 'M', 2, 't_core_knit-M')], 'shipping' => 'standard']);
t_ok($two['status'] === 200 && $two['json']['subtotal'] == 398 && $two['json']['total'] == 447, 'subtotal 398, standard total 447', $two['body']);
$exp = $q(['items' => [t_cart_item('t_core_knit', 'M', 2, 't_core_knit-M')], 'shipping' => 'express']); t_ok($exp['json']['total'] == 597 && $exp['json']['shipping'] == 199, 'express total 597', $exp['body']);
$three = $q(['items' => [t_cart_item('t_core_knit', 'M', 2, 't_core_knit-M'), t_cart_item('t_core_knit', 'S', 1, 't_core_knit-S')], 'shipping' => 'standard']);
t_ok($three['json']['subtotal'] == 597, 'merged 3 x 199 = 597', $three['body']);
t_eq($q(['items' => [], 'shipping' => 'express'])['status'], 400, 'empty cart rejected');
$sale = $q(['items' => [t_cart_item('t_core_season', 'M', 1)], 'shipping' => 'standard']);
t_ok($sale['json']['subtotal'] == 149 && $sale['json']['total'] == 198, 'end-of-season price 149 -> total 198', $sale['body']);
t_doc_put('promotions', 't_core_promo', ['code' => 'TCORELAUNCH', 'discount' => 10, 'active' => true, 'minSubtotal' => 150, 'startsAt' => '2020-01-01', 'endsAt' => '2100-01-01']);
$cart = ['items' => [t_cart_item('t_core_real', 'M', 1)], 'code' => 'TCORELAUNCH', 'shipping' => 'standard'];
$lq = $q($cart + ['total' => 1, 'discount' => 5000]);
t_ok($lq['status'] === 200 && $lq['json']['subtotal'] == 199.9 && $lq['json']['discount'] == 19.99 && $lq['json']['total'] == 228.91 && $lq['json']['paymentConnected'] === false, 'LAUNCH 10%: 199.90 / 19.99 / 228.91, client values ignored', $lq['body']);
t_eq($q(['items' => [t_cart_item('t_core_real', 'S', 2)], 'shipping' => 'standard'])['status'], 400, 'size S qty 2 vs stock 1 rejected');
t_eq($q(['items' => [t_cart_item('t_core_real', 'M', 1), t_cart_item('t_core_real', 'M', 1)], 'shipping' => 'standard'])['status'], 400, 'duplicate lines rejected');
t_doc_put('promotions', 't_core_promo', ['code' => 'TCORELAUNCH', 'discount' => 10, 'active' => true, 'minSubtotal' => 150, 'startsAt' => '2020-01-01', 'endsAt' => '2100-01-01', 'excludeSale' => true]);
$ex1 = $q($cart); t_ok($ex1['status'] === 400, 'excludeSale promo with a sale item rejected', $ex1['body']);
t_eq($ex1['json']['error'] ?? null, 'הקוד אינו חל על הפריטים שבסל.', 'excludeSale error text');
t_eq(t_call('POST', '/api/quote', $cart, [], [], false)['status'], 403, 'quote without Origin -> 403');
t_eq(t_call('POST', '/api/quote', $cart, [], ['content-type' => 'text/plain'])['status'], 415, 'quote without JSON -> 415');
t_eq(t_call('POST', '/api/quote', '{bad')['status'], 400, 'invalid JSON -> 400');
t_eq(t_call('POST', '/api/quote', $cart, [], ['content-length' => '2000000'])['status'], 413, 'oversized -> 413');
t_eq($q(['items' => [t_cart_item('nope', 'M', 1)], 'shipping' => 'standard'])['json']['error'] ?? null, 'פריט או כמות אינם זמינים.', 'unknown product error text');
t_eq($q(['items' => [t_cart_item('t_core_real', 'M', 9)], 'shipping' => 'standard'])['status'], 400, 'qty > 5 rejected');
t_eq($q(['items' => [t_cart_item('t_core_real', 'M', 1)], 'shipping' => 'free'])['status'], 400, 'bad shipping rejected');

/* ================================================================== 4. requests / club / store / collections */
t_sec('4 requests club store collections');
global $wpdb;
$ereq = strtolower('t_core_req' . wp_generate_password(6, false) . '@example.invalid');
$rq = fn(array $x) => t_call('POST', '/api/requests', $x);
$ok = $rq(['name' => 'Test', 'email' => $ereq, 'orderNumber' => 'LOCAL-TEST', 'type' => 'cancel', 'privacyAcknowledged' => true]);
t_ok($ok['status'] === 201 && !empty($ok['json']['reference']) && $ok['json']['ok'] === true, 'request created 201', $ok['body']); if (!empty($ok['json']['reference'])) $CLEAN['docs'][] = ['requests', $ok['json']['reference']];
t_eq($rq(['name' => 'Test', 'email' => $ereq, 'orderNumber' => 'TEST', 'type' => 'cancel'])['status'], 400, 'request without privacy ack -> 400');
t_eq($rq(['name' => 'Test', 'email' => 'bad', 'orderNumber' => 'T', 'type' => 'cancel', 'privacyAcknowledged' => true])['status'], 400, 'request bad email -> 400');
t_eq($rq(['name' => 'Test', 'email' => $ereq, 'orderNumber' => 'T', 'type' => 'other', 'privacyAcknowledged' => true])['status'], 400, 'request bad type -> 400');
if (!empty($ok['json']['reference'])) { $rd = Scc_Docs::get('requests', $ok['json']['reference']); t_ok(($rd['status'] ?? '') === 'new' && ($rd['privacyVersion'] ?? '') === '2026-10-07' && ($rd['email'] ?? '') === $ereq, 'request doc shape', json_encode($rd)); }
$last = 0; for ($i = 0; $i < 5; $i++) { $x = $rq(['name' => 'T', 'email' => $ereq, 'orderNumber' => "N$i", 'type' => 'support', 'privacyAcknowledged' => true]); $last = $x['status']; if (!empty($x['json']['reference'])) $CLEAN['docs'][] = ['requests', $x['json']['reference']]; }
t_eq($last, 429, 'sixth request within the hour -> 429');
t_save_setting('club'); $wpdb->delete(Scc_Docs::t('settings'), ['id' => 'club']);
t_eq(t_call('POST', '/api/club', ['email' => 'qa@example.invalid', 'consent' => true])['status'], 409, 'club POST while disabled -> 409');
$dflt = t_call('GET', '/api/club', null, [], [], false); t_eq($dflt['json']['description'] ?? null, "המעגל של $BRAND", 'club default description');
Scc_Docs::set_setting('club', ['enabled' => true, 'title' => 'Club', 'privacyUrl' => 'https://example.com/privacy']);
$em = strtolower('t_core_m' . wp_generate_password(6, false) . '@example.invalid');
t_eq(t_call('POST', '/api/club', ['email' => $em, 'consent' => false])['status'], 400, 'club consent false -> 400');
t_eq(t_call('POST', '/api/club', ['email' => $em, 'consent' => true])['status'], 200, 'club signup 200');
t_eq(t_call('POST', '/api/club', ['email' => strtoupper($em), 'consent' => true])['status'], 200, 'club signup repeat 200');
$mem = $wpdb->get_results($wpdb->prepare('SELECT id,data FROM ' . Scc_Docs::t('members') . ' WHERE data LIKE %s', '%' . $wpdb->esc_like($em) . '%'), ARRAY_A);
t_eq(count($mem), 1, 'exactly one member row'); $mid = $mem[0]['id'] ?? ''; $CLEAN['docs'][] = ['members', $mid]; $CLEAN['docs'][] = ['member_profiles', $mid];
$prof = Scc_Docs::get('member_profiles', $mid); t_ok(str_starts_with($prof['cardNumber'] ?? '', "$BRAND-") && ($prof['tier'] ?? '') === 'standard', 'profile card number prefix', json_encode($prof));
t_save_setting('business'); t_save_setting('content'); t_save_setting('store'); t_save_setting('operations');
Scc_Docs::set_setting('business', ['legalName' => 'Test company', 'registrationNumber' => 'TEST']);
Scc_Docs::set_setting('content', ['heroTitle' => 'H', 'x' => 1]); Scc_Docs::set_setting('store', ['heroTitle' => 'S', 'heroSubtitle' => 'SS', 'brands' => ['A', 'B']]);
$wpdb->delete(Scc_Docs::t('settings'), ['id' => 'operations']);
$st = t_call('GET', '/api/store', null, [], [], false);
t_eq($st['body'], '{"heroTitle":"H","x":1,"operations":{},"heroSubtitle":"SS","brands":["A","B"],"business":{"legalName":"Test company","registrationNumber":"TEST"}}', '/api/store payload and key order');
t_ok(!array_key_exists('integrations', $st['json']), 'store does not expose integrations');
$wpdb->delete(Scc_Docs::t('settings'), ['id' => 'content']); $wpdb->delete(Scc_Docs::t('settings'), ['id' => 'store']); $wpdb->delete(Scc_Docs::t('settings'), ['id' => 'business']);
t_eq(t_call('GET', '/api/store', null, [], [], false)['body'], '{"operations":{},"business":{}}', '/api/store empty settings -> {} objects');
foreach ([['t_core_c1', 2, true], ['t_core_c2', 1, true], ['t_core_c3', 0, false], ['t_core_c4', 3, true]] as [$cid, $pos, $act]) t_doc_put('collections', $cid, ['title' => $cid, 'position' => $pos, 'active' => $act, 'archived' => $cid === 't_core_c4']);
$cols = t_call('GET', '/api/collections', null, [], [], false); $cids = array_values(array_filter(array_column($cols['json'], 'id'), fn($i) => str_starts_with($i, 't_core_')));
t_eq($cids, ['t_core_c2', 't_core_c1'], 'collections: active, not archived, sorted by position');
t_ok(isset($cols['json'][0]['revision']), 'collection rows carry revision');

/* ================================================================== 5. order totals (fuzz) and gateway body */
t_sec('5 order totals');
$gw = class_exists('WC_Gateway_Shem_Via_Shimi') ? new WC_Gateway_Shem_Via_Shimi() : null;
mt_srand(20261018);
$mk = function (int $n) use ($gw, &$CLEAN) {
    $names = [['t_core_real', 'M'], ['t_core_real', 'S'], ['t_core_season', 'M'], ['t_core_knit', 'M', 't_core_knit-M'], ['t_core_knit', 'S', 't_core_knit-S']];
    $items = []; $used = [];
    $k = mt_rand(1, 3); shuffle($names);
    foreach (array_slice($names, 0, $k) as $nm) $items[] = ['productId' => $nm[0], 'variantId' => $nm[2] ?? '', 'size' => $nm[1], 'qty' => mt_rand(1, 3)];
    $sub = 0; $prices = ['t_core_real' => 19990, 't_core_season' => 14900, 't_core_knit' => 19900];
    foreach ($items as $i) $sub += $i['qty'] * $prices[$i['productId']];
    $disc = mt_rand(0, 3) === 0 ? 0 : mt_rand(0, (int) ($sub * 0.3));
    $ship = [0, 4900, 19900, 3333][mt_rand(0, 3)];
    $c = ['name' => "Test $n", 'email' => "t_core_$n@example.invalid", 'phone' => '0500000000', 'city' => 'בדיקה', 'street' => 'רחוב 1'];
    $d = Scc_Core_Pricing::make_order(['customer' => $c, 'items' => $items, 'shippingCost' => $ship / 100, 'discount' => $disc / 100, 'shippingMethod' => 'standard']);
    $o = t_draft_order($d, "t_core_fz$n");
    try { $wc = Scc_Core_Orders::create_wc_order($o, $disc ? 'T_CORE' : '', 0); }
    catch (Throwable $e) { t_ok(false, "fuzz $n create order", $e->getMessage() . json_encode([$items, $disc, $ship])); return; }
    $CLEAN['orders'][] = $wc->get_id();
    t_eq((int) round($wc->get_total() * 100), $o['totalCents'], "fuzz $n total equals draft total (" . $o['totalCents'] . ')');
    $sum = 0; foreach ($wc->get_items('line_item') as $li) $sum += (int) round(($li->get_total() + $li->get_total_tax()) * 100);
    $sum += (int) round(($wc->get_shipping_total() + $wc->get_shipping_tax()) * 100);
    t_eq($sum, $o['totalCents'], "fuzz $n lines+shipping (gross) equal total");
    t_eq((int) round(($wc->get_total_tax()) * 100), $o['vatItemsCents'] + $o['vatShippingCents'], "fuzz $n order VAT equals draft VAT");
    if ($gw) { $b = WC_Gateway_Shem_Via_Shimi::build_body($wc); t_ok(is_array($b) && $b['amountCents'] === $o['totalCents'], "fuzz $n gateway body accepted", is_wp_error($b) ? $b->get_error_message() : ''); }
};
for ($n = 1; $n <= 40; $n++) $mk($n);
if (!$gw) t_skip('gateway build_body check (WC_Gateway_Shem_Via_Shimi not present on this site)');

/* ================================================================== 6. checkout-home gating + status/invoice + paid flow */
t_sec('6 checkout');
$cust = ['name' => 'Test Buyer', 'email' => 't_core_buyer@example.invalid', 'phone' => '0500000000', 'city' => 'באר שבע', 'street' => 'דרך מצדה 1'];
$body = ['items' => [t_cart_item('t_core_knit', 'M', 1, 't_core_knit-M')], 'shipping' => 'standard', 'customer' => $cust, 'termsAccepted' => true];
t_save_setting('operations'); t_save_setting('home'); $wpdb->delete(Scc_Docs::t('settings'), ['id' => 'operations']); $wpdb->delete(Scc_Docs::t('settings'), ['id' => 'home']);
$g = t_call('POST', '/api/checkout-home', $body);
t_ok($g['status'] === 503, 'checkout-home disabled -> 503', $g['body']);
t_eq($g['json']['error'] ?? null, 'הרכישה עדיין אינה פעילה. אתר הבית טרם חובר.', 'checkout-home disabled error text');
if ($ROLE === 'shem' && $gw) {
    Scc_Docs::set_setting('operations', ['checkoutEnabled' => true]); Scc_Docs::set_setting('home', ['enabled' => true, 'siteUrl' => 'http://localhost:8082']);
    $b2 = $body; $b2['termsAccepted'] = false; t_eq(t_call('POST', '/api/checkout-home', $b2)['json']['error'] ?? null, 'יש לאשר תנאי רכישה.', 'terms error text');
    $b3 = $body; unset($b3['customer']['phone']); t_eq(t_call('POST', '/api/checkout-home', $b3)['json']['error'] ?? null, 'נדרשים טלפון וכתובת משלוח.', 'phone/address required text');
    // link guard (SCC_CHANNEL_SECRET is set on staging): unlinked product refused
    $nl = t_call('POST', '/api/checkout-home', $body); t_ok($nl['status'] === 409 && ($nl['json']['error'] ?? '') === 'המוצר אינו מקושר ל-SHIMI.', 'unlinked product refused 409', $nl['body']);
    t_doc_put('product_links', 't_core_knit', ['sourceId' => 't_core_knit', 'status' => 'connected', 'variantMap' => ['h-S' => 't_core_knit-S', 'h-M' => 't_core_knit-M']]);
    $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Scc_Channel_Stock::t('reservations'));
    $co = t_call('POST', '/api/checkout-home', $body);
    t_ok($co['status'] === 201 && !empty($co['json']['embedUrl']) && !empty($co['json']['orderId']) && !empty($co['json']['viewToken']), 'checkout-home 201 {embedUrl,orderId,viewToken}', $co['body']);
    if ($co['status'] === 201) {
        $oid = $co['json']['orderId']; $vt = $co['json']['viewToken'];
        t_eq(array_keys($co['json']), ['embedUrl', 'orderId', 'viewToken'], 'response key order');
        $woo = wc_get_order(Scc_Core::wc_order_id($oid)); $CLEAN['orders'][] = $woo->get_id();
        t_ok($woo->has_status('pending') && $woo->get_meta('_shem_pay_embed') === $co['json']['embedUrl'], 'WC order pending with embed meta');
        t_eq((int) round($woo->get_total() * 100), 24800, 'WC order total 248.00');
        $doc = Scc_Core::order_doc($oid);
        t_ok(($doc['paymentStatus'] ?? '') === 'unpaid' && ($doc['source'] ?? '') === 'storefront' && ($doc['paid'] ?? null) === false && $doc['viewToken'] === $vt, 'legacy doc unpaid/storefront/viewToken', json_encode($doc));
        t_eq($doc['totalCents'], 19900 + 4900, 'legacy totalCents 249');
        t_eq(Scc_Core::order_docs_by_email($cust['email'])[0]['id'] ?? null, $oid, 'order_docs_by_email finds it');
        $res = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Scc_Channel_Stock::t('reservations') . " WHERE reservation_id=%s AND state='held'", $oid));
        t_eq($res, 1, 'stock hold created for the order');
        // status
        $s0 = t_call('GET', "/api/checkout-status/$oid", null, ['token' => $vt], [], false);
        t_eq($s0['body'], '{"paymentStatus":"unpaid","orderId":"' . $oid . '","invoiceReady":false}', 'checkout-status unpaid');
        t_eq(t_call('GET', "/api/checkout-status/$oid", null, ['token' => 'wrong'], [], false)['status'], 404, 'status wrong token 404');
        t_eq(t_call('GET', "/api/checkout-status/$oid", null, [], [], false)['body'], '{"error":"Not found"}', 'status no token 404 body');
        t_eq(t_call('GET', "/api/checkout-status/nope", null, ['token' => $vt], [], false)['status'], 404, 'status unknown id 404');
        $iv0 = t_call('GET', "/api/checkout-invoice/$oid", null, ['token' => $vt], [], false); t_eq([$iv0['status'], $iv0['json']['error'] ?? null], [409, 'התשלום עדיין לא אושר.'], 'invoice before payment 409');
        t_eq(t_call('GET', "/api/checkout-invoice/$oid", null, ['token' => 'x'], [], false)['status'], 404, 'invoice wrong token 404');
        // rate limit for the same email is 10/hour: not exercised here (not-run)
        // paid (what SHEM-DOK /shimi/paid does): meta first, then payment_complete
        $stk0 = t_wc_product_stock('t_core_knit', 't_core_knit-M');
        $woo->update_meta_data('_shem_pay_verified', '1'); $woo->update_meta_data('_shem_pay_method', 'card'); $woo->update_meta_data('_shem_pay_last4', '4242'); $woo->update_meta_data('_shem_pay_brand', 'visa'); $woo->update_meta_data('_shem_pay_auth', 'AUTH1'); $woo->update_meta_data('_shem_pay_installments', '3'); $woo->save();
        $woo->payment_complete('t_core_txn_1');
        $pd = Scc_Core::order_doc($oid); $CLEAN['docs'][] = ['payment_transactions', 'home:t_core_txn_1'];
        t_ok(($pd['paymentStatus'] ?? '') === 'paid' && $pd['paid'] === true && ($pd['status'] ?? '') === 'processing' && ($pd['paymentReference'] ?? '') === 't_core_txn_1' && ($pd['paymentSource'] ?? '') === 'home', 'legacy doc marked paid', json_encode($pd));
        t_eq($pd['paymentDetails'] ?? null, ['method' => 'card', 'last4' => '4242', 'cardholderName' => '', 'brand' => 'visa', 'authorizationCode' => 'AUTH1', 'installments' => 3], 'paymentDetails shape');
        t_eq(t_wc_product_stock('t_core_knit', 't_core_knit-M'), $stk0 - 1, 'WooCommerce reduced variation stock once');
        $st1 = t_call('GET', "/api/checkout-status/$oid", null, ['token' => $vt], [], false); t_eq($st1['json']['paymentStatus'] ?? null, 'paid', 'status paid');
        $held = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Scc_Channel_Stock::t('reservations') . " WHERE reservation_id=%s AND state='held'", $oid)); t_eq($held, 0, 'holds closed after payment (channel hook)');
        $woo2 = wc_get_order($woo->get_id()); $woo2->payment_complete('t_core_txn_1');
        t_eq(t_wc_product_stock('t_core_knit', 't_core_knit-M'), $stk0 - 1, 'second payment_complete does not reduce again');
        $iv1 = t_call('GET', "/api/checkout-invoice/$oid", null, ['token' => $vt], [], false); t_eq([$iv1['status'], $iv1['json']['error'] ?? null], [409, 'חשבונית העסקה עדיין אינה זמינה.'], 'invoice paid but no SHEM-DOK document -> 409');
        t_skip('invoice PDF download (needs a SHEM-DOK document; issuing one consumes an invoice number)');
    }
} else { t_skip('checkout-home end to end (needs the SHEM site with the SHEM-PAY gateway)'); }

} catch (Throwable $e) { t_ok(false, 'unhandled exception', get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); }
finally {
/* ================================================================== cleanup (own data only) */
    global $wpdb;
    foreach (array_unique($CLEAN['orders']) as $oid) { $o = wc_get_order($oid); if ($o) $o->delete(true); }
    foreach (array_keys($CLEAN['products']) as $lid) { $pid = Scc_Core::find_product_id($lid); if ($pid) { $p = wc_get_product($pid); if ($p) $p->delete(true); } Scc_Docs::delete('products', $lid); }
    foreach ($CLEAN['docs'] as [$tb, $id]) if ($id !== '') Scc_Docs::delete($tb, $id);
    $wpdb->query("DELETE FROM " . Scc_Docs::t('stock_movements') . " WHERE data LIKE '%t\\_core\\_%'");
    if (class_exists('Scc_Channel_Stock')) {
        $wpdb->query("DELETE FROM " . Scc_Channel_Stock::t('reservations') . " WHERE product_id LIKE 't\\_core\\_%'");
        $wpdb->query("DELETE FROM " . Scc_Channel_Stock::t('ledger') . " WHERE product_id LIKE 't\\_core\\_%'");
    }
    $wpdb->query("DELETE FROM " . Scc_Docs::t('orders') . " WHERE id LIKE 't\\_core\\_%'");
    t_restore_settings(); Scc_Core::forget_cache();
    $T = $GLOBALS['T'];
    echo "\n===== core-test ($ROLE): PASS {$T['pass']}  FAIL {$T['fail']}  NOT-RUN {$T['skip']} =====\n";
    foreach ($T['by'] as $s => [$p, $f]) echo sprintf("  %-28s pass %3d  fail %d\n", $s, $p, $f);
    foreach ($T['fails'] as $f) echo "FAIL $f\n";
}
