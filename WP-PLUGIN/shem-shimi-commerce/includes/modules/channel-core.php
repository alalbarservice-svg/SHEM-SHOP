<?php
if (!defined('ABSPATH')) exit;
/**
 * Channel plugin - core helpers (SHEM <-> SHIMI). Port of SHEM/worker/channel.js (identical to SHIMI/worker/channel.js;
 * the only difference between the two stores is the role: SCC_ROLE 'shem' = source / stock authority (JS: SHOP_ROLE != home),
 * 'shimi' = linked home store (JS: SHOP_ROLE === 'home')).
 *
 * Constants (wp-config.php only, never in the database):
 *   SCC_PEER_URL          server-to-server base URL of the other store (staging: http://shimi | http://shem)
 *   SCC_PEER_PUBLIC_URL   optional second origin accepted in copied source URLs (public/browser origin of the SHEM store)
 *   SCC_CHANNEL_SECRET    HMAC secret shared by both stores (64 hex)
 *   SCC_HOME_PAYMENT_SECRET / SHIMI_HOME_PAYMENT_SECRET   shem-home-v1 secret (SHIMI side)
 *   SCC_PAYMENT_ADAPTER_URL / _SECRET / _ENABLED / _CHECKOUT_ORIGIN   generic provider adapter (disabled unless all set)
 *   SCC_CHANNEL_INLINE    false = never try an inline delivery at shutdown (queue only)
 */
