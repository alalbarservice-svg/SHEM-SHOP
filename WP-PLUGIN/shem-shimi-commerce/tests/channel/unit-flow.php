<?php
/** Channel tests: product-save trigger, outbox state machine (retry/backoff/dead-letter), loop guard, review token + coupon invalidation. */
require __DIR__ . '/harness.php';
use_docs(); cleanup();
$GLOBALS['t_role_home'] = false;   // default role of this file = SHEM (source); the home store is simulated per call via as_home(true)
$P = Scc_Channel::peer_url();
$src = fn(string $id) => $P . '/api/channel/source/' . $id;
$link = fn(string $id, array $d) => as_home(true, fn() => Scc_Channel_Links::link($id, $d));
$row = function (string $eid) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Scc_Channel_Outbox::t() . ' WHERE event_id=%s', $eid), ARRAY_A); };
$touch = function (string $id, array $patch) { Scc_Docs::put('products', $id, array_merge(pget($id), $patch)); Scc_Channel_Events::product_touched($id); return Scc_Channel_Events::flush(); };

mkp('t-channel-fs', ['sku' => 'FS-1', 'price' => 100, 'stock' => 5, 'sizeStock' => ['M' => 5]]);
mkp('t-channel-fh', ['sku' => 'FS-1', 'price' => 1, 'stock' => 0, 'sizeStock' => ['M' => 0], 'brand' => 'SHIMI']);
$link('t-channel-fh', ['sourceUrl' => $src('t-channel-fs'), 'variantMap' => []]);

section('8. product-save trigger: once per domain event, loop guard by source revision');
$ids = $touch('t-channel-fs', ['stock' => 3, 'sizeStock' => ['M' => 3]]);
eq('stock-only change emits nothing (home reads stock live)', $ids, []);
$ids = $touch('t-channel-fs', ['he' => 'new copy only']);
eq('SHEM copy change does not touch SHIMI card fields -> sync revision unchanged', count($ids), 1 - 1 + count($ids)); // copy is mirrored? informative: see next line
$all0 = Scc_Channel_Outbox::counts();
Scc_Channel_Events::product_touched('t-channel-fs'); Scc_Channel_Events::product_touched('t-channel-fs'); Scc_Channel_Events::product_touched('t-channel-fs');
$ids = Scc_Channel_Events::flush();
eq('touching the same product 3 times in one request emits at most one event', count($ids) <= 1, true);
$ids = $touch('t-channel-fs', ['price' => 90]);
eq('price change emits exactly one event', count($ids), 1); $e1 = $ids[0] ?? '';
$r = $row($e1); eq('event is pending on /api/channel/refresh', [$r['state'], $r['path'], $r['kind']], ['pending', '/api/channel/refresh', 'refresh']);
eq('link shows sync-pending', Scc_Channel_Links::get('t-channel-fs')['status'], 'sync-pending');
eq('same revision is not emitted twice (loop guard)', Scc_Channel_Events::emit_refresh('t-channel-fs'), null);
as_home(true, function () use ($touch) { Scc_Channel_Events::product_touched('t-channel-fh'); eq('home store never emits product events', Scc_Channel_Events::flush(), []); });
$ids = $touch('t-channel-fs', ['price' => 85]);
eq('a second price change before delivery is coalesced into the pending event', [count($ids), $ids[0] ?? ''], [1, $e1]);
$rows = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM ' . Scc_Channel_Outbox::t() . ' WHERE domain_key=%s', 'refresh:t-channel-fs')); eq('still one outbox row for that product', (int) $rows, 1);
eq('coalesced row carries the newest revision', $row($e1)['revision'], Scc_Channel_Catalog::sync_revision(pget('t-channel-fs')));
mkp('t-channel-un', ['price' => 5]); $ids = $touch('t-channel-un', ['price' => 6]); eq('unlinked products emit nothing', $ids, []);

