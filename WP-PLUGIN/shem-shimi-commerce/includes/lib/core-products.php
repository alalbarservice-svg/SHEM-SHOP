<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/core-util.php';

/**
 * Products as real WooCommerce products (ADR 04 section 3), with the legacy document kept next to them.
 *
 * Storage contract (shared with the channel agent's Scc_Channel_Catalog_Woo and the data-import agent):
 *   product    meta _scc_legacy_id (original UUID/slug), _scc_doc (full legacy JSON, byte-exact), _scc_doc_hash, _scc_updated_at (revision),
 *              _scc_sizes (JSON list), _scc_color, _scc_previous_price, _scc_archived ('1'), _scc_brand, _scc_cat, _scc_product_type
 *              _scc_size_stock (JSON {size:n}) only on SIMPLE products whose document has sizeStock, _scc_stock_sync (stock sum at last sync)
 *   variation  meta _scc_legacy_variant_id, _scc_size, _scc_color   (variable product only when the document has variants)
 *   price      document price -> sale price when previousPrice > price (regular = previousPrice), else regular
 *   mirror     the document is also kept in Scc_Docs 'products' (id = legacy id) because other modules read that table.
 *
 * Truth model: the document is the truth for everything except price, previousPrice, stock, sizeStock, variant stock, active and archived.
 * Those five come from WooCommerce when the catalogue is projected (overlay()), so edits in WooCommerce and WooCommerce's own stock
 * reduction on paid orders are visible to the storefront.
 */
final class Scc_Core {
    const M_ID = '_scc_legacy_id', M_DOC = '_scc_doc', M_HASH = '_scc_doc_hash', M_UPD = '_scc_updated_at';
    /** Set by importers: suppresses mail/outbox side effects in this module's hooks. */
    public static bool $importing = false;
    private static array $id_cache = [];

    /* ------------------------------------------------------------------ lookup */
    public static function find_product_id(string $legacy_id): int {
        global $wpdb;
        if (isset(self::$id_cache[$legacy_id]) && self::$id_cache[$legacy_id] > 0 && get_post_type(self::$id_cache[$legacy_id]) === 'product') return self::$id_cache[$legacy_id];
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key=%s AND m.meta_value=%s AND p.post_type='product' ORDER BY m.post_id LIMIT 1", self::M_ID, $legacy_id));
        return self::$id_cache[$legacy_id] = $pid;
    }
    public static function forget_cache(): void { self::$id_cache = []; }

