<?php
/**
 * Customer experience (SHEM only) - shared helpers and the seams to the other domains.
 * Port of SHEM/worker/customer-experience.js. On SCC_ROLE 'shimi' nothing is registered, so the router answers 404 as the original does.
 *
 * Seams (documented for the director):
 *  - Orders:   Scc_Cx_Orders. Uses Scc_Core::order_doc / order_docs_by_email / save_order_doc when the core agent provides them,
 *              otherwise reads WooCommerce orders that carry the legacy JSON meta (_scc_legacy_id, _scc_legacy_order, _scc_legacy_updated_at, _scc_legacy_email).
 *  - Invoice:  Scc_Cx_Invoice. Uses ShemDok (SHEM-DOK plugin) for paid-order invoices; filter 'scc_cx_invoice_pdf' as an alternative source.
 *  - Products: raw product docs come from Scc_Docs 'products'; filter 'scc_hydrate_products' lets the channel module apply reservation holds (original hydrateProducts).
 *  - Identity: the logged-in WordPress user (id = user id, email = user email). Filter 'scc_cx_identity' lets the auth module deny/override (e.g. unverified emails).
 */
if (!defined('ABSPATH')) exit;
if (SCC_ROLE !== 'shem') return;

final class Scc_Cx {
    public static function default_help(): array {
        return [
            ['id' => 'sizes',
             'category' => 'product',
             'title' => 'איך בוחרים מידה וצבע?',
             'answer' => 'בדף המוצר בחרו צבע ומידה. מדריך המידות מציג את המדידות שהוזנו עבור הפריט. אם חסר מידע או שיש ספק, פנו לשירות לפני ההזמנה.',
             'action' => 'shop',
             'active' => true,
             'placement' => 'product'],
            ['id' => 'delivery',
             'category' => 'delivery',
             'title' => 'כמה עולה המשלוח ומתי הוא מגיע?',
             'answer' => 'בחרו משלוח בעמוד פרטי ההזמנה. המחיר וזמן האספקה של האפשרות שנבחרה מוצגים לפני התשלום. פרטי המדיניות המלאים זמינים בעמוד תשלום ומשלוחים.',
             'action' => 'payments',
             'active' => true,
             'placement' => 'all'],
            ['id' => 'tracking',
             'category' => 'orders',
             'title' => 'איך עוקבים אחר ההזמנה?',
             'answer' => 'באזור שלי ניתן לראות הזמנות של החשבון המחובר. רכשתם כאורחים? השתמשו בקישור האישי שקיבלתם בסיום הרכישה. סטטוס המשלוח מתעדכן לפי המידע שהחנות קיבלה.',
             'action' => 'account',
             'active' => true,
             'placement' => 'none'],
            ['id' => 'invoice',
             'category' => 'orders',
             'title' => 'איפה חשבונית העסקה?',
             'answer' => 'לאחר אישור התשלום חשבונית העסקה זמינה בעמוד סיום הרכישה ובפרטי ההזמנה. ניתן לפתוח ולהוריד את קובץ ה-PDF למחשב או לטלפון.',
             'action' => 'account',
             'active' => true,
             'placement' => 'none'],
            ['id' => 'exchange',
             'category' => 'returns',
             'title' => 'איך מבקשים החלפה?',
             'answer' => 'להחלפה יש לפנות לתמיכה. הכינו את מספר ההזמנה, הפריט והמידה המבוקשת. ההחלפה תתואם עם נציג לפי המדיניות וזמינות המלאי.',
             'action' => 'contact',
             'active' => true,
             'placement' => 'product'],
            ['id' => 'return',
             'category' => 'returns',
             'title' => 'איך מבקשים החזרה או ביטול?',
             'answer' => 'ניתן להגיש בקשה דרך עמוד ביטול עסקה או לפנות לתמיכה. הגשת בקשה אינה אישור אוטומטי להחזר כספי. פרטי המדיניות מופיעים בעמוד ההחלפות וההחזרות.',
             'action' => 'cancel',
             'active' => true,
             'placement' => 'product'],
            ['id' => 'payment',
             'category' => 'payment',
             'title' => 'התשלום לא הסתיים. מה לעשות?',
             'answer' => 'אם מופיעה הודעה שמצב התשלום נבדק, המתינו לבדיקה ואל תשלמו שוב. אפשר לפתוח את ההזמנה האישית ולבדוק את הסטטוס. אם הוא עדיין אינו ברור, פנו לתמיכה עם מספר ההזמנה.',
             'action' => 'account',
             'active' => true,
             'placement' => 'checkout'],
            ['id' => 'coupon',
             'category' => 'club',
             'title' => 'למה קוד ההטבה לא מתקבל?',
             'answer' => 'קוד עשוי להיות מוגבל בתוקף, בסכום הסל, במוצרים או בשילוב עם מבצעים. ההודעה ליד הקוד תסביר אם אינו חל על הסל. ההטבות מחושבות לפני התשלום.',
             'action' => 'club',
             'active' => true,
             'placement' => 'checkout'],
        ];
    }

