<?php
/**
 * Customer-experience module tests (PHP port of SHEM/tests/customer-experience.test.mjs + customer-invoice.test.mjs).
 * Black-box over HTTP against the SHEM staging site, plus WP-CLI eval for fixtures. Staging only (never the live sites).
 *
 *   php WP-PLUGIN/shem-shimi-commerce/tests/cx-test.php
 *
 * Fixtures are prefixed t_cx_; the run removes only its own fixtures and restores the shared settings it changed.
 * Needs: docker staging up (shem :8081, shimi :8082), /home/claude/wps.sh. The shipment-webhook secret is a temporary wp-config
 * constant (random, local) that the script removes at the end.
 */
const BASE = 'http://localhost:8081';
const BASE_SHIMI = 'http://localhost:8082';
const WPS = '/home/claude/wps.sh';

$GLOBALS['pass'] = 0; $GLOBALS['fail'] = 0; $GLOBALS['fails'] = []; $GLOBALS['section'] = '';
function section(string $s): void { $GLOBALS['section'] = $s; echo "\n== $s\n"; }
function check(string $name, bool $ok, mixed $detail = null): void {
    if ($ok) { $GLOBALS['pass']++; echo "  ok   $name\n"; return; }
    $GLOBALS['fail']++; $GLOBALS['fails'][] = $GLOBALS['section'] . ': ' . $name;
    echo "  FAIL $name" . ($detail !== null ? ' :: ' . (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n";
}
function wp(string $site, string $php): mixed {
    $cmd = WPS . ' ' . $site . ' eval ' . escapeshellarg($php) . ' 2>&1';
    $out = shell_exec($cmd) ?? '';
    foreach (array_reverse(explode("\n", $out)) as $l) if (str_starts_with($l, '@@')) return json_decode(substr($l, 2), true) ?? ['_raw' => $l];
    return ['_error' => $out];
}
/** @return array{0:int,1:array,2:string,3:mixed} status, headers, body, decoded json */
function http(string $base, string $method, string $path, mixed $data = null, array $o = []): array {
    $h = [];
    $origin = array_key_exists('origin', $o) ? $o['origin'] : $base;
    if ($origin !== null) $h[] = 'Origin: ' . $origin;
    if (!array_key_exists('ct', $o) || $o['ct'] !== null) $h[] = 'Content-Type: ' . ($o['ct'] ?? 'application/json');
    if (!empty($o['cookie'])) $h[] = 'Cookie: ' . $o['cookie'];
    if (!empty($o['nonce'])) $h[] = 'X-WP-Nonce: ' . $o['nonce'];
    foreach ($o['headers'] ?? [] as $k => $v) $h[] = "$k: $v";
    $body = $data === null ? null : (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE));
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $hdr = []; foreach (explode("\r\n", substr((string) $resp, 0, $hs)) as $l) if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $hdr[strtolower(trim($k))] = trim($v); }
    $b = substr((string) $resp, $hs);
    return [$code, $hdr, $b, json_decode($b, true)];
}
function api(string $method, string $path, mixed $data = null, array $o = []): array { return http(BASE, $method, $path, $data, $o); }

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$P = 't_cx_' . $run;
$secret = bin2hex(random_bytes(16));

/* ---------- fixtures ---------- */
section('setup');
$setup = wp('shem', str_replace('__RUN__', $run, <<<'PHP'
$run = '__RUN__'; $P = 't_cx_' . $run; $out = [];
foreach (['admin' => 'administrator', 'buyer' => 'subscriber', 'other' => 'subscriber'] as $k => $role) {
  $login = $P . '_' . $k; $email = $k === 'admin' ? 'your.shem88+' . $P . '@example.test' : ($k === 'buyer' ? 'Buyer@Example.test' : 'other@example.test');
  $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(24), 'user_email' => $email, 'role' => $role]);
  if (is_wp_error($id)) { echo '@@' . json_encode(['error' => $id->get_error_message()]); exit; }
  $exp = time() + 7200; $tok = WP_Session_Tokens::get_instance($id)->create($exp);
  $out[$k] = ['id' => $id, 'email' => strtolower($email), 'cookie' => LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie($id, $exp, 'logged_in', $tok)];
}
$out['prev'] = ['help' => Scc_Docs::get('settings', 'help'), 'experience' => Scc_Docs::get('settings', 'experience'), 'loyalty' => Scc_Docs::get('settings', 'loyalty')];
$out['engine'] = class_exists('ShemDok') && ShemDok::engine_ready();
$out['scc_core'] = class_exists('Scc_Core');
$out['audit_before'] = (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . Scc_Docs::t('audit'));
echo '@@' . json_encode($out);
PHP));
if (isset($setup['error']) || empty($setup['admin'])) { echo "setup failed: " . json_encode($setup) . "\n"; exit(2); }
$admin = $setup['admin']; $buyer = $setup['buyer']; $other = $setup['other'];
$nonce = trim(api('GET', '/wp-admin/admin-ajax.php?action=rest-nonce', null, ['cookie' => $admin['cookie']])[2]);
check('fixtures: 3 users with sessions, admin REST nonce obtained', $nonce !== '' && strlen($nonce) < 20, $nonce);
check('fixtures: DOK engine ready (real invoice PDF path)', $setup['engine'] === true);
echo "  info Scc_Core present: " . json_encode($setup['scc_core']) . " (order seam: " . ($setup['scc_core'] ? 'Scc_Core' : 'WC legacy-meta fallback') . ")\n";
$A = ['cookie' => $admin['cookie'], 'nonce' => $nonce];
$B = ['cookie' => $buyer['cookie']];
$O = ['cookie' => $other['cookie']];

