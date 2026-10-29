<?php
if (!defined('ABSPATH')) exit;
/**
 * Conversation engine (no external AI, no cost). Every sentence the bot says comes from:
 *   1. the admin brain (Ssb_Brain), or 2. live store data (Ssb_Live), or 3. fixed flow prompts that are themselves brain entries.
 * Customer text is DATA: it is matched against the brain, never executed or obeyed.
 */
final class Ssb_Engine {
    const FALLBACK_DEFAULT = 'מתנצל אך אין ביכולתי לענות תשובה על שאלתך, לכן אשמח להעביר את פנייתך לשירות הלקוחות עם נציג אינושי. האם תרצה שהעביר אותך?';
    const HANDOFF_DEFAULT = "אין בעיה הנושא בטיפול, שלחתי את פנייתך לנציג השירות שלנו והוא יצור איתך קשר בהקדם להמשך תהליך. תודה על סבלנותך והמשך יום נפלא בינתיים \u{1F60A}";
    const CHIPS = ['בדיקת הזמנה', 'כמה עולה משלוח', 'איך מחזירים מוצר', 'דברו עם נציג'];

    public static function new_id(): string { return bin2hex(random_bytes(16)); }
    public static function valid_id(string $id): bool { return (bool) preg_match('/^[a-f0-9]{32}$/', $id); }

