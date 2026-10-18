<?php
if (!defined('ABSPATH')) exit;
/** wp-admin screen: outbox visibility (pending / retry / dead-letter) with manual retry. Read-only except for the retry / run-now buttons. */
final class Scc_Channel_Admin_Page {
    const SLUG = 'scc-channel-outbox';
    public static function menu(): void { add_management_page('ערוץ SHEM-SHIMI', 'ערוץ SHEM-SHIMI', 'manage_options', self::SLUG, [self::class, 'render']); }
    public static function act(): void {
        if (!current_user_can('manage_options')) wp_die('אין הרשאה', 403);
        check_admin_referer('scc_channel_action');
        $do = sanitize_key($_POST['do'] ?? '');
        if ($do === 'retry' && !empty($_POST['event_id'])) Scc_Channel_Outbox::retry(sanitize_text_field(wp_unslash($_POST['event_id'])));
        if ($do === 'sweep') Scc_Channel_Outbox::sweep();
        wp_safe_redirect(admin_url('tools.php?page=' . self::SLUG)); exit;
    }
    public static function render(): void {
        if (!current_user_can('manage_options')) return;
        $c = Scc_Channel_Outbox::counts(); $rows = Scc_Channel_Outbox::listing(100);
        $e = fn($x) => esc_html((string) $x);
        echo '<div class="wrap" dir="rtl"><h1>ערוץ SHEM-SHIMI - תור אירועים</h1><p>תפקיד החנות: <strong>' . $e(Scc_Channel::is_home() ? 'SHIMI (חנות מקושרת)' : 'SHEM (מקור מלאי)') . '</strong> | כתובת חנות עמית: <code>' . $e(Scc_Channel::peer_url() ?: 'לא הוגדר') . '</code></p>';
        echo '<p>'; foreach ($c as $k => $n) echo '<span style="display:inline-block;margin-left:16px"><strong>' . $e($k) . '</strong>: ' . (int) $n . '</span>'; echo '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('scc_channel_action'); echo '<input type="hidden" name="action" value="scc_channel_action"><input type="hidden" name="do" value="sweep"><button class="button">הרץ עכשיו משלוח ובדיקות</button></form>';
        echo '<table class="widefat striped" style="margin-top:12px"><thead><tr><th>אירוע</th><th>סוג</th><th>מצב</th><th>ניסיונות</th><th>ניסיון הבא</th><th>שגיאה אחרונה</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $dead = $r['state'] === 'dead';
            echo '<tr' . ($dead ? ' style="background:#fde8e8"' : '') . '><td><code>' . $e($r['event_id']) . '</code></td><td>' . $e($r['kind']) . '</td><td>' . $e($r['state']) . ($dead ? ' (נכשל סופית)' : '') . '</td><td>' . (int) $r['attempts'] . '/' . (int) $r['max_attempts'] . '</td><td>' . ($r['next_at'] ? $e(gmdate('Y-m-d H:i:s', (int) $r['next_at'])) : '') . '</td><td>' . $e($r['last_error']) . '</td><td>';
            if (in_array($r['state'], ['dead', 'retry'], true)) { echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; wp_nonce_field('scc_channel_action'); echo '<input type="hidden" name="action" value="scc_channel_action"><input type="hidden" name="do" value="retry"><input type="hidden" name="event_id" value="' . esc_attr($r['event_id']) . '"><button class="button">נסה שוב</button></form>'; }
            echo '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="7">אין אירועים.</td></tr>';
        echo '</tbody></table></div>';
    }
}
add_action('admin_menu', ['Scc_Channel_Admin_Page', 'menu']);
add_action('admin_post_scc_channel_action', ['Scc_Channel_Admin_Page', 'act']);