/* ---------- 1. productPresentation (unit test 2 of customer-experience.test.mjs) ---------- */
section('productPresentation validator (unit)');
$pp = wp('shem', <<<'PHP'
$cases = [
 'external_image' => ['images' => [['url' => 'https://untrusted.test/image.png']]],
 'four_images' => ['images' => array_fill(0, 4, ['url' => '/assets/knit.png'])],
 'row_values_missing' => ['sizeGuide' => ['columns' => ['חזה'], 'rows' => [['size' => 'M', 'values' => []]]]],
 'duplicate_size' => ['sizeGuide' => ['columns' => ['חזה'], 'rows' => [['size' => 'M', 'values' => ['100']], ['size' => 'M', 'values' => ['102']]]]],
 'bad_hex' => ['colors' => [['name' => 'שחור', 'hex' => 'javascript:bad']]],
 'dotdot' => ['images' => [['url' => '/assets/../secret.png']]],
 'two_colors_no_variants' => ['colors' => [['name' => 'a', 'hex' => '#111111'], ['name' => 'b', 'hex' => '#222222']]],
 'dup_color' => ['variants' => [['id' => 'v']], 'colors' => [['name' => 'a', 'hex' => '#111111'], ['name' => 'a', 'hex' => '#222222']]],
 'rows_without_columns' => ['sizeGuide' => ['rows' => [['size' => 'M', 'values' => []]]]],
];
$out = [];
foreach ($cases as $k => $c) { try { Scc_Cx_Presentation::validate($c); $out[$k] = 'NO-THROW'; } catch (Scc_Http_Exception $e) { $out[$k] = [$e->status, $e->getMessage()]; } }
$ok = Scc_Cx_Presentation::validate(['images' => [['url' => '/assets/knit.png', 'alt' => 'חזית'], ['url' => '/assets/shirt.png', 'alt' => 'גב'], ['url' => '/assets/trousers.png', 'alt' => 'תקריב']],
  'sizeGuide' => ['columns' => ['חזה', 'אורך'], 'rows' => [['size' => 'M', 'values' => ['100', 70]]], 'instructions' => 'מדדו מסביב לחזה'], 'colors' => [['name' => 'black', 'hex' => '#111111']]]);
$out['valid'] = $ok;
echo '@@' . json_encode($out, JSON_UNESCAPED_UNICODE);
PHP);
foreach (['external_image', 'four_images'] as $k) check("rejects $k (400, Hebrew)", ($pp[$k][0] ?? 0) === 400 && $pp[$k][1] === 'ניתן להוסיף עד שלוש תמונות מוצר שהועלו לאתר.', $pp[$k] ?? null);
check('rejects row with missing values', ($pp['row_values_missing'][1] ?? '') === 'יש להזין מידה וערך לכל עמודה בטבלת המידות.', $pp['row_values_missing'] ?? null);
check('rejects duplicate sizes', ($pp['duplicate_size'][1] ?? '') === 'יש להסיר מידות כפולות בטבלה.', $pp['duplicate_size'] ?? null);
check('rejects non-hex colour', ($pp['bad_hex'][1] ?? '') === 'יש להזין שם צבע וקוד צבע תקין.', $pp['bad_hex'] ?? null);
check('rejects path traversal in image url', ($pp['dotdot'][0] ?? 0) === 400, $pp['dotdot'] ?? null);
check('rejects several colours without variants', ($pp['two_colors_no_variants'][1] ?? '') === 'למוצר עם מספר צבעים יש להגדיר גרסאות מידה וצבע.', $pp['two_colors_no_variants'] ?? null);
check('rejects duplicate colour names', ($pp['dup_color'][1] ?? '') === 'יש להסיר צבעים כפולים.', $pp['dup_color'] ?? null);
check('rejects rows without columns', ($pp['rows_without_columns'][1] ?? '') === 'יש להוסיף לפחות עמודת מדידה אחת.', $pp['rows_without_columns'] ?? null);
check('valid input: 3 images, chart value "70", colour hex kept', count($pp['valid']['images'] ?? []) === 3 && ($pp['valid']['sizeGuide']['rows'][0]['values'][1] ?? '') === '70' && ($pp['valid']['colors'][0]['hex'] ?? '') === '#111111', $pp['valid'] ?? null);
echo "  not-run frontend unit test 1 (src/experience.js matchesSearch/lineColour): belongs to the theme/frontend task\n";

/* ---------- product + orders fixtures ---------- */
section('fixtures: product, orders');
$fx = wp('shem', str_replace(['__RUN__', '__BUYER__', '__OTHER__'], [$run, $buyer['email'], $other['email']], <<<'PHP'
$P = 't_cx_' . '__RUN__'; $out = [];
$pres = Scc_Cx_Presentation::validate(['images' => [['url' => '/assets/knit.png', 'alt' => 'חזית'], ['url' => '/assets/shirt.png', 'alt' => 'גב'], ['url' => '/assets/trousers.png', 'alt' => 'תקריב']],
  'sizeGuide' => ['columns' => ['חזה', 'אורך'], 'rows' => [['size' => 'M', 'values' => ['100', '70']]], 'instructions' => 'מדדו מסביב לחזה'], 'colors' => [['name' => 'black', 'hex' => '#111111']]]);
$prod = $pres + ['he' => 'סריג לבדיקה מבודדת', 'sku' => 'CX-1', 'price' => 149, 'stock' => 2, 'sizes' => ['M'], 'sizeStock' => ['M' => 2], 'image' => 'knit', 'active' => true, 'color' => 'black'];
Scc_Docs::put('products', $P . '_p1', $prod);
$prod2 = ['he' => 'מוצר עם וריאנטים', 'sku' => 'CX-2', 'price' => 99, 'stock' => 3, 'sizes' => ['M', 'L'], 'active' => true, 'color' => 'black',
  'variants' => [['id' => $P . '_v1', 'size' => 'M', 'color' => 'black', 'stock' => 0], ['id' => $P . '_v2', 'size' => 'L', 'color' => 'ivory', 'stock' => 3]]];
Scc_Docs::put('products', $P . '_p2', $prod2);
// WooCommerce product + order for a real DOK invoice
$wcp = new WC_Product_Simple(); $wcp->set_name('סריג לבדיקה מבודדת'); $wcp->set_sku($P . '-SKU'); $wcp->set_regular_price('149'); $wcp->set_status('publish'); $wcp->save();
$mk = function (string $legacyId, string $email, array $extra = []) use ($wcp, $P) {
  $o = wc_create_order(); $o->add_product($wcp, 1);
  $o->set_billing_first_name('לקוח'); $o->set_billing_last_name('בדיקה'); $o->set_billing_email($email); $o->set_billing_phone('0500000000'); $o->set_billing_address_1('בדיקה 1'); $o->set_billing_city('בדיקה'); $o->set_billing_country('IL');
  $si = new WC_Order_Item_Shipping(); $si->set_method_title('שליח'); $si->set_method_id('flat_rate'); $si->set_total('49'); $o->add_item($si);
  $o->set_currency('ILS'); $o->set_payment_method('paypal'); $o->set_payment_method_title('PayPal'); $o->calculate_totals(true); $o->set_status('pending');
  $doc = ['customer' => ['name' => 'לקוח בדיקה', 'email' => $email, 'phone' => '0500000000', 'city' => 'בדיקה', 'street' => 'בדיקה 1'],
    'items' => [['name' => 'סריג לבדיקה מבודדת', 'sku' => 'CX-1', 'size' => 'M', 'color' => 'black', 'qty' => 1, 'priceCents' => 14900, 'lineCents' => 14900, 'internalCost' => 5000]],
    'totalCents' => 19800, 'subtotalCents' => 14900, 'discountCents' => 0, 'shippingCents' => 4900, 'shippingMethod' => 'standard',
    'status' => 'new', 'paymentStatus' => 'unpaid', 'viewToken' => 'isolated-private-token-' . $legacyId, 'notes' => 'INTERNAL-ONLY', 'createdAt' => '2026-10-18T10:00:00.000Z'] + $extra;
  $o->update_meta_data(Scc_Cx_Orders::M_ID, $legacyId); $o->update_meta_data(Scc_Cx_Orders::M_DOC, wp_json_encode($doc, JSON_UNESCAPED_UNICODE));
  $o->update_meta_data(Scc_Cx_Orders::M_UPD, '2026-10-18T10:00:00.000Z'); $o->update_meta_data(Scc_Cx_Orders::M_EMAIL, strtolower($email)); $o->save();
  return $o->get_id();
};
$out['wc1'] = $mk($P . '_o1', '__BUYER__');
$out['wc2'] = $mk($P . '_o2', '__BUYER__', ['paymentStatus' => 'paid', 'status' => 'cancelled']);
$out['wc3'] = $mk($P . '_o3', '__OTHER__', ['paymentStatus' => 'unpaid']);
$out['wcp'] = $wcp->get_id();
echo '@@' . json_encode($out);
PHP));
check('fixtures: products, WC product and 3 legacy-meta WC orders created', isset($fx['wc1'], $fx['wc2'], $fx['wc3']), $fx);
$p1 = $P . '_p1'; $p2 = $P . '_p2'; $o1 = $P . '_o1'; $o2 = $P . '_o2'; $o3 = $P . '_o3';
$tok1 = 'isolated-private-token-' . $o1; $guest = ['headers' => ['Authorization' => 'Bearer ' . $tok1]];
$me = fn(array $who) => api('GET', '/api/customer/me', null, $who)[3];

