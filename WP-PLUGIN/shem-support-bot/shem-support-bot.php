<?php
/**
 * Plugin Name: SHEM Support Bot
 * Description: Hebrew (RTL) customer-support bot for the SHEM / SHIMI stores. The whole "brain" is edited in the admin (versioned), answers come only from the brain and live store data, escalation creates tickets. No external AI service, no cost.
 * Version: 0.1.0
 * Requires PHP: 8.1
 */
if (!defined('ABSPATH')) exit;
define('SSB_VERSION', '0.1.0');
define('SSB_DIR', __DIR__ . '/');
define('SSB_URL', plugin_dir_url(__FILE__));

foreach (['db', 'text', 'brain', 'live', 'content', 'tickets', 'engine', 'rest', 'admin', 'widget'] as $f) {
    require_once SSB_DIR . "includes/class-ssb-$f.php";
}
register_activation_hook(__FILE__, ['Ssb_Db', 'install']);
add_action('plugins_loaded', ['Ssb_Db', 'maybe_install'], 6);
Ssb_Brain::boot();
Ssb_Content::boot();
Ssb_Rest::boot();
Ssb_Admin::boot();
Ssb_Widget::boot();
Ssb_Tickets::boot();