final class Scc_Channel {
    public static function is_home(): bool { return (bool) apply_filters('scc_channel_is_home', defined('SCC_ROLE') && SCC_ROLE === 'shimi'); } // filter exists for tests only
    public static function role(): string { return self::is_home() ? 'home' : 'source'; }
    public static function secret(): string { return defined('SCC_CHANNEL_SECRET') && is_string(SCC_CHANNEL_SECRET) ? SCC_CHANNEL_SECRET : ''; }
    public static function peer_url(): string {
        $u = defined('SCC_PEER_URL') ? rtrim((string) SCC_PEER_URL, '/') : '';
        return (string) apply_filters('scc_channel_peer_url', $u);
    }
    public static function now(): string { $t = microtime(true); return gmdate('Y-m-d\TH:i:s', (int) $t) . sprintf('.%03dZ', (int) (($t - floor($t)) * 1000)); }
    public static function ts(): int { return (int) apply_filters('scc_channel_time', time()); }
    public static function fail(string $m, int $s = 409): never { throw new Scc_Http_Exception($m, $s); }
    public static function json(mixed $v): string { return (string) wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    public static function sign(string $secret, string $input): string { return hash_hmac('sha256', $input, $secret); }
    public static function insecure_ok(): bool { return (bool) apply_filters('scc_channel_allow_insecure', wp_get_environment_type() !== 'production'); }

    /** scheme://host[:port] of a URL, default ports dropped; null when not http(s). */
    public static function origin_of(string $url): ?string {
        $p = wp_parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return null;
        $s = strtolower($p['scheme']);
        if (!in_array($s, ['http', 'https'], true)) return null;
        $port = $p['port'] ?? null;
        if (($s === 'http' && $port === 80) || ($s === 'https' && $port === 443)) $port = null;
        return $s . '://' . strtolower($p['host']) . ($port ? ':' . $port : '');
    }
    /** Origins a copied source URL may carry (peerOrigins in the worker). */
    public static function peer_origins(): array {
        $out = [];
        foreach ([self::peer_url(), defined('SCC_PEER_PUBLIC_URL') ? (string) SCC_PEER_PUBLIC_URL : ''] as $u) {
            if ($u === '') continue;
            $o = self::origin_of($u);
            if ($o === null) self::fail('כתובת חנות לא תקינה.');
            if (strpos($o, 'https://') !== 0 && !self::insecure_ok()) self::fail('כתובת חנות לא תקינה.');
            $out[$o] = true;
        }
        return array_keys($out);
    }

    /** Verify an inbound signed request. $legacy = shem-home-v1 style (x-shem-*, ts.raw). Returns the decoded JSON body. */
    public static function verify(Scc_Request $r, string $secret, bool $legacy = false): array {
        if ($secret === '') self::fail('חיבור בין החנויות טרם הוגדר.', 503);
        $ts = $r->header($legacy ? 'x-shem-timestamp' : 'x-channel-timestamp');
        $sig = strtolower($r->header($legacy ? 'x-shem-signature' : 'x-channel-signature'));
        if ($ts === '' || !ctype_digit($ts) || abs(self::ts() - (int) $ts) > 300) self::fail('תוקף חתימה פג.', 401);
        if (strlen($r->raw) > 200000) self::fail('בקשה גדולה מדי.', 413);
        $input = $legacy ? $ts . '.' . $r->raw : implode('.', [$ts, $r->method, $r->path, $r->raw]);
        if (!preg_match('/^[a-f0-9]{64}$/', $sig)) self::fail('חתימה חסרה.', 401);
        if (!hash_equals(self::sign($secret, $input), $sig)) self::fail('חתימה שגויה.', 401);
        $d = json_decode($r->raw, true);
        if (!is_array($d)) self::fail('בקשה לא תקינה.', 400);
        return $d;
    }

    /** Signed POST to the other store. A fresh timestamp is signed on every call (retries reuse the business event id in the body). */
    public static function peer(string $path, array $d, array $opt = []): array {
        $base = self::peer_url();
        if ($base === '') self::fail('חיבור בין החנויות טרם הוגדר.', 503);
        if (strpos($base, 'https://') !== 0 && !self::insecure_ok()) self::fail('כתובת חנות לא תקינה.');
        $secret = self::secret();
        if ($secret === '') self::fail('חיבור בין החנויות טרם הוגדר.', 503);
        $raw = self::json($d === [] ? (object) [] : $d);
        $ts = (string) self::ts();
        $res = wp_remote_post($base . $path, [
            'timeout' => $opt['timeout'] ?? 15, 'redirection' => 0, 'body' => $raw, 'blocking' => true,
            'headers' => ['content-type' => 'application/json', 'x-channel-timestamp' => $ts,
                'x-channel-signature' => self::sign($secret, implode('.', [$ts, 'POST', $path, $raw]))],
        ]);
        if (is_wp_error($res)) self::fail('החנות המקושרת אינה זמינה.', 503);
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code >= 300 && $code < 400) self::fail('החנות המקושרת החזירה הפניה לא מאושרת.', 502);
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($j)) self::fail('החנות המקושרת אינה זמינה.', $code >= 400 ? $code : 503);
        if ($code < 200 || $code >= 300) self::fail((string) ($j['error'] ?? 'החנות המקושרת אינה זמינה.'), $code === 401 ? 502 : $code);
        return $j;
    }

    /** Route wrapper: same error contract as the worker (status errors keep their message, anything else = 503 generic). */
    public static function wrap(callable $fn): callable {
        return function (Scc_Request $r) use ($fn) {
            try { return $fn($r); }
            catch (Scc_Http_Exception $e) { return Scc_Response::error($e->getMessage(), $e->status); }
            catch (Throwable $e) { error_log('[scc-channel] ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); return Scc_Response::error('לא ניתן להשלים את הפעולה. יש לבדוק את החיבור.', 503); }
        };
    }
    public static function body_json(Scc_Request $r): array {
        if (strlen($r->raw) > 200000) self::fail('בקשה גדולה מדי.', 413);
        $d = json_decode($r->raw, true);
        if (!is_array($d)) self::fail('בקשה לא תקינה.', 400);
        return $d;
    }
    /** Same-origin check for browser-facing POSTs (Origin must match this site). */
    public static function same_origin(Scc_Request $r): bool {
        $o = $r->header('origin');
        if ($o === '') return false;
        $o = self::origin_of($o);
        if ($o === null) return false;
        $mine = array_filter([self::origin_of(home_url()), self::origin_of(site_url())]);
        return in_array($o, $mine, true);
    }
    /**
     * Per-coupon version used inside review tokens: explicit bump counter (hooks, see Scc_Channel_Events) plus a short hash of the stored
     * promotion document, so a coupon edited through any path (admin API, import, direct DB) invalidates earlier reviews as well.
     */
    public static function coupon_version(string $code): string {
        global $wpdb; $code = strtoupper(trim($code));
        if ($code === '') return '0';
        $m = get_option('scc_channel_coupon_ver', []);
        $n = (int) (is_array($m) ? ($m[$code] ?? 0) : 0);
        $h = '';
        $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id,data,updated_at FROM ' . Scc_Docs::t('promotions') . ' WHERE UPPER(JSON_UNQUOTE(JSON_EXTRACT(data,%s)))=%s ORDER BY id', '$.code', $code), ARRAY_A);
        $wpdb->suppress_errors(false);
        foreach ($rows ?: [] as $r) $h .= $r['id'] . '|' . $r['updated_at'] . '|' . $r['data'] . ';';
        return $n . '-' . ($h === '' ? '0' : substr(hash('sha256', $h), 0, 12));
    }
}

/** Plain DB error as an exception so transactions roll back; deadlocks are retried by Scc_Channel_Tx. */
final class Scc_Channel_Db_Exception extends RuntimeException { public bool $retryable = false; }

/** Small transaction helper on the shared $wpdb connection (READ COMMITTED, per-product lock rows, deadlock retry). */
final class Scc_Channel_Tx {
    private static int $depth = 0;
    public static function t(string $n): string { global $wpdb; return $wpdb->prefix . 'scc_channel_' . $n; }
    /** Execute a write; throws on SQL error. Returns affected rows. */
    public static function exec(string $sql): int {
        global $wpdb;
        $wpdb->suppress_errors(true);
        $wpdb->last_error = '';
        $r = $wpdb->query($sql);
        $err = $wpdb->last_error;
        $wpdb->suppress_errors(false);
        if ($r === false || $err !== '') {
            $e = new Scc_Channel_Db_Exception($err ?: 'db error');
            $e->retryable = (bool) preg_match('/deadlock|lock wait timeout/i', $err);
            throw $e;
        }
        return (int) $r;
    }
    public static function in_tx(): bool { return self::$depth > 0; }
    public static function run(callable $fn, int $retries = 4): mixed {
        if (self::$depth > 0) return $fn();
        for ($i = 0;; $i++) {
            self::$depth = 1;
            try {
                self::exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                self::exec('START TRANSACTION');
                $res = $fn();
                self::exec('COMMIT');
                self::$depth = 0;
                return $res;
            } catch (Throwable $e) {
                global $wpdb;
                $wpdb->query('ROLLBACK');
                self::$depth = 0;
                if ($e instanceof Scc_Channel_Db_Exception && $e->retryable && $i < $retries) { usleep(random_int(15000, 90000)); continue; }
                throw $e;
            }
        }
    }
    /** Make sure a lock row exists (autocommit, before the transaction), then lock the rows in a stable order. */
    public static function ensure_locks(array $ids): array {
        global $wpdb;
        $ids = array_values(array_unique(array_map('strval', $ids)));
        sort($ids, SORT_STRING);
        foreach ($ids as $id) $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::t('locks') . ' (product_id) VALUES (%s)', $id));
        return $ids;
    }
    public static function lock(array $sorted_ids): void {
        global $wpdb;
        foreach ($sorted_ids as $id) { self::exec($wpdb->prepare('SELECT product_id FROM ' . self::t('locks') . ' WHERE product_id=%s FOR UPDATE', $id)); }
    }
}
