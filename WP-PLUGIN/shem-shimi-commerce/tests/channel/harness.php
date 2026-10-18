<?php
/**
 * Channel test harness (run inside WordPress):  /home/claude/wps.sh shem eval-file wp-content/plugins/shem-shimi-commerce/tests/channel/unit.php
 * Staging only. Every fixture id starts with t_channel_; cleanup() removes only those rows. The two stores are simulated in one process:
 * the other store is the same plugin code run with the opposite role (filter scc_channel_is_home) behind a stubbed pre_http_request,
 * so signing, routing, status codes and Hebrew errors go through the real code paths. Nothing here prints a secret.
 */
if (!defined('ABSPATH')) exit;
$GLOBALS['T'] = ['pass' => 0, 'fail' => 0, 'fails' => [], 'section' => '', 'notrun' => []];
function section(string $s): void { $GLOBALS['T']['section'] = $s; echo "\n== $s\n"; }
function ok(string $name, bool $cond, mixed $detail = null): void {
    if ($cond) { $GLOBALS['T']['pass']++; echo "  ok   $name\n"; return; }
    $GLOBALS['T']['fail']++; $GLOBALS['T']['fails'][] = $GLOBALS['T']['section'] . ': ' . $name;
    echo "  FAIL $name" . ($detail !== null ? ' :: ' . (is_string($detail) ? $detail : wp_json_encode($detail, JSON_UNESCAPED_UNICODE)) : '') . "\n";
}
function eq(string $name, mixed $actual, mixed $expected): void { ok($name, $actual === $expected || (is_numeric($actual) && is_numeric($expected) && (float) $actual === (float) $expected && !is_string($actual)), ['actual' => $actual, 'expected' => $expected]); }
/** Expect a Scc_Http_Exception with the exact Hebrew/English message and status. */
function throws(string $name, callable $fn, ?string $msg = null, ?int $status = null): void {
    try { $fn(); ok($name . ' (expected error)', false, 'no exception'); }
    catch (Scc_Http_Exception $e) { ok($name, ($msg === null || $e->getMessage() === $msg) && ($status === null || $e->status === $status), ['msg' => $e->getMessage(), 'status' => $e->status]); }
    catch (Throwable $e) { ok($name, false, get_class($e) . ': ' . $e->getMessage()); }
}
function notrun(string $what): void { $GLOBALS['T']['notrun'][] = $what; echo "  NOT-RUN $what\n"; }

/* ---------------- environment ---------------- */
$GLOBALS['t_role_home'] = null; $GLOBALS['t_time'] = 0; $GLOBALS['t_peer_log'] = []; $GLOBALS['t_peer_mode'] = 'sim';
add_filter('scc_channel_is_home', fn($d) => $GLOBALS['t_role_home'] ?? $d);
add_filter('scc_channel_time', fn($t) => $t + $GLOBALS['t_time']);
add_filter('scc_channel_allow_insecure', '__return_true');
add_filter('scc_channel_backoff', fn($s) => $s);
function as_home(bool $home, callable $fn): mixed { $prev = $GLOBALS['t_role_home']; $GLOBALS['t_role_home'] = $home; try { return $fn(); } finally { $GLOBALS['t_role_home'] = $prev; } }
function use_docs(): void { Scc_Channel_Catalog::use_backend(new Scc_Channel_Catalog_Docs()); }

