<?php
/**
 * Storefront theme bootstrap. Shared source for shem-storefront and shimi-storefront
 * (generated per brand by WP-THEMES/build-theme.sh). The theme only serves the existing
 * React storefront shell and its static assets; commerce lives in the shem-shimi-commerce plugin.
 */
if (!defined('ABSPATH')) exit;

define('STOREFRONT_DIR', __DIR__ . '/');
define('STOREFRONT_CONFIG', require STOREFRONT_DIR . 'inc/config.php');

require_once STOREFRONT_DIR . 'inc/disable-extras.php';
require_once STOREFRONT_DIR . 'inc/shell.php';
require_once STOREFRONT_DIR . 'inc/static.php';
