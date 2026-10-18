<?php
if (!defined('ABSPATH')) exit;
/**
 * Core shop API - storage adapters for the admin module (seam: filter `scc_admin_store_<table>` in admin-00-core.php).
 * The admin code keeps working on its Scc_Admin_Store interface while the data lives where the ADR puts it:
 *   products  mirror table (document) + WooCommerce truth for price/previousPrice/stock/sizeStock/variant stock/active/archived.
 *             Reads overlay WooCommerce on the document; every write is written through to the WooCommerce product
 *             (Scc_Core::upsert_product_from_legacy, same transaction as the admin request).
 *   orders    WooCommerce orders (legacy JSON in meta, see lib/core-order-store.php). Orders that exist only in the document table
 *             (not imported yet) are still readable and writable through the table.
 * A write that marks an order paid also moves the WooCommerce order to "processing" with the stock-reduced flag set, because the admin
 * confirm-payment flow has already decremented the stock through the products store (no second decrement). WooCommerce customer e-mails are
 * suppressed for that sync (the admin flow queues its own mail jobs).
 */
if (!class_exists('Scc_Admin_Store')) return;
require_once SCC_DIR . 'includes/lib/core-util.php';
require_once SCC_DIR . 'includes/lib/core-products.php';
require_once SCC_DIR . 'includes/lib/core-order-store.php';

final class Scc_Core_Admin_Products extends Scc_Admin_Store {
    public function __construct() { parent::__construct('products'); }
    private function overlay_rows(array $rows): array {
        if (!$rows || !function_exists('wc_get_product')) return $rows;
        $rev = []; foreach ($rows as $r) $rev[$r['id']] = $r['revision'] ?? null;
        $out = Scc_Core::overlay($rows);
        foreach ($out as $k => $r) { $out[$k]['revision'] = $rev[$r['id']] ?? ($r['revision'] ?? null); }
        return $out;
    }
    public function rows(int $limit = 1000): array { return $this->overlay_rows(parent::rows($limit)); }
    public function get(string $id): ?array { $r = parent::get($id); return $r ? $this->overlay_rows([$r])[0] : null; }
    private function through(string $id, array $data, string $rev): void {
        if (!function_exists('wc_get_product')) return;
        unset($data['revision']); $data['id'] = $id;
        Scc_Core::upsert_product_from_legacy($data, ['updated_at' => $rev]);
    }
    public function insert(string $id, array $data, ?string $rev = null): string|false {
        $r = parent::insert($id, $data, $rev); if ($r === false) return false;
        $this->through($id, $data, $r); return $r;
    }
    public function cas(string $id, array $data, string $expected, ?string $rev = null): string|false {
        $r = parent::cas($id, $data, $expected, $rev); if ($r === false) return false;
        $this->through($id, $data, $r); return $r;
    }
    public function upsert(string $id, array $data, ?string $rev = null): string {
        $r = parent::upsert($id, $data, $rev);
        $this->through($id, $data, $r); return $r;
    }
}

