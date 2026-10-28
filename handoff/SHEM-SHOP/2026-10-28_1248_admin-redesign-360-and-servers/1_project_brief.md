# Project Brief - SHEM / SHIMI (נכון ל-28.10.2026 12:48)

מקורות: סשן זה, docs/project-state.md, docs/decisions.md, project docs, memory. פריט שלא נבדק מסומן "לא אומת".

## 1. מטרה וקריטריוני הצלחה
העברת שתי החנויות (SHEM -> shem-shop.com, SHIMI -> shimishop.co) וממשקי הניהול שלהן ל-WordPress/WooCommerce בלי שינוי עיצוב לא מאושר, ובלי לפגוע באינטגרציות, בסנכרון חד-כיווני SHEM->SHIMI, בטריגרים בין חנויות ובתפעול יומי. הצלחה: כל הבדיקות עוברות 360 ב-staging, משה מאשר במפורש עלייה, ורק אז פריסה. עד אז: סטייג'ינג מקומי ב-Docker בלבד.

## 2. מצב עכשיו + צעד הבא
שדרוג העיצוב היוקרתי של ממשק הניהול של SHEM (טאבים, טבלאות, כפתורים, טפסים, פאנלים) ובדיקת 360 הושלמו. שני בדיקות ישנות תוקנו, הענף `staging/shimi-linked-48` ב-863f434 נדחף ושווה ל-remote (נבדק ב-ls-remote). נוסח פאנל החשבונית אושר. השרתים והבדיקות שלפני רכישה ממתינים לאישור הבעלים. Docker היה כבוי בזמן ההעברה. צעד הבא: לקרוא את SERVERS_DECISION.md ולפנות לבעלים לאישור שרתי בדיקה ובחירת שיטת גישה.

