<?php
if (!defined('ABSPATH')) exit;
/**
 * Initial brain content (owner request 29.10.2026) and the store scan.
 *
 * Pack: policy / payment / FAQ / system phrases. Wording of returns, cancellation, shipping and payment pages is copied
 * from the store's own pages (SHEM/src/components/PolicyPages.jsx); payment terms and the "item is out of stock" wording
 * are the owner's own statements. Applying is idempotent and never overwrites an entry a person edited in the admin.
 *
 * Scan: Q&A built from the products customers can see (Ssb_Live::products()) - brands, item types, colours, sizes in stock.
 * Regenerated daily and on demand; entries a person edited are left alone, vanished brands are deactivated, nothing is deleted.
 */
final class Ssb_Content {
    const VERSION = 4;
    const PACK_EDITOR = 'בקשת בעלים 29.10.2026';
    const SCAN_EDITOR = 'סריקת חנות אוטומטית';
    const SEED_EDITOR = 'system (seed)';

    public static function boot(): void {
        add_action('plugins_loaded', [self::class, 'maybe_apply'], 8);
        add_action('ssb_daily_scan', function () { self::apply(); self::scan_apply(); });
        add_action('init', function () { if (!get_option('ssb_scan_at')) { $r = self::scan_apply(); }  if (!wp_next_scheduled('ssb_daily_scan')) wp_schedule_event(time() + 3600, 'daily', 'ssb_daily_scan'); });
    }
    public static function maybe_apply(): void {
        if (!get_option('ssb_db_version') || !function_exists('wc_get_product')) return;
        $h = md5(wp_json_encode([self::ops(), class_exists('Scc_Docs') ? Scc_Docs::setting('business', []) : []]));
        if ((int) get_option('ssb_content_version', 0) >= self::VERSION && get_option('ssb_ops_hash') === $h) return;
        update_option('ssb_ops_hash', $h, false);
        $r = self::apply();
        $r['scan'] = self::scan_apply();
        update_option('ssb_content_report', $r, false);
        update_option('ssb_content_version', self::VERSION, false);
    }

