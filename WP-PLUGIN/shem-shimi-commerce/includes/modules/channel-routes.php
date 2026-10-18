<?php
if (!defined('ABSPATH')) exit;
/**
 * HTTP surface of the channel.
 *   Browser:  POST /api/checkout-review (both stores), POST /api/shimi/checkout (SHIMI), GET /payment/{id}?token= (SHIMI)
 *   Signed (HMAC x-channel-*; legacy x-shem-* for the payment bridge): /api/channel/{source,sources,register,unregister,order,reserve,release,commit} on SHEM,
 *       /api/channel/{review,refresh,checkout,provider-callback} on SHIMI. Anything else: 404 {"error":"Not found"} (after the signature check, like the worker).
 *   Admin (WP cookie + nonce): /api/admin/channel/{config,status,preview,reconcile,outbox,sweep} and /api/admin/channel/products/{id}/{link|sync|unlink|source}
 * Idempotency: reserve/release/commit are idempotent by reservation id; refresh carries an event id (inbox) so a replayed delivery is a no-op.
 */
final class Scc_Channel_Routes {
    private static function ok(mixed $d, int $s = 200): Scc_Response { return Scc_Response::json($d, $s, ['cache-control' => 'no-store']); }

    /* ---------- inbox (replay protection) ---------- */
    private static function inbox_begin(string $event_id, string $kind): ?array {
        global $wpdb; $t = Scc_Channel_Tx::t('inbox'); $now = Scc_Channel::ts();
        $n = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (event_id,kind,status,locked_until,created_at,updated_at) VALUES (%s,%s,'processing',%d,%d,%d)", $event_id, $kind, $now + 60, $now, $now));
        if ($n) return null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT status,locked_until,response FROM $t WHERE event_id=%s", $event_id), ARRAY_A);
        if ($row && $row['status'] === 'done') return (json_decode((string) $row['response'], true) ?: ['ok' => true]) + ['duplicate' => true];
        if ($row && (int) $row['locked_until'] > $now) Scc_Channel::fail('האירוע בעיבוד. נסו שוב.', 409);
        $taken = $wpdb->query($wpdb->prepare("UPDATE $t SET locked_until=%d,updated_at=%d WHERE event_id=%s AND status='processing' AND locked_until<=%d", $now + 60, $now, $event_id, $now));
        if (!$taken) Scc_Channel::fail('האירוע בעיבוד. נסו שוב.', 409);
        return null;
    }
    private static function inbox_done(string $event_id, array $res): void {
        global $wpdb; $wpdb->update(Scc_Channel_Tx::t('inbox'), ['status' => 'done', 'response' => Scc_Channel::json($res), 'updated_at' => Scc_Channel::ts()], ['event_id' => $event_id]);
    }
    private static function inbox_abort(string $event_id): void { global $wpdb; $wpdb->delete(Scc_Channel_Tx::t('inbox'), ['event_id' => $event_id, 'status' => 'processing']); }