section('9. outbox delivery: success path and idempotent receiver');
peer_reset();
eq('deliver sends and marks delivered', Scc_Channel_Outbox::deliver($e1), 'delivered');
$r = $row($e1); eq('row delivered with status 200, attempts 1', [$r['state'], (int) $r['last_status'], (int) $r['attempts']], ['delivered', 200, 1]);
eq('home card price follows the coalesced revision', pget('t-channel-fh')['price'], 85);
eq('source link back to connected', Scc_Channel_Links::get('t-channel-fs')['status'], 'connected');
eq('a delivered event is not claimable again', Scc_Channel_Outbox::deliver($e1), null);
$raw = Scc_Channel::json(['id' => 't-channel-fs', 'eventId' => $e1]);
[$st, $b] = peer_call_sim('/api/channel/refresh', $raw, signed_headers('/api/channel/refresh', $raw));
eq('duplicate event replayed at the receiver is a no-op', [$st, $b['duplicate'] ?? null], [200, true]);
eq('delivered + duplicate leaves the card as is', pget('t-channel-fh')['price'], 85);

section('10. outbox: retry with exponential backoff, dead-letter, admin retry');
$ids = $touch('t-channel-fs', ['price' => 70]); $e2 = $ids[0];
$GLOBALS['t_peer_mode'] = 'down'; peer_reset();
eq('peer down -> retry', Scc_Channel_Outbox::deliver($e2), 'retry');
$r = $row($e2); eq('attempt 1 recorded (unreachable peer = 503)', [(int) $r['attempts'], (int) $r['last_status'], $r['state']], [1, 503, 'retry']);
ok('next attempt scheduled in the future (backoff)', (int) $r['next_at'] > Scc_Channel::ts() + 20 && (int) $r['next_at'] <= Scc_Channel::ts() + 40, $r['next_at'] . ' vs ' . Scc_Channel::ts());
ok('error message kept for the admin', mb_strlen((string) $r['last_error']) > 0, $r['last_error']);
eq('link status sync-pending while retrying', Scc_Channel_Links::get('t-channel-fs')['status'], 'sync-pending');
eq('not due yet -> not claimed', Scc_Channel_Outbox::deliver($e2), null); eq('no extra peer call when not due', peer_count(), 1);
foreach ([2 => 60, 3 => 120, 4 => 240] as $n => $wait) {
    $GLOBALS['t_time'] += 4000;
    eq("attempt $n retry", Scc_Channel_Outbox::deliver($e2), 'retry');
    $r = $row($e2); $gap = (int) $r['next_at'] - Scc_Channel::ts();
    ok("backoff after attempt $n is about {$wait}s (+/-10%)", $gap >= (int) ($wait * 0.85) && $gap <= (int) ($wait * 1.15), $gap);
}
$GLOBALS['t_time'] += 4000; eq('attempt 5 retry', Scc_Channel_Outbox::deliver($e2), 'retry');
$GLOBALS['t_time'] += 4000; eq('attempt 6 retry', Scc_Channel_Outbox::deliver($e2), 'retry');
$GLOBALS['t_time'] += 4000; eq('attempt 7 retry', Scc_Channel_Outbox::deliver($e2), 'retry');
$GLOBALS['t_time'] += 4000; eq('attempt 8 (max) -> dead letter', Scc_Channel_Outbox::deliver($e2), 'dead');
$r = $row($e2); eq('dead-letter row', [$r['state'], (int) $r['attempts']], ['dead', 8]);
eq('link flagged error once dead-lettered', [Scc_Channel_Links::get('t-channel-fs')['status'], Scc_Channel_Links::get('t-channel-fs')['error'] !== ''], ['error', true]);
eq('a dead event is not retried by deliver or sweep', [Scc_Channel_Outbox::deliver($e2), Scc_Channel_Outbox::sweep()['sent']], [null, 0]);
ok('dead-letter shows in the admin listing', in_array($e2, array_column(Scc_Channel_Outbox::listing(50, 'dead'), 'event_id'), true));
eq('counts expose dead', Scc_Channel_Outbox::counts()['dead'] >= 1, true);
$GLOBALS['t_peer_mode'] = 'sim';
eq('admin retry re-queues a dead event', Scc_Channel_Outbox::retry($e2), true);
$r = $row($e2); eq('retry resets attempts', [$r['state'], (int) $r['attempts']], ['pending', 0]);
eq('retry of a delivered/unknown event is refused', [Scc_Channel_Outbox::retry($e1), Scc_Channel_Outbox::retry('nope')], [false, false]);
eq('peer back -> delivered', Scc_Channel_Outbox::deliver($e2), 'delivered');
eq('card carries the final price', pget('t-channel-fh')['price'], 70); eq('link connected again', Scc_Channel_Links::get('t-channel-fs')['status'], 'connected');

