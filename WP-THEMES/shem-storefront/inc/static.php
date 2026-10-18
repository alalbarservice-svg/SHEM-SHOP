<?php
/**
 * Serves the original static files (public/): /assets/* and root files such as /favicon.svg, at the
 * SAME URLs the React bundle and CSS reference. When Apache mod_rewrite is available, rules added to
 * .htaccess let the web server stream them directly; this PHP handler is the fallback for any host
 * (nginx, rules not flushed yet).
 */
if (!defined('ABSPATH')) exit;

function storefront_mime(string $ext): string {
    static $m = [
        'js' => 'text/javascript; charset=UTF-8', 'mjs' => 'text/javascript; charset=UTF-8', 'css' => 'text/css; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'txt' => 'text/plain; charset=UTF-8',
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'map' => 'application/json',
    ];
    return $m[strtolower($ext)] ?? 'application/octet-stream';
}

add_action('parse_request', function () {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'HEAD'], true)) return;
    $path = rawurldecode(storefront_request_path());
    if ($path === '/' || strpos($path, "\0") !== false || strpos($path, '..') !== false) return;
    $root = realpath(STOREFRONT_DIR . 'public');
    if (!$root) return;
    $file = realpath($root . $path);
    if (!$file || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) return;

    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $size = filesize($file);
    $mtime = filemtime($file);
    $etag = '"' . dechex($mtime) . '-' . dechex($size) . '"';
    $hashed = (bool) preg_match('/-[A-Za-z0-9_-]{8}\.[a-z0-9]+$/', basename($file));
    header('Content-Type: ' . storefront_mime($ext));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . ($hashed ? 'public, max-age=31536000, immutable' : 'public, max-age=86400'));
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    $inm = array_map('trim', explode(',', str_replace('W/', '', wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''))));
    if (in_array($etag, $inm, true)) { status_header(304); exit; }
    status_header(200);
    header('Content-Length: ' . $size);
    if ($method !== 'HEAD') { while (ob_get_level()) ob_end_clean(); readfile($file); }
    exit;
}, 0);

/** Let Apache serve public/ files directly (only when the file really exists, so stale rules are harmless). */
add_filter('mod_rewrite_rules', function ($rules) {
    $rel = ltrim(str_replace(wp_normalize_path(ABSPATH), '', wp_normalize_path(STOREFRONT_DIR)), '/');
    $alts = [];
    foreach ((array) scandir(STOREFRONT_DIR . 'public') as $e) {
        if ($e === '.' || $e === '..') continue;
        $alts[] = is_dir(STOREFRONT_DIR . 'public/' . $e) ? preg_quote($e, '#') . '/.+' : preg_quote($e, '#');
    }
    if (!$alts) return $rules;
    $mine = "# BEGIN storefront-static\n"
        . "RewriteCond %{DOCUMENT_ROOT}/{$rel}public%{REQUEST_URI} -f\n"
        . "RewriteRule ^(" . implode('|', $alts) . ")$ {$rel}public/$1 [L]\n"
        . "# END storefront-static\n";
    $anchor = "RewriteRule ^index\\.php$ - [L]";
    return strpos($rules, $anchor) === false ? $rules : str_replace($anchor, $mine . $anchor, $rules);
});
add_action('after_switch_theme', function () { flush_rewrite_rules(true); });
