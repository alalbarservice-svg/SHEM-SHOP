<?php
if (!defined('ABSPATH')) exit;
/** wp-admin screen "מוח הבוט": brain, tickets, dashboard, unanswered, settings. All data via the ssb/v1 admin REST routes (cookie + nonce + manage_options). */
final class Ssb_Admin {
    const SLUG = 'ssb-brain';

    public static function boot(): void {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
    }
    public static function menu(): void {
        $n = Ssb_Tickets::unseen_count();
        $label = 'מוח הבוט' . ($n > 0 ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '');
        add_menu_page('מוח הבוט', $label, 'manage_options', self::SLUG, [self::class, 'render'], 'dashicons-format-chat', 58);
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) wp_die('אין הרשאה');
        echo '<div class="wrap ssb-wrap" dir="rtl" lang="he"><h1 class="screen-reader-text">מוח הבוט</h1><div id="ssb-app" aria-live="polite">טוען...</div>';
        echo '<noscript>נדרש JavaScript כדי לנהל את הבוט.</noscript></div>';
    }
    public static function assets(string $hook): void {
        if ($hook !== 'toplevel_page_' . self::SLUG) return;
        $v = SSB_VERSION . '.' . max(filemtime(SSB_DIR . 'assets/admin.js'), filemtime(SSB_DIR . 'assets/admin.css'));
        wp_enqueue_style('ssb-admin', SSB_URL . 'assets/admin.css', [], $v);
        wp_enqueue_script('ssb-admin', SSB_URL . 'assets/admin.js', [], $v, true);
        wp_localize_script('ssb-admin', 'SSB_ADMIN', [
            'api' => home_url('/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'sections' => Ssb_Brain::sections(),
            'statuses' => Ssb_Tickets::STATUSES,
            'priorities' => Ssb_Tickets::PRIORITIES,
        ]);
    }
}
