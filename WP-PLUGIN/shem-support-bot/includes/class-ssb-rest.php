<?php
if (!defined('ABSPATH')) exit;
/** REST API: public chat endpoints (rate-limited, no secrets) and admin endpoints (WP cookie login + nonce + manage_options). */
final class Ssb_Rest {
    const NS = 'ssb/v1';

    public static function boot(): void { add_action('rest_api_init', [self::class, 'routes']); }

    /** Fixed-window counter. Returns false when the limit for the bucket is exceeded. */
    public static function hit(string $bucket, int $limit, int $window): bool {
        $k = 'ssb_rl_' . md5($bucket); $d = get_transient($k); $now = time();
        if (!is_array($d) || ($now - (int) $d['t']) >= $window) $d = ['n' => 0, 't' => $now];
        $d['n']++;
        set_transient($k, $d, max(1, $window - ($now - (int) $d['t'])));
        return $d['n'] <= $limit;
    }
    public static function ip_hash(): string { return hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? ''), wp_salt('auth')); }

    private static function admin_ok(): bool { return current_user_can('manage_options'); }
    private static function editor(): string { $u = wp_get_current_user(); return $u && $u->exists() ? ($u->display_name ?: $u->user_login) : 'admin'; }
    private static function err($e, int $code = 400): WP_REST_Response {
        return new WP_REST_Response(['ok' => false, 'error' => is_wp_error($e) ? $e->get_error_message() : (string) $e], $code);
    }
    private static function ok(array $d = []): WP_REST_Response { return new WP_REST_Response(['ok' => true] + $d); }

    public static function routes(): void {
        $pub = ['permission_callback' => '__return_true'];
        $adm = ['permission_callback' => [self::class, 'admin_perm']];
        register_rest_route(self::NS, '/init', ['methods' => 'GET', 'callback' => [self::class, 'init']] + $pub);
        register_rest_route(self::NS, '/chat', ['methods' => 'POST', 'callback' => [self::class, 'chat']] + $pub);
        register_rest_route(self::NS, '/rate', ['methods' => 'POST', 'callback' => [self::class, 'rate']] + $pub);

        register_rest_route(self::NS, '/admin/brain', [
            ['methods' => 'GET', 'callback' => [self::class, 'a_brain_list']] + $adm,
            ['methods' => 'POST', 'callback' => [self::class, 'a_brain_save']] + $adm]);
        register_rest_route(self::NS, '/admin/brain/(?P<id>\d+)/active', ['methods' => 'POST', 'callback' => [self::class, 'a_brain_active']] + $adm);
        register_rest_route(self::NS, '/admin/brain/(?P<id>\d+)/versions', ['methods' => 'GET', 'callback' => [self::class, 'a_brain_versions']] + $adm);
        register_rest_route(self::NS, '/admin/brain/(?P<id>\d+)/restore', ['methods' => 'POST', 'callback' => [self::class, 'a_brain_restore']] + $adm);
        register_rest_route(self::NS, '/admin/tickets', ['methods' => 'GET', 'callback' => [self::class, 'a_tickets']] + $adm);
        register_rest_route(self::NS, '/admin/tickets/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [self::class, 'a_ticket']] + $adm,
            ['methods' => 'POST', 'callback' => [self::class, 'a_ticket_update']] + $adm]);
        register_rest_route(self::NS, '/admin/tickets/(?P<id>\d+)/note', ['methods' => 'POST', 'callback' => [self::class, 'a_ticket_note']] + $adm);
        register_rest_route(self::NS, '/admin/tickets/(?P<id>\d+)/reply', ['methods' => 'POST', 'callback' => [self::class, 'a_ticket_reply']] + $adm);
        register_rest_route(self::NS, '/admin/dashboard', ['methods' => 'GET', 'callback' => [self::class, 'a_dashboard']] + $adm);
        register_rest_route(self::NS, '/admin/unanswered/(?P<id>\d+)', ['methods' => 'POST', 'callback' => [self::class, 'a_unanswered']] + $adm);
        register_rest_route(self::NS, '/admin/settings', [
            ['methods' => 'GET', 'callback' => [self::class, 'a_settings']] + $adm,
            ['methods' => 'POST', 'callback' => [self::class, 'a_settings_save']] + $adm]);
        register_rest_route(self::NS, '/admin/notices', ['methods' => 'GET', 'callback' => [self::class, 'a_notices']] + $adm);
        register_rest_route(self::NS, '/admin/notices/seen', ['methods' => 'POST', 'callback' => [self::class, 'a_seen']] + $adm);
        register_rest_route(self::NS, '/admin/purge', ['methods' => 'POST', 'callback' => [self::class, 'a_purge']] + $adm);
    }

    /* ------------------------------------------------------------------ public */
    public static function init(): WP_REST_Response {
        $s = Ssb_Db::settings(); $g = Ssb_Engine::greeting();
        $r = self::ok(['enabled' => (bool) $s['enabled'], 'bot' => $s['bot_name'], 'greeting' => $g['text'], 'chips' => $g['chips'], 'open' => Ssb_Tickets::is_open()]);
        $r->header('Cache-Control', 'no-store');
        return $r;
    }
    public static function chat(WP_REST_Request $req): WP_REST_Response {
        $s = Ssb_Db::settings();
        if (!$s['enabled']) return self::err('השירות כבוי כרגע', 503);
        $b = $req->get_json_params(); if (!is_array($b)) $b = [];
        $msg = isset($b['message']) && is_string($b['message']) ? $b['message'] : '';
        $conv = isset($b['conv']) && is_string($b['conv']) ? $b['conv'] : '';
        $ip = self::ip_hash();
        if (!self::hit('m:' . $ip, (int) $s['rate_msgs_per_10min'], 600)) return new WP_REST_Response(['ok' => true, 'conv' => $conv, 'text' => Ssb_Brain::phrase('sys.rate_limited', 'נשלחו הרבה הודעות. נסו שוב בעוד כמה דקות.'), 'chips' => [], 'type' => 'rate'], 200);
        if (mb_strlen($msg) > (int) $s['max_msg_len']) return new WP_REST_Response(['ok' => true, 'conv' => $conv, 'text' => Ssb_Brain::phrase('sys.too_long', 'ההודעה ארוכה מדי.'), 'chips' => [], 'type' => 'long'], 200);
        $out = Ssb_Engine::handle($conv, $msg, $ip);
        $r = self::ok($out); $r->header('Cache-Control', 'no-store'); return $r;
    }
    public static function rate(WP_REST_Request $req): WP_REST_Response {
        global $wpdb; $b = $req->get_json_params(); if (!is_array($b)) $b = [];
        $conv = (string) ($b['conv'] ?? ''); $rt = (int) ($b['rating'] ?? 0);
        if (!Ssb_Engine::valid_id($conv) || $rt < 1 || $rt > 5) return self::err('נתונים לא חוקיים');
        if (!self::hit('r:' . self::ip_hash(), 20, 3600)) return self::err('יותר מדי בקשות', 429);
        $note = mb_substr(trim(wp_strip_all_tags((string) ($b['note'] ?? ''))), 0, 500);
        $n = $wpdb->update(Ssb_Db::t('conv'), ['rating' => $rt, 'rating_note' => Ssb_Text::mask($note)], ['id' => $conv]);
        return $n === false ? self::err('שגיאה', 500) : self::ok();
    }

    /* ------------------------------------------------------------------ admin */
    public static function admin_perm(): bool { return self::admin_ok(); }
    public static function a_brain_list(WP_REST_Request $r): WP_REST_Response {
        return self::ok(['sections' => Ssb_Brain::sections(), 'items' => Ssb_Brain::all($r->get_param('section') ?: null, false)]);
    }
    public static function a_brain_save(WP_REST_Request $r): WP_REST_Response {
        $d = $r->get_json_params(); if (!is_array($d)) return self::err('נתונים לא חוקיים');
        $row = Ssb_Brain::save($d, self::editor());
        return is_wp_error($row) ? self::err($row) : self::ok(['item' => $row]);
    }
    public static function a_brain_active(WP_REST_Request $r): WP_REST_Response {
        $b = $r->get_json_params(); $row = Ssb_Brain::set_active((int) $r['id'], !empty($b['active']), self::editor());
        return is_wp_error($row) ? self::err($row) : self::ok(['item' => $row]);
    }
    public static function a_brain_versions(WP_REST_Request $r): WP_REST_Response { return self::ok(['versions' => Ssb_Brain::versions((int) $r['id'])]); }
    public static function a_brain_restore(WP_REST_Request $r): WP_REST_Response {
        $b = $r->get_json_params(); $row = Ssb_Brain::restore((int) $r['id'], (int) ($b['version'] ?? 0), self::editor());
        return is_wp_error($row) ? self::err($row) : self::ok(['item' => $row]);
    }
    public static function a_tickets(WP_REST_Request $r): WP_REST_Response {
        return self::ok(['tickets' => Ssb_Tickets::list(['status' => $r->get_param('status'), 'priority' => $r->get_param('priority'), 'assignee' => $r->get_param('assignee'), 'q' => $r->get_param('q')]), 'statuses' => Ssb_Tickets::STATUSES, 'priorities' => Ssb_Tickets::PRIORITIES]);
    }
    public static function a_ticket(WP_REST_Request $r): WP_REST_Response {
        $t = Ssb_Tickets::get((int) $r['id']); if (!$t) return self::err('לא נמצא', 404);
        return self::ok(['ticket' => Ssb_Tickets::decorate($t), 'notes' => Ssb_Tickets::notes((int) $t['id']), 'history' => Ssb_Tickets::history((string) $t['contact_key'])]);
    }
    public static function a_ticket_update(WP_REST_Request $r): WP_REST_Response {
        $b = $r->get_json_params(); $t = Ssb_Tickets::update_fields((int) $r['id'], is_array($b) ? $b : [], self::editor());
        return is_wp_error($t) ? self::err($t) : self::ok(['ticket' => Ssb_Tickets::decorate($t)]);
    }
    public static function a_ticket_note(WP_REST_Request $r): WP_REST_Response {
        $b = $r->get_json_params(); $txt = trim((string) ($b['body'] ?? '')); if ($txt === '') return self::err('ההערה ריקה');
        if (!Ssb_Tickets::get((int) $r['id'])) return self::err('לא נמצא', 404);
        Ssb_Tickets::note((int) $r['id'], 'internal', self::editor(), $txt); return self::ok();
    }
    public static function a_ticket_reply(WP_REST_Request $r): WP_REST_Response {
        $b = $r->get_json_params(); $t = Ssb_Tickets::reply((int) $r['id'], (string) ($b['body'] ?? ''), self::editor());
        return is_wp_error($t) ? self::err($t) : self::ok(['ticket' => Ssb_Tickets::decorate($t)]);
    }
    public static function a_unanswered(WP_REST_Request $r): WP_REST_Response {
        global $wpdb; $b = $r->get_json_params(); $st = (string) ($b['status'] ?? '');
        if (!in_array($st, ['open', 'added', 'ignored'], true)) return self::err('סטטוס לא חוקי');
        $wpdb->update(Ssb_Db::t('unanswered'), ['status' => $st], ['id' => (int) $r['id']]); return self::ok();
    }
    public static function a_settings(): WP_REST_Response { return self::ok(['settings' => Ssb_Db::settings(), 'mail_live' => Ssb_Db::mail_live()]); }
    public static function a_settings_save(WP_REST_Request $r): WP_REST_Response { $b = $r->get_json_params(); return self::ok(['settings' => Ssb_Db::save_settings(is_array($b) ? $b : [])]); }
    public static function a_notices(): WP_REST_Response {
        global $wpdb; $n = Ssb_Db::t('notice');
        $rows = $wpdb->get_results("SELECT * FROM $n ORDER BY id DESC LIMIT 100", ARRAY_A) ?: [];
        return self::ok(['unseen' => Ssb_Tickets::unseen_count(), 'notices' => $rows, 'mail_live' => Ssb_Db::mail_live()]);
    }
    public static function a_seen(): WP_REST_Response { Ssb_Tickets::mark_seen(); return self::ok(); }
    public static function a_purge(): WP_REST_Response { return self::ok(['removed' => Ssb_Tickets::purge()]); }

    public static function a_dashboard(WP_REST_Request $r): WP_REST_Response {
        global $wpdb; $c = Ssb_Db::t('conv'); $m = Ssb_Db::t('msg'); $t = Ssb_Db::t('ticket'); $u = Ssb_Db::t('unanswered');
        $days = max(1, min(365, (int) ($r->get_param('days') ?: 30))); $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $tot = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $c WHERE created_at>=%s AND msgs>0", $since));
        $esc = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $c WHERE created_at>=%s AND msgs>0 AND escalated=1", $since));
        $bot = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $c WHERE created_at>=%s AND msgs>0 AND escalated=0 AND answered>0", $since));
        $rate = $wpdb->get_row($wpdb->prepare("SELECT COUNT(rating) n, AVG(rating) a FROM $c WHERE created_at>=%s AND rating IS NOT NULL", $since), ARRAY_A);
        $top = $wpdb->get_results($wpdb->prepare("SELECT text, COUNT(*) n FROM $m WHERE role='user' AND created_at>=%s AND CHAR_LENGTH(text)>=4 GROUP BY text ORDER BY n DESC, MAX(id) DESC LIMIT 10", $since), ARRAY_A) ?: [];
        $byStatus = $wpdb->get_results("SELECT status, COUNT(*) n FROM $t GROUP BY status", ARRAY_A) ?: [];
        $overdue = 0; foreach (Ssb_Tickets::list(['status' => '']) as $x) if (!empty($x['sla_overdue'])) $overdue++;
        return self::ok([
            'days' => $days, 'conversations' => $tot, 'resolved_by_bot' => $bot, 'escalated' => $esc,
            'rating_count' => (int) ($rate['n'] ?? 0), 'rating_avg' => $rate && $rate['n'] ? round((float) $rate['a'], 2) : null,
            'top_questions' => $top,
            'unanswered' => $wpdb->get_results("SELECT * FROM $u WHERE status='open' ORDER BY hits DESC, last_seen DESC LIMIT 50", ARRAY_A) ?: [],
            'tickets_by_status' => $byStatus, 'tickets_overdue' => $overdue,
        ]);
    }
}
