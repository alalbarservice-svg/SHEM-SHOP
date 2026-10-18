<?php
if (!defined('ABSPATH')) exit;
/**
 * Product access seam for the channel. The channel logic works on the ORIGINAL product document shape so the worker code ports 1:1:
 *   { id, he, sku, price, previousPrice|null, active, archived, color, sizes[], stock, sizeStock{size:n}|null,
 *     variants[{id,size,color,sku,stock}], revision }
 * Two interchangeable backends (filter `scc_channel_catalog_backend` can replace them, e.g. with Scc_Core based one):
 *   Scc_Channel_Catalog_Woo   WooCommerce products. Contract with the data-import agent (documented, not owned here):
 *       product    meta _scc_legacy_id (UUID of the original product; numeric Woo id when missing)
 *       variation  meta _scc_legacy_variant_id, size in meta _scc_size, colour in meta _scc_color
 *       simple     meta _scc_sizes (JSON array or comma list), optional _scc_size_stock (JSON {size:n}), _scc_color
 *       price      sale price active => price=sale, previousPrice=regular ; else price=regular ; meta _scc_previous_price overrides previousPrice
 *       active     post_status === 'publish' ; archived = trashed or meta _scc_archived=1
 *   Scc_Channel_Catalog_Docs  Scc_Docs table `products` (document store mirroring the D1 tables). Used when WooCommerce is absent.
 */
interface Scc_Channel_Catalog_Backend {
    /** Product document by legacy id. $fresh = read stock numbers straight from the database (inside a locked transaction). */
    public function get(string $id, bool $fresh = false): ?array;
    /** Home side: write synchronised fields (sku, price, previousPrice, variantSkus[variantId=>sku]). */
    public function apply_sync(string $id, array $f): void;
    public function set_active(string $id, bool $active): void;
    /** Source side register(): remember the home card (homeProductId, homeSku). */
    public function set_home_meta(string $id, string $homeId): void;
    /** Subtract stock. $lines = [['productId','variantId','size','qty']]. Caller holds the product lock and verified availability. */
    public function decrement(array $lines): void;
}

final class Scc_Channel_Catalog {
    /** true while the channel itself writes products: product-save hooks must stay silent (loop guard). */
    public static bool $quiet = false;
    private static ?Scc_Channel_Catalog_Backend $backend = null;
    public static function reset(): void { self::$backend = null; }
    public static function backend(): Scc_Channel_Catalog_Backend {
        if (self::$backend === null) {
            $b = apply_filters('scc_channel_catalog_backend', null);
            if (!$b instanceof Scc_Channel_Catalog_Backend) $b = (function_exists('wc_get_product') && !defined('SCC_CHANNEL_DOCS_CATALOG')) ? new Scc_Channel_Catalog_Woo() : new Scc_Channel_Catalog_Docs();
            self::$backend = $b;
        }
        return self::$backend;
    }
    public static function use_backend(?Scc_Channel_Catalog_Backend $b): void { self::$backend = $b; }
    public static function get(string $id, bool $fresh = false): ?array { return self::backend()->get($id, $fresh); }
    public static function size_ok(array $p, string $size): bool { return !empty($p['sizes']) ? in_array($size, $p['sizes'], true) : $size === ''; }
    /** Hash of everything the home store mirrors (never stock): the "source revision" used by the loop / duplicate guard. */
    public static function sync_revision(array $p): string {
        $v = [];
        foreach (($p['variants'] ?? []) as $x) $v[] = [$x['id'], $x['size'] ?? '', $x['color'] ?? '', $x['sku'] ?? ''];
        return substr(hash('sha256', Scc_Channel::json([$p['sku'] ?? '', (float) ($p['price'] ?? 0), $p['previousPrice'] ?? null, ($p['active'] ?? true) !== false, !empty($p['archived']), $p['sizes'] ?? [], $v])), 0, 32);
    }
    /** Silent write wrapper. */
    public static function quietly(callable $fn): mixed {
        $prev = self::$quiet; self::$quiet = true;
        try { return $fn(); } finally { self::$quiet = $prev; }
    }
}

