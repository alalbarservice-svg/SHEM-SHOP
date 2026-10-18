<?php
if (!defined('ABSPATH')) exit;
/**
 * Admin API - shared helpers (module owner: admin agent). Loaded first (alphabetical glob).
 * Every admin route is registered through Scc_Admin::route(), which uses Scc_Router::add(..., 'admin')
 * (WP cookie login + manage_options + wp_rest nonce on writes) and adds the original worker guards:
 * same-origin writes (403 "Origin rejected"), JSON content type (415), size limit (413), generic 503 on
 * unexpected failure, and an append-only audit row for every successful write.
 *
 * Extension points (filters/actions) other modules may use - nothing here is stubbed silently:
 *   scc_admin_store_<table>            filter  Scc_Admin_Store|null   replace the storage of products/orders (core agent)
 *   (stock holds are read from Scc_Channel_Stock::holds(); links from the product_links doc table)
 *   scc_admin_product_saved            action  ($record,$previous)    fired once after a product write (channel outbox)
 *   scc_admin_confirm_payment          filter  array|null|WP_Error    override of manual "mark paid" (core/payments agent)
 *   scc_admin_payment_link             filter  array|null             "payment-link" action on an unpaid order (payments agent)
 *   scc_admin_document_render          filter  array|null             PDF/HTML rendering of documents (documents agent)
 *   scc_admin_mail_process             filter  array|null             sends pending mail_jobs (mail agent)
 *   scc_admin_issue_document           filter  array|null             manual invoice issue for a paid order (documents agent)
 */
final class Scc_Admin {
    const GENERIC_503 = 'לא ניתן להשלים את הפעולה כרגע. הנתונים לא אבדו, אפשר לנסות שוב.';
    private static string $last = '';
    /** @var array<string,Scc_Admin_Store> */
    private static array $stores = [];

    /* ------------------------------------------------------------ small JS-compatible helpers */
    public static function now(): string {
        $t = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
        if ($t <= self::$last) { // strictly increasing revisions inside one process
            $d = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', self::$last, new DateTimeZone('UTC'));
            $t = $d->modify('+1 microsecond')->format('Y-m-d\TH:i:s.u\Z');
        }
        return self::$last = $t;
    }
    /** new Date().toISOString() - millisecond ISO used inside record data (createdAt, paidAt, ...). */
    public static function stamp(): string { return self::iso_ms(self::ms()); }
    public static function iso_ms(int $ms): string { return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . '.' . sprintf('%03d', $ms % 1000) . 'Z'; }
    public static function ms(): int { return (int) floor(microtime(true) * 1000); }
    public static function uuid(): string { return wp_generate_uuid4(); }
    public static function fail(string $m, int $s = 400): never { throw new Scc_Http_Exception($m, $s); }
    /** JS: typeof v === "string" ? v.trim().slice(0,n) : "" */
    public static function str(mixed $v, int $n = 500): string {
        if (!is_string($v)) return '';
        $v = preg_replace('/^[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}]+|[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $v) ?? $v;
        return mb_substr($v, 0, $n);
    }
    public static function email(string $v): bool { return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $v); }
    /** JS Number(v) (NAN when not numeric). */
    public static function num(mixed $v): float {
        if ($v === null) return 0.0;
        if (is_bool($v)) return $v ? 1.0 : 0.0;
        if (is_int($v) || is_float($v)) return (float) $v;
        if (is_string($v)) {
            $t = trim($v);
            if ($t === '') return 0.0;
            if (is_numeric($t) && !preg_match('/^[+-]?0[xX]/', $t)) return (float) $t;
            return NAN;
        }
        if (is_array($v) && !$v) return 0.0;
        return NAN;
    }
    public static function is_int_val(float $n): bool { return is_finite($n) && floor($n) == $n; }
    /** JS Math.round (half up). */
    public static function round(float $x): float { return floor($x + 0.5); }
    public static function amount(mixed $v): float { return self::round(self::num($v) * 100); }
    /** Empty list that must serialize as {} */
    public static function obj(array $a): array|stdClass { return $a === [] ? new stdClass() : $a; }
    public static function arr(mixed $v): array { return is_array($v) && array_is_list($v) ? $v : []; }
    /** number -> JSON value like JS (integral => int, NaN/Infinity => null). */
    public static function nv(float $n): int|float|null { if (!is_finite($n)) return null; return (floor($n) == $n && abs($n) < 9.0e15) ? (int) $n : $n; }
    public static function truthy(mixed $v): bool { return !($v === null || $v === false || $v === 0 || $v === 0.0 || $v === '' || $v === [] || (is_float($v) && is_nan($v))); }
    public static function uniq(array $a): array { return array_values(array_unique($a, SORT_REGULAR)); }
    public static function cfg_email(): string { return defined('SCC_ADMIN_CONTACT_EMAIL') ? (string) SCC_ADMIN_CONTACT_EMAIL : 'your.shem88@gmail.com'; }
    public static function is_home(): bool { return SCC_ROLE === 'shimi'; } // original SHOP_ROLE=home is SHIMI (peer origin of home is SHEM)
    public static function brand(): string { return SCC_ROLE === 'shimi' ? 'SHIMI' : 'SHEM'; }

