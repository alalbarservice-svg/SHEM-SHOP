<?php
if (!defined('ABSPATH')) exit;

/**
 * Hands a queued WhatsApp notice to the isolated n8n webhook of this plugin, which sends it to the service rep.
 * Secrets live only in wp-config constants: SSB_WA_URL (n8n webhook URL), SSB_WA_KEY (shared header key).
 * Nothing is sent unless SSB_WA_LIVE === true (staging only logs), mirroring SSB_MAIL_LIVE.
 */
class Ssb_Wa {
    const HOOK = 'ssb_wa_send';
    const MAX_ATTEMPTS = 5;

    public static function boot(): void {
        add_action(self::HOOK, [self::class, 'deliver'], 10, 1);
    }
    public static function live(): bool { return defined('SSB_WA_LIVE') && SSB_WA_LIVE === true; }
    public static function configured(): bool { return defined('SSB_WA_URL') && SSB_WA_URL !== '' && defined('SSB_WA_KEY') && SSB_WA_KEY !== ''; }

    /** Queues a WhatsApp notice and schedules its delivery. Returns the notice id (0 when the number is invalid). */
    public static function queue(string $to, string $text, string $ref): int {
        global $wpdb;
        $to = preg_replace('/\D/', '', $to);
        if (preg_match('/^0\d{8,9}$/', $to)) $to = '972' . substr($to, 1);
        if (!preg_match('/^972\d{8,9}$/', $to)) return 0;
        $sub = self::live() && self::configured() ? 'ממתין לשליחה' : 'לא נשלח (סביבת בדיקה או חיבור לא מוגדר)';
        $wpdb->insert(Ssb_Db::t('notice'), ['channel' => 'whatsapp', 'kind' => 'ticket', 'ref' => $ref, 'to_addr' => $to, 'subject' => $sub, 'text' => mb_substr($text, 0, 3500), 'sent' => 0, 'seen' => 1, 'created_at' => Ssb_Db::now()]);
        $id = (int) $wpdb->insert_id;
        if ($id && self::live() && self::configured()) {
            wp_schedule_single_event(time() + 1, self::HOOK, [$id]);
            if (function_exists('spawn_cron')) spawn_cron();
        }
        return $id;
    }

    /** Sends one queued notice. Retries with backoff up to MAX_ATTEMPTS, records the last error. */
    public static function deliver($id): bool {
        global $wpdb; $t = Ssb_Db::t('notice'); $id = (int) $id;
        $n = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d AND channel='whatsapp'", $id), ARRAY_A);
        if (!$n || (int) $n['sent'] === 1 || (int) $n['attempts'] >= self::MAX_ATTEMPTS) return false;
        if (!self::live() || !self::configured()) return false;
        $attempts = (int) $n['attempts'] + 1;
        $res = wp_remote_post(SSB_WA_URL, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json', 'X-SSB-Key' => SSB_WA_KEY],
            'body'    => wp_json_encode(['ts' => time(), 'to' => $n['to_addr'], 'text' => $n['text'], 'ref' => $n['ref']]),
        ]);
        $err = '';
        if (is_wp_error($res)) $err = 'http: ' . $res->get_error_message();
        else {
            $code = (int) wp_remote_retrieve_response_code($res);
            $j = json_decode((string) wp_remote_retrieve_body($res), true);
            if ($code !== 200 || empty($j['ok'])) $err = 'status ' . $code . (is_array($j) && !empty($j['error']) ? ' ' . preg_replace('/[^a-z_]/', '', (string) $j['error']) : '');
        }
        if ($err === '') {
            $wpdb->update($t, ['sent' => 1, 'attempts' => $attempts, 'last_error' => '', 'subject' => 'נשלח'], ['id' => $id]);
            return true;
        }
        $wpdb->update($t, ['attempts' => $attempts, 'last_error' => mb_substr($err, 0, 190), 'subject' => $attempts >= self::MAX_ATTEMPTS ? 'נכשל סופית' : 'נכשל, ינוסה שוב'], ['id' => $id]);
        if ($attempts < self::MAX_ATTEMPTS) wp_schedule_single_event(time() + 60 * (int) pow(3, $attempts - 1), self::HOOK, [$id]);
        return false;
    }
}