    public static function reply(mixed $data, int $status = 200): Scc_Response {
        return Scc_Response::json($data, $status, ['cache-control' => 'private,no-store', 'x-content-type-options' => 'nosniff']);
    }
    public static function fail(string $message, int $status = 400): never { throw new Scc_Http_Exception($message, $status); }

    /** JS-style: trim then slice, '' for non-strings. */
    public static function text(mixed $v, int $max = 200): string {
        if (!is_string($v)) return '';
        $t = preg_replace('/^[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}]+|[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $v);
        if ($t === null) $t = trim($v);
        return mb_substr($t, 0, $max);
    }
    /** ISO-8601 with milliseconds, as JS toISOString(). */
    public static function stamp(?float $t = null): string {
        $ms = (int) round(($t ?? microtime(true)) * 1000);
        return gmdate('Y-m-d\\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }
    public static function iso_to_ms(string $iso): float {
        $t = strtotime($iso); if ($t === false) return NAN;
        $ms = 0; if (preg_match('/\.(\d{1,3})/', $iso, $m)) $ms = (int) str_pad($m[1], 3, '0');
        return $t * 1000 + $ms;
    }
    /** JS truthiness (used where the original relies on `||`, `&&`, `!x`). */
    public static function truthy(mixed $v): bool {
        if ($v === null || $v === false || $v === 0 || $v === 0.0 || $v === '') return false;
        if (is_float($v) && is_nan($v)) return false;
        return true;
    }
    /** SQLite COALESCE(json_extract(data,'$.archived'),0)=0 semantics. */
    public static function archived(array $d): bool {
        $a = $d['archived'] ?? null;
        return !($a === null || $a === false || $a === 0 || $a === 0.0);
    }
    public static function num(mixed $v): float|int {
        if (is_int($v) || is_float($v)) return $v;
        return is_numeric($v) ? $v + 0 : 0;
    }
    public static function intify(float|int $n): float|int { return is_float($n) && floor($n) == $n && abs($n) < 9.0e15 ? (int) $n : $n; }

    /* ---------- request guards (original api.js, applied to every non-GET except shipment-events) ---------- */
    public static function guard_mutation(Scc_Request $r): void {
        $origin = $r->header('origin');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $hp = wp_parse_url(home_url()); $home = ($hp['scheme'] ?? 'http') . '://' . ($hp['host'] ?? '') . (isset($hp['port']) ? ':' . $hp['port'] : '');
        $ok = $origin !== '' && in_array($origin, [(is_ssl() ? 'https' : 'http') . '://' . $host, $home], true);
        if (!$ok) throw new Scc_Http_Exception('Origin rejected', 403);
        if (stripos($r->header('content-type'), 'application/json') === false) throw new Scc_Http_Exception('JSON required', 415);
        if ((int) $r->header('content-length') > 1000000) throw new Scc_Http_Exception('Request too large', 413);
    }
    /** Original parse(): Hebrew errors. Non-object JSON values behave like an empty object. */
    public static function parse(Scc_Request $r): array {
        $raw = $r->raw;
        if (strlen($raw) > 1000000) self::fail('הבקשה גדולה מדי.', 413);
        $d = json_decode($raw, true);
        if ($d === null && json_last_error() !== JSON_ERROR_NONE) self::fail('הבקשה אינה תקינה.');
        return is_array($d) ? $d : [];
    }

    /* ---------- identity ---------- */
    public static function identity(Scc_Request $r): array {
        $u = ['id' => '', 'email' => ''];
        if (is_user_logged_in()) {
            $wp = wp_get_current_user();
            $u = ['id' => (string) $wp->ID, 'email' => strtolower(self::text($wp->user_email, 254))];
        }
        $f = apply_filters('scc_cx_identity', $u, $r);
        return is_array($f) ? ['id' => (string) ($f['id'] ?? ''), 'email' => strtolower((string) ($f['email'] ?? ''))] : $u;
    }
    public static function require_identity(Scc_Request $r): array {
        $u = self::identity($r);
        if ($u['id'] === '' || $u['email'] === '') self::fail('יש להתחבר כדי לראות את ההזמנות וההטבות שלך.', 401);
        return $u;
    }
    public static function bearer(Scc_Request $r): string {
        $h = $r->header('authorization');
        if ($h === '') $h = (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($h === '' && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = (string) $v;
        return preg_replace('/^Bearer /', '', $h, 1) ?? '';
    }

    /* ---------- storage helpers over Scc_Docs tables ---------- */
    public static function row(string $table, string $id): ?array {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT data,updated_at FROM ' . Scc_Docs::t($table) . ' WHERE id=%s', $id), ARRAY_A);
        if (!$r) return null;
        return (json_decode($r['data'], true) ?: []) + ['id' => $id, 'updatedAt' => $r['updated_at']];
    }
    public static function setting(string $key): array {
        $v = Scc_Docs::setting($key, []);
        return is_array($v) ? $v : [];
    }
    public static function audit(string $actor, string $action): void {
        Scc_Docs::put('audit', wp_generate_uuid4(), ['actor' => $actor, 'action' => $action, 'createdAt' => self::stamp()]);
    }
    /** original hydrateProducts([raw])[0]; the channel module applies reservation holds through the filter. */
    public static function hydrate_one(array $raw): array {
        $out = apply_filters('scc_hydrate_products', [$raw]);
        return is_array($out) && isset($out[0]) && is_array($out[0]) ? $out[0] : $raw;
    }
    public static function json_col(string $path): string { return "JSON_UNQUOTE(JSON_EXTRACT(data,'\$.$path'))"; }
    public static function lock(string $name, int $wait = 5): bool {
        global $wpdb; return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', substr('scc_' . md5($name), 0, 60), $wait)) === '1';
    }
    public static function unlock(string $name): void {
        global $wpdb; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', substr('scc_' . md5($name), 0, 60)));
    }
}

/** Orders seam. Order docs are legacy-shaped arrays (id, updatedAt, customer{email}, items, paymentStatus, status, viewToken, ...). */
final class Scc_Cx_Orders {
    const M_ID = '_scc_legacy_id', M_DOC = '_scc_legacy_order', M_UPD = '_scc_legacy_updated_at', M_EMAIL = '_scc_legacy_email';

    private static function core(string $m): bool { return class_exists('Scc_Core') && method_exists('Scc_Core', $m); }

    /**
     * Scc_Router::dispatch runs on init:1, before WooCommerce registers its order types (init:5). wc_get_orders() then fails with
     * "Could not find classname for order ID". Register them early (WC registers them again at init:5, which is harmless).
     */
    public static function boot_wc(): void {
        static $done = false; if ($done) return;
        if (class_exists('WC_Post_Types') && function_exists('wc_get_order_types') && !wc_get_order_types()) WC_Post_Types::register_post_types();
        $done = true;
    }

    public static function get(string $id): ?array {
        if (self::core('order_doc')) { $d = Scc_Core::order_doc($id); return is_array($d) ? $d : null; }
        self::boot_wc();
        $o = self::wc($id); if (!$o) return null;
        return self::doc($o, $id);
    }
    /** Newest first, not archived, at most $limit. */
    public static function by_email(string $email, int $limit = 100): array {
        if (self::core('order_docs_by_email')) return array_values((array) Scc_Core::order_docs_by_email($email, $limit));
        if (!function_exists('wc_get_orders')) return [];
        self::boot_wc();
        $out = [];
        foreach (wc_get_orders(['limit' => 500, 'return' => 'objects', 'meta_query' => [['key' => self::M_EMAIL, 'value' => strtolower($email)]]]) as $o) {
            $id = (string) $o->get_meta(self::M_ID); if ($id === '') continue;
            $d = self::doc($o, $id); if (!$d || Scc_Cx::archived($d)) continue;
            if (strtolower(Scc_Cx::text($d['customer']['email'] ?? '', 254)) !== strtolower($email)) continue;
            $out[] = $d;
        }
        usort($out, fn($a, $b) => strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? '')));
        return array_slice($out, 0, $limit);
    }
    /** Optimistic write: returns the new updatedAt, or null when the stored updatedAt differs from $expected. Caller holds the per-order lock. */
    public static function save(string $id, array $doc, string $expected): ?string {
        $t = Scc_Cx::stamp(max(microtime(true), Scc_Cx::iso_to_ms($expected) / 1000 + 0.001));
        if (self::core('save_order_doc')) { $r = Scc_Core::save_order_doc($id, $doc, $expected, $t); return $r === null || $r === false ? null : (is_string($r) ? $r : $t); }
        self::boot_wc();
        $o = self::wc($id); if (!$o) return null;
        $cur = self::doc($o, $id);
        if (!$cur || ($cur['updatedAt'] ?? '') !== $expected) return null;
        unset($doc['id'], $doc['updatedAt'], $doc['_wcId']);
        $o->update_meta_data(self::M_DOC, wp_json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $o->update_meta_data(self::M_UPD, $t);
        $o->update_meta_data(self::M_EMAIL, strtolower(Scc_Cx::text($doc['customer']['email'] ?? '', 254)));
        $o->save();
        do_action('scc_cx_order_shipment_saved', $id, $o->get_id(), $doc);
        return $t;
    }
    public static function wc_id(array $doc): int {
        if (isset($doc['_wcId'])) return (int) $doc['_wcId'];
        if (self::core('wc_order_id')) return (int) Scc_Core::wc_order_id((string) ($doc['id'] ?? ''));
        self::boot_wc();
        $o = self::wc((string) ($doc['id'] ?? '')); return $o ? $o->get_id() : 0;
    }
    private static function wc(string $id): ?WC_Order {
        if ($id === '' || !function_exists('wc_get_orders')) return null;
        $r = wc_get_orders(['limit' => 1, 'return' => 'objects', 'meta_query' => [['key' => self::M_ID, 'value' => $id]]]);
        return $r ? $r[0] : null;
    }
    private static function doc(WC_Order $o, string $id): ?array {
        $raw = $o->get_meta(self::M_DOC); if ($raw === '' || $raw === null) return null;
        $d = json_decode((string) $raw, true); if (!is_array($d)) return null;
        $upd = (string) $o->get_meta(self::M_UPD);
        if ($upd === '') $upd = Scc_Cx::stamp($o->get_date_modified() ? $o->get_date_modified()->getTimestamp() : time());
        return $d + ['id' => $id, 'updatedAt' => $upd, '_wcId' => $o->get_id()];
    }
}

/** Invoice seam: SHEM DOK is the invoice system on WordPress. */
final class Scc_Cx_Invoice {
    private static function dok(array $order): ?array {
        if (!class_exists('ShemDok')) return null;
        $wc = Scc_Cx_Orders::wc_id($order); if ($wc <= 0) return null;
        return ShemDok::invoice_for_order($wc) ?: null;
    }
    public static function ready(array $order): bool {
        if (class_exists('ShemDok')) return (bool) self::dok($order);
        return (bool) Scc_Docs::get('invoice_documents', (string) $order['id']);
    }
    /** PDF bytes, or null when there is no document yet. */
    public static function pdf(array $order): ?string {
        if (class_exists('ShemDok')) {
            $d = self::dok($order); if (!$d) return null;
            try { $bytes = ShemDok::stored_pdf((int) $d['id']); }
            catch (Throwable $e) { error_log('[scc-cx] invoice pdf: ' . $e->getMessage()); return null; }
            if (class_exists('ShemDok') && method_exists('ShemDok', 'audit')) { try { ShemDok::audit((int) $d['id'], 'customer_download', [], Scc_Cx_Orders::wc_id($order)); } catch (Throwable $e) {} }
            return $bytes;
        }
        $doc = Scc_Docs::get('invoice_documents', (string) $order['id']);
        $bytes = $doc ? apply_filters('scc_cx_invoice_pdf', null, $order, $doc) : null;
        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }
}
