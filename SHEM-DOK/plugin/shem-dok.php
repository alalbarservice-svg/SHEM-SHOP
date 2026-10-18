<?php
/**
 * Plugin Name: SHEM DOK
 * Description: SHEM transaction document editor, private immutable PDF archive and WooCommerce paid-order integration (SHIMI payment bridge, customer download, credit documents).
 * Version: 1.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/includes.php';
require_once __DIR__.'/includes-bridge.php';
require_once __DIR__.'/includes-customer.php';
if (defined('WP_CLI') && WP_CLI) require_once __DIR__.'/includes-cli.php';

register_activation_hook(__FILE__, ['ShemDok','install']);
add_action('plugins_loaded', ['ShemDok','maybe_upgrade']);
add_action('before_woocommerce_init', function(){if(class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables',__FILE__,true);});
add_action('admin_menu', ['ShemDok','menu']);
add_action('admin_notices', ['ShemDok','admin_notice']);
add_action('rest_api_init',['ShemDok','routes']);
add_action('rest_api_init',['ShemDok_Bridge','routes']);
add_action('rest_api_init',['ShemDok_Customer','routes']);
add_action('admin_post_shem_dok_pdf',['ShemDok','download']);
add_action('admin_post_shem_dok_customer_pdf',['ShemDok_Customer','download']);
add_action('admin_post_nopriv_shem_dok_customer_pdf',['ShemDok_Customer','download']);
add_action('admin_post_shem_dok_order_issue',['ShemDok_Customer','order_issue']);
add_action('woocommerce_payment_complete',['ShemDok','paid'],30);
add_action('shem_dok_retry',['ShemDok','paid'],10,2);
add_action('woocommerce_order_refunded',['ShemDok','on_refund'],10,2);
add_action('woocommerce_thankyou',['ShemDok_Customer','thankyou'],20);
add_action('woocommerce_order_details_after_order_table',['ShemDok_Customer','account_link'],20);
add_action('add_meta_boxes',['ShemDok_Customer','metabox']);
