<?php
if (!defined('ABSPATH')) exit;
/**
 * Injects the chat widget into the SHEM storefront through the theme's existing `storefront_shell_head` hook.
 * No theme file is touched. Only the customer-facing shell ('index') gets the widget - never admin / order-complete.
 */
final class Ssb_Widget {
    public static function boot(): void { add_action('storefront_shell_head', [self::class, 'head'], 20, 1); }

    public static function head($name = ''): void {
        if ($name !== 'index') return;
        $s = Ssb_Db::settings();
        if (empty($s['enabled'])) return;
        $file = SSB_DIR . 'assets/widget.js';
        $ver = SSB_VERSION . '.' . (file_exists($file) ? filemtime($file) : '0');
        $cfg = ['api' => home_url('/'), 'v' => SSB_VERSION];
        echo '<script>window.SSB_CFG=' . wp_json_encode($cfg) . ';</script>' . "\n";
        echo '<script src="' . esc_url(SSB_URL . 'assets/widget.js?ver=' . rawurlencode($ver)) . '" defer></script>' . "\n";
    }
}
