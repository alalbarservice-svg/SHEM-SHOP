<?php
if (!defined('ABSPATH')) exit;
/**
 * The "brain": every fact the bot may say. Admin-editable, versioned (each save = new version row with date + editor),
 * effective immediately (no cache, every answer reads the table). Entries are never deleted from here, only deactivated.
 */
final class Ssb_Brain {
    const SEED_VERSION = 2;

    public static function sections(): array {
        return [
            'policy'     => 'מדיניות (משלוחים, החזרות, החלפות, אחריות, פרטיות, תשלום)',
            'ops'        => 'תפעול (שעות, זמני אספקה, חברות שילוח, נקודות איסוף)',
            'status'     => 'שלבי הזמנה (הסבר ללקוח ופעולות מותרות)',
            'product'    => 'ידע על מוצרים (טבלאות מידות, גזרה, צבעים, מותגים)',
            'faq'        => 'שאלות ותשובות מוכנות',
            'tone'       => 'טון דיבור',
            'forbidden'  => 'נושאים וביטויים אסורים',
            'escalation' => 'כללי הסלמה לנציג',
            'system'     => 'משפטי מערכת (פתיחה, אישורים, הודעות שגיאה)',
        ];
    }

    public static function boot(): void { add_action('plugins_loaded', [self::class, 'maybe_seed'], 7); }