    /* ------------------------------------------------------------------ pack */
    /** Shipping settings from the store itself (operations setting), site defaults when not set. */
    public static function ops(): array {
        $o = class_exists('Scc_Docs') ? (array) Scc_Docs::setting('operations', []) : [];
        $n = fn($k, $d) => (isset($o[$k]) && is_numeric($o[$k])) ? 0 + $o[$k] : $d;
        $days = fn($v) => $v == 1 ? 'יום עסקים אחד' : $v . ' ימי עסקים';
        $sd = $n('standardDays', 7); $ed = $n('expressDays', 1); $sp = $n('standardPrice', 49); $ep = $n('expressPrice', 199);
        return ['sp' => $sp, 'ep' => $ep, 'sd' => $sd, 'ed' => $ed,
            'std' => 'משלוח רגיל: עד ' . $days($sd) . ', ' . $sp . ' ש"ח', 'exp' => 'משלוח מהיר: עד ' . $days($ed) . ', ' . $ep . ' ש"ח',
            'areas' => is_scalar($o['serviceAreas'] ?? null) ? trim((string) $o['serviceAreas']) : ''];
    }
    private static function e(string $section, string $slug, string $title, string $kw, string $body, $meta = ''): array {
        return ['slug' => $slug, 'section' => $section, 'title' => $title, 'keywords' => $kw, 'body' => $body, 'meta' => $meta];
    }
    public static function pack(): array {
        $no_match = 'ככל הנראה הפריט המבוקש אזל כרגע מהמלאי וצפוי להתחדש בקרוב.';
        $nl = "\n";
        $d = []; $ops = self::ops();
        // ---- system phrases (owner wording)
        $d[] = self::e('system', 'sys.fallback', 'אין תשובה (פתיחת הצעת העברה)', '', 'מתנצל אך אין ביכולתי לענות תשובה על שאלתך, לכן אשמח להעביר את פנייתך לשירות הלקוחות עם נציג אינושי. האם תרצה שהעביר אותך?');
        $d[] = self::e('system', 'sys.handoff_done', 'אישור העברה לנציג', '', "אין בעיה הנושא בטיפול, שלחתי את פנייתך לנציג השירות שלנו והוא יצור איתך קשר בהקדם להמשך תהליך. תודה על סבלנותך והמשך יום נפלא בינתיים \u{1F60A}");
        $d[] = self::e('system', 'sys.ask_phone', 'בקשת טלפון (העברה לנציג)', '', 'כדי שהנציג יוכל לחזור אליך, מה מספר הטלפון שלך?');
        $d[] = self::e('system', 'sys.no_match', 'פריט או מותג לא נמצאו (אזל מהמלאי)', '', $no_match);
        $d[] = self::e('system', 'sys.no_color', 'צבע לא קיים (אזל מהמלאי)', '', 'לא מצאתי כרגע מוצר בצבע {color}. ' . $no_match);
        // ---- policy: returns and shipping (copied from the store's returns / payments / terms pages)
        $d[] = self::e('policy', 'policy.returns_shipping', 'מדיניות החזרות ומשלוחים',
            "מדיניות החזרות ומשלוחים\nמדיניות החזרות\nמדיניות משלוחים\nמדיניות החזרה\nתנאי החזרה\nתנאי משלוח\nהחזרות ומשלוחים",
            'ביטול והחזרה: אפשר להודיע על ביטול עסקה מיום ביצוע העסקה ועד 14 ימים מקבלת המוצר או מסמך פרטי העסקה, לפי המאוחר. אין חובה לנמק. אפשר להגיש בקשה באתר, בעמוד "בקשת ביטול, החזרה או החלפה", או במייל השירות.' . $nl . $nl
            . 'החלפה: החנות מציעה החלפות בתוך 14 ימים מקבלת המשלוח, עם איסוף שליח מהבית בתיאום עם השירות. להחלפה מרצון נבקש שלא נעשה שימוש בפריט ושהתוויות נשמרו.' . $nl . $nl
            . 'החזר כספי: ההשבה וביטול החיוב מתבצעים תוך 14 ימים מקבלת הודעת הביטול. דמי ביטול: עד 5% ממחיר העסקה או 100 ש"ח, הנמוך מביניהם. במקרה של פגם, אי התאמה או אי אספקה במועד, במקרים המזכים בכך לפי דין, לא נגבים דמי ביטול.' . $nl . $nl
            . 'משלוח עד הבית: ' . $ops['std'] . '. ' . $ops['exp'] . '. ימי עסקים אינם כוללים שבתות, חגים וימי שבתון. הסכום הכולל והמשלוח מוצגים בנפרד לפני אישור ההזמנה.' . $nl . $nl
            . 'זכויות מורחבות: אדם עם מוגבלות, אזרח ותיק או עולה חדש עשויים להיות זכאים לתקופת ביטול של ארבעה חודשים בעסקת מכר מרחוק שכללה שיחה עם העוסק, בהתאם לתנאי החוק.');
        // ---- policy: payment (owner statement)
        $d[] = self::e('policy', 'policy.payment', 'תנאי תשלום',
            "תשלום\nלשלם\nאיך משלמים\nאפשר לשלם\nאמצעי תשלום\nאמצעי תשלום באתר\nכרטיס אשראי\nאשראי\nפייפאל\npaypal\nתשלומים\nריבית\nללא ריבית\nבלי ריבית\nביט\nמזומן\nהעברה בנקאית",
            'התשלום באתר מתבצע בכרטיס אשראי או ב-PayPal בלבד.' . $nl . 'אפשר לשלם בתשלומים: עד 3 תשלומים ללא ריבית, ועד 12 תשלומים בתוספת ריבית. מספר התשלומים והעלות המדויקת מוצגים בקופה לפני אישור ההזמנה.' . $nl . 'מטעמי אבטחה, אין לשלוח פרטי כרטיס אשראי בצ\'אט, במייל או בוואטסאפ.');

        $biz = class_exists('Scc_Docs') ? (array) Scc_Docs::setting('business', []) : [];
        $bz = fn($k) => trim((string) ($biz[$k] ?? ''));
        $d[] = self::e('policy', 'policy.terms', 'תנאי שימוש ורכישה',
            "תנאי שימוש\nתנאי רכישה\nתקנון\nתנאי האתר\nתנאי שימוש ורכישה\nמי העוסק\nפרטי העוסק\nשימוש הוגן",
            'עודכן: 13.10.2026.' . $nl . 'החנות והעוסק: SHEM היא חנות אופנה לגברים. שם העסק: ' . ($bz('legalName') ?: 'טרם הושלם') . '. כתובת: ' . ($bz('address') ?: 'טרם הושלמה') . '. ח.פ: ' . ($bz('registrationNumber') ?: 'טרם הושלם') . '. מייל: ' . ($bz('email') ?: 'your.shem88@gmail.com') . '.' . $nl . $nl
            . 'מידע על מוצרים ומחירים: המחירים מוצגים בשקלים ויכללו את כל המסים החלים. דמי משלוח מפורטים בנפרד לפני אישור העסקה. התמונות נועדו להציג את המוצר, וייתכנו הבדלי גוון בין מסכים. פריטי הדגמה מסומנים ואינם הצעה למכירת מוצר מאומת. שימוש בשמות מותגים אינו מעיד כשלעצמו על קשר רשמי או זכיינות.' . $nl . $nl
            . 'הזמנה ותשלום: לפני התחייבות יוצגו המוצר, המידה, המחיר, ההנחה, המשלוח והסכום הכולל. אישור הזמנה ומסמך פרטי עסקה יישלחו לאחר השלמת תהליך ההזמנה. פרטי כרטיס אשראי אינם נאספים באתר.' . $nl . $nl
            . 'מלאי ומבצעים: מלאי עשוי להשתנות עד לאישור ההזמנה. במקרה של אי אפשרות לספק מוצר שהוזמן, נפנה ללקוח לפתרון ולהשבה בהתאם לדין. קופון חל לפי תוקפו, סכום המינימום והכללים המוצגים בעת מימושו. מחיר קודם יוצג רק כשקיים בסיס תקף להצגתו.' . $nl . $nl
            . 'שימוש הוגן: יש למסור פרטי קשר נכונים ולהשתמש באמצעי תשלום מורשה. אין לבצע שימוש בלתי חוקי, להפר זכויות בתכנים, לפגוע באבטחה או לאסוף מידע אישי של אחרים. אין בתנאים אלו פטור מאחריות שהדין אינו מאפשר לשלול.' . $nl . $nl
            . 'זכויות צרכניות: תנאי ההחזרה והביטול הם חלק מהתנאים. בכל סתירה תגבר הוראת דין מחייבת. שינוי תנאים יחול מכאן ולהבא ולא יגרע מזכויות בעסקה קיימת. הדין הישראלי חל, וסמכות השיפוט נקבעת לפי הדין.' . $nl . 'שירות ופניות: your.shem88@gmail.com.');
        $d[] = self::e('policy', 'policy.privacy', 'מדיניות פרטיות',
            "פרטיות\nמדיניות פרטיות\nמידע אישי\nאילו פרטים נשמרים\nמחיקת מידע\nדיוור\nעוגיות\nעיון במידע",
            'עודכן: 07.10.2026.' . $nl . 'מה נאסף ולמה: במועדון נשמרים האימייל, מצב הדיוור ומועד ההסכמה. בפניית שירות נשמרים שם, אימייל, מספר הזמנה, הודעת הלקוח ומצב הטיפול. בכרטיסי שירות עשויים להישמר טלפון והערות רלוונטיות. המידע משמש לטיפול בפניות, ניהול החנות ושליחת עדכונים רק כשניתנה הסכמה. אין איסוף פרטי כרטיס אשראי.' . $nl . $nl
            . 'בחירה ומסירת מידע: מסירת המידע תלויה ברצונך ואינה חובה חוקית. בלי פרטי הקשר הנדרשים לא נוכל לטפל בפנייה או לרשום אותך לעדכונים. אין צורך לשלוח מסמכי זיהוי או מידע רגיש.' . $nl . $nl
            . 'ספקים ואחסון: המידע נמסר רק למורשים הזקוקים לו ולספקי אירוח ושירות לצורך תפעול האתר והפנייה, או כשנדרש בדין. האירוח עשוי לכלול עיבוד מידע מחוץ לישראל.' . $nl . $nl
            . 'העדפות ומעקב: אחסון מקומי בדפדפן שומר סל, מועדפים ושפה. לא הוטמעו באתר כלי פרסום או פיקסלים שיווקיים.' . $nl . $nl
            . 'עיון, תיקון והפסקת דיוור: אפשר לפנות למייל השירות לבקשת עיון במידע ותיקונו בהתאם לחוק, ולבקש הסרה מדיוור. בקשת מחיקה תיבחן לפי הדין וחובות השמירה החלות.' . $nl . $nl
            . 'אבטחה: ממשק הניהול מוגבל לחשבונות מורשים, בחיבור מוצפן ובבקרת גישה; אין אפשרות להבטיח חסינות מוחלטת. אין למסור סיסמאות או פרטי תשלום לצוות השירות.' . $nl . 'פניות פרטיות: your.shem88@gmail.com.');
        $d[] = self::e('policy', 'policy.accessibility', 'הצהרת נגישות',
            "נגישות\nהצהרת נגישות\nנגישות האתר\nתקן נגישות\nקורא מסך\nמוגבלות",
            'SHEM פועלת לשפר את נגישות האתר בהתאם לתקן הישראלי ת"י 5568 חלק 1 וברמת AA. ההצהרה מתארת התאמות שבוצעו ואינה אישור תאימות מטעם בודק מוסמך.' . $nl
            . 'התאמות: ניווט מקלדת וסימון מיקוד, קישור דילוג לתוכן, כותרות ותוויות לשדות, תיאורים חלופיים לתמונות, סגירת חלונות ב-Escape, עצירת מצגת התמונות, כיבוד העדפת הפחתת תנועה והתאמה למסכים קטנים.' . $nl
            . 'מצב הבדיקה: בוצעו בדיקות קוד, מקלדת ותצוגה. עדיין נדרשת בדיקה מלאה עם קורא מסך ובדיקת נגישות מקצועית לפני פתיחת המכירה.' . $nl
            . 'לפנייה בנושא נגישות: במייל your.shem88@gmail.com או בוואטסאפ, עם כתובת העמוד, הפעולה והקושי. ננסה לספק מידע או שירות בדרך חלופית. אין כרגע מידע מאומת על נקודת קבלת קהל פיזית.');
        $d[] = self::e('policy', 'policy.payments_shipping', 'תשלום ומשלוחים',
            "תשלום ומשלוחים\nעמוד תשלום\nעמוד משלוחים\nמדיניות משלוחים\nאזורי שירות\nאזורי משלוח\nימי עסקים",
            'תשלום: בכרטיס אשראי או ב-PayPal בלבד. עד 3 תשלומים ללא ריבית ועד 12 תשלומים בתוספת ריבית. מספר התשלומים וכל עלות נוספת יוצגו בקופה לפני התחייבות. אין לשלוח פרטי אשראי במייל, בוואטסאפ או בהערות שירות.' . $nl
            . 'משלוח עד הבית: ' . $ops['std'] . '. ' . $ops['exp'] . '. ימי עסקים אינם כוללים שבתות, חגים וימי שבתון. זמינות המסלול ואזורי השירות נמסרים לפני אישור הזמנה.' . ($ops['areas'] !== '' ? ' אזורי השירות: ' . $ops['areas'] . '.' : '') . ' במקרה של עיכוב נמסור עדכון, מבלי לגרוע מזכות ביטול לפי דין.' . $nl
            . 'הסכום הכולל והמשלוח מוצגים בנפרד. הנחת קופון מחושבת על פריטים זכאים ואינה מפחיתה את דמי המשלוח, אלא אם נאמר אחרת.');
        // ---- FAQ (wording from the store's own pages / owner statements)
        $f = function (string $slug, string $q, string $kw, string $a) use (&$d) { $d[] = self::e('faq', 'faq.' . $slug, $q, $kw, $a); };
        $f('how_cancel', 'איך מבטלים עסקה או מחזירים מוצר?', "איך מבטלים\nאיך מחזירים\nלבטל הזמנה\nלבטל עסקה\nלהחזיר מוצר\nבקשת ביטול\nאיך מחזירים מוצר",
            'אפשר להגיש בקשת ביטול, החזרה או החלפה בעמוד הייעודי באתר, או לשלוח הודעה למייל השירות your.shem88@gmail.com. אין חובה לנמק, ושם ופרטי הזמנה יסייעו לזהות את העסקה. מועד קבלת ההודעה הוא הקובע, ואין צורך להמתין לאיסוף כדי למסור אותה.');
        $f('cancel_period', 'תוך כמה זמן אפשר לבטל או להחזיר?', "תוך כמה זמן אפשר להחזיר\nכמה זמן יש להחזיר\nעד מתי אפשר להחזיר\n14 יום\nתקופת ביטול\nתקופת החזרה",
            'אפשר להודיע על ביטול מיום ביצוע העסקה ועד 14 ימים מקבלת המוצר או מסמך פרטי העסקה, לפי המאוחר. רכישה במבצע אינה שוללת כשלעצמה זכות ביטול.');
        $f('refund_time', 'מתי מקבלים החזר כספי?', "מתי מקבלים החזר\nמתי יחזור הכסף\nהחזר כספי\nזמן החזר\nכמה זמן לוקח החזר\nזיכוי לכרטיס",
            'ההשבה וביטול החיוב מתבצעים תוך 14 ימים מקבלת הודעת הביטול. ההחזרה מתואמת עם השירות.');
        $f('cancel_fee', 'האם יש דמי ביטול?', "דמי ביטול\nעמלת ביטול\nכמה עולה ביטול\nעלות ביטול",
            'דמי הביטול לא יעלו על 5% ממחיר העסקה או 100 ש"ח, לפי הנמוך. במקרה של פגם, אי התאמה או אי אספקה במועד, במקרים המזכים בכך לפי דין, לא נגבים דמי ביטול.');
        $f('exchange', 'איך מחליפים מידה או מוצר?', "להחליף מידה\nלהחליף מוצר\nהחלפה\nאיך מחליפים\nמידה אחרת\nלהחליף צבע\nהחלפת מידה",
            'החנות מציעה החלפות בתוך 14 ימים מקבלת המשלוח, עם איסוף שליח מהבית בתיאום עם השירות. להחלפה מרצון נבקש שלא נעשה שימוש בפריט ושהתוויות נשמרו. אם יש עלות איסוף בהחזרה מרצון, היא תימסר לפני אישור השירות. לבקשת החלפה אפשר לבקש נציג ואפתח פנייה.');
        $f('defect', 'קיבלתי מוצר פגום או לא תואם', "מוצר פגום\nפגם במוצר\nלא תואם להזמנה\nלא הגיע בזמן\nאי אספקה\nקיבלתי מוצר שגוי\nמוצר לא תואם",
            'במקרה של פגם, אי התאמה או אי אספקה במועד, הלקוח מעמיד את המוצר לרשות החנות במקום שבו נמסר, והחנות מתאמת את איסופו. זכויות בשל פגם אינן מוגבלות בהכרח לתקופת הביטול מחמת חרטה. לדיווח אפשר לבקש נציג ואפתח פנייה.');
        $f('extended_rights', 'זכויות מורחבות בביטול עסקה', "זכויות מורחבות\nאזרח ותיק\nעולה חדש\nאדם עם מוגבלות\nארבעה חודשים\nתקופת ביטול מורחבת",
            'אדם עם מוגבלות, אזרח ותיק או עולה חדש עשויים להיות זכאים לתקופת ביטול של ארבעה חודשים בעסקת מכר מרחוק שכללה שיחה עם העוסק, בהתאם לתנאי החוק.');
        $f('ship_cost', 'כמה עולה משלוח ובכמה זמן הוא מגיע?', "כמה עולה משלוח\nעלות משלוח\nדמי משלוח\nמשלוח רגיל\nמשלוח מהיר\nמתי יגיע\nזמן אספקה\nכמה זמן לוקח משלוח\nימי עסקים",
            $ops['std'] . '. ' . $ops['exp'] . '. ימי עסקים אינם כוללים שבתות, חגים וימי שבתון. זמינות המסלול ואזורי השירות נמסרים לפני אישור הזמנה.');
        $f('ship_delay', 'מה קורה אם המשלוח מתעכב?', "משלוח מתעכב\nעיכוב במשלוח\nהמשלוח לא הגיע\nמאחר\nמתי יגיע המשלוח שלי",
            'במקרה של עיכוב נמסור עדכון, מבלי לגרוע מזכות ביטול לפי דין. לבירור הזמנה מסוימת אפשר לבדוק כאן סטטוס הזמנה, או לבקש נציג.');
        $f('coupon', 'איך עובד קופון הנחה?', "קופון\nקוד קופון\nקוד הנחה\nהנחה\nקופון הנחה\nמימוש קופון",
            'קופון חל לפי תוקפו, סכום המינימום והכללים המוצגים בעת מימושו. הנחת קופון מחושבת על פריטים זכאים ואינה מפחיתה את דמי המשלוח, אלא אם נאמר אחרת.');
        $f('stock_changes', 'האם המלאי מתעדכן אחרי שהזמנתי?', "מלאי משתנה\nהמלאי נגמר\nאי אפשר לספק\nמוצר שהוזמן אזל\nזמינות מלאי",
            'מלאי עשוי להשתנות עד לאישור ההזמנה. אם אי אפשר לספק מוצר שהוזמן, נפנה אליך לפתרון ולהשבה בהתאם לדין.');
        $f('pay_card_security', 'אפשר לשלוח פרטי אשראי בצ\'אט או במייל?', "לשלוח פרטי אשראי\nמספר כרטיס\nפרטי כרטיס אשראי\nלשלוח אשראי בצאט\nלשלוח כרטיס במייל\nאבטחת תשלום",
            'לא. אין לשלוח פרטי אשראי בצ\'אט, במייל, בוואטסאפ או בהערות שירות. התשלום מתבצע בכרטיס אשראי או ב-PayPal בלבד.');
        $f('installments', 'אפשר לשלם בתשלומים?', "תשלומים\nלשלם בתשלומים\nכמה תשלומים\nתשלומים בלי ריבית\nתשלומים ללא ריבית\nעד 12 תשלומים\nריבית",
            'כן. עד 3 תשלומים ללא ריבית, ועד 12 תשלומים בתוספת ריבית. התשלום מתבצע בכרטיס אשראי או ב-PayPal. מספר התשלומים והעלות המדויקת מוצגים בקופה לפני אישור ההזמנה.');
        $f('pay_methods', 'באילו אמצעי תשלום אפשר לשלם?', "אמצעי תשלום\nבאילו אמצעי תשלום\nאיך אפשר לשלם\nאפשר לשלם בפייפאל\nאפשר לשלם בביט\nאפשר לשלם במזומן\nהעברה בנקאית",
            'בכרטיס אשראי או ב-PayPal בלבד.');

        $f('privacy_collect', 'אילו פרטים אתם שומרים עליי?', "אילו פרטים נשמרים\nאילו פרטים אתם שומרים\nמה אתם שומרים עליי\nמידע אישי\nפרטיות\nמחיקת מידע\nלמחוק את המידע שלי\nעיון במידע",
            'במועדון נשמרים האימייל, מצב הדיוור ומועד ההסכמה. בפניית שירות נשמרים שם, אימייל, מספר הזמנה, הודעת הלקוח ומצב הטיפול, ולעיתים טלפון והערות. אין איסוף פרטי אשראי. אפשר לבקש עיון, תיקון, הסרה מדיוור או מחיקה במייל השירות your.shem88@gmail.com, והבקשה תיבחן לפי הדין.');
        $f('accessibility_help', 'האתר נגיש? איך פונים בנושא נגישות?', "נגישות\nהאתר נגיש\nבעיית נגישות\nקורא מסך\nנגישות האתר\nתקן נגישות",
            'האתר פועל לעמוד בתקן ת"י 5568 ברמת AA, עם ניווט מקלדת, תיאורים חלופיים לתמונות והתאמה למסכים קטנים. בדיקה מקצועית מלאה עדיין לא בוצעה. לדיווח על קושי אפשר לפנות לשירות במייל או בוואטסאפ עם כתובת העמוד והקושי.');
        $f('who_operates', 'מי מפעיל את החנות?', "מי מפעיל\nמי העוסק\nפרטי העוסק\nמספר עוסק\nמי אתם\nעל החנות",
            'SHEM היא חנות אופנה לגברים. שם העסק: ' . ($bz('legalName') ?: 'טרם הושלם') . ', ח.פ ' . ($bz('registrationNumber') ?: 'טרם הושלם') . ', כתובת: ' . ($bz('address') ?: 'טרם הושלמה') . ', מייל: ' . ($bz('email') ?: 'your.shem88@gmail.com') . '.');
        $f('contact_service', 'איך יוצרים קשר עם השירות?', "איך יוצרים קשר\nמייל שירות\nטלפון שירות\nשירות לקוחות\nליצור קשר\nוואטסאפ\nדרכי יצירת קשר",
            'אפשר לכתוב כאן ואפתח פנייה לנציג, או לפנות למייל your.shem88@gmail.com. אם נדרש נציג, אעביר את הפנייה והנציג ייצור איתך קשר. בפנייה על רכישה ציינו מספר הזמנה. אין לשלוח פרטי אשראי או סיסמאות.');
        $d[] = self::e('ops', 'ops.contact', 'יצירת קשר עם השירות', "יצירת קשר\nמייל שירות\nדרכי יצירת קשר", 'אפשר לפנות למייל your.shem88@gmail.com, או לכתוב כאן ואפתח פנייה לנציג. הנציג ייצור איתך קשר בהקדם.');
        $f('not_found', 'לא מצאתי פריט, מותג או צבע שחיפשתי', "לא מצאתי\nלא מוצא\nלא קיים באתר\nחסר מוצר\nאין את הפריט\nאזל\nאזל מהמלאי\nמתי יחזור למלאי\nמתי יהיה במלאי",
            $no_match . ' אפשר לנסות סוג פריט או צבע אחר, או לבקש נציג.');
        return $d;
    }

