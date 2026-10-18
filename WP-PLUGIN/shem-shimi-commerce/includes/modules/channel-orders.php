<?php
if (!defined('ABSPATH')) exit;
/**
 * Order <-> stock ownership. Exactly one owner decrements stock for every order line:
 *   line on a product owned by this store      -> WooCommerce itself (payment_complete / wc_reduce_stock_levels, guarded by _reduced_stock).
 *                                                  The channel only closes the matching holds (state committed + informational ledger row 'wc:...').
 *   line on a SHIMI card linked to a SHEM item -> SHEM (channel commit, reservation 'shimi:<orderId>', UNIQUE ledger key).
 *                                                  WooCommerce on SHIMI must NOT touch it: the linked line is hidden from wc_reduce_stock_levels
 *                                                  (filter woocommerce_order_item_product, only while a reduction runs), so no `_reduced_stock`
 *                                                  is set, no double decrement now and no phantom restock on cancel.
 * Holds: local reservation id = order legacy id (this store's stock), central reservation id = 'shimi:'.<legacy id> (SHEM's stock).
 */
final class Scc_Channel_Orders {
    private static bool $reducing = false;
    public static function legacy_id(WC_Order $o): string { $l = (string) $o->get_meta('_scc_legacy_id'); return $l !== '' ? $l : (string) $o->get_id(); }

    /** @return array<int,array{productId:string,variantId:string,size:string,qty:int,priceCents:int}> */
    public static function lines(WC_Order $o): array {
        $f = apply_filters('scc_channel_order_lines', null, $o);
        if (is_array($f)) return $f;
        $out = [];
        foreach ($o->get_items() as $item) {
            if (!$item->is_type('line_item')) continue;
            $vid = (int) $item->get_variation_id(); $pid = (int) $item->get_product_id();
            $qty = (int) $item->get_quantity();
            $out[] = ['productId' => Scc_Channel_Catalog_Woo::legacy_id($pid), 'variantId' => $vid ? Scc_Channel_Catalog_Woo::legacy_variant_id($vid) : '',
                'size' => (string) ($vid ? get_post_meta($vid, '_scc_size', true) : $item->get_meta('size', true)), 'qty' => $qty,
                'priceCents' => (int) floor(((float) $item->get_subtotal() / max(1, $qty)) * 100 + 0.5)];
        }
        return $out;
    }
    /** SHIMI: split into [central lines (mapped to SHEM ids), local lines]. Other store roles return everything as local. */
    public static function split(array $lines): array {
        $central = []; $local = [];
        foreach ($lines as $l) {
            $link = Scc_Channel::is_home() ? Scc_Channel_Links::get($l['productId']) : null;
            if ($link) $central[] = array_merge($l, ['linkedSourceId' => $link['sourceId'], 'productId' => $link['sourceId'], 'variantId' => ((array) ($link['variantMap'] ?? []))[$l['variantId']] ?? '']);
            else $local[] = $l;
        }
        return [$central, $local];
    }
    /** ownCheckout reservation step: local holds here, central holds on SHEM; any failure releases both and rethrows. */
    public static function reserve_for_order(string $order_id, array $lines): array {
        [$central, $local] = self::split($lines);
        $rid = 'shimi:' . $order_id;
        try {
            Scc_Channel_Stock::reserve($order_id, $local);
            if ($central) Scc_Channel::peer('/api/channel/reserve', ['reservationId' => $rid, 'items' => $central]);
        } catch (Throwable $e) {
            self::release_for_order($order_id, (bool) $central);
            throw $e;
        }
        return ['reservationId' => $rid, 'central' => $central, 'local' => $local];
    }
    /** Called by whoever creates the Woo order after reserve_for_order(): remembers that SHEM holds exist so payment/cancel know what to close. */
    public static function mark_order(WC_Order $o, array $reservation): void {
        $o->update_meta_data('_scc_channel_central', $reservation['central'] ? '1' : '0');
        if ($reservation['central']) $o->update_meta_data('_scc_channel_reservation', $reservation['reservationId']);
        $o->save();
    }
    public static function release_for_order(string $order_id, bool $central = true): void {
        Scc_Channel_Stock::release($order_id);
        if ($central && Scc_Channel::is_home() && Scc_Channel::peer_url() !== '') Scc_Channel_Outbox::enqueue('release', '/api/channel/release', ['reservationId' => 'shimi:' . $order_id], 'release:' . $order_id, '', 'release:shimi:' . $order_id);
    }