    /* ---------- /api/channel/* ---------- */
    public static function signed(Scc_Request $r): Scc_Response {
        $path = $r->path; $home = Scc_Channel::is_home();
        if ($path === '/api/channel/checkout' && $home && $r->method === 'POST') return Scc_Channel_Payment::accept_shem($r);
        if ($path === '/api/channel/provider-callback' && $home && $r->method === 'POST') return Scc_Channel_Payment::provider_callback($r);
        if ($r->method !== 'POST' || $path === '/api/channel/qa-fixtures') return self::ok(['error' => 'Not found'], 404);
        $d = Scc_Channel::verify($r, Scc_Channel::secret());
        if ($home) {
            if ($path === '/api/channel/review') return self::ok(Scc_Channel_Review::accept($d));
            if ($path === '/api/channel/refresh') return self::refresh($d);
            Scc_Channel::fail('Not found', 404);
        }
        switch ($path) {
            case '/api/channel/sources':
                if (!is_array($d['ids'] ?? null) || !$d['ids'] || count($d['ids']) > 100 || array_filter($d['ids'], fn($i) => !is_string($i) || strlen($i) > 100)) Scc_Channel::fail('בקשת מוצרים אינה תקינה.', 400);
                return self::ok(['products' => Scc_Channel_Links::sources($d['ids'])]);
            case '/api/channel/source': return self::ok(Scc_Channel_Links::source((string) ($d['id'] ?? '')));
            case '/api/channel/register': return self::ok(Scc_Channel_Links::register($d));
            case '/api/channel/unregister': return self::ok(Scc_Channel_Links::unregister($d));
            case '/api/channel/order': return self::ok(Scc_Channel_Orders::snapshot((string) ($d['id'] ?? '')));
            case '/api/channel/reserve':
                if (!is_string($d['reservationId'] ?? null) || !preg_match('/^shimi:[a-zA-Z0-9-]+$/', $d['reservationId'])) Scc_Channel::fail('מזהה אינו תקין.');
                Scc_Channel_Stock::reserve($d['reservationId'], is_array($d['items'] ?? null) ? $d['items'] : []);
                return self::ok(['ok' => true]);
            case '/api/channel/release': Scc_Channel_Stock::release((string) ($d['reservationId'] ?? '')); return self::ok(['ok' => true]);
            case '/api/channel/commit': return self::ok(Scc_Channel_Stock::commit((string) ($d['reservationId'] ?? '')));
        }
        return self::ok(['error' => 'Not found'], 404);
    }
    /** SHIMI: SHEM says a product changed. Replay-safe by event id. */
    public static function refresh(array $d): Scc_Response {
        $eid = isset($d['eventId']) && is_string($d['eventId']) && $d['eventId'] !== '' ? substr($d['eventId'], 0, 190) : '';
        if ($eid !== '') { $dup = self::inbox_begin($eid, 'refresh'); if ($dup !== null) return self::ok($dup); }
        try {
            $link = Scc_Channel_Links::find_by_source((string) ($d['id'] ?? ''));
            if ($link) Scc_Channel_Links::sync($link);
            $res = ['ok' => true];
        } catch (Throwable $e) { if ($eid !== '') self::inbox_abort($eid); throw $e; }
        if ($eid !== '') self::inbox_done($eid, $res);
        return self::ok($res);
    }

