<?php
/** Signed shipping-provider webhook: POST /api/shipment-events. Port of shipmentEvent() in SHEM/worker/customer-experience.js (HMAC-SHA256 over "<ms timestamp>.<raw body>"). */
if (!defined('ABSPATH')) exit;
if (SCC_ROLE !== 'shem') return;
require_once __DIR__ . '/cx-base.php';

final class Scc_Cx_Shipment {
    /** Secret lives in wp-config.php as SHIPPING_WEBHOOK_SECRET; never in the database. */
    public static function secret(): string { return defined('SHIPPING_WEBHOOK_SECRET') ? (string) SHIPPING_WEBHOOK_SECRET : ''; }

    private static function err(string $m, int $s): Scc_Response { return Scc_Cx::reply(['error' => $m], $s); }

    public static function handle(Scc_Request $r): Scc_Response {
        global $wpdb;
        $secret = self::secret();
        if ($secret === '') return self::err('חיבור עדכוני משלוח עדיין אינו פעיל.', 503);
        $raw = $r->raw;
        if (strlen($raw) > 20000) return self::err('Request too large', 413);
        $ts = $r->header('x-shem-timestamp'); $sig = $r->header('x-shem-signature');
        if (!preg_match('/^\d{13}$/', $ts) || abs(microtime(true) * 1000 - (float) $ts) > 300000 || !preg_match('/^[0-9a-f]{64}$/i', $sig))
            return self::err('Invalid signature', 401);
        if (!hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $secret), strtolower($sig))) return self::err('Invalid signature', 401);
        $d = json_decode($raw, true);
        if ($d === null && json_last_error() !== JSON_ERROR_NONE) return self::err('Invalid JSON', 400);
        $d = is_array($d) ? $d : [];
        $eventId = Scc_Cx::text($d['eventId'] ?? null, 100); $orderId = Scc_Cx::text($d['orderId'] ?? null, 100);
        $status = $d['status'] ?? null;
        if ($eventId === '' || $orderId === '' || !in_array($status, ['processing', 'shipped', 'completed'], true)) return self::err('Invalid event', 400);
        if (Scc_Docs::get('cx_shipment_events', $eventId) !== null) return Scc_Cx::reply(['ok' => true, 'duplicate' => true]);

        $lockName = 'ship:' . $orderId;
        if (!Scc_Cx::lock($lockName, 5)) return self::err('Order changed; retry event', 409);
        $evTable = Scc_Docs::t('cx_shipment_events'); $inserted = false;
        try {
            if (Scc_Docs::get('cx_shipment_events', $eventId) !== null) return Scc_Cx::reply(['ok' => true, 'duplicate' => true]);
            $old = Scc_Cx_Orders::get($orderId);
            if (!$old || ($old['paymentStatus'] ?? null) !== 'paid' || ($old['status'] ?? null) === 'cancelled') return self::err('Order unavailable', 409);
            $trackingUrl = Scc_Cx::text($d['trackingUrl'] ?? null, 500);
            if ($trackingUrl !== '') {
                $u = wp_parse_url($trackingUrl);
                if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || isset($u['user']) || isset($u['pass'])) return self::err('Invalid tracking URL', 400);
            }
            $eta = $d['estimatedDelivery'] ?? null;
            if (Scc_Cx::truthy($eta) && (!is_string($eta) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eta) || strtotime($eta . ' UTC') === false || gmdate('Y-m-d', strtotime($eta . ' UTC')) !== $eta))
                return self::err('Invalid delivery date', 400);
            $rank = ['new' => 0, 'processing' => 1, 'shipped' => 2, 'completed' => 3];
            $oldKey = Scc_Cx::truthy($old['status'] ?? null) ? $old['status'] : 'new';
            $new = $rank[$status] >= ($rank[$oldKey] ?? 0) ? $status : ($old['status'] ?? null);

            $updated = $old; unset($updated['id'], $updated['_wcId']);
            $updated['shipmentEventId'] = $eventId;
            $updated['status'] = $new;
            foreach ([['carrier', Scc_Cx::text($d['carrier'] ?? null, 100)], ['tracking', Scc_Cx::text($d['tracking'] ?? null, 200)], ['trackingUrl', $trackingUrl], ['estimatedDelivery', Scc_Cx::text($d['estimatedDelivery'] ?? null, 10)]] as [$k, $v]) {
                if ($v !== '') $updated[$k] = $v; elseif (!array_key_exists($k, $old)) unset($updated[$k]);
            }
            $hist = (array) ($old['shipmentHistory'] ?? []);
            if ($new !== ($old['status'] ?? null)) $hist[] = ['status' => $new, 'at' => Scc_Cx::stamp()];
            $updated['shipmentHistory'] = array_slice($hist, -30);
            $expected = (string) $old['updatedAt']; unset($updated['updatedAt']);

            // Insert (not REPLACE): the primary key makes the event id single-use, even across lock timeouts.
            $inserted = (bool) $wpdb->insert($evTable, ['id' => $eventId, 'data' => wp_json_encode(['orderId' => $orderId, 'createdAt' => Scc_Cx::stamp()], JSON_UNESCAPED_UNICODE), 'updated_at' => gmdate('c')]);
            if (!$inserted) return Scc_Cx::reply(['ok' => true, 'duplicate' => true]);
            $saved = Scc_Cx_Orders::save($orderId, $updated, $expected);
            if ($saved === null) { $wpdb->delete($evTable, ['id' => $eventId]); $inserted = false; return self::err('Order changed; retry event', 409); }
            Scc_Cx::audit('shipment-integration', 'עדכון משלוח מאומת להזמנה ' . $orderId);
            return Scc_Cx::reply(['ok' => true, 'status' => $new]);
        } catch (Throwable $e) {
            if ($inserted) $wpdb->delete($evTable, ['id' => $eventId]);
            error_log('[scc-cx] shipment: ' . $e->getMessage());
            return self::err('Order changed; retry event', 409);
        } finally { Scc_Cx::unlock($lockName); }
    }
}

// Original dispatches this path for any method; non-POST answers 405.
Scc_Router::add('*', '#^/api/shipment-events$#', function (Scc_Request $r) {
    if ($r->method !== 'POST') return Scc_Cx::reply(['error' => 'Method not allowed'], 405);
    return Scc_Cx_Shipment::handle($r);
}, 'hmac');