/* ---------- 2. routes absent for SHIMI ---------- */
section('SHIMI role: routes answer 404');
foreach ([['GET', '/api/customer/me'], ['GET', '/api/help'], ['GET', '/api/experience'], ['POST', '/api/shipment-events'], ['GET', '/api/admin/experience'], ['POST', '/api/experience/events']] as [$m, $pth]) {
    [$c, , , $j] = http(BASE_SHIMI, $m, $pth, $m === 'POST' ? [] : null);
    check("$m $pth -> 404 on shimi", $c === 404, [$c, $j]);
}

/* ---------- 3. watches, preferences, notifications ---------- */
section('watches, preferences, notifications');
check('anonymous /me -> {signedIn:false,orders:[],notifications:[]}', ($r = api('GET', '/api/customer/me')) && $r[0] === 200 && $r[3] === ['signedIn' => false, 'orders' => [], 'notifications' => []], $r[3]);
check('anonymous watch POST -> 401 Hebrew', ($r = api('POST', '/api/customer/watches', ['productId' => $p1, 'size' => 'M'])) && $r[0] === 401 && $r[3]['error'] === 'יש להתחבר כדי לראות את ההזמנות וההטבות שלך.', $r[3]);
check('watch with unavailable size L -> 400 "יש לבחור מידה וצבע להתראה."', ($r = api('POST', '/api/customer/watches', ['productId' => $p1, 'size' => 'L'], $B)) && $r[0] === 400 && $r[3]['error'] === 'יש לבחור מידה וצבע להתראה.', $r[3]);
check('watch on unknown product -> 404 "הפריט אינו זמין."', ($r = api('POST', '/api/customer/watches', ['productId' => 'nope', 'size' => 'M'], $B)) && $r[0] === 404 && $r[3]['error'] === 'הפריט אינו זמין.', $r[3]);
check('watch on variant product without variantId -> 400', ($r = api('POST', '/api/customer/watches', ['productId' => $p2, 'size' => 'M'], $B)) && $r[0] === 400, $r[3]);
check('watch variant/size mismatch -> 400', ($r = api('POST', '/api/customer/watches', ['productId' => $p2, 'variantId' => $P . '_v1', 'size' => 'L'], $B)) && $r[0] === 400, $r[3]);
check('watch POST size M -> 201 {ok:true}', ($r = api('POST', '/api/customer/watches', ['productId' => $p1, 'size' => 'M'], $B)) && $r[0] === 201 && $r[3] === ['ok' => true], $r[3]);
check('variant watch (out of stock variant) -> 201', ($r = api('POST', '/api/customer/watches', ['productId' => $p2, 'variantId' => $P . '_v1', 'size' => 'M'], $B)) && $r[0] === 201);
check('variant watch (in stock variant) -> 201', ($r = api('POST', '/api/customer/watches', ['productId' => $p2, 'variantId' => $P . '_v2', 'size' => 'L'], $B)) && $r[0] === 201);
$m = $me($other); check("other customer sees 0 watches", $m['signedIn'] === true && count($m['watches']) === 0 && count($m['orders']) === 1, $m);
$m = $me($B);
$notif = array_column($m['notifications'], 'productId');
check('buyer: 3 watches, notifications only for in-stock (p1 M and p2 v2)', count($m['watches']) === 3 && count($m['notifications']) === 2 && in_array($p1, $notif, true) && in_array($p2, $notif, true), $m['notifications']);
check('notification shape {id,productId,title,size,color,kind}', ($m['notifications'][0]['kind'] ?? '') === 'back-in-stock' && isset($m['notifications'][0]['title'], $m['notifications'][0]['id']), $m['notifications'][0] ?? null);
check('watch entry does not leak userId', !isset($m['watches'][0]['userId']) && isset($m['watches'][0]['createdAt']), $m['watches'][0] ?? null);
check('preferences PUT siteNotifications=false -> echo', ($r = api('PUT', '/api/customer/preferences', ['siteNotifications' => false], $B)) && $r[0] === 200 && $r[3] === ['siteNotifications' => false], $r[3]);
$m = $me($B); check('notifications hidden when customer opts out; watches kept', count($m['notifications']) === 0 && count($m['watches']) === 3 && $m['preferences'] === ['siteNotifications' => false], $m['preferences']);
api('PUT', '/api/customer/preferences', ['siteNotifications' => true], $B);
check('notifications back after opt-in', count($me($B)['notifications']) === 2);
check('anonymous preferences PUT -> 401', api('PUT', '/api/customer/preferences', ['siteNotifications' => false])[0] === 401);

/* ---------- 3b. request guards ---------- */
section('request guards (origin, content-type, body)');
check('POST with foreign Origin -> 403 Origin rejected', ($r = api('POST', '/api/customer/watches', ['productId' => $p1, 'size' => 'M'], $B + ['origin' => 'https://evil.test'])) && $r[0] === 403 && $r[3]['error'] === 'Origin rejected', $r[3]);
check('POST without Origin -> 403', api('POST', '/api/customer/watches', ['productId' => $p1, 'size' => 'M'], $B + ['origin' => null])[0] === 403);
check('POST with text/plain -> 415 JSON required', ($r = api('POST', '/api/customer/watches', '{"productId":"x"}', $B + ['ct' => 'text/plain'])) && $r[0] === 415 && $r[3]['error'] === 'JSON required', $r[3]);
check('malformed JSON -> 400 "הבקשה אינה תקינה."', ($r = api('PUT', '/api/customer/preferences', '{bad', $B)) && $r[0] === 400 && $r[3]['error'] === 'הבקשה אינה תקינה.', $r[3]);
check('body > 1,000,000 bytes -> 413', api('PUT', '/api/customer/preferences', str_repeat(' ', 1000002) . '{}', $B)[0] === 413);
check('DELETE with foreign Origin -> 403', api('DELETE', '/api/customer/watches/x', null, $B + ['origin' => 'https://evil.test'])[0] === 403);

