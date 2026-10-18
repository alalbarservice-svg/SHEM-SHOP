<?php
if (!defined('ABSPATH')) exit;
/**
 * Embedded payment flow of the channel (port of providerSession / acceptShem / finalize / ownCheckout / provider-callback / GET /payment/{id}).
 * The generic provider adapter stays DISABLED unless SCC_PAYMENT_ADAPTER_ENABLED === true and URL + secret + checkout origin are set in wp-config.php;
 * nothing here ever charges. Real payments of the staging pair run through the SHIMI-PAY / SHEM-PAY plugins (separate protocol, untouched).
 * Secrets: SCC_HOME_PAYMENT_SECRET (shem-home-v1 between the stores; falls back to SHIMI_HOME_PAYMENT_SECRET / SHEM_HOME_PAYMENT_SECRET), SCC_PAYMENT_ADAPTER_SECRET.
 * Seams: scc_channel_make_order (core: build the SHIMI order doc from the cart), scc_channel_store_order (core: persist it), scc_channel_confirm_payment (core: mark paid).
 */
final class Scc_Channel_Payment {
    private static function const_str(array $names): string { foreach ($names as $n) if (defined($n) && is_string(constant($n)) && constant($n) !== '') return constant($n); return ''; }
    public static function home_secret(): string { return self::const_str(['SCC_HOME_PAYMENT_SECRET', 'SHIMI_HOME_PAYMENT_SECRET', 'SHEM_HOME_PAYMENT_SECRET']); }
    public static function adapter_secret(): string { return self::const_str(['SCC_PAYMENT_ADAPTER_SECRET']); }
    public static function adapter_ready(): bool { return defined('SCC_PAYMENT_ADAPTER_ENABLED') && SCC_PAYMENT_ADAPTER_ENABLED === true && self::const_str(['SCC_PAYMENT_ADAPTER_URL']) !== '' && self::adapter_secret() !== ''; }
    public static function gateway(): array { $g = Scc_Docs::setting('gateway', []); return is_array($g) ? $g : []; }
    private static function sess(string $id): ?array { return Scc_Docs::get('payment_sessions', $id); }
    private static function put(string $id, array $s): void { Scc_Docs::put('payment_sessions', $id, $s); }
    private static function base(): string { return rtrim(home_url(), '/'); }

    /** Raw signed (shem-home-v1) POST, no redirects. */
    private static function post_legacy(string $url, string $secret, string $raw): array {
        $ts = (string) Scc_Channel::ts();
        $r = wp_remote_post($url, ['timeout' => 15, 'redirection' => 0, 'body' => $raw, 'headers' => ['content-type' => 'application/json', 'x-shem-timestamp' => $ts, 'x-shem-signature' => Scc_Channel::sign($secret, $ts . '.' . $raw)]]);
        if (is_wp_error($r)) return ['status' => 0, 'body' => ''];
        return ['status' => (int) wp_remote_retrieve_response_code($r), 'body' => (string) wp_remote_retrieve_body($r)];
    }
    public static function provider_session(array $session, string $base): array {
        $g = self::gateway();
        if (empty($g['collectorName'])) Scc_Channel::fail('יש להגדיר את שם העסק שגובה את התשלום.', 503);
        if (!self::adapter_ready()) Scc_Channel::fail('ספק סליקה טרם חובר ונבדק. לא בוצע חיוב.', 503);
        $url = self::const_str(['SCC_PAYMENT_ADAPTER_URL']);
        if (strpos($url, 'https://') !== 0 && !Scc_Channel::insecure_ok()) Scc_Channel::fail('כתובת מתאם סליקה אינה תקינה.');
        $raw = Scc_Channel::json(['idempotencyKey' => $session['id'], 'orderId' => $session['id'], 'amountCents' => $session['amountCents'], 'currency' => 'ILS', 'customer' => $session['customer'], 'callbackUrl' => $base . '/api/channel/provider-callback']);
        $r = self::post_legacy($url, self::adapter_secret(), $raw);
        if ($r['status'] < 200 || $r['status'] >= 300) Scc_Channel::fail('ספק הסליקה לא אישר פתיחת תשלום.', 502);
        $data = json_decode($r['body'], true); $co = Scc_Channel::origin_of((string) ($data['checkoutUrl'] ?? '')); $allowed = Scc_Channel::origin_of(self::const_str(['SCC_PAYMENT_CHECKOUT_ORIGIN']));
        $https = strpos((string) ($data['checkoutUrl'] ?? ''), 'https://') === 0 || Scc_Channel::insecure_ok();
        if (!$co || !$allowed || $co !== $allowed || !$https) Scc_Channel::fail('כתובת התשלום אינה מאושרת.', 502);
        return array_merge($session, ['checkoutUrl' => $data['checkoutUrl'], 'status' => 'pending']);
    }
    private static function embed(array $s): string { return self::base() . '/payment/' . rawurlencode($s['id']) . '?token=' . $s['token']; }

