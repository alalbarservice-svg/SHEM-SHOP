# תלויות ושחזור SHEM / SHIMI

| רכיב | מקור האמת והקבצים | מה נדרש בשחזור |
| --- | --- | --- |
| חנות וממשק ניהול | SHEM/src, SHIMI/src; אותו פרויקט לכל חנות וניהול | npm ci לפי package-lock.json ואז npm run build |
| השרת | worker/ בכל חנות | תשתית Cloudflare Workers / Sites, bindings מ-.openai/hosting.json |
| סכמת הנתונים | drizzle/ ומטא-נתוני המיגרציות בכל חנות | בניית סכמה וייבוא צילום טבלאות נכון לחנות |
| מלאי לפריטים מקושרים | SHEM products, channel_reservations, stock_movements | אין להפוך את SHIMI למקור מלאי נוסף |
| מיפוי מוצרים | product_links ומידע הווריאנטים בשתי החנויות | לשמר IDs, URLs, מק"טים ומידות; סודות/כתובות תואמות |
| מחירים וסיכום | SHEM worker/operations.js, worker/channel.js ובדיקות channel | מחירון, מבצעים, קופון, מע"מ כלול, משלוח וסכום סופי זהים |
| סליקה | SHIMI worker/channel.js; SHEM HomeCheckout | ספק אמיתי עדיין לא מחובר; אישור חתום, מניעת כפילויות ושמירת הרשאות |
| חשבונית SHEM | worker/documents.js, document-assets, invoice_documents והגדרות מנפיק | PDF A4 רק אחרי תשלום מאומת; הורדה מוגנת; זהות העסק 516067873 |
| הרשאות ניהול | worker/api.js, ADMIN_EMAILS וזהות מאומתת Sites | אימייל לבדו אינו התחברות; הגדרות גישה/זהות נשמרות בצילום המוצפן |
| שירות לקוחות ואזור חשבון | SHEM worker/customer-experience.js ו-src/components | גישה להזמנות רק לזהות מאומתת או מפתח הזמנה פרטי; חיבור משלוחים חיצוני נדרש |
| מדיה ופונטים | public/assets, src/assets/embedded-logos, worker/document-assets | קובצי המקור נכללו בקוד; R2 אינו ממופה במלואו |
| סודות החיבור | CHANNEL_SECRET, HOME_PAYMENT_SECRET, PEER_SERVICE_TOKEN | אינם נגישים לייצוא; לשחזר או להחליף בתיאום בין החנויות |
| דומיינים | shem-shop.com / shimishop.co, מטא-נתוני Sites, GoDaddy | DNS וחיבור TLS נפרדים מהקוד; תיעוד הצילום אינו אישור שהדומיינים פעילים |

כל ממשק ניהול נמצא תחת /admin.html של החנות שלו, ללא קישור ניווט ללקוחות; APIs מוגנים בהרשאות שרת. מידע עסקי רגיל, מיפויים ונתונים נשמרים בצילום המוצפן, לא בקוד בלבד.

לבדיקת שחזור יש להריץ בדיקות קוד, בדיקות סנכרון וממשקי ניהול, לבדוק סל וסיכום זהים בשתי החנויות במחשב ובמובייל, ואז בדיקות sandbox עם הספק המחובר. אין להפעיל חיוב אמיתי על סמך גיבוי בלבד.
