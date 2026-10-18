<?php
/** SHEM customer account API: /api/customer/me, orders, invoice, watches, preferences. Port of customerExperience() in SHEM/worker/customer-experience.js. */
if (!defined('ABSPATH')) exit;
if (SCC_ROLE !== 'shem') return;
require_once __DIR__ . '/cx-base.php';

final class Scc_Cx_Customer {
    /** Customer-safe view of an order doc (no viewToken, notes or internal fields). */
    public static function order_view(array $o): array {
        $out = [
            'id' => $o['id'],
            'createdAt' => Scc_Cx::truthy($o['createdAt'] ?? null) ? $o['createdAt'] : ($o['updatedAt'] ?? null),
            'updatedAt' => $o['updatedAt'] ?? null,
        ];
        foreach (['status', 'paymentStatus', 'customer'] as $k) if (array_key_exists($k, $o)) $out[$k] = $o[$k];
        $items = [];
        foreach ((array) ($o['items'] ?? []) as $i) {
            $i = is_array($i) ? $i : [];
            $row = [];
            foreach (['name', 'sku', 'size', 'color', 'qty', 'priceCents', 'lineCents'] as $k) if (array_key_exists($k, $i)) $row[$k] = $i[$k];
            $items[] = $row;
        }
        $out['items'] = $items;
        $total = $o['totalCents'] ?? null;
        $out['totalCents'] = Scc_Cx::truthy($total) ? $total : Scc_Cx::intify(round(Scc_Cx::num($o['total'] ?? 0) * 100));
        foreach (['subtotalCents', 'discountCents', 'shippingCents', 'shippingMethod'] as $k) if (array_key_exists($k, $o)) $out[$k] = $o[$k];
        $out['tracking'] = Scc_Cx::truthy($o['tracking'] ?? null) ? $o['tracking'] : '';
        $out['carrier'] = Scc_Cx::truthy($o['carrier'] ?? null) ? $o['carrier'] : '';
        $tu = $o['trackingUrl'] ?? '';
        $out['trackingUrl'] = is_string($tu) && preg_match('#^https://#', $tu) ? $tu : '';
        $out['estimatedDelivery'] = Scc_Cx::truthy($o['estimatedDelivery'] ?? null) ? $o['estimatedDelivery'] : '';
        $hist = [];
        foreach ((array) ($o['shipmentHistory'] ?? []) as $h) {
            $h = is_array($h) ? $h : [];
            $hist[] = ['status' => Scc_Cx::text($h['status'] ?? null, 100), 'at' => Scc_Cx::text($h['at'] ?? null, 40)];
        }
        $out['shipmentHistory'] = $hist;
        $out['invoiceReady'] = ($o['paymentStatus'] ?? null) === 'paid' && Scc_Cx_Invoice::ready($o);
        return $out;
    }

    /** Ownership: signed-in email equals the order email, or the private bearer token equals order.viewToken. 404 otherwise. */
    public static function owned(Scc_Request $r, string $id): array {
        $order = Scc_Cx_Orders::get($id);
        $user = Scc_Cx::identity($r);
        $token = Scc_Cx::bearer($r);
        $ok = false;
        if ($order) {
            $emailMatch = $user['id'] !== '' && $user['email'] !== '' && $user['email'] === strtolower(Scc_Cx::text($order['customer']['email'] ?? null, 254));
            $vt = $order['viewToken'] ?? null;
            $tokenMatch = $token !== '' && is_string($vt) && $vt !== '' && hash_equals($vt, $token);
            $ok = $emailMatch || $tokenMatch;
        }
        if (!$ok) Scc_Cx::fail('לא ניתן לפתוח את ההזמנה. בדקו את הקישור האישי או התחברו לחשבון הרכישה.', 404);
        return $order;
    }

