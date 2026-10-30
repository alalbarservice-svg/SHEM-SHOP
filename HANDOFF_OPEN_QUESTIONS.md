# HANDOFF_OPEN_QUESTIONS

שאלות מהסוכן החדש. פורמט: תאריך | שאלה | הקשר | מופנה אל (manager / outgoing agent / user). המשתמש מעביר.

כותרת: נמדד ב-28.10.2026 13:01 בסביבת הסוכן החדש. כלי: ls, docker ps, git ls-remote, git clone של staging/shimi-linked-48. בקרה: השוואה מול handoff_state.json. לא נבדק: סביבת הסוכן היוצא.

ממצא: סביבת הסוכן החדש נפרדת ואינה מכילה את /home/claude/shem-shop, את הסקריפטים (dstart.sh, wps.sh, backup-staging.sh, deploy-scc.sh), את קונטיינרי ה-staging, את הגיבויים, את הלוגים ואת קבצי הסיסמאות. Docker לא רץ. ב-remote: הענף ב-863f434 כולל את b3fa448 ו-de97361 (מאומת). docs ב-remote עודכנו לאחרונה ב-08:15, ותיקיית handoff לא קיימת ב-remote.

| תאריך | שאלה | הקשר | מופנה אל |
|---|---|---|---|
| 28.10.2026 | לקמט ולדחוף (רק אחרי "דחוף" מהבעלים) את עדכוני docs/project-state.md ו-docs/decisions.md מ-12:48 ואת תיקיית handoff/SHEM-SHOP/2026-10-28_1248_... | קיימים רק אצל הסוכן היוצא. מחיקתו תאבד אותם | outgoing agent + user |
| 28.10.2026 | להכניס לריפו (בלי סודות) את dstart.sh, wps.sh, backup-staging.sh, deploy-scc.sh ואת נוהל בניית ה-staging מאפס, כולל fixtures (48 פריטים מקושרים, וריאציה 59 מלאי 10/10) | ב-25.10 נכתב "Staging was rebuilt from the repo" אבל הנוהל המדויק לא מתועד | outgoing agent |
| 28.10.2026 | האם גיבויי ה-staging (pre-merge-tabs-*.sql.gz) נדרשים, או שה-staging משוחזר במלואו מהריפו? אם נדרשים - איך להעביר אותם בלי סודות ובלי לחשוף בריפו ציבורי | הגיבויים קיימים רק אצל הסוכן היוצא | outgoing agent |
| 28.10.2026 | איך יוצרים מחדש בסביבה חדשה את סודות ה-staging (סיסמאות admin, HMAC תשלום) | ערכים לא נמסרו ואסור שיופיעו בצ'אט | outgoing agent |
| 28.10.2026 | שורות חשבונית ישנות: decisions.md אומר "ממתין לאישור מפורש", 1_project_brief.md מצטט "מאשר מחיקה". מה נכון? | סתירה בין מקורות | user |
| 28.10.2026 | נסגר: הבעלים בחר באפשרות ג (המחשב שלו המקושר לשיחה) כשיטת הגישה לשרתי הבדיקה | עדיין לא נבדק שה-shell עובד, ייבדק בתחילת יום הבדיקה | user (נענה) |

## בדיקת הסוכן החדש לתשובות (28.10.2026 13:12)
כותרת: נבדק מול clone של staging/shimi-linked-48 (863f434). לא נבדק: ops/staging (עוד לא ב-remote).

- 2: מקובל. ב-remote קיימים WP-TESTS/import ו-WP-TESTS/shimi-linked (build.py, verify.py), כך שבנייה מחדש של הנתונים אפשרית. לא נמצא בריפו סקריפט שיוצר את ה-fixture qa-shem-knit-20261009 (רק הפניות בתוך e2e_*.py).
- 3, 4: מקובל.
- 5: נסגר. 0 שורות בשתי החנויות, אין מה למחוק.
- 6: נסגר. הבעלים בחר במחשב שלו (אפשרות ג).

| תאריך | שאלה | הקשר | מופנה אל |
|---|---|---|---|
| 28.10.2026 | איך נוצר ה-fixture qa-shem-knit-20261009 (וריאציה 59, מלאי 10/10)? אם בפקודות wp-cli ידניות - להוסיף אותן כסקריפט ל-ops/staging לפני הדחיפה | בלעדיו e2e_customer לא ירוץ ב-staging חדש | outgoing agent |
| 28.10.2026 | גרסאות מדויקות של wp.phar ו-woocommerce.zip (WooCommerce 11.2.1?) ומאיפה הורדו - להוסיף ל-README | נדרש לבנייה מאפס | outgoing agent |
| 28.10.2026 | לדחוף רק ל-staging/shimi-linked-48, לא ל-master | master בהיסטוריה זרה | outgoing agent |

## ממצא בבנייה מאפס (28.10.2026 14:20)
כותרת: נמדד בסביבת הסוכן החדש. כלים: wp-cli, `wp scc import` במצב dry-run, grep על הריפו (5df5942). בקרה: wp.phar ו-woocommerce.zip הורדו מהמקורות הרשמיים וה-sha256 זהה לזה שב-README. לא נבדק: תוכן סביבת הסוכן היוצא.

הושלם: WordPress 7.1.3, WooCommerce 11.2.1, shem-shimi-commerce, shem-dok (mPDF 8.3.1 מ-composer.lock), shem-pay-gateway, shimi-pay, שתי ערכות, משתמשי בדיקה, סודות חדשים. הכל רק ב-staging המקומי.