    /** POST /api/channel/checkout (SHIMI): SHEM asks for an embedded payment session for one of its orders. */
    public static function accept_shem(Scc_Request $r): Scc_Response {
        $d = Scc_Channel::verify($r, self::home_secret(), true);
        if (($d['protocol'] ?? '') !== 'shem-home-v1' || ($d['currency'] ?? '') !== 'ILS' || !is_array($d['items'] ?? null) || !$d['items']) Scc_Channel::fail('בקשת תשלום אינה תקינה.');
        $original = Scc_Channel::peer('/api/channel/order', ['id' => $d['orderId'] ?? '']);
        foreach (['amountCents', 'shippingCents', 'discountCents'] as $k) if (($d[$k] ?? null) !== ($original[$k] ?? null)) Scc_Channel::fail('הסכום אינו תואם להזמנת SHEM.');
        if (Scc_Channel::json($d['items']) !== Scc_Channel::json($original['items'] ?? null) || Scc_Channel::json($d['customer'] ?? null) !== Scc_Channel::json($original['customer'] ?? null)) Scc_Channel::fail('פרטי ההזמנה אינם תואמים.');
        $okCb = false; foreach (Scc_Channel::peer_origins() as $b) if (($d['callbackUrl'] ?? '') === $b . '/api/home/payment-callback') $okCb = true;
        if (!$okCb) Scc_Channel::fail('כתובת אישור אינה מאושרת.');
        foreach ($d['items'] as $i) {
            $link = Scc_Channel_Links::find_by_source((string) $i['productId']);
            if (!$link) Scc_Channel::fail('מוצר SHEM אינו מקושר ל-SHIMI.');
            $p = Scc_Channel_Links::sync($link)['product']; $tv = null;
            if (!empty($i['variantId'])) { foreach (($p['variants'] ?? []) as $v) if ((((array) $link['variantMap'])[$v['id']] ?? null) === $i['variantId']) $tv = $v; if (!$tv) Scc_Channel::fail('גרסה אינה מקושרת.'); }
            if (($tv['sku'] ?? $p['sku'] ?? null) !== ($i['sku'] ?? null) || (int) floor($p['price'] * 100 + 0.5) !== ($i['priceCents'] ?? null)) Scc_Channel::fail('מחיר או מק״ט אינם תואמים.');
        }
        $id = 'shem:' . $d['orderId']; $old = self::sess($id); $hash = Scc_Channel::sign(Scc_Channel::secret(), Scc_Channel::json($d));
        if ($old) {
            if (($old['hash'] ?? '') !== $hash) Scc_Channel::fail('מזהה תשלום משמש להזמנה אחרת.');
            if (!empty($old['checkoutUrl'])) return Scc_Response::json(['embedUrl' => self::embed($old)], 200, ['cache-control' => 'no-store']);
        }
        $s = $old ?: ['id' => $id, 'source' => 'SHEM', 'orderId' => $d['orderId'], 'amountCents' => $d['amountCents'], 'currency' => 'ILS', 'customer' => $d['customer'], 'items' => $d['items'], 'shippingCents' => $d['shippingCents'], 'discountCents' => $d['discountCents'],
            'callbackUrl' => $d['callbackUrl'], 'hash' => $hash, 'token' => bin2hex(random_bytes(32)), 'status' => 'creating', 'createdAt' => Scc_Channel::now()];
        self::put($id, $s);
        try { $s = self::provider_session($s, self::base()); self::put($id, $s); }
        catch (Throwable $e) { self::put($id, array_merge($s, ['status' => 'creation-failed', 'error' => $e->getMessage()])); throw $e; }
        return Scc_Response::json(['embedUrl' => self::embed($s)], 201, ['cache-control' => 'no-store']);
    }

