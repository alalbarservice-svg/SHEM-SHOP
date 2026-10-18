<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/core-util.php';
require_once __DIR__ . '/core-products.php';

/**
 * Pricing and cart rules - 1:1 port of operations.js (quote) and
 * management.js (makeOrder, cleanCustomer). All money is integer agorot until the very last division, exactly like the worker.
 */
final class Scc_Core_Pricing {
    private static function fail(string $m, int $s = 400): never { throw new Scc_Http_Exception($m, $s); }

    /** operations.js records(): rows of a doc table, newest first, {...data, id, revision}. */
    public static function records(string $table, int $limit = 1000): array {
        global $wpdb; $out = [];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT id,data,updated_at FROM ' . Scc_Docs::t($table) . ' ORDER BY updated_at DESC LIMIT %d', $limit), ARRAY_A) ?: [] as $r) {
            $d = json_decode($r['data'], true);
            $out[] = (is_array($d) ? $d : []) + ['id' => $r['id'], 'revision' => $r['updated_at']];
            $last = count($out) - 1; $out[$last]['id'] = $r['id']; $out[$last]['revision'] = $r['updated_at'];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ quote */
    /** @param mixed $d decoded request body. @return array the /api/quote response (before JSON encoding). */
    public static function quote(mixed $d): array {
        $d = is_array($d) ? $d : [];
        $lines = $d['items'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || !$lines || count($lines) > 100) self::fail('הסל אינו תקין.');
        $ids = [];
        foreach ($lines as $l) if (is_array($l) && isset($l['id']) && is_string($l['id'])) $ids[$l['id']] = true;
        $products = $ids ? Scc_Core::hydrated(array_keys($ids)) : [];
        $find = function (string $id) use ($products) { foreach ($products as $p) if (($p['id'] ?? null) === $id) return $p; return null; };
        $subtotal = 0; $items = []; $quantities = [];
        foreach ($lines as $line) {
            $line = is_array($line) ? $line : [];
            $p = isset($line['id']) && is_string($line['id']) ? $find($line['id']) : null;
            if ($p && (($p['active'] ?? true) === false || Scc_Core_Util::truthy($p['archived'] ?? false))) $p = null;
            $qty = $line['qty'] ?? null;
            if (!$p || !in_array($line['size'] ?? null, is_array($p['sizes'] ?? null) ? $p['sizes'] : [], true)
                || !Scc_Core_Util::is_int_val($qty) || $qty < 1 || $qty > 5) self::fail('פריט או כמות אינם זמינים.');
            $qty = (int) $qty;
            $variant = null;
            foreach ((is_array($p['variants'] ?? null) ? $p['variants'] : []) as $v) if (($v['id'] ?? null) === ($line['variantId'] ?? null)) { $variant = $v; break; }
            if (!empty($p['variants']) && (!$variant || ($variant['size'] ?? null) !== $line['size'])) self::fail('יש לבחור גרסת מידה וצבע תקינה.');
            $vk = $line['variantId'] ?? ''; $vk = Scc_Core_Util::truthy($vk) ? (is_scalar($vk) ? (string) $vk : '') : '';
            $key = $p['id'] . ':' . (is_scalar($line['size']) ? (string) $line['size'] : '') . ':' . $vk;
            if (isset($quantities[$key])) self::fail('פריט כפול בסל.');
            $quantities[$key] = $qty;
            $sizeStock = $variant['stock'] ?? (is_array($p['sizeStock'] ?? null) ? ($p['sizeStock'][$line['size']] ?? null) : null) ?? ($p['stock'] ?? 0);
            if ($qty > $sizeStock) self::fail('אין מספיק מלאי במידה שנבחרה.');
            $productQty = 0;
            foreach ($lines as $l) if (is_array($l) && ($l['id'] ?? null) === $p['id'] && isset($l['qty']) && is_numeric($l['qty'])) $productQty += $l['qty'];
            if ($productQty > ($p['stock'] ?? 0)) self::fail('אין מספיק מלאי לפריט.');
            $row = $line; $row['price'] = $p['price']; $row['he'] = $p['he'] ?? null;
            $items[] = $row;
            $subtotal += Scc_Core_Util::amount($p['price']) * $qty;
        }
        $discount = 0; $promotion = null;
        $code = mb_strtoupper(Scc_Core_Util::clean($d['code'] ?? null, 40));
        if ($code !== '') {
            $promo = null;
            foreach (self::records('promotions') as $r) if (($r['code'] ?? null) === $code && Scc_Core_Util::truthy($r['active'] ?? null) && !Scc_Core_Util::truthy($r['archived'] ?? null)) { $promo = $r; break; }
            $today = Scc_Core_Util::today_jerusalem();
            if (!$promo || (Scc_Core_Util::truthy($promo['startsAt'] ?? null) && strcmp((string) $promo['startsAt'], $today) > 0) || (Scc_Core_Util::truthy($promo['endsAt'] ?? null) && strcmp((string) $promo['endsAt'], $today) < 0))
                self::fail('הקוד אינו פעיל או שפג תוקפו.');
            $min = Scc_Core_Util::truthy($promo['minSubtotal'] ?? null) ? Scc_Core_Util::num($promo['minSubtotal']) : 0;
            if ($subtotal / 100 < $min) self::fail('סכום הסל נמוך מהמינימום לקוד.');
            $eligible = 0;
            foreach ($items as $i) {
                $pp = $find((string) ($i['id'] ?? ''));
                if (!Scc_Core_Util::truthy($promo['excludeSale'] ?? null) || !Scc_Core_Util::truthy($pp['previousPrice'] ?? null)) $eligible += Scc_Core_Util::amount($i['price']) * (int) $i['qty'];
            }
            if (!$eligible) self::fail('הקוד אינו חל על הפריטים שבסל.');
            $pct = Scc_Core_Util::num($promo['discount'] ?? 0);
            $discount = (int) min($eligible, Scc_Core_Util::js_round(($eligible * $pct) / 100));
            $promotion = ['code' => $promo['code'], 'discount' => $promo['discount'] ?? null];
        }
        $method = $d['shipping'] ?? null; $method = Scc_Core_Util::truthy($method) ? $method : 'standard';
        if (!in_array($method, ['standard', 'express'], true)) self::fail('שיטת משלוח לא תקינה.');
        $op = Scc_Core_Util::setting('operations');
        $shipping = ($d['shipping'] ?? null) === 'express' ? ($op['expressPrice'] ?? 199) : ($op['standardPrice'] ?? 49);
        $net = $subtotal - $discount;
        return [
            'items' => $items,
            'subtotal' => $subtotal / 100,
            'discount' => $discount / 100,
            'shipping' => $shipping,
            'vatRate' => 18,
            'vatIncluded' => Scc_Core_Util::js_round(($net * 18) / 118) / 100,
            'netSubtotal' => ($net - Scc_Core_Util::js_round(($net * 18) / 118)) / 100,
            'total' => $net / 100 + $shipping,
            'promotion' => $promotion,
            'quotedAt' => Scc_Core_Util::now_iso(),
            'paymentConnected' => false,
        ];
    }

    /* ------------------------------------------------------------------ order draft (management.js makeOrder) */
    public static function clean_customer(mixed $c): array {
        $c = Scc_Core_Util::obj($c);
        $out = [
            'name' => Scc_Core_Util::clean($c['name'] ?? null, 150), 'email' => strtolower(Scc_Core_Util::clean($c['email'] ?? null, 254)),
            'phone' => Scc_Core_Util::clean($c['phone'] ?? null, 40), 'city' => Scc_Core_Util::clean($c['city'] ?? null, 150),
            'street' => Scc_Core_Util::clean($c['street'] ?? null, 300), 'apartment' => Scc_Core_Util::clean($c['apartment'] ?? null, 50),
            'postcode' => Scc_Core_Util::clean($c['postcode'] ?? null, 30),
        ];
        if ($out['name'] === '' || !Scc_Core_Util::is_email($out['email'])) self::fail('שם ואימייל תקינים נדרשים.');
        return $out;
    }
    public static function shipping_label(array $d): string {
        $l = $d['shippingLabel'] ?? null;
        if (Scc_Core_Util::truthy($l) && is_scalar($l)) return mb_substr((string) $l, 0, 300);
        $m = $d['shippingMethod'] ?? null;
        $map = ['standard' => 'שליח עד הבית - 5-7 ימי עסקים', 'express' => 'שליח עד הבית - יום עסקים אחד'];
        if (is_string($m) && isset($map[$m])) return $map[$m];
        if (Scc_Core_Util::truthy($m) && is_scalar($m)) return (string) $m;
        return 'שליח עד הבית';
    }
    /**
     * makeOrder(): validates against the current catalogue and returns the legacy order draft (key order preserved).
     * @param array $d customer, items[{productId|id, variantId, size, qty}], shippingCost (shekel), discount (shekel), shippingMethod, shippingLabel?, notes?
     */
    public static function make_order(array $d): array {
        $customer = self::clean_customer($d['customer'] ?? []);
        $in = $d['items'] ?? null;
        if (!is_array($in) || !array_is_list($in) || !$in || count($in) > 100) self::fail('יש להוסיף פריטים להזמנה.');
        $ids = []; foreach ($in as $x) if (is_array($x)) { $pid = $x['productId'] ?? ($x['id'] ?? null); if (is_string($pid)) $ids[$pid] = true; }
        $products = $ids ? Scc_Core::overlaid(array_keys($ids)) : [];
        $items = []; $seen = [];
        foreach ($in as $x) {
            $x = is_array($x) ? $x : [];
            $pid = $x['productId'] ?? null; $p = null;
            foreach ($products as $c) if (($c['id'] ?? null) === $pid && !Scc_Core_Util::truthy($c['archived'] ?? false)) { $p = $c; break; }
            $qty = $x['qty'] ?? null;
            $qn = is_numeric($qty) ? (float) $qty : NAN;
            if (!$p || !in_array($x['size'] ?? null, is_array($p['sizes'] ?? null) ? $p['sizes'] : [], true) || !is_finite($qn) || floor($qn) != $qn || $qn < 1 || $qn > 1000)
                self::fail('פריט, מידה או כמות אינם תקינים.');
            $variant = null;
            foreach ((is_array($p['variants'] ?? null) ? $p['variants'] : []) as $v) if (($v['id'] ?? null) === ($x['variantId'] ?? null)) { $variant = $v; break; }
            if (!empty($p['variants']) && !$variant) self::fail('יש לבחור גרסת מוצר.');
            if ($variant && ($variant['size'] ?? null) !== $x['size']) self::fail('המידה אינה תואמת לגרסה.');
            $key = $p['id'] . ':' . ($variant['id'] ?? $x['size']);
            if (isset($seen[$key])) self::fail('יש לאחד פריטים כפולים.');
            $seen[$key] = true;
            $items[] = [
                'productId' => $p['id'], 'variantId' => $variant['id'] ?? '', 'name' => $p['he'] ?? null,
                'sku' => $variant['sku'] ?? ($p['sku'] ?? null), 'homeSku' => ($variant['homeSku'] ?? null) ?: (($p['homeSku'] ?? null) ?: ''),
                'size' => $x['size'], 'color' => $variant['color'] ?? ($p['color'] ?? null), 'qty' => (int) $qn, 'priceCents' => Scc_Core_Util::amount($p['price']),
            ];
        }
        $items = array_map(fn($r) => array_filter($r, fn($v) => $v !== null), $items);
        $subtotal = 0; foreach ($items as $i) $subtotal += $i['qty'] * $i['priceCents'];
        $ship = Scc_Core_Util::amount($d['shippingCost'] ?? 49);
        $disc = Scc_Core_Util::amount($d['discount'] ?? 0);
        if ($ship < 0 || $ship > 100000 || $disc < 0 || $disc > $subtotal) self::fail('משלוח או הנחה אינם תקינים.');
        $total = $subtotal + $ship - $disc;
        $method = Scc_Core_Util::clean($d['shippingMethod'] ?? null, 500);
        return [
            'customer' => $customer, 'items' => $items, 'subtotalCents' => $subtotal, 'shippingCents' => $ship, 'discountCents' => $disc, 'vatRate' => 18,
            'vatItemsCents' => Scc_Core_Util::ratio_round($subtotal - $disc, 18, 118), 'vatShippingCents' => Scc_Core_Util::ratio_round($ship, 18, 118),
            'shippingLabel' => self::shipping_label($d), 'totalCents' => $total, 'total' => $total / 100,
            'shippingMethod' => $method !== '' ? $method : 'standard', 'notes' => Scc_Core_Util::clean($d['notes'] ?? null, 2000),
        ];
    }
}
