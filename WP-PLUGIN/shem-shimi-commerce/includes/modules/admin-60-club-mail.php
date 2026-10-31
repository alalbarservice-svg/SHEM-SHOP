<?php
if (!defined('ABSPATH')) exit;
/**
 * SHEM club mailing ("הודעת תפוצה") - server side only, no admin UI change.
 *
 * Only the SHEM role has a club (SHIMI answers 404 everywhere here). Transport: Resend over HTTPS using the
 * server constants SCC_RESEND_API_KEY and SCC_MAIL_FROM (never stored in the repo, the database or any response).
 * On staging define SCC_MAIL_SINK=true: messages are recorded in the option scc_mail_sink instead of being sent.
 *
 * Safety rules built in:
 *   - default is a dry run; real sending needs {"confirm": true} in the request;
 *   - recipients = club members with status "active" and a recorded consent date, de-duplicated by e-mail;
 *   - the advertiser details (name, address, phone, e-mail from the invoice settings) must be complete (Israeli spam law, s.30A);
 *   - subject starts with the word "פרסומת"; each message carries a personal opt-out link and List-Unsubscribe headers;
 *   - daily cap (SCC_MAIL_DAILY_CAP, default 100 = Resend free plan); the rest is deferred and sent when the same
 *     campaignId is posted again (members already sent are never sent twice).
 *
 * Routes:
 *   GET  /api/admin/manage/club/broadcast        status (configured, sink, cap, recipients, missing advertiser fields)
 *   POST /api/admin/manage/club/broadcast        {subject,text,confirm?,campaignId?,testTo?}
 *   GET  /api/club/unsubscribe?m=&t=             public confirmation page (shows a button)
 *   POST /api/club/unsubscribe?m=&t=             public: performs the opt-out (also the RFC 8058 one-click target)
 */
final class Scc_Admin_Mail {
    const SINK_OPTION = 'scc_mail_sink';
    const DAY_OPTION = 'scc_mail_day';
    const MAX_SUBJECT = 150;
    const MAX_TEXT = 5000;

    public static function role_ok(): bool { return SCC_ROLE === 'shem'; }
    public static function sink(): bool { return (bool) apply_filters('scc_admin_mail_sink', defined('SCC_MAIL_SINK') && SCC_MAIL_SINK); }
    public static function from(): string { return defined('SCC_MAIL_FROM') ? trim((string) SCC_MAIL_FROM) : ''; }
    public static function configured(): bool { return self::from() !== '' && (self::sink() || (defined('SCC_RESEND_API_KEY') && SCC_RESEND_API_KEY)); }
    public static function daily_cap(): int { return defined('SCC_MAIL_DAILY_CAP') ? max(0, (int) SCC_MAIL_DAILY_CAP) : 100; }

    /* ------------------------------------------------------------ daily counter (UTC day, same as the provider) */
    public static function sent_today(): int {
        $d = get_option(self::DAY_OPTION);
        return is_array($d) && ($d['d'] ?? '') === gmdate('Y-m-d') ? (int) ($d['n'] ?? 0) : 0;
    }
    private static function add_sent(int $n): void { update_option(self::DAY_OPTION, ['d' => gmdate('Y-m-d'), 'n' => self::sent_today() + $n], false); }
    public static function remaining_today(): int { return max(0, self::daily_cap() - self::sent_today()); }

    /* ------------------------------------------------------------ advertiser identity (from the invoice settings) */
    public static function advertiser(): array {
        $b = (array) (Scc_Admin::setting('invoice') ?? []);
        return ['name' => Scc_Admin::str($b['legalName'] ?? null, 200), 'address' => Scc_Admin::str($b['address'] ?? null, 300),
            'phone' => Scc_Admin::str($b['phone'] ?? null, 50), 'email' => Scc_Admin::str($b['email'] ?? null, 254)];
    }
    public static function missing_advertiser(): array {
        $a = self::advertiser(); $m = [];
        foreach (['name', 'address', 'phone'] as $k) if ($a[$k] === '') $m[] = $k;
        if (!Scc_Admin::email($a['email'])) $m[] = 'email';
        return $m;
    }

    /* ------------------------------------------------------------ recipients */
    /** @return array<int,array{id:string,email:string}> active + consented, unique e-mails */
    public static function recipients(): array {
        $out = []; $seen = [];
        foreach (Scc_Admin::store('members')->rows() as $m) {
            $email = strtolower(trim((string) ($m['email'] ?? '')));
            if (($m['status'] ?? '') !== 'active' || empty($m['consent_at']) || !Scc_Admin::email($email) || isset($seen[$email])) continue;
            $seen[$email] = true; $out[] = ['id' => (string) $m['id'], 'email' => $email];
        }
        usort($out, fn($a, $b) => strcmp($a['id'], $b['id']));
        return $out;
    }
    private static function mask(string $e): string { [$u, $d] = explode('@', $e, 2) + ['', '']; return mb_substr($u, 0, 1) . '***@' . $d; }