    /* ---------------------------------------------------------------- SHEM: snapshot of an order (/api/channel/order) */
    public static function snapshot(string $id): array {
        $o = apply_filters('scc_channel_order_snapshot', null, $id);
        if ($o === null && class_exists('Scc_Cx_Orders')) {
            $d = Scc_Cx_Orders::get($id);
            if ($d && empty($d['archived'])) $o = ['items' => $d['items'] ?? [], 'customer' => $d['customer'] ?? (object) [], 'amountCents' => $d['totalCents'] ?? null, 'shippingCents' => $d['shippingCents'] ?? null, 'discountCents' => $d['discountCents'] ?? null];
        }
        if (!is_array($o)) Scc_Channel::fail('הזמנה חסרה.', 404);
        return $o;
    }

    /* ---------------------------------------------------------------- WooCommerce hooks */
    /** woocommerce_can_reduce_order_stock: open the window in which linked lines are hidden from the Woo stock reduction. */
    public static function can_reduce($ok, $order) { if (Scc_Channel::is_home()) self::$reducing = true; return $ok; }
    public static function end_reduce($order): void { self::$reducing = false; }
    public static function item_product($product, $item) {
        if (!self::$reducing || !$product) return $product;
        $pid = (int) $item->get_product_id();
        return Scc_Channel_Links::get(Scc_Channel_Catalog_Woo::legacy_id($pid)) ? false : $product;
    }
    /** Paid: close the holds. Safe to call repeatedly (state guarded + ledger key). */
    public static function on_paid($order_id): void {
        $o = wc_get_order($order_id); if (!$o) return;
        $id = self::legacy_id($o);
        Scc_Channel_Stock::close_holds_for_woo($id, 'WooCommerce ' . $o->get_id());
        if (Scc_Channel::is_home() && $o->get_meta('_scc_channel_central') === '1' && $o->get_meta('_scc_channel_central_done') !== '1') {
            Scc_Channel_Outbox::enqueue('commit', '/api/channel/commit', ['reservationId' => 'shimi:' . $id, 'orderId' => $id], 'commit:' . $id, '', 'commit:shimi:' . $id, 12);
        }
    }
    public static function on_gone($order_id): void {
        $o = wc_get_order($order_id); if (!$o) return;
        $id = self::legacy_id($o);
        if ($o->get_meta('_scc_channel_central_done') === '1') return;        // already committed centrally: refunds/restock are a SHEM manual action
        self::release_for_order($id, $o->get_meta('_scc_channel_central') === '1');
    }
    /** Outbox result: remember the central commit on the order. */
    public static function outbox_result(array $row, bool $ok, $e, string $state): void {
        if ($row['kind'] !== 'commit' || !function_exists('wc_get_orders')) return;
        $p = json_decode($row['payload'], true) ?: []; $oid = (string) ($p['orderId'] ?? ''); if ($oid === '') return;
        $orders = wc_get_orders(['limit' => 1, 'meta_key' => '_scc_legacy_id', 'meta_value' => $oid, 'return' => 'objects']);
        $o = $orders[0] ?? (ctype_digit($oid) ? wc_get_order((int) $oid) : null); if (!$o) return;
        if ($ok) { $o->update_meta_data('_scc_channel_central_done', '1'); $o->add_order_note('מלאי SHEM עודכן (שמירה ' . $row['event_id'] . ').'); }
        elseif ($state === 'dead') { $o->add_order_note('עדכון מלאי SHEM נכשל וממתין לטיפול: ' . ($e ? $e->getMessage() : '')); }
        $o->save();
    }
}
add_filter('woocommerce_can_reduce_order_stock', ['Scc_Channel_Orders', 'can_reduce'], 5, 2);
add_action('woocommerce_reduce_order_stock', ['Scc_Channel_Orders', 'end_reduce'], 99, 1);
add_filter('woocommerce_order_item_product', ['Scc_Channel_Orders', 'item_product'], 10, 2);
add_action('woocommerce_payment_complete', ['Scc_Channel_Orders', 'on_paid'], 20, 1);
add_action('woocommerce_order_status_processing', ['Scc_Channel_Orders', 'on_paid'], 20, 1);
add_action('woocommerce_order_status_completed', ['Scc_Channel_Orders', 'on_paid'], 20, 1);
add_action('woocommerce_order_status_cancelled', ['Scc_Channel_Orders', 'on_gone'], 20, 1);
add_action('woocommerce_order_status_failed', ['Scc_Channel_Orders', 'on_gone'], 20, 1);
add_action('scc_channel_outbox_result', ['Scc_Channel_Orders', 'outbox_result'], 10, 4);
