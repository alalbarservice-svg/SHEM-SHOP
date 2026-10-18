<?php
if (!defined('ABSPATH')) exit;
/**
 * Core shop API - public read side: /api/catalogue, /api/store, /api/collections, /api/club (GET+POST), /api/requests (POST).
 * Port of SHEM|SHIMI worker api.js + operations.js. Products are real WooCommerce products (see lib/core-products.php); the
 * catalogue JSON is generated from them so the storefront receives the same bytes as the original worker.
 * Not here (other modules): /api/session, /api/help, /signin-with-chatgpt, /api/admin/*, /api/channel/*.
 */
require_once SCC_DIR . 'includes/lib/core-util.php';
require_once SCC_DIR . 'includes/lib/core-products.php';
require_once SCC_DIR . 'includes/lib/core-pricing.php';

final class Scc_Core_Catalogue {
    /** Settings value decoded WITHOUT assoc so that {} stays {} and [] stays [] (null when missing / falsy). */
    public static function raw_setting(string $key): mixed {
        global $wpdb;
        $r = $wpdb->get_var($wpdb->prepare('SELECT data FROM ' . Scc_Docs::t('settings') . ' WHERE id=%s', $key));
        if ($r === null) return null;
        $d = json_decode($r);
        return is_object($d) && property_exists($d, 'value') ? $d->value : null;
    }
    /** `(await getSetting(db,key)) || {}` */
    private static function obj_setting(string $key): object {
        $v = self::raw_setting($key);
        return is_object($v) ? $v : new stdClass();
    }
    private static function brand(): string { return defined('SCC_ROLE') && SCC_ROLE === 'shimi' ? 'SHIMI' : 'SHEM'; }

    /** An array that came out of assoc json_decode as [] but is an object in the original data (sizeStock only). */
    private static function fix_empty_objects(array $p): array {
        if (array_key_exists('sizeStock', $p) && $p['sizeStock'] === []) $p['sizeStock'] = new stdClass();
        return $p;
    }

    /** Public projection of one product (api.js: strips homeSku/homeProductId and variant homeSku). */
    public static function project(array $p): array {
        unset($p['homeSku'], $p['homeProductId']);
        $v = [];
        foreach ((array) ($p['variants'] ?? []) as $x) { if (is_array($x)) unset($x['homeSku']); $v[] = $x; }
        $p['variants'] = $v;
        return self::fix_empty_objects($p);
    }
    public static function catalogue(): array {
        $out = [];
        foreach (Scc_Core::hydrated() as $p) {
            if (($p['active'] ?? null) === false || Scc_Core_Util::truthy($p['archived'] ?? null)) continue;
            $out[] = self::project($p);
        }
        return $out;
    }

    public static function store(): array {
        $content = self::obj_setting('content');
        $store = self::obj_setting('store');
        $out = get_object_vars($content);
        $out['operations'] = self::obj_setting('operations');
        // JS: `content.x || store.x`; `undefined` vanishes from JSON, null / '' stay.
        $pick = function (string $k) use ($content, $store) {
            if (Scc_Core_Util::truthy($content->$k ?? null)) return [true, $content->$k];
            return property_exists($store, $k) ? [true, $store->$k] : [false, null];
        };
        foreach (['heroTitle', 'heroSubtitle'] as $k) { [$has, $v] = $pick($k); if ($has) $out[$k] = $v; else unset($out[$k]); }
        if (property_exists($store, 'brands')) $out['brands'] = $store->brands; else unset($out['brands']);
        $out['business'] = self::obj_setting('business');
        return $out;
    }

    public static function collections(): array {
        $rows = array_values(array_filter(Scc_Core_Pricing::records('collections'), fn($c) => Scc_Core_Util::truthy($c['active'] ?? null) && !Scc_Core_Util::truthy($c['archived'] ?? null)));
        usort($rows, fn($a, $b) => (Scc_Core_Util::num($a['position'] ?? 0) ?: 0) <=> (Scc_Core_Util::num($b['position'] ?? 0) ?: 0)); // usort is stable on PHP >= 8.0
        return $rows;
    }

    public static function club_default(): array {
        return ['title' => 'הסטייל שלך. המעגל שלך.', 'description' => 'המעגל של ' . self::brand(), 'benefits' => "גישה מוקדמת לקולקציות\nעדכונים על פריטים נבחרים", 'enabled' => false, 'privacyUrl' => ''];
    }