    /** True when nobody edited the entry in the admin since the system (seed or this pack) wrote it. */
    private static function untouched(array $row, string $editor): bool {
        return in_array((string) $row['updated_by'], [self::SEED_EDITOR, $editor, 'תיקון ראשוני'], true);
    }

    /** @return array{created:string[],updated:string[],skipped:string[],deactivated:string[]} */
    public static function apply(): array {
        $r = ['created' => [], 'updated' => [], 'skipped' => [], 'deactivated' => []];
        foreach (self::pack() as $e) {
            $row = Ssb_Brain::by_slug($e['slug']);
            if (!$row) { $x = Ssb_Brain::save($e, self::PACK_EDITOR, 'create', 'owner-pack'); if (!is_wp_error($x)) $r['created'][] = $e['slug']; continue; }
            if (!self::untouched($row, self::PACK_EDITOR)) { $r['skipped'][] = $e['slug'] . ' (נערך ידנית)'; continue; }
            if ($row['body'] === $e['body'] && $row['title'] === $e['title'] && (int) $row['active'] === 1) continue;
            $x = Ssb_Brain::save($e + ['id' => (int) $row['id']], self::PACK_EDITOR, 'update');
            if (!is_wp_error($x)) $r['updated'][] = $e['slug'];
        }
        // superseded seeds: the new "מדיניות החזרות ומשלוחים" entry and the specific FAQ entries replace them (deactivated, never deleted)
        foreach (['policy.returns', 'policy.exchanges', 'policy.shipping'] as $slug) {
            $row = Ssb_Brain::by_slug($slug);
            if (!$row || (int) $row['active'] !== 1) continue;
            if (!self::untouched($row, self::PACK_EDITOR)) { $r['skipped'][] = $slug . ' (נערך ידנית, לא כובה)'; continue; }
            Ssb_Brain::set_active((int) $row['id'], false, self::PACK_EDITOR);
            $r['deactivated'][] = $slug;
        }
        return $r;
    }