    /* ------------------------------------------------------------ storage */
    public static function store(string $table): Scc_Admin_Store {
        if (!isset(self::$stores[$table])) {
            $s = apply_filters('scc_admin_store_' . $table, null);
            self::$stores[$table] = $s instanceof Scc_Admin_Store ? $s : new Scc_Admin_Store($table);
        }
        return self::$stores[$table];
    }
    public static function tx(callable $fn): mixed {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try { $r = $fn(); $wpdb->query('COMMIT'); return $r; }
        catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }
    /** settings value (original: {} when missing). */
    public static function setting(string $key): mixed {
        $v = Scc_Docs::setting($key, null);
        return $v === null ? [] : $v;
    }
    public static function save_setting(string $key, mixed $value): void { Scc_Docs::set_setting($key, $value); }

    /* ------------------------------------------------------------ request helpers */
    /** $lang 'en' => api.js wording ("Invalid JSON"); 'he' => management wording. */
    public static function body(Scc_Request $r, string $lang = 'he'): array {
        $raw = $r->raw;
        if (strlen($raw) > 1000000) self::fail($lang === 'en' ? 'Request too large' : 'הבקשה גדולה מדי.', 413);
        $d = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($d)) self::fail($lang === 'en' ? 'Invalid JSON' : 'בקשה לא תקינה.');
        return $d;
    }
    public static function user_label(): string { $u = wp_get_current_user(); return $u && $u->ID ? 'wp:' . $u->ID . ':' . $u->user_login : 'unknown'; }
    private static function origins(): array {
        $out = [];
        foreach ([home_url(), site_url()] as $u) {
            $p = wp_parse_url($u);
            if (!empty($p['host'])) $out[] = strtolower(($p['scheme'] ?? 'http') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : ''));
        }
        if (!empty($_SERVER['HTTP_HOST'])) $out[] = strtolower((is_ssl() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);
        return array_unique($out);
    }
    public static function json(mixed $data, int $status = 200): Scc_Response {
        return Scc_Response::json($data, $status, ['x-content-type-options' => 'nosniff']);
    }

    /** Register an admin route (cookie + manage_options + nonce on writes are enforced by Scc_Router 'admin'). */
    public static function route(string $method, string $regex, callable $fn, array $o = []): void {
        Scc_Router::add($method, $regex, function (Scc_Request $r) use ($fn, $o) { return self::guarded($r, $fn, $o); }, 'admin');
    }
    private static function guarded(Scc_Request $r, callable $fn, array $o): Scc_Response {
        if ($r->method !== 'GET') {
            if (!in_array(strtolower($r->header('origin')), self::origins(), true)) self::fail('Origin rejected', 403);
            if (empty($o['media'])) {
                if (stripos($r->header('content-type'), 'application/json') === false) self::fail('JSON required', 415);
                if (strlen($r->raw) > 1000000) self::fail('Request too large', 413);
            }
        }
        try { $res = call_user_func($fn, $r); }
        catch (Scc_Http_Exception $e) { throw $e; }
        catch (Throwable $e) { error_log('[scc-admin] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine()); self::fail(self::GENERIC_503, 503); }
        if (!($res instanceof Scc_Response)) $res = self::json($res);
        if ($r->method !== 'GET' && $res->status >= 200 && $res->status < 300) self::audit($r->method . ' ' . $r->path);
        return $res;
    }

    /* ------------------------------------------------------------ audit (append-only) */
    public static function audit(string $action): void {
        global $wpdb;
        try {
            $id = self::uuid(); $at = gmdate('Y-m-d\TH:i:s') . '.' . sprintf('%03d', self::ms() % 1000) . 'Z';
            $wpdb->insert(Scc_Docs::t('audit'), ['id' => $id, 'data' => wp_json_encode(['id' => $id, 'actor' => self::user_label(), 'action' => $action, 'created_at' => $at], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => $at]);
        } catch (Throwable $e) { error_log('[scc-admin] audit write failed'); }
    }
    /** Create the audit immutability triggers once (UPDATE/DELETE on the audit table are rejected by the database). */
    public static function install(): void {
        global $wpdb;
        $v = 'admin-2';
        if (get_option('scc_admin_db_version') === $v) return;
        $t = Scc_Docs::t('audit');
        $wpdb->suppress_errors(true);
        $ok = true;
        foreach (['UPDATE' => 'upd', 'DELETE' => 'del'] as $ev => $s) {
            $name = $t . '_no_' . $s;
            $wpdb->query("DROP TRIGGER IF EXISTS `$name`");
            $r = $wpdb->query("CREATE TRIGGER `$name` BEFORE $ev ON `$t` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit log is append-only'");
            if ($r === false) $ok = false;
        }
        $wpdb->suppress_errors(false);
        update_option('scc_admin_audit_immutable', $ok ? '1' : '0', false);
        update_option('scc_admin_db_version', $v, false); // attempted once; audit_immutable records the outcome
    }
}

/** Document-table storage with revision (updated_at) compare-and-swap. Table = Scc_Docs table (wp_scc_<table>). */
class Scc_Admin_Store {
    public function __construct(public string $table) {}
    protected function t(): string { return Scc_Docs::t($this->table); }
    protected static function row_out(array $r): array {
        $d = json_decode($r['data'], true); if (!is_array($d)) $d = [];
        return array_merge($d, ['id' => $r['id'], 'revision' => $r['updated_at']]);
    }
    /** @return array<int,array> newest first, each {...data,id,revision} */
    public function rows(int $limit = 1000): array {
        global $wpdb;
        $out = [];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT id,data,updated_at FROM ' . $this->t() . ' ORDER BY updated_at DESC, id DESC LIMIT %d', $limit), ARRAY_A) as $r) $out[] = static::row_out($r);
        return $out;
    }
    public function get(string $id): ?array {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT id,data,updated_at FROM ' . $this->t() . ' WHERE id=%s', $id), ARRAY_A);
        return $r ? static::row_out($r) : null;
    }
    public function exists(string $id): bool { global $wpdb; return (bool) $wpdb->get_var($wpdb->prepare('SELECT 1 FROM ' . $this->t() . ' WHERE id=%s', $id)); }
    public function count(): int { global $wpdb; return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $this->t()); }
    protected static function enc(array $d): string { unset($d['revision']); return wp_json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    /** INSERT; false when the id already exists. */
    public function insert(string $id, array $data, ?string $rev = null): string|false {
        global $wpdb; $rev ??= Scc_Admin::now();
        $wpdb->suppress_errors(true);
        $r = $wpdb->query($wpdb->prepare('INSERT INTO ' . $this->t() . ' (id,data,updated_at) VALUES (%s,%s,%s)', $id, self::enc($data), $rev));
        $wpdb->suppress_errors(false);
        return $r ? $rev : false;
    }
    /** UPDATE ... WHERE id AND updated_at=expected. false when the revision moved on. */
    public function cas(string $id, array $data, string $expected, ?string $rev = null): string|false {
        global $wpdb; $rev ??= Scc_Admin::now();
        $r = $wpdb->query($wpdb->prepare('UPDATE ' . $this->t() . ' SET data=%s,updated_at=%s WHERE id=%s AND updated_at=%s', self::enc($data), $rev, $id, $expected));
        return $r ? $rev : false;
    }
    public function upsert(string $id, array $data, ?string $rev = null): string {
        global $wpdb; $rev ??= Scc_Admin::now();
        $wpdb->query($wpdb->prepare('INSERT INTO ' . $this->t() . ' (id,data,updated_at) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE data=VALUES(data),updated_at=VALUES(updated_at)', $id, self::enc($data), $rev));
        return $rev;
    }
    /** First row whose JSON path equals value (excluding id). */
    public function find_json(string $path, string $value, string $exclude = '', bool $lower = false): ?array {
        global $wpdb;
        $col = $lower ? 'LOWER(JSON_VALUE(data,%s))' : 'JSON_VALUE(data,%s)';
        $r = $wpdb->get_row($wpdb->prepare('SELECT id,data,updated_at FROM ' . $this->t() . " WHERE $col=%s AND id<>%s LIMIT 1", '$.' . $path, $value, $exclude), ARRAY_A);
        return $r ? static::row_out($r) : null;
    }
}

add_action('scc_modules_loaded', ['Scc_Admin', 'install']);

/* ---------------------------------------------------------------- session + login mapping */
// GET /api/admin/session - same shape as the worker plus the wp_rest nonce the browser must echo in X-WP-Nonce on writes.
Scc_Admin::route('GET', '#^/api/admin/session$#', function (Scc_Request $r) {
    $u = wp_get_current_user();
    return Scc_Admin::json([
        'authorized' => true,
        'contactEmail' => Scc_Admin::cfg_email(),
        'signedInEmail' => (string) $u->user_email,
        'ownershipTransferred' => false,
        'localPreview' => false,
        'nonce' => wp_create_nonce('wp_rest'),
    ]);
});
// GET /api/session - identity probe used by the admin login screen ({signedIn,userId,email}).
Scc_Router::add('GET', '#^/api/session$#', function (Scc_Request $r) {
    $u = wp_get_current_user(); $in = $u && $u->ID > 0;
    return Scc_Response::json(['signedIn' => $in, 'userId' => $in ? (string) $u->ID : null, 'email' => $in ? (string) $u->user_email : null]);
}, 'public');

// /signin-with-chatgpt?return_to=... -> WordPress login (the original platform sign-in URL baked into the bundle).
add_action('init', function () {
    $p = '/' . trim(rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
    if ($p !== '/signin-with-chatgpt') return;
    $to = isset($_GET['return_to']) ? (string) wp_unslash($_GET['return_to']) : '/';
    if ($to === '' || $to[0] !== '/' || preg_match('#^/[/\\\\]#', $to) || preg_match('/[\r\n]/', $to)) $to = '/';
    $target = home_url($to);
    wp_safe_redirect(is_user_logged_in() ? $target : wp_login_url($target));
    exit;
}, 0);

// Compatibility shim: the compiled admin bundle (src/admin-api.js) sends plain same-origin fetch() calls and no nonce.
// For logged-in managers we add X-WP-Nonce to /api/admin/* requests (and refresh it once on a 401). No bundle change needed.
function scc_admin_nonce_shim(): void {
    static $done = false; if ($done) return; $done = true;
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    $n = wp_create_nonce('wp_rest');
    echo '<script id="scc-admin-nonce">(function(){var N=' . wp_json_encode($n) . ',F=window.fetch;if(!F)return;function adm(i){try{var u=new URL(typeof i==="string"?i:(i&&i.url)||"",location.href);return u.origin===location.origin&&u.pathname.indexOf("/api/admin/")===0}catch(e){return false}}'
        . 'window.fetch=function(i,o){if(!adm(i))return F.call(this,i,o);function go(){o=Object.assign({},o||{});var h=new Headers(o.headers||(i&&i.headers)||{});h.set("X-WP-Nonce",N);o.headers=h;return F.call(window,i,o)}'
        . 'return go().then(function(r){if(r.status!==401)return r;return F.call(window,"/api/admin/session",{credentials:"same-origin"}).then(function(s){return s.ok?s.json():null}).then(function(j){if(j&&j.nonce){N=j.nonce;return go()}return r})})}})();</script>' . "\n";
}
// The migrated themes render the React shell through do_action('storefront_shell_head') and never call wp_head, so hook both.
add_action('wp_head', 'scc_admin_nonce_shim', 1);
add_action('storefront_shell_head', 'scc_admin_nonce_shim', 1);
