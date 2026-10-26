<?php
if (!defined('ABSPATH')) exit;
/**
 * Public-surface hardening: an anonymous visitor learns nothing about the people or the stack behind the shop.
 * Closes: PHP version header, WordPress user names (REST users, ?author=N, user sitemap), XML-RPC and pingback
 * (including outbound self-pings, which would reveal the server address), generator and discovery tags.
 * Static files (readme.html, license.txt) and the Server header are web-server level: see docs/hosting.
 */

// Stack disclosure.
add_action('send_headers', function () { header_remove('X-Powered-By'); }, 0);
add_filter('the_generator', '__return_empty_string');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');

// XML-RPC and pingback off. No outbound pings, no X-Pingback header.
add_filter('xmlrpc_enabled', '__return_false');
add_filter('xmlrpc_methods', '__return_empty_array');
add_filter('pings_open', '__return_false', 99);
add_filter('wp_headers', function ($h) { unset($h['X-Pingback']); return $h; });
add_action('pre_ping', function (&$links) { $links = []; });
// Modules load at plugins_loaded:5, so a hook added here for an earlier priority would never run: act immediately.
if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
    header_remove('X-Powered-By');
    status_header(403);
    header('Cache-Control: no-store');
    exit;
}

// No user enumeration for anonymous visitors.
add_filter('rest_endpoints', function ($eps) {
    if (!is_user_logged_in()) {
        foreach (array_keys($eps) as $route) {
            if (preg_match('#^/wp/v2/users(/|$)#', $route)) unset($eps[$route]);
        }
    }
    return $eps;
});
add_filter('wp_sitemaps_add_provider', function ($provider, $name) { return $name === 'users' ? false : $provider; }, 10, 2);
add_action('template_redirect', function () {
    if (is_admin()) return;
    if (isset($_GET['author']) || is_author()) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        include get_404_template();
        exit;
    }
}, 0);