    /* ------------------------------------------------------------ opt-out token */
    public static function token(string $memberId): string { return hash_hmac('sha256', 'club-unsub:' . $memberId, wp_salt('auth')); }
    public static function unsubscribe_url(string $memberId): string {
        return home_url('/api/club/unsubscribe') . '?m=' . rawurlencode($memberId) . '&t=' . self::token($memberId);
    }

    /* ------------------------------------------------------------ message */
    public static function subject(string $s): string {
        $s = trim($s);
        return mb_strpos($s, 'פרסומת') === 0 ? $s : 'פרסומת: ' . $s;
    }
    public static function build(string $memberId, string $to, string $subject, string $text): array {
        $a = self::advertiser(); $url = self::unsubscribe_url($memberId); $brand = Scc_Admin::brand();
        $footerText = "\n\n--\nהודעה זו נשלחה אליך כי נרשמת למועדון {$brand}.\n{$a['name']} | {$a['address']} | {$a['phone']} | {$a['email']}\nלהסרה מרשימת הדיוור: {$url}\nאפשר גם לשלוח בקשת הסרה למייל {$a['email']}";
        $e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $html = '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"></head><body style="font-family:Arial,Helvetica,sans-serif;direction:rtl;text-align:right;line-height:1.6;color:#111">'
            . '<div>' . nl2br($e($text), false) . '</div><hr style="margin:24px 0;border:0;border-top:1px solid #ddd">'
            . '<p style="font-size:12px;color:#555">הודעה זו נשלחה אליך כי נרשמת למועדון ' . $e($brand) . '.<br>' . $e($a['name']) . ' | ' . $e($a['address']) . ' | ' . $e($a['phone']) . ' | ' . $e($a['email'])
            . '<br><a href="' . $e($url) . '">להסרה מרשימת הדיוור</a> (אפשר גם לשלוח בקשת הסרה למייל ' . $e($a['email']) . ')</p></body></html>';
        return ['to' => $to, 'subject' => self::subject($subject), 'html' => $html, 'text' => $text . $footerText, 'reply_to' => $a['email'],
            'headers' => ['List-Unsubscribe' => '<' . $url . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']];
    }

    /* ------------------------------------------------------------ transport */
    /** @param array<int,array> $msgs @return array<int,array{ok:bool,id:?string,error:?string}> same order as $msgs */
    public static function deliver(array $msgs): array {
        $res = [];
        if (self::sink()) {
            $log = get_option(self::SINK_OPTION); $log = is_array($log) ? $log : [];
            foreach ($msgs as $i => $m) {
                $id = 'sink-' . wp_generate_uuid4();
                $log[] = ['id' => $id, 'at' => gmdate('c'), 'from' => self::from(), 'to' => $m['to'], 'subject' => $m['subject'], 'text' => $m['text'], 'html' => $m['html'], 'headers' => $m['headers'], 'reply_to' => $m['reply_to']];
                $res[$i] = ['ok' => true, 'id' => $id, 'error' => null];
            }
            update_option(self::SINK_OPTION, array_slice($log, -200), false);
            return $res;
        }
        $key = defined('SCC_RESEND_API_KEY') ? (string) SCC_RESEND_API_KEY : '';
        foreach (array_chunk($msgs, 50, true) as $chunk) {
            $keys = array_keys($chunk);
            $payload = [];
            foreach ($chunk as $m) $payload[] = ['from' => self::from(), 'to' => [$m['to']], 'subject' => $m['subject'], 'html' => $m['html'], 'text' => $m['text'], 'headers' => $m['headers'], 'reply_to' => $m['reply_to']];
            $args = ['timeout' => 30, 'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'], 'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            $r = wp_remote_post('https://api.resend.com/emails/batch', $args);
            if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 429) { // one polite retry
                $wait = (int) wp_remote_retrieve_header($r, 'retry-after'); sleep(max(1, min(5, $wait)));
                $r = wp_remote_post('https://api.resend.com/emails/batch', $args);
            }
            $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
            $body = is_wp_error($r) ? [] : (json_decode((string) wp_remote_retrieve_body($r), true) ?: []);
            $ids = is_array($body['data'] ?? null) ? array_values($body['data']) : [];
            if ($code >= 200 && $code < 300 && count($ids) === count($keys)) {
                foreach ($keys as $n => $k) $res[$k] = ['ok' => true, 'id' => (string) ($ids[$n]['id'] ?? ''), 'error' => null];
            } else {
                $err = is_wp_error($r) ? 'transport' : ('http ' . $code . ' ' . mb_substr((string) ($body['message'] ?? ''), 0, 160));
                error_log('[scc-mail] batch failed: ' . $err);
                foreach ($keys as $k) $res[$k] = ['ok' => false, 'id' => null, 'error' => $err];
            }
        }
        return $res;
    }

    /* ------------------------------------------------------------ admin API */
    public static function status(): Scc_Response {
        if (!self::role_ok()) Scc_Admin::fail('לא נמצא.', 404);
        return Scc_Admin::json(['configured' => self::configured(), 'sink' => self::sink(), 'dailyCap' => self::daily_cap(), 'sentToday' => self::sent_today(), 'remainingToday' => self::remaining_today(),
            'activeRecipients' => count(self::recipients()), 'missingAdvertiserFields' => self::missing_advertiser()]);
    }

    public static function broadcast(Scc_Request $r): Scc_Response {
        if (!self::role_ok()) Scc_Admin::fail('לא נמצא.', 404);
        $d = Scc_Admin::body($r);
        $subject = Scc_Admin::str($d['subject'] ?? null, self::MAX_SUBJECT);
        $text = Scc_Admin::str($d['text'] ?? null, self::MAX_TEXT);
        if ($subject === '' || $text === '') Scc_Admin::fail('יש למלא נושא ותוכן להודעה.');
        $confirm = ($d['confirm'] ?? null) === true;
        $missing = self::missing_advertiser();
        $testTo = isset($d['testTo']) ? strtolower(Scc_Admin::str($d['testTo'], 254)) : '';
        if ($testTo !== '' && !Scc_Admin::email($testTo)) Scc_Admin::fail('כתובת הבדיקה אינה תקינה.');

        // ---- single test message
        if ($testTo !== '') {
            $msg = self::build('_test', $testTo, 'בדיקה - ' . $subject, $text);
            if (!$confirm) return Scc_Admin::json(['dryRun' => true, 'test' => true, 'subject' => $msg['subject'], 'missingAdvertiserFields' => $missing, 'wouldSendTo' => self::mask($testTo)]);
            self::guard_real($missing);
            if (self::remaining_today() < 1) Scc_Admin::fail('הושגה מכסת השליחה היומית.', 429);
            $out = self::deliver([$msg])[0];
            if ($out['ok']) self::add_sent(1);
            return Scc_Admin::json(['test' => true, 'sent' => $out['ok'] ? 1 : 0, 'failed' => $out['ok'] ? 0 : 1, 'remainingToday' => self::remaining_today()]);
        }

        // ---- campaign
        $rcpts = self::recipients();
        $cid = Scc_Admin::str($d['campaignId'] ?? null, 64);
        $camp = null;
        if ($cid !== '') {
            if (!preg_match('/^[a-f0-9-]{36}$/', $cid)) Scc_Admin::fail('מזהה קמפיין לא תקין.');
            $camp = get_option('scc_mail_campaign_' . $cid);
            if (!is_array($camp)) Scc_Admin::fail('הקמפיין לא נמצא.', 404);
            $subject = (string) $camp['subject']; $text = (string) $camp['text']; // a resumed campaign keeps its original content
        }
        $done = $camp ? array_flip($camp['sent']) : [];
        $pending = array_values(array_filter($rcpts, fn($m) => !isset($done[$m['id']])));
        $now = min(count($pending), self::remaining_today());
        if (!$confirm) {
            return Scc_Admin::json(['dryRun' => true, 'subject' => self::subject($subject), 'recipients' => count($rcpts), 'alreadySent' => count($done), 'wouldSendNow' => $now, 'deferred' => count($pending) - $now,
                'dailyCap' => self::daily_cap(), 'sentToday' => self::sent_today(), 'configured' => self::configured(), 'sink' => self::sink(), 'missingAdvertiserFields' => $missing,
                'sample' => array_map(fn($m) => self::mask($m['email']), array_slice($pending, 0, 3))]);
        }
        self::guard_real($missing);
        if (!$pending) return Scc_Admin::json(['campaignId' => $cid, 'sent' => 0, 'failed' => 0, 'deferred' => 0, 'remainingToday' => self::remaining_today(), 'note' => 'אין נמענים נוספים.']);
        if ($now < 1) Scc_Admin::fail('הושגה מכסת השליחה היומית. אפשר להמשיך מחר עם אותו campaignId.', 429);
        if (!Scc_Core_Util::lock('club-broadcast', 5)) Scc_Admin::fail('שליחה אחרת מתבצעת כרגע. נסו שוב בעוד רגע.', 409);
        try {
            if (!$camp) { $cid = wp_generate_uuid4(); $camp = ['subject' => $subject, 'text' => $text, 'created' => gmdate('c'), 'sent' => [], 'failed' => []]; }
            $batch = array_slice($pending, 0, $now);
            $msgs = []; foreach ($batch as $i => $m) $msgs[$i] = self::build($m['id'], $m['email'], $subject, $text);
            $res = self::deliver($msgs);
            $ok = 0; $bad = 0; $failed = [];
            foreach ($batch as $i => $m) { if (!empty($res[$i]['ok'])) { $camp['sent'][] = $m['id']; $ok++; } else { $failed[] = $m['id']; $bad++; } }
            $camp['failed'] = $failed;
            update_option('scc_mail_campaign_' . $cid, $camp, false);
            if ($ok) self::add_sent($ok);
        } finally { Scc_Core_Util::unlock('club-broadcast'); }
        return Scc_Admin::json(['campaignId' => $cid, 'sent' => $ok, 'failed' => $bad, 'deferred' => count($pending) - $ok - $bad, 'remainingToday' => self::remaining_today(), 'sink' => self::sink()]);
    }
    private static function guard_real(array $missing): void {
        if (!self::configured()) Scc_Admin::fail('שירות הדיוור אינו מוגדר בשרת (נדרשים שולח ומפתח).', 409);
        if ($missing) Scc_Admin::fail('פרטי המפרסם בהגדרות המסמכים חסרים (' . implode(', ', $missing) . '). הם נדרשים בכל הודעה פרסומית.', 409);
    }

    /* ------------------------------------------------------------ public opt-out */
    private static function page(string $title, string $body, int $status = 200): Scc_Response {
        $html = '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>'
            . '<body style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:12vh auto;padding:0 16px;text-align:center;line-height:1.7"><h1 style="font-size:20px">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>' . $body . '</body></html>';
        return Scc_Response::raw($html, 'text/html; charset=utf-8', $status);
    }
    private static function valid_link(Scc_Request $r): ?string {
        $m = is_string($r->query['m'] ?? null) ? $r->query['m'] : ''; $t = is_string($r->query['t'] ?? null) ? $r->query['t'] : '';
        return $m !== '' && strlen($m) <= 64 && hash_equals(self::token($m), $t) ? $m : null;
    }
    public static function unsubscribe(Scc_Request $r): Scc_Response {
        if (!self::role_ok()) return Scc_Response::error('לא נמצא.', 404);
        $id = self::valid_link($r);
        if ($id === null) return self::page('הקישור אינו תקין', '<p>אפשר לבקש הסרה במענה להודעה שקיבלתם.</p>', 400);
        if ($r->method === 'GET') {
            $q = '?m=' . rawurlencode($id) . '&t=' . self::token($id);
            return self::page('הסרה מרשימת הדיוור', '<form method="post" action="' . htmlspecialchars(home_url('/api/club/unsubscribe') . $q, ENT_QUOTES, 'UTF-8') . '"><button type="submit" style="font-size:16px;padding:10px 24px;cursor:pointer">אישור הסרה</button></form>');
        }
        $m = $id === '_test' ? null : Scc_Docs::get('members', $id);
        if ($m && ($m['status'] ?? '') !== 'unsubscribed') { $m['status'] = 'unsubscribed'; $m['unsubscribed_at'] = gmdate('Y-m-d\TH:i:s') . '.000Z'; unset($m['id']); Scc_Docs::put('members', $id, $m); }
        return self::page('הוסרתם מרשימת הדיוור', '<p>לא תקבלו מאיתנו הודעות פרסומיות נוספות.</p>');
    }
}

add_filter('scc_admin_mail_configured', fn($v) => $v || (Scc_Admin_Mail::role_ok() && Scc_Admin_Mail::configured()));
Scc_Admin::route('GET', '#^/api/admin/manage/club/broadcast$#', fn() => Scc_Admin_Mail::status());
Scc_Admin::route('POST', '#^/api/admin/manage/club/broadcast$#', fn(Scc_Request $r) => Scc_Admin_Mail::broadcast($r));
Scc_Router::add('GET', '#^/api/club/unsubscribe$#', [Scc_Admin_Mail::class, 'unsubscribe']);
Scc_Router::add('POST', '#^/api/club/unsubscribe$#', [Scc_Admin_Mail::class, 'unsubscribe']);