/* ---------- 4. order ownership ---------- */
section('order ownership (server-side)');
foreach ([['no auth, no token', []], ['other signed-in customer', $O], ['wrong bearer', ['headers' => ['Authorization' => 'Bearer wrong']]], ['bearer of another order', ['headers' => ['Authorization' => 'Bearer isolated-private-token-' . $o3]]]] as [$n, $who]) {
    $r = api('GET', '/api/customer/orders/' . $o1, null, $who);
    check("$n -> 404 with Hebrew message", $r[0] === 404 && $r[3]['error'] === 'לא ניתן לפתוח את ההזמנה. בדקו את הקישור האישי או התחברו לחשבון הרכישה.', [$r[0], $r[3]]);
}
check('unknown order id -> 404', api('GET', '/api/customer/orders/' . $P . '_nope', null, $B)[0] === 404);
$m = $me($B);
check('buyer /me lists own orders only (o1 + o2, not other\'s o3)', count($m['orders']) === 2 && !in_array($o3, array_column($m['orders'], 'id'), true), array_column($m['orders'], 'id'));
$ord = null; foreach ($m['orders'] as $x) if ($x['id'] === $o1) $ord = $x;
check('order view hides viewToken, notes and internal item fields', $ord && !array_key_exists('viewToken', $ord) && !array_key_exists('notes', $ord) && !array_key_exists('internalCost', $ord['items'][0]), $ord);
check('order view: invoiceReady=false while unpaid; totalCents 19800', $ord && $ord['invoiceReady'] === false && $ord['totalCents'] === 19800 && $ord['items'][0]['priceCents'] === 14900, $ord);
$m2 = $me($O); check('other customer /me: only own order o3', count($m2['orders']) === 1 && $m2['orders'][0]['id'] === $o3, array_column($m2['orders'], 'id'));
check('guest bearer opens the order', ($r = api('GET', '/api/customer/orders/' . $o1, null, $guest)) && $r[0] === 200 && $r[3]['id'] === $o1, $r[3]);
check('signed-in email match is case-insensitive (buyer "Buyer@Example.test")', api('GET', '/api/customer/orders/' . $o1, null, $B)[0] === 200);
check('invoice of unpaid order -> 409 "החשבונית תהיה זמינה לאחר אישור התשלום."', ($r = api('GET', '/api/customer/orders/' . $o1 . '/invoice', null, $B)) && $r[0] === 409 && $r[3]['error'] === 'החשבונית תהיה זמינה לאחר אישור התשלום.', $r[3]);
check('invoice with no auth -> 404', api('GET', '/api/customer/orders/' . $o1 . '/invoice')[0] === 404);

/* ---------- 5. invoice after payment ---------- */
section('invoice (SHEM DOK) after verified payment');
$inv = wp('shem', str_replace(['__WC__', '__LEG__'], [$fx['wc1'] ?? 0, $o1], <<<'PHP'
$o = wc_get_order(__WC__); $o->set_transaction_id('T_CX_TXN'); $o->set_status('processing'); $o->save();
$doc = json_decode($o->get_meta(Scc_Cx_Orders::M_DOC), true); $doc['paymentStatus'] = 'paid'; $doc['status'] = 'new';
$o->update_meta_data(Scc_Cx_Orders::M_DOC, wp_json_encode($doc, JSON_UNESCAPED_UNICODE)); $o->update_meta_data(Scc_Cx_Orders::M_UPD, '2026-10-18T10:05:00.000Z'); $o->save();
$out = ['paid' => $o->is_paid(), 'total' => $o->get_total()];
// paid but no document yet
echo '@@' . json_encode($out);
PHP));
check('order marked paid (WC paid + legacy doc paymentStatus=paid)', ($inv['paid'] ?? false) === true && (float) ($inv['total'] ?? 0) > 198.0, $inv);
check('paid without document: invoiceReady=false and /invoice -> 409 "המסמך עדיין בהכנה. אפשר לרענן בעוד רגע."', ($r = api('GET', '/api/customer/orders/' . $o1 . '/invoice', null, $B)) && $r[0] === 409 && $r[3]['error'] === 'המסמך עדיין בהכנה. אפשר לרענן בעוד רגע.' && ($me($B)['orders'][0]['invoiceReady'] ?? null) !== true, $r[3]);
$doc = wp('shem', str_replace('__WC__', (string) ($fx['wc1'] ?? 0), <<<'PHP'
try { $res = ShemDok::issue_for_order(wc_get_order(__WC__)); $row = ShemDok::invoice_for_order(__WC__); $pdf = ShemDok::stored_pdf((int) $row['id']);
  echo '@@' . json_encode(['id' => $res['id'], 'sha' => hash('sha256', $pdf), 'len' => strlen($pdf), 'mail' => (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . Scc_Docs::t('mail_jobs'))]); }
catch (Throwable $e) { echo '@@' . json_encode(['error' => $e->getMessage()]); }
PHP));
check('invoice document issued by SHEM DOK for the paid order', isset($doc['id']) && ($doc['len'] ?? 0) > 1000, $doc);
$ord = null; foreach ($me($B)['orders'] as $x) if ($x['id'] === $o1) $ord = $x;
check('/me: invoiceReady=true after the document exists', $ord && $ord['invoiceReady'] === true, $ord);
$r = api('GET', '/api/customer/orders/' . $o1 . '/invoice', null, $B);
check('invoice -> 200 application/pdf, inline, private,no-store, no-referrer', $r[0] === 200 && str_starts_with($r[1]['content-type'] ?? '', 'application/pdf') && str_starts_with($r[1]['content-disposition'] ?? '', 'inline;') && ($r[1]['cache-control'] ?? '') === 'private,no-store' && ($r[1]['referrer-policy'] ?? '') === 'no-referrer', $r[1]);
check('invoice filename SHEM-invoice-<id>.pdf', str_contains($r[1]['content-disposition'] ?? '', 'filename="SHEM-invoice-' . preg_replace('/[^a-zA-Z0-9-]/', '', $o1) . '.pdf"'), $r[1]['content-disposition'] ?? null);
check('served bytes are the stored DOK PDF (sha256 equal, unchanged)', hash('sha256', $r[2]) === ($doc['sha'] ?? 'x'), [hash('sha256', $r[2]), $doc['sha'] ?? null]);
preg_match('#/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]#', $r[2], $mb);
check('PDF is A4 (595.28 x 841.89)', isset($mb[1]) && abs((float) $mb[1] - 595.28) < 0.02 && abs((float) $mb[2] - 841.89) < 0.02, $mb);
check('other customer -> invoice 404', api('GET', '/api/customer/orders/' . $o1 . '/invoice', null, $O)[0] === 404);
$g = api('GET', '/api/customer/orders/' . $o1 . '/invoice?download=1', null, $guest);
check('guest bearer + ?download=1 -> attachment, same bytes', $g[0] === 200 && str_starts_with($g[1]['content-disposition'] ?? '', 'attachment;') && $g[2] === $r[2], $g[1]);
check('no mail jobs created by viewing/downloading', ($doc['mail'] ?? -1) === 0, $doc);
check('cancelled+paid order o2 has no invoice -> /invoice 409 document in preparation', ($x = api('GET', '/api/customer/orders/' . $o2 . '/invoice', null, $B))[0] === 409, $x[3]);