/* ---------------------------------------------------------------- Docs backend */
final class Scc_Channel_Catalog_Docs implements Scc_Channel_Catalog_Backend {
    private function row(string $id, bool $lock = false): ?array {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT data,updated_at FROM ' . Scc_Docs::t('products') . ' WHERE id=%s' . ($lock ? ' FOR UPDATE' : ''), $id), ARRAY_A);
        if (!$r) return null;
        $d = json_decode($r['data'], true) ?: [];
        $d['id'] = $id; $d['revision'] = $r['updated_at'];
        return $d;
    }
    private function save(array $p): void { $id = $p['id']; unset($p['revision']); Scc_Docs::put('products', $id, $p); }
    public function get(string $id, bool $fresh = false): ?array {
        $p = $this->row($id, $fresh && Scc_Channel_Tx::in_tx());
        if (!$p) return null;
        $p += ['sizes' => [], 'variants' => [], 'active' => true, 'archived' => false, 'previousPrice' => null];
        $p['revision'] = Scc_Channel_Catalog::sync_revision($p);
        return $p;
    }
    public function apply_sync(string $id, array $f): void {
        $p = $this->row($id); if (!$p) Scc_Channel::fail('מוצר היעד חסר.');
        $p['sku'] = $f['sku']; $p['price'] = $f['price'];
        if ($f['previousPrice']) $p['previousPrice'] = $f['previousPrice']; else unset($p['previousPrice']);
        foreach ($p['variants'] ?? [] as $i => $v) if (isset($f['variantSkus'][$v['id']])) $p['variants'][$i]['sku'] = $f['variantSkus'][$v['id']];
        $this->save($p);
    }
    public function set_active(string $id, bool $active): void { $p = $this->row($id); if ($p) { $p['active'] = $active; $this->save($p); } }
    public function set_home_meta(string $id, string $homeId): void {
        $p = $this->row($id); if (!$p) return;
        $p['homeSku'] = $p['sku'] ?? ''; $p['homeProductId'] = $homeId;
        $p['variants'] = array_map(fn($v) => $v + ['homeSku' => $v['sku'] ?? ''], $p['variants'] ?? []);
        $this->save($p);
    }
    public function decrement(array $lines): void {
        $by = [];
        foreach ($lines as $l) $by[$l['productId']][] = $l;
        foreach ($by as $pid => $ls) {
            $p = $this->row($pid, Scc_Channel_Tx::in_tx()); if (!$p) Scc_Channel::fail('המוצר אינו זמין.');
            foreach ($ls as $l) {
                $vi = null;
                foreach (($p['variants'] ?? []) as $i => $v) if ($v['id'] === $l['variantId']) $vi = $i;
                if ($vi !== null) $p['variants'][$vi]['stock'] -= $l['qty'];
                elseif (isset($p['sizeStock'])) $p['sizeStock'][$l['size']] = ($p['sizeStock'][$l['size']] ?? 0) - $l['qty'];
                $p['stock'] -= $l['qty'];
            }
            if (!empty($p['variants'])) {
                foreach (($p['sizes'] ?? []) as $s) $p['sizeStock'][$s] = array_sum(array_map(fn($v) => $v['size'] === $s ? $v['stock'] : 0, $p['variants']));
            }
            $this->save($p);
        }
    }
}

