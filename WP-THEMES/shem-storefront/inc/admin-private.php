<?php
/**
 * Serves admin-only JavaScript chunks (the build's lazy-loaded admin code) at /admin-private/*.js.
 * The files live in private/admin-private/ inside the theme, outside public/, so the web server never
 * serves them directly. Only a logged-in administrator (manage_options) gets the file; everyone else
 * gets the same 404 as any unknown path, so the code (and its strings) is not part of the public site.
 */
if (!defined('ABSPATH')) exit;

add_action('parse_request', function () {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'HEAD'], true)) return;
    $path = rawurldecode(storefront_request_path());
    if (!preg_match('#^/admin-private/([A-Za-z0-9_.-]+\.js)$#', $path, $m) || strpos($m[1], '..') !== false) return;
    $deny = function () { status_header(404); nocache_headers(); header('Content-Type: text/plain; charset=UTF-8'); echo 'Not found'; exit; };
    if (!is_user_logged_in() || !current_user_can('manage_options')) $deny();
    $root = realpath(STOREFRONT_DIR . 'private/admin-private');
    $file = $root ? realpath($root . '/' . $m[1]) : false;
    if (!$file || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) $deny();
    header('Content-Type: text/javascript; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Vary: Cookie');
    header('Content-Length: ' . filesize($file));
    status_header(200);
    if ($method !== 'HEAD') { while (ob_get_level()) ob_end_clean(); readfile($file); }
    exit;
}, 0);