    /* ------------------------------------------------------------------ read */
    public static function row(int $id): ?array {
        global $wpdb; $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Ssb_Db::t('brain') . ' WHERE id=%d', $id), ARRAY_A);
        return $r ?: null;
    }
    public static function by_slug(string $slug): ?array {
        global $wpdb; $slug = sanitize_key($slug); $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Ssb_Db::t('brain') . ' WHERE slug=%s', $slug), ARRAY_A);
        return $r ?: null;
    }
    /** @return array[] */
    public static function all(?string $section = null, bool $active_only = false): array {
        global $wpdb; $t = Ssb_Db::t('brain'); $w = []; $a = [];
        if ($section) { $w[] = 'section=%s'; $a[] = $section; }
        if ($active_only) $w[] = 'active=1';
        $sql = "SELECT * FROM $t" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY section, title';
        return $wpdb->get_results($a ? $wpdb->prepare($sql, ...$a) : $sql, ARRAY_A) ?: [];
    }
    /** System phrase by slug, {placeholders} replaced. Falls back to $default if missing/inactive. */
    public static function phrase(string $slug, string $default = '', array $vars = []): string {
        $r = self::by_slug($slug);
        $t = ($r && (int) $r['active'] === 1 && trim((string) $r['body']) !== '') ? (string) $r['body'] : $default;
        foreach ($vars as $k => $v) $t = str_replace('{' . $k . '}', (string) $v, $t);
        return $t;
    }
    public static function meta(array $row): array { $m = json_decode((string) ($row['meta'] ?? ''), true); return is_array($m) ? $m : []; }
    public static function versions(int $id): array {
        global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Ssb_Db::t('brain_versions') . ' WHERE brain_id=%d ORDER BY version DESC, id DESC', $id), ARRAY_A) ?: [];
    }

    /* ------------------------------------------------------------------ write (versioned) */
    /** @return array|WP_Error saved row */
    public static function save(array $d, string $editor, string $action = '', string $source = 'manual') {
        global $wpdb; $t = Ssb_Db::t('brain'); $now = Ssb_Db::now();
        $section = (string) ($d['section'] ?? '');
        if (!isset(self::sections()[$section])) return new WP_Error('bad_section', 'קטגוריה לא חוקית');
        $title = mb_substr(trim(wp_strip_all_tags((string) ($d['title'] ?? ''))), 0, 255);
        $body  = mb_substr(trim(sanitize_textarea_field((string) ($d['body'] ?? ''))), 0, 8000);
        if ($title === '' || $body === '') return new WP_Error('empty', 'חובה למלא כותרת ותוכן');
        $kw   = mb_substr(trim(sanitize_textarea_field((string) ($d['keywords'] ?? ''))), 0, 1000);
        $meta = $d['meta'] ?? '';
        if (is_array($meta)) $meta = wp_json_encode($meta, JSON_UNESCAPED_UNICODE);
        $meta = mb_substr((string) $meta, 0, 2000);
        $active = array_key_exists('active', $d) ? (!empty($d['active']) ? 1 : 0) : 1;
        $slug = isset($d['slug']) && $d['slug'] !== '' ? sanitize_key((string) $d['slug']) : null;
        $editor = mb_substr(wp_strip_all_tags($editor), 0, 100);
        $id = (int) ($d['id'] ?? 0);
        if ($id) {
            $cur = self::row($id); if (!$cur) return new WP_Error('nf', 'הרשומה לא נמצאה');
            $ver = (int) $cur['version'] + 1;
            $ok = $wpdb->update($t, ['section' => $section, 'title' => $title, 'keywords' => $kw, 'body' => $body, 'meta' => $meta, 'active' => $active, 'version' => $ver, 'updated_by' => $editor, 'updated_at' => $now], ['id' => $id]);
            if ($ok === false) return new WP_Error('db', 'שגיאת מסד נתונים');
            $act = $action ?: 'update';
        } else {
            if ($slug && self::by_slug($slug)) return new WP_Error('dup', 'מזהה כבר קיים');
            $ver = 1;
            $ok = $wpdb->insert($t, ['section' => $section, 'slug' => $slug, 'title' => $title, 'keywords' => $kw, 'body' => $body, 'meta' => $meta, 'active' => $active, 'version' => 1, 'source' => $source, 'updated_by' => $editor, 'created_at' => $now, 'updated_at' => $now]);
            if (!$ok) return new WP_Error('db', 'שגיאת מסד נתונים');
            $id = (int) $wpdb->insert_id; $act = $action ?: 'create';
        }
        $wpdb->insert(Ssb_Db::t('brain_versions'), ['brain_id' => $id, 'version' => $ver, 'action' => $act, 'section' => $section, 'title' => $title, 'keywords' => $kw, 'body' => $body, 'meta' => $meta, 'active' => $active, 'editor' => $editor, 'created_at' => $now]);
        return self::row($id);
    }
    public static function set_active(int $id, bool $on, string $editor) {
        $r = self::row($id); if (!$r) return new WP_Error('nf', 'הרשומה לא נמצאה');
        $r['active'] = $on ? 1 : 0;
        return self::save($r, $editor, $on ? 'activate' : 'deactivate');
    }
    public static function restore(int $id, int $version, string $editor) {
        global $wpdb;
        $v = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Ssb_Db::t('brain_versions') . ' WHERE brain_id=%d AND version=%d ORDER BY id DESC LIMIT 1', $id, $version), ARRAY_A);
        if (!$v) return new WP_Error('nf', 'גרסה לא נמצאה');
        $cur = self::row($id); if (!$cur) return new WP_Error('nf', 'הרשומה לא נמצאה');
        return self::save(['id' => $id, 'section' => $v['section'], 'title' => $v['title'], 'keywords' => $v['keywords'], 'body' => $v['body'], 'meta' => $v['meta'], 'active' => $v['active']], $editor, 'restore');
    }

    /* ------------------------------------------------------------------ retrieval */
    /**
     * Rank active entries against the customer's text (data only). Returns best-first [ ['row'=>..., 'score'=>n], ... ].
     * @param string[]|null $sections
     */
    public static function search(string $text, ?array $sections = null, int $limit = 3, float $min = 4.0): array {
        $q = Ssb_Text::stems($text); if (!$q) return [];
        $qf = ' ' . Ssb_Text::fold($text) . ' ';
        $out = [];
        foreach (self::all(null, true) as $r) {
            if ($sections && !in_array($r['section'], $sections, true)) continue;
            if (in_array($r['section'], ['tone', 'forbidden', 'escalation', 'system', 'status'], true) && !$sections) continue;
            $head = Ssb_Text::stems($r['title'] . ' ' . $r['keywords']);
            $body = Ssb_Text::stems($r['body']);
            $hh = count(array_intersect($q, $head)); $bb = count(array_intersect($q, $body));
            $score = 3 * $hh + $bb;
            foreach (preg_split('/[\n,;]+/u', (string) $r['keywords']) ?: [] as $k) {
                $kf = Ssb_Text::fold(trim($k));
                if ($kf !== '' && mb_strlen($kf) >= 3 && mb_strpos($qf, ' ' . $kf . ' ') !== false) { $score += 5; break; }
            }
            if ($hh === 0 && $bb < 2 && $score < 5) continue;
            if ($score >= $min) $out[] = ['row' => $r, 'score' => (float) $score];
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($out, 0, $limit);
    }
    /** Does the text contain a forbidden phrase / topic? Returns the matching entry or null. */
    public static function forbidden_hit(string $text): ?array {
        $tf = ' ' . Ssb_Text::fold($text) . ' ';
        foreach (self::all('forbidden', true) as $r) {
            foreach (preg_split('/[\n,;]+/u', (string) $r['keywords']) ?: [] as $k) {
                $kf = Ssb_Text::fold(trim($k));
                if ($kf !== '' && mb_strpos($tf, ' ' . $kf . ' ') !== false) return $r;
            }
        }
        return null;
    }
    /** Admin escalation rules: first rule whose keyword appears in the text. */
    public static function escalation_hit(string $text): ?array {
        $tf = ' ' . Ssb_Text::fold($text) . ' ';
        foreach (self::all('escalation', true) as $r) {
            foreach (preg_split('/[\n,;]+/u', (string) $r['keywords']) ?: [] as $k) {
                $kf = Ssb_Text::fold(trim($k));
                if ($kf !== '' && mb_strpos($tf, ' ' . $kf . ' ') !== false) return $r;
            }
        }
        return null;
    }
    /** Apply the forbidden-phrase list to a draft answer (defence in depth). */
    public static function answer_is_clean(string $answer): bool { return self::forbidden_hit($answer) === null; }

    /* ------------------------------------------------------------------ seed */
    public static function maybe_seed(): void {
        if ((int) get_option('ssb_seed_version', 0) >= self::SEED_VERSION) return;
        if (!get_option('ssb_db_version')) return;
        foreach (self::defaults() as $e) {
            if (self::by_slug($e['slug'])) continue;
            self::save($e, 'system (seed)', 'create', 'seed');
        }
        update_option('ssb_seed_version', self::SEED_VERSION, false);
    }
    private static function defaults(): array {
        $s = Ssb_Db::settings();
        $hours = 'שעות מענה של צוות השירות: ראשון עד חמישי 09:00-18:00, שישי 09:00-13:00, שבת סגור.';
        $d = [];
        $p = fn($slug, $title, $kw, $body, $meta = '') => $d[] = ['slug' => $slug, 'section' => 'policy', 'title' => $title, 'keywords' => $kw, 'body' => $body, 'meta' => $meta];
        $d = [];
        $add = function (string $section, string $slug, string $title, string $kw, string $body, $meta = '') use (&$d) {
            $d[] = ['slug' => $slug, 'section' => $section, 'title' => $title, 'keywords' => $kw, 'body' => $body, 'meta' => $meta];
        };
        // ---- policies (text taken from the store's own policy pages)
        $add('policy', 'policy.returns', 'החזרות וביטול עסקה', "החזרה\nלהחזיר\nהחזר\nביטול עסקה\nביטול הזמנה\nזיכוי\nלהחזיר מוצר\nלא מתאים",
            "אפשר לבטל עסקה תוך 14 ימים מהמועד המאוחר מבין קבלת המוצר או קבלת מסמך העסקה. דמי ביטול: עד 5% ממחיר העסקה או 100 ש\"ח, הנמוך מביניהם. ההחזר הכספי מתבצע תוך 14 ימים ממועד קבלת ההודעה על הביטול. לזכאים (אנשים עם מוגבלות, אזרחים ותיקים, עולים חדשים) חלות זכויות מורחבות לפי החוק. לפתיחת בקשת החזרה אפשר לבקש נציג ואפתח פנייה.");
        $add('policy', 'policy.exchanges', 'החלפות', "החלפה\nלהחליף\nמידה אחרת\nלהחליף מידה\nלהחליף צבע",
            "אפשר לבקש החלפה תוך 14 ימים. לבקשת החלפה אפשר לבקש נציג ואפתח פנייה עם פרטי ההזמנה.");
        $add('policy', 'policy.shipping', 'משלוחים ועלויות', "משלוח\nמשלוחים\nדמי משלוח\nעלות משלוח\nמשלוח מהיר\nמשלוח רגיל\nכמה עולה משלוח\nמתי יגיע",
            "משלוח רגיל: עד 7 ימי עסקים, 49 ש\"ח. משלוח מהיר: עד יום עסקים אחד, 199 ש\"ח.");
        $add('policy', 'policy.payment', 'תשלום', "תשלום\nלשלם\nכרטיס אשראי\nאמצעי תשלום\nתשלומים\nביט\nסליקה",
            "פרטי אמצעי התשלום עדיין לא הוזנו אצלי. לשאלה בנושא תשלום אפשר לבקש נציג ואפתח פנייה.");
        $add('policy', 'policy.privacy', 'פרטיות', "פרטיות\nמידע אישי\nמדיניות פרטיות\nהפרטים שלי\nמחיקת מידע",
            "פרטים שנמסרים לצורך הזמנה ושירות משמשים רק למטרות אלה. מדיניות הפרטיות המלאה מופיעה באתר, בעמוד מדיניות הפרטיות. לבקשות בנושא מידע אישי אפשר לבקש נציג ואפתח פנייה.");
        $add('policy', 'policy.accessibility', 'נגישות', "נגישות\nתקן נגישות\nמוגבלות\nקורא מסך\nהצהרת נגישות",
            "האתר שואף לעמוד בתקן הישראלי לנגישות (ת\"י 5568, רמה AA). הצהרת הנגישות המלאה מופיעה באתר. לדיווח על בעיית נגישות אפשר לבקש נציג ואפתח פנייה.");
        $add('policy', 'policy.warranty', 'אחריות', "אחריות\nתקלה במוצר\nפגם\nפגם ייצור\nמוצר פגום",
            "פרטי האחריות לא הוזנו עדיין. לשאלה בנושא אחריות או פגם במוצר אפשר לבקש נציג ואפתח פנייה.");
        // ---- ops
        $add('ops', 'ops.hours', 'שעות מענה', "שעות פעילות\nשעות מענה\nפתוחים\nמתי אתם זמינים\nשבת\nשישי\nמתי עונים",
            $hours . ' הבוט זמין סביב השעון; פניות שנפתחות מחוץ לשעות נענות ביום העבודה הבא.');
        $add('ops', 'ops.contact', 'יצירת קשר', "יצירת קשר\nטלפון\nוואטסאפ\nמייל\nדוא\"ל\nליצור קשר\nמספר טלפון\nכתובת מייל",
            'אפשר לפנות במייל your.shem88@gmail.com או לבקש נציג כאן ואפתח פנייה.');
        // ---- order stages (one per WooCommerce status)
        $act = 'לשאלה או בקשה בנוגע להזמנה אפשר לבקש נציג ואפתח פנייה.';
        $add('status', 'status.wc-pending', 'ממתינה לתשלום', 'wc-pending', 'ההזמנה התקבלה וממתינה לתשלום. ' . $act, ['wc_status' => 'wc-pending']);
        $add('status', 'status.wc-processing', 'בטיפול', 'wc-processing', 'התשלום התקבל וההזמנה בטיפול. ' . $act, ['wc_status' => 'wc-processing']);
        $add('status', 'status.wc-on-hold', 'בהמתנה', 'wc-on-hold', 'ההזמנה בהמתנה לבדיקה. ' . $act, ['wc_status' => 'wc-on-hold']);
        $add('status', 'status.wc-completed', 'הושלמה', 'wc-completed', 'ההזמנה הושלמה. ' . $act, ['wc_status' => 'wc-completed']);
        $add('status', 'status.wc-cancelled', 'בוטלה', 'wc-cancelled', 'ההזמנה בוטלה. ' . $act, ['wc_status' => 'wc-cancelled']);
        $add('status', 'status.wc-refunded', 'זוכתה', 'wc-refunded', 'בוצע זיכוי להזמנה. ' . $act, ['wc_status' => 'wc-refunded']);
        $add('status', 'status.wc-failed', 'נכשלה', 'wc-failed', 'התשלום או ההזמנה לא הושלמו. ' . $act, ['wc_status' => 'wc-failed']);
        // ---- tone / forbidden / escalation
        $add('tone', 'tone.style', 'סגנון דיבור', 'סגנון', 'עברית פשוטה וקצרה, בגובה העיניים, בלשון ניטרלית מבחינת מגדר (למשל: "אפשר", "ניתן"). בלי הבטחות שלא מופיעות במוח.');
        $add('forbidden', 'forbidden.legal', 'התחייבויות משפטיות וכספיות', "אני מתחייב\nמתחייבים\nמובטח לך\nמבטיחים\nפיצוי מובטח\nאחריות משפטית",
            'הבוט אינו מתחייב, אינו מבטיח פיצוי ואינו נותן ייעוץ משפטי. במקרים כאלה מציעים העברה לנציג.');
        $add('escalation', 'escalation.refund', 'בקשת החזר כספי או זיכוי', "החזר כספי\nזיכוי\nלהחזיר את הכסף", 'בקשת החזר או זיכוי מועברת תמיד לנציג.', ['priority' => 'high']);
        // ---- system phrases
        $sys = fn(string $slug, string $title, string $body) => $add('system', $slug, $title, '', $body);
        $sys('sys.greeting', 'פתיחה', 'היי, אני {bot}. אפשר לשאול אותי על מוצרים ומידות, משלוחים והחזרות, או לבדוק הזמנה. במה אפשר לעזור?');
        $sys('sys.fallback', 'אין תשובה', 'אין לי תשובה מאומתת לזה, ואני לא מנחש. אפשר לפתוח פנייה ונציג יחזור אליך.');
        $sys('sys.escalate_offer', 'הצעת העברה לנציג', 'רוצה שאפתח פנייה לנציג? כתוב/י "כן" ואבקש כמה פרטים.');
        $sys('sys.ask_name', 'בקשת שם', 'איך קוראים לך?');
        $sys('sys.ask_contact', 'בקשת פרטי קשר', 'מה מספר הטלפון או כתובת המייל שבהם נחזור אליך?');
        $sys('sys.ask_order', 'בקשת מספר הזמנה', 'יש מספר הזמנה שקשור לפנייה? אם אין, כתוב/י "אין".');
        $sys('sys.ask_summary', 'בקשת תיאור', 'במשפט או שניים, מה הנושא?');
        $sys('sys.ticket_created', 'אישור פתיחת פנייה', 'פתחתי פנייה מספר {ticket}. זמן מענה צפוי: {eta}. אפשר לבדוק את הסטטוס כאן בכל עת עם מספר הפנייה.');
        $sys('sys.order_ask', 'בדיקת הזמנה', 'כדי לבדוק הזמנה צריך מספר הזמנה וגם כתובת מייל או טלפון שנמסרו בהזמנה.');
        $sys('sys.order_fail', 'אימות נכשל', 'לא הצלחתי לאמת את ההזמנה לפי הפרטים האלה. לא ניתן למסור פרטי הזמנה בלי אימות. אפשר לנסות שוב, או לפתוח פנייה לנציג.');
        $sys('sys.order_locked', 'יותר מדי ניסיונות', 'בוצעו מספר ניסיונות אימות שלא הצליחו. מטעמי אבטחה אפשר להמשיך רק דרך נציג.');
        $sys('sys.angry', 'לקוח כועס', 'מצטערים על החוויה. אני פותח פנייה בעדיפות גבוהה כדי שנציג יחזור אליך.');
        $sys('sys.injection', 'ניסיון מניפולציה', 'אני עוזר שירות של החנות ועונה רק על שאלות בנושא החנות. במה אפשר לעזור?');
        $sys('sys.thanks', 'סיום', 'בשמחה. אם צריך עוד משהו, אני כאן.');
        $sys('sys.rate_limited', 'יותר מדי הודעות', 'נשלחו הרבה הודעות בזמן קצר. אפשר לנסות שוב בעוד כמה דקות.');
        $sys('sys.too_long', 'הודעה ארוכה', 'ההודעה ארוכה מדי. אפשר לקצר ולנסות שוב.');
        $sys('sys.unknown_ticket', 'פנייה לא נמצאה', 'לא מצאתי פנייה שמתאימה למספר ולפרטי הקשר האלה.');
        $sys('sys.closed_hours', 'מחוץ לשעות', 'שעת המענה כרגע סגורה. הפנייה תיענה ביום העבודה הבא.');
        $sys('sys.size_missing', 'טבלת מידות חסרה', 'טבלת המידות {for} עדיין לא הוזנה אצלי, ולכן אני לא מנחש מידה.');
        $sys('sys.no_color', 'צבע לא קיים', 'לא מצאתי מוצר תואם בצבע {color}.');
        return $d;
    }
}