    /* ------------------------------------------------------------------ storage */
    private static function load(string $id): ?array {
        global $wpdb; $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Ssb_Db::t('conv') . ' WHERE id=%s', $id), ARRAY_A);
        if (!$r) return null; $r['state'] = json_decode((string) $r['state'], true) ?: []; return $r;
    }
    private static function create(string $ip_hash): string {
        global $wpdb; $id = self::new_id(); $now = Ssb_Db::now();
        $wpdb->insert(Ssb_Db::t('conv'), ['id' => $id, 'created_at' => $now, 'updated_at' => $now, 'state' => '{}', 'ip_hash' => $ip_hash]);
        return $id;
    }
    private static function log(string $conv, string $role, string $text, array $meta = []): void {
        global $wpdb;
        $wpdb->insert(Ssb_Db::t('msg'), ['conv_id' => $conv, 'role' => $role, 'text' => mb_substr(Ssb_Text::mask($text), 0, 3000), 'meta' => $meta ? wp_json_encode($meta, JSON_UNESCAPED_UNICODE) : null, 'created_at' => Ssb_Db::now()]);
    }
    private static function bump(string $conv, array $set, array $inc = []): void {
        global $wpdb; $t = Ssb_Db::t('conv'); $sql = "UPDATE $t SET updated_at=%s"; $a = [Ssb_Db::now()];
        foreach ($set as $k => $v) { $sql .= ", `$k`=%s"; $a[] = is_array($v) ? wp_json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v; }
        foreach ($inc as $k => $v) { $sql .= ", `$k`=`$k`+%d"; $a[] = (int) $v; }
        $sql .= ' WHERE id=%s'; $a[] = $conv;
        $wpdb->query($wpdb->prepare($sql, ...$a));
    }
    private static function unanswered(string $q): void {
        global $wpdb; $t = Ssb_Db::t('unanswered'); $norm = mb_substr(Ssb_Text::fold($q), 0, 190); if ($norm === '') return;
        $wpdb->query($wpdb->prepare("INSERT INTO $t (norm,question,hits,status,last_seen) VALUES (%s,%s,1,'open',%s) ON DUPLICATE KEY UPDATE hits=hits+1, last_seen=VALUES(last_seen), status=IF(status='ignored','ignored','open')", $norm, mb_substr(Ssb_Text::mask($q), 0, 500), Ssb_Db::now()));
    }

    /* ------------------------------------------------------------------ public entry */
    public static function greeting(): array {
        $s = Ssb_Db::settings();
        return ['text' => Ssb_Brain::phrase('sys.greeting', 'שלום, במה אפשר לעזור?', ['bot' => $s['bot_name']]), 'chips' => self::CHIPS, 'type' => 'greeting'];
    }
    /** @return array{conv:string, text:string, chips:array, type:string, ticket?:string} */
    public static function handle(string $conv_id, string $raw, string $ip_hash): array {
        $s = Ssb_Db::settings();
        $conv = self::valid_id($conv_id) ? self::load($conv_id) : null;
        if (!$conv) { $id = self::create($ip_hash); $conv = self::load($id); }
        $id = $conv['id']; $st = $conv['state'];
        $text = mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($raw)) ?? ''), 0, (int) $s['max_msg_len']);
        self::log($id, 'user', $text);
        self::bump($id, [], ['msgs' => 1]);
        $out = self::route($id, $st, $text, $ip_hash);
        $out += ['chips' => [], 'type' => 'answer', 'meta' => []];
        $inc = [];
        if ($out['type'] === 'answer') $inc['answered'] = 1;
        if ($out['type'] === 'fallback') $inc['unanswered'] = 1;
        $set = ['state' => $st];
        if (!empty($out['meta']['flag'])) $set['flagged'] = 1;
        if ($out['type'] === 'ticket') $set['escalated'] = 1;
        self::bump($id, $set, $inc);
        self::log($id, 'bot', !empty($out['meta']['redact']) ? '[פרטי הזמנה אומתו והוצגו ללקוח]' : $out['text'], ['type' => $out['type']]);
        return ['conv' => $id, 'text' => $out['text'], 'chips' => $out['chips'], 'type' => $out['type']] + (isset($out['ticket']) ? ['ticket' => $out['ticket']] : []);
    }

    /* ------------------------------------------------------------------ routing */
    private static function say(string $text, string $type = 'answer', array $chips = [], array $extra = []): array { return ['text' => $text, 'type' => $type, 'chips' => $chips] + $extra; }
    private static function p(string $slug, string $def, array $v = []): string { return Ssb_Brain::phrase($slug, $def, $v); }

    private static function route(string $id, array &$st, string $raw, string $ip): array {
        $l = Ssb_Text::light($raw);
        if ($raw === '') {
            if (!empty($st['mode'])) return self::say('לא הצלחתי לקרוא את ההודעה. אפשר לכתוב אותה שוב במילים?', 'ask');
            return self::say('לא הצלחתי לקרוא את ההודעה. אפשר לכתוב אותה במילים?', 'ask');
        }
        // Prompt-injection / role-play attempts: the text is never obeyed, never stored as a field value.
        if (Ssb_Text::is_injection($raw)) return self::say(self::p('sys.injection', 'אני עונה רק על שאלות בנושא החנות.'), 'injection', self::CHIPS, ['meta' => ['flag' => 1]]);
        $mode = $st['mode'] ?? null;
        if ($mode && Ssb_Text::cancel_flow($l)) { $st['mode'] = null; unset($st['esc'], $st['order'], $st['tc']); return self::say('בסדר, ביטלתי. במה עוד אפשר לעזור?', 'answer', self::CHIPS); }
        if ($mode === 'esc') return self::esc_step($id, $st, $raw, $l, $ip);
        if ($mode === 'order') return self::order_step($st, $raw, $l, $ip);
        if ($mode === 'tc') return self::tc_step($st, $raw);
        if (!empty($st['offer'])) {
            if (Ssb_Text::yes($l)) { $st['offer'] = false; $why = (($st['offer_why'] ?? '') === 'unanswered') ? 'unanswered' : 'customer_request'; $st['offer_why'] = ''; return self::esc_start($st, $why, (string) ($st['last_q'] ?? ''), false, ''); }
            if (Ssb_Text::no($l)) { $st['offer'] = false; $st['offer_why'] = ''; return self::say('בסדר. אם צריך עוד משהו, אני כאן.', 'answer', self::CHIPS); }
            $st['offer'] = false;
        }
        if (Ssb_Text::thanks($l)) return self::say(self::p('sys.thanks', 'בשמחה.'), 'answer');
        if (Ssb_Text::greeting_only($l)) { $g = self::greeting(); return self::say($g['text'], 'answer', $g['chips']); }
        if (Ssb_Text::legal($l)) return self::esc_start($st, 'legal', $raw, true, self::p('sys.angry', 'מצטערים על החוויה.'), 'urgent');
        if ($sens = Ssb_Text::sensitive($l)) return self::esc_start($st, $sens, $raw, false, self::p('sys.angry', 'מצטערים על החוויה.'));
        if (Ssb_Text::anger($raw) >= 2) return self::esc_start($st, 'angry', $raw, true, self::p('sys.angry', 'מצטערים על החוויה.'));
        if (Ssb_Text::ticket_status_intent($l)) { $st['mode'] = 'tc'; $st['tc'] = []; return self::tc_step($st, $raw); }
        if (Ssb_Text::wants_human($l)) return self::esc_start($st, 'customer_request', '', false, '');
        if ($rule = Ssb_Brain::escalation_hit($raw)) {
            $m = Ssb_Brain::meta($rule);
            return self::esc_start($st, 'rule_' . ($rule['slug'] ?: $rule['id']), $raw, false, (string) $rule['body'], $m['priority'] ?? null);
        }
        if ($hit = Ssb_Brain::forbidden_hit($raw)) {
            $st['offer'] = true; $st['last_q'] = $raw;
            return self::say($hit['body'] . ' ' . self::p('sys.escalate_offer', 'רוצה שאפתח פנייה לנציג?'), 'answer', ['כן', 'לא']);
        }
        if (Ssb_Text::order_intent($l) && !Ssb_Text::size_intent($l) || self::has_order_pair($raw)) {
            $st['mode'] = 'order'; $st['order'] = $st['order'] ?? [];
            return self::order_step($st, $raw, $l, $ip);
        }
        if (Ssb_Text::size_intent($l)) { $a = self::size_answer($st, $raw); if ($a) return $a; }
        if ($a = self::product_answer($raw, $l)) return $a;
        $found = Ssb_Brain::search($raw, ['policy', 'ops', 'faq', 'product'], 1);
        if ($found) {
            $ans = (string) $found[0]['row']['body'];
            if (Ssb_Brain::answer_is_clean($ans)) { $st['miss'] = 0; return self::say($ans, 'answer', ['לא עזר, נציג']); }
        }
        if (Ssb_Text::item_intent($l)) {
            self::unanswered($raw); // demand signal for the manager, shown under "שאלות שלא נענו"
            return self::say(self::p('sys.no_match', 'ככל הנראה הפריט המבוקש אזל כרגע מהמלאי וצפוי להתחדש בקרוב.'), 'fallback', ['לא עזר, נציג']);
        }
        return self::fallback($st, $raw);
    }

    private static function fallback(array &$st, string $raw): array {
        $st['miss'] = (int) ($st['miss'] ?? 0) + 1; $st['last_q'] = $raw;
        self::unanswered($raw);
        if ($st['miss'] >= 3) { $st['miss'] = 0; return self::esc_start($st, 'repeated_miss', $raw, false, 'נראה שלא הצלחתי לעזור.'); }
        $st['offer'] = true; $st['offer_why'] = 'unanswered';
        return self::say(self::p('sys.fallback', self::FALLBACK_DEFAULT), 'fallback', ['כן', 'לא']); // the owner's wording already ends with the offer question
    }

    /* ------------------------------------------------------------------ products / sizes (live data) */
    private static function size_in(string $raw): ?string {
        if (preg_match('/(?<![A-Za-z0-9])(XXXL|XXL|XL|XS|XXS|S|M|L)(?![A-Za-z0-9])/i', $raw, $m)) return strtoupper($m[1]);
        if (preg_match('/(?:מידה|מידת)\s*(\d{2})\b/u', $raw, $m)) return $m[1];
        return null;
    }
    private static function stock_line(array $p): string {
        $st = Ssb_Live::stock($p); $in = []; foreach ($st['sizes'] as $s => $q) if ($q > 0) $in[] = $s;
        return $in ? 'במלאי: ' . implode(', ', $in) : 'אזל מהמלאי';
    }
    private static function product_answer(string $raw, string $l): ?array {
        $m = Ssb_Live::match_products($raw);
        $prods = $m['by_color'];
        if (!$prods) {
            $b = $m['brand'];
            if ($m['by_name'] && $m['colors']) {
                $have = []; foreach ($m['by_name'] as $p) foreach (Ssb_Live::color_names($p) as $c) $have[$c] = 1;
                $cw = implode(', ', array_values($m['colors']));
                $msg = self::p('sys.no_color', 'לא מצאתי מוצר תואם בצבע {color}.', ['color' => $cw]);
                if ($have) $msg .= ' הצבעים הקיימים בדגמים דומים: ' . implode(', ', array_slice(array_keys($have), 0, 8)) . '.';
                return self::say($msg, 'answer', ['לא עזר, נציג']);
            }
            if ($m['colors'] && !$b && Ssb_Text::product_intent($l)) {
                return self::say(self::p('sys.no_color', 'לא מצאתי מוצר תואם בצבע {color}.', ['color' => implode(', ', array_values($m['colors']))]), 'answer', ['לא עזר, נציג']);
            }
            if (($b || $m['colors']) && Ssb_Text::product_intent($l)) {
                if ($b && !$m['colors'] && preg_match('/צבעים|באיזה צבע/u', $l)) {
                    $have = []; foreach (Ssb_Live::products() as $p) if (($p['brand'] ?? '') === $b) foreach (Ssb_Live::color_names($p) as $c) $have[$c] = 1;
                    if ($have) return self::say("הצבעים הקיימים כרגע במותג $b: " . implode(', ', array_slice(array_keys($have), 0, 12)) . '. אפשר לשאול על צבע ספציפי, ואבדוק זמינות.', 'answer', ['לא עזר, נציג']);
                }
                if ($b) {
                    $n = count(array_filter(Ssb_Live::products(), fn($p) => ($p['brand'] ?? '') === $b));
                    return self::say(self::p('sys.no_match', 'ככל הנראה הפריט המבוקש אזל כרגע מהמלאי וצפוי להתחדש בקרוב.') . " במותג $b יש כרגע $n מוצרים אחרים. אפשר לנסות סוג בגד אחר, או לבקש נציג.", 'answer', ['לא עזר, נציג']);
                }
            }
            return null;
        }
        $size = self::size_in($raw); $lines = [];
        foreach (array_slice($prods, 0, 3) as $p) {
            $name = trim(($p['brand'] ?? '') . ' ' . ($p['he'] ?? ''));
            $colors = Ssb_Live::colors($p);
            $line = $name . ' - ' . (isset($p['price']) ? number_format((float) $p['price'], 0) . ' ש"ח' : 'מחיר לא הוזן');
            if ($colors) $line .= ' | צבע: ' . implode(', ', $colors);
            if ($size) { $sz = Ssb_Live::stock($p)['sizes']; $line .= isset($sz[$size]) ? ($sz[$size] > 0 ? " | מידה $size במלאי" : " | מידה $size אזלה") : " | מידה $size לא קיימת במוצר"; }
            else $line .= ' | ' . self::stock_line($p);
            $lines[] = $line;
        }
        $more = count($prods) - count($lines);
        return self::say("מצאתי באתר:\n" . implode("\n", $lines) . ($more > 0 ? "\nועוד $more מוצרים דומים." : ''), 'answer', ['לא עזר, נציג']);
    }
    private static function size_answer(array &$st, string $raw): ?array {
        $m = Ssb_Live::match_products($raw);
        $prods = $m['by_color'];
        if ($prods) {
            $g = Ssb_Live::size_guide_text($prods[0]);
            if ($g !== '') return self::say(trim(($prods[0]['brand'] ?? '') . ' ' . ($prods[0]['he'] ?? '')) . ":\n" . $g, 'answer', ['לא עזר, נציג']);
        }
        $brand = $m['brand'];
        $q = $raw . ' ' . ($brand ?? '');
        $found = Ssb_Brain::search($q, ['product'], 1, 3.0);
        if ($found && Ssb_Brain::answer_is_clean((string) $found[0]['row']['body'])) return self::say((string) $found[0]['row']['body'], 'answer', ['לא עזר, נציג']);
        if ($brand || $prods) {
            $st['offer'] = true; $st['last_q'] = $raw;
            $for = $brand ? "של $brand" : 'של המוצר';
            return self::say(self::p('sys.size_missing', 'טבלת המידות {for} עדיין לא הוזנה אצלי, ולכן אני לא מנחש מידה.', ['for' => $for]) . ' ' . self::p('sys.escalate_offer', 'רוצה שאפתח פנייה לנציג?'), 'answer', ['כן', 'לא']);
        }
        return null;
    }

    /* ------------------------------------------------------------------ order lookup (verified) */
    private static function has_order_pair(string $raw): bool { return Ssb_Text::emails($raw) && Ssb_Text::order_numbers($raw) && mb_strlen(preg_replace('/\D/', '', $raw)) >= 3 && Ssb_Text::order_intent(Ssb_Text::light($raw)); }
    private static function order_step(array &$st, string $raw, string $l, string $ip): array {
        $o = $st['order'] ?? []; $max = (int) Ssb_Db::settings()['verify_max_attempts'];
        if (!empty($st['order_lock'])) { $st['mode'] = null; return self::esc_start($st, 'order_verify_locked', '', false, self::p('sys.order_locked', 'מטעמי אבטחה אפשר להמשיך רק דרך נציג.')); }
        $emails = Ssb_Text::emails($raw); $phones = Ssb_Text::phones($raw); $nums = Ssb_Text::order_numbers($raw);
        if ($emails) $o['email'] = $emails[0];
        if ($phones) $o['phone'] = $phones[0];
        if ($nums && empty($o['number'])) $o['number'] = $nums[0];
        elseif ($nums && !empty($o['number']) && ($emails || $phones)) $o['number'] = $o['number'];
        $st['order'] = $o;
        if (empty($o['number'])) return self::say(self::p('sys.order_ask', 'צריך מספר הזמנה וכתובת מייל או טלפון.') . "\nמה מספר ההזמנה?", 'ask');
        if (empty($o['email']) && empty($o['phone'])) return self::say('ומה כתובת המייל או הטלפון שנמסרו בהזמנה?', 'ask');
        // verification attempt (global per-IP throttle so the lookup cannot be used to probe order numbers)
        if (!Ssb_Rest::hit('o:' . $ip, 10, 3600)) { $st['mode'] = null; return self::say(self::p('sys.rate_limited', 'נסו שוב בעוד כמה דקות.'), 'rate'); }
        $st['attempts'] = (int) ($st['attempts'] ?? 0) + 1;
        $r = Ssb_Live::order_lookup((string) $o['number'], (string) ($o['email'] ?? ''), (string) ($o['phone'] ?? ''));
        $st['order'] = [];
        if (!$r['ok']) {
            if ($st['attempts'] >= $max) { $st['order_lock'] = true; $st['mode'] = null; return self::esc_start($st, 'order_verify_locked', '', false, self::p('sys.order_locked', 'מטעמי אבטחה אפשר להמשיך רק דרך נציג.')); }
            return self::say(self::p('sys.order_fail', 'לא ניתן לאמת את ההזמנה.'), 'ask', ['דברו עם נציג'], ['meta' => []]);
        }
        $st['mode'] = null; $st['attempts'] = 0; $ord = $r['order'];
        $stage = Ssb_Brain::by_slug('status.' . $ord['status']);
        $why = ($stage && (int) $stage['active'] === 1) ? $stage['body'] : 'לא הוגדר עדיין הסבר לשלב הזה. אפשר לבקש נציג ואפתח פנייה.';
        $lines = ["הזמנה {$ord['id']} מתאריך {$ord['date']}", 'שלב: ' . ($stage['title'] ?? $ord['status']) . ' - ' . $why];
        if ($ord['items']) $lines[] = 'פריטים: ' . implode('; ', $ord['items']);
        $lines[] = 'סכום: ' . $ord['total'];
        if ($ord['shipping']) $lines[] = 'משלוח: ' . $ord['shipping'];
        return self::say(implode("\n", $lines), 'answer', ['דברו עם נציג'], ['meta' => ['redact' => 1]]);
    }

    /* ------------------------------------------------------------------ ticket status for the customer */
    private static function tc_step(array &$st, string $raw): array {
        $tc = $st['tc'] ?? [];
        if ($n = Ssb_Text::ticket_number($raw)) $tc['number'] = $n;
        $e = Ssb_Text::emails($raw); $p = Ssb_Text::phones($raw);
        if ($e) $tc['contact'] = $e[0]; elseif ($p) $tc['contact'] = $p[0];
        $st['tc'] = $tc;
        if (empty($tc['number'])) return self::say('מה מספר הפנייה? (למשל T-1001)', 'ask');
        if (empty($tc['contact'])) return self::say('ומה המייל או הטלפון שבהם נפתחה הפנייה?', 'ask');
        $st['mode'] = null; $st['tc'] = [];
        $v = Ssb_Tickets::customer_view((string) $tc['number'], (string) $tc['contact']);
        if (!$v) return self::say(self::p('sys.unknown_ticket', 'לא מצאתי פנייה מתאימה.'), 'answer', ['דברו עם נציג']);
        $out = "פנייה {$v['number']}: {$v['status']}";
        foreach ($v['replies'] as $r) $out .= "\nתגובת נציג: " . $r['text'];
        if (!$v['replies']) $out .= "\nעדיין אין תגובה מנציג. נחזור אליך בהקדם.";
        return self::say($out, 'answer', [], ['meta' => ['redact' => 1]]);
    }

    /* ------------------------------------------------------------------ escalation */
    private static function esc_start(array &$st, string $reason, string $trigger, bool $angry, string $lead, ?string $priority = null): array {
        $trigger = mb_substr(trim($trigger), 0, 500);
        $e = Ssb_Text::emails($trigger); $p = Ssb_Text::phones($trigger);
        $st['mode'] = 'esc';
        $st['esc'] = ['step' => 'name', 'lite' => in_array($reason, ['unanswered', 'repeated_miss'], true), 'reason' => $reason, 'angry' => $angry, 'priority' => $priority, 'summary' => $trigger, 'contact' => $e ? $e[0] : ($p ? $p[0] : ''), 'name' => '', 'order' => null, 'tries' => 0];
        return self::say(trim($lead . ' ' . self::p('sys.ask_name', 'איך קוראים לך?')), 'ask');
    }
    private static function esc_step(string $conv, array &$st, string $raw, string $l, string $ip): array {
        $e = $st['esc'];
        switch ($e['step']) {
            case 'name':
                if (mb_strlen($raw) < 2 || strpos($raw, '@') !== false || preg_match('/\d{3,}/u', $raw)) return self::say(self::p('sys.ask_name', 'איך קוראים לך?'), 'ask');
                $e['name'] = mb_substr(trim(preg_replace('/^(קוראים לי|שמי|אני)\s+/u', '', $raw) ?? $raw), 0, 80);
                $e['step'] = !empty($e['lite']) ? 'contact' : ($e['contact'] !== '' ? 'order' : 'contact');
                break;
            case 'contact':
                if (!empty($e['lite'])) { // name + phone + reason only (the rep gets them on WhatsApp)
                    if (!preg_match('/(?:\+?972[\s\-]?|0)(?:5\d|[2-4]|[7-9])[\s\-]?\d{3}[\s\-]?\d{4}/', $raw, $pm)) {
                        $e['tries']++;
                        if ($e['tries'] >= 3) { $st['mode'] = null; $st['esc'] = []; return self::say('לא הצלחתי לקבל מספר טלפון תקין, ולכן לא נפתחה פנייה. אפשר לפנות במייל או בוואטסאפ מהעמוד "יצירת קשר".', 'answer', self::CHIPS); }
                        $st['esc'] = $e; return self::say('המספר לא נראה תקין. ' . self::p('sys.ask_phone', 'מה מספר הטלפון שלך, כדי שנציג יחזור אליך?'), 'ask');
                    }
                    $e['contact'] = trim($pm[0]); $e['order'] = ''; if (trim((string) $e['summary']) === '') $e['summary'] = 'שאלה ללא מענה'; $e['step'] = 'create'; break;
                }
                if (Ssb_Tickets::contact_key($raw) === '') {
                    $e['tries']++;
                    if ($e['tries'] >= 3) { $st['mode'] = null; $st['esc'] = []; return self::say('לא הצלחתי לקבל פרטי קשר תקינים, ולכן לא נפתחה פנייה. אפשר לפנות במייל או בוואטסאפ מהעמוד "יצירת קשר".', 'answer', self::CHIPS); }
                    $st['esc'] = $e; return self::say('הפרטים לא נראים תקינים. ' . self::p('sys.ask_contact', 'מה הטלפון או המייל?'), 'ask');
                }
                $e['contact'] = $raw; $e['step'] = 'order'; break;
            case 'order':
                $e['order'] = ($l === '' || Ssb_Text::no($l) || preg_match('/^(אין|אין לי|לא יודע|לא יודעת|אין מספר)/u', $l)) ? '' : (Ssb_Text::order_numbers($raw)[0] ?? mb_substr($raw, 0, 60));
                if ($e['order'] !== '' && Ssb_Text::order_numbers($raw)) {
                    $rest = trim((string) preg_replace('/(הזמנה|מספר|מס\')?\s*#?' . preg_quote($e['order'], '/') . '/u', '', $raw), " \t,.-:;");
                    if (mb_strlen($rest) >= 8) $e['summary'] = trim($e['summary'] . ' ' . mb_substr($rest, 0, 500));
                }
                $e['step'] = mb_strlen((string) $e['summary']) >= 12 ? 'create' : 'summary'; break;
            case 'summary':
                $e['summary'] = trim($e['summary'] . ' ' . mb_substr($raw, 0, 800)); $e['step'] = 'create'; break;
        }
        $st['esc'] = $e;
        $prompts = ['contact' => ['sys.ask_contact', 'מה הטלפון או המייל שבהם נחזור אליך?'], 'order' => ['sys.ask_order', 'יש מספר הזמנה? אם אין, כתוב/י "אין".'], 'summary' => ['sys.ask_summary', 'במשפט או שניים, מה הנושא?']];
        if (!empty($e['lite'])) $prompts['contact'] = ['sys.ask_phone', 'מה מספר הטלפון שלך, כדי שנציג יחזור אליך?'];
        if ($e['step'] !== 'create') return self::say(self::p($prompts[$e['step']][0], $prompts[$e['step']][1]), 'ask');
        // create ticket (rate-limited per visitor)
        if (!Ssb_Rest::hit('t:' . $ip, (int) Ssb_Db::settings()['rate_tickets_per_hour'], 3600)) { $st['mode'] = null; $st['esc'] = []; return self::say(self::p('sys.rate_limited', 'נסו שוב מאוחר יותר.'), 'rate'); }
        $transcript = self::transcript($conv);
        $t = Ssb_Tickets::create(['conv_id' => $conv, 'name' => $e['name'], 'contact' => $e['contact'], 'order_number' => (string) $e['order'], 'summary' => $e['summary'], 'reason' => $e['reason'], 'angry' => $e['angry'], 'priority' => $e['priority'], 'transcript' => $transcript]);
        $st['mode'] = null; $st['esc'] = [];
        if (is_wp_error($t)) return self::say('לא הצלחתי לפתוח פנייה כרגע. אפשר לנסות שוב בעוד רגע.', 'answer');
        $msg = !empty($e['lite'])
            ? self::p('sys.handoff_done', self::HANDOFF_DEFAULT, ['ticket' => $t['number']])
            : self::p('sys.ticket_created', 'פתחתי פנייה {ticket}.', ['ticket' => $t['number'], 'eta' => Ssb_Tickets::eta_text($t['priority'])]);
        if (!Ssb_Tickets::is_open()) $msg .= ' ' . self::p('sys.closed_hours', '');
        return self::say(trim($msg), 'ticket', [], ['ticket' => $t['number']]);
    }
    private static function transcript(string $conv): string {
        global $wpdb; $rows = $wpdb->get_results($wpdb->prepare('SELECT role,text,created_at FROM ' . Ssb_Db::t('msg') . ' WHERE conv_id=%s ORDER BY id DESC LIMIT 30', $conv), ARRAY_A) ?: [];
        $out = []; foreach (array_reverse($rows) as $r) $out[] = ($r['role'] === 'user' ? 'לקוח' : 'בוט') . ': ' . $r['text'];
        return implode("\n", $out);
    }
}
