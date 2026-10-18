<?php
if (!defined('ABSPATH')) exit;
/**
 * Domain events SHEM -> SHIMI.
 *  - Product saved (admin API write, WooCommerce product/variation save): ONE `refresh` event per product per request/domain change.
 *    Several hook firings in one request are coalesced (shutdown flush). The event is skipped when the mirrored data (sync revision =
 *    sku/price/previousPrice/active/archived/sizes/variant skus, never stock) equals the revision last emitted, which is also the loop guard:
 *    writes made by the channel itself (Scc_Channel_Catalog::$quiet) never emit, and a SHIMI -> SHEM -> SHIMI echo carries no new revision.
 *  - A not yet delivered event for the same product is coalesced into the newest revision instead of piling up.
 *  - Coupon changes bump a version used by review tokens, so every review quote built with the old coupon state is rejected.
 * Only the source store (SHEM) emits; the home store never pushes anything about products.
 */
final class Scc_Channel_Events {
    private static array $pending = [];
    private static bool $armed = false;

    public static function product_touched(string $legacy_id): void {
        if ($legacy_id === '' || Scc_Channel::is_home() || Scc_Channel_Catalog::$quiet) return;
        self::$pending[$legacy_id] = true;
        if (!self::$armed) { self::$armed = true; add_action('shutdown', [self::class, 'flush'], 5); }
    }
    public static function on_admin_saved($record, $prev = null): void { if (is_array($record) && isset($record['id'])) self::product_touched((string) $record['id']); }
    public static function on_woo_product($wc_id): void {
        if (Scc_Channel_Catalog::$quiet || Scc_Channel::is_home() || !function_exists('wc_get_product')) return;
        $p = wc_get_product($wc_id); if (!$p) return;
        $pid = $p->is_type('variation') ? $p->get_parent_id() : $p->get_id();
        self::product_touched(Scc_Channel_Catalog_Woo::legacy_id((int) $pid));
    }
    /** Emit what changed. Returns the event ids created (for tests / callers that flush manually). */
    public static function flush(): array {
        $ids = array_keys(self::$pending); self::$pending = []; $out = [];
        foreach ($ids as $id) { $e = self::emit_refresh($id); if ($e) $out[] = $e; }
        return $out;
    }
    public static function emit_refresh(string $id): ?string {
        global $wpdb;
        $link = Scc_Channel_Links::get($id); if (!$link) return null;
        $p = Scc_Channel_Catalog::get($id); if (!$p) return null;
        $rev = Scc_Channel_Catalog::sync_revision($p);
        if (($link['emittedRevision'] ?? '') === $rev) return null;                      // nothing the home store mirrors changed (stock, images, copy...)
        $t = Scc_Channel_Outbox::t(); $dk = 'refresh:' . $id;
        $last = $wpdb->get_row($wpdb->prepare("SELECT event_id,state,revision FROM $t WHERE domain_key=%s ORDER BY created_at DESC, event_id DESC LIMIT 1", $dk), ARRAY_A);
        $eid = null;
        if ($last && in_array($last['state'], ['pending', 'retry'], true)) {              // coalesce into the not yet delivered event
            if ($last['revision'] !== $rev) $wpdb->update($t, ['revision' => $rev, 'updated_at' => Scc_Channel::ts()], ['event_id' => $last['event_id']]);
            $eid = $last['event_id'];
        } else {
            $eid = Scc_Channel_Outbox::enqueue('refresh', '/api/channel/refresh', ['id' => $id, 'revision' => $rev], $dk, $rev, 'refresh:' . $id . ':' . substr($rev, 0, 8) . ':' . substr(wp_generate_uuid4(), 0, 8));
        }
        Scc_Docs::put(Scc_Channel_Links::TABLE, $id, array_merge($link, ['emittedRevision' => $rev, 'status' => ($link['status'] ?? '') === 'connected' ? 'sync-pending' : ($link['status'] ?? 'pending')]));
        return $eid;
    }

    /* ---------------------------------------------------------------- coupons */
    public static function coupon_changed(string $code): void {
        $code = strtoupper(trim($code)); if ($code === '') return;
        $m = get_option('scc_channel_coupon_ver', []); if (!is_array($m)) $m = [];
        $m[$code] = (int) ($m[$code] ?? 0) + 1;
        update_option('scc_channel_coupon_ver', $m, false);
        do_action('scc_channel_coupon_invalidated', $code, $m[$code]);
    }
    public static function on_wc_coupon($id): void { $c = get_the_title((int) $id); if ($c) self::coupon_changed($c); }
    public static function on_promotion($record): void { if (is_array($record) && !empty($record['code'])) self::coupon_changed((string) $record['code']); }
}
add_action('scc_admin_product_saved', ['Scc_Channel_Events', 'on_admin_saved'], 10, 2);
add_action('woocommerce_update_product', ['Scc_Channel_Events', 'on_woo_product'], 20, 1);
add_action('woocommerce_update_product_variation', ['Scc_Channel_Events', 'on_woo_product'], 20, 1);
add_action('woocommerce_new_product', ['Scc_Channel_Events', 'on_woo_product'], 20, 1);
add_action('woocommerce_update_coupon', ['Scc_Channel_Events', 'on_wc_coupon'], 20, 1);
add_action('woocommerce_new_coupon', ['Scc_Channel_Events', 'on_wc_coupon'], 20, 1);
add_action('woocommerce_delete_coupon', ['Scc_Channel_Events', 'on_wc_coupon'], 20, 1);
add_action('scc_admin_promotion_saved', ['Scc_Channel_Events', 'on_promotion'], 10, 1);
add_action('scc_coupon_changed', ['Scc_Channel_Events', 'coupon_changed'], 10, 1);