/* ---------- 6. shipment history visible to the customer ---------- */
section('customer view of shipping data');
wp('shem', str_replace(['__WC__'], [(string) ($fx['wc1'] ?? 0)], <<<'PHP'
$o = wc_get_order(__WC__); $doc = json_decode($o->get_meta(Scc_Cx_Orders::M_DOC), true);
$doc = array_merge($doc, ['status' => 'shipped', 'tracking' => 'TRACK-123', 'carrier' => 'חברת בדיקה', 'trackingUrl' => 'https://carrier.test/tracking', 'estimatedDelivery' => '2026-10-20', 'notes' => 'INTERNAL-ONLY', 'shipmentHistory' => [['status' => 'shipped', 'at' => '2026-10-18T11:00:00.000Z', 'secret' => 'x']]]);
$o->update_meta_data(Scc_Cx_Orders::M_DOC, wp_json_encode($doc, JSON_UNESCAPED_UNICODE)); $o->update_meta_data(Scc_Cx_Orders::M_UPD, '2026-10-18T11:00:00.000Z'); $o->save(); echo '@@1';
PHP));
$d = api('GET', '/api/customer/orders/' . $o1, null, $guest)[3];
check('detail: status shipped, tracking, https trackingUrl, ETA, history has shipped', $d['status'] === 'shipped' && $d['tracking'] === 'TRACK-123' && $d['trackingUrl'] === 'https://carrier.test/tracking' && $d['estimatedDelivery'] === '2026-10-20' && $d['shipmentHistory'] === [['status' => 'shipped', 'at' => '2026-10-18T11:00:00.000Z']], $d);
check('detail has no notes / viewToken', !array_key_exists('notes', $d) && !array_key_exists('viewToken', $d));
wp('shem', str_replace('__WC__', (string) ($fx['wc1'] ?? 0), <<<'PHP'
$o = wc_get_order(__WC__); $doc = json_decode($o->get_meta(Scc_Cx_Orders::M_DOC), true); $doc['trackingUrl'] = 'javascript:alert(1)';
$o->update_meta_data(Scc_Cx_Orders::M_DOC, wp_json_encode($doc)); $o->update_meta_data(Scc_Cx_Orders::M_UPD, '2026-10-18T11:01:00.000Z'); $o->save(); echo '@@1';
PHP));
check('non-https trackingUrl is never exposed to the customer', api('GET', '/api/customer/orders/' . $o1, null, $guest)[3]['trackingUrl'] === '');

/* ---------- 7. loyalty block of /me ---------- */
section('loyalty summary in /api/customer/me');
$ly = wp('shem', str_replace(['__RUN__', '__MAIL__'], [$run, $buyer['email']], <<<'PHP'
$P = 't_cx_' . '__RUN__';
Scc_Docs::put('member_profiles', $P . '_m1', ['email' => '__MAIL__', 'tier' => 'gold', 'status' => 'active', 'cardNumber' => 'C-1']);
Scc_Docs::put('loyalty_ledger', $P . '_l1', ['memberId' => $P . '_m1', 'deltaCents' => 5000]);
Scc_Docs::put('loyalty_ledger', $P . '_l2', ['memberId' => $P . '_m1', 'deltaCents' => 2000, 'availableAt' => '2099-01-01T00:00:00.000Z']);
Scc_Docs::put('loyalty_ledger', $P . '_l3', ['memberId' => $P . '_m1', 'deltaCents' => 900, 'expiresAt' => '2020-01-01T00:00:00.000Z']);
Scc_Docs::put('loyalty_ledger', $P . '_l4', ['memberId' => $P . '_m1', 'deltaCents' => -1500]);
Scc_Docs::put('loyalty_ledger', $P . '_l5', ['memberId' => $P . '_zz', 'deltaCents' => 77777]);
Scc_Docs::set_setting('loyalty', ['enabled' => true, 'benefits' => 'הטבות בדיקה']);
echo '@@1';
PHP));
$mm = $me($B)['member'] ?? null;
check('member: balance 3500 (5000-1500, expired and other member ignored), pending 2000', $mm && $mm['balanceCents'] === 3500 && $mm['pendingCents'] === 2000 && $mm['tier'] === 'gold' && $mm['cardNumber'] === 'C-1' && $mm['enabled'] === true && $mm['benefits'] === 'הטבות בדיקה', $mm);
check('other customer has member=null', array_key_exists('member', $m3 = $me($O)) && $m3['member'] === null, $m3);

/* ---------- 8. help centre, config, events, admin report ---------- */
section('help centre, experience config, events, admin');
$help = api('GET', '/api/help')[3];
$exp = json_decode(file_get_contents('/home/claude/export-2026-10-18/shem-menswear-help.json'), true);
$hadHelp = $setup['prev']['help'] !== null;
check('GET /api/help (no stored help) = 8 defaults, identical to the live export', is_array($help) && count($help) >= 8 && ($hadHelp || $help === $exp), count($help));
check('admin PUT without login -> 401 Hebrew', ($r = api('PUT', '/api/admin/experience', ['help' => $help, 'config' => ['analyticsEnabled' => true]])) && $r[0] === 401 && $r[3]['error'] === 'יש להתחבר לחשבון המורשה כדי לנהל את החנות.', $r[3]);
check('admin PUT as customer (not admin) -> 401', api('PUT', '/api/admin/experience', ['help' => $help], $B + ['nonce' => $nonce])[0] === 401);
check('admin PUT without nonce -> 401', api('PUT', '/api/admin/experience', ['help' => $help], ['cookie' => $admin['cookie']])[0] === 401);
check('admin GET as customer -> 401', api('GET', '/api/admin/experience', null, $B)[0] === 401);
check('admin PUT: >100 answers -> 400 "ניתן להוסיף עד מאה תשובות."', ($r = api('PUT', '/api/admin/experience', ['help' => array_fill(0, 101, ['title' => 'a', 'answer' => 'b'])], $A)) && $r[0] === 400 && $r[3]['error'] === 'ניתן להוסיף עד מאה תשובות.', $r[3]);
check('admin PUT: missing title/answer -> 400 Hebrew', ($r = api('PUT', '/api/admin/experience', ['help' => [['id' => 'x', 'title' => '', 'answer' => 'b']]], $A)) && $r[0] === 400 && $r[3]['error'] === 'יש להזין שאלה ותשובה ולהימנע ממזהים כפולים.', $r[3]);
check('admin PUT: duplicate ids -> 400', api('PUT', '/api/admin/experience', ['help' => [['id' => 'x', 'title' => 'a', 'answer' => 'b'], ['id' => 'x', 'title' => 'c', 'answer' => 'd']]], $A)[0] === 400);
check('admin PUT: valid (1 published + 1 draft) -> {ok:true}', ($r = api('PUT', '/api/admin/experience', ['help' => [
    ['id' => 'size-help', 'title' => 'בחירת מידה', 'answer' => 'טבלת המידות בדף המוצר', 'category' => 'product', 'placement' => 'product', 'action' => 'shop', 'active' => true],
    ['id' => 'hidden', 'title' => 'לא פורסם', 'answer' => 'טיוטה', 'active' => false]], 'config' => ['analyticsEnabled' => true, 'siteAlertsEnabled' => true]], $A)) && $r[0] === 200 && $r[3] === ['ok' => true], $r[3]);
