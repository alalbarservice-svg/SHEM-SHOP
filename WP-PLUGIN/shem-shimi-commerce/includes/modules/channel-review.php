<?php
if (!defined('ABSPATH')) exit;
/**
 * Pre-payment review (POST /api/checkout-review on both stores, POST /api/channel/review on SHIMI). No order, no payment session, no
 * stock hold is created here. SHEM is the sole authority for prices/coupons/shipping; SHIMI only verifies links, SKUs, sizes and stock.
 *
 * Seam - pricing engine: the canonical quote comes from filter `scc_channel_quote` (payload {items,shipping,code}) or Scc_Core::quote().
 * It must return the worker's /api/quote shape (shekels): {items[{id,variantId,size,qty,price}],subtotal,discount,shipping,vatIncluded,netSubtotal,total}
 * and throw Scc_Http_Exception for a Hebrew business error.
 */
final class Scc_Channel_Review {
    public static function quote(array $payload): array {
        $q = apply_filters('scc_channel_quote', null, $payload);
        if ($q === null && class_exists('Scc_Core') && method_exists('Scc_Core', 'quote')) $q = Scc_Core::quote($payload);
        if ($q instanceof Scc_Response) { if ($q->status >= 400) Scc_Channel::fail((string) ($q->body['error'] ?? 'בקשה לא תקינה.'), $q->status); $q = $q->body; }
        if (!is_array($q)) Scc_Channel::fail('מנוע התמחור טרם חובר.', 503);
        return $q;
    }
    private static function cents(float|int $v): int { return (int) floor($v * 100 + 0.5); }
    public static function summary(array $quote, array $products, ?string $shipping, mixed $code): array {
        $byId = []; foreach ($products as $p) $byId[$p['id']] = $p;
        $items = [];
        foreach ($quote['items'] as $line) {
            $p = $byId[$line['id']]; $v = null;
            foreach (($p['variants'] ?? []) as $x) if ($x['id'] === ($line['variantId'] ?? null)) $v = $x;
            $price = self::cents($line['price']);
            $items[] = ['productId' => $p['id'], 'variantId' => $v['id'] ?? '', 'name' => $p['he'] ?? '', 'sku' => $v['sku'] ?? $p['sku'] ?? '', 'size' => $line['size'] ?? '', 'color' => $v['color'] ?? $p['color'] ?? '',
                'qty' => (int) $line['qty'], 'priceCents' => $price, 'lineCents' => $price * (int) $line['qty']];
        }
        return ['items' => $items, 'currency' => 'ILS', 'subtotalCents' => self::cents($quote['subtotal']), 'discountCents' => self::cents($quote['discount']), 'shippingCents' => self::cents($quote['shipping']),
            'vatCents' => self::cents($quote['vatIncluded']), 'netSubtotalCents' => self::cents($quote['netSubtotal']), 'totalCents' => self::cents($quote['total']),
            'shippingMethod' => $shipping ?: 'standard', 'couponCode' => strtoupper(trim((string) ($code ?? ''))), 'vatRate' => 18];
    }
    private static function from_quote(array $data, array $quote): array {
        $ids = array_values(array_unique(array_map(fn($i) => (string) $i['id'], $data['items'])));
        $products = [];
        foreach ($ids as $id) {
            $p = Scc_Channel_Catalog::get($id);
            if (!$p || !empty($p['archived']) || ($p['active'] ?? true) === false) Scc_Channel::fail('מוצר אינו זמין.', 409);
            $line = null; foreach ($quote['items'] as $x) if ($x['id'] === $id) { $line = $x; break; }
            if (!$line) Scc_Channel::fail('מוצר אינו זמין.', 409);
            if (self::cents($p['price']) !== self::cents($line['price'])) Scc_Channel::fail('מחיר השתנה במהלך הבדיקה. רעננו את הסיכום.', 409);
            $products[] = $p;
        }
        return self::summary($quote, $products, $data['shipping'] ?? null, $data['code'] ?? '');
    }
    /** Digest input binds expiry, the exact summary and the coupon state (per-coupon version) - editing the coupon invalidates the token. */
    private static function digest(string $expires, array $summary): string {
        return Scc_Channel::sign(Scc_Channel::secret(), $expires . '.' . Scc_Channel::json($summary) . '.cv' . Scc_Channel::coupon_version((string) ($summary['couponCode'] ?? '')));
    }
    /** validateReviewToken(): called by the core checkout when the browser presents a reviewToken. */
    public static function validate_token(array $data, array $quote): void {
        if (empty($data['reviewToken'])) return;
        $parts = explode('.', (string) $data['reviewToken']); $expires = $parts[0] ?? ''; $digest = $parts[1] ?? '';
        $nowms = (int) floor(microtime(true) * 1000);
        if (!ctype_digit($expires) || (int) $expires < $nowms || (int) $expires > $nowms + 301000) Scc_Channel::fail('סיכום ההזמנה פג. יש לאמת שוב את ההזמנה.', 409);
        $summary = self::from_quote($data, $quote);
        if (Scc_Channel::secret() === '' || !hash_equals(self::digest($expires, $summary), (string) $digest)) Scc_Channel::fail('פרטי הסל או המחיר השתנו. יש לאמת שוב את סיכום ההזמנה.', 409);
    }
    public static function checkout_review(Scc_Request $r): Scc_Response {
        if (!Scc_Channel::same_origin($r)) Scc_Channel::fail('Origin rejected', 403);
        if (stripos($r->header('content-type'), 'application/json') === false) Scc_Channel::fail('JSON required', 415);
        $data = Scc_Channel::body_json($r);
        if (($data['termsAccepted'] ?? null) !== true) Scc_Channel::fail('יש לאשר תנאי רכישה.', 400);
        $c = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $s = fn($k) => is_string($c[$k] ?? null) ? trim($c[$k]) : '';
        if ($s('name') === '' || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $s('email')) || $s('phone') === '' || $s('city') === '' || $s('street') === '') Scc_Channel::fail('נדרשים שם, אימייל, טלפון וכתובת משלוח תקינים.', 400);
        if (!is_array($data['items'] ?? null) || !$data['items']) Scc_Channel::fail('הסל ריק.', 400);
        $quote = self::quote(['items' => $data['items'], 'shipping' => $data['shipping'] ?? null, 'code' => $data['code'] ?? null]);
        $summary = self::from_quote($data, $quote);
        if (Scc_Channel::is_home()) return self::out(['source' => $summary, 'home' => null, 'verified' => false, 'quote' => $quote, 'checkedAt' => Scc_Channel::now()]);
        if (Scc_Channel::secret() === '' || Scc_Channel::peer_url() === '') Scc_Channel::fail('חיבור לסיכום SHIMI טרם הוגדר.', 503);
        $home = Scc_Channel::peer('/api/channel/review', ['protocol' => 'shem-review-v1', 'summary' => $summary]);
        if (Scc_Channel::json($home['summary'] ?? null) !== Scc_Channel::json($summary) || ($home['verified'] ?? null) !== true) Scc_Channel::fail('סיכומי ההזמנה אינם תואמים. לא ניתן להמשיך.', 409);
        $expires = (string) ((int) floor(microtime(true) * 1000) + 300000);
        return self::out(['source' => $summary, 'home' => $home['summary'], 'verified' => true, 'quote' => $quote, 'checkedAt' => Scc_Channel::now(), 'reviewToken' => $expires . '.' . self::digest($expires, $summary)]);
    }
    private static function out(array $d, int $st = 200): Scc_Response { return Scc_Response::json($d, $st, ['cache-control' => 'no-store']); }

    /** SHIMI: verify the SHEM summary against the linked cards and SHEM availability. */
    public static function accept(array $data): array {
        $s = $data['summary'] ?? null;
        if (($data['protocol'] ?? '') !== 'shem-review-v1' || !is_array($s) || ($s['currency'] ?? '') !== 'ILS' || !is_array($s['items'] ?? null) || !$s['items'] || count($s['items']) > 100) Scc_Channel::fail('סיכום הזמנה לא תקין.', 400);
        $ids = array_values(array_unique(array_map(fn($i) => (string) ($i['productId'] ?? ''), $s['items'])));
        $links = [];
        foreach ($ids as $id) { $l = Scc_Channel_Links::find_by_source($id); if (!$l) Scc_Channel::fail('מוצר SHEM אינו מקושר ל-SHIMI.', 409); $links[$id] = $l; }
        $sources = Scc_Channel::peer('/api/channel/sources', ['ids' => $ids])['products'] ?? [];
        $synced = []; $quantities = [];
        foreach ($s['items'] as $i) {
            if (!is_int($i['qty'] ?? null) || $i['qty'] < 1 || $i['qty'] > 5) Scc_Channel::fail('כמות לא תקינה.', 400);
            $key = $i['productId'] . ':' . ($i['variantId'] ?? '') . ':' . ($i['size'] ?? '');
            if (isset($quantities[$key])) Scc_Channel::fail('פריט כפול בסיכום.', 400);
            $quantities[$key] = $i['qty'];
            $link = $links[$i['productId']];
            if (!isset($synced[$i['productId']])) {
                $source = null; foreach ($sources as $x) if ($x['id'] === $i['productId']) $source = $x;
                if (!$source) Scc_Channel::fail('מוצר מקור אינו זמין.', 409);
                $synced[$i['productId']] = Scc_Channel_Links::sync($link, $source);
            }
            $p = $synced[$i['productId']]['product']; $source = $synced[$i['productId']]['source'];
            if (!empty($p['archived']) || ($p['active'] ?? true) === false || ($source['active'] ?? true) === false) Scc_Channel::fail('מוצר מקושר אינו פעיל.', 409);
            $vm = (array) ($link['variantMap'] ?? []); $v = null; $sv = null;
            if (!empty($i['variantId'])) {
                foreach (($p['variants'] ?? []) as $x) if (($vm[$x['id']] ?? null) === $i['variantId']) $v = $x;
                foreach ($source['availability']['variants'] as $x) if ($x['id'] === $i['variantId']) $sv = $x;
            }
            if ((!empty($p['variants']) && !$v) || !in_array($i['size'] ?? '', $p['sizes'] ?? [], true) || ($v && $v['size'] !== $i['size'])) Scc_Channel::fail('מיפוי מידה וצבע אינו תואם.', 409);
            if (($v['sku'] ?? $p['sku'] ?? null) !== ($i['sku'] ?? null) || self::cents($p['price']) !== ($i['priceCents'] ?? null) || ($i['lineCents'] ?? null) !== $i['priceCents'] * $i['qty']) Scc_Channel::fail('מחיר או מק״ט אינם תואמים.', 409);
            $available = $sv['stock'] ?? ($source['availability']['sizeStock'][$i['size']] ?? $source['availability']['stock']);
            $total = 0; foreach ($s['items'] as $x) if ($x['productId'] === $i['productId']) $total += $x['qty'];
            if ($i['qty'] > $available || $total > $source['availability']['stock']) Scc_Channel::fail('אין מספיק מלאי במוצר המקושר.', 409);
        }
        $ok = true;
        foreach (['subtotalCents', 'discountCents', 'shippingCents', 'vatCents', 'netSubtotalCents', 'totalCents'] as $k) if (!is_int($s[$k] ?? null) || $s[$k] < 0) $ok = false;
        if ($ok) {
            $lines = 0; foreach ($s['items'] as $i) $lines += $i['lineCents'];
            if ($s['discountCents'] > $s['subtotalCents'] || $lines !== $s['subtotalCents'] || $s['totalCents'] !== $s['subtotalCents'] - $s['discountCents'] + $s['shippingCents']
                || $s['vatCents'] !== (int) floor((($s['subtotalCents'] - $s['discountCents']) * 18) / 118 + 0.5) || $s['netSubtotalCents'] + $s['vatCents'] !== $s['subtotalCents'] - $s['discountCents']
                || !in_array($s['shippingMethod'] ?? '', ['standard', 'express'], true) || ($s['vatRate'] ?? null) !== 18) $ok = false;
        }
        if (!$ok) Scc_Channel::fail('סכומי הסיכום אינם תואמים.', 409);
        // SHEM stays the sole coupon/shipping authority: no repricing through SHIMI's independent cart.
        return ['summary' => $s, 'verified' => true, 'selectedCount' => count($s['items'])];
    }
}