    private static function clean_payment(array $p): array {
        if (class_exists('Scc_Core') && method_exists('Scc_Core', 'clean_payment')) return (array) Scc_Core::clean_payment($p);
        $o = []; foreach (['method', 'brand', 'last4', 'holder', 'installments'] as $k) if (isset($p[$k]) && is_scalar($p[$k])) $o[$k] = mb_substr((string) $p[$k], 0, 40);
        return $o;
    }
    public static function finalize(array $s, array $event): array {
        if (!empty($s['paymentReference']) && $s['paymentReference'] !== $event['transactionId']) Scc_Channel::fail('אסמכתת תשלום אינה תואמת.');
        $details = self::clean_payment((array) ($event['paymentDetails'] ?? []));
        $s = array_merge($s, ['paymentReference' => $event['transactionId'], 'paymentDetails' => $details, 'status' => 'paid-awaiting-sync', 'paidAt' => $s['paidAt'] ?? Scc_Channel::now()]);
        self::put($s['id'], $s);
        try {
            if (($s['source'] ?? '') === 'SHEM') {
                $raw = Scc_Channel::json(['orderId' => $s['orderId'], 'status' => 'paid', 'currency' => 'ILS', 'amountCents' => $s['amountCents'], 'transactionId' => $event['transactionId'], 'paymentDetails' => $details ?: new stdClass()]);
                $r = self::post_legacy((string) $s['callbackUrl'], self::home_secret(), $raw);
                if ($r['status'] < 200 || $r['status'] >= 300) Scc_Channel::fail('התשלום אושר אך העדכון ל-SHEM דורש ניסיון חוזר.');
            } else {
                if (!empty($s['centralItems'])) Scc_Channel::peer('/api/channel/commit', ['reservationId' => $s['id']]);
                $done = apply_filters('scc_channel_confirm_payment', null, $s['orderId'], $event['transactionId'], 'shimi', $details);
                if ($done === null && function_exists('wc_get_orders')) {
                    $o = (wc_get_orders(['limit' => 1, 'meta_key' => '_scc_legacy_id', 'meta_value' => $s['orderId'], 'return' => 'objects']) ?: [null])[0];
                    if ($o) { $o->update_meta_data('_scc_channel_central_done', '1'); $o->save(); $o->payment_complete($event['transactionId']); }
                }
            }
            $s['status'] = 'paid'; $s['error'] = ''; self::put($s['id'], $s);
            return ['ok' => true];
        } catch (Throwable $e) { self::put($s['id'], array_merge($s, ['error' => $e->getMessage()])); throw $e; }
    }
    /** POST /api/channel/provider-callback (SHIMI): adapter confirms the payment. */
    public static function provider_callback(Scc_Request $r): Scc_Response {
        $event = Scc_Channel::verify($r, self::adapter_secret(), true);
        $s = self::sess((string) ($event['orderId'] ?? ''));
        if (!$s || ($event['currency'] ?? '') !== 'ILS' || ($event['amountCents'] ?? null) !== ($s['amountCents'] ?? -1) || empty($event['transactionId']) || ($event['status'] ?? '') !== 'paid') Scc_Channel::fail('אישור תשלום אינו תואם.');
        return Scc_Response::json(self::finalize($s, $event), 200, ['cache-control' => 'no-store']);
    }
    /** POST /api/admin/channel/reconcile */
    public static function reconcile(array $d): array {
        $s = self::sess((string) ($d['id'] ?? ''));
        if (!$s || ($s['status'] ?? '') !== 'paid-awaiting-sync' || empty($s['paymentReference'])) Scc_Channel::fail('אין תשלום מאושר הממתין לסנכרון.', 409);
        return self::finalize($s, ['transactionId' => $s['paymentReference'], 'paymentDetails' => $s['paymentDetails'] ?? []]);
    }

