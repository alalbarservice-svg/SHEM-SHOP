<?php
if (!defined('ABSPATH')) exit;
/** Schema + shared helpers. All timestamps are stored in UTC ("Y-m-d H:i:s"). */
final class Ssb_Db {
    const VERSION = 3;

    public static function t(string $n): string { global $wpdb; return $wpdb->prefix . 'ssb_' . $n; }
    public static function now(): string { return gmdate('Y-m-d H:i:s'); }

    public static function maybe_install(): void {
        if ((int) get_option('ssb_db_version', 0) < self::VERSION) self::install();
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $t = fn(string $n) => self::t($n);
        dbDelta("CREATE TABLE {$t('brain')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            section VARCHAR(40) NOT NULL,
            slug VARCHAR(80) NULL,
            title VARCHAR(255) NOT NULL,
            keywords TEXT NULL,
            body LONGTEXT NOT NULL,
            meta TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            version INT NOT NULL DEFAULT 1,
            source VARCHAR(40) NOT NULL DEFAULT 'manual',
            updated_by VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY section (section)
        ) $c;");
        dbDelta("CREATE TABLE {$t('brain_versions')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            brain_id BIGINT UNSIGNED NOT NULL,
            version INT NOT NULL,
            action VARCHAR(20) NOT NULL,
            section VARCHAR(40) NOT NULL,
            title VARCHAR(255) NOT NULL,
            keywords TEXT NULL,
            body LONGTEXT NOT NULL,
            meta TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            editor VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY brain_id (brain_id)
        ) $c;");
        dbDelta("CREATE TABLE {$t('conv')} (
            id VARCHAR(40) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            state LONGTEXT NULL,
            ip_hash CHAR(64) NOT NULL DEFAULT '',
            msgs INT NOT NULL DEFAULT 0,
            escalated TINYINT(1) NOT NULL DEFAULT 0,
            resolved_by VARCHAR(10) NOT NULL DEFAULT '',
            answered INT NOT NULL DEFAULT 0,
            unanswered INT NOT NULL DEFAULT 0,
            flagged TINYINT(1) NOT NULL DEFAULT 0,
            rating TINYINT NULL,
            rating_note TEXT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $c;");
        dbDelta("CREATE TABLE {$t('msg')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conv_id VARCHAR(40) NOT NULL,
            role VARCHAR(10) NOT NULL,
            text TEXT NOT NULL,
            meta TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY conv_id (conv_id),
            KEY created_at (created_at)
        ) $c;");
        dbDelta("CREATE TABLE {$t('ticket')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            number VARCHAR(20) NOT NULL,
            conv_id VARCHAR(40) NOT NULL DEFAULT '',
            name VARCHAR(190) NOT NULL DEFAULT '',
            contact VARCHAR(190) NOT NULL DEFAULT '',
            contact_key VARCHAR(190) NOT NULL DEFAULT '',
            order_number VARCHAR(60) NOT NULL DEFAULT '',
            summary TEXT NULL,
            reason VARCHAR(80) NOT NULL DEFAULT '',
            priority VARCHAR(10) NOT NULL DEFAULT 'normal',
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            assignee VARCHAR(100) NOT NULL DEFAULT '',
            sla_due DATETIME NULL,
            sla_paused_at DATETIME NULL,
            first_response_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY number (number),
            KEY status (status),
            KEY contact_key (contact_key)
        ) $c;");
        dbDelta("CREATE TABLE {$t('ticket_note')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(12) NOT NULL,
            author VARCHAR(100) NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id)
        ) $c;");
        dbDelta("CREATE TABLE {$t('unanswered')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            norm VARCHAR(190) NOT NULL,
            question VARCHAR(500) NOT NULL,
            hits INT NOT NULL DEFAULT 1,
            status VARCHAR(10) NOT NULL DEFAULT 'open',
            last_seen DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY norm (norm),
            KEY status (status)
        ) $c;");
        dbDelta("CREATE TABLE {$t('notice')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            channel VARCHAR(12) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            ref VARCHAR(40) NOT NULL DEFAULT '',
            to_addr VARCHAR(190) NOT NULL DEFAULT '',
            subject VARCHAR(255) NOT NULL DEFAULT '',
            text TEXT NOT NULL,
            sent TINYINT(1) NOT NULL DEFAULT 0,
            seen TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY channel (channel, seen)
        ) $c;");
        update_option('ssb_db_version', self::VERSION, false);
    }