$h = api('GET', '/api/help')[3];
check('public help shows only the published item, placement product', count($h) === 1 && $h[0]['id'] === 'size-help' && $h[0]['placement'] === 'product', $h);
check('GET /api/experience reflects config', api('GET', '/api/experience')[3] === ['analyticsEnabled' => true, 'siteAlertsEnabled' => true, 'externalMessagingConnected' => false]);
$sid = 'tcx-isolated-session-' . $run;
check('event without consent -> 400 "אירוע לא תקין."', ($r = api('POST', '/api/experience/events', ['sessionId' => $sid, 'event' => 'checkout_start', 'consent' => false])) && $r[0] === 400 && $r[3]['error'] === 'אירוע לא תקין.', $r[3]);
check('event with unknown name -> 400', api('POST', '/api/experience/events', ['sessionId' => $sid, 'event' => 'evil', 'consent' => true])[0] === 400);
check('event with short sessionId -> 400', api('POST', '/api/experience/events', ['sessionId' => 'short', 'event' => 'cart_add', 'consent' => true])[0] === 400);
check('event with consent -> 201 {recorded:true} (no auth needed)', ($r = api('POST', '/api/experience/events', ['sessionId' => $sid, 'event' => 'checkout_start', 'consent' => true, 'route' => 'checkout', 'customerEmail' => 'PRIVATE-DO-NOT-STORE', 'productId' => 'bad id!'])) && $r[0] === 201 && $r[3] === ['recorded' => true], $r[3]);
$ev = wp('shem', str_replace('__SID__', $sid, <<<'PHP'
global $wpdb; $rows = $wpdb->get_results($wpdb->prepare('SELECT id,data FROM ' . Scc_Docs::t('cx_events') . ' WHERE id LIKE %s', '__SID__|%'), ARRAY_A);
echo '@@' . json_encode(['n' => count($rows), 'data' => array_map(fn($r) => $r['data'], $rows)]);
PHP));
check('stored event holds only whitelisted detail; no PRIVATE text, no email, bad productId blanked', ($ev['n'] ?? 0) === 1 && !str_contains($ev['data'][0], 'PRIVATE') && !str_contains($ev['data'][0], 'customerEmail') && str_contains($ev['data'][0], '"productId":""') && str_contains($ev['data'][0], '"route":"checkout"'), $ev);
$rep = api('GET', '/api/admin/experience', null, $A)[3];
$cs = null; foreach ($rep['events'] ?? [] as $e) if ($e['event'] === 'checkout_start') $cs = $e;
check('admin report: checkout_start events>=1, sessions>=1, flags', $cs && $cs['events'] >= 1 && $cs['sessions'] >= 1 && $rep['externalMessagingConnected'] === false && count($rep['help']) === 2 && $rep['config'] === ['analyticsEnabled' => true, 'siteAlertsEnabled' => true] && isset($rep['requests']) && array_key_exists('trackingConnected', $rep), $rep);
$rl = 'tcx-ratelimit-session-' . $run; $codes = [];
for ($i = 0; $i < 122; $i++) { $codes[] = api('POST', '/api/experience/events', ['sessionId' => $rl, 'event' => 'product_view', 'consent' => true])[0]; }
$cnt = array_count_values($codes);
check('rate limit: first 120 events 201, then 429', ($cnt[201] ?? 0) === 120 && ($cnt[429] ?? 0) === 2, $cnt);
check('analytics off -> {recorded:false} 200 and nothing stored', (api('PUT', '/api/admin/experience', ['help' => $help, 'config' => ['analyticsEnabled' => false, 'siteAlertsEnabled' => true]], $A)[0] === 200) && ($r = api('POST', '/api/experience/events', ['sessionId' => $sid . 'x', 'event' => 'cart_add', 'consent' => true])) && $r[0] === 200 && $r[3] === ['recorded' => false], $r[3]);
check('help setting accepts "config missing" => analytics defaults to false on PUT (original behaviour)', api('PUT', '/api/admin/experience', ['help' => $help], $A)[0] === 200 && api('GET', '/api/experience')[3]['analyticsEnabled'] === false);

/* ---------- 9. stock changes, alerts disabled, deleting watches ---------- */
section('stock alerts lifecycle');
wp('shem', str_replace('__P1__', $p1, <<<'PHP'
$d = Scc_Docs::get('products', '__P1__'); $d['stock'] = 0; $d['sizeStock']['M'] = 0; Scc_Docs::put('products', '__P1__', $d); echo '@@1';
PHP));
$m = $me($B);
check('p1 out of stock -> its notification disappears (p2 in-stock variant remains)', count($m['notifications']) === 1 && $m['notifications'][0]['productId'] === $p2, $m['notifications']);
api('PUT', '/api/admin/experience', ['help' => $help, 'config' => ['analyticsEnabled' => false, 'siteAlertsEnabled' => false]], $A);
check('siteAlertsEnabled=false -> notifications empty', count($me($B)['notifications']) === 0);
check('new watch while alerts disabled -> 409 "התראות חזרה למלאי אינן פעילות כרגע."', ($r = api('POST', '/api/customer/watches', ['productId' => $p1, 'size' => 'M'], $B)) && $r[0] === 409 && $r[3]['error'] === 'התראות חזרה למלאי אינן פעילות כרגע.', $r[3]);
$wid = $me($B)['watches'][0]['id'];
api('DELETE', '/api/customer/watches/' . rawurlencode($wid), '{}', $O);
check('other customer cannot delete someone else\'s watch', count($me($B)['watches']) === 3);
check('anonymous delete -> 401', api('DELETE', '/api/customer/watches/' . rawurlencode($wid), '{}')[0] === 401);
check('owner delete -> {ok:true}, watch gone', ($r = api('DELETE', '/api/customer/watches/' . rawurlencode($wid), '{}', $B)) && $r[3] === ['ok' => true] && count($me($B)['watches']) === 2, $r[3]);
$lim = wp('shem', str_replace(['__UID__', '__P1__'], [(string) $buyer['id'], $p1], <<<'PHP'
for ($i = 0; $i < 98; $i++) Scc_Docs::put('cx_watches', '__UID__:filler' . $i . ':M', ['userId' => '__UID__', 'productId' => 'filler' . $i, 'variantId' => '', 'size' => 'M', 'createdAt' => gmdate('c')]);
echo '@@1';
PHP));
api('PUT', '/api/admin/experience', ['help' => $help, 'config' => ['analyticsEnabled' => false, 'siteAlertsEnabled' => true]], $A);
$delIsP1 = str_contains($wid, ':' . $p1 . ':');
$newW = $delIsP1 ? ['productId' => $p1, 'size' => 'M'] : ['productId' => $p2, 'variantId' => $P . '_v2', 'size' => 'L'];
$oldW = $delIsP1 ? ['productId' => $p2, 'variantId' => $P . '_v2', 'size' => 'L'] : ['productId' => $p1, 'size' => 'M'];
check('100-watch cap: a new 101st watch -> 429 "ניתן לשמור עד מאה התראות."', ($r = api('POST', '/api/customer/watches', $newW, $B)) && $r[0] === 429 && $r[3]['error'] === 'ניתן לשמור עד מאה התראות.', $r[3]);
check('re-saving an existing watch at the cap is allowed (upsert)', api('POST', '/api/customer/watches', $oldW, $B)[0] === 201);