    public static function me(Scc_Request $r): Scc_Response {
        global $wpdb;
        $user = Scc_Cx::identity($r);
        if ($user['id'] === '' || $user['email'] === '') return Scc_Cx::reply(['signedIn' => false, 'orders' => [], 'notifications' => []]);
        $orders = array_map([self::class, 'order_view'], Scc_Cx_Orders::by_email($user['email'], 100));

        $member = null; $memberId = null;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,data FROM ' . Scc_Docs::t('member_profiles') . ' WHERE LOWER(' . Scc_Cx::json_col('email') . ')=%s ORDER BY updated_at DESC', $user['email']), ARRAY_A);
        foreach ($rows as $m) { $d = json_decode($m['data'], true) ?: []; if (!Scc_Cx::archived($d)) { $member = $d; $memberId = $m['id']; break; } }
        $rules = Scc_Cx::setting('loyalty');
        $balance = 0; $pending = 0; $now = Scc_Cx::stamp();
        if ($member) {
            foreach ($wpdb->get_col($wpdb->prepare('SELECT data FROM ' . Scc_Docs::t('loyalty_ledger') . ' WHERE ' . Scc_Cx::json_col('memberId') . '=%s', $memberId)) as $raw) {
                $e = json_decode($raw, true) ?: [];
                if (Scc_Cx::truthy($e['expiresAt'] ?? null) && strcmp((string) $e['expiresAt'], $now) <= 0) continue;
                $delta = Scc_Cx::num($e['deltaCents'] ?? 0);
                if (Scc_Cx::truthy($e['availableAt'] ?? null) && strcmp((string) $e['availableAt'], $now) > 0) $pending += $delta; else $balance += $delta;
            }
        }

        $watchRows = $wpdb->get_results($wpdb->prepare('SELECT id,data FROM ' . Scc_Docs::t('cx_watches') . ' WHERE ' . Scc_Cx::json_col('userId') . '=%s ORDER BY updated_at DESC LIMIT 100', $user['id']), ARRAY_A);
        $experience = Scc_Cx::setting('experience');
        $notifications = []; $watches = [];
        foreach ($watchRows as $wr) {
            $w = json_decode($wr['data'], true) ?: [];
            unset($w['userId']);
            $watches[] = ['id' => $wr['id']] + $w;
            $raw = Scc_Cx::row('products', (string) ($w['productId'] ?? ''));
            $p = $raw ? Scc_Cx::hydrate_one($raw) : null;
            if (!$p) continue;
            if (($p['active'] ?? null) === false || Scc_Cx::truthy($p['archived'] ?? null)) continue;
            $size = $w['size'] ?? '';
            if (Scc_Cx::truthy($w['variantId'] ?? null)) {
                $stock = null;
                foreach ((array) ($p['variants'] ?? []) as $v) if (is_array($v) && ($v['id'] ?? null) === $w['variantId']) { $stock = $v['stock'] ?? null; break; }
                $available = $stock !== null && Scc_Cx::num($stock) > 0;
            } else {
                $stock = isset($p['sizeStock'][$size]) ? $p['sizeStock'][$size] : ($p['stock'] ?? null);
                $available = $stock !== null && Scc_Cx::num($stock) > 0;
            }
            if (!$available) continue;
            $n = ['id' => $wr['id'], 'productId' => $p['id']];
            if (array_key_exists('he', $p)) $n['title'] = $p['he'];
            if (array_key_exists('size', $w)) $n['size'] = $w['size'];
            if (array_key_exists('color', $w)) $n['color'] = $w['color'];
            $n['kind'] = 'back-in-stock';
            $notifications[] = $n;
        }
        $pr = Scc_Docs::get('cx_preferences', $user['id']);
        $siteOn = ($experience['siteAlertsEnabled'] ?? null) !== false && (!$pr || ($pr['siteNotifications'] ?? null) !== false);
        return Scc_Cx::reply([
            'signedIn' => true,
            'email' => $user['email'],
            'orders' => $orders,
            'notifications' => $siteOn ? $notifications : [],
            'watches' => $watches,
            'member' => $member ? [
                'tier' => $member['tier'] ?? null, 'status' => $member['status'] ?? null, 'cardNumber' => $member['cardNumber'] ?? null,
                'balanceCents' => Scc_Cx::intify(max(0, $balance)), 'pendingCents' => Scc_Cx::intify(max(0, $pending)),
                'benefits' => Scc_Cx::truthy($rules['benefits'] ?? null) ? $rules['benefits'] : '',
                'enabled' => ($rules['enabled'] ?? null) === true,
            ] : null,
            'preferences' => $pr ?: ['siteNotifications' => true],
        ]);
    }