    /** Admin-editable settings (stored as one option). */
    public static function defaults(): array {
        return [
            'enabled' => 1,
            'bot_name' => 'עוזר השירות',
            'retention_days' => 180,
            'rate_msgs_per_10min' => 30,
            'rate_tickets_per_hour' => 5,
            'max_msg_len' => 600,
            'manager_email' => defined('SCC_ADMIN_CONTACT_EMAIL') ? (string) SCC_ADMIN_CONTACT_EMAIL : (string) get_option('admin_email'),
            'hours' => ['sun-thu' => ['09:00', '18:00'], 'fri' => ['09:00', '13:00'], 'sat' => null],
            'sla_hours' => ['urgent' => 1, 'high' => 4, 'normal' => 8, 'low' => 24],
            'verify_max_attempts' => 3,
            'wa_number' => '', // WhatsApp number of the service rep (empty until the owner replaces the provider; see docs/OPEN_ITEMS)
            'ticket_prefix' => 'T',
        ];
    }
    public static function settings(): array {
        $s = get_option('ssb_settings', []);
        return array_replace_recursive(self::defaults(), is_array($s) ? $s : []);
    }
    public static function save_settings(array $in): array {
        $cur = self::settings();
        $int = fn($v, $min, $max, $d) => max($min, min($max, is_numeric($v) ? (int) $v : $d));
        if (array_key_exists('enabled', $in)) $cur['enabled'] = !empty($in['enabled']) ? 1 : 0;
        if (isset($in['bot_name'])) $cur['bot_name'] = mb_substr(trim(wp_strip_all_tags((string) $in['bot_name'])), 0, 60) ?: $cur['bot_name'];
        if (isset($in['retention_days'])) $cur['retention_days'] = $int($in['retention_days'], 7, 3650, 180);
        if (isset($in['rate_msgs_per_10min'])) $cur['rate_msgs_per_10min'] = $int($in['rate_msgs_per_10min'], 5, 300, 30);
        if (isset($in['rate_tickets_per_hour'])) $cur['rate_tickets_per_hour'] = $int($in['rate_tickets_per_hour'], 1, 50, 5);
        if (isset($in['manager_email']) && is_email((string) $in['manager_email'])) $cur['manager_email'] = (string) $in['manager_email'];
        if (isset($in['wa_number'])) { $d = preg_replace('/\D+/', '', (string) $in['wa_number']) ?? ''; $cur['wa_number'] = (strlen($d) >= 9 && strlen($d) <= 15) ? $d : ''; }
        if (isset($in['sla_hours']) && is_array($in['sla_hours'])) foreach (['urgent', 'high', 'normal', 'low'] as $k) if (isset($in['sla_hours'][$k])) $cur['sla_hours'][$k] = $int($in['sla_hours'][$k], 1, 240, $cur['sla_hours'][$k]);
        if (isset($in['hours']) && is_array($in['hours'])) {
            $ok = fn($p) => is_array($p) && count($p) === 2 && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $p[0]) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $p[1]) && $p[0] < $p[1];
            if (isset($in['hours']['sun-thu']) && $ok($in['hours']['sun-thu'])) $cur['hours']['sun-thu'] = [(string) $in['hours']['sun-thu'][0], (string) $in['hours']['sun-thu'][1]];
            if (array_key_exists('fri', $in['hours'])) $cur['hours']['fri'] = $ok($in['hours']['fri']) ? [(string) $in['hours']['fri'][0], (string) $in['hours']['fri'][1]] : null;
        }
        update_option('ssb_settings', $cur, false);
        return $cur;
    }
    /** Mail is real only when wp-config defines SSB_MAIL_LIVE=true. Otherwise every email is logged, never sent (staging safety). */
    public static function mail_live(): bool { return defined('SSB_MAIL_LIVE') && SSB_MAIL_LIVE === true; }
}
