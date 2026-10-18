<?php
if (!defined('ABSPATH')) exit;
/**
 * Central stock authority (SHEM): 20 minute reservations + one-time stock ledger.
 *  - reserve(): atomic. Per product a lock row (SELECT .. FOR UPDATE, stable order) serialises writers; availability =
 *    fresh stock - unexpired holds; a hold that does not fit is rejected, so two parallel buyers can never oversell.
 *  - commit(): exactly once. Reservation rows flip held->committed inside the same transaction that decrements stock; the
 *    UNIQUE ledger movement_key is a second guard. A replay returns {ok:true,duplicate:true} and never decrements again.
 *  - Woo-owned orders (own checkout of either store): WooCommerce itself decrements on payment_complete; we only flip the
 *    holds to committed and write an informational ledger row (applied_by=woocommerce, same movement key) - see channel-orders.php.
 */
final class Scc_Channel_Stock {
    const HOLD_SECONDS = 1200;
    public static function t(string $n): string { return Scc_Channel_Tx::t($n); }

    /* ---------- reads ---------- */
    /** @return array<int,array{product_id:string,variant_id:string,size:string,qty:int,state:string,expires_at:int}> */
    public static function rows(string $id, bool $lock = false): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT product_id,variant_id,size,qty,state,expires_at FROM ' . self::t('reservations') . ' WHERE reservation_id=%s ORDER BY product_id,variant_id,size' . ($lock ? ' FOR UPDATE' : ''), $id), ARRAY_A) ?: [];
    }
    /** Unexpired holds on a product grouped by variant/size, optionally without one reservation. */
    public static function holds(string $product_id, string $exclude = ''): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT variant_id,size,SUM(qty) qty FROM ' . self::t('reservations') . " WHERE product_id=%s AND state='held' AND expires_at>%d AND reservation_id<>%s GROUP BY variant_id,size", $product_id, Scc_Channel::ts(), $exclude), ARRAY_A) ?: [];
    }
    /** All unexpired holds, grouped per product (one query for a catalogue page). */
    public static function holds_all(): array {
        global $wpdb; $out = [];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT product_id,variant_id,size,SUM(qty) qty FROM ' . self::t('reservations') . " WHERE state='held' AND expires_at>%d GROUP BY product_id,variant_id,size", Scc_Channel::ts()), ARRAY_A) ?: [] as $r) $out[$r['product_id']][] = $r;
        return $out;
    }
    /** applyHolds(): subtract holds from the stock numbers of a product document. */
    public static function apply_holds(array $p, array $holds): array {
        $total = array_sum(array_map(fn($r) => (int) $r['qty'], $holds));
        $p['stock'] = max(0, (int) $p['stock'] - $total);
        if (isset($p['sizeStock']) && is_array($p['sizeStock'])) {
            foreach ($p['sizeStock'] as $s => $n) {
                $h = 0; foreach ($holds as $r) if ($r['size'] === (string) $s) $h += (int) $r['qty'];
                $p['sizeStock'][$s] = max(0, (int) $n - $h);
            }
        }
        $p['variants'] = array_map(function ($v) use ($holds) {
            $h = 0; foreach ($holds as $r) if ($r['variant_id'] === $v['id']) $h += (int) $r['qty'];
            $v['stock'] = max(0, (int) $v['stock'] - $h);
            return $v;
        }, $p['variants'] ?? []);
        return $p;
    }
    public static function view(array $p, string $exclude = ''): array { return self::apply_holds($p, self::holds((string) $p['id'], $exclude)); }

    /* ---------- reserve / release ---------- */
    private static function norm(array $items): array {
        $out = [];
        foreach ($items as $x) {
            $out[] = ['productId' => (string) ($x['productId'] ?? $x['product_id'] ?? ''), 'variantId' => (string) ($x['variantId'] ?? $x['variant_id'] ?? ''),
                'size' => (string) ($x['size'] ?? ''), 'qty' => $x['qty'] ?? null, 'priceCents' => $x['priceCents'] ?? null];
        }
        return $out;
    }
    /** Merge duplicate (product,variant,size) keys; stable sort - the PK of the reservation row. */
    private static function merged(array $items): array {
        $m = [];
        foreach ($items as $x) { $k = $x['productId'] . "\x1f" . $x['variantId'] . "\x1f" . $x['size']; if (!isset($m[$k])) $m[$k] = $x + ['qty' => 0]; else $m[$k]['qty'] = (int) $m[$k]['qty'] + (int) $x['qty']; }
        ksort($m, SORT_STRING);
        return array_values($m);
    }
    private static function canonical(array $rows): string {
        $a = array_map(fn($x) => [(string) ($x['productId'] ?? $x['product_id']), (string) ($x['variantId'] ?? $x['variant_id'] ?? ''), (string) ($x['size'] ?? ''), (int) $x['qty']], $rows);
        usort($a, fn($x, $y) => strcmp(Scc_Channel::json($x), Scc_Channel::json($y)));
        // Different spellings of the same cart (split lines) must compare equal: merge by key.
        $m = []; foreach ($a as $x) { $k = $x[0] . "\x1f" . $x[1] . "\x1f" . $x[2]; $m[$k] = ($m[$k] ?? 0) + $x[3]; }
        ksort($m, SORT_STRING);
        return Scc_Channel::json($m);
    }
    /** Returns true when the reservation already exists unchanged and is still valid. Throws on conflict / expiry. */
    private static function existing_ok(string $id, array $existing, array $items): bool {
        if (!$existing) return false;
        if (self::canonical($existing) !== self::canonical($items)) Scc_Channel::fail('מזהה הזמנה כבר משמש לסל אחר.');
        $ok = true;
        foreach ($existing as $x) if (!($x['state'] === 'committed' || ($x['state'] === 'held' && (int) $x['expires_at'] > Scc_Channel::ts()))) $ok = false;
        if ($ok) return true;
        Scc_Channel::fail('שמירת המלאי פגה. יש להתחיל הזמנה חדשה.');
    }
    public static function line_stock(array $p, array $line): int {
        foreach (($p['variants'] ?? []) as $v) if ($v['id'] === $line['variantId']) return (int) $v['stock'];
        return (int) (($p['sizeStock'] ?? [])[$line['size']] ?? $p['stock']);
    }
    public static function reserve(string $id, array $items): void {
        global $wpdb;
        if (!$items) return;
        $items = self::norm($items);
        if (self::existing_ok($id, self::rows($id), $items)) return;
        // Validation of every line (port of reserveStock) - reads only.
        $cat = Scc_Channel_Catalog::backend(); $seen = [];
        foreach ($items as $line) {
            $p = $seen[$line['productId']] ?? ($seen[$line['productId']] = $cat->get($line['productId']));
            if (!$p || !empty($p['archived']) || ($p['active'] ?? true) === false) Scc_Channel::fail('מוצר אינו זמין.');
            if (!is_int($line['qty']) || $line['qty'] < 1 || $line['qty'] > 100) Scc_Channel::fail('כמות אינה תקינה.');
            $v = null; foreach (($p['variants'] ?? []) as $x) if ($x['id'] === $line['variantId']) $v = $x;
            if ((!empty($p['variants']) && (!$v || $v['size'] !== $line['size'])) || !Scc_Channel_Catalog::size_ok($p, $line['size'])) Scc_Channel::fail('גרסת מוצר אינה תואמת.');
            if ($line['priceCents'] !== null && (int) $line['priceCents'] !== (int) round($p['price'] * 100)) Scc_Channel::fail('מחיר השתנה. יש לרענן את הסל.');
        }
        $lines = self::merged($items);
        $pids = Scc_Channel_Tx::ensure_locks(array_column($lines, 'productId'));
        try {
            Scc_Channel_Tx::run(function () use ($id, $items, $lines, $pids, $cat) {
                global $wpdb;
                Scc_Channel_Tx::lock($pids);
                // Same reservation id arriving twice in parallel: the loser sees the winner's rows here.
                if (self::existing_ok($id, self::rows($id, true), $items)) return;
                $now = Scc_Channel::ts(); $exp = $now + self::HOLD_SECONDS;
                $by = []; foreach ($lines as $l) $by[$l['productId']][] = $l;
                foreach ($by as $pid => $ls) {
                    $p = $cat->get($pid, true);
                    if (!$p) Scc_Channel::fail('מוצר אינו זמין.');
                    $held = array_sum(array_map(fn($r) => (int) $r['qty'], self::holds($pid)));
                    if ($held + array_sum(array_column($ls, 'qty')) > (int) $p['stock']) Scc_Channel::fail('המלאי השתנה או אינו מספיק. רעננו את הסל.');
                    foreach ($ls as $l) {
                        $lineHeld = 0; foreach (self::holds($pid) as $r) if ($r['variant_id'] === $l['variantId'] && $r['size'] === $l['size']) $lineHeld += (int) $r['qty'];
                        if ($lineHeld + (int) $l['qty'] > self::line_stock($p, $l)) Scc_Channel::fail('המלאי השתנה או אינו מספיק. רעננו את הסל.');
                    }
                }
                foreach ($lines as $l) Scc_Channel_Tx::exec($wpdb->prepare('INSERT INTO ' . self::t('reservations') . " (reservation_id,product_id,variant_id,size,qty,state,expires_at,created_at,updated_at) VALUES (%s,%s,%s,%s,%d,'held',%d,%d,%d)", $id, $l['productId'], $l['variantId'], $l['size'], (int) $l['qty'], $exp, $now, $now));
            });
        } catch (Scc_Channel_Db_Exception $e) {
            if (self::existing_ok($id, self::rows($id), $items)) return;
            Scc_Channel::fail('המלאי השתנה או אינו מספיק. רעננו את הסל.');
        }
    }
    public static function release(string $id): int {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare('UPDATE ' . self::t('reservations') . " SET state='released',updated_at=%d WHERE reservation_id=%s AND state='held'", Scc_Channel::ts(), $id));
    }
    /** Housekeeping: flag due holds as expired (availability already ignores them by time). */
    public static function expire_due(): int {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare('UPDATE ' . self::t('reservations') . " SET state='expired',updated_at=%d WHERE state='held' AND expires_at<=%d", Scc_Channel::ts(), Scc_Channel::ts()));
    }

    /* ---------- commit (channel owns the decrement: SHIMI purchases of linked SHEM products) ---------- */
    public static function commit(string $id): array {
        $rows = self::rows($id);
        if (!$rows) Scc_Channel::fail('שמירת מלאי לא נמצאה.');
        if (self::all_committed($rows)) return ['ok' => true, 'duplicate' => true];
        if (self::any_unusable($rows)) Scc_Channel::fail('נדרש בירור תשלום: שמירת המלאי פגה.');
        $pids = Scc_Channel_Tx::ensure_locks(array_column($rows, 'product_id'));
        $cat = Scc_Channel_Catalog::backend();
        try {
            return Scc_Channel_Tx::run(function () use ($id, $pids, $cat) {
                global $wpdb;
                Scc_Channel_Tx::lock($pids);
                $rows = self::rows($id, true);
                if (!$rows) Scc_Channel::fail('שמירת מלאי לא נמצאה.');
                if (self::all_committed($rows)) return ['ok' => true, 'duplicate' => true];
                if (self::any_unusable($rows)) Scc_Channel::fail('נדרש בירור תשלום: שמירת המלאי פגה.');
                $docs = []; $lines = [];
                foreach ($rows as $r) {
                    $p = $docs[$r['product_id']] ?? ($docs[$r['product_id']] = $cat->get($r['product_id'], true));
                    if (!$p || !empty($p['archived'])) Scc_Channel::fail('המוצר אינו זמין.');
                    $line = ['productId' => $r['product_id'], 'variantId' => $r['variant_id'], 'size' => $r['size'], 'qty' => (int) $r['qty']];
                    if (self::line_stock($p, $line) < $line['qty'] || (int) $p['stock'] < $line['qty']) Scc_Channel::fail('נדרש בירור מלאי.');
                    $lines[] = $line;
                }
                // Every decrement is recorded once; a repeated key means this movement was already applied.
                $now = Scc_Channel::ts();
                foreach ($lines as $l) {
                    $key = 'commit:' . $id . ':' . $l['productId'] . ':' . $l['variantId'] . ':' . $l['size'];
                    Scc_Channel_Tx::exec($wpdb->prepare('INSERT INTO ' . self::t('ledger') . " (movement_key,product_id,variant_id,size,delta,reason,ref,applied_by,created_at) VALUES (%s,%s,%s,%s,%d,%s,%s,'channel',%d)", $key, $l['productId'], $l['variantId'], $l['size'], -$l['qty'], 'רכישה בחנות SHIMI ' . $id, $id, $now));
                }
                $cat->decrement($lines);
                // After the decrement, the remaining holds of OTHER reservations must still fit (stockGuards).
                foreach ($docs as $pid => $_) {
                    $p = $cat->get($pid, true);
                    $others = self::holds($pid, $id);
                    if (array_sum(array_map(fn($r) => (int) $r['qty'], $others)) > (int) $p['stock']) Scc_Channel::fail('נדרש בירור מלאי.');
                }
                Scc_Channel_Tx::exec($wpdb->prepare('UPDATE ' . self::t('reservations') . " SET state='committed',updated_at=%d WHERE reservation_id=%s AND state='held'", $now, $id));
                return ['ok' => true];
            });
        } catch (Scc_Channel_Db_Exception $e) {
            if (stripos($e->getMessage(), 'duplicate') !== false) return ['ok' => true, 'duplicate' => true];
            throw $e;
        }
    }
    private static function all_committed(array $rows): bool { foreach ($rows as $r) if ($r['state'] !== 'committed') return false; return true; }
    private static function any_unusable(array $rows): bool { foreach ($rows as $r) if ($r['state'] !== 'held' || (int) $r['expires_at'] <= Scc_Channel::ts()) return true; return false; }

    /* ---------- Woo-owned orders: WooCommerce decrements, we only close the holds ---------- */
    public static function close_holds_for_woo(string $id, string $reason): int {
        global $wpdb;
        $n = 0;
        foreach (self::rows($id) as $r) {
            if ($r['state'] !== 'held') continue;
            $key = 'wc:' . $id . ':' . $r['product_id'] . ':' . $r['variant_id'] . ':' . $r['size'];
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::t('ledger') . " (movement_key,product_id,variant_id,size,delta,reason,ref,applied_by,created_at) VALUES (%s,%s,%s,%s,%d,%s,%s,'woocommerce',%d)", $key, $r['product_id'], $r['variant_id'], $r['size'], -(int) $r['qty'], $reason, $id, Scc_Channel::ts()));
        }
        $n = (int) $wpdb->query($wpdb->prepare('UPDATE ' . self::t('reservations') . " SET state='committed',updated_at=%d WHERE reservation_id=%s AND state='held'", Scc_Channel::ts(), $id));
        return $n;
    }

    /** Guard for any other stock writer (admin edits / imports / plugins): true when $new_stock still covers the live holds. */
    public static function write_allowed(string $product_id, int $new_stock, string $variant_id = '', string $size = '', string $exclude = ''): bool {
        $held = 0;
        foreach (self::holds($product_id, $exclude) as $r) if ($variant_id === '' ? true : $r['variant_id'] === $variant_id) $held += (int) $r['qty'];
        return $held <= $new_stock;
    }
}