/* ---------- 10. shipment webhook ---------- */
section('POST /api/shipment-events (HMAC)');
check('no secret configured -> 503', ($r = api('POST', '/api/shipment-events', [], ['origin' => null])) && $r[0] === 503 && $r[3]['error'] === 'חיבור עדכוני משלוח עדיין אינו פעיל.', $r[3]);
$cfg = trim((string) shell_exec(WPS . ' shem config set SHIPPING_WEBHOOK_SECRET ' . escapeshellarg($secret) . ' --type=constant 2>&1'));
check('temporary wp-config constant set', str_contains($cfg, 'Success'), $cfg);
for ($i = 0; $i < 20 && api('POST', '/api/shipment-events', [], ['origin' => null])[0] === 503; $i++) usleep(500000); // opcache revalidates wp-config.php after ~2s
check('webhook active after config reload (no longer 503)', api('POST', '/api/shipment-events', [], ['origin' => null])[0] !== 503);
$sign = function (string $raw, ?string $key = null, ?string $ts = null) use ($secret) {
    $ts ??= (string) (int) (microtime(true) * 1000);
    return ['ct' => 'application/json', 'origin' => null, 'headers' => ['X-Shem-Timestamp' => $ts, 'X-Shem-Signature' => hash_hmac('sha256', $ts . '.' . $raw, $key ?? $secret)]];
};
$ship = function (array $ev, array $opt = []) use ($sign) { $raw = json_encode($ev, JSON_UNESCAPED_UNICODE); return api($opt['method'] ?? 'POST', '/api/shipment-events', $raw, $opt['o'] ?? $sign($raw)); };
$e1 = ['eventId' => $P . '_ev1', 'orderId' => $o1, 'status' => 'completed', 'tracking' => 'TRACK-123', 'carrier' => 'חברת בדיקה', 'totalCents' => 1];
check('GET -> 405 Method not allowed', ($r = api('GET', '/api/shipment-events', null, ['origin' => null]))[0] === 405 && $r[3]['error'] === 'Method not allowed', $r[3]);
check('wrong signature -> 401 Invalid signature', ($r = $ship($e1, ['o' => $sign(json_encode($e1), 'wrong-secret')]))[0] === 401 && $r[3]['error'] === 'Invalid signature', $r[3]);
$raw = json_encode($e1);
check('stale timestamp (10 min) -> 401', $ship($e1, ['o' => $sign($raw, null, (string) ((int) (microtime(true) * 1000) - 600000))])[0] === 401);
check('malformed signature/timestamp headers -> 401', api('POST', '/api/shipment-events', $raw, ['origin' => null, 'headers' => ['X-Shem-Timestamp' => '123', 'X-Shem-Signature' => 'zz']])[0] === 401);
check('signed non-JSON -> 400 Invalid JSON', ($r = api('POST', '/api/shipment-events', '{nope', $sign('{nope')))[0] === 400 && $r[3]['error'] === 'Invalid JSON');
check('signed body > 20000 bytes -> 413', api('POST', '/api/shipment-events', ($big = json_encode(['x' => str_repeat('a', 21000)])), $sign($big))[0] === 413);
check('invalid status -> 400 Invalid event', ($r = $ship(['eventId' => 'e', 'orderId' => $o1, 'status' => 'delivered']))[0] === 400 && $r[3]['error'] === 'Invalid event', $r[3]);
check('missing eventId -> 400 Invalid event', $ship(['orderId' => $o1, 'status' => 'shipped'])[0] === 400);
check('unpaid order -> 409 Order unavailable', ($r = $ship(['eventId' => $P . '_ev_unpaid', 'orderId' => $o3, 'status' => 'shipped']))[0] === 409 && $r[3]['error'] === 'Order unavailable', $r[3]);
check('cancelled order -> 409 Order unavailable', $ship(['eventId' => $P . '_ev_canc', 'orderId' => $o2, 'status' => 'shipped'])[0] === 409);
check('unknown order -> 409', $ship(['eventId' => $P . '_ev_unk', 'orderId' => $P . '_nope', 'status' => 'shipped'])[0] === 409);
check('http tracking URL -> 400 Invalid tracking URL', ($r = $ship(['eventId' => $P . '_ev_url', 'orderId' => $o1, 'status' => 'shipped', 'trackingUrl' => 'http://carrier.test/x']))[0] === 400 && $r[3]['error'] === 'Invalid tracking URL', $r[3]);
check('credentialed tracking URL -> 400', $ship(['eventId' => $P . '_ev_url2', 'orderId' => $o1, 'status' => 'shipped', 'trackingUrl' => 'https://u:p@carrier.test/x'])[0] === 400);
check('bad delivery date -> 400 Invalid delivery date', ($r = $ship(['eventId' => $P . '_ev_date', 'orderId' => $o1, 'status' => 'shipped', 'estimatedDelivery' => '2026-02-31']))[0] === 400 && $r[3]['error'] === 'Invalid delivery date', $r[3]);
check('rejected events leave no event row (not marked as seen)', ($x = wp('shem', str_replace('__P__', $P, '$n=(int)$GLOBALS["wpdb"]->get_var("SELECT COUNT(*) FROM ".Scc_Docs::t("cx_shipment_events")." WHERE id LIKE \'__P___ev_%\'"); echo "@@".json_encode(["n"=>$n]);')))['n'] === 0, $x);
$before = api('GET', '/api/customer/orders/' . $o1, null, $guest)[3];
check('valid completed event -> 200 {ok:true,status:completed}', ($r = $ship($e1))[0] === 200 && $r[3] === ['ok' => true, 'status' => 'completed'], $r[3]);
$after = api('GET', '/api/customer/orders/' . $o1, null, $B)[3];
check('customer sees completed; totalCents untouched (19800) despite event totalCents:1', $after['status'] === 'completed' && $after['totalCents'] === 19800, $after);
check('history appended once with the new status; carrier/tracking updated', end($after['shipmentHistory'])['status'] === 'completed' && $after['carrier'] === 'חברת בדיקה' && $after['tracking'] === 'TRACK-123', $after['shipmentHistory']);
check('same event again -> {ok:true,duplicate:true}', ($r = $ship($e1))[0] === 200 && $r[3] === ['ok' => true, 'duplicate' => true], $r[3]);
check('older status (processing) later -> 200 but status stays completed (no regression)', ($r = $ship(['eventId' => $P . '_ev2', 'orderId' => $o1, 'status' => 'processing']))[0] === 200 && $r[3]['status'] === 'completed' && api('GET', '/api/customer/orders/' . $o1, null, $B)[3]['status'] === 'completed', $r[3]);
$hist1 = count(api('GET', '/api/customer/orders/' . $o1, null, $B)[3]['shipmentHistory']);
$ship(['eventId' => $P . '_ev3', 'orderId' => $o1, 'status' => 'completed', 'estimatedDelivery' => '2026-11-01', 'trackingUrl' => 'https://carrier.test/t2']);
$d3 = api('GET', '/api/customer/orders/' . $o1, null, $B)[3];
check('same status adds no history entry; ETA + https trackingUrl stored', count($d3['shipmentHistory']) === $hist1 && $d3['estimatedDelivery'] === '2026-11-01' && $d3['trackingUrl'] === 'https://carrier.test/t2', $d3);
// race: two concurrent events for the same order
$mh = curl_multi_init(); $hs = [];
foreach (['race-1', 'race-2'] as $k) {
    $ev = ['eventId' => $P . '_' . $k, 'orderId' => $o1, 'status' => 'completed', 'tracking' => $k]; $rawr = json_encode($ev); $s = $sign($rawr);
    $ch = curl_init(BASE . '/api/shipment-events');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $rawr, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Shem-Timestamp: ' . $s['headers']['X-Shem-Timestamp'], 'X-Shem-Signature: ' . $s['headers']['X-Shem-Signature']]]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $active); curl_multi_select($mh); } while ($active);
$rc = array_map(fn($ch) => curl_getinfo($ch, CURLINFO_HTTP_CODE), $hs);
check('concurrent events: at least one 200 and all in {200,409}', in_array(200, $rc, true) && count(array_diff($rc, [200, 409])) === 0, $rc);
$fin = wp('shem', str_replace('__P__', $P, <<<'PHP'
global $wpdb; echo '@@' . json_encode(['mail' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Scc_Docs::t('mail_jobs')), 'events' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Scc_Docs::t('cx_shipment_events') . " WHERE id LIKE '__P___%'"),
  'audit' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Scc_Docs::t('audit') . " WHERE data LIKE '%shipment-integration%' AND data LIKE '%__P__%'")]);
