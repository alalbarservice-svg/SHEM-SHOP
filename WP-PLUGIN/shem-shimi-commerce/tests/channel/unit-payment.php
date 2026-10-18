<?php
/** Channel tests: SHEM->SHIMI embedded payment protocol (shem-home-v1) and the generic provider adapter, with a STUBBED adapter. No provider, no charge, no mail. */
require __DIR__ . '/harness.php';
use_docs(); cleanup();
$P = Scc_Channel::peer_url();
$orig = ['gateway' => Scc_Docs::get('settings', 'gateway'), 'operations' => Scc_Docs::get('settings', 'operations')];   // backed up, restored in finally
$restore = function () use ($orig) { foreach ($orig as $k => $v) { if ($v === null) Scc_Docs::delete('settings', $k); else Scc_Docs::put('settings', $k, $v); } };
try {
$hs = Scc_Channel_Payment::home_secret();
ok('home payment secret is configured on staging (value never printed)', $hs !== '');
$hsign = fn(string $raw, ?int $ts = null) => (function () use ($raw, $ts, $hs) { $t = (string) ($ts ?? Scc_Channel::ts()); return ['x-shem-timestamp' => $t, 'x-shem-signature' => Scc_Channel::sign($hs, $t . '.' . $raw)]; })();
$run = function (callable $fn, Scc_Request $r) { try { $x = Scc_Channel::wrap($fn)($r); return [$x->status, is_array($x->body) ? $x->body : (string) $x->body]; } catch (Throwable $e) { return [500, $e->getMessage()]; } };

section('16. disabled-by-default provider adapter (no constants set)');
ok('adapter not ready without constants', Scc_Channel_Payment::adapter_ready() === false);
Scc_Docs::put('settings', 'gateway', ['value' => []]);
throws('no collector name -> blocked', fn() => Scc_Channel_Payment::provider_session(['id' => 'x', 'amountCents' => 100, 'customer' => []], 'http://x'), 'יש להגדיר את שם העסק שגובה את התשלום.', 503);
Scc_Docs::put('settings', 'gateway', ['value' => ['collectorName' => 'T Collector']]);
throws('collector set but no adapter -> no charge', fn() => Scc_Channel_Payment::provider_session(['id' => 'x', 'amountCents' => 100, 'customer' => []], 'http://x'), 'ספק סליקה טרם חובר ונבדק. לא בוצע חיוב.', 503);
$raw = Scc_Channel::json(['orderId' => 'shem:x', 'currency' => 'ILS', 'amountCents' => 100, 'transactionId' => 'tx', 'status' => 'paid']);
[$st, $b] = as_home(true, fn() => $run(['Scc_Channel_Payment', 'provider_callback'], mkreq('POST', '/api/channel/provider-callback', $raw, $hsign($raw))));
eq('provider callback without an adapter secret -> 503', [$st, $b['error'] ?? ''], [503, 'חיבור בין החנויות טרם הוגדר.']);
$bodyc = Scc_Channel::json(['termsAccepted' => true, 'items' => [['id' => 't-channel-pm', 'qty' => 1, 'size' => 'M', 'variantId' => '']], 'shipping' => 'standard']);
mkp('t-channel-pm', ['sku' => 'PM-1', 'price' => 100, 'stock' => 5, 'sizeStock' => ['M' => 5]]);
add_filter('scc_channel_quote', $qf = fn($q, $p) => $q ?? ['items' => [['id' => 't-channel-pm', 'variantId' => '', 'size' => 'M', 'qty' => 1, 'price' => 100]], 'subtotal' => 100, 'discount' => 0, 'shipping' => 29, 'vatIncluded' => 19.7, 'netSubtotal' => 109.3, 'total' => 129], 10, 2);
$origin = Scc_Channel::origin_of(home_url());
[$st, $b] = as_home(true, fn() => $run(['Scc_Channel_Payment', 'own_checkout'], mkreq('POST', '/api/shimi/checkout', $bodyc, ['origin' => $origin, 'content-type' => 'application/json'])));
eq('own checkout stays closed until a provider is connected', [$st, $b['error'] ?? ''], [503, 'הרכישה עדיין אינה פעילה. ספק סליקה טרם חובר.']);
[$st, $b] = as_home(false, fn() => $run(['Scc_Channel_Payment', 'own_checkout'], mkreq('POST', '/api/shimi/checkout', $bodyc, ['origin' => $origin, 'content-type' => 'application/json'])));
eq('own checkout does not exist on SHEM', $st, 404);
[$st, $b] = as_home(true, fn() => $run(['Scc_Channel_Payment', 'own_checkout'], mkreq('POST', '/api/shimi/checkout', $bodyc, ['origin' => 'https://evil.test', 'content-type' => 'application/json'])));
eq('own checkout rejects a foreign Origin', [$st, $b['error'] ?? ''], [403, 'Origin rejected']);
remove_filter('scc_channel_quote', $qf, 10);

section('17. accept_shem: SHEM asks SHIMI for an embedded session (shem-home-v1 HMAC)');
mkp('t-channel-ps', ['sku' => 'PS-1', 'price' => 100, 'stock' => 5, 'sizeStock' => ['M' => 5]]);
mkp('t-channel-ph', ['sku' => 'PS-1', 'price' => 100, 'stock' => 0, 'sizeStock' => ['M' => 0], 'brand' => 'SHIMI']);
as_home(true, fn() => Scc_Channel_Links::link('t-channel-ph', ['sourceUrl' => $P . '/api/channel/source/t-channel-ps', 'variantMap' => []]));
$items = [['productId' => 't-channel-ps', 'variantId' => '', 'name' => 'item', 'sku' => 'PS-1', 'size' => 'M', 'qty' => 1, 'priceCents' => 10000, 'lineCents' => 10000]];
$customer = ['name' => 'T', 'email' => 't@example.com', 'phone' => '050', 'city' => 'TLV', 'street' => 'X 1'];
$snap = ['items' => $items, 'customer' => $customer, 'amountCents' => 12900, 'shippingCents' => 2900, 'discountCents' => 0];
add_filter('scc_channel_order_snapshot', $sf = fn($o, $id) => $id === 't-channel-ord' ? $snap : $o, 10, 2);
$req = ['protocol' => 'shem-home-v1', 'orderId' => 't-channel-ord', 'currency' => 'ILS', 'amountCents' => 12900, 'shippingCents' => 2900, 'discountCents' => 0, 'items' => $items, 'customer' => $customer, 'callbackUrl' => $P . '/api/home/payment-callback'];
$acc = fn(array $d, ?int $ts = null) => as_home(true, function () use ($d, $run, $hsign, $ts) { $raw = Scc_Channel::json($d); return $run(['Scc_Channel_Payment', 'accept_shem'], mkreq('POST', '/api/channel/checkout', $raw, $hsign($raw, $ts))); });
[$st, $b] = $acc($req, Scc_Channel::ts() - 400); eq('stale timestamp', [$st, $b['error'] ?? ''], [401, 'תוקף חתימה פג.']);
$raw = Scc_Channel::json($req); $h = $hsign($raw); $h['x-shem-signature'] = str_repeat('a', 64);
[$st, $b] = as_home(true, fn() => $run(['Scc_Channel_Payment', 'accept_shem'], mkreq('POST', '/api/channel/checkout', $raw, $h))); eq('bad signature', [$st, $b['error'] ?? ''], [401, 'חתימה שגויה.']);
[$st, $b] = $acc(array_merge($req, ['protocol' => 'other'])); eq('wrong protocol', [$st, $b['error'] ?? ''], [409, 'בקשת תשלום אינה תקינה.']);
[$st, $b] = $acc(array_merge($req, ['amountCents' => 100])); eq('amount differs from the SHEM order', [$st, $b['error'] ?? ''], [409, 'הסכום אינו תואם להזמנת SHEM.']);
[$st, $b] = $acc(array_merge($req, ['customer' => ['name' => 'Other'] + $customer])); eq('customer differs from the SHEM order', [$st, $b['error'] ?? ''], [409, 'פרטי ההזמנה אינם תואמים.']);
[$st, $b] = $acc(array_merge($req, ['callbackUrl' => 'https://evil.test/api/home/payment-callback'])); eq('callback must be SHEM', [$st, $b['error'] ?? ''], [409, 'כתובת אישור אינה מאושרת.']);
[$st, $b] = $acc($req); eq('valid request but no provider -> 503, no charge', [$st, $b['error'] ?? ''], [503, 'ספק סליקה טרם חובר ונבדק. לא בוצע חיוב.']);
eq('failed creation recorded on the session', Scc_Docs::get('payment_sessions', 'shem:t-channel-ord')['status'] ?? '', 'creation-failed');
Scc_Docs::delete('payment_sessions', 'shem:t-channel-ord');

section('18. stubbed provider adapter: session, callback, SHEM notification, reconcile');
$adapter_secret = bin2hex(random_bytes(16));
define('SCC_PAYMENT_ADAPTER_ENABLED', true); define('SCC_PAYMENT_ADAPTER_URL', 'https://adapter.invalid/session'); define('SCC_PAYMENT_ADAPTER_SECRET', $adapter_secret); define('SCC_PAYMENT_CHECKOUT_ORIGIN', 'https://pay.invalid');
ok('adapter ready only with all constants + enabled flag', Scc_Channel_Payment::adapter_ready() === true);
$http = []; $adapter_mode = 'ok'; $callback_mode = 'ok';
add_filter('pre_http_request', function ($pre, $args, $url) use (&$http, &$adapter_mode, &$callback_mode, $adapter_secret, $hs) {
    if (strpos($url, 'https://adapter.invalid/') === 0) {
        $h = array_change_key_case((array) $args['headers']); $http[] = 'adapter';
        $expect = Scc_Channel::sign($adapter_secret, ($h['x-shem-timestamp'] ?? '') . '.' . $args['body']);
        if (!hash_equals($expect, (string) ($h['x-shem-signature'] ?? ''))) return ['headers' => [], 'body' => '{}', 'response' => ['code' => 401, 'message' => '']];
        if ($adapter_mode === 'bad-origin') return ['headers' => [], 'body' => '{"checkoutUrl":"https://evil.invalid/c"}', 'response' => ['code' => 200, 'message' => '']];
        if ($adapter_mode === 'down') return ['headers' => [], 'body' => '', 'response' => ['code' => 500, 'message' => '']];
        return ['headers' => [], 'body' => '{"checkoutUrl":"https://pay.invalid/c/abc"}', 'response' => ['code' => 200, 'message' => '']];
    }
    if (preg_match('#/api/home/payment-callback$#', $url)) {
        $h = array_change_key_case((array) $args['headers']); $http[] = 'callback';
        $expect = Scc_Channel::sign($hs, ($h['x-shem-timestamp'] ?? '') . '.' . $args['body']);
        if (!hash_equals($expect, (string) ($h['x-shem-signature'] ?? ''))) { return ['headers' => [], 'body' => '{}', 'response' => ['code' => 401, 'message' => '']]; }
        return ['headers' => [], 'body' => '{"ok":true}', 'response' => ['code' => $callback_mode === 'ok' ? 200 : 500, 'message' => '']];
    }
    return $pre;
}, 5, 3);
$adapter_mode = 'bad-origin'; [$st, $b] = $acc($req); eq('checkout URL outside the approved origin is refused', [$st, $b['error'] ?? ''], [502, 'כתובת התשלום אינה מאושרת.']);
$adapter_mode = 'down'; Scc_Docs::delete('payment_sessions', 'shem:t-channel-ord'); [$st, $b] = $acc($req); eq('adapter error -> 502 and no session left open', [$st, $b['error'] ?? ''], [502, 'ספק הסליקה לא אישר פתיחת תשלום.']);
$adapter_mode = 'ok'; Scc_Docs::delete('payment_sessions', 'shem:t-channel-ord'); $http = [];
[$st, $b] = $acc($req); eq('embedded session created', $st, 201); ok('embed URL points at SHIMI /payment/<id>?token=', isset($b['embedUrl']) && preg_match('#/payment/shem%3At-channel-ord\?token=[a-f0-9]{64}$#', $b['embedUrl']) === 1, $b);
$calls = count($http);
[$st, $b2] = $acc($req); eq('same request again is idempotent (200, same URL, no second adapter call)', [$st, $b2['embedUrl'] ?? '', count($http)], [200, $b['embedUrl'] ?? '', $calls]);
[$st, $b3] = $acc(array_merge($req, ['shippingCents' => 2900, 'amountCents' => 12900, 'items' => [array_merge($items[0], ['name' => 'changed'])]])); ok('same order id with a different body is refused', $st >= 400, [$st, $b3]);
$s = Scc_Docs::get('payment_sessions', 'shem:t-channel-ord'); eq('session pending with the checkout URL', [$s['status'], $s['checkoutUrl']], ['pending', 'https://pay.invalid/c/abc']);
// page
$tokq = ['token' => $s['token']];
$pg = as_home(true, fn() => Scc_Channel_Payment::page((function () use ($tokq) { $r = mkreq('GET', '/payment/shem%3At-channel-ord', '', [], $tokq); $r->params = ['', 'shem:t-channel-ord']; return $r; })()));
eq('payment page renders for the right token', $pg->status, 200); ok('page has the iframe + CSP frame-src pinned to the provider origin', strpos((string) $pg->body, '<iframe') !== false && strpos(json_encode($pg->headers, JSON_UNESCAPED_SLASHES), 'frame-src https://pay.invalid') !== false);
$pg = as_home(true, fn() => Scc_Channel_Payment::page((function () { $r = mkreq('GET', '/payment/x', '', [], ['token' => 'nope']); $r->params = ['', 'shem:t-channel-ord']; return $r; })()));
eq('wrong token -> 404', $pg->status, 404);
// callback
$ev = ['orderId' => 'shem:t-channel-ord', 'currency' => 'ILS', 'amountCents' => 12900, 'transactionId' => 'tx-T-1', 'status' => 'paid', 'paymentDetails' => ['method' => 'card', 'last4' => '4242', 'cvv' => '123', 'pan' => '4111111111111111']];
$asign = fn(string $raw, ?int $ts = null) => (function () use ($raw, $ts, $adapter_secret) { $t = (string) ($ts ?? Scc_Channel::ts()); return ['x-shem-timestamp' => $t, 'x-shem-signature' => Scc_Channel::sign($adapter_secret, $t . '.' . $raw)]; })();
$cb = fn(array $e, ?array $hdr = null) => as_home(true, function () use ($e, $hdr, $run, $asign) { $raw = Scc_Channel::json($e); return $run(['Scc_Channel_Payment', 'provider_callback'], mkreq('POST', '/api/channel/provider-callback', $raw, $hdr ?? $asign($raw))); });
[$st, $b] = $cb($ev, ['x-shem-timestamp' => (string) Scc_Channel::ts(), 'x-shem-signature' => str_repeat('0', 64)]); eq('callback with a bad signature', [$st, $b['error'] ?? ''], [401, 'חתימה שגויה.']);
[$st, $b] = $cb(array_merge($ev, ['amountCents' => 100])); eq('callback with another amount', [$st, $b['error'] ?? ''], [409, 'אישור תשלום אינו תואם.']);
[$st, $b] = $cb(array_merge($ev, ['status' => 'failed'])); eq('callback that is not paid', [$st, $b['error'] ?? ''], [409, 'אישור תשלום אינו תואם.']);
[$st, $b] = $cb(array_merge($ev, ['orderId' => 'shem:unknown'])); eq('callback for an unknown session', [$st, $b['error'] ?? ''], [409, 'אישור תשלום אינו תואם.']);
$http = []; $callback_mode = 'fail';
[$st, $b] = $cb($ev); eq('SHEM unreachable: payment kept, retry demanded', [$st, $b['error'] ?? ''], [409, 'התשלום אושר אך העדכון ל-SHEM דורש ניסיון חוזר.']);
$s = Scc_Docs::get('payment_sessions', 'shem:t-channel-ord'); eq('session parked as paid-awaiting-sync with the reference', [$s['status'], $s['paymentReference']], ['paid-awaiting-sync', 'tx-T-1']);
ok('card number and CVV are never stored (allow-listed fields only)', !isset($s['paymentDetails']['pan']) && !isset($s['paymentDetails']['cvv']) && ($s['paymentDetails']['last4'] ?? '') === '4242', array_keys($s['paymentDetails']));
$callback_mode = 'ok';
try { $rec = as_home(true, fn() => Scc_Channel_Payment::reconcile(['id' => 'shem:t-channel-ord'])); } catch (Throwable $e) { $rec = 'EXC ' . $e->getMessage() . ' | ' . wp_json_encode(array_intersect_key(Scc_Docs::get('payment_sessions', 'shem:t-channel-ord'), array_flip(['status', 'error', 'callbackUrl'])), JSON_UNESCAPED_UNICODE); }
eq('reconcile re-notifies SHEM', $rec, ['ok' => true]);
eq('session is paid', Scc_Docs::get('payment_sessions', 'shem:t-channel-ord')['status'], 'paid');
throws('nothing left to reconcile', fn() => as_home(true, fn() => Scc_Channel_Payment::reconcile(['id' => 'shem:t-channel-ord'])), 'אין תשלום מאושר הממתין לסנכרון.', 409);
[$st, $b] = $cb($ev); eq('provider retries the same callback: accepted again (idempotent)', [$st, $b], [200, ['ok' => true]]);
[$st, $b] = $cb(array_merge($ev, ['transactionId' => 'tx-OTHER'])); eq('a different transaction id on a paid session is refused', [$st, $b['error'] ?? ''], [409, 'אסמכתת תשלום אינה תואמת.']);
notrun('real payment provider, 3DS and iframe behaviour in a browser (no provider is connected - by design)');
remove_filter('scc_channel_order_snapshot', $sf, 10);
} finally { $restore(); }
cleanup(); finish();