    private static function doc_by_email(string $table, string $email): ?array {
        global $wpdb;
        $like = '%' . $wpdb->esc_like('"email":' . wp_json_encode($email, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '%';
        foreach ($wpdb->get_results($wpdb->prepare('SELECT id,data FROM ' . Scc_Docs::t($table) . ' WHERE data LIKE %s', $like), ARRAY_A) ?: [] as $r) {
            $d = json_decode($r['data'], true);
            if (is_array($d) && ($d['email'] ?? null) === $email) return ['id' => $r['id']] + $d;
        }
        return null;
    }

    public static function club_signup(Scc_Request $r): Scc_Response {
        Scc_Core_Util::guard_mutation($r);
        $config = self::raw_setting('club');
        if (!is_object($config) || !Scc_Core_Util::truthy($config->enabled ?? null) || !Scc_Core_Util::truthy($config->privacyUrl ?? null))
            return Scc_Core_Util::json(['error' => 'ההרשמה למועדון עדיין אינה פעילה.'], 409);
        $d = Scc_Core_Util::obj(Scc_Core_Util::parse($r, 'Invalid JSON'));
        $address = strtolower(Scc_Core_Util::clean($d['email'] ?? null, 254));
        if (!Scc_Core_Util::is_email($address) || ($d['consent'] ?? null) !== true) Scc_Core_Util::fail('כתובת אימייל תקינה והסכמה לקבלת עדכונים נדרשות.');
        $now = Scc_Core_Util::now_iso();
        if (!Scc_Core_Util::lock('club:' . $address, 5)) Scc_Core_Util::fail('נסו שוב.', 409);
        try {
            $m = self::doc_by_email('members', $address);
            if ($m) { $id = $m['id']; $m['status'] = 'active'; $m['consent_at'] = $now; unset($m['id']); Scc_Docs::put('members', $id, $m); }
            else { $id = Scc_Core_Util::uuid(); Scc_Docs::put('members', $id, ['email' => $address, 'status' => 'active', 'consent_at' => $now, 'created_at' => $now]); }
            $profile = Scc_Docs::get('member_profiles', $id) ?? self::doc_by_email('member_profiles', $address);
            if (!$profile) {
                Scc_Docs::put('member_profiles', $id, ['id' => $id, 'email' => $address, 'name' => '', 'tier' => 'standard', 'status' => 'active',
                    'cardNumber' => self::brand() . '-' . strtoupper(substr(str_replace('-', '', $id), 0, 12)), 'createdAt' => $now, 'marketingConsent' => false]);
            }
        } finally { Scc_Core_Util::unlock('club:' . $address); }
        return Scc_Core_Util::json(['ok' => true]);
    }

    public static function submit_request(Scc_Request $r): Scc_Response {
        Scc_Core_Util::guard_mutation($r);
        $d = Scc_Core_Util::obj(Scc_Core_Util::parse($r));
        $email = strtolower(Scc_Core_Util::clean($d['email'] ?? null, 254));
        if (!Scc_Core_Util::is_email($email) || Scc_Core_Util::clean($d['name'] ?? null) === '' || Scc_Core_Util::clean($d['orderNumber'] ?? null) === ''
            || !in_array($d['type'] ?? null, ['cancel', 'return', 'exchange', 'support'], true) || ($d['privacyAcknowledged'] ?? null) !== true)
            Scc_Core_Util::fail('יש להזין שם, אימייל, מספר הזמנה וסוג פנייה ולאשר את הודעת הפרטיות.');
        global $wpdb;
        $like = '%' . $wpdb->esc_like('"email":' . wp_json_encode($email, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '%';
        $recent = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Scc_Docs::t('requests') . ' WHERE data LIKE %s AND updated_at>%s', $like, gmdate('c', time() - 3600)));
        if ($recent >= 5) Scc_Core_Util::fail('נשלחו מספר פניות בזמן קצר. אפשר לפנות גם במייל.', 429);
        $id = Scc_Core_Util::uuid();
        Scc_Docs::put('requests', $id, ['id' => $id, 'name' => Scc_Core_Util::clean($d['name']), 'email' => $email, 'orderNumber' => Scc_Core_Util::clean($d['orderNumber'], 100),
            'type' => $d['type'], 'message' => Scc_Core_Util::clean($d['message'] ?? null, 2000), 'status' => 'new', 'createdAt' => Scc_Core_Util::now_iso(), 'privacyVersion' => '2026-10-07']);
        do_action('scc_core_request_created', $id);
        return Scc_Core_Util::json(['ok' => true, 'reference' => $id], 201);
    }
}

/* WooCommerce is the truth for price/stock/active/archived: apply it first in the hydrate chain (channel hooks run at priority 10). */
add_filter('scc_hydrate_products', ['Scc_Core', 'overlay'], 1);

Scc_Router::add('GET', '#^/api/catalogue$#', function (Scc_Request $r) {
    $products = Scc_Core_Catalogue::catalogue();
    return Scc_Core_Util::json(['products' => $products, 'configured' => Scc_Core_Util::truthy(Scc_Core_Catalogue::raw_setting('catalogueInitialized'))]);
});
Scc_Router::add('GET', '#^/api/store$#', function (Scc_Request $r) { return Scc_Core_Util::json(Scc_Core_Catalogue::store()); });
Scc_Router::add('GET', '#^/api/collections$#', function (Scc_Request $r) { return Scc_Core_Util::json(Scc_Core_Catalogue::collections()); });
Scc_Router::add('GET', '#^/api/club$#', function (Scc_Request $r) {
    $v = Scc_Core_Catalogue::raw_setting('club');
    return Scc_Core_Util::json(Scc_Core_Util::truthy($v) ? $v : Scc_Core_Catalogue::club_default());
});
Scc_Router::add('POST', '#^/api/club$#', ['Scc_Core_Catalogue', 'club_signup']);
Scc_Router::add('POST', '#^/api/requests$#', ['Scc_Core_Catalogue', 'submit_request']);
