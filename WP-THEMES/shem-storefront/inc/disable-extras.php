<?php
/**
 * Switch off WordPress front-end output that would alter the storefront markup.
 * Nothing here touches wp-admin, the REST API or the shem-shimi-commerce plugin.
 * (The shell template does not call wp_head()/wp_footer(); these removals are the safety net
 * for any other front-end template that might.)
 */
if (!defined('ABSPATH')) exit;

// Admin bar for everybody on the front end.
add_filter('show_admin_bar', '__return_false');

add_action('init', function () {
    // Emoji detection script, styles and conversions.
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    add_filter('emoji_svg_url', '__return_false');
    add_filter('tiny_mce_plugins', function ($p) { return is_array($p) ? array_diff($p, ['wpemoji']) : $p; });

    // wp-embed / oEmbed discovery.
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    add_filter('embed_oembed_discover', '__return_false');

    // Generator, RSD, WLW, shortlink, feed links, REST discovery link, resource hints, canonical guesses.
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'wp_shortlink_wp_head', 10);
    remove_action('wp_head', 'feed_links', 2);
    remove_action('wp_head', 'feed_links_extra', 3);
    remove_action('wp_head', 'rest_output_link_wp_head', 10);
    remove_action('wp_head', 'wp_resource_hints', 2);
    remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
    remove_action('wp_head', 'rel_canonical');
    remove_action('template_redirect', 'rest_output_link_header', 11);
    remove_action('template_redirect', 'wp_shortlink_header', 11);
    add_filter('the_generator', '__return_empty_string');
    add_filter('wp_headers', function ($h) { unset($h['X-Pingback']); return $h; });

    // wptexturize (smart quotes, dash replacement) must never rewrite text.
    add_filter('run_wptexturize', '__return_false');
    foreach (['the_title', 'the_content', 'the_excerpt', 'comment_text', 'widget_title', 'widget_text', 'term_name', 'term_description'] as $f) {
        remove_filter($f, 'wptexturize');
    }
}, 20);

// Block library, global styles, classic-theme styles, jQuery: not used by the React storefront.
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;
    foreach (['wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles', 'wc-blocks-style', 'wp-embed'] as $h) {
        wp_dequeue_style($h);
        wp_deregister_style($h);
    }
    wp_deregister_script('wp-embed');
    wp_deregister_script('jquery');
    wp_deregister_script('jquery-core');
    wp_deregister_script('jquery-migrate');
}, 100);
add_action('wp_enqueue_scripts', function () {
    remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
    remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
    remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
}, 1);