    /* ------------------------------------------------------------------ raw documents */
    /**
     * Legacy product documents (assoc arrays, `id` and `revision` included), newest revision first.
     * @param string[]|null $ids restrict to legacy ids
     */
    public static function product_docs(?array $ids = null): array {
        global $wpdb;
        $where = '';
        if ($ids !== null) {
            if (!$ids) return [];
            $where = ' AND l.meta_value IN (' . implode(',', array_fill(0, count($ids), '%s')) . ')';
        }
        $sql = "SELECT l.meta_value legacy, d.meta_value doc, u.meta_value upd FROM {$wpdb->posts} p
                JOIN {$wpdb->postmeta} l ON l.post_id=p.ID AND l.meta_key=%s
                JOIN {$wpdb->postmeta} d ON d.post_id=p.ID AND d.meta_key=%s
                LEFT JOIN {$wpdb->postmeta} u ON u.post_id=p.ID AND u.meta_key=%s
                WHERE p.post_type='product' AND p.post_status IN ('publish','draft','private','pending','future')$where
                ORDER BY u.meta_value DESC, p.ID DESC";
        $args = array_merge([self::M_ID, self::M_DOC, self::M_UPD], $ids ?? []);
        $out = []; $seen = [];
        foreach ($wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) ?: [] as $r) {
            if (isset($seen[$r['legacy']])) continue; $seen[$r['legacy']] = true;
            $d = json_decode((string) $r['doc'], true);
            if (!is_array($d)) continue;
            $d['id'] = $r['legacy'];
            $d['revision'] = (string) $r['upd'];
            $out[] = $d;
        }
        return $out;
    }
    public static function product_doc(string $legacy_id): ?array { $r = self::product_docs([$legacy_id]); return $r[0] ?? null; }

    /** Full pipeline used by every public read: raw docs -> WooCommerce overlay -> channel holds/links (filter scc_hydrate_products). */
    public static function hydrated(?array $ids = null): array {
        $docs = self::product_docs($ids);
        foreach ($docs as &$d) unset($d['revision']);
        unset($d);
        $out = apply_filters('scc_hydrate_products', $docs);
        return is_array($out) ? $out : $docs;
    }
    /** Same as hydrated() but keeps `revision` (admin / checks). */
    public static function overlaid(?array $ids = null): array { return self::overlay(self::product_docs($ids)); }

    /* ------------------------------------------------------------------ WooCommerce overlay */
    /** @return array<string,array> legacy id => state read from WooCommerce */
    public static function wc_state(array $ids): array {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), 'strlen')));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT m.meta_value legacy, p.ID pid, p.post_status st FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key=%s AND p.post_type='product' AND p.post_status<>'auto-draft' AND m.meta_value IN ($in) ORDER BY p.ID DESC", array_merge([self::M_ID], $ids)), ARRAY_A) ?: [];
        $by = []; $parents = [];
        foreach ($rows as $r) { $by[$r['legacy']] = ['pid' => (int) $r['pid'], 'status' => $r['st']]; $parents[(int) $r['pid']] = $r['legacy']; }
        if (!$by) return [];
        $pin = implode(',', array_map('intval', array_keys($parents)));
        $keys = ['_price', '_regular_price', '_sale_price', '_stock', '_manage_stock', '_scc_size_stock', '_scc_stock_sync', '_scc_previous_price', '_scc_archived', '_scc_legacy_variant_id', '_scc_size', '_scc_color'];
        $kin = "'" . implode("','", $keys) . "'";
        $meta = $wpdb->get_results("SELECT p.ID id, p.post_parent par, p.post_type ty, p.post_status st, pm.meta_key k, pm.meta_value v FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID WHERE (p.ID IN ($pin) OR (p.post_parent IN ($pin) AND p.post_type='product_variation')) AND pm.meta_key IN ($kin)", ARRAY_A) ?: [];
        $nodes = [];
        foreach ($meta as $m) {
            $n = &$nodes[(int) $m['id']];
            $n['id'] = (int) $m['id']; $n['par'] = (int) $m['par']; $n['ty'] = $m['ty']; $n['st'] = $m['st']; $n['m'][$m['k']] = $m['v'];
            unset($n);
        }
        $varPosts = $wpdb->get_results("SELECT ID id, post_parent par, post_status st, menu_order mo FROM {$wpdb->posts} WHERE post_type='product_variation' AND post_parent IN ($pin) ORDER BY menu_order, ID", ARRAY_A) ?: [];
        $kids = [];
        foreach ($varPosts as $v) $kids[(int) $v['par']][] = ['id' => (int) $v['id'], 'st' => $v['st'], 'm' => $nodes[(int) $v['id']]['m'] ?? []];
        foreach ($by as $legacy => &$s) {
            $m = $nodes[$s['pid']]['m'] ?? [];
            $s['m'] = $m; $s['kids'] = $kids[$s['pid']] ?? [];
        }
        return $by;
    }

    /** Apply WooCommerce truth (price, previousPrice, stock, sizeStock, variant stock, active, archived) to legacy documents. Idempotent. */
    public static function overlay(array $products): array {
        $state = self::wc_state(array_map(fn($p) => (string) ($p['id'] ?? ''), $products));
        foreach ($products as $i => $p) {
            $s = $state[(string) ($p['id'] ?? '')] ?? null;
            if (!$s) continue;
            $products[$i] = self::overlay_one($p, $s);
        }
        return $products;
    }
    private static function pnum(mixed $v): ?float { return $v === null || $v === '' || !is_numeric($v) ? null : (float) $v; }
    private static function overlay_one(array $p, array $s): array {
        $m = $s['m']; $kids = array_values(array_filter($s['kids'], fn($k) => $k['st'] !== 'private' && $k['st'] !== 'trash'));
        $isVar = !empty($p['variants']) && is_array($p['variants']);
        // ---- price / previousPrice (variable: from the first variation; simple: from the product)
        $pm = $isVar && $kids ? $kids[0]['m'] : $m;
        $price = self::pnum($pm['_price'] ?? null) ?? self::pnum($m['_price'] ?? null);
        $regular = self::pnum($pm['_regular_price'] ?? null); $sale = self::pnum($pm['_sale_price'] ?? null);
        if ($price !== null) $p['price'] = Scc_Core_Util::n($price);
        $onSale = $sale !== null && $regular !== null && $sale < $regular;
        $prevMeta = self::pnum($m['_scc_previous_price'] ?? null);
        if ($onSale) $p['previousPrice'] = Scc_Core_Util::n($regular);
        elseif ($prevMeta !== null && $prevMeta > 0 && $price !== null && $prevMeta > $price) $p['previousPrice'] = Scc_Core_Util::n($prevMeta);
        elseif (array_key_exists('previousPrice', $p) && $price !== null && is_numeric($p['previousPrice']) && (float) $p['previousPrice'] > $price) unset($p['previousPrice']);
        // ---- stock
        $sumNow = 0; $syncSum = isset($m['_scc_stock_sync']) && is_numeric($m['_scc_stock_sync']) ? (int) $m['_scc_stock_sync'] : null;
        if ($isVar) {
            $byVariant = [];
            foreach ($kids as $k) { $vid = (string) ($k['m']['_scc_legacy_variant_id'] ?? ''); $stock = ($k['m']['_manage_stock'] ?? 'no') === 'yes' ? (int) ($k['m']['_stock'] ?? 0) : 0; if ($vid !== '') $byVariant[$vid] = ['stock' => $stock, 'size' => (string) ($k['m']['_scc_size'] ?? '')]; }
            foreach ($p['variants'] as $vi => $v) {
                $vid = (string) ($v['id'] ?? '');
                if (isset($byVariant[$vid])) { $p['variants'][$vi]['stock'] = max(0, $byVariant[$vid]['stock']); $sumNow += max(0, $byVariant[$vid]['stock']); }
                else { $p['variants'][$vi]['stock'] = 0; }
            }
            if (array_key_exists('sizeStock', $p)) {
                $ss = is_array($p['sizeStock']) ? $p['sizeStock'] : [];
                foreach (array_keys($ss) as $size) { $t = 0; foreach ($p['variants'] as $v) if ((string) ($v['size'] ?? '') === (string) $size) $t += (int) $v['stock']; $ss[$size] = $t; }
                $p['sizeStock'] = $ss;
            }
            $base = isset($p['stock']) && is_numeric($p['stock']) ? (int) $p['stock'] : 0;
            $p['stock'] = max(0, $base + ($syncSum === null ? 0 : $sumNow - $syncSum));
            if ($syncSum === null) $p['stock'] = $sumNow;
        } else {
            $stockNow = ($m['_manage_stock'] ?? 'no') === 'yes' ? (int) ($m['_stock'] ?? 0) : 0;
            $p['stock'] = max(0, $stockNow);
            if (array_key_exists('sizeStock', $p)) {
                $raw = $m['_scc_size_stock'] ?? '';
                $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                if (is_array($j)) { $ss = []; foreach ($j as $k => $n) $ss[(string) $k] = max(0, (int) $n); $p['sizeStock'] = $ss; }
            }
        }
        // ---- visibility
        $archived = $s['status'] === 'trash' || ($m['_scc_archived'] ?? '') === '1';
        if ($archived) $p['archived'] = true;
        if ($s['status'] !== 'publish') $p['active'] = false;
        return $p;
    }

    /* ------------------------------------------------------------------ upsert (importer API) */
    /**
     * Create or update the WooCommerce product for a legacy product document. Idempotent by legacy id.
     * Never deletes: archived/inactive documents become draft products.
     * @param array $doc  legacy document (assoc). Pass $opts['raw_json'] to preserve exact bytes ({} vs [], number formatting).
     * @param array $opts raw_json:string, updated_at:string (ISO ms revision; default now, kept when unchanged), force:bool, dry_run:bool
     * @return array{ok:bool,action:string,legacy_id:string,wc_id:int,variations:array<string,int>,warnings:string[],updated_at:string,hash:string}
     */
    public static function upsert_product_from_legacy(array $doc, array $opts = []): array {
        if (!function_exists('wc_get_product')) throw new RuntimeException('WooCommerce is not active.');
        $id = Scc_Core_Util::clean($doc['id'] ?? null, 190);
        if ($id === '') throw new InvalidArgumentException('product document has no id');
        $json = isset($opts['raw_json']) && is_string($opts['raw_json']) && $opts['raw_json'] !== '' ? $opts['raw_json'] : self::doc_json($doc);
        $hash = sha1($json);
        $warnings = [];
        if (!Scc_Core_Util::lock('product:' . $id, 15)) throw new RuntimeException('product busy: ' . $id);
        try {
            $pid = self::find_product_id($id);
            $oldHash = $pid ? (string) get_post_meta($pid, self::M_HASH, true) : '';
            $oldUpd = $pid ? (string) get_post_meta($pid, self::M_UPD, true) : '';
            $rev = isset($opts['updated_at']) && is_string($opts['updated_at']) && $opts['updated_at'] !== '' ? $opts['updated_at'] : ($pid && $oldHash === $hash && $oldUpd !== '' ? $oldUpd : Scc_Core_Util::now_iso());
            if ($pid && $oldHash === $hash && empty($opts['force']) && ($oldUpd === $rev || !isset($opts['updated_at']))) {
                self::mirror($id, $json, $oldUpd !== '' ? $oldUpd : $rev);
                return ['ok' => true, 'action' => 'unchanged', 'legacy_id' => $id, 'wc_id' => $pid, 'variations' => self::variation_map($pid), 'warnings' => [], 'updated_at' => $oldUpd, 'hash' => $hash];
            }
            if (!empty($opts['dry_run'])) return ['ok' => true, 'action' => $pid ? 'would_update' : 'would_create', 'legacy_id' => $id, 'wc_id' => $pid, 'variations' => [], 'warnings' => [], 'updated_at' => $rev, 'hash' => $hash];
            $run = function () use ($doc, $id, $json, $hash, $rev, $pid, &$warnings) { return self::write_product($doc, $id, $json, $hash, $rev, $pid, $warnings); };
            $res = class_exists('Scc_Channel_Catalog') ? Scc_Channel_Catalog::quietly($run) : $run();
            return ['ok' => true, 'action' => $pid ? 'updated' : 'created', 'legacy_id' => $id, 'wc_id' => $res['pid'], 'variations' => $res['variations'], 'warnings' => $warnings, 'updated_at' => $rev, 'hash' => $hash];
        } finally { Scc_Core_Util::unlock('product:' . $id); }
    }

    /** Canonical JSON of a decoded document: JSON.stringify-style, {} kept for sizeStock. */
    public static function doc_json(array $doc): string {
        unset($doc['revision']);
        if (array_key_exists('sizeStock', $doc) && $doc['sizeStock'] === []) $doc['sizeStock'] = new stdClass();
        return Scc_Core_Util::enc($doc);
    }
    private static function mirror(string $id, string $json, string $rev): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare('REPLACE INTO ' . Scc_Docs::t('products') . ' (id,data,updated_at) VALUES (%s,%s,%s)', $id, $json, $rev));
    }
    public static function variation_map(int $pid): array {
        $out = [];
        $p = $pid ? wc_get_product($pid) : null;
        if ($p && $p->is_type('variable')) foreach ($p->get_children() as $vid) { $l = (string) get_post_meta($vid, '_scc_legacy_variant_id', true); if ($l !== '') $out[$l] = (int) $vid; }
        return $out;
    }
    private static function term_id(string $name): int {
        if ($name === '') return 0;
        $t = term_exists($name, 'product_cat');
        if (!$t) $t = wp_insert_term($name, 'product_cat');
        if (is_wp_error($t)) return 0;
        return (int) (is_array($t) ? $t['term_id'] : $t);
    }
    private static function set_sku_safe(WC_Product $p, string $sku, string $what, array &$warnings): void {
        if ($sku === '') return;
        try { $p->set_sku($sku); } catch (WC_Data_Exception $e) { $warnings[] = 'sku not set (' . $what . '): ' . $e->getMessage(); }
    }
    private static function write_product(array $doc, string $id, string $json, string $hash, string $rev, int $pid, array &$warnings): array {
        $variants = []; foreach ((is_array($doc['variants'] ?? null) ? $doc['variants'] : []) as $v) if (is_array($v) && Scc_Core_Util::clean($v['id'] ?? null, 190) !== '') $variants[] = $v;
        $type = $variants ? 'variable' : 'simple';
        $he = Scc_Core_Util::clean($doc['he'] ?? null, 300) ?: (Scc_Core_Util::clean($doc['en'] ?? null, 300) ?: $id);
        $price = is_numeric($doc['price'] ?? null) ? (float) $doc['price'] : 0.0;
        $prev = is_numeric($doc['previousPrice'] ?? null) ? (float) $doc['previousPrice'] : null;
        $sale = $prev !== null && $prev > $price;
        $active = ($doc['active'] ?? true) !== false; $archived = Scc_Core_Util::truthy($doc['archived'] ?? false);
        $status = $active && !$archived ? 'publish' : 'draft';
        $sizes = []; foreach ((is_array($doc['sizes'] ?? null) ? $doc['sizes'] : []) as $s) if (is_scalar($s)) $sizes[] = (string) $s;
        $color = Scc_Core_Util::clean($doc['color'] ?? null, 100);
        $stock = is_numeric($doc['stock'] ?? null) ? (int) $doc['stock'] : 0;
        $existing = $pid ? wc_get_product($pid) : null;
        if ($existing && $existing->get_type() !== $type) { wp_set_object_terms($pid, $type, 'product_type'); clean_post_cache($pid); wp_cache_delete($pid, 'post_meta'); $existing = wc_get_product($pid); }
        $product = $existing ?: ($type === 'variable' ? new WC_Product_Variable() : new WC_Product_Simple());
        $product->set_name($he);
        $product->set_status($status);
        $product->set_catalog_visibility('visible');
        $product->set_description(is_string($doc['description'] ?? null) ? $doc['description'] : '');
        self::set_sku_safe($product, Scc_Core_Util::clean($doc['sku'] ?? null, 100), 'product ' . $id, $warnings);
        $product->set_regular_price((string) ($sale ? $prev : $price));
        $product->set_sale_price($sale ? (string) $price : '');
        $cat = self::term_id(Scc_Core_Util::clean($doc['cat'] ?? null, 100));
        if ($cat) $product->set_category_ids([$cat]);
        $product->set_backorders('no');
        if ($type === 'simple') {
            $product->set_manage_stock(true); $product->set_stock_quantity($stock); $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock');
        }
        $varMap = [];
        if ($type === 'variable') {
            $sizeOpts = []; $colorOpts = [];
            foreach ($variants as $v) { $sz = (string) ($v['size'] ?? ''); $cl = (string) ($v['color'] ?? ''); if ($sz !== '' && !in_array($sz, $sizeOpts, true)) $sizeOpts[] = $sz; if ($cl !== '' && !in_array($cl, $colorOpts, true)) $colorOpts[] = $cl; }
            $attrs = [];
            if ($sizeOpts) { $a = new WC_Product_Attribute(); $a->set_name('מידה'); $a->set_options($sizeOpts); $a->set_position(0); $a->set_visible(true); $a->set_variation(true); $attrs[] = $a; }
            if (count($colorOpts) > 0) { $a = new WC_Product_Attribute(); $a->set_name('צבע'); $a->set_options($colorOpts); $a->set_position(1); $a->set_visible(true); $a->set_variation(true); $attrs[] = $a; }
            $product->set_attributes($attrs);
        }
        $pid = $product->save();
        self::$id_cache[$id] = $pid;
        if ($type === 'variable') {
            $keySize = sanitize_title('מידה'); $keyColor = sanitize_title('צבע');
            $byLegacy = []; foreach ($product->get_children() as $cid) { $l = (string) get_post_meta($cid, '_scc_legacy_variant_id', true); if ($l !== '') $byLegacy[$l] = (int) $cid; }
            $seen = []; $order = 0;
            foreach ($variants as $v) {
                $vid = Scc_Core_Util::clean($v['id'] ?? null, 190);
                $var = isset($byLegacy[$vid]) ? wc_get_product($byLegacy[$vid]) : new WC_Product_Variation();
                if (!$var instanceof WC_Product_Variation) $var = new WC_Product_Variation();
                $var->set_parent_id($pid);
                $attr = [];
                if (($v['size'] ?? '') !== '' ) $attr[$keySize] = (string) $v['size'];
                if (($v['color'] ?? '') !== '' && $colorOpts) $attr[$keyColor] = (string) $v['color'];
                $var->set_attributes($attr);
                $var->set_status('publish');
                $var->set_menu_order($order++);
                self::set_sku_safe($var, Scc_Core_Util::clean($v['sku'] ?? null, 100), 'variation ' . $vid, $warnings);
                $var->set_regular_price((string) ($sale ? $prev : $price)); $var->set_sale_price($sale ? (string) $price : '');
                $vs = is_numeric($v['stock'] ?? null) ? (int) $v['stock'] : 0;
                $var->set_manage_stock(true); $var->set_stock_quantity($vs); $var->set_stock_status($vs > 0 ? 'instock' : 'outofstock'); $var->set_backorders('no');
                $var->update_meta_data('_scc_legacy_variant_id', $vid);
                $var->update_meta_data('_scc_size', (string) ($v['size'] ?? ''));
                $var->update_meta_data('_scc_color', (string) ($v['color'] ?? ''));
                $vpid = $var->save();
                $varMap[$vid] = $vpid; $seen[$vpid] = true;
            }
            foreach ($byLegacy as $vid => $cid) {
                if (isset($seen[$cid])) continue;
                $old = wc_get_product($cid); if (!$old) continue; // never delete: retire
                $old->set_manage_stock(true); $old->set_stock_quantity(0); $old->set_stock_status('outofstock'); $old->set_status('private'); $old->save();
                $warnings[] = 'variation retired (not in document): ' . $vid;
            }
            WC_Product_Variable::sync($pid);
        }
        // ---- meta (shared contract)
        $sumNow = 0;
        if ($type === 'variable') foreach ($variants as $v) $sumNow += is_numeric($v['stock'] ?? null) ? (int) $v['stock'] : 0;
        update_post_meta($pid, self::M_ID, $id);
        update_post_meta($pid, self::M_DOC, wp_slash($json));
        update_post_meta($pid, self::M_HASH, $hash);
        update_post_meta($pid, self::M_UPD, $rev);
        update_post_meta($pid, '_scc_sizes', wp_json_encode(array_values($sizes), JSON_UNESCAPED_UNICODE));
        update_post_meta($pid, '_scc_color', $color);
        update_post_meta($pid, '_scc_previous_price', $prev !== null && $prev > $price ? (string) $prev : '');
        update_post_meta($pid, '_scc_archived', $archived ? '1' : '');
        update_post_meta($pid, '_scc_brand', Scc_Core_Util::clean($doc['brand'] ?? null, 100));
        update_post_meta($pid, '_scc_cat', Scc_Core_Util::clean($doc['cat'] ?? null, 100));
        update_post_meta($pid, '_scc_product_type', Scc_Core_Util::clean($doc['productType'] ?? null, 60));
        update_post_meta($pid, '_scc_stock_sync', (string) ($type === 'variable' ? $sumNow : $stock));
        if ($type === 'simple' && is_array($doc['sizeStock'] ?? null)) {
            $ss = []; foreach ($doc['sizeStock'] as $k => $n) $ss[(string) $k] = (int) $n;
            update_post_meta($pid, '_scc_size_stock', wp_json_encode($ss === [] ? new stdClass() : $ss, JSON_UNESCAPED_UNICODE));
        } else delete_post_meta($pid, '_scc_size_stock');
        self::mirror($id, $json, $rev);
        wc_delete_product_transients($pid);
        return ['pid' => $pid, 'variations' => $varMap];
    }

    /** Archive instead of delete: the product becomes a draft and is hidden from the catalogue. */
    public static function archive_product(string $legacy_id): bool {
        $pid = self::find_product_id($legacy_id); if (!$pid) return false;
        $run = function () use ($pid) { $p = wc_get_product($pid); $p->set_status('draft'); $p->save(); update_post_meta($pid, '_scc_archived', '1'); };
        class_exists('Scc_Channel_Catalog') ? Scc_Channel_Catalog::quietly($run) : $run();
        return true;
    }
}
