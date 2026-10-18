<?php
if (!defined('ABSPATH')) exit;
/**
 * Product links (product_links doc table) + source/projection + one-way sync SHEM -> SHIMI + hydrate (availability from SHEM).
 *   home side (SHIMI): doc id = SHIMI card id  { id, sourceId, sourceUrl, variantMap{homeVariant:sourceVariant}, status, createdAt, lastSyncAt, sourceRevision, error }
 *   source side (SHEM): doc id = SHEM product  { id, homeId, variantMap, status, lastSyncAt }
 * Unique constraints: the doc id (one link per product on each side) and scc_channel_link_keys UNIQUE(side, legacy_id):
 *   home 'source'/sourceId  - a SHEM product feeds one SHIMI card only;   source 'home'/homeId - a SHIMI card is registered once.
 */
final class Scc_Channel_Links {
    const TABLE = 'product_links';
    public static function get(string $id): ?array { return Scc_Docs::get(self::TABLE, $id); }
    public static function all(): array { return array_values(Scc_Docs::all(self::TABLE)); }
    public static function side(): string { return Scc_Channel::is_home() ? 'source' : 'home'; }

    /** Atomic claim of the unique key. Returns the link id that owns it after the attempt. */
    public static function claim(string $legacy_id, string $link_id): string {
        global $wpdb; $t = Scc_Channel_Tx::t('link_keys');
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (side,legacy_id,link_id) VALUES (%s,%s,%s)", self::side(), $legacy_id, $link_id));
        return (string) $wpdb->get_var($wpdb->prepare("SELECT link_id FROM $t WHERE side=%s AND legacy_id=%s", self::side(), $legacy_id));
    }
    public static function release_keys(string $link_id): void {
        global $wpdb; $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Channel_Tx::t('link_keys') . ' WHERE side=%s AND link_id=%s', self::side(), $link_id));
    }
    /** Home: the link feeding a SHEM product (json sourceId lookup via the key table, falls back to a scan for pre-key rows). */
    public static function find_by_source(string $source_id): ?array {
        global $wpdb;
        $owner = $wpdb->get_var($wpdb->prepare('SELECT link_id FROM ' . Scc_Channel_Tx::t('link_keys') . ' WHERE side=%s AND legacy_id=%s', self::side(), $source_id));
        if ($owner && ($l = self::get($owner)) && ($l['sourceId'] ?? '') === $source_id) return $l;
        foreach (self::all() as $l) if (($l['sourceId'] ?? '') === $source_id) return $l;
        return null;
    }
    /** Persist a link doc. Claims the unique key first; the caller gets a Hebrew conflict error when another card owns it. */
    public static function save(string $id, array $link): void {
        $key = Scc_Channel::is_home() ? ($link['sourceId'] ?? '') : ($link['homeId'] ?? '');
        if ($key !== '') {
            if (self::claim((string) $key, $id) !== $id) Scc_Channel::fail(Scc_Channel::is_home() ? 'מוצר המקור כבר מקושר לכרטיס אחר.' : 'מוצר המקור כבר מקושר למוצר אחר.');
        }
        Scc_Docs::put(self::TABLE, $id, $link);
    }
    public static function remove(string $id): void { self::release_keys($id); Scc_Docs::delete(self::TABLE, $id); }

    /* ---------------------------------------------------------------- source side projections */
    public static function projection(array $p, array $a): array {
        $o = ['id' => $p['id'], 'sku' => $p['sku'] ?? null, 'price' => $p['price'], 'previousPrice' => $p['previousPrice'] ?? null, 'revision' => $p['revision'] ?? null, 'active' => $p['active'] ?? true];
        $av = ['stock' => $a['stock']];
        if (isset($a['sizeStock']) && is_array($a['sizeStock'])) $av['sizeStock'] = (object) $a['sizeStock'];
        $av['variants'] = array_map(fn($v) => ['id' => $v['id'], 'size' => $v['size'] ?? '', 'color' => $v['color'] ?? '', 'sku' => $v['sku'] ?? '', 'stock' => $v['stock']], $a['variants'] ?? []);
        $o['availability'] = $av; $o['sizes'] = $p['sizes'] ?? []; $o['color'] = $p['color'] ?? '';
        return $o;
    }
    public static function source(string $id): array {
        $p = Scc_Channel_Catalog::get($id);
        if (!$p || !empty($p['archived'])) Scc_Channel::fail('מוצר המקור אינו זמין.', 404);
        return self::projection($p, Scc_Channel_Stock::view($p));
    }
    public static function sources(array $ids): array {
        $held = Scc_Channel_Stock::holds_all(); $out = [];
        foreach ($ids as $id) {
            $p = Scc_Channel_Catalog::get((string) $id);
            if (!$p || !empty($p['archived'])) continue;
            $out[] = self::projection($p, Scc_Channel_Stock::apply_holds($p, $held[$p['id'] ?? ''] ?? []));
        }
        return $out;
    }
    public static function register(array $d): array {
        $sid = (string) ($d['sourceId'] ?? ''); $hid = (string) ($d['homeId'] ?? '');
        $p = Scc_Channel_Catalog::get($sid);
        if (!$p || !empty($p['archived'])) Scc_Channel::fail('מוצר מקור אינו זמין.');
        $old = self::get($sid);
        if ($old && ($old['homeId'] ?? null) !== $hid) Scc_Channel::fail('מוצר המקור כבר מקושר למוצר אחר.');
        Scc_Channel_Catalog::backend()->set_home_meta($sid, $hid);
        // emittedRevision: the home store has just read this exact state, so only a later change of what it mirrors emits an event (loop guard).
        $rev = $old['emittedRevision'] ?? Scc_Channel_Catalog::sync_revision($p);
        self::save($sid, ['id' => $sid, 'homeId' => $hid, 'variantMap' => (object) ($d['variantMap'] ?? []), 'status' => 'connected', 'lastSyncAt' => Scc_Channel::now(), 'emittedRevision' => $rev]);
        return ['ok' => true];
    }
    public static function unregister(array $d): array {
        $sid = (string) ($d['sourceId'] ?? ''); $old = self::get($sid);
        if ($old && ($old['homeId'] ?? null) === ($d['homeId'] ?? null)) self::remove($sid);
        return ['ok' => true];
    }

    /* ---------------------------------------------------------------- home side sync */
    /** Port of syncLink(): mirror sku/price/previousPrice/variant sku of the SHEM product into the SHIMI card. */
    public static function sync(array $link, ?array $provided = null): array {
        try {
            $s = $provided ?? Scc_Channel::peer('/api/channel/source', ['id' => $link['sourceId']]);
            $p = Scc_Channel_Catalog::get($link['id']);
            if (!$p) Scc_Channel::fail('מוצר היעד חסר.');
            $variants = [];
            foreach (($p['variants'] ?? []) as $v) {
                $sourceVid = ((array) ($link['variantMap'] ?? []))[$v['id']] ?? null; $sv = null;
                foreach ($s['availability']['variants'] ?? [] as $x) if ($x['id'] === $sourceVid) $sv = $x;
                if (!$sv) Scc_Channel::fail('מיפוי מידה וצבע חסר או השתנה.');
                $variants[] = ['v' => $v, 'sku' => $sv['sku']];
            }
            if (empty($p['variants']) && !empty($s['availability']['variants'])) Scc_Channel::fail('יש ליצור ולמפות גרסאות יעד.');
            foreach (($p['sizes'] ?? []) as $x) if (!in_array($x, $s['sizes'] ?? [], true)) Scc_Channel::fail('מידות המוצר אינן תואמות.');
            $skus = []; foreach ($variants as $x) $skus[$x['v']['id']] = $x['sku'];
            Scc_Channel_Catalog::backend()->apply_sync($link['id'], ['sku' => $s['sku'], 'price' => $s['price'], 'previousPrice' => $s['previousPrice'] ?? null, 'variantSkus' => $skus]);
            $changed = $p; $changed['sku'] = $s['sku']; $changed['price'] = $s['price'];
            if (!empty($s['previousPrice'])) $changed['previousPrice'] = $s['previousPrice']; else unset($changed['previousPrice']);
            $changed['variants'] = array_map(fn($x) => array_merge($x['v'], ['sku' => $x['sku']]), $variants);
            $new = array_merge($link, ['status' => ($s['active'] ?? true) === false ? 'source-inactive' : 'connected', 'lastSyncAt' => Scc_Channel::now(), 'sourceRevision' => $s['revision'] ?? null, 'error' => '']);
            unset($new['lastAttemptAt']);
            self::save($link['id'], $new);
            return ['product' => $changed, 'source' => $s];
        } catch (Throwable $e) {
            try { self::save($link['id'], array_merge($link, ['status' => 'error', 'error' => $e->getMessage(), 'lastAttemptAt' => Scc_Channel::now()])); } catch (Throwable $_) {}
            throw $e;
        }
    }

    /** Port of hydrateProducts(): subtract own holds; on the home store replace price/sku/stock of linked cards with the SHEM truth (fail closed). */
    public static function hydrate(array $products): array {
        $held = Scc_Channel_Stock::holds_all();
        $local = fn(array $p) => Scc_Channel_Stock::apply_holds($p, $held[$p['id'] ?? ''] ?? []);
        if (!Scc_Channel::is_home()) return array_map($local, $products);
        $links = []; foreach (self::all() as $l) if (isset($l['id'])) $links[$l['id']] = $l;
        $needed = array_values(array_filter($links, fn($l) => (bool) array_filter($products, fn($p) => ($p['id'] ?? null) === $l['id'])));
        $snap = [];
        foreach (array_chunk($needed, 100) as $batch) {
            try { $d = Scc_Channel::peer('/api/channel/sources', ['ids' => array_column($batch, 'sourceId')]); foreach ($d['products'] ?? [] as $x) $snap[$x['id']] = $x; } catch (Throwable $e) {}
        }
        $out = [];
        foreach ($products as $p) {
            $link = $links[$p['id']] ?? null;
            if (!$link) { $out[] = $local($p); continue; }
            $failClosed = function () use ($p) {
                $z = $p; $z['stock'] = 0; $z['sizeStock'] = (object) array_fill_keys($p['sizes'] ?? [], 0);
                $z['variants'] = array_map(fn($v) => array_merge($v, ['stock' => 0]), $p['variants'] ?? []); $z['syncUnavailable'] = true; return $z;
            };
            $s = $snap[$link['sourceId']] ?? null;
            if (!$s) { $out[] = $failClosed(); continue; }
            try {
                foreach (($p['sizes'] ?? []) as $x) if (!in_array($x, $s['sizes'] ?? [], true)) throw new RuntimeException('mapping');
                if (empty($p['variants']) && !empty($s['availability']['variants'])) throw new RuntimeException('mapping');
                $variants = [];
                foreach (($p['variants'] ?? []) as $v) {
                    $sv = null; foreach ($s['availability']['variants'] as $x) if ($x['id'] === (((array) $link['variantMap'])[$v['id']] ?? null)) $sv = $x;
                    if (!$sv) throw new RuntimeException('mapping');
                    $variants[] = array_merge($v, ['sku' => $sv['sku'], 'stock' => $sv['stock']]);
                }
                $needsSync = ($p['sku'] ?? null) !== ($s['sku'] ?? null) || (float) $p['price'] !== (float) $s['price'] || (float) ($p['previousPrice'] ?? 0) !== (float) ($s['previousPrice'] ?? 0) || ($link['status'] ?? '') === 'error';
                foreach ($variants as $i => $v) if ($v['sku'] !== ($p['variants'][$i]['sku'] ?? null)) $needsSync = true;
                $base = $needsSync ? self::sync($link, $s)['product'] : $p;
                $b = $base; $b['price'] = $s['price'];
                if (!empty($s['previousPrice'])) $b['previousPrice'] = $s['previousPrice']; else unset($b['previousPrice']);
                $b['active'] = ($p['active'] ?? true) !== false && ($s['active'] ?? true) !== false;
                $b['stock'] = $s['availability']['stock']; $b['sizeStock'] = $s['availability']['sizeStock'] ?? null; $b['variants'] = $variants;
                $out[] = $b;
            } catch (Throwable $e) { $out[] = $failClosed(); }
        }
        return $out;
    }

    /* ---------------------------------------------------------------- admin flows (/api/admin/channel/...) */
    public static function preview(array $d): array {
        $u = wp_parse_url((string) ($d['sourceUrl'] ?? ''));
        $origin = is_array($u) && !empty($u['host']) ? Scc_Channel::origin_of((string) $d['sourceUrl']) : null;
        if (!$origin || !in_array($origin, Scc_Channel::peer_origins(), true) || !preg_match('#^/api/channel/source/[a-zA-Z0-9-]+$#', $u['path'] ?? '')) Scc_Channel::fail('קישור המקור אינו תקין.');
        return Scc_Channel::peer('/api/channel/source', ['id' => basename($u['path'])]);
    }
    public static function source_url(string $id): array {
        if (!Scc_Channel_Catalog::get($id)) Scc_Channel::fail('מוצר לא נמצא.', 404);
        return ['url' => rtrim(home_url(), '/') . '/api/channel/source/' . rawurlencode($id), 'link' => self::get($id)];
    }
    /** SHEM side: "sync" only asks SHIMI to refresh. */
    public static function source_sync(string $id): array {
        if (!Scc_Channel_Catalog::get($id)) Scc_Channel::fail('מוצר לא נמצא.', 404);
        $link = self::get($id); if (!$link) Scc_Channel::fail('אין חיבור למוצר.');
        Scc_Channel::peer('/api/channel/refresh', ['id' => $id, 'eventId' => 'manual:' . wp_generate_uuid4()]);
        self::save($id, array_merge($link, ['status' => 'connected', 'lastSyncAt' => Scc_Channel::now(), 'error' => '']));
        return ['ok' => true];
    }
    public static function link(string $id, array $d): array {
        $p = Scc_Channel_Catalog::get($id); if (!$p) Scc_Channel::fail('מוצר לא נמצא.', 404);
        $u = wp_parse_url((string) ($d['sourceUrl'] ?? '')); $origin = is_array($u) && !empty($u['host']) ? Scc_Channel::origin_of((string) $d['sourceUrl']) : null;
        if (!$origin) Scc_Channel::fail('קישור מקור אינו תקין.', 400);
        if (!in_array($origin, Scc_Channel::peer_origins(), true) || !preg_match('#^/api/channel/source/[a-zA-Z0-9-]+$#', $u['path'] ?? '') || isset($u['query']) || isset($u['fragment'])) Scc_Channel::fail('יש להשתמש בקישור מקור מהחנות SHEM המוגדרת.', 400);
        $sourceId = basename($u['path']);
        if (self::get($id)) Scc_Channel::fail('יש לנתק את החיבור הקיים לפני החלפת המקור.');
        $dup = self::find_by_source($sourceId);
        if ($dup && $dup['id'] !== $id) Scc_Channel::fail('מוצר המקור כבר מקושר לכרטיס אחר.');
        $s = Scc_Channel::peer('/api/channel/source', ['id' => $sourceId]); $map = [];
        if (empty($s['sku'])) Scc_Channel::fail('יש להגדיר מק״ט במוצר SHEM לפני החיבור.');
        $vm = is_array($d['variantMap'] ?? null) ? $d['variantMap'] : [];
        foreach (($p['variants'] ?? []) as $v) {
            $sv = null; foreach ($s['availability']['variants'] as $x) if (isset($vm[$v['id']]) && $x['id'] === $vm[$v['id']]) $sv = $x;
            if (!$sv || $sv['size'] !== $v['size']) Scc_Channel::fail('נדרש מיפוי ידני לכל גרסה באותה מידה.');
            $map[$v['id']] = $sv['id'];
        }
        $sv_ids = array_column($s['availability']['variants'], 'id');
        if (count(array_unique(array_values($map))) !== count($map) || array_diff($sv_ids, array_values($map))) Scc_Channel::fail('נדרש מיפוי חד ערכי לכל גרסאות המקור.');
        if (empty($p['variants']) && !empty($s['availability']['variants'])) Scc_Channel::fail('למקור יש גרסאות. יש ליצור גרסאות בכרטיס SHIMI.');
        foreach (($p['sizes'] ?? []) as $x) if (!in_array($x, $s['sizes'] ?? [], true)) Scc_Channel::fail('המידות אינן תואמות למקור.');
        $link = ['id' => $id, 'sourceId' => $sourceId, 'sourceUrl' => (string) $d['sourceUrl'], 'variantMap' => (object) $map, 'status' => 'pending', 'createdAt' => Scc_Channel::now()];
        if (self::claim($sourceId, $id) !== $id) Scc_Channel::fail('מוצר המקור כבר מקושר לכרטיס אחר.');
        try {
            Scc_Channel::peer('/api/channel/register', ['sourceId' => $sourceId, 'homeId' => $id, 'variantMap' => (object) $map]);
            self::save($id, $link);
        } catch (Throwable $e) { self::release_keys($id); throw $e; }
        return self::sync($link);
    }
    public static function resync(string $id): array {
        if (!Scc_Channel_Catalog::get($id)) Scc_Channel::fail('מוצר לא נמצא.', 404);
        $link = self::get($id); if (!$link) Scc_Channel::fail('אין חיבור למוצר.');
        return self::sync($link);
    }
    public static function unlink(string $id): array {
        $p = Scc_Channel_Catalog::get($id); if (!$p) Scc_Channel::fail('מוצר לא נמצא.', 404);
        $link = self::get($id); if (!$link) Scc_Channel::fail('אין חיבור למוצר.');
        Scc_Channel::peer('/api/channel/unregister', ['sourceId' => $link['sourceId'], 'homeId' => $id]);
        self::remove($id);
        Scc_Channel_Catalog::backend()->set_active($id, false);
        $p['active'] = false;
        return ['ok' => true, 'product' => $p];
    }
}
add_filter('scc_hydrate_products', ['Scc_Channel_Links', 'hydrate'], 10);