## 3. בוצע
- ממשק ניהול SHEM: עיצוב יוקרתי (צד #0B0B0B, זהב #C9A96A/#A58042/#ECD6A6, כפתורי pill, קווי זהב דקים, גופן Arimo, RTL, מגע 44px). קומיט b3fa448, מוזג 3018514. מגירת מובייל נסגרת בשינוי נתיב.
- תיקון admin_suite.py שורה 148 (אימייל `scc_test_admin@example.invalid`) ו-SHIMI/tests/channel.test.mjs (fixture `demo-home.sql`; הקובץ זהה בייטית ל-0004_demo_catalogue.sql). קומיט de97361, מוזג 863f434.
- 360: SHEM unit 32/32, SHIMI unit 21/21, e2e manual 30/30, admin-issue 30/0, e2e customer 42/42, admin_suite SHEM 324 pass/0 fail/2 not-run, SHIMI 319 pass/0 fail/4 blocked/1 not-run, Playwright admin PASS, sync 48/48, price sync OK, visibility OK, reviews 49/0, UI sweep 38 views 0 issues. לוגים ב-/home/claude/logs/full360/.
- remote עודכן ל-https://github.com/alalbarservice-svg/SHEM-SHOP.git.
- בעבר (מתועד ב-project-state): נתיב חשבונית יחיד דרך SHEM DOK, גשר F1, ייבוא 48 פריטים אמיתיים מקושרים, תיקוני פרטיות (ערוץ ניהול מחוץ לחבילות ציבוריות, callback ניטרלי, פרוקסי תשלום באותו origin).

## 4. לא בוצע (לפי עדיפות)
1. אישור והרצת בדיקות שרתי בדיקה (בעלים, חוסם: אישור וגישה).
2. HawkScan (חוסם: HAWK_API_KEY בכספת).
3. 3DS אמיתי (חוסם: מפתחות sandbox).
4. מע"מ חי 18 אחוז ב-WooCommerce.
5. אריזת מנוע PDF לייצור ול-SHIMI.
6. SCC_INVOICE_DELIVERY_MODE בקבצי הפריסה.
7. תמונות: 43 placeholders ב-SHIMI, שקפי hero 2-3 למובייל.
8. פרטי מנפיק, ישות חיוב, ספק סליקה, חלון מעבר ו-DNS.

## 5. נבדק ולא בוצע
- ממשק ניהול SHIMI: לא שונה, עד בקשה.
- Multisite: נדחה, שתי התקנות נפרדות.
- שרת עם כתובת מתחלפת כל 24 שעות: נדחה 15.10 לטובת Cloudflare מלפנים. חידוש רק בהחלטה חדשה.
- מחיקת נתוני חשבונית ישנים (invoice_documents, שורת הגדרה invoice): אושרה מחיקה ב"לגבי נתונים הישנים - מאשר מחיקה" אך לפי decisions.md נשארו לא נמחקו וממתינים לאישור מפורש. לא אומת מצב עדכני במסד.
- בדיקת כל מודאל ומצב עריכה ידנית, DOK unit לא הורץ מחדש (לא נגעו), HawkScan.

## 6. החלטות (תאריך)
- 15.10: Docker מקומי קודם, אחר כך אחסון מנוהל; כניסת Google ל-WP רק לשני מיילים; מסמכים פרטיים מחוץ לשורש; PayPal+אשראי; חשבון PayPal משותף; דומיינים לא מחוברים עד אישור staging; Cloudflare מלפני SHEM.
- 17-18.10: כל המוצרים הנוכחיים דמו; סנכרון חד-כיווני SHEM->SHIMI; סליקה ב-SHIMI; חשבונית עסקה מ-SHEM.
- 25-27.10: הסתרה בשתי החנויות; שרתים נפרדים; בלי דואר יוצא; בוט שירות בסוף. פרטי השרתים ב-SERVERS_DECISION.md.
- 26.10: שם ניטרלי "SHEM" הוגדר בחשבון PayPal של SHIMI (הצהרת בעלים).
- 28.10: נתיב חשבונית יחיד (SHEM DOK) ב-SHEM; אוטומטי רק לכרטיס ו-PayPal; ידני לכל אמצעי תשלום. פאנל חשבונית אושר. remote עודכן.
- חוקי תצוגה: טקסט על תמונה בניגוד למה שמתחתיו; בלי קווים אפורים בין בלוקים, רק קו זהב דק; כפתורי pill.

## 7. דילמות ואישורים פתוחים
- אישור שרתי בדיקה (ספקים, גדלים, אזור, תקרה, "להשמיד בסוף").
- שיטת גישה לשרת הבדיקה: טוקן API בכספת / סקריפט שהבעלים מריץ / קישור המחשב.
- SHIMI כשפריט SHEM לא פעיל: האם מוסתר (נקבע "עשוי להסתיר", לא סופי).
- מחיקת שורות חשבונית ישנות.
- דחיפה: רק בכתיבת "דחוף".

## 8. סטנדרטים וחוקי משתמש
עברית בלבד, קצר וישיר, תשובה ראשונה אז פירוט, המלצה אחת; מקף קצר בלבד; כל קובץ נשלח לצ'אט ב-SendUserFile; חוק ברזל: כל טענה ממקור שנבדק באותו תור או "לא אומת" (סף 95 אחוז); לא נוגעים ב-.env; אין מחיקה בלי אישור; לא שולחים מייל בלי להודיע ולחכות לאישור; גיבוי לפני נגיעה בקוד/מסד; לא פורסים לפני מדידה והוכחה 360 (סביבה סטרילית); אין שינוי עיצוב/ניסוח בלי אישור; אין שאלות על מה שאפשר לעשות לבד; כל דוח נפתח בכותרת (מה נמדד, מכשיר, גבול סריקה, בקרה, מה לא נבדק); דוח סיום בעברית עם שגיאות, סטיות, סתירות, קבצים, לא נבדק, בדיקות, צעד הבא. `alealabar-production` מוגן, `top-model-production` מחוץ לתחום, Supabase מורשה רק `zpzhyckdqmkbllebhglv`. ללא סודות בצ'אט/ריפו/סקילים.

## 9. נתונים מרכזיים
- Repo מקומי /home/claude/shem-shop; remote origin SHEM-SHOP; ענף staging/shimi-linked-48 ב-863f434. ענפים מקומיים לא נדחפו: feat/admin-tabs-redesign (b3fa448), fix/legacy-tests (de97361). master עם היסטוריה לא קשורה, לא לדחוף.
- Staging: shem-staging-shem-1 :8081, shem-staging-shimi-1 :8082, shem-staging-db-1; WooCommerce 11.2.1, PHP 8.3, MariaDB 11.
- מוצר fixture `qa-shem-knit-20261009`, וריאציה 59 (M) מלאי 10/10 לצורך e2e_customer.
- 48 פריטי SHEM אמיתיים (1.00 ש"ח placeholder) מקושרים 1:1 ל-48 פריטי SHIMI; 9 דמו לא מקושרים.
- גיבוי staging לפני מיזוג: /home/claude/backups-staging/pre-merge-tabs-1793176656-shem-1028-1037.sql.gz (29,233,832 בייט) ושל shimi (107,364 בייט).
- Trailers בקומיט: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`, `Claude-Session: https://claude.ai/code/session_012gA4BxGh6ZQKLdVddSxiFA`.

## 10. מקורות
docs/project-state.md, docs/decisions.md, docs/hosting/*, docs/reports/*, project docs `claude/hosting-handoff-server-selection.md` ו-`claude/pre-golive-critical-actions.md`, memory `areas/shem-shimi*.md`, לוגים ב-/home/claude/logs/full360/.

## 11. כלים וחיבורים
Bash/Docker (staging), wp-cli דרך /home/claude/wps.sh, Playwright (e2e), git/GitHub (alalbarservice-svg), Projects tool (knowledge), memory tools, Cloudinary (תמונות), PayPal MCP (קריאה בלבד), Supabase (רק גיבוי zpzhyckdqmkbllebhglv), n8n (חיבור נכשל ב-15.10), Hostinger connector (מחירים), Figma/Chrome לפי צורך. HawkScan לא זמין.

## 12. סקילים חיוניים
migrate-shem-shimi-woocommerce (מבצע ההגירה), dev-workflow-master-v2 (דוח סיום), verify-before-instruct-v2 (אימות), ui-ux-interface-core-v2 + hebrew-rtl-ui-system (עיצוב), agent-handoff-closeout/deep-transfer (מעבר), cloudflare + hostinger (שרתים, כשמתחילים), wordpress-devkit (דיבוג), engineering:deploy-checklist ו-deploy-readiness-gate (לפני עלייה).

## 13. סיכונים ובאגים ידועים
Docker כבוי עד dstart.sh; e2e רגיש למלאי ולהזמנות held (20 דקות); SCC_INVOICE_DELIVERY_MODE נעלם מ-wp-config בסטייג'ינג (סיבה לא אומתה); דוחות staging מ-HawkScan חסרים; ריפו ציבורי; מחירי שרתים לא אומתו.

## 14. פערים
- האם נעשתה ריצת בדיקות שרת אחרי 27.10: לא ידוע.
- תוכן skill `migrate-shem-shimi-woocommerce` לא נקרא בסשן הזה.
- מצב הנתונים הישנים (חשבוניות) במסד: לא אומת.
- שיטת הגישה לשרת הבדיקה: לא הוחלטה.
- PROJECT_ROOT ידוע: /home/claude/shem-shop.