    /* ------------------------------------------------------------------ store scan */
    private static function size_sort(array $sizes): array {
        $order = ['XXS' => 1, 'XS' => 2, 'S' => 3, 'M' => 4, 'L' => 5, 'XL' => 6, 'XXL' => 7, 'XXXL' => 8];
        $k = array_keys($sizes);
        usort($k, function ($a, $b) use ($order) {
            $na = is_numeric($a); $nb = is_numeric($b);
            if ($na && $nb) return (int) $a <=> (int) $b;
            if ($na !== $nb) return $na ? 1 : -1;
            return ($order[strtoupper($a)] ?? 99) <=> ($order[strtoupper($b)] ?? 99);
        });
        return $k;
    }
    private static function type_label(array $p): string {
        $words = [];
        foreach (explode(' ', trim((string) ($p['he'] ?? ''))) as $w) {
            if ($w === '' || preg_match('/^\d+$/', $w)) continue;
            if (Ssb_Live::color_roots($w)) continue; // colour is reported separately
            $words[] = $w;
        }
        return trim(implode(' ', $words));
    }
    /** Facts about what customers can see right now. */
    public static function facts(): array {
        $brands = []; $colors = []; $sizes = ['clothing' => [], 'shoes' => [], 'other' => []]; $types = [];
        foreach (Ssb_Live::products() as $p) {
            $b = trim((string) ($p['brand'] ?? '')); if ($b === '') continue;
            $brands[$b] = $brands[$b] ?? ['types' => [], 'colors' => [], 'sizes' => ['clothing' => [], 'shoes' => [], 'other' => []]];
            if (($t = self::type_label($p)) !== '') { $brands[$b]['types'][$t] = 1; $types[$t] = 1; }
            foreach (Ssb_Live::color_names($p) as $c) { if ($c !== '') { $brands[$b]['colors'][$c] = 1; $colors[$c] = 1; } }
            $cat = ($p['cat'] ?? '') === 'shoes' ? 'shoes' : (($p['cat'] ?? '') === 'clothing' ? 'clothing' : 'other');
            foreach (Ssb_Live::stock($p)['sizes'] as $s => $q) if ($q > 0) { $brands[$b]['sizes'][$cat][(string) $s] = 1; $sizes[$cat][(string) $s] = 1; }
        }
        ksort($brands);
        return compact('brands', 'colors', 'sizes', 'types');
    }
    private static function sizes_text(array $by): string {
        $parts = [];
        if ($by['clothing']) $parts[] = 'בגדים ' . implode(', ', self::size_sort($by['clothing']));
        if ($by['shoes']) $parts[] = 'נעליים ' . implode(', ', self::size_sort($by['shoes']));
        if ($by['other']) $parts[] = 'מידות נוספות ' . implode(', ', self::size_sort($by['other']));
        return implode('; ', $parts);
    }
    public static function scan_entries(): array {
        $f = self::facts(); $out = [];
        if (!$f['brands']) return $out;
        $names = array_keys($f['brands']);
        $out[] = self::e('faq', 'scan.overview', 'אילו מותגים ומוצרים יש בחנות?', "אילו מותגים יש\nמה המותגים\nמותגים\nמה יש בחנות\nמה אתם מוכרים\nמה יש לכם למכירה\nקולקציות\nקולקציה\nקטגוריות\nאילו מוצרים יש",
            'בחנות יש כרגע מוצרים מהמותגים: ' . implode(', ', $names) . '. סוגי פריטים: ' . implode(', ', array_keys($f['types'])) . '. אפשר לשאול אותי על מותג, סוג פריט, צבע או מידה, ואבדוק זמינות. אם פריט לא מופיע, ' . Ssb_Brain::phrase('sys.no_match', 'ככל הנראה הפריט המבוקש אזל כרגע מהמלאי וצפוי להתחדש בקרוב.'));
        if ($f['sizes']['clothing'] || $f['sizes']['shoes'] || $f['sizes']['other'])
            $out[] = self::e('faq', 'scan.sizes', 'אילו מידות קיימות בחנות?', "אילו מידות יש\nמה המידות\nמידות\nמידות זמינות\nמידות בגדים\nמידות נעליים\nאילו מידות קיימות",
                'מידות שזמינות כרגע במלאי: ' . self::sizes_text($f['sizes']) . '. הזמינות משתנה לפי פריט וצבע, ואפשר לשאול על פריט מסוים. טבלת מידות מפורטת עדיין לא הוזנה, ולכן אני לא ממליץ על מידה.');
        if ($f['colors'])
            $out[] = self::e('faq', 'scan.colors', 'באילו צבעים יש מוצרים בחנות?', "אילו צבעים יש\nצבעים\nבאילו צבעים\nצבעים זמינים\nמה הצבעים",
                'צבעים שקיימים כרגע בחנות: ' . implode(', ', array_keys($f['colors'])) . '. הצבע הזמין משתנה לפי פריט.');
        foreach ($f['brands'] as $b => $x) {
            $alias = array_merge([$b], Ssb_Live::BRAND_ALIASES[$b] ?? []);
            $kw = implode("\n", array_unique(array_merge($alias, array_map(fn($a) => 'מותג ' . $a, $alias))));
            $body = "במותג $b יש כרגע" . ($x['types'] ? ': ' . implode(', ', array_keys($x['types'])) : ' מוצרים') . '.';
            if ($x['colors']) $body .= ' צבעים: ' . implode(', ', array_keys($x['colors'])) . '.';
            if (($st = self::sizes_text($x['sizes'])) !== '') $body .= ' מידות זמינות: ' . $st . '.';
            $body .= ' כדי לבדוק פריט מסוים כתוב/י את הסוג והצבע שמעניינים אותך.';
            $out[] = self::e('faq', 'scan.brand.' . strtolower($b), "מה יש במותג $b?", $kw, $body, ['brand' => $b]);
        }
        return $out;
    }
    /** Create / refresh scan entries; deactivate scan entries whose brand is gone. @return array<string,string[]> */
    public static function scan_apply(): array {
        Ssb_Live::flush();
        $r = ['created' => [], 'updated' => [], 'skipped' => [], 'deactivated' => []];
        $entries = self::scan_entries(); $keep = [];
        foreach ($entries as $e) {
            $keep[sanitize_key($e['slug'])] = 1;
            $row = Ssb_Brain::by_slug($e['slug']);
            if (!$row) { $x = Ssb_Brain::save($e, self::SCAN_EDITOR, 'create', 'store-scan'); if (!is_wp_error($x)) $r['created'][] = $e['slug']; continue; }
            if ((string) $row['updated_by'] !== self::SCAN_EDITOR) { $r['skipped'][] = $e['slug'] . ' (נערך ידנית)'; continue; }
            if ($row['body'] === $e['body'] && $row['title'] === $e['title'] && $row['keywords'] === $e['keywords'] && (int) $row['active'] === 1) continue;
            $x = Ssb_Brain::save($e + ['id' => (int) $row['id']], self::SCAN_EDITOR, 'update');
            if (!is_wp_error($x)) $r['updated'][] = $e['slug'];
        }
        if ($entries) foreach (Ssb_Brain::all('faq') as $row) {
            if (strpos((string) $row['slug'], 'scan') !== 0 || isset($keep[$row['slug']]) || (int) $row['active'] !== 1 || (string) $row['updated_by'] !== self::SCAN_EDITOR) continue;
            Ssb_Brain::set_active((int) $row['id'], false, self::SCAN_EDITOR); $r['deactivated'][] = $row['slug'];
        }
        update_option('ssb_scan_at', Ssb_Db::now(), false);
        return $r;
    }
}
