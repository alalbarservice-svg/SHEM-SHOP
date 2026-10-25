<?php
if (!defined('ABSPATH')) exit;
/**
 * Admin: products, promotions, order status (api.js generic CRUD), media upload, club member list,
 * collections/customers/requests/member-profiles (operations.js).
 * Ports: SHEM/worker/api.js (/api/admin/(products|orders|promotions|members), /api/admin/media),
 *        SHEM/worker/operations.js adminOperations. SHEM-only parts are gated on SCC_ROLE === 'shem'.
 */
final class Scc_Admin_Catalog {
    /* ---------- helpers ---------- */
    public static function js_date_ok(string $s): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $s, $m)) return false;
        return (int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[3] >= 1 && (int) $m[3] <= 31;
    }
    public static function asset_ok(mixed $v): bool {
        return !$v || (is_string($v) && preg_match('#^/(assets|media)/[a-zA-Z0-9_./-]+\z#', $v) && !str_contains($v, '..'));
    }
    /** SHEM only: productPresentation() from customer-experience.js (images, sizeGuide, colors) - validation + normalisation. */
    public static function presentation(array $d): array {
        $t = static fn($v, $n = 200) => Scc_Admin::str($v, $n);
        $images = is_array($d['images'] ?? null) && array_is_list($d['images']) ? $d['images'] : [];
        foreach ($images as $i) if (!is_array($i) || empty($i['url']) || !self::asset_ok($i['url'])) { $bad = true; }
        if (count($images) > 3 || isset($bad)) Scc_Admin::fail('ניתן להוסיף עד שלוש תמונות מוצר שהועלו לאתר.');
        $guide = is_array($d['sizeGuide'] ?? null) ? $d['sizeGuide'] : [];
        $columns = is_array($guide['columns'] ?? null) && array_is_list($guide['columns']) ? array_map(fn($c) => $t($c, 60), $guide['columns']) : [];
        $rows = is_array($guide['rows'] ?? null) && array_is_list($guide['rows']) ? $guide['rows'] : [];
        if (count($columns) > 6 || count($rows) > 30 || in_array('', $columns, true) || count(array_unique($columns)) !== count($columns)) Scc_Admin::fail('טבלת המידות אינה תקינה.');
        if ($rows && !$columns) Scc_Admin::fail('יש להוסיף לפחות עמודת מדידה אחת.');
        foreach ($rows as $r) if (!is_array($r) || $t($r['size'] ?? null, 20) === '' || !is_array($r['values'] ?? null) || !array_is_list($r['values']) || count($r['values']) !== count($columns)) Scc_Admin::fail('יש להזין מידה וערך לכל עמודה בטבלת המידות.');
        $sz = array_map(fn($r) => $t($r['size'], 20), $rows);
        if (count(array_unique($sz)) !== count($sz)) Scc_Admin::fail('יש להסיר מידות כפולות בטבלה.');
        $colors = is_array($d['colors'] ?? null) && array_is_list($d['colors']) ? $d['colors'] : [];
        if (count($colors) > 1 && empty($d['variants'])) Scc_Admin::fail('למוצר עם מספר צבעים יש להגדיר גרסאות מידה וצבע.');
        foreach ($colors as $c) if (!is_array($c) || $t($c['name'] ?? null, 50) === '' || !preg_match('/^#[0-9a-f]{6}\z/i', (string) ($c['hex'] ?? '')) || !self::asset_ok($c['imageUrl'] ?? null)) { $badc = true; }
        if (count($colors) > 30 || isset($badc)) Scc_Admin::fail('יש להזין שם צבע וקוד צבע תקין.');
        $names = array_map(fn($c) => $t($c['name'], 50), $colors);
        if (count(array_unique($names)) !== count($names)) Scc_Admin::fail('יש להסיר צבעים כפולים.');
        return [
            'images' => array_map(fn($i) => ['url' => $i['url'], 'alt' => $t($i['alt'] ?? null, 160)], $images),
            'sizeGuide' => ['columns' => $columns, 'rows' => array_map(fn($r) => ['size' => $t($r['size'], 20), 'values' => array_map(fn($v) => $t(is_scalar($v) ? (string) $v : '', 60), $r['values'])], $rows),
                'instructions' => $t($guide['instructions'] ?? null, 1500), 'fit' => $t($guide['fit'] ?? null, 500), 'model' => $t($guide['model'] ?? null, 500)],
            'colors' => array_map(fn($c) => ['name' => $t($c['name'], 50), 'hex' => $c['hex'], 'imageUrl' => $c['imageUrl'] ?? ''], $colors),
        ];
    }
    /** stockGuards(): live channel holds must not exceed the new stock (generic 503 like the worker's failed guard insert). */
    public static function guard_stock(array $p, string $exclude = ''): void {
        if (!class_exists('Scc_Channel_Stock')) return;
        $holds = Scc_Channel_Stock::holds((string) $p['id'], $exclude);
        $sum = static function (callable $match) use ($holds): int { $n = 0; foreach ($holds as $h) if ($match($h)) $n += (int) $h['qty']; return $n; };
        $bad = $sum(static fn($h) => true) > $p['stock'];
        foreach (($p['variants'] ?? []) as $v) if ($sum(static fn($h) => $h['variant_id'] === $v['id']) > $v['stock']) $bad = true;
        if (empty($p['variants']) && !empty($p['sizeStock'])) foreach ((array) $p['sizeStock'] as $size => $n) if ($sum(static fn($h) => $h['size'] === (string) $size) > $n) $bad = true;
        if ($bad) throw new Scc_Http_Exception(Scc_Admin::GENERIC_503, 503);
    }
    /** linkedGuard(): the product_links row of a product (null when not linked to the other store). */
    public static function linked(string $id): ?array { return Scc_Docs::get('product_links', $id); }
    /** notifyChange() (SHEM only): tell SHIMI to refresh a linked product; failure marks the link sync-pending, never fails the save. */
    public static function notify_change(array $record): void {
        if (Scc_Admin::is_home() || !class_exists('Scc_Channel')) return;
        $id = (string) ($record['id'] ?? ''); $link = $id !== '' ? self::linked($id) : null;
        if (!$link) return;
        try {
            Scc_Channel::peer('/api/channel/refresh', ['id' => $id]);
            Scc_Docs::put('product_links', $id, array_merge(self::linked($id) ?: $link, ['status' => 'connected', 'lastSyncAt' => Scc_Admin::now(), 'error' => '']));
        } catch (Throwable $e) {
            Scc_Docs::put('product_links', $id, array_merge(self::linked($id) ?: $link, ['status' => 'sync-pending', 'error' => $e->getMessage()]));
        }
    }
    public static function movement_state(?array $p): array { return $p ? [$p['stock'] ?? null, $p['sizeStock'] ?? null, $p['variants'] ?? null] : []; }

    /* ---------- GET ---------- */
    public static function list_or_get(string $table, ?string $id): Scc_Response {
        $st = Scc_Admin::store($table);
        if ($id !== null) {
            $row = $st->get($id);
            if (!$row) Scc_Admin::fail('הרשומה לא נמצאה.', 404);
            unset($row['id']); // worker returns {...data, revision}; id lives in data
            return Scc_Admin::json(array_merge(['id' => $id], $row));
        }
        return Scc_Admin::json($st->rows());
    }

    /* ---------- members (club e-mail list) ---------- */
    public static function members_list(): Scc_Response {
        $rows = Scc_Admin::store('members')->rows();
        usort($rows, fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        return Scc_Admin::json(array_map(fn($m) => ['id' => $m['id'], 'email' => $m['email'] ?? null, 'status' => $m['status'] ?? null, 'consent_at' => $m['consent_at'] ?? null, 'created_at' => $m['created_at'] ?? null], array_slice($rows, 0, 1000)));
    }
    public static function members_patch(?string $id, Scc_Request $r): Scc_Response {
        if ($r->method !== 'PATCH' || !$id) Scc_Admin::fail('Invalid operation');
        $d = Scc_Admin::body($r, 'en');
        if (!in_array($d['status'] ?? null, ['active', 'unsubscribed'], true)) Scc_Admin::fail('Invalid status');
        $st = Scc_Admin::store('members'); $m = $st->get($id);
        if (!$m) Scc_Admin::fail('החבר לא נמצא.', 404);
        if ($d['status'] === 'active') Scc_Admin::fail('הפעלת דיוור מחדש דורשת הסכמה חדשה מהלקוח.', 409);
        $rev = $m['revision']; unset($m['revision']);
        $m['status'] = 'unsubscribed';
        if (!$st->cas($id, $m, $rev)) Scc_Admin::fail('הרשומה השתנתה. רעננו לפני שמירה.', 409);
        return Scc_Admin::json(['ok' => true]);
    }

    /* ---------- POST/PUT for products | orders | promotions ---------- */
    public static function save(string $table, ?string $id, Scc_Request $r): Scc_Response {
        if (!in_array($r->method, ['POST', 'PUT'], true)) return Scc_Admin::json(['error' => 'Method not allowed'], 405);
        $d = Scc_Admin::body($r, 'en');
        $st = Scc_Admin::store($table);
        $recordId = $id ?: Scc_Admin::uuid();
        $expected = null;
        if ($r->method === 'PUT') {
            if (!$id) Scc_Admin::fail('חסר מזהה.');
            $existing = $st->get($id);
            if (!$existing) Scc_Admin::fail('הרשומה לא נמצאה.', 404);
            $expected = $existing['revision'];
            if (Scc_Admin::truthy($d['revision'] ?? null) && $d['revision'] !== $existing['revision']) Scc_Admin::fail('הרשומה השתנתה. רעננו לפני שמירה.', 409);
        }
        $prev = null;
        if ($table === 'products') { [$record, $prev] = self::build_product($d, $recordId, $r->method); }
        elseif ($table === 'orders') {
            if (!in_array($d['status'] ?? null, ['new', 'processing', 'shipped', 'completed', 'cancelled'], true)) Scc_Admin::fail('Invalid order status');
            if (!$id) Scc_Admin::fail('יצירת הזמנות תתאפשר לאחר חיבור מערכת ההזמנות.', 409);
            $ex = $st->get($id); if (!$ex) Scc_Admin::fail('ההזמנה לא נמצאה.', 404);
            unset($ex['revision']);
            $record = array_merge($ex, ['status' => $d['status'], 'tracking' => Scc_Admin::str($d['tracking'] ?? null, 200), 'notes' => Scc_Admin::str($d['notes'] ?? null, 2000)]);
        } else { $record = self::build_promotion($d, $recordId); }
        $rev = Scc_Admin::now();
        Scc_Admin::tx(function () use ($st, $table, $r, $recordId, $record, $prev, $expected, $rev) {
            if ($r->method === 'PUT') { if (!$st->cas($recordId, $record, $expected, $rev)) Scc_Admin::fail('הרשומה השתנתה. רעננו לפני שמירה.', 409); }
            else $st->insert($recordId, $record, $rev) ?: Scc_Admin::fail(Scc_Admin::GENERIC_503, 503);
            if ($table === 'products') {
                if (!$prev || wp_json_encode(self::movement_state($prev)) !== wp_json_encode(self::movement_state($record))) {
                    $mv = ['id' => Scc_Admin::uuid(), 'productId' => $recordId, 'delta' => $record['stock'] - ($prev['stock'] ?? 0),
                        'reason' => $prev ? 'עריכת מלאי בכרטיס מוצר' : 'מלאי פתיחה', 'createdAt' => $rev,
                        'before' => $prev ? ['stock' => $prev['stock'] ?? null, 'sizeStock' => $prev['sizeStock'] ?? null, 'variants' => $prev['variants'] ?? null] : null,
                        'after' => ['stock' => $record['stock'], 'sizeStock' => $record['sizeStock'] ?? null, 'variants' => $record['variants']], 'system' => true];
                    Scc_Admin::store('stock_movements')->insert($mv['id'], $mv, $rev);
                }
                self::guard_stock($record);
            }
        });
        if ($table === 'products') { Scc_Admin::save_setting('catalogueInitialized', true); do_action('scc_admin_product_saved', $record, $prev); self::notify_change($record); }
        return Scc_Admin::json(array_merge($record, ['revision' => $rev]), $r->method === 'POST' ? 201 : 200);
    }

    private static function build_promotion(array $d, string $recordId): array {
        $discount = Scc_Admin::num($d['discount'] ?? null);
        if (Scc_Admin::str($d['code'] ?? null, 40) === '' || !is_finite($discount) || $discount <= 0 || $discount > 100) Scc_Admin::fail('יש להזין קוד ואחוז הנחה בין 1 ל-100.');
        $code = mb_strtoupper(Scc_Admin::str($d['code'], 40));
        if (!preg_match('/^[A-Z0-9_-]+\z/', $code)) Scc_Admin::fail('הקוד יכול להכיל אותיות באנגלית, מספרים ומקף.');
        if (Scc_Admin::store('promotions')->find_json('code', $code, $recordId)) Scc_Admin::fail('קוד זה כבר קיים.', 409);
        foreach ([$d['startsAt'] ?? null, $d['endsAt'] ?? null] as $date) if (Scc_Admin::truthy($date) && (!is_string($date) || !self::js_date_ok($date))) Scc_Admin::fail('תאריך לא תקין.');
        if (Scc_Admin::truthy($d['startsAt'] ?? null) && Scc_Admin::truthy($d['endsAt'] ?? null) && strcmp((string) $d['startsAt'], (string) $d['endsAt']) > 0) Scc_Admin::fail('תאריך הסיום קודם לתאריך ההתחלה.');
        $min = Scc_Admin::num(Scc_Admin::truthy($d['minSubtotal'] ?? null) ? $d['minSubtotal'] : 0);
        if (!is_finite($min) || $min < 0) Scc_Admin::fail('סכום מינימום אינו תקין.');
        return ['id' => $recordId, 'startsAt' => Scc_Admin::str($d['startsAt'] ?? null, 30), 'minSubtotal' => Scc_Admin::nv($min), 'excludeSale' => ($d['excludeSale'] ?? null) === true,
            'code' => $code, 'discount' => Scc_Admin::nv($discount), 'active' => ($d['active'] ?? null) === true, 'endsAt' => Scc_Admin::str($d['endsAt'] ?? null, 30)];
    }

    /** @return array{0:array,1:?array} [record, previous product] */
    private static function build_product(array $d, string $recordId, string $method): array {
        $S = static fn($k, $n = 200) => Scc_Admin::str($d[$k] ?? null, $n);
        $price = Scc_Admin::num($d['price'] ?? null); $previous = Scc_Admin::num(Scc_Admin::truthy($d['previousPrice'] ?? null) ? $d['previousPrice'] : 0); $stock = Scc_Admin::num($d['stock'] ?? null);
        if ($S('he') === '' || !is_finite($price) || $price < 0.01 || $price > 100000 || !Scc_Admin::is_int_val($stock) || $stock < 0 || $stock > 100000) Scc_Admin::fail('יש להזין שם, מחיר חיובי ומלאי תקין.');
        if (!is_finite($previous) || $previous < 0) Scc_Admin::fail('מחיר קודם אינו תקין.');
        $sizes = [];
        if (is_array($d['sizes'] ?? null) && array_is_list($d['sizes'])) foreach (array_slice($d['sizes'], 0, 1000) as $s) { $s = Scc_Admin::str($s, 20); if ($s !== '') $sizes[] = $s; }
        $sizes = array_slice($sizes, 0, 30);
        if (count(array_unique($sizes)) !== count($sizes)) Scc_Admin::fail('יש להסיר מידות כפולות.');
        if (!$sizes) Scc_Admin::fail('יש להזין לפחות מידה אחת.');
        $imageUrl = $S('imageUrl', 500); $image = $S('image', 100);
        if ($image !== '' && !preg_match('/^[a-zA-Z0-9_-]+\z/', $image)) Scc_Admin::fail('Invalid image reference');
        if (($d['active'] ?? null) !== false && $imageUrl === '' && $image === '') Scc_Admin::fail('יש להעלות תמונת מוצר לפני הצגתו בחנות.');
        if ($imageUrl !== '' && (!preg_match('#^/(assets|media)/[a-zA-Z0-9_./-]+\z#', $imageUrl) || str_contains($imageUrl, '..'))) Scc_Admin::fail('Invalid image URL');
        $sizeStock = null;
        if (Scc_Admin::truthy($d['sizeStock'] ?? null) && is_array($d['sizeStock'])) {
            $sizeStock = [];
            foreach ($sizes as $size) $sizeStock[$size] = Scc_Admin::num(array_key_exists($size, $d['sizeStock']) && $d['sizeStock'][$size] !== null ? $d['sizeStock'][$size] : 0);
            foreach ($sizeStock as $n) if (!Scc_Admin::is_int_val($n) || $n < 0 || $n > 100000) Scc_Admin::fail('מלאי לפי מידה אינו תקין.');
            if (array_sum($sizeStock) != $stock) Scc_Admin::fail('מלאי כולל חייב להתאים לסכום המידות.');
        }
        $sku = $S('sku', 100);
        if ($sku !== '' && Scc_Admin::store('products')->find_json('sku', $sku, $recordId)) Scc_Admin::fail('מק"ט זה כבר קיים.', 409);
        $variants = [];
        if (is_array($d['variants'] ?? null) && array_is_list($d['variants'])) foreach (array_slice($d['variants'], 0, 200) as $v) {
            $v = is_array($v) ? $v : [];
            $variants[] = ['id' => Scc_Admin::str($v['id'] ?? null, 100) ?: Scc_Admin::uuid(), 'size' => Scc_Admin::str($v['size'] ?? null, 20), 'color' => Scc_Admin::str($v['color'] ?? null, 50),
                'sku' => Scc_Admin::str($v['sku'] ?? null, 100), 'homeSku' => Scc_Admin::str($v['homeSku'] ?? null, 100), 'stock' => Scc_Admin::num($v['stock'] ?? null)];
        }
        $bad = false; $ids = []; $skus = []; $combo = [];
        foreach ($variants as $v) {
            if (!in_array($v['size'], $sizes, true) || $v['color'] === '' || $v['sku'] === '' || !Scc_Admin::is_int_val($v['stock']) || $v['stock'] < 0) $bad = true;
            $ids[] = $v['id']; $skus[] = $v['sku']; $combo[] = $v['size'] . ':' . $v['color'];
        }
        if ($bad || count(array_unique($ids)) !== count($variants) || count(array_unique($skus)) !== count($variants) || count(array_unique($combo)) !== count($variants)) Scc_Admin::fail('גרסאות מוצר אינן תקינות או כפולות.');
        if ($variants) {
            $sum = array_sum(array_column($variants, 'stock')); $mismatch = $sum != $stock;
            if ($sizeStock !== null) foreach ($sizes as $size) { $ss = 0; foreach ($variants as $v) if ($v['size'] === $size) $ss += $v['stock']; if ($sizeStock[$size] != $ss) $mismatch = true; }
            if ($mismatch) Scc_Admin::fail('מלאי הגרסאות חייב להתאים למלאי הכולל והמידות.');
        }
        if ($variants) {
            $others = Scc_Admin::store('products')->rows();
            foreach ($variants as $v) foreach ($others as $o) {
                if ($o['id'] === $recordId) continue;
                foreach (($o['variants'] ?? []) as $ov) if (($ov['sku'] ?? null) === $v['sku']) Scc_Admin::fail('מק״ט גרסה כבר משויך למוצר אחר.', 409);
            }
        }
        $prev = null;
        if ($method === 'PUT') { $prev = Scc_Admin::store('products')->get($recordId); if ($prev) unset($prev['revision']); }
        if ($prev && !empty($prev['archived'])) Scc_Admin::fail('יש לשחזר מוצר מהארכיון לפני עריכה.', 409);
        $linked = Scc_Admin::is_home() && $method === 'PUT' ? self::linked($recordId) : null;
        if ($linked && $prev && (($d['sku'] ?? null) !== ($prev['sku'] ?? null) || Scc_Admin::num($d['price'] ?? null) != ($prev['price'] ?? null) || Scc_Admin::num($d['previousPrice'] ?? 0) != ($prev['previousPrice'] ?? 0)
            || $stock != ($prev['stock'] ?? null) || wp_json_encode($d['variants'] ?? []) !== wp_json_encode($prev['variants'] ?? [])))
            Scc_Admin::fail('מק״ט, מחיר וגרסאות של מוצר מקושר מנוהלים דרך SHEM. נתקו חיבור לפני שינוי.', 409);
        foreach ($variants as &$v) $v['stock'] = Scc_Admin::nv($v['stock']); unset($v);
        $record = ['id' => $recordId];
        if (SCC_ROLE === 'shem') $record += self::presentation($d);
        $record += ['sku' => $sku, 'homeSku' => $S('homeSku', 100), 'homeProductId' => $S('homeProductId', 100), 'variants' => $variants];
        if ($sizeStock !== null) { $o = new stdClass(); foreach ($sizeStock as $k => $n) $o->{(string) $k} = Scc_Admin::nv($n); $record['sizeStock'] = $o; }
        $record += ['description' => $S('description', 3000), 'material' => $S('material', 500), 'care' => $S('care', 500), 'demo' => ($d['demo'] ?? null) === true, 'he' => $S('he'), 'en' => $S('en') ?: $S('he'), 'brand' => $S('brand')];
        if (SCC_ROLE === 'shem') $record['productType'] = in_array($d['productType'] ?? null, Scc_Admin_Settings::item_type_keys(), true) ? $d['productType'] : '';
        $record += ['cat' => in_array($d['cat'] ?? null, ['clothing', 'shoes', 'accessories'], true) ? $d['cat'] : 'clothing', 'price' => Scc_Admin::nv(Scc_Admin::round($price * 100) / 100)];
        if ($previous > $price) $record['previousPrice'] = Scc_Admin::nv($previous);
        $record += ['stock' => Scc_Admin::nv($stock), 'sizes' => $sizes, 'color' => $S('color', 50), 'image' => $image, 'imageUrl' => $imageUrl, 'active' => ($d['active'] ?? null) !== false];
        return [$record, $prev];
    }

    /* ---------- operations.js: collections | customers | requests | member-profiles ---------- */
    private static function clean(mixed $v, int $n = 200): string { return Scc_Admin::str($v, $n); }
    public static function ops(string $slug, ?string $id, Scc_Request $r): Scc_Response {
        $table = $slug === 'member-profiles' ? 'member_profiles' : $slug;
        $st = Scc_Admin::store($table);
        if ($r->method === 'GET') return Scc_Admin::json($st->rows());
        if (!in_array($r->method, ['POST', 'PUT'], true)) return Scc_Admin::json(['error' => 'פעולה אינה נתמכת.'], 405);
        $d = Scc_Admin::body($r, 'he');
        $recordId = $id ?: Scc_Admin::uuid();
        if ($r->method === 'PUT' && !$id) Scc_Admin::fail('חסר מזהה.');
        if ($id && !$st->exists($id) && $table !== 'member_profiles') Scc_Admin::fail('הרשומה לא נמצאה.', 404);
        $c = static fn($k, $n = 200) => self::clean($d[$k] ?? null, $n);
        if ($table === 'collections') {
            if ($c('title') === '') Scc_Admin::fail('יש להזין שם קולקציה.');
            $ids = []; foreach (Scc_Admin::arr($d['productIds'] ?? null) as $x) if (is_string($x) && !in_array($x, $ids, true)) $ids[] = $x;
            $value = ['id' => $recordId, 'title' => $c('title'), 'description' => $c('description', 2000), 'productIds' => array_slice($ids, 0, 1000), 'active' => ($d['active'] ?? null) === true];
            $have = array_column(Scc_Admin::store('products')->rows(), 'id');
            foreach ($value['productIds'] as $x) if (!in_array($x, $have, true)) Scc_Admin::fail('הקולקציה כוללת מוצר שאינו קיים.');
        } elseif ($table === 'customers') {
            $email = mb_strtolower($c('email', 254));
            if ($c('name') === '' || !Scc_Admin::email($email)) Scc_Admin::fail('נדרשים שם ואימייל תקינים.');
            if ($st->find_json('email', $email, $recordId, true)) Scc_Admin::fail('כרטיס לקוח עם אימייל זה כבר קיים.', 409);
            $value = ['id' => $recordId, 'name' => $c('name'), 'email' => $email, 'phone' => $c('phone', 40), 'notes' => $c('notes', 3000), 'tags' => $c('tags', 200), 'createdAt' => Scc_Admin::truthy($d['createdAt'] ?? null) ? $d['createdAt'] : Scc_Admin::now()];
        } elseif ($table === 'requests') {
            if (!$id) Scc_Admin::fail('פניות מתקבלות מטופס השירות.', 409);
            $old = $st->get($id); unset($old['revision']);
            if (!in_array($d['status'] ?? null, ['new', 'reviewing', 'collection', 'resolved', 'rejected'], true)) Scc_Admin::fail('מצב פנייה לא תקין.');
            $value = array_merge($old, ['status' => $d['status'], 'notes' => $c('notes', 2000)]);
        } else { // member_profiles
            $member = $id ? Scc_Admin::store('members')->get($id) : null;
            if (!$member) Scc_Admin::fail('חבר לא נמצא.', 404);
            if (!in_array($d['tier'] ?? null, ['standard', 'vip'], true)) Scc_Admin::fail('דרגת מועדון לא תקינה.');
            $op = $st->get($id); if ($op) unset($op['revision']);
            $value = array_merge($op ?: [], ['email' => $member['email'] ?? null, 'status' => 'active', 'id' => $recordId, 'name' => $c('name'), 'phone' => $c('phone', 40), 'tier' => $d['tier'], 'notes' => $c('notes', 2000),
                'cardNumber' => Scc_Admin::brand() . '-' . strtoupper(substr(str_replace('-', '', $recordId), 0, 12))]);
        }
        $rev = Scc_Admin::now(); $given = $d['revision'] ?? null;
        if (Scc_Admin::truthy($given)) { // revision supplied: reject if it moved (compare-and-swap)
            $ex = $st->get($recordId);
            if ($ex && $given !== $ex['revision']) Scc_Admin::fail('הרשומה השתנתה מאז פתיחתה. רעננו ונסו שוב.', 409);
            if ($ex && !$st->cas($recordId, $value, $ex['revision'], $rev)) Scc_Admin::fail('הרשומה השתנתה מאז פתיחתה. רעננו ונסו שוב.', 409);
            if (!$ex) $st->upsert($recordId, $value, $rev);
        } else $st->upsert($recordId, $value, $rev);
        return Scc_Admin::json(array_merge($value, ['id' => $recordId, 'revision' => $rev]), $r->method === 'POST' ? 201 : 200);
    }

    /* ---------- media ---------- */
    public static function media_dir(): string { $u = wp_upload_dir(); return rtrim($u['basedir'], '/') . '/scc-media/products'; }
    public static function upload(Scc_Request $r): Scc_Response {
        $type = $r->header('content-type');
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$type] ?? null;
        if (!$ext) Scc_Admin::fail('יש להעלות PNG, JPG או WebP בלבד.');
        if (!Scc_Admin_Settings::media_available()) return Scc_Admin::json(['error' => 'אחסון התמונות אינו זמין.'], 503);
        $bytes = $r->raw;
        if (strlen($bytes) > 8 * 1024 * 1024) Scc_Admin::fail('התמונה גדולה מדי. עד 8MB.', 413);
        $v = $ext === 'png' ? str_starts_with($bytes, "\x89PNG") : ($ext === 'jpg' ? str_starts_with($bytes, "\xFF\xD8\xFF") : (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP'));
        if (!$v) Scc_Admin::fail('תוכן הקובץ אינו תואם לסוג התמונה.');
        $dir = self::media_dir();
        if (!wp_mkdir_p($dir)) return Scc_Admin::json(['error' => 'אחסון התמונות אינו זמין.'], 503);
        $name = Scc_Admin::uuid() . '.' . $ext;
        if (file_put_contents($dir . '/' . $name, $bytes) === false) return Scc_Admin::json(['error' => 'אחסון התמונות אינו זמין.'], 503);
        return Scc_Admin::json(['imageUrl' => '/media/products/' . $name], 201);
    }
    public static function serve_media(): void {
        $p = '/' . trim(rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
        if (strpos($p, '/media/') !== 0) return;
        $key = substr($p, 7);
        $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!preg_match('#^products/[a-zA-Z0-9.-]+\z#', $key) || !in_array($m, ['GET', 'HEAD'], true)) return; // not ours: WordPress continues (404)
        $file = self::media_dir() . '/' . substr($key, 9);
        if (!is_file($file) || !preg_match('/\.(png|jpg|webp)\z/', $file, $mm)) { status_header(404); echo 'Not found'; exit; }
        $ct = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$mm[1]];
        status_header(200); header('content-type: ' . $ct); header('cache-control: public,max-age=86400'); header('x-content-type-options: nosniff'); header('content-length: ' . filesize($file));
        if ($m === 'GET') readfile($file);
        exit;
    }
}

