<?php
if (!defined('ABSPATH')) exit;
/**
 * Core shop API - JS-compatible helpers (module owner: core agent).
 * Everything here exists to reproduce the original worker's observable behavior 1:1:
 * JS Math.round, agorot arithmetic, JSON.stringify number/unicode formatting, trim/slice, email regex.
 */
final class Scc_Core_Util {
    /** JS Math.round: halves go up (also for negatives: -2.5 -> -2). */
    public static function js_round(float|int $x): float { return floor($x + 0.5); }
    /** Math.round(Number(v) * 100) */
    public static function amount(mixed $v): int { return (int) self::js_round(((float) (is_numeric($v) ? $v : 0)) * 100); }
    /** cents -> shekel number (as the worker prints it: whole values are ints). */
    public static function shekel(int $cents): int|float { return self::n($cents / 100); }
    /** Whole floats become ints so json_encode prints 129 and not 129.0 (JSON.stringify behavior). */
    public static function n(float|int $v): int|float {
        if (is_float($v) && is_finite($v) && floor($v) == $v && abs($v) < 9.0e15) return (int) $v;
        return $v;
    }
    /** Recursively normalise whole floats (arrays and objects). */
    public static function norm(mixed $v): mixed {
        if (is_float($v)) return self::n($v);
        if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = self::norm($x); return $v; }
        if ($v instanceof stdClass) { foreach ($v as $k => $x) $v->$k = self::norm($x); return $v; }
        return $v;
    }
    /** JS: typeof v === 'string' ? v.trim().slice(0,max) : '' */
    public static function clean(mixed $v, int $max = 200): string {
        if (!is_string($v)) return '';
        $t = preg_replace('/^[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}]+|[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $v);
        if ($t === null) $t = trim($v);
        return mb_substr($t, 0, $max);
    }
    public static function is_email(string $v): bool { return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+\z/u', $v); }
    public static function uuid(): string { return wp_generate_uuid4(); }
    /** new Date().toISOString() */
    public static function now_iso(?float $t = null): string {
        $ms = (int) round(($t ?? microtime(true)) * 1000);
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }
    /** YYYY-MM-DD in Asia/Jerusalem. */
    public static function today_jerusalem(?int $ts = null): string {
        $d = new DateTime('@' . ($ts ?? time())); $d->setTimezone(new DateTimeZone('Asia/Jerusalem')); return $d->format('Y-m-d');
    }
    /** JS truthiness. */
    public static function truthy(mixed $v): bool {
        if ($v === null || $v === false || $v === 0 || $v === 0.0 || $v === '') return false;
        if (is_float($v) && is_nan($v)) return false;
        return true;
    }
    public static function num(mixed $v): int|float { return is_int($v) || is_float($v) ? $v : (is_numeric($v) ? $v + 0 : 0); }
    /** Number.isInteger */
    public static function is_int_val(mixed $v): bool { return is_int($v) || (is_float($v) && is_finite($v) && floor($v) == $v); }
    /** JSON.stringify-compatible encoding. */
    public static function enc(mixed $v): string {
        return (string) json_encode(self::norm($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }
    /** Response with exact JSON.stringify formatting and the worker's headers. */
    public static function json(mixed $data, int $status = 200, array $headers = []): Scc_Response {
        return Scc_Response::raw(self::enc($data), 'application/json; charset=utf-8', $status,
            $headers + ['cache-control' => 'no-store', 'x-content-type-options' => 'nosniff']);
    }
    public static function fail(string $m, int $status = 400): never { throw new Scc_Http_Exception($m, $status); }
    /** Original api.js guards for every non-GET public request. */
    public static function guard_mutation(Scc_Request $r): void {
        $origin = $r->header('origin');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $hp = wp_parse_url(home_url());
        $home = ($hp['scheme'] ?? 'http') . '://' . ($hp['host'] ?? '') . (isset($hp['port']) ? ':' . $hp['port'] : '');
        $ok = $origin !== '' && in_array($origin, [(is_ssl() ? 'https' : 'http') . '://' . $host, $home], true);
        if (!$ok) self::fail('Origin rejected', 403);
        if (stripos($r->header('content-type'), 'application/json') === false) self::fail('JSON required', 415);
        if ((int) $r->header('content-length') > 1000000) self::fail('Request too large', 413);
    }
    /** operations.js / management.js parse(): 1,000,000 chars, Hebrew errors. Returns the decoded JSON value (array, scalar or null). */
    public static function parse(Scc_Request $r, string $invalid = 'בקשה לא תקינה.'): mixed {
        if (mb_strlen($r->raw) > 1000000) self::fail('הבקשה גדולה מדי.', 413);
        $d = json_decode($r->raw, true);
        if ($d === null && json_last_error() !== JSON_ERROR_NONE) self::fail($invalid);
        return $d;
    }
    /** `d.x` on a possibly non-object body. */
    public static function obj(mixed $d): array { return is_array($d) && !array_is_list($d) ? $d : []; }
    /** Settings value; {} (empty array) when missing (management.js setting()). */
    public static function setting(string $key): array {
        $v = Scc_Docs::setting($key, null);
        return is_array($v) ? $v : [];
    }
    /** Constant-time hex HMAC-SHA256 compare. */
    public static function sign(string $secret, string $message): string { return hash_hmac('sha256', $message, $secret); }
    public static function lock(string $name, int $wait = 5): bool {
        global $wpdb; return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)', substr('scc_core_' . md5($name), 0, 60), $wait)) === '1';
    }
    public static function unlock(string $name): void {
        global $wpdb; $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', substr('scc_core_' . md5($name), 0, 60)));
    }
    /** Integer round-half-up of (n*num)/den for non-negative n (exact, no float error). */
    public static function ratio_round(int $n, int $num, int $den): int { return intdiv(2 * $n * $num + $den, 2 * $den); }
    /** Largest-remainder split of $total across $weights (non-negative ints). Sum of result == $total. */
    public static function allocate(int $total, array $weights): array {
        $sum = array_sum($weights); $n = count($weights);
        if ($n === 0) return [];
        if ($sum <= 0) { $out = array_fill(0, $n, 0); $out[0] = $total; return $out; }
        $out = []; $rem = [];
        foreach ($weights as $i => $w) { $q = intdiv($total * $w, $sum); $out[$i] = $q; $rem[$i] = ($total * $w) % $sum; }
        $left = $total - array_sum($out);
        arsort($rem, SORT_NUMERIC);
        foreach (array_keys($rem) as $i) { if ($left <= 0) break; $out[$i]++; $left--; }
        ksort($out);
        return $out;
    }
}
