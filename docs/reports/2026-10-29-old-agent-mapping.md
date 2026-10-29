# דוח מיפוי לפני סגירה - SHEM ו-SHIMI
תאריך: 29.10.2026

## כותרת
- מה נמדד: מצב המאגר המקומי, `git ls-remote` מול GitHub, ו-`ls` על התיקיות של הסוכן.
- מכשיר: פקודות קריאה בלבד. לא שונה, לא נמחק ולא נפרס דבר.
- גבול: `/home/claude` והמאגר SHEM-SHOP.
- בקרה: השוואת hash של קבצים מול עץ העבודה של המאגר.
- לא נבדק: תוכן תיקיות הגיבוי, מצב Docker (כבוי כרגע), הזיכרון של Claude, ומצב המאגר המרוחק מעבר ל-`ls-remote` (לא הורץ fetch).

## 1. מה שהועבר למאגר (מוכח)
- הענף `staging/shimi-linked-48` ב-GitHub נמצא על הקומיט `5df5942`. `master` נשאר על `ae5971f`. מקור: `git ls-remote`.
- אין קומיט מקומי שלא נמצא בענף מרוחק, ואין stash. מקור: `git log --branches --not --remotes`, לפי הרפרנסים המקומיים.
- הסקריפטים `backup-staging.sh`, `deploy-scc.sh`, `dstart.sh`, `docker-compose.yml` ו-`initdb.sql` זהים לקבצים ב-`ops/staging/`. ל-`wps.sh` יש במאגר גרסה חדשה יותר.
- גרסאות והאש של `wp.phar` ושל `woocommerce.zip` מתועדות ב-`ops/staging/README.md`.

## 2. מה שמוחזק רק אצל הסוכן ולא במאגר

| # | פריט | מיקום | במאגר? | מקור / הערה |
|---|---|---|---|---|
| 1 | קבצי סיסמת staging (שמות בלבד) | `/home/claude/.stg-admin-pass`, `.stg-pw-localadmin`, `/home/claude/staging/.env` | לא, ובכוונה | במאגר יש רק `ops/staging/env.example` |
| 2 | גיבויי מסד staging (22 קבצים) | `/home/claude/backups-staging/` | לא | לא אומת שיש להם עותק מחוץ למכונה |
| 3 | גיבויי קוד ושרת מ-25.10 (12 קבצים) | `/home/claude/backups/` | לא | תוכן לא נבדק. כולל `wp-config-pre-secret-rotation`, שעלול להכיל סודות |
| 4 | חבילת ההעברה: dumps, uploads, 4 חלקים | `/home/claude/transfer-pkg-2026-10-28/` | לא, לפי הוראת הבעלים | נשלחה לצ'אט. ההגעה של 4 החלקים לא אומתה |
| 5 | ייצוא SHEM | `/home/claude/staging/export-shem/` | חלקי | `store.json` זהה לקובץ במאגר. `catalogue.json`, `collections.json`, `help.json` ו-`SHA256SUMS` לא נמצאו במאגר לפי hash. לא נבדק למה ה-catalogue שונה מקובץ ה-fixture |
| 6 | `woocommerce.zip` ו-`wp.phar` | `/home/claude/staging/` | לא (קבצים גדולים) | מקור ההורדה לא אומת |
| 7 | נתוני אתרי ה-staging החיים | קונטיינרים ב-Docker | לא | Docker כבוי, לכן המצב לא אומת. הנתונים קיימים רק בגיבויים (סעיף 2) |
| 8 | לוגים של בדיקות | `/home/claude/logs/` | לא נבדק | סיכום ב-`docs/reports/2026-10-28-shem-dok-360.md`. הלוגים עצמם לא הושוו |
| 9 | בדיקות DOK | `/home/claude/dok-build/tests/` | לא נבדק | `shem-dok` שם הוא קישור לתוך המאגר |
| 10 | ארבעה קבצי handoff ישנים מ-23.10 | `/home/claude/` (`01_FULL_BRIEF…md`, `02_DECISIONS…md`, `03_SKILL.md`, `handoff_report…md`) | לא, לפי hash | תוכנם לא נקרא עכשיו |
| 11 | פלט בנייה ותמונות מסך | `SHEM/dist`, `SHIMI/dist`, `node_modules`, `SHEM-DOK/tests/ui/shots` | לא, מוחרג ב-`.gitignore` | ניתן לבנייה מחדש. לא אומת |

## 3. החלטות וממצאים שקיימים רק בשיחה
1. **אישור שרתי הבדיקה:** הבעלים כתב "מאשר" ב-28.10. הסוכן ענה שחסרים תקרת הוצאה ואופן גישה. המאגר עדיין כותב "ממתין לאישור" (`docs/project-state.md`, `SERVERS_DECISION.md`), והפרטים האלה לא מתועדים שם.
2. **ממצא הסיסמאות בדאמפים:**
   - SHEM: 4 משתמשים עם hash של סיסמה ו-session token. לשניים מהם יש גם application password.
   - SHIMI: 2 משתמשים עם hash ו-session token, בלי application password.
   - כל האימיילים הם כתובות בדיקה.
   - המלצה: אחרי הייבוא לאפס סיסמאות ולמחוק sessions ו-application passwords.
   - אין מילה על זה במאגר.
3. **חסר לשרת החדש:** התיקייה `export-2026-10-18` והתיקיות `amiri`, `lp`, `rl` (תמונות המקור של 48 הפריטים) לא קיימות אצל הסוכן. במאגר רק קובץ אחד מזכיר "amiri" (`docs/reports/theme-visual-diff.md`), ולא נבדק אם זה אותו נושא.
4. **בקשת חבילת ההעברה ושליחתה לצ'אט:** לא מתועדות במאגר.

## 4. גישות והרשאות (שמות בלבד)
- GitHub: גישת push למאגר. הגדרות ה-credentials לא נבדקו.
- Docker: על המכונה הסוכנת, כבוי כרגע.
- המחשב של הבעלים "blanko": מחובר, בלי תיקיות מחוברות (נבדק היום). לא מתועד במאגר.
- Connectors שהוזכרו בשיחה: Cloudinary, Hostinger, PayPal, Supabase, Figma, n8n. כל אחד מוזכר בקובץ אחד או יותר במאגר, אבל ב-`2_information_map.md` אין סעיף גישות. לא אומת איזה חשבון שימש בכל אחד.
- StackHawk: חסר `HAWK_API_KEY`.

## 5. משימות פתוחות
- אישור הבעלים לשרתי בדיקה: תקרת הוצאה ואופן גישה.
- איפוס סיסמאות, מחיקת sessions ו-application passwords אחרי ייבוא.
- אימות שארבעת חלקי `uploads-shem.tar.gz` הגיעו לצ'אט.
- תמונות המקור של 48 הפריטים: אין להן קבצים אצל הסוכן.
- מפתח HawkScan, מפתחות sandbox ל-3DS, מס חי 18 אחוז, אריזת PDF לפרודקשן, והגדרת `SCC_INVOICE_DELIVERY_MODE` בקבצי הפריסה (מתוך `docs/project-state.md`).

## 6. סיכום
- יש דברים שלא הועברו, כמפורט בסעיפים 2 ו-3. לא נכון לכתוב "אין שום דבר שלא הועבר".
- ההמלצה להעברה למסמכי המאגר: הסעיפים 1 ו-2 בחלק ההחלטות והממצאים. סודות וגיבויים לא נכנסים למאגר.
- לא בוצעה שום פעולה במהלך המיפוי. הסוכן ממתין.