add_action('init', ['Scc_Admin_Catalog', 'serve_media'], 1);
Scc_Admin::route('POST', '#^/api/admin/media$#', fn(Scc_Request $r) => Scc_Admin_Catalog::upload($r), ['media' => true]);
// operations.js routes (run before the generic CRUD, like the worker)
Scc_Admin::route('*', '#^/api/admin/(collections|customers|requests|member-profiles)(?:/([^/]+))?$#', fn(Scc_Request $r) => Scc_Admin_Catalog::ops($r->params[1], $r->params[2] ?? null, $r));
// api.js generic CRUD
Scc_Admin::route('*', '#^/api/admin/(products|orders|promotions|members)(?:/([^/]+))?$#', function (Scc_Request $r) {
    $table = $r->params[1]; $id = $r->params[2] ?? null;
    if (!in_array($r->method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) return Scc_Admin::json(['error' => 'Method not allowed'], 405);
    if ($r->method === 'GET') {
        if ($id !== null && $table !== 'members') return Scc_Admin_Catalog::list_or_get($table, $id);
        if ($table === 'members') return Scc_Admin_Catalog::members_list();
        return Scc_Admin_Catalog::list_or_get($table, null);
    }
    if ($table === 'members') return Scc_Admin_Catalog::members_patch($id, $r);
    return Scc_Admin_Catalog::save($table, $id, $r);
});