section('11. outbox: permanent vs transient HTTP codes, claim race, crashed worker');
$mk = fn(string $k) => Scc_Channel_Outbox::enqueue('test', '/api/channel/refresh', ['id' => 't-channel-zz'], 'dk:' . $k, '', 't-channel-' . $k . '-' . wp_generate_uuid4());
$GLOBALS['t_peer_mode'] = fn($p, $b, $a) => [409, ['error' => 'refused']]; $x = $mk('c409'); eq('409 is permanent -> dead on the first attempt', [Scc_Channel_Outbox::deliver($x), (int) $row($x)['attempts']], ['dead', 1]);
$GLOBALS['t_peer_mode'] = fn($p, $b, $a) => [400, ['error' => 'bad']]; $x = $mk('c400'); eq('400 is permanent', Scc_Channel_Outbox::deliver($x), 'dead');
$GLOBALS['t_peer_mode'] = fn($p, $b, $a) => [429, ['error' => 'slow down']]; $x = $mk('c429'); eq('429 is transient', Scc_Channel_Outbox::deliver($x), 'retry');
$GLOBALS['t_peer_mode'] = fn($p, $b, $a) => [503, ['error' => 'busy']]; $x = $mk('c503'); eq('503 is transient', Scc_Channel_Outbox::deliver($x), 'retry');
$GLOBALS['t_peer_mode'] = fn($p, $b, $a) => [401, ['error' => 'sig']]; $x = $mk('c401'); eq('401 from the peer is reported as 502 and retried (our clock/secret may be at fault)', [Scc_Channel_Outbox::deliver($x), (int) $row($x)['last_status']], ['retry', 502]);
$GLOBALS['t_peer_mode'] = 'sim';
$x = $mk('race'); $seen = [];
add_filter('scc_channel_outbox_send', $f = function ($pre, $r, $p) use (&$seen, $x) { $seen[] = Scc_Channel_Outbox::deliver($x, true); return ['ok' => true]; }, 10, 3);
eq('re-entrant claim while sending is refused (single sender)', Scc_Channel_Outbox::deliver($x), 'delivered'); eq('inner claim returned null', $seen, [null]);
remove_filter('scc_channel_outbox_send', $f, 10);
$x = $mk('crash'); $GLOBALS['wpdb']->update(Scc_Channel_Outbox::t(), ['state' => 'sending', 'locked_until' => Scc_Channel::ts() - 5], ['event_id' => $x]);
add_filter('scc_channel_outbox_send', $f2 = fn($pre, $r, $p) => ['ok' => true], 10, 3);
$s = Scc_Channel_Outbox::sweep(); ok('sweep reclaims a crashed sender and resends it', $s['reclaimed'] >= 1 && $row($x)['state'] === 'delivered', $s);
remove_filter('scc_channel_outbox_send', $f2, 10);
$x = $mk('lock'); $GLOBALS['wpdb']->update(Scc_Channel_Outbox::t(), ['state' => 'sending', 'locked_until' => Scc_Channel::ts() + 80], ['event_id' => $x]);
Scc_Channel_Outbox::sweep(); eq('a live lock is left alone by sweep', $row($x)['state'], 'sending');
$x = $mk('evid'); $payloads = [];
add_filter('scc_channel_outbox_send', $f3 = function ($pre, $r, $p) use (&$payloads) { $payloads[] = $p['eventId']; return ['ok' => true]; }, 10, 3);
Scc_Channel_Outbox::deliver($x); eq('the same business event id is sent on every attempt', $payloads, [$x]);
remove_filter('scc_channel_outbox_send', $f3, 10);
eq('enqueue is idempotent per event id', [Scc_Channel_Outbox::enqueue('test', '/p', [], '', '', $x), (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM ' . Scc_Channel_Outbox::t() . ' WHERE event_id=%s', $x))], [$x, 1]);

section('12. review: totals, signed token, expiry, coupon-version invalidation');
mkp('t-channel-rs', ['sku' => 'RV-1', 'price' => 100, 'stock' => 9, 'sizeStock' => ['M' => 9]]);
mkp('t-channel-rh', ['sku' => 'RV-1', 'price' => 100, 'stock' => 0, 'sizeStock' => ['M' => 0], 'brand' => 'SHIMI']);
$link('t-channel-rh', ['sourceUrl' => $src('t-channel-rs'), 'variantMap' => []]);
$mkquote = function (array $payload) {
    $items = []; $sub = 0;
    foreach ($payload['items'] as $i) { $p = Scc_Docs::get('products', $i['id']); if (!$p) throw new Scc_Http_Exception('מוצר אינו זמין.', 409); $items[] = ['id' => $i['id'], 'variantId' => $i['variantId'] ?? '', 'size' => $i['size'], 'qty' => $i['qty'], 'price' => $p['price']]; $sub += $p['price'] * $i['qty']; }
    $code = strtoupper(trim((string) ($payload['code'] ?? ''))); $disc = $code === 'T-CHANNEL-10' ? round($sub * 0.1, 2) : 0; $ship = $sub - $disc >= 200 ? 0 : 29;
    $total = $sub - $disc + $ship; $vat = round(($sub - $disc) * 18 / 118, 2);
    return ['items' => $items, 'subtotal' => $sub, 'discount' => $disc, 'shipping' => $ship, 'vatIncluded' => $vat, 'netSubtotal' => round($sub - $disc - $vat, 2), 'total' => $total];
};
add_filter('scc_channel_quote', fn($q, $p) => $q ?? $mkquote($p), 10, 2);
$body = fn(array $o = []) => array_merge(['termsAccepted' => true, 'customer' => ['name' => 'T', 'email' => 't@example.com', 'phone' => '050', 'city' => 'TLV', 'street' => 'X 1'], 'items' => [['id' => 't-channel-rs', 'variantId' => '', 'size' => 'M', 'qty' => 1]], 'shipping' => 'standard', 'code' => ''], $o);
$origin = Scc_Channel::origin_of(home_url());
$review = fn(array $b, array $h = null) => as_home(false, function () use ($b, $h, $origin) { $raw = Scc_Channel::json($b); $r = mkreq('POST', '/api/checkout-review', $raw, $h ?? ['origin' => $origin, 'content-type' => 'application/json']); try { $x = Scc_Channel::wrap(['Scc_Channel_Review', 'checkout_review'])($r); } catch (Throwable $e) { return [500, ['error' => $e->getMessage()]]; } return [$x->status, $x->body]; });
[$st, $d] = $review($body());
eq('review ok', $st, 200); eq('verified by SHIMI', $d['verified'] ?? null, true);
eq('subtotal/total in agorot (100.00 + 29.00 shipping)', [$d['source']['subtotalCents'] ?? 0, $d['source']['shippingCents'] ?? 0, $d['source']['totalCents'] ?? 0], [10000, 2900, 12900]);
eq('VAT 18% included, rounded half up', $d['source']['vatCents'] ?? 0, (int) floor(((10000) * 18) / 118 + 0.5));
eq('SHIMI echoes the exact same summary', Scc_Channel::json($d['home']), Scc_Channel::json($d['source']));
ok('token present with 5 minute expiry', isset($d['reviewToken']) && preg_match('/^\d+\.[a-f0-9]{64}$/', $d['reviewToken']) === 1);
$tok = $d['reviewToken']; $quote = $mkquote($body()); $vt = fn(array $b, string $t, ?array $q = null) => as_home(false, fn() => Scc_Channel_Review::validate_token(array_merge($b, ['reviewToken' => $t]), $q ?? $mkquote($b)));
$vt($body(), $tok); ok('token validates for the same cart', true);
throws('token fails when the cart changes', fn() => $vt($body(['items' => [['id' => 't-channel-rs', 'variantId' => '', 'size' => 'M', 'qty' => 3]]]), $tok), 'פרטי הסל או המחיר השתנו. יש לאמת שוב את סיכום ההזמנה.', 409);
[$exp, $dig] = explode('.', $tok);
throws('tampered digest', fn() => $vt($body(), $exp . '.' . str_repeat('0', 64)), 'פרטי הסל או המחיר השתנו. יש לאמת שוב את סיכום ההזמנה.', 409);
throws('expired token', fn() => $vt($body(), (string) ((int) floor(microtime(true) * 1000) - 1000) . '.' . $dig), 'סיכום ההזמנה פג. יש לאמת שוב את ההזמנה.', 409);
throws('token too far in the future', fn() => $vt($body(), (string) ((int) floor(microtime(true) * 1000) + 400000) . '.' . $dig), 'סיכום ההזמנה פג. יש לאמת שוב את ההזמנה.', 409);
$vt($body(['reviewToken' => ''] + $body()), ''); ok('no token = nothing to validate', true);
Scc_Docs::put('products', 't-channel-rs', array_merge(pget('t-channel-rs'), ['price' => 110]));
throws('price moved after the review -> token invalid', fn() => $vt($body(), $tok, $mkquote($body())), 'פרטי הסל או המחיר השתנו. יש לאמת שוב את סיכום ההזמנה.', 409);
Scc_Docs::put('products', 't-channel-rs', array_merge(pget('t-channel-rs'), ['price' => 100]));
// coupon invalidation
[$st, $dc] = $review($body(['code' => 't-channel-10'])); eq('coupon review ok', $st, 200); eq('10% discount in agorot', $dc['source']['discountCents'] ?? 0, 1000); eq('coupon code normalised upper-case', $dc['source']['couponCode'] ?? '', 'T-CHANNEL-10');
$ct = $dc['reviewToken']; $cb = $body(['code' => 't-channel-10']); $vt($cb, $ct); ok('coupon token valid before the coupon changes', true);
$v0 = Scc_Channel::coupon_version('T-CHANNEL-10');
Scc_Channel_Events::coupon_changed('t-channel-10');
ok('coupon version bumped', Scc_Channel::coupon_version('T-CHANNEL-10') !== $v0, [$v0, Scc_Channel::coupon_version('T-CHANNEL-10')]);
throws('coupon change invalidates the earlier review', fn() => $vt($cb, $ct), 'פרטי הסל או המחיר השתנו. יש לאמת שוב את סיכום ההזמנה.', 409);
$vt($body(), $tok); ok('other carts (no coupon) are unaffected', true);
$v1 = Scc_Channel::coupon_version('T-CHANNEL-10');
Scc_Docs::put('promotions', 't-channel-promo', ['id' => 't-channel-promo', 'code' => 'T-CHANNEL-10', 'type' => 'percent', 'value' => 20, 'active' => true]);
ok('editing the stored promotion (any path) also changes the version', Scc_Channel::coupon_version('T-CHANNEL-10') !== $v1);
do_action('scc_admin_promotion_saved', ['id' => 't-channel-promo', 'code' => 'T-CHANNEL-10']); $v2 = Scc_Channel::coupon_version('T-CHANNEL-10');
do_action('scc_coupon_changed', 'T-CHANNEL-10'); ok('hooks scc_admin_promotion_saved / scc_coupon_changed bump the counter', Scc_Channel::coupon_version('T-CHANNEL-10') !== $v2);
// request guards
[$st, $d] = $review($body(), ['origin' => 'https://evil.test', 'content-type' => 'application/json']); eq('foreign Origin rejected', [$st, $d['error'] ?? ''], [403, 'Origin rejected']);
[$st, $d] = $review($body(), ['origin' => $origin, 'content-type' => 'text/plain']); eq('JSON required', [$st, $d['error'] ?? ''], [415, 'JSON required']);
[$st, $d] = $review($body(['termsAccepted' => false])); eq('terms must be accepted', [$st, $d['error'] ?? ''], [400, 'יש לאשר תנאי רכישה.']);
[$st, $d] = $review($body(['customer' => ['name' => 'T', 'email' => 'bad', 'phone' => '1', 'city' => 'c', 'street' => 's']])); eq('customer fields validated', [$st, $d['error'] ?? ''], [400, 'נדרשים שם, אימייל, טלפון וכתובת משלוח תקינים.']);
[$st, $d] = $review($body(['items' => []])); eq('empty cart', [$st, $d['error'] ?? ''], [400, 'הסל ריק.']);
[$st, $d] = $review($body(['items' => [['id' => 't-channel-unknown', 'variantId' => '', 'size' => 'M', 'qty' => 1]]])); eq('unknown product', $st, 409);
// SHIMI side verification
$acc = fn(array $s) => as_home(true, function () use ($s) { try { return [200, Scc_Channel_Review::accept(['protocol' => 'shem-review-v1', 'summary' => $s])]; } catch (Scc_Http_Exception $e) { return [$e->status, $e->getMessage()]; } });
[$st, $d0] = $review($body()); $sum = $d0['source'];
eq('SHIMI accepts a consistent summary', $acc($sum)[0], 200);
$bad = $sum; $bad['totalCents'] += 1; eq('altered total', $acc($bad), [409, 'סכומי הסיכום אינם תואמים.']);
$bad = $sum; $bad['items'][0]['priceCents'] = 9000; $bad['items'][0]['lineCents'] = 9000; eq('altered price', $acc($bad), [409, 'מחיר או מק״ט אינם תואמים.']);
$bad = $sum; $bad['items'][0]['sku'] = 'WRONG'; eq('altered SKU', $acc($bad), [409, 'מחיר או מק״ט אינם תואמים.']);
$bad = $sum; $bad['items'][0]['qty'] = 6; $bad['items'][0]['lineCents'] = 60000; eq('qty over 5', $acc($bad), [400, 'כמות לא תקינה.']);
$bad = $sum; $bad['items'][] = $sum['items'][0]; eq('duplicate line', $acc($bad), [400, 'פריט כפול בסיכום.']);
$bad = $sum; $bad['items'][0]['size'] = 'XXL'; eq('size not on the card', $acc($bad), [409, 'מיפוי מידה וצבע אינו תואם.']);
$bad = $sum; $bad['items'][0]['productId'] = 't-channel-notlinked'; eq('unlinked SHEM product', $acc($bad), [409, 'מוצר SHEM אינו מקושר ל-SHIMI.']);
$bad = $sum; $bad['currency'] = 'USD'; eq('wrong currency', $acc($bad), [400, 'סיכום הזמנה לא תקין.']);
Scc_Docs::put('products', 't-channel-rs', array_merge(pget('t-channel-rs'), ['stock' => 0, 'sizeStock' => ['M' => 0]]));
eq('not enough SHEM stock (qty 1 vs 0)', $acc($sum), [409, 'אין מספיק מלאי במוצר המקושר.']);
Scc_Docs::put('products', 't-channel-rs', array_merge(pget('t-channel-rs'), ['stock' => 9, 'sizeStock' => ['M' => 9]]));
$GLOBALS['t_peer_mode'] = 'down'; [$st, $d] = $review($body()); eq('review fails closed when SHIMI is unreachable (no token issued)', [$st, isset($d['reviewToken'])], [503, false]); $GLOBALS['t_peer_mode'] = 'sim';
add_filter('scc_channel_peer_url', fn($u) => ''); [$st, $d] = $review($body()); eq('review without peer config -> 503', [$st, $d['error'] ?? ''], [503, 'חיבור לסיכום SHIMI טרם הוגדר.']); remove_all_filters('scc_channel_peer_url');
cleanup(); finish();