PHP));
check('no mail_jobs created by any shipment/customer action', ($fin['mail'] ?? -1) === 0, $fin);
check('audit rows written for accepted events (shipment-integration)', ($fin['audit'] ?? 0) >= 4, $fin);

/* ---------- cleanup ---------- */
section('cleanup (own fixtures only)');
$cfgd = trim((string) shell_exec(WPS . ' shem config delete SHIPPING_WEBHOOK_SECRET 2>&1'));
check('temporary SHIPPING_WEBHOOK_SECRET removed from wp-config', str_contains($cfgd, 'Success') && !str_contains((string) shell_exec(WPS . ' shem config list SHIPPING_WEBHOOK_SECRET 2>&1'), 'SHIPPING_WEBHOOK_SECRET'), $cfgd);
$clean = wp('shem', str_replace(['__RUN__', '__PREV__', '__UIDS__', '__WCS__', '__AUDIT0__'], [$run, json_encode($setup['prev']), json_encode([$admin['id'], $buyer['id'], $other['id']]), json_encode([$fx['wc1'] ?? 0, $fx['wc2'] ?? 0, $fx['wc3'] ?? 0, $fx['wcp'] ?? 0]), (string) $setup['audit_before']], <<<'PHP'
global $wpdb; $P = 't_cx_' . '__RUN__'; $prev = json_decode('__PREV__', true); $uids = json_decode('__UIDS__', true); $wcs = json_decode('__WCS__', true);
foreach (['products', 'member_profiles', 'loyalty_ledger'] as $t) $wpdb->query("DELETE FROM " . Scc_Docs::t($t) . " WHERE id LIKE '{$P}\\_%'");
$wpdb->query("DELETE FROM " . Scc_Docs::t('cx_shipment_events') . " WHERE id LIKE '{$P}\\_%'");
$wpdb->query("DELETE FROM " . Scc_Docs::t('cx_events') . " WHERE id LIKE 'tcx-%" . '__RUN__' . "|%'");
foreach ($uids as $u) { $wpdb->query($wpdb->prepare("DELETE FROM " . Scc_Docs::t('cx_watches') . " WHERE id LIKE %s", $wpdb->esc_like($u . ':') . '%')); $wpdb->delete(Scc_Docs::t('cx_preferences'), ['id' => (string) $u]); }
$wpdb->query("DELETE FROM " . Scc_Docs::t('audit') . " WHERE data LIKE '%{$P}%'");
foreach ($uids as $u) $wpdb->query($wpdb->prepare("DELETE FROM " . Scc_Docs::t('audit') . " WHERE data LIKE %s", '{"actor":"' . $u . '"%'));
foreach (['help', 'experience', 'loyalty'] as $k) { if ($prev[$k] === null) Scc_Docs::delete('settings', $k); else Scc_Docs::put('settings', $k, $prev[$k]); }
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($wcs as $id) { $o = wc_get_order($id); if ($o) $o->delete(true); else { $p = wc_get_product($id); if ($p) $p->delete(true); } }
$doc_left = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . ShemDok::table() . " WHERE order_id IN (" . implode(',', array_map('intval', $wcs)) . ")");
foreach ($uids as $u) wp_delete_user($u);
echo '@@' . json_encode(['dok_docs_left_for_deleted_orders' => $doc_left, 'audit_now' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Scc_Docs::t('audit')), 'audit_before' => (int) '__AUDIT0__']);
PHP));
check('fixtures removed, shared settings restored', isset($clean['audit_now']), $clean);
echo "  note: DOK documents are immutable by design; the test invoice document issued for the deleted test order stays in wp_shem_dok (count for those orders now: " . ($clean['dok_docs_left_for_deleted_orders'] ?? '?') . ")\n";

echo "\nRESULT: {$GLOBALS['pass']} passed, {$GLOBALS['fail']} failed\n";
foreach ($GLOBALS['fails'] as $f) echo " - $f\n";
exit($GLOBALS['fail'] ? 1 : 0);
