<?php
/**
 * Plugin Name: SHEM-SHIMI Commerce
 * Description: Commerce contract (/api/*), admin API, channel sync and documents for the SHEM and SHIMI stores. One code base, role set by SCC_ROLE ('shem'|'shimi') in wp-config.php.
 * Version: 0.1.0
 * Requires PHP: 8.1
 */
if (!defined('ABSPATH')) exit;
define('SCC_VERSION', '0.1.0');
define('SCC_DIR', __DIR__ . '/');
if (!defined('SCC_ROLE')) define('SCC_ROLE', 'shem');

require_once SCC_DIR . 'includes/class-scc-http.php';
require_once SCC_DIR . 'includes/class-scc-docs.php';
require_once SCC_DIR . 'includes/class-scc-router.php';

register_activation_hook(__FILE__, ['Scc_Docs', 'install']);
add_action('plugins_loaded', function () {
    Scc_Docs::maybe_install();
    // Modules self-register routes. One file per domain so agents never edit the same file.
    foreach (glob(SCC_DIR . 'includes/modules/*.php') as $f) require_once $f;
    do_action('scc_modules_loaded');
}, 5);
// Dispatch /api/* and /payment/* before WordPress routing but AFTER WooCommerce has registered its post types/taxonomies and
// fired woocommerce_init (init:0 and init:5): wc_get_product() returns false before that point, which made every admin write
// create a duplicate WooCommerce product (Round 2, item 4/5 root cause).
add_action('init', ['Scc_Router', 'dispatch'], 20);
