# תיק מעבר - SHEM-SHOP

כותרת: נמדד מתוך קבצי הסשן, git (rev-parse, log, status, ls-remote) והמסמכים שנקראו בתור זה. לא נסרק: מסד staging חי (Docker כבוי), skill ההגירה, החשבונות מול הספקים. מה לא נבדק מפורט בסעיף 7.

## 1. מצב נוכחי
- מטרה: הגירת SHEM ו-SHIMI ל-WordPress/WooCommerce בלי שינוי עיצוב לא מאושר, בלי לפגוע בסנכרון ובתפעול.
- נקודת עצירה: ענף staging/shimi-linked-48 ב-863f434, נדחף ושווה ל-remote. 360 ירוק על staging מקומי. השרתים ובדיקות לפני רכישה ממתינים לאישור בעלים.
- סטטוס: in_progress. שום דבר לא נפרס ולא נקנה.

## 2. מה הושלם
- עיצוב יוקרתי לממשק הניהול של SHEM (b3fa448 -> 3018514), אושר על ידי משה ("נראה מעולה").
- תיקון שני בדיקות ישנות (de97361 -> 863f434).
- בדיקת 360 מלאה, פירוט ב-1_project_brief.md סעיף 3.
- עדכון remote ל-SHEM-SHOP.git; אושר נוסח פאנל החשבונית.
- תיק מעבר זה ועדכון docs/project-state.md ו-docs/decisions.md (מקומי, לא נקומט).

## 3. מה פתוח / חסום
- [ ] אישור שרתי בדיקה ו-T1-T8 - חוסם: בעלים
- [ ] שיטת גישה לשרת בדיקה - חוסם: בעלים
- [ ] HawkScan - חוסם: HAWK_API_KEY בכספת
- [ ] 3DS אמיתי - חוסם: מפתחות sandbox מהבעלים
- [ ] מע"מ 18 אחוז חי, אריזת PDF, SCC_INVOICE_DELIVERY_MODE בקבצי פריסה
- [ ] מחיקת שורות חשבונית ישנות - חוסם: אישור מפורש
- [ ] תמונות, פרטי מנפיק, ישות חיוב, ספק סליקה, חלון מעבר ו-DNS

## 4. החלטות מאושרות
ראו 1_project_brief.md סעיף 6. החלטת השרתים המלאה (מה הוחלט, למה, איך משרתת, יעדים ומצב סופי רצוי) ב-SERVERS_DECISION.md. תמצית: הבעלים קבע שרתים נפרדים והסתרת מקור; Cloudflare מלפני SHEM (15.10). הספקים (Hetzner CPX22 ו-Linode 4GB), Tunnel ובדיקות T1-T8 הם המלצת סוכן שממתינה לאישור.

## 5. הנחות ופריטים לא מאומתים
- UNCERTAIN: מחירי ספקים, מדיניות Hetzner לחשבון חדש.
- UNCERTAIN: Tunnel והסתרת IP המקור; האם שני אזורי Cloudflare חולקים nameservers.
- UNCERTAIN: מה מוצג ללקוח בקבלה/חלון PayPal; חשיפת פרטי חיוב של מארח/רשם/Cloudflare ללקוח (לא נבדק).
- UNCERTAIN: אם בוצעה ריצת בדיקות שרת אחרי 27.10.
- UNCERTAIN: מצב נתוני החשבונית הישנים במסד; סיבת היעלמות SCC_INVOICE_DELIVERY_MODE.
- הנחה: Docker יעלה עם dstart.sh (כך עבד בסשן).