    /** POST /api/shimi/checkout (SHIMI own checkout; blocked until a provider adapter is connected and tested). */
    public static function own_checkout(Scc_Request $r): Scc_Response {
        if (!Scc_Channel::is_home()) return Scc_Response::error('לא נמצא.', 404);
        if (!Scc_Channel::same_origin($r)) Scc_Channel::fail('Origin rejected', 403);
        $d = Scc_Channel::body_json($r);
        if (($d['termsAccepted'] ?? null) !== true) Scc_Channel::fail('יש לאשר את תנאי הרכישה.', 400);
        if (!is_array($d['items'] ?? null) || !$d['items']) Scc_Channel::fail('הסל ריק.', 400);
        $docs = []; foreach ($d['items'] as $i) { $p = Scc_Channel_Catalog::get((string) ($i['id'] ?? '')); if ($p) $docs[$p['id']] = $p; }
        Scc_Channel_Links::hydrate(array_values($docs));
        $quote = Scc_Channel_Review::quote(['items' => $d['items'], 'shipping' => $d['shipping'] ?? null, 'code' => $d['code'] ?? null] + $d);
        $cfg = Scc_Docs::setting('operations', []);
        if (empty($cfg['checkoutEnabled']) || !self::adapter_ready()) Scc_Channel::fail('הרכישה עדיין אינה פעילה. ספק סליקה טרם חובר.', 503);
        $draft = apply_filters('scc_channel_make_order', null, $d + ['shippingCost' => $quote['shipping'], 'shippingMethod' => $d['shipping'] ?? null, 'discount' => $quote['discount'], 'quote' => $quote]);
        if (!is_array($draft)) Scc_Channel::fail('יצירת הזמנה טרם חוברה למנוע ההזמנות.', 503);
        $c = $draft['customer'] ?? [];
        if (empty($c['phone']) || empty($c['city']) || empty($c['street'])) Scc_Channel::fail('יש להשלים פרטי משלוח.', 400);
        $id = wp_generate_uuid4();
        $order = array_merge($draft, ['id' => $id, 'source' => 'SHIMI', 'status' => 'new', 'paymentStatus' => 'unpaid', 'createdAt' => Scc_Channel::now(), 'viewToken' => bin2hex(random_bytes(32))]);
        $lines = array_map(fn($i) => ['productId' => (string) ($i['productId'] ?? $i['id'] ?? ''), 'variantId' => (string) ($i['variantId'] ?? ''), 'size' => (string) ($i['size'] ?? ''), 'qty' => $i['qty'] ?? null, 'priceCents' => $i['priceCents'] ?? null], $order['items']);
        $session = ['id' => 'shimi:' . $id, 'orderId' => $id, 'source' => 'SHIMI', 'amountCents' => $order['totalCents'], 'currency' => 'ILS', 'customer' => $c, 'items' => $order['items'], 'token' => $order['viewToken'], 'status' => 'creating', 'createdAt' => Scc_Channel::now()];
        do_action('scc_channel_store_order', $order); if (!has_action('scc_channel_store_order')) Scc_Docs::put('orders', $id, $order);
        try {
            $res = Scc_Channel_Orders::reserve_for_order($id, $lines);
            $session['centralItems'] = $res['central'];
            self::put($session['id'], $session);
            $s = self::provider_session($session, self::base()); self::put($s['id'], $s);
            return Scc_Response::json(['orderId' => $id, 'viewToken' => $order['viewToken'], 'embedUrl' => self::embed($s)], 201, ['cache-control' => 'no-store']);
        } catch (Throwable $e) {
            Scc_Channel_Orders::release_for_order($id, true);
            self::put($session['id'], array_merge($session, ['status' => 'creation-failed', 'error' => $e->getMessage()]));
            throw $e;
        }
    }

    /** GET /payment/{id}?token=... (SHIMI): iframe page that embeds the provider checkout. */
    public static function page(Scc_Request $r): Scc_Response {
        if (!Scc_Channel::is_home()) return Scc_Response::error('Not found', 404);
        $s = self::sess(rawurldecode((string) ($r->params[1] ?? '')));
        if (!$s || !hash_equals((string) ($s['token'] ?? ''), (string) ($r->query['token'] ?? ''))) return Scc_Response::error('Not found', 404);
        $esc = fn($x) => htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8');
        $url = (string) ($s['checkoutUrl'] ?? '');
        $html = '<!doctype html><html lang="he" dir="rtl"><meta name="viewport" content="width=device-width"><title>תשלום SHIMI</title><style>body{font-family:Arial;margin:24px}iframe{width:100%;height:580px;border:0}</style><h2>תשלום מאובטח</h2><p>העסק הגובה: '
            . $esc(self::gateway()['collectorName'] ?? '') . '</p><p>הסכום לתשלום: ' . number_format($s['amountCents'] / 100, 2, '.', '') . ' ₪</p>'
            . ($url !== '' ? '<iframe title="תשלום אצל ספק הסליקה" src="' . $esc($url) . '" sandbox="allow-scripts allow-forms allow-same-origin allow-popups"></iframe>' : '<p>ספק סליקה טרם חובר. לא בוצע חיוב.</p>') . '</html>';
        return Scc_Response::raw($html, 'text/html;charset=utf-8', 200, ['referrer-policy' => 'no-referrer',
            'content-security-policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-src " . ($url !== '' ? Scc_Channel::origin_of($url) : 'https://invalid.example') . "; frame-ancestors 'self' " . implode(' ', Scc_Channel::peer_origins())]);
    }
}
