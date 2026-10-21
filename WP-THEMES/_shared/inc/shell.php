<?php
/**
 * Serves the React storefront HTML shell.
 *
 * Mirrors the original Cloudflare worker (worker/index.js) plus the static-asset rules it ran behind:
 *   /                    -> shell/index.html
 *   /admin.html          -> shell/admin.html            (noindex; the React app switches to the admin UI when pathname is /admin.html)
 *   /order-complete.html -> shell/order-complete.html   (noindex, no-referrer)
 *   any other path       -> shell/index.html when the request is GET/HEAD and Accept contains text/html (SPA fallback)
 *   /api/* /payment/*    -> handled by the shem-shimi-commerce plugin on init (never reaches here)
 *   /media/*             -> not served by the theme (plugin/uploads concern); plain 404
 * The shell files are the exact output of `vite build` + scripts/prepare-admin-share.mjs; only the legacy
 * public origin in canonical/og/twitter URLs is swapped for this site's home URL, and (on sites with
 * "discourage search engines" on, i.e. staging) a noindex meta/header is added.
 */
if (!defined('ABSPATH')) exit;

/** Request path relative to the WordPress home path, without query string. */
function storefront_request_path(): string {
    $uri = wp_unslash($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $base = rtrim((string) parse_url(home_url(), PHP_URL_PATH), '/');
    if ($base !== '' && strpos($path, $base) === 0) $path = substr($path, strlen($base));
    return $path === '' ? '/' : $path;
}

/** Decide which shell (if any) a request gets. Returns ['shell'=>name]|['redirect'=>path]|null. */
function storefront_route(string $path, string $method, string $accept): ?array {
    if (!in_array($method, ['GET', 'HEAD'], true)) return null;
    if ($path === '/') return ['shell' => 'index'];
    if ($path === '/index.html') return ['redirect' => '/'];
    if ($path === '/admin.html') return ['shell' => 'admin'];
    if ($path === '/order-complete.html') return ['shell' => 'order-complete'];
    // Extensionless forms: the old host redirected X.html -> /X; send them to the real entry points.
    $trim = rtrim($path, '/');
    if ($trim === '/admin') return ['redirect' => '/admin.html'];
    if ($trim === '/order-complete') return ['redirect' => '/order-complete.html'];
    foreach (['/api/', '/payment/', '/media/', '/wp-json/', '/wp-admin/', '/wp-content/', '/wp-includes/'] as $p) {
        if (strpos($path, $p) === 0) return null;
    }
    if (stripos($accept, 'text/html') !== false) return ['shell' => 'index'];
    return null;
}

function storefront_render_shell(string $name): string {
    $file = STOREFRONT_DIR . 'shell/' . $name . '.html';
    $html = is_readable($file) ? (string) file_get_contents($file) : '';
    $cfg = STOREFRONT_CONFIG;
    $html = str_replace($cfg['legacy_origin'], untrailingslashit(home_url()), $html);
    // Open Graph / Twitter images must be absolute URLs for link previews.
    $html = preg_replace('~(property="og:image" content=|name="twitter:image" content=)"/~', '$1"' . untrailingslashit(home_url()) . '/', $html);

    $extra = '';
    if (get_option('blog_public') === '0' && stripos($html, 'name="robots"') === false) {
        $extra .= '<meta name="robots" content="noindex, nofollow">';
    }
    // Hook for the commerce plugin (e.g. print a bootstrap <script> with an admin nonce). Prints nothing by default.
    if (has_action('storefront_shell_head')) {
        ob_start();
        do_action('storefront_shell_head', $name);
        $extra .= (string) ob_get_clean();
    }
    if ($extra !== '') {
        $html = preg_replace('#</head>#i', str_replace('$', '\\$', $extra) . '</head>', $html, 1);
    }
    return $html;
}

add_action('template_redirect', function () {
    if (is_robots() || is_feed() || is_trackback() || get_query_var('sitemap') || get_query_var('sitemap-stylesheet')) return;
    if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) return;

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $route = storefront_route(storefront_request_path(), $method, wp_unslash($_SERVER['HTTP_ACCEPT'] ?? ''));
    $route = apply_filters('storefront_shell_route', $route, storefront_request_path());
    if (!$route) return;

    if (isset($route['redirect'])) {
        $qs = (string) parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
        wp_redirect(home_url($route['redirect']) . ($qs !== '' ? '?' . $qs : ''), 307, 'storefront');
        exit;
    }

    $name = $route['shell'];
    $html = storefront_render_shell($name);
    if ($html === '') return;

    global $wp_query;
    if ($wp_query) $wp_query->is_404 = false;
    status_header(200);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    if ($name !== 'index' || get_option('blog_public') === '0') header('X-Robots-Tag: noindex, nofollow');
    if ($name === 'order-complete') header('Referrer-Policy: no-referrer');
    header('Content-Length: ' . strlen($html));
    if ($method !== 'HEAD') echo $html;
    exit;
}, 1);
