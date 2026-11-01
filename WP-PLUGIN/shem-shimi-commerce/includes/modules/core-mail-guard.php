<?php
if (!defined('ABSPATH')) exit;
/**
 * SHIMI sends no e-mail to customers (owner decision 01.11.2026).
 *
 * On the SHIMI role every wp_mail() is suppressed unless all recipients are administrator accounts
 * (the store owner's own sign-in / password-reset mail). Anything addressed to anyone else is dropped
 * (wp_mail returns false) and counted in the option scc_mail_blocked. The SHEM role is not affected.
 */
final class Scc_Mail_Guard {
    public static function init(): void {
        if (!defined('SCC_ROLE') || SCC_ROLE !== 'shimi') return;
        add_filter('pre_wp_mail', [self::class, 'filter'], 1, 2);
    }
    /** @param mixed $pre @param array $atts */
    public static function filter($pre, $atts) {
        $to = $atts['to'] ?? [];
        $list = is_array($to) ? $to : explode(',', (string) $to);
        $ok = $list !== [];
        foreach ($list as $r) {
            $r = trim((string) $r);
            if (preg_match('/<([^>]+)>/', $r, $m)) $r = trim($m[1]);
            if ($r === '' || !self::is_admin_email($r)) { $ok = false; break; }
        }
        if ($ok) return $pre;
        update_option('scc_mail_blocked', (int) get_option('scc_mail_blocked', 0) + 1, false);
        return false;
    }
    private static function is_admin_email(string $email): bool {
        $u = get_user_by('email', $email);
        return $u && user_can($u, 'manage_options');
    }
}
Scc_Mail_Guard::init();