חסר בריפו, קיים רק אצל הסוכן היוצא:
1. /home/claude/export-2026-10-18/ - הייצוא של החנויות החיות. ה-importer נכשל בלעדיו (חסרים shem-menswear-collections.json ו-shem-menswear-help.json). גם cx-test.php ו-verify_import.py קוראים ממנו ישירות.
2. תמונות 48 הפריטים האמיתיים של SHEM. build.py קורא מתיקיות scratchpad של סשן ישן (amiri/, lp/, rl/).
3. מסדי ה-staging ותיקיות uploads של שתי החנויות.

| תאריך | בקשה | הקשר | מופנה אל |
|---|---|---|---|
| 28.10.2026 | ליצור חבילת העברה ולשלוח לצ'אט (ראו נוסח ההודעה מהסוכן החדש) | בלעדיה אי אפשר לשחזר את ה-staging זהה | outgoing agent |
| 28.10.2026 | WordPress core version ב-staging הישן? | ב-staging החדש 7.1.3 (מתמונת wordpress:php8.3-apache). הגרסה הישנה לא מתועדת | outgoing agent |

## תוצאות בנייה מחדש ו-360 (28.10.2026 16:45)
כותרת: נמדד בסביבת הסוכן החדש, staging בנוי מהריפו (5df5942) + חבילת ההעברה, כל ההאשים תואמים SHA256SUMS. כלי: ההרצות המקוריות מהריפו. בקרה: השוואה למספרים ב-handoff_report_3.md. לא נבדק: UI sweep (38 תצוגות), DOK unit, HawkScan.

| בדיקה | ישן | חדש |
|---|---|---|
| SHEM unit | 32/32 | 32/32 |
| SHIMI unit | 21/21 | 21/21 |
| e2e manual payment | 30/30 | 30/30 |
| admin-issue | 30 ok / 0 fail | 30 ok / 0 fail / 1 info |
| e2e customer | 42/42 | 42 ok / 0 fail |
| admin_suite SHEM | 324/0/2 not-run | 324/0/2 not-run |
| admin_suite SHIMI | 319/0/4 blocked/1 not-run | 319/0/4 blocked/1 not-run |
| Playwright admin | PASS | PASS (SHEM ו-SHIMI) |
| 48 זוגות מקושרים | 48/48 | 48/48 |
| reviews | 49/0 | 49 checks / 0 problems |
| price propagation | OK | OK (0.8 שניות) |

פערים שנמצאו (הוספו תיקונים רק ב-staging המקומי ובקובץ ops/staging/docker-compose.yml, ללא commit):
1. הקבועים SHEM_HOME_PAYMENT_SECRET, SHEM_PAY_SESSION_URL, SHEM_PAY_ALLOWED_ORIGIN (ב-SHEM) ו-SHIMI_HOME_PAYMENT_SECRET (ב-SHIMI) לא היו בנוהל הבנייה. בלעדיהם שער התשלום shem_pay לא זמין והרכישה מחזירה 503. הוספתי אותם בערכים שנגזרו מהקוד (SESSION_URL = http://shimi/wp-json/shimi-pay/v1/session, ORIGIN = http://localhost:8082). אחרי זה e2e manual עבר 30/30.
2. מלאי ה-fixture qa-shem-knit-20261009 במידה M היה 9 אחרי השחזור/הרצות (חייב 10/10). seed-qa-product.sh --reset החזיר ל-10/10 ואז e2e customer עבר. הסקריפט דורש FIX=<נתיב מוחלט> כשמריצים אותו מחוץ ל-ops/staging.
3. ה-uploads של SHEM מכילים רק 48 קבצי PNG (תמונה אחת לכל פריט). ל-48 הפריטים מוגדרות 2-3 תמונות, ו-92 מתוכן (תמונות 2 ו-3) חסרות בדיסק. 9 פריטי הדמו ללא תמונות.
4. visibility_sync.py: בשלב "inactive" התוצאה בפועל היא SHEM None | SHIMI None (הפריט לא מוצג גם ב-SHIMI), בעוד שה-docstring של הבדיקה אומר ש-SHIMI אמור להציג. הבדיקה לא עושה assert.
5. uploads של SHEM כוללים גם wc-logs (לוגי staging ישן). לא סודות, לא אומת תוכן.

| תאריך | שאלה | הקשר | מופנה אל |
|---|---|---|---|
| 28.10.2026 | האם 92 התמונות 2 ו-3 חסרות גם ב-staging הישן? (בדיקה: האם קיימים קבצים ב-wp-content/uploads/scc-media/products מעבר ל-48) | אם קיימות אצלכם, צריך להעביר אותן | outgoing agent |
| 28.10.2026 | איך הוגדרו SHEM_PAY_SESSION_URL, SHEM_PAY_ALLOWED_ORIGIN ו-secrets של השער ב-staging הישן? האם הערכים שבחרתי זהים? | נגזרו מהקוד, לא אומתו מול הישן | outgoing agent |
| 28.10.2026 | מה הדפיס visibility_sync.py בשלב inactive בריצה הישנה? | אצלי SHIMI None | outgoing agent |
| 28.10.2026 | איפה סקריפט ה-UI sweep (38 תצוגות)? לא נמצא בריפו | נדרש כדי לאמת את כל ה-360 | outgoing agent |