    /* ---------- admin ---------- */
    public static function config(Scc_Request $r) {
        if ($r->method === 'GET') return Scc_Admin::json(Scc_Payment_Gateway_Cfg::get());
        if ($r->method !== 'PUT') Scc_Channel::fail('Method not allowed', 405);
        $d = Scc_Channel::body_json($r); $c = [];
        foreach (['provider' => 100, 'collectorName' => 150, 'merchantId' => 100] as $k => $n) $c[$k] = mb_substr(trim(is_scalar($d[$k] ?? null) ? (string) $d[$k] : ''), 0, $n);
        Scc_Docs::set_setting('gateway', $c);
        return Scc_Admin::json($c);
    }
    public static function status(Scc_Request $r) {
        global $wpdb; $home = Scc_Channel::is_home();
        $sessions = [];
        foreach ($wpdb->get_results('SELECT data FROM ' . Scc_Docs::t('payment_sessions') . ' ORDER BY updated_at DESC LIMIT 100', ARRAY_A) as $x) { $s = json_decode($x['data'], true) ?: []; unset($s['token']); $sessions[] = $s; }
        return Scc_Admin::json(['role' => Scc_Channel::role(), 'peerOrigin' => Scc_Channel::peer_url(), 'connected' => Scc_Channel::secret() !== '' && Scc_Channel::peer_url() !== '',
            'paymentReady' => $home && Scc_Channel_Payment::adapter_ready(), 'providerConfigured' => false, 'links' => Scc_Channel_Links::all(), 'sessions' => $sessions,
            'outbox' => Scc_Channel_Outbox::counts()]);
    }
    public static function product_action(Scc_Request $r) {
        $id = rawurldecode($r->params[1]); $action = $r->params[2];
        if (!Scc_Channel_Catalog::get($id)) Scc_Channel::fail('מוצר לא נמצא.', 404);
        if ($action === 'source' && $r->method === 'GET') return Scc_Admin::json(Scc_Channel_Links::source_url($id));
        if ($r->method !== 'POST') Scc_Channel::fail('Method not allowed', 405);
        $d = Scc_Channel::body_json($r);
        if (!Scc_Channel::is_home()) {
            if ($action === 'sync') return Scc_Admin::json(Scc_Channel_Links::source_sync($id));
            Scc_Channel::fail('חיבור המקור נעשה בכרטיס SHIMI.');
        }
        return Scc_Admin::json(match ($action) { 'link' => Scc_Channel_Links::link($id, $d), 'sync' => Scc_Channel_Links::resync($id), 'unlink' => Scc_Channel_Links::unlink($id), default => Scc_Channel::fail('Not found', 404) });
    }
    public static function preview(Scc_Request $r) { return Scc_Admin::json(Scc_Channel_Links::preview(Scc_Channel::body_json($r))); }
    public static function reconcile(Scc_Request $r) { return Scc_Admin::json(Scc_Channel_Payment::reconcile(Scc_Channel::body_json($r))); }
    public static function outbox(Scc_Request $r) {
        return Scc_Admin::json(['counts' => Scc_Channel_Outbox::counts(), 'events' => Scc_Channel_Outbox::listing(min(500, max(1, (int) ($r->query['limit'] ?? 100))), (string) ($r->query['state'] ?? ''))]);
    }
    public static function outbox_retry(Scc_Request $r) {
        $id = rawurldecode($r->params[1]);
        if (!Scc_Channel_Outbox::retry($id)) Scc_Channel::fail('האירוע אינו ממתין לטיפול ידני.', 409);
        return Scc_Admin::json(['ok' => true, 'state' => Scc_Channel_Outbox::deliver($id, true) ?? 'pending']);
    }
    public static function sweep(Scc_Request $r) { return Scc_Admin::json(['ok' => true] + Scc_Channel_Outbox::sweep()); }
}
/** Tiny wrapper so config GET shares the stored shape ({} when never saved). */
final class Scc_Payment_Gateway_Cfg { public static function get(): array|object { $g = Scc_Docs::setting('gateway', null); return is_array($g) && $g ? $g : new stdClass(); } }

if (class_exists('Scc_Admin')) {
    $route = ['Scc_Admin', 'route']; $S = 'Scc_Channel_Routes';
    $w = fn(string $m) => fn(Scc_Request $r) => call_user_func(Scc_Channel::wrap([$S, $m]), $r);
    Scc_Admin::route('*', '#^/api/admin/channel/config$#', $w('config'));
    Scc_Admin::route('GET', '#^/api/admin/channel/status$#', $w('status'));
    Scc_Admin::route('POST', '#^/api/admin/channel/preview$#', $w('preview'));
    Scc_Admin::route('POST', '#^/api/admin/channel/reconcile$#', $w('reconcile'));
    Scc_Admin::route('GET', '#^/api/admin/channel/outbox$#', $w('outbox'));
    Scc_Admin::route('POST', '#^/api/admin/channel/outbox/([^/]+)/retry$#', $w('outbox_retry'));
    Scc_Admin::route('POST', '#^/api/admin/channel/sweep$#', $w('sweep'));
    Scc_Admin::route('*', '#^/api/admin/channel/products/([^/]+)/(link|sync|unlink|source)$#', $w('product_action'));
}
Scc_Router::add('POST', '#^/api/checkout-review$#', Scc_Channel::wrap(['Scc_Channel_Review', 'checkout_review']), 'public');
Scc_Router::add('POST', '#^/api/shimi/checkout$#', Scc_Channel::wrap(['Scc_Channel_Payment', 'own_checkout']), 'public');
Scc_Router::add('GET', '#^/payment/(.+)$#', Scc_Channel::wrap(['Scc_Channel_Payment', 'page']), 'public');
Scc_Router::add('*', '#^/api/channel/#', Scc_Channel::wrap(['Scc_Channel_Routes', 'signed']), 'hmac');