/** Build a request object the way the router would. */
function mkreq(string $method, string $path, string $raw, array $headers = [], array $query = []): Scc_Request {
    $r = new Scc_Request(); $r->method = $method; $r->path = $path; $r->raw = $raw; $r->headers = array_change_key_case($headers); $r->query = $query; return $r;
}
function signed_headers(string $path, string $raw, ?string $secret = null, ?int $ts = null, bool $legacy = false): array {
    $secret ??= Scc_Channel::secret(); $ts = (string) ($ts ?? Scc_Channel::ts());
    return $legacy ? ['x-shem-timestamp' => $ts, 'x-shem-signature' => Scc_Channel::sign($secret, $ts . '.' . $raw)]
                   : ['x-channel-timestamp' => $ts, 'x-channel-signature' => Scc_Channel::sign($secret, implode('.', [$ts, 'POST', $path, $raw]))];
}
/** Deliver a signed channel request to the OTHER store (opposite role) and return [status, decoded body]. */
function peer_call_sim(string $path, string $raw, array $headers): array {
    $r = mkreq('POST', $path, $raw, $headers);
    $cur = Scc_Channel::is_home();
    $res = as_home(!$cur, function () use ($r) {
        try { $x = Scc_Channel::wrap(['Scc_Channel_Routes', 'signed'])($r); }
        catch (Throwable $e) { $x = Scc_Response::error('boom ' . $e->getMessage(), 500); }
        return $x;
    });
    return [$res->status, is_array($res->body) ? $res->body : json_decode((string) $res->body, true)];
}
add_filter('pre_http_request', function ($pre, $args, $url) {
    $base = Scc_Channel::peer_url();
    if ($base === '' || strpos($url, $base) !== 0) return $pre;
    $path = substr($url, strlen($base));
    $mode = $GLOBALS['t_peer_mode'];
    $GLOBALS['t_peer_log'][] = $path;
    if ($mode === 'down') return new WP_Error('http_request_failed', 'connection refused (simulated)');
    if (is_callable($mode)) { $out = $mode($path, (string) $args['body'], $args); if ($out !== null) return is_wp_error($out) ? $out : ['headers' => [], 'body' => wp_json_encode($out[1]), 'response' => ['code' => $out[0], 'message' => '']]; }
    [$st, $b] = peer_call_sim($path, (string) $args['body'], $args['headers']);
    return ['headers' => [], 'body' => wp_json_encode($b), 'response' => ['code' => $st, 'message' => '']];
}, 10, 3);
function peer_count(string $path = ''): int { return count(array_filter($GLOBALS['t_peer_log'], fn($p) => $path === '' || $p === $path)); }
function peer_reset(): void { $GLOBALS['t_peer_log'] = []; }

/* ---------------- fixtures ---------------- */
function mkp(string $id, array $o = []): array {
    $p = array_merge(['id' => $id, 'he' => 't_channel item ' . $id, 'sku' => 'TC-' . strtoupper($id), 'price' => 100, 'stock' => 4, 'sizes' => ['M'], 'sizeStock' => ['M' => 4], 'color' => 'black', 'active' => true, 'variants' => [], 'brand' => 'SHEM', 'image' => 'knit'], $o);
    Scc_Docs::put('products', $id, $p); return $p;
}
function pget(string $id): array { return Scc_Docs::get('products', $id); }
function cleanup(): void {
    global $wpdb;
    /* Only data created by these tests: ids prefixed t_channel_ (docs-level tests) or t-channel- (ids that go through URL/route patterns, which only allow [a-zA-Z0-9-]). */
    foreach (['t\\_channel\\_%', 't-channel-%'] as $like) {
        $pre = rtrim($like, '%');
        foreach (['products', 'product_links', 'payment_sessions', 'orders', 'promotions'] as $t) $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Docs::t($t) . ' WHERE id LIKE %s', $like));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Docs::t('payment_sessions') . ' WHERE id LIKE %s OR id LIKE %s', 'shem:' . $like, 'shimi:' . $like));
        foreach (['reservations' => 'reservation_id', 'ledger' => 'product_id', 'locks' => 'product_id', 'link_keys' => 'legacy_id', 'inbox' => 'event_id'] as $t => $col) {
            $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Channel_Tx::t($t) . " WHERE $col LIKE %s OR $col LIKE %s", $like, 'shimi:' . $like));
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Channel_Tx::t('reservations') . ' WHERE product_id LIKE %s', $like));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Channel_Tx::t('outbox') . ' WHERE event_id LIKE %s OR domain_key LIKE %s OR payload LIKE %s', 'refresh:' . $like, '%' . $pre . '%', '%' . $pre . '%'));
    }
    /* coupon versions: drop only our own test coupon keys, never the whole option */
    $m = get_option('scc_channel_coupon_ver', []);
    if (is_array($m)) { foreach (array_keys($m) as $k) if (stripos((string) $k, 'T-CHANNEL') === 0 || stripos((string) $k, 'T_CHANNEL') === 0) unset($m[$k]); update_option('scc_channel_coupon_ver', $m, false); }
}
function finish(): void {
    $T = $GLOBALS['T'];
    echo "\n== SUMMARY: pass={$T['pass']} fail={$T['fail']} not-run=" . count($T['notrun']) . "\n";
    foreach ($T['fails'] as $f) echo "  FAILED: $f\n";
    echo '@@' . wp_json_encode(['pass' => $T['pass'], 'fail' => $T['fail'], 'fails' => $T['fails'], 'notrun' => $T['notrun']], JSON_UNESCAPED_UNICODE) . "\n";
}
