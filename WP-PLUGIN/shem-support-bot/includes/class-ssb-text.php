<?php
if (!defined('ABSPATH')) exit;
/**
 * Hebrew text helpers: normalisation, light stemming, intent / sentiment detection, PII masking.
 * Customer text is only ever DATA here (tokens to match against the brain) - it is never evaluated as an instruction.
 */
final class Ssb_Text {
    private static ?array $stop = null;

    /** NFC, no niqqud, no geresh/quotes (lawyer abbreviations expanded first). Final letters kept. */
    public static function light(string $s): string {
        if (class_exists('Normalizer')) { $n = Normalizer::normalize($s, Normalizer::FORM_C); if (is_string($n)) $s = $n; }
        $s = preg_replace('/[\x{0591}-\x{05C7}]/u', '', $s) ?? $s;
        $s = preg_replace('/עו\s*["\x{05F4}\x{201C}\x{201D}\']?\s*ד\b/u', 'עורך דין', $s) ?? $s;
        $s = str_replace(["\u{05F3}", "\u{05F4}", "'", '"', '`', "\u{2019}", "\u{201C}", "\u{201D}"], '', $s);
        $s = mb_strtolower($s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return trim($s);
    }
    /** light() + final letters folded + punctuation removed (for token matching only). */
    public static function fold(string $s): string {
        $s = strtr(self::light($s), ['ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ']);
        $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }
    private static function stop(): array {
        if (self::$stop === null) {
            $w = 'את של על אני אתה הוא היא אנחנו הם עם אל מה מי איך כמה האם יש אין לי לנו זה זאת גם או אבל כי לא כן רק אפשר רוצה צריך צריכה בבקשה תודה היי הי שלום אפשר יכול יכולה מעוניין מעוניינת שלי שלכם לכם אצלכם אצל כל עוד אם מתי איפה למה כאן פה הזה הזאת האלה ואני אז ממש מאוד קצת בעצם רגע טוב יותר פחות הנה אותו אותה שלו שלה';
            self::$stop = [];
            foreach (explode(' ', $w) as $x) self::$stop[self::fold($x)] = 1;
        }
        return self::$stop;
    }
    /** Canonical stem of one folded word: leading particles and one common suffix removed. */
    public static function stem(string $w): string {
        $x = $w;
        foreach (['ימ', 'ות', 'ה', 'ת', 'י'] as $suf) { // suffix first, so plural forms reach the same stem as the singular
            if (mb_strlen($x) > 3 && mb_substr($x, -mb_strlen($suf)) === $suf) { $x = mb_substr($x, 0, -mb_strlen($suf)); break; }
        }
        for ($i = 0; $i < 4; $i++) { // prefixes greedily to a 3-letter floor: משלוח / המשלוח / והמשלוח reach the same stem
            if (mb_strlen($x) >= 4 && mb_strpos('והבכלמש', mb_substr($x, 0, 1)) !== false) $x = mb_substr($x, 1); else break;
        }
        return $x;
    }
    /** @return string[] distinct canonical stems of meaningful words */
    public static function stems(string $text): array {
        $st = self::stop(); $out = [];
        foreach (explode(' ', self::fold($text)) as $w) {
            if ($w === '' || mb_strlen($w) < 2 || isset($st[$w])) continue;
            $out[self::stem($w)] = 1;
        }
        return array_keys($out);
    }

    /* ------------------------------------------------------------------ intent detectors (on light() text) */
    private static function any(string $l, array $patterns): bool {
        foreach ($patterns as $p) if (preg_match('/' . $p . '/u', $l)) return true;
        return false;
    }
    public static function is_injection(string $raw): bool {
        $l = self::light($raw);
        return self::any($l, [
            'ignore (all |the )?(previous|prior|above)', 'disregard', 'system prompt', 'you are now', 'act as', 'reveal .*(prompt|key|secret|password|instructions)', 'jailbreak', '\bdan\b', 'developer mode',
            'התעלם', 'תתעלם', 'להתעלם מ', 'הוראות קודמות', 'ההוראות שלך', 'הנחיות שלך', 'פרומפט', 'תתנהג כ', 'אתה עכשיו', 'מעכשיו אתה', 'שכח את', 'תשכח את', 'חשוף את', 'תחשוף', 'תראה לי את ההוראות', 'מפתח api', 'סיסמת מנהל', 'מצב מפתח',
        ]);
    }
    public static function wants_human(string $l): bool {
        return self::any($l, ['נציג', 'בן אדם', 'אדם אמיתי', 'מישהו אמיתי', 'לדבר עם (מישהו|מנהל|אדם)', 'מנהל', 'אחראי', 'תחזרו אלי', 'תחזרו אליי', 'תתקשרו', 'פנייה', 'לפתוח פניה', 'לפתוח פנייה', '(רוצה|צריך|צריכה|אשמח|מבקש|מבקשת) (ל)?יצור קשר', 'הסלמה']);
    }
    public static function legal(string $l): bool {
        return self::any($l, ['עורך דין', 'בית משפט', 'תביעה', 'תובע', 'הרשות להגנת הצרכן', 'הגנת הצרכן', 'משטרה', 'להתלונן', 'תלונה רשמית', 'צו ', 'lawsuit', 'lawyer']);
    }
    /** @return string|null 'damaged'|'payment'|'refund_dispute' */
    public static function sensitive(string $l): ?string {
        if (self::any($l, ['פגום', 'שבור', 'קרוע', 'הגיע (שבור|קרוע|פגום|מלוכלך)', 'נזק', 'פגם', 'לא תקין', 'מוצר פגום'])) return 'damaged';
        if (self::any($l, ['חויבתי פעמיים', 'חיוב כפול', 'חיוב לא', 'חייבו אותי', 'כסף ירד', 'לא התקבל תשלום', 'התשלום נכשל', 'תקלה בתשלום', 'שילמתי ו', 'שילמתי והזמנה', 'תקלת תשלום', 'סליקה'])) return 'payment';
        if (self::any($l, ['לא קיבלתי (את )?(ה)?(החזר|זיכוי)', 'עדיין לא זוכיתי', 'מסרבים להחזיר', 'מחלוקת', 'לא מחזירים', 'החזר כספי שלא', 'דורש (החזר|זיכוי)', 'רוצה (את )?הכסף'])) return 'refund_dispute';
        return null;
    }
    /** 0..n anger score. >=2 means angry. */
    public static function anger(string $raw): int {
        $l = self::light($raw); $n = 0;
        $words = ['זעם', 'עצבני', 'מעצבן', 'מעצבנים', 'גרוע', 'נוראי', 'נורא ו', 'שערורייה', 'בושה', 'חרפה', 'נמאס', 'לא מקובל', 'מטומטם', 'מגעיל', 'רמאים', 'נוכלים', 'עוקץ', 'עבדתם עלי', 'פשע', 'זבל', 'שירות גרוע', 'אין לי סבלנות', 'מתעצבן', 'כועס', 'כועסת', 'מאוכזב', 'מאוכזבת', 'לעולם לא אקנה', 'לא אקנה יותר', 'חוצפה', 'מרושעים', 'קטסטרופה', 'אסון', 'זוועתי', 'זוועה', 'נורא', 'גנבים', 'גנבתם', 'גונבים', 'מחפיר', 'מבייש', 'מביש', 'עלוב', 'דוחה', 'בלגן', 'לא יאומן', 'הזוי', 'מזעזע', 'שמעתם אותי', 'אף אחד לא עונה', 'מתעלמים'];
        foreach ($words as $w) if (mb_strpos($l, $w) !== false) $n++;
        foreach (['זוועתי', 'זוועה', 'גנבים', 'גנבתם', 'גונבים', 'רמאים', 'נוכלים', 'מחפיר', 'מבייש', 'מביש', 'שערורייה', 'חרפה', 'מזעזע', 'זבל', 'שירות גרוע', 'מטומטם'] as $w) if (mb_strpos($l, $w) !== false) { $n++; break; } // one clearly abusive word is enough
        if (preg_match('/!{2,}/', $raw)) $n++;
        elseif ($n >= 1 && strpos($raw, '!') !== false) $n++;
        if (preg_match('/\?{3,}/', $raw)) $n++;
        if (preg_match('/[\x{05D0}-\x{05EA}]{3,}\s*[!?]{2,}/u', $raw) && $n === 0) $n++;
        return $n;
    }
    public static function greeting_only(string $l): bool {
        return (bool) preg_match('/^(היי+|הי|שלום|בוקר טוב|ערב טוב|צהריים טובים|אהלן|הלו|hello|hi|hey|מה נשמע|מה קורה)[\s!.]*$/u', $l);
    }
    public static function thanks(string $l): bool {
        return (bool) preg_match('/^(תודה|תודה רבה|תודה לך|אחלה תודה|מעולה תודה|סבבה תודה|thanks?|thank you|ביי|להתראות|יום טוב|זהו|זה הכל|זה הכול|לא תודה)[\s!.]*$/u', $l);
    }
    public static function cancel_flow(string $l): bool {
        return (bool) preg_match('/^(ביטול|בטל|עזוב|עזבי|לא משנה|תעזוב|עצור|חזרה|תפריט)[\s!.]*$/u', $l);
    }
    public static function yes(string $l): bool { return (bool) preg_match('/^(כן|כן בטח|בטח|אשר|מאשר|מאשרת|שלח|שלחו|שלחי|אוקיי|אוקי|בסדר|סבבה|יאללה|ok|yes)[\s!.]*$/u', $l); }
    public static function no(string $l): bool { return (bool) preg_match('/^(לא|לא תודה|ביטול|בטל|עזוב|no)[\s!.]*$/u', $l); }
    public static function order_intent(string $l): bool {
        return self::any($l, ['הזמנה', 'הזמנתי', 'הזמנות', 'סטטוס', 'איפה החבילה', 'איפה המשלוח', 'מעקב', 'מתי יגיע', 'מתי תגיע', 'מתי אקבל', 'לא הגיע', 'עוד לא הגיע', 'order']);
    }
    public static function ticket_status_intent(string $l): bool {
        return (bool) (preg_match('/\bt-?\d{3,}\b/u', $l) || self::any($l, ['מה קורה עם הפנייה', 'סטטוס פנייה', 'סטטוס הפנייה', 'מה עם הפנייה', 'בדיקת פנייה', 'מספר פנייה', 'מספר הפנייה', 'פנייה קיימת', 'פניה קיימת']));
    }
    public static function product_intent(string $l): bool {
        return self::any($l, ['במלאי', 'זמין', 'זמינה', 'זמינים', 'יש לכם', 'האם יש', 'יש בצבע', 'בצבע', 'מוצר', 'פריט', 'יש במידה', 'נשאר', 'נותר', 'להזמין', 'מחיר של', 'כמה עולה', 'כמה עולים', 'צבעים', 'באיזה צבע', 'איזה צבעים', 'קולקציה', 'חולצ', 'מכנס', 'ג.ינס', 'סריג', 'חליפ', 'מעיל', 'ז.קט', 'נעל', 'חגור', 'עניבה', 'כובע', 'בגד']);
    }
    /** Narrow "asks about a specific item" test (used for the sold-out answer). Generic phrases like "יש לכם" are excluded. */
    public static function item_intent(string $l): bool {
        return self::any($l, ['במלאי', 'בצבע', 'מוצר', 'פריט', 'דגם', 'קולקציה', 'חולצ', 'מכנס', 'ג.ינס', 'סריג', 'חליפ', 'מעיל', 'ז.קט', 'נעל', 'חגור', 'עניבה', 'כובע', 'בגד', 'מחיר של', 'כמה עולה', 'כמה עולים']);
    }
    public static function size_intent(string $l): bool {
        return self::any($l, ['טבלת מידות', 'מדריך מידות', 'איזה מידה', 'איזו מידה', 'מה המידה', 'איזה גודל', 'מתאימ', 'גזרה', 'גזרת', 'רחב', '(?<![א-ת])צר ', 'קטן עלי', 'גדול עלי', 'ללבוש', 'מידות', 'מידה']);
    }

    /* ------------------------------------------------------------------ extractors (on RAW text) */
    public static function emails(string $raw): array { preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $raw, $m); return array_values(array_unique(array_map('strtolower', $m[0]))); }
    public static function phones(string $raw): array {
        $out = [];
        if (preg_match_all('/(?:\+?972[\s\-]?|0)(?:5\d|[2-4]|[7-9])[\s\-]?\d{3}[\s\-]?\d{4}/', $raw, $m)) foreach ($m[0] as $p) $out[] = self::phone_key($p);
        return array_values(array_unique(array_filter($out)));
    }
    /** last 9 digits of an Israeli number (comparison key). */
    public static function phone_key(string $p): string { $d = preg_replace('/\D+/', '', $p) ?? ''; return strlen($d) >= 9 ? substr($d, -9) : ''; }
    public static function order_numbers(string $raw): array {
        $clean = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', ' ', $raw) ?? $raw;
        $clean = preg_replace('/(?:\+?972[\s\-]?|0)(?:5\d|[2-4]|[7-9])[\s\-]?\d{3}[\s\-]?\d{4}/', ' ', $clean) ?? $clean;
        $out = [];
        if (preg_match_all('/(?<![\d])#?(\d{3,10})(?![\d])/', $clean, $m)) $out = $m[1];
        return array_values(array_unique($out));
    }
    public static function ticket_number(string $raw): ?string {
        if (preg_match('/\bT[\s\-]?(\d{3,})\b/i', $raw, $m)) return 'T-' . $m[1];
        return null;
    }

    /* ------------------------------------------------------------------ privacy */
    /** Mask personal data before anything is written to the conversation log. */
    public static function mask(string $s): string {
        $s = preg_replace_callback('/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', fn($m) => $m[1] . '***@' . $m[2], $s) ?? $s;
        $s = preg_replace('/\b(?:\d[ \-]?){13,19}\b/', '[מספר כרטיס הוסר]', $s) ?? $s;
        $s = preg_replace_callback('/(?:\+?972[\s\-]?|0)(?:5\d|[2-4]|[7-9])[\s\-]?\d{3}[\s\-]?(\d{4})/', fn($m) => '***-***' . substr($m[1], -2), $s) ?? $s;
        return $s;
    }
    public static function clip(string $s, int $n): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($s)) ?? ''), 0, $n); }
}
