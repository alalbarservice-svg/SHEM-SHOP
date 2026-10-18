<?php
if (!defined('ABSPATH')) exit;
/**
 * Route table. Modules call Scc_Router::add() on load.
 *   Scc_Router::add('GET', '#^/api/catalogue$#', function (Scc_Request $r) { return Scc_Response::json([...]); }, 'public');
 * Regex capture groups are passed in $r->params (index 1..n).
 * auth: 'public' | 'admin' (logged-in WP user with manage_options + valid X-WP-Nonce) | 'hmac' (module verifies signature itself).
 */
final class Scc_Router {
    private static array $routes = [];
    public static function add(string $method, string $regex, callable $handler, string $auth = 'public'): void {
        self::$routes[] = [strtoupper($method), $regex, $handler, $auth];
    }
    public static function dispatch(): void {
        $req = new Scc_Request();
        $p = $req->path;
        if (strpos($p, '/api/') !== 0 && strpos($p, '/payment/') !== 0) return;
        foreach (self::$routes as [$m, $rx, $h, $auth]) {
            if ($m !== $req->method && $m !== '*') continue;
            if (!preg_match($rx, $p, $mm)) continue;
            $req->params = $mm;
            if ($auth === 'admin' && !self::is_admin($req)) Scc_Response::error('יש להתחבר לחשבון המורשה כדי לנהל את החנות.', 401)->send();
            try { $res = call_user_func($h, $req); }
            catch (Scc_Http_Exception $e) { $res = Scc_Response::error($e->getMessage(), $e->status); }
            catch (Throwable $e) { error_log('[scc] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine()); $res = Scc_Response::error('אירעה שגיאה. נסו שוב.', 500); }
            if (!($res instanceof Scc_Response)) $res = Scc_Response::json($res);
            $res->send();
        }
        Scc_Response::error('לא נמצא.', 404)->send();
    }
    /** Cookie-authenticated admin: logged-in WP user with manage_options and a valid wp_rest nonce header. */
    public static function is_admin(Scc_Request $r): bool {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return false;
        if ($r->method === 'GET') return true;
        return (bool) wp_verify_nonce($r->header('x-wp-nonce'), 'wp_rest');
    }
}
final class Scc_Http_Exception extends Exception { public function __construct(string $m, public int $status = 400) { parent::__construct($m); } }
