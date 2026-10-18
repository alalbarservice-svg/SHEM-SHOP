<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/core-util.php';
require_once __DIR__ . '/core-products.php';

/**
 * Orders as WooCommerce orders (HPOS or posts storage - only the CRUD API is used) with the legacy order document in meta.
 * Meta contract (shared with the customer-experience module, which reads the same keys):
 *   _scc_legacy_id (order UUID) | _scc_legacy_order (legacy JSON without id/updatedAt) | _scc_legacy_updated_at (ISO ms revision)
 *   _scc_legacy_email (lower case) | _scc_view_token
 * Where WooCommerce is not available (or the order is not a WooCommerce order) the Scc_Docs 'orders' table is used as a fallback so
 * that status/invoice lookups keep working.
 */
final class Scc_Core_Orders {
    const M_ID = '_scc_legacy_id', M_DOC = '_scc_legacy_order', M_UPD = '_scc_legacy_updated_at', M_EMAIL = '_scc_legacy_email', M_TOKEN = '_scc_view_token';
    const GATEWAY = 'shem_via_shimi';

    /* ------------------------------------------------------------------ lookup / read */
    public static function wc_id(string $legacy_id): int {
        if ($legacy_id === '' || !function_exists('wc_get_orders')) return 0;
        $r = wc_get_orders(['limit' => 1, 'return' => 'ids', 'type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'meta_key' => self::M_ID, 'meta_value' => $legacy_id]);
        return $r ? (int) $r[0] : 0;
    }
    private static function from_wc(WC_Order $o, string $id): ?array {
        $raw = $o->get_meta(self::M_DOC); if ($raw === '' || $raw === null) return null;
        $d = json_decode((string) $raw, true); if (!is_array($d)) return null;
        $upd = (string) $o->get_meta(self::M_UPD);
        if ($upd === '') $upd = Scc_Core_Util::now_iso($o->get_date_modified() ? $o->get_date_modified()->getTimestamp() : time());
        return $d + ['id' => $id, 'updatedAt' => $upd, '_wcId' => $o->get_id()];
    }
    /** Legacy-shaped order document ({...data, id, updatedAt, _wcId}) or null. */
    public static function doc(string $id): ?array {
        $wc = self::wc_id($id);
        if ($wc) { $o = wc_get_order($wc); if ($o) { $d = self::from_wc($o, $id); if ($d) return $d; } }
        $row = Scc_Docs::get('orders', $id);
        if ($row === null) return null;
        global $wpdb;
        $upd = (string) $wpdb->get_var($wpdb->prepare('SELECT updated_at FROM ' . Scc_Docs::t('orders') . ' WHERE id=%s', $id));
        return $row + ['id' => $id, 'updatedAt' => $upd];
    }
    /** Newest first, not archived, at most $limit. */
    public static function docs_by_email(string $email, int $limit = 100): array {
        if (!function_exists('wc_get_orders')) return [];
        $email = strtolower($email); $out = [];
        foreach (wc_get_orders(['limit' => 500, 'return' => 'objects', 'type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'meta_key' => self::M_EMAIL, 'meta_value' => $email]) as $o) {
            $id = (string) $o->get_meta(self::M_ID); if ($id === '') continue;
            $d = self::from_wc($o, $id); if (!$d) continue;
            $a = $d['archived'] ?? null; if (!($a === null || $a === false || $a === 0 || $a === 0.0)) continue;
            if (strtolower((string) ($d['customer']['email'] ?? '')) !== $email) continue;
            $out[] = $d;
        }
        usort($out, fn($a, $b) => strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? '')));
        return array_slice($out, 0, $limit);
    }
    /** Optimistic write. Returns the new revision or null when the stored revision differs from $expected. Caller serialises per order. */
    public static function save_doc(string $id, array $doc, string $expected, ?string $t = null): ?string {
        $wc = self::wc_id($id); if (!$wc) return null;
        $o = wc_get_order($wc); if (!$o) return null;
        $cur = self::from_wc($o, $id);
        if (!$cur || ($cur['updatedAt'] ?? '') !== $expected) return null;
        $t ??= Scc_Core_Util::now_iso(max(microtime(true), self::ms($expected) / 1000 + 0.001));
        unset($doc['id'], $doc['updatedAt'], $doc['_wcId'], $doc['revision']);
        $o->update_meta_data(self::M_DOC, Scc_Core_Util::enc($doc));
        $o->update_meta_data(self::M_UPD, $t);
        $o->update_meta_data(self::M_EMAIL, strtolower((string) ($doc['customer']['email'] ?? '')));
        $o->save();
        do_action('scc_cx_order_shipment_saved', $id, $o->get_id(), $doc);
        return $t;
    }
    public static function ms(string $iso): float {
        $t = strtotime($iso); if ($t === false) return 0.0;
        $ms = 0; if (preg_match('/\.(\d{1,3})/', $iso, $m)) $ms = (int) str_pad($m[1], 3, '0');
        return $t * 1000 + $ms;
    }

    /* ------------------------------------------------------------------ create from a legacy draft */
    public static function tax_rate_id(): int {
        static $id = null; if ($id !== null) return $id;
        foreach (WC_Tax::get_rates_for_tax_class('') as $rid => $rate) if (abs((float) $rate->tax_rate - 18.0) < 0.001) return $id = (int) $rid;
        $rid = WC_Tax::_insert_tax_rate(['tax_rate_country' => 'IL', 'tax_rate_state' => '', 'tax_rate' => '18.0000', 'tax_rate_name' => 'מע"מ', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '']);
        return $id = (int) $rid;
    }
    private static function dec(int $cents): string { return number_format($cents / 100, 2, '.', ''); }

    /**
     * Build the pending WooCommerce order for a legacy order draft. Totals are explicit (VAT-inclusive prices, per-line VAT from the
     * draft's vatItemsCents, discount spread by largest remainder) so that sum(line total + tax) + shipping == totalCents to the agora.
     * @param array $doc full legacy order document (id, viewToken, items, totals...). @param string $coupon promotion code or ''
     */
    public static function create_wc_order(array $doc, string $coupon = '', int $customer_user = 0): WC_Order {
        $rate = self::tax_rate_id();
        $o = new WC_Order();
        $o->set_created_via('scc-storefront');
        $o->set_currency('ILS');
        $o->set_prices_include_tax(true);
        $o->set_status('pending');
        if ($customer_user > 0) $o->set_customer_id($customer_user);
        $c = $doc['customer'];
        $parts = preg_split('/\s+/u', trim((string) $c['name']), 2); $first = $parts[0] ?? ''; $last = $parts[1] ?? '';
        $addr = ['first_name' => $first, 'last_name' => $last, 'address_1' => (string) ($c['street'] ?? ''), 'address_2' => (string) ($c['apartment'] ?? ''), 'city' => (string) ($c['city'] ?? ''), 'postcode' => (string) ($c['postcode'] ?? ''), 'country' => 'IL'];
        $o->set_address($addr + ['email' => (string) $c['email'], 'phone' => (string) ($c['phone'] ?? '')], 'billing');
        $o->set_address($addr + ['phone' => (string) ($c['phone'] ?? '')], 'shipping');
        $o->set_customer_note((string) ($doc['notes'] ?? ''));
        $gateways = function_exists('WC') && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
        if (isset($gateways[self::GATEWAY])) $o->set_payment_method($gateways[self::GATEWAY]); else $o->set_payment_method(self::GATEWAY);
        $o->save();
        $items = $doc['items'];
        $gross = []; foreach ($items as $i) $gross[] = $i['qty'] * $i['priceCents'];
        $disc = Scc_Core_Util::allocate((int) $doc['discountCents'], $gross);
        $net = []; foreach ($gross as $k => $g) $net[$k] = $g - $disc[$k];
        $vatNet = Scc_Core_Util::allocate((int) $doc['vatItemsCents'], $net);
        $vatSub = Scc_Core_Util::allocate(Scc_Core_Util::ratio_round(array_sum($gross), 18, 118), $gross);
        $sumDiscEx = 0;
        foreach ($items as $k => $i) {
            $pid = Scc_Core::find_product_id((string) $i['productId']);
            $wcp = $pid ? wc_get_product($pid) : null;
            if ($wcp && ($i['variantId'] ?? '') !== '') { $vm = Scc_Core::variation_map($pid); if (isset($vm[$i['variantId']])) $wcp = wc_get_product($vm[$i['variantId']]); }
            $it = new WC_Order_Item_Product();
            if ($wcp) $it->set_product($wcp);
            $it->set_name((string) $i['name']);
            $it->set_quantity((int) $i['qty']);
            $it->set_tax_class('');
            $it->set_subtotal(self::dec($gross[$k] - $vatSub[$k])); $it->set_total(self::dec($net[$k] - $vatNet[$k]));
            $it->set_taxes(['subtotal' => [$rate => self::dec($vatSub[$k])], 'total' => [$rate => self::dec($vatNet[$k])]]);
            $it->add_meta_data('מידה', (string) $i['size'], true);
            if (($i['color'] ?? '') !== '') $it->add_meta_data('צבע', (string) $i['color'], true);
            $it->add_meta_data('_scc_product_id', (string) $i['productId'], true);
            $it->add_meta_data('_scc_variant_id', (string) ($i['variantId'] ?? ''), true);
            $it->add_meta_data('_scc_size', (string) $i['size'], true);
            $it->add_meta_data('_scc_sku', (string) ($i['sku'] ?? ''), true);
            $it->add_meta_data('_scc_price_cents', (string) $i['priceCents'], true);
            $o->add_item($it);
            $sumDiscEx += ($gross[$k] - $vatSub[$k]) - ($net[$k] - $vatNet[$k]);
        }
        $shipVat = (int) $doc['vatShippingCents'];
        $sh = new WC_Order_Item_Shipping();
        $sh->set_method_title((string) $doc['shippingLabel']); $sh->set_method_id('scc_courier'); $sh->set_instance_id(0);
        $sh->set_total(self::dec((int) $doc['shippingCents'] - $shipVat));
        $sh->set_taxes(['total' => [$rate => self::dec($shipVat)]]);
        $o->add_item($sh);
        if ((int) $doc['discountCents'] > 0) {
            $cp = new WC_Order_Item_Coupon(); $cp->set_code($coupon !== '' ? $coupon : 'DISCOUNT'); $cp->set_discount(self::dec($sumDiscEx)); $cp->set_discount_tax(self::dec((int) $doc['discountCents'] - $sumDiscEx));
            $o->add_item($cp);
        }
        $o->update_taxes();
        $o->calculate_totals(false);
        if ((int) round(((float) $o->get_total()) * 100) !== (int) $doc['totalCents']) {
            $got = $o->get_total(); $o->delete(true);
            throw new Scc_Http_Exception('סכומי ההזמנה אינם עקביים (' . $got . ').', 500);
        }
        $legacy = (string) $doc['id'];
        $store = $doc; unset($store['id']);
        $o->update_meta_data(self::M_ID, $legacy);
        $o->update_meta_data(self::M_DOC, Scc_Core_Util::enc($store));
        $o->update_meta_data(self::M_UPD, (string) $doc['createdAt']);
        $o->update_meta_data(self::M_EMAIL, strtolower((string) $c['email']));
        $o->update_meta_data(self::M_TOKEN, (string) $doc['viewToken']);
        $o->save();
        return $o;
    }

    /* ------------------------------------------------------------------ payment completion */
    private static function payment_details(WC_Order $o): array {
        $m = (string) $o->get_meta('_shem_pay_method');
        return Scc_Core::clean_payment(['method' => $m === 'card' ? 'card' : 'other', 'last4' => (string) $o->get_meta('_shem_pay_last4'), 'cardholderName' => (string) $o->get_meta('_shem_pay_holder'),
            'brand' => (string) $o->get_meta('_shem_pay_brand'), 'authorizationCode' => (string) $o->get_meta('_shem_pay_auth'), 'installments' => (int) $o->get_meta('_shem_pay_installments') ?: 1]);
    }
    /** WooCommerce says the order is paid: update the legacy document like confirmPayment() and close the stock holds. Idempotent. */
    public static function on_paid(int $wc_id): void {
        $o = wc_get_order($wc_id); if (!$o) return;
        $legacy = (string) $o->get_meta(self::M_ID); if ($legacy === '') return;
        if (!Scc_Core_Util::lock('order:' . $legacy, 10)) return;
        try {
            $o = wc_get_order($wc_id);
            $doc = self::from_wc($o, $legacy); if (!$doc) return;
            if (($doc['paymentStatus'] ?? null) === 'paid') return;
            $now = Scc_Core_Util::now_iso();
            $paid = $doc; unset($paid['id'], $paid['updatedAt'], $paid['_wcId']);
            $paid['paymentStatus'] = 'paid'; $paid['paid'] = true; $paid['paidAt'] = $now;
            $paid['paymentReference'] = (string) $o->get_transaction_id();
            $paid['paymentSource'] = $o->get_meta('_shem_pay_verified') === '1' || !(defined('SCC_ROLE') && SCC_ROLE === 'shimi') ? 'home' : 'shimi';
            $paid['paymentDetails'] = self::payment_details($o); $paid['invoiceDocumentId'] = $legacy; $paid['status'] = 'processing';
            $rev = self::save_doc($legacy, $paid, (string) $doc['updatedAt'], $now);
            if ($rev === null) { error_log('[scc-core] order doc revision moved while marking paid: ' . $legacy); return; }
            Scc_Docs::put('payment_transactions', $paid['paymentSource'] . ':' . $paid['paymentReference'], ['order_id' => $legacy]);
            do_action('scc_core_order_paid', $legacy, $wc_id, $paid + ['id' => $legacy, 'updatedAt' => $rev]);
        } finally { Scc_Core_Util::unlock('order:' . $legacy); }
    }
    /** After WooCommerce reduced stock for an order: mirror what it cannot know (per-size stock of simple products) + stock movements. */
    public static function on_stock_reduced(WC_Order $o): void {
        $legacy = (string) $o->get_meta(self::M_ID); if ($legacy === '' || $o->get_meta('_scc_size_stock_done') === '1') return;
        $now = Scc_Core_Util::now_iso();
        foreach ($o->get_items('line_item') as $it) {
            $pid = (int) $it->get_product_id(); $size = (string) $it->get_meta('_scc_size'); $qty = (int) $it->get_quantity();
            $lid = (string) get_post_meta($pid, Scc_Core::M_ID, true);
            if (!$it->get_variation_id()) {
                $ss = get_post_meta($pid, '_scc_size_stock', true); $j = is_string($ss) && $ss !== '' ? json_decode($ss, true) : null;
                if (is_array($j) && isset($j[$size])) { $j[$size] = (int) $j[$size] - $qty; update_post_meta($pid, '_scc_size_stock', wp_json_encode($j)); }
            }
            Scc_Docs::put('stock_movements', wp_generate_uuid4(), ['productId' => $lid !== '' ? $lid : (string) $pid, 'size' => $size, 'variantId' => (string) $it->get_meta('_scc_variant_id'), 'delta' => -$qty, 'reason' => 'תשלום הזמנה ' . $legacy, 'createdAt' => $now, 'system' => true]);
        }
        $o->update_meta_data('_scc_size_stock_done', '1'); $o->save_meta_data();
    }

    /* ------------------------------------------------------------------ invoice */
    /** @return array{0:bool,1:?int} [ready, shem-dok document id] */
    public static function invoice_row(array $order): ?array {
        if (!class_exists('ShemDok')) return null;
        $wc = (int) ($order['_wcId'] ?? self::wc_id((string) $order['id']));
        if ($wc <= 0) return null;
        $r = ShemDok::invoice_for_order($wc);
        return is_object($r) || is_array($r) ? (array) $r : null;
    }
    public static function invoice_ready(array $order): bool {
        if (class_exists('ShemDok')) return (bool) self::invoice_row($order);
        return (bool) Scc_Docs::get('invoice_documents', (string) $order['id']);
    }
    /** PDF bytes or null. */
    public static function invoice_pdf(array $order): ?string {
        if (class_exists('ShemDok')) {
            $d = self::invoice_row($order); if (!$d) return null;
            try { $bytes = ShemDok::stored_pdf((int) $d['id']); }
            catch (Throwable $e) { error_log('[scc-core] invoice pdf: ' . $e->getMessage()); return null; }
            if (method_exists('ShemDok', 'audit')) { try { ShemDok::audit((int) $d['id'], 'customer_download', [], (int) ($order['_wcId'] ?? 0)); } catch (Throwable $e) {} }
            return is_string($bytes) && $bytes !== '' ? $bytes : null;
        }
        $doc = Scc_Docs::get('invoice_documents', (string) $order['id']);
        $bytes = $doc ? apply_filters('scc_cx_invoice_pdf', null, $order, $doc) : null;
        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