final class Scc_Core_Admin_Orders extends Scc_Admin_Store {
    public function __construct() { parent::__construct('orders'); }
    private static function out(array $d): array {
        $rev = (string) ($d['updatedAt'] ?? ''); $id = (string) $d['id'];
        unset($d['updatedAt'], $d['_wcId'], $d['id']);
        return array_merge($d, ['id' => $id, 'revision' => $rev]);
    }
    public function rows(int $limit = 1000): array {
        $rows = [];
        if (function_exists('wc_get_orders')) {
            foreach (wc_get_orders(['limit' => $limit, 'type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'meta_key' => Scc_Core_Orders::M_ID, 'meta_compare' => 'EXISTS', 'return' => 'objects']) as $o) {
                $d = Scc_Core_Orders::to_doc($o); if ($d) $rows[$d['id']] = self::out($d);
            }
        }
        foreach (parent::rows($limit) as $r) if (!isset($rows[$r['id']])) $rows[$r['id']] = $r;
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => strcmp((string) $b['revision'], (string) $a['revision']) ?: strcmp((string) $b['id'], (string) $a['id']));
        return array_slice($rows, 0, $limit);
    }
    public function get(string $id): ?array { $d = Scc_Core_Orders::doc($id); return $d ? self::out($d) : null; }
    public function exists(string $id): bool { return Scc_Core_Orders::wc_id($id) > 0 || parent::exists($id); }
    public function count(): int {
        $n = 0;
        if (function_exists('wc_get_orders')) $n = count(wc_get_orders(['limit' => -1, 'type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'meta_key' => Scc_Core_Orders::M_ID, 'meta_compare' => 'EXISTS', 'return' => 'ids']));
        return $n + parent::count();
    }
    public function find_json(string $path, string $value, string $exclude = '', bool $lower = false): ?array {
        foreach ($this->rows(5000) as $r) {
            if ($r['id'] === $exclude) continue;
            $v = $r; foreach (explode('.', $path) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { $v = null; break; } $v = $v[$k]; }
            if (!is_scalar($v)) continue;
            $v = (string) $v; if ($lower) { $v = strtolower($v); $value = strtolower($value); }
            if ($v === $value) return $r;
        }
        return null;
    }
    private function create(string $id, array $data, ?string $rev): string|false {
        $data['id'] = $id; $rev ??= Scc_Core_Util::now_iso();
        $data['createdAt'] ??= $rev;
        try { $o = Scc_Core_Orders::create_wc_order($data, '', 0); }
        catch (Throwable $e) { error_log('[scc-core] admin order create fell back to the document table: ' . $e->getMessage()); return false; }
        $o->update_meta_data(Scc_Core_Orders::M_UPD, $rev); $o->save();
        return $rev;
    }
    public function insert(string $id, array $data, ?string $rev = null): string|false {
        if ($this->exists($id)) return false;
        $r = function_exists('wc_get_orders') ? $this->create($id, $data, $rev) : false;
        return $r !== false ? $r : parent::insert($id, $data, $rev);
    }
    public function cas(string $id, array $data, string $expected, ?string $rev = null): string|false {
        if (!Scc_Core_Orders::wc_id($id)) return parent::cas($id, $data, $expected, $rev);
        $old = Scc_Core_Orders::doc($id);
        $r = Scc_Core_Orders::save_doc($id, $data, $expected, $rev);
        if ($r === null) return false;
        $this->sync_wc($id, (array) $old, $data);
        return $r;
    }
    public function upsert(string $id, array $data, ?string $rev = null): string {
        $wc = Scc_Core_Orders::wc_id($id);
        if ($wc) {
            $old = Scc_Core_Orders::doc($id); $rev ??= Scc_Core_Util::now_iso();
            $r = Scc_Core_Orders::save_doc($id, $data, (string) $old['updatedAt'], $rev);
            if ($r === null) throw new Scc_Http_Exception(Scc_Admin::GENERIC_503, 503);
            $this->sync_wc($id, (array) $old, $data);
            return $r;
        }
        if (parent::exists($id)) return parent::upsert($id, $data, $rev);
        $r = function_exists('wc_get_orders') ? $this->create($id, $data, $rev) : false;
        return $r !== false ? $r : parent::upsert($id, $data, $rev);
    }
    /** Keep the WooCommerce order status in step with the legacy document (paid / cancelled). */
    private function sync_wc(string $id, array $old, array $new): void {
        $o = wc_get_order(Scc_Core_Orders::wc_id($id)); if (!$o) return;
        $mute = static function () { return false; };
        $hooks = ['woocommerce_email_enabled_customer_processing_order', 'woocommerce_email_enabled_new_order', 'woocommerce_email_enabled_cancelled_order', 'woocommerce_email_enabled_customer_completed_order'];
        foreach ($hooks as $h) add_filter($h, $mute, 99);
        try {
            $paid = ($new['paymentStatus'] ?? null) === 'paid' && ($old['paymentStatus'] ?? null) !== 'paid';
            if ($paid && !$o->is_paid()) {
                $o->set_order_stock_reduced(true);
                if (!empty($new['paymentReference'])) $o->set_transaction_id((string) $new['paymentReference']);
                $o->set_date_paid(time());
                $o->set_status('processing', 'אושר תשלום בממשק הניהול.');
                $o->save();
            } elseif (($new['status'] ?? null) === 'cancelled' && ($new['paymentStatus'] ?? null) !== 'paid' && !$o->has_status('cancelled')) {
                $o->set_status('cancelled', 'הזמנה בוטלה בממשק הניהול.'); $o->save();
            }
        } finally { foreach ($hooks as $h) remove_filter($h, $mute, 99); }
    }
}

add_filter('scc_admin_store_products', function ($s) { return $s ?: new Scc_Core_Admin_Products(); });
add_filter('scc_admin_store_orders', function ($s) { return $s ?: new Scc_Core_Admin_Orders(); });