    public static function order(Scc_Request $r): Scc_Response {
        $order = self::owned($r, (string) $r->params[1]);
        if (empty($r->params[2])) return Scc_Cx::reply(self::order_view($order));
        if (($order['paymentStatus'] ?? null) !== 'paid') Scc_Cx::fail('החשבונית תהיה זמינה לאחר אישור התשלום.', 409);
        if (!Scc_Cx_Invoice::ready($order)) Scc_Cx::fail('המסמך עדיין בהכנה. אפשר לרענן בעוד רגע.', 409);
        $bytes = Scc_Cx_Invoice::pdf($order);
        if ($bytes === null) Scc_Cx::fail('המסמך עדיין בהכנה. אפשר לרענן בעוד רגע.', 409);
        $disp = (($r->query['download'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="SHEM-invoice-' . preg_replace('/[^a-zA-Z0-9-]/', '', (string) $order['id']) . '.pdf"';
        return Scc_Response::raw($bytes, 'application/pdf', 200, ['content-disposition' => $disp, 'cache-control' => 'private,no-store', 'referrer-policy' => 'no-referrer']);
    }

    public static function preferences(Scc_Request $r): Scc_Response {
        Scc_Cx::guard_mutation($r);
        $user = Scc_Cx::require_identity($r);
        $d = Scc_Cx::parse($r);
        $p = ['siteNotifications' => ($d['siteNotifications'] ?? null) !== false];
        Scc_Docs::put('cx_preferences', $user['id'], $p);
        return Scc_Cx::reply($p);
    }

    public static function watch_add(Scc_Request $r): Scc_Response {
        global $wpdb;
        Scc_Cx::guard_mutation($r);
        $user = Scc_Cx::require_identity($r);
        $d = Scc_Cx::parse($r);
        $p = Scc_Cx::row('products', Scc_Cx::text($d['productId'] ?? null, 100));
        if ((Scc_Cx::setting('experience')['siteAlertsEnabled'] ?? null) === false) Scc_Cx::fail('התראות חזרה למלאי אינן פעילות כרגע.', 409);
        if (!$p || Scc_Cx::truthy($p['archived'] ?? null) || ($p['active'] ?? null) === false) Scc_Cx::fail('הפריט אינו זמין.', 404);
        $variantId = Scc_Cx::text($d['variantId'] ?? null, 100);
        $size = Scc_Cx::text($d['size'] ?? null, 20);
        $v = null;
        foreach ((array) ($p['variants'] ?? []) as $x) if (is_array($x) && ($x['id'] ?? null) === $variantId) { $v = $x; break; }
        $hasVariants = !empty($p['variants']);
        if (($hasVariants && !$v) || !in_array($size, (array) ($p['sizes'] ?? []), true) || ($v && ($v['size'] ?? null) !== $size))
            Scc_Cx::fail('יש לבחור מידה וצבע להתראה.');
        $id = $user['id'] . ':' . $p['id'] . ':' . ($variantId !== '' ? $variantId : $size);
        $value = ['userId' => $user['id'], 'productId' => $p['id'], 'variantId' => $variantId, 'size' => $size];
        $color = Scc_Cx::truthy($v['color'] ?? null) ? $v['color'] : ($p['color'] ?? null);
        if (Scc_Cx::truthy($color) || array_key_exists('color', $p)) $value['color'] = $color;
        $value['createdAt'] = Scc_Cx::stamp();
        $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Scc_Docs::t('cx_watches') . ' WHERE ' . Scc_Cx::json_col('userId') . '=%s', $user['id']));
        if ($count >= 100 && !Scc_Docs::get('cx_watches', $id)) Scc_Cx::fail('ניתן לשמור עד מאה התראות.', 429);
        Scc_Docs::put('cx_watches', $id, $value);
        return Scc_Cx::reply(['ok' => true], 201);
    }

    public static function watch_delete(Scc_Request $r): Scc_Response {
        Scc_Cx::guard_mutation($r);
        $user = Scc_Cx::require_identity($r);
        $id = (string) $r->params[1];
        $w = Scc_Docs::get('cx_watches', $id);
        if ($w && (string) ($w['userId'] ?? '') === $user['id']) Scc_Docs::delete('cx_watches', $id);
        return Scc_Cx::reply(['ok' => true]);
    }
}

Scc_Router::add('GET', '#^/api/customer/me$#', [Scc_Cx_Customer::class, 'me']);
Scc_Router::add('GET', '#^/api/customer/orders/([^/]+)(/invoice)?$#', [Scc_Cx_Customer::class, 'order']);
Scc_Router::add('PUT', '#^/api/customer/preferences$#', [Scc_Cx_Customer::class, 'preferences']);
Scc_Router::add('POST', '#^/api/customer/watches$#', [Scc_Cx_Customer::class, 'watch_add']);
Scc_Router::add('DELETE', '#^/api/customer/watches/(.*)$#', [Scc_Cx_Customer::class, 'watch_delete']);