/* ---------------------------------------------------------------- Woo backend */
final class Scc_Channel_Catalog_Woo implements Scc_Channel_Catalog_Backend {
    private array $ids = [];
    /**
     * Signed channel routes are dispatched on init:1, before WooCommerce registers its post types and taxonomies (init:5), so wc_get_product()
     * would return false there (found by the live HTTP scenario, invisible to CLI tests that run after init). Register them early; both calls are
     * guarded inside WooCommerce (post_type_exists / taxonomy_exists), so the later init:5 pass is a no-op.
     */
    public static function boot(): void {
        static $done = false; if ($done) return; $done = true;
        if (class_exists('WC_Post_Types') && !taxonomy_exists('product_type')) { WC_Post_Types::register_taxonomies(); WC_Post_Types::register_post_types(); }
    }
    /** legacy id -> Woo product id (parent products only). */
    public function resolve(string $id): int {
        global $wpdb;
        if (isset($this->ids[$id])) return $this->ids[$id];
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key='_scc_legacy_id' AND m.meta_value=%s AND p.post_type='product' ORDER BY m.post_id LIMIT 1", $id));
        if (!$pid && ctype_digit($id)) { $t = get_post_type((int) $id); if ($t === 'product') $pid = (int) $id; }
        return $this->ids[$id] = $pid;
    }
    public static function legacy_id(int $wc_id): string { $l = (string) get_post_meta($wc_id, '_scc_legacy_id', true); return $l !== '' ? $l : (string) $wc_id; }
    public static function legacy_variant_id(int $wc_id): string { $l = (string) get_post_meta($wc_id, '_scc_legacy_variant_id', true); return $l !== '' ? $l : (string) $wc_id; }
    private static function list_meta(int $pid, string $key): array {
        $v = get_post_meta($pid, $key, true);
        if (is_array($v)) return array_values(array_map('strval', $v));
        $v = (string) $v; if ($v === '') return [];
        $j = json_decode($v, true);
        return is_array($j) ? array_values(array_map('strval', $j)) : array_values(array_filter(array_map('trim', explode(',', $v)), 'strlen'));
    }
    private function stock_of(int $id): int {
        global $wpdb;
        $m = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_manage_stock'", $id));
        if ($m !== 'yes') return 0;
        return max(0, (int) $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock'", $id)));
    }
    public function get(string $id, bool $fresh = false): ?array { self::boot();
        $pid = $this->resolve($id);
        if (!$pid) return null;
        if ($fresh) { wp_cache_delete($pid, 'post_meta'); clean_post_cache($pid); }
        $p = wc_get_product($pid);
        if (!$p || $p->is_type('variation')) return null;
        $status = get_post_status($pid);
        $price = (float) $p->get_price();
        $prev = get_post_meta($pid, '_scc_previous_price', true);
        $previous = $prev !== '' ? (float) $prev : ($p->is_on_sale('edit') && $p->get_regular_price('edit') !== '' ? (float) $p->get_regular_price('edit') : null);
        $doc = ['id' => $id, 'wcId' => $pid, 'he' => $p->get_name(), 'sku' => (string) $p->get_sku(), 'price' => $price, 'previousPrice' => $previous,
            'active' => $status === 'publish', 'archived' => $status === 'trash' || get_post_meta($pid, '_scc_archived', true) === '1',
            'color' => (string) get_post_meta($pid, '_scc_color', true), 'sizes' => [], 'variants' => []];
        if ($p->is_type('variable')) {
            $sizes = [];
            foreach ($p->get_children() as $vid) {
                $stock = $fresh ? $this->stock_of($vid) : null;
                $v = wc_get_product($vid); if (!$v) continue;
                if ($stock === null) $stock = $v->get_manage_stock('edit') ? max(0, (int) $v->get_stock_quantity('edit')) : 0;
                $size = (string) get_post_meta($vid, '_scc_size', true);
                $doc['variants'][] = ['id' => self::legacy_variant_id($vid), 'size' => $size, 'color' => (string) get_post_meta($vid, '_scc_color', true), 'sku' => (string) $v->get_sku('edit'), 'stock' => $stock, 'wcId' => $vid];
                if (!in_array($size, $sizes, true)) $sizes[] = $size;
            }
            $declared = self::list_meta($pid, '_scc_sizes');
            $doc['sizes'] = $declared ?: $sizes;
            $doc['sizeStock'] = [];
            foreach ($doc['sizes'] as $s) $doc['sizeStock'][$s] = array_sum(array_map(fn($v) => $v['size'] === $s ? $v['stock'] : 0, $doc['variants']));
            $doc['stock'] = array_sum(array_column($doc['variants'], 'stock'));
        } else {
            $doc['sizes'] = self::list_meta($pid, '_scc_sizes');
            $doc['stock'] = $fresh ? $this->stock_of($pid) : ($p->get_manage_stock('edit') ? max(0, (int) $p->get_stock_quantity('edit')) : 0);
            $ss = get_post_meta($pid, '_scc_size_stock', true);
            if (is_string($ss) && $ss !== '' && is_array($j = json_decode($ss, true))) $doc['sizeStock'] = array_map('intval', $j);
            elseif (is_array($ss)) $doc['sizeStock'] = array_map('intval', $ss);
        }
        $doc['revision'] = Scc_Channel_Catalog::sync_revision($doc);
        return $doc;
    }
    private function set_prices(WC_Product $p, float $price, ?float $prev): void {
        if ($prev !== null && $prev > $price) { $p->set_regular_price((string) $prev); $p->set_sale_price((string) $price); }
        else { $p->set_regular_price((string) $price); $p->set_sale_price(''); }
        update_post_meta($p->get_id(), '_scc_previous_price', $prev !== null && $prev > $price ? (string) $prev : '');
    }
    public function apply_sync(string $id, array $f): void { self::boot();
        $pid = $this->resolve($id); $p = $pid ? wc_get_product($pid) : null;
        if (!$p) Scc_Channel::fail('מוצר היעד חסר.');
        $prev = $f['previousPrice'] ? (float) $f['previousPrice'] : null;
        Scc_Channel_Catalog::quietly(function () use ($p, $f, $prev, $pid) {
            try {
                if ($p->is_type('variable')) {
                    foreach ($p->get_children() as $vid) {
                        $v = wc_get_product($vid); if (!$v) continue;
                        $lv = self::legacy_variant_id($vid);
                        if (isset($f['variantSkus'][$lv])) $v->set_sku($f['variantSkus'][$lv]);
                        $this->set_prices($v, (float) $f['price'], $prev);
                        $v->save();
                    }
                    $p->set_sku($f['sku']); $p->save();
                    WC_Product_Variable::sync($pid);
                } else {
                    $p->set_sku($f['sku']); $this->set_prices($p, (float) $f['price'], $prev); $p->save();
                }
            } catch (WC_Data_Exception $e) { Scc_Channel::fail('עדכון כרטיס המוצר נכשל: ' . $e->getMessage()); }
            wc_delete_product_transients($pid);
        });
    }
    public function set_active(string $id, bool $active): void { self::boot();
        $pid = $this->resolve($id); if (!$pid) return;
        Scc_Channel_Catalog::quietly(function () use ($pid, $active) {
            $p = wc_get_product($pid); if (!$p) return;
            $p->set_status($active ? 'publish' : 'draft'); $p->save();
        });
    }
    public function set_home_meta(string $id, string $homeId): void { self::boot();
        $pid = $this->resolve($id); if (!$pid) return;
        Scc_Channel_Catalog::quietly(function () use ($pid, $homeId) {
            update_post_meta($pid, '_scc_home_product_id', $homeId);
            update_post_meta($pid, '_scc_home_sku', (string) get_post_meta($pid, '_sku', true));
            $p = wc_get_product($pid);
            if ($p && $p->is_type('variable')) foreach ($p->get_children() as $vid) update_post_meta($vid, '_scc_home_sku', (string) get_post_meta($vid, '_sku', true));
        });
    }
    public function decrement(array $lines): void { self::boot();
        foreach ($lines as $l) {
            $pid = $this->resolve($l['productId']); $p = $pid ? wc_get_product($pid) : null;
            if (!$p) Scc_Channel::fail('המוצר אינו זמין.');
            if ($p->is_type('variable')) {
                $vid = 0;
                foreach ($p->get_children() as $c) if (self::legacy_variant_id($c) === $l['variantId']) $vid = $c;
                $v = $vid ? wc_get_product($vid) : null;
                if (!$v) Scc_Channel::fail('גרסת מוצר אינה תואמת.');
                Scc_Channel_Catalog::quietly(fn() => wc_update_product_stock($v, (int) $l['qty'], 'decrease'));
            } else {
                Scc_Channel_Catalog::quietly(function () use ($p, $pid, $l) {
                    wc_update_product_stock($p, (int) $l['qty'], 'decrease');
                    $ss = get_post_meta($pid, '_scc_size_stock', true);
                    $j = is_string($ss) && $ss !== '' ? json_decode($ss, true) : (is_array($ss) ? $ss : null);
                    if (is_array($j) && isset($j[$l['size']])) { $j[$l['size']] = (int) $j[$l['size']] - (int) $l['qty']; update_post_meta($pid, '_scc_size_stock', wp_json_encode($j)); }
                });
            }
        }
    }
}
