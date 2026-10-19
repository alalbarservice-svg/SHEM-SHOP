# מצב פרויקט - 18.10.2026

- שלב: 1, סטייג'ינג מקומי ב-Docker (שתי התקנות WordPress + WooCommerce 11.2.1, PHP 8.3, MariaDB 11). אין חיבור לדומיינים.
- בוצע: בסיס ויזואלי (86 צילומים), גיבוי ושחזור של מסדי הסטייג'ינג (48 טבלאות, 346 אופציות, תואם), אימות שלמות הגיבוי המוצפן מ-13.10 (sha256 תואם ל-MANIFEST).
- נמצא: README.md אינו תואם ל-CHECKSUMS.sha256 (נערך אחרי חישוב הסכומים; לא אומת הגורם). 249 מתוך 250 הקבצים תואמים.
- חסום: שחזור בפועל של הגיבוי המוצפן דורש את מפתח ההצפנה (אצל הבעלים). פרויקט הגיבוי ב-Supabase לא נגיש מהחיבור הנוכחי.
- ממתין לבעלים: מפתח הגיבוי, אפליקציית PayPal (Sandbox) ומסירת מפתחות לכספת, אישור הוספת תמונות מובייל לשקפי ה-hero 2 ו-3.
- הבא: ייצוא נתונים טרי לקריאה בלבד, importer במצב dry-run.
- הערה: המאגר ציבורי. לא להעלות סודות, נתוני לקוחות או מסמכים עסקיים רגישים.
- 18.10.2026: נקראו ממשקי הניהול (קריאה בלבד). אושרה הלוגיקה: סנכרון חד-כיווני SHEM->SHIMI, חיוב דרך SHIMI, חשבונית עסקה מ-SHEM. ממתין: מערכת החשבוניות החדשה מהבעלים.

## Update 18.10.2026
SHEM DOK 1.1 verified on local staging (unit, 193 e2e, 12 ui). Branch feature/shem-dok-1.1-staging-verified awaits review. Not done: sandbox charges, HPOS-off run, PDF visual approval.

## Update 18.10.2026 (payment flow)
Built on staging: SHIMI-PAY/shimi-pay (session, PayPal create/capture, signed callback, retry/reconcile) and SHEM-PAY/shem-pay-gateway (gateway, "הזמנתך התקבלה" page, iframe). Tests: SHIMI-PAY/tests/e2e_pay.py 42/42 (capture response mocked in section E; order creation against real PayPal Sandbox). DOK verify: 316 documents OK. Not verified: real wallet approval and capture (needs buyer login), card/ACDC, live-theme look (themes not built yet), real PayPal webhook delivery (reconcile polling built and tested with mocked PayPal responses). Tests now 49/49. Waiting for owner: payment provider decision.

## Update 19.10.2026
- Round 2 final fixes executed on staging: customer E2E 42/0 x3, import 121/0 and 73/0, live sync 49/49, SHEM admin 333 pass, SHIMI admin 322 pass (1 blocked payment link, 1 not-run), DOK s1 43/0, s2 54/0, s3 52 pass + 2 blocked (theme routing), s4 42/0, ui 12/0, ui3 16/0, wp shem-dok verify 472 documents.
- Open: SHIMI admin payment link needs a provider adapter; non-manager 403 vs 401; theme order-received page; mPDF packaging for SHIMI; staging HMAC secret rotation; payment suite not rerun (blocked).
- Next: owner decisions listed in the final report, then go-live preparation only after approval.

## Update 19.10.2026 (evening)
- Done on staging: approved thank-you wording (SHEM), order-received redirect, HMAC rotation (both sites), 30 SHIMI basics demo products in collection basics (staging only). Regression: cx 129/0, core, unit suites, admin 0 fail, live 49/49, DOK s1 43, s2 54, s3 52 (+2 blocked), s4 42, customer e2e 42/42.
- Open: 25 product photos (placeholders), ui3.js DOK UI test needs fixture login files (not run this round), mPDF bundling decision for SHIMI production (owner has not answered), issuer details, hero slides 2-3 mobile images, hosting/payment provider/charging entity, cutover window and DNS.
