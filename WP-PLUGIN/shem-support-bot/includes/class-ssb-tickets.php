<?php
if (!defined('ABSPATH')) exit;
/** Escalation tickets: creation, priority, SLA (Israeli business hours), notes/replies, notifications, retention purge. */
final class Ssb_Tickets {
    const STATUSES = ['new' => 'חדש', 'in_progress' => 'בטיפול', 'waiting' => 'ממתין ללקוח', 'closed' => 'סגור'];
    const PRIORITIES = ['urgent' => 'דחוף', 'high' => 'גבוהה', 'normal' => 'רגילה', 'low' => 'נמוכה'];
    const TZ = 'Asia/Jerusalem';

    public static function boot(): void {
        add_action('ssb_daily_purge', [self::class, 'purge']);
        add_action('init', function () { if (!wp_next_scheduled('ssb_daily_purge')) wp_schedule_event(time() + 600, 'daily', 'ssb_daily_purge'); });
    }

    /* ------------------------------------------------------------------ business hours / SLA */
    public static function tz(): DateTimeZone { return new DateTimeZone(self::TZ); }
    private static function window(DateTimeImmutable $d): ?array {
        $h = Ssb_Db::settings()['hours']; $w = (int) $d->format('w');
        $r = $w <= 4 ? ($h['sun-thu'] ?? null) : ($w === 5 ? ($h['fri'] ?? null) : ($h['sat'] ?? null));
        if (!is_array($r) || count($r) < 2) return null;
        [$oh, $om] = array_map('intval', explode(':', (string) $r[0]) + [0, 0]);
        [$ch, $cm] = array_map('intval', explode(':', (string) $r[1]) + [0, 0]);
        return [$d->setTime($oh, $om, 0), $d->setTime($ch, $cm, 0)];
    }
    public static function is_open(?DateTimeImmutable $t = null): bool {
        $t = $t ?: new DateTimeImmutable('now', self::tz()); $w = self::window($t);
        return $w && $t >= $w[0] && $t < $w[1];
    }
    /** Add business hours; the clock starts at the next opening when $t is outside hours. */
    public static function add_business_hours(DateTimeImmutable $t, float $hours): DateTimeImmutable {
        $left = (int) round($hours * 3600);
        for ($i = 0; $i < 90 && $left > 0; $i++) {
            $w = self::window($t);
            if (!$w || $t >= $w[1]) { $t = $t->modify('+1 day')->setTime(0, 0, 0); continue; }
            if ($t < $w[0]) $t = $w[0];
            $avail = $w[1]->getTimestamp() - $t->getTimestamp();
            if ($left <= $avail) return $t->modify('+' . $left . ' seconds');
            $left -= $avail; $t = $t->modify('+1 day')->setTime(0, 0, 0);
        }
        return $t;
    }
    public static function eta_text(string $priority): string {
        $h = (float) (Ssb_Db::settings()['sla_hours'][$priority] ?? 8);
        $due = self::add_business_hours(new DateTimeImmutable('now', self::tz()), $h);
        return 'עד ' . $due->format('d/m/Y H:i');
    }
    public static function priority_for(string $text, string $reason, bool $angry, string $contact_key): string {
        $l = Ssb_Text::light($text);
        if (Ssb_Text::legal($l)) return 'urgent';
        $p = 'normal';
        if ($angry || in_array($reason, ['damaged', 'payment', 'refund_dispute', 'angry'], true)) $p = 'high';
        if (preg_match('/(\d[\d,]{3,})\s*(₪|ש"ח|שח|שקל)/u', $l, $m) && (int) str_replace(',', '', $m[1]) > 1000) $p = 'high';
        if ($contact_key !== '' && self::recent_count($contact_key, 7) >= 2) $p = 'high';
        return $p;
    }
    private static function recent_count(string $key, int $days): int {
        global $wpdb; $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Ssb_Db::t('ticket') . ' WHERE contact_key=%s AND created_at>=%s', $key, $since));
    }
    public static function contact_key(string $contact): string {
        $e = Ssb_Text::emails($contact); if ($e) return $e[0];
        $p = Ssb_Text::phones($contact); return $p ? $p[0] : '';
    }

    /* ------------------------------------------------------------------ create */
    /** @return array|WP_Error ticket row */
    public static function create(array $d) {
        global $wpdb; $t = Ssb_Db::t('ticket'); $now = Ssb_Db::now(); $s = Ssb_Db::settings();
        $name = mb_substr(trim(wp_strip_all_tags((string) ($d['name'] ?? ''))), 0, 190);
        $contact = mb_substr(trim(wp_strip_all_tags((string) ($d['contact'] ?? ''))), 0, 190);
        $key = self::contact_key($contact);
        if ($name === '' || $key === '') return new WP_Error('invalid', 'חסר שם או פרטי קשר תקינים');
        $order = mb_substr(trim(wp_strip_all_tags((string) ($d['order_number'] ?? ''))), 0, 60);
        $summary = mb_substr(trim(sanitize_textarea_field((string) ($d['summary'] ?? ''))), 0, 2000);
        $reason = mb_substr(sanitize_key((string) ($d['reason'] ?? 'customer_request')), 0, 80);
        $pr = $d['priority'] ?? self::priority_for($summary . ' ' . ($d['transcript'] ?? ''), $reason, !empty($d['angry']), $key);
        if (!isset(self::PRIORITIES[$pr])) $pr = 'normal';
        $due = self::add_business_hours(new DateTimeImmutable('now', self::tz()), (float) ($s['sla_hours'][$pr] ?? 8))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $tmp = 'tmp' . wp_generate_password(10, false);
        $ok = $wpdb->insert($t, ['number' => $tmp, 'conv_id' => mb_substr((string) ($d['conv_id'] ?? ''), 0, 40), 'name' => $name, 'contact' => $contact, 'contact_key' => $key, 'order_number' => $order, 'summary' => $summary, 'reason' => $reason, 'priority' => $pr, 'status' => 'new', 'sla_due' => $due, 'created_at' => $now, 'updated_at' => $now]);
        if (!$ok) return new WP_Error('db', 'שגיאת מסד נתונים');
        $id = (int) $wpdb->insert_id; $num = $s['ticket_prefix'] . '-' . (1000 + $id);
        $wpdb->update($t, ['number' => $num], ['id' => $id]);
        if (!empty($d['transcript'])) self::note($id, 'system', 'bot', "תמלול השיחה:\n" . mb_substr((string) $d['transcript'], 0, 6000));
        $row = self::get($id);
        self::notify_new($row);
        return $row;
    }

    /* ------------------------------------------------------------------ read */
    public static function get($idOrNumber): ?array {
        global $wpdb; $t = Ssb_Db::t('ticket');
        $r = is_numeric($idOrNumber) ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", (int) $idOrNumber), ARRAY_A)
                                     : $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE number=%s", strtoupper((string) $idOrNumber)), ARRAY_A);
        return $r ?: null;
    }
    public static function notes(int $id, bool $customer_visible = false): array {
        global $wpdb; $n = Ssb_Db::t('ticket_note');
        $sql = "SELECT * FROM $n WHERE ticket_id=%d" . ($customer_visible ? " AND kind IN ('reply','customer')" : '') . ' ORDER BY id ASC';
        return $wpdb->get_results($wpdb->prepare($sql, $id), ARRAY_A) ?: [];
    }
    public static function list(array $f = []): array {
        global $wpdb; $t = Ssb_Db::t('ticket'); $w = ['1=1']; $a = [];
        if (!empty($f['status']) && isset(self::STATUSES[$f['status']])) { $w[] = 'status=%s'; $a[] = $f['status']; }
        if (!empty($f['priority']) && isset(self::PRIORITIES[$f['priority']])) { $w[] = 'priority=%s'; $a[] = $f['priority']; }
        if (!empty($f['assignee'])) { $w[] = 'assignee=%s'; $a[] = (string) $f['assignee']; }
        if (!empty($f['q'])) { $like = '%' . $wpdb->esc_like((string) $f['q']) . '%'; $w[] = '(number LIKE %s OR name LIKE %s OR contact LIKE %s OR order_number LIKE %s)'; array_push($a, $like, $like, $like, $like); }
        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(status,'new','in_progress','waiting','closed'), FIELD(priority,'urgent','high','normal','low'), sla_due ASC LIMIT 300";
        $rows = $wpdb->get_results($a ? $wpdb->prepare($sql, ...$a) : $sql, ARRAY_A) ?: [];
        return array_map([self::class, 'decorate'], $rows);
    }
    public static function history(string $contact_key): array {
        global $wpdb; if ($contact_key === '') return [];
        return $wpdb->get_results($wpdb->prepare('SELECT id,number,status,priority,reason,created_at FROM ' . Ssb_Db::t('ticket') . ' WHERE contact_key=%s ORDER BY id DESC LIMIT 50', $contact_key), ARRAY_A) ?: [];
    }
    /** Adds the SLA view (remaining seconds, overdue flag). */
    public static function decorate(array $r): array {
        $due = $r['sla_due'] ? strtotime($r['sla_due'] . ' UTC') : 0;
        $ref = $r['status'] === 'waiting' && $r['sla_paused_at'] ? strtotime($r['sla_paused_at'] . ' UTC') : time();
        $r['sla_remaining'] = $due ? $due - $ref : null;
        $r['sla_overdue'] = $due && $r['status'] !== 'closed' && $due < $ref;
        $r['status_label'] = self::STATUSES[$r['status']] ?? $r['status'];
        $r['priority_label'] = self::PRIORITIES[$r['priority']] ?? $r['priority'];
        return $r;
    }

    /* ------------------------------------------------------------------ write */
    public static function note(int $ticket_id, string $kind, string $author, string $body): int {
        global $wpdb;
        if (!in_array($kind, ['internal', 'reply', 'customer', 'system'], true)) $kind = 'internal';
        $wpdb->insert(Ssb_Db::t('ticket_note'), ['ticket_id' => $ticket_id, 'kind' => $kind, 'author' => mb_substr($author, 0, 100), 'body' => mb_substr(sanitize_textarea_field($body), 0, 6000), 'created_at' => Ssb_Db::now()]);
        $wpdb->update(Ssb_Db::t('ticket'), ['updated_at' => Ssb_Db::now()], ['id' => $ticket_id]);
        return (int) $wpdb->insert_id;
    }
    /** Status change with SLA pause/resume: "ממתין ללקוח" pauses the clock, leaving it moves the due time by the paused span. */
    public static function set_status(int $id, string $status, string $editor) {
        global $wpdb; $t = Ssb_Db::t('ticket'); $r = self::get($id);
        if (!$r) return new WP_Error('nf', 'פנייה לא נמצאה');
        if (!isset(self::STATUSES[$status])) return new WP_Error('bad', 'סטטוס לא חוקי');
        if ($r['status'] === $status) return $r;
        $u = ['status' => $status, 'updated_at' => Ssb_Db::now()];
        if ($status === 'waiting') $u['sla_paused_at'] = Ssb_Db::now();
        elseif ($r['status'] === 'waiting' && $r['sla_paused_at']) {
            $span = time() - strtotime($r['sla_paused_at'] . ' UTC');
            if ($r['sla_due']) $u['sla_due'] = gmdate('Y-m-d H:i:s', strtotime($r['sla_due'] . ' UTC') + max(0, $span));
            $u['sla_paused_at'] = null;
        }
        $u['closed_at'] = $status === 'closed' ? Ssb_Db::now() : null;
        $wpdb->update($t, $u, ['id' => $id]);
        self::note($id, 'system', $editor, 'סטטוס שונה ל: ' . self::STATUSES[$status]);
        return self::get($id);
    }
    public static function update_fields(int $id, array $in, string $editor) {
        global $wpdb; $r = self::get($id); if (!$r) return new WP_Error('nf', 'פנייה לא נמצאה');
        $u = [];
        if (isset($in['priority']) && isset(self::PRIORITIES[$in['priority']]) && $in['priority'] !== $r['priority']) { $u['priority'] = $in['priority']; }
        if (isset($in['assignee'])) $u['assignee'] = mb_substr(trim(wp_strip_all_tags((string) $in['assignee'])), 0, 100);
        if ($u) { $u['updated_at'] = Ssb_Db::now(); $wpdb->update(Ssb_Db::t('ticket'), $u, ['id' => $id]); self::note($id, 'system', $editor, 'עודכן: ' . implode(', ', array_keys($u))); }
        if (isset($in['status'])) { $res = self::set_status($id, (string) $in['status'], $editor); if (is_wp_error($res)) return $res; }
        return self::get($id);
    }
    /** Manager reply from admin: stored (customer-visible), emailed (gated), moves to waiting-for-customer. */
    public static function reply(int $id, string $body, string $editor) {
        $r = self::get($id); if (!$r) return new WP_Error('nf', 'פנייה לא נמצאה');
        $body = trim($body); if ($body === '') return new WP_Error('empty', 'התגובה ריקה');
        global $wpdb;
        self::note($id, 'reply', $editor, $body);
        if (empty($r['first_response_at'])) $wpdb->update(Ssb_Db::t('ticket'), ['first_response_at' => Ssb_Db::now()], ['id' => $id]);
        self::set_status($id, 'waiting', $editor);
        $e = Ssb_Text::emails((string) $r['contact']);
        if ($e) self::send_mail($e[0], 'תגובה לפנייה ' . $r['number'], "שלום " . $r['name'] . ",\n\n" . $body . "\n\nמספר פנייה: " . $r['number'], 'reply', $r['number']);
        return self::get($id);
    }
    /** What a customer may see: status + manager replies. Needs ticket number AND matching contact. */
    public static function customer_view(string $number, string $contact): ?array {
        $r = self::get($number); $key = self::contact_key($contact);
        if (!$r || $key === '' || !hash_equals((string) $r['contact_key'], $key)) return null;
        return ['number' => $r['number'], 'status' => self::STATUSES[$r['status']] ?? $r['status'], 'replies' => array_map(fn($n) => ['at' => $n['created_at'], 'text' => $n['body']], array_values(array_filter(self::notes((int) $r['id'], true), fn($n) => $n['kind'] === 'reply')))];
    }

    /* ------------------------------------------------------------------ notifications (admin + email) */
    private static function notify_new(array $r): void {
        global $wpdb; $s = Ssb_Db::settings(); $now = Ssb_Db::now();
        $due = $r['sla_due'] ? (new DateTimeImmutable($r['sla_due'] . ' UTC'))->setTimezone(self::tz())->format('d/m/Y H:i') : '';
        $text = "פנייה חדשה {$r['number']} (עדיפות: " . self::PRIORITIES[$r['priority']] . ")\nשם: {$r['name']}\nקשר: {$r['contact']}\nהזמנה: " . ($r['order_number'] ?: '-') . "\nסיבה: {$r['reason']}\nנושא: {$r['summary']}\nיעד מענה: $due";
        $wpdb->insert(Ssb_Db::t('notice'), ['channel' => 'admin', 'kind' => 'ticket', 'ref' => $r['number'], 'subject' => 'פנייה חדשה ' . $r['number'], 'text' => $text, 'sent' => 1, 'seen' => 0, 'created_at' => $now]);
        self::send_mail((string) $s['manager_email'], '[' . get_bloginfo('name') . '] פנייה חדשה ' . $r['number'], $text, 'ticket', $r['number']);
        // Questions the bot could not answer are also handed to the service rep on WhatsApp. No sender is connected yet:
        // the message is always queued in the log (sent=0) with the rep number from settings (empty until the owner sets it).
        if (in_array($r['reason'], ['unanswered', 'repeated_miss'], true)) {
            $wa = (string) $s['wa_number'];
            $msg = "פנייה חדשה מהבוט {$r['number']}\nשם: {$r['name']}\nטלפון: {$r['contact']}\nסיבת הפנייה: {$r['summary']}";
            $wpdb->insert(Ssb_Db::t('notice'), ['channel' => 'whatsapp', 'kind' => 'ticket', 'ref' => $r['number'], 'to_addr' => $wa, 'subject' => $wa === '' ? 'ממתין להגדרת מספר וואטסאפ של הנציג' : 'ממתין לחיבור שליחת וואטסאפ', 'text' => $msg, 'sent' => 0, 'seen' => 1, 'created_at' => $now]);
        }
    }
    /** Logs every mail; really sends only when SSB_MAIL_LIVE===true (staging never emails). */
    public static function send_mail(string $to, string $subject, string $text, string $kind, string $ref): bool {
        global $wpdb; if (!is_email($to)) return false;
        $live = Ssb_Db::mail_live(); $sent = 0;
        if ($live) $sent = wp_mail($to, $subject, $text, ['Content-Type: text/plain; charset=UTF-8']) ? 1 : 0;
        $wpdb->insert(Ssb_Db::t('notice'), ['channel' => 'email', 'kind' => $kind, 'ref' => $ref, 'to_addr' => $to, 'subject' => mb_substr($subject, 0, 255), 'text' => $text, 'sent' => $sent, 'seen' => 1, 'created_at' => Ssb_Db::now()]);
        return (bool) $sent;
    }
    public static function unseen_count(): int { global $wpdb; return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Ssb_Db::t('notice') . " WHERE channel='admin' AND seen=0"); }
    public static function mark_seen(): void { global $wpdb; $wpdb->query('UPDATE ' . Ssb_Db::t('notice') . " SET seen=1 WHERE channel='admin'"); }

    /* ------------------------------------------------------------------ retention */
    /** Deletes chat logs older than the configured retention (tickets are kept). Returns rows removed. */
    public static function purge(): int {
        global $wpdb; $days = (int) Ssb_Db::settings()['retention_days']; $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $c = Ssb_Db::t('conv'); $m = Ssb_Db::t('msg');
        $n = (int) $wpdb->query($wpdb->prepare("DELETE FROM $m WHERE created_at < %s", $cut));
        $n += (int) $wpdb->query($wpdb->prepare("DELETE FROM $c WHERE updated_at < %s", $cut));
        return $n;
    }
}