## 6. קבצים, נתיבים וכלים
- שורש: /home/claude/shem-shop. תיק: handoff/SHEM-SHOP/2026-10-28_1248_admin-redesign-360-and-servers/ (handoff_report.md, handoff_state.json, 1_project_brief.md, 2_information_map.md, 3_SKILL.md, 4_start_prompt.md, SERVERS_DECISION.md).
- שאלות: /home/claude/shem-shop/HANDOFF_OPEN_QUESTIONS.md.
- פקודות: `bash /home/claude/dstart.sh`; `/home/claude/wps.sh shem|shimi <wp-cli>`; `/home/claude/backup-staging.sh <label>`; `WP-THEMES/build-theme.sh SHEM|SHIMI`; `/home/claude/deploy-scc.sh`.
- בדיקות: unit צריך `npm run build` קודם (SHIMI גם `npm ci`); e2e צריך /home/claude/.stg-pw-localadmin ו-/home/claude/.stg-admin-pass (chmod 600, ערכים לא מוצגים).
- טבלת משאבים מלאה: 2_information_map.md.

## 7. ראיות ולידציה
- עבר: SHEM unit 32/32, SHIMI unit 21/21, e2e manual 30/30, admin-issue 30/0, customer 42/42, admin_suite SHEM 324 pass/0 fail/2 not-run, SHIMI 319 pass/0 fail/4 blocked/1 not-run (חסימה מתוכננת: אין ספק תשלום ואין מנוע מסמכים ב-SHIMI), Playwright admin PASS, sync 48/48, price sync OK, visibility OK, reviews 49/0, UI sweep 38 תצוגות 0 בעיות. לוגים: /home/claude/logs/full360/.
- מאומת בתור הזה: ענף, 5 הקומיטים האחרונים, status נקי מקבצים לא מעקבים פרט לתיק זה, ls-remote 863f434, קיום docs/hosting, Docker כבוי.
- לא נבדק: HawkScan, 3DS אמיתי, פריסה חיה, כל מודאל ומצב עריכה ידנית, DOK unit בריצה חוזרת, iOS Safari, הרצת T1-T8, שלוש שאלות הטרום-רכישה (PayPal, חשיפת חיוב, בעל דומיין/Tunnel) כבדיקה חדשה.

## 8. סיכונים וגבולות אישור
- סיכון: ריפו ציבורי; e2e רגיש למלאי/הזמנות held; master בהיסטוריה זרה; מחירי שרתים לא אומתו.
- דורש אישור: כל פעולה מול ספק, רכישה, DNS, פריסה, דחיפה ("דחוף"), מחיקה, שינוי עיצוב/ניסוח, מייל או הודעה, חיוב חי.

## 9. הוראות לסוכן הבא
1. קרא את התיק ואת SERVERS_DECISION.md.
2. הסבר את ההבנה ב-5 שורות.
3. זהה נקודת עצירה: אישור שרתי בדיקה.
4. ציין סיכונים וגבולות אישור.
5. התחל רק אחרי שההקשר ברור: הרם Docker, שאל את הבעלים (אישור + שיטת גישה), אל תפעל מול ספק לפני אישור.

## 10. Prompt פתיחה
ראו 4_start_prompt.md (מועתק כאן):

```text
אתה ממשיך את פרויקט SHEM-SHOP (הגירת SHEM ו-SHIMI ל-WordPress/WooCommerce, סטייג'ינג מקומי בלבד). טען את הסקיל shem-shimi-continuation-agent. קרא קודם את התיק ב-/home/claude/shem-shop/handoff/SHEM-SHOP/2026-10-28_1248_admin-redesign-360-and-servers/, את docs/project-state.md ו-docs/decisions.md, וסכם ב-5 שורות מצב, נקודת עצירה, סיכונים, אישורים וצעד ראשון. שאלות ב-HANDOFF_OPEN_QUESTIONS.md. אל תשנה קוד או תפעל מול ספקים עד שהבנת את ההקשר.
```

## 11. סטטוס סנכרון
PENDING_USER_FORWARD_OR_MANAGER_ACK. לא הייתה תקשורת עם מנהל סוכנים ולא התקבל אישור.

## 12. רמת ביטחון
MEDIUM. הקוד, הבדיקות והמצב ב-git מאומתים. החלטת השרתים נשענת על מסמכי 27.10 (המלצה, לא אישור), ופריטים רבים מסומנים "לא אומת".
