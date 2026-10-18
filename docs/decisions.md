# החלטות הבעלים

- 15.10.2026: Docker מקומי קודם, אירוח מנוהל אחר כך (ספק ותוכנית טרם נבחרו).
- 15.10.2026: כניסה ל-WordPress עם Google לשני חשבונות מורשים בלבד.
- 15.10.2026: מסמכים פרטיים מחוץ לשורש הציבורי.
- 15.10.2026: תשלום ב-PayPal וכרטיס אשראי; חשבון PayPal עסקי אחד משותף; מפתחות רק דרך כספת.
- 15.10.2026: דומיינים לא מחוברים עד אישור סטייג'ינג; ממשק הניהול בעמוד נסתר באותו דומיין.
- 15.10.2026: SHEM מאחורי VPN מתחלף: Cloudflare מלפנים.
- 17.10.2026: כל המוצרים בשתי החנויות הם נתוני דמו; יוחלפו בנתונים אמיתיים.
- 17.10.2026: תמונות hero של SHEM יוחלפו בתמונות הקולקציה האמיתית (2560x950 מחשב, 1200x1600 מובייל).
- 18.10.2026: הסנכרון בין החנויות חד-כיווני בלבד, מ-SHEM ל-SHIMI. ב-SHEM נקבעים מק"ט ומחיר; שום נתון לא חוזר ל-SHEM.
- 18.10.2026: מערכת חשבוניות העסקה החדשה מחליפה את המחולל הקיים רק ב-SHEM ובממשק הניהול שלו (הנפקה ידנית ואוטומטית, עמוד תודה עם הורדה). פרטי מנפיק ניתנים לעדכון, עם ברירת מחדל.
- 18.10.2026: החיוב האמיתי מתבצע תמיד דרך SHIMI (PayPal או סליקה), גם בקנייה ב-SHEM. חשבונית העסקה ללקוח מונפקת מ-SHEM רק אחרי אישור תשלום. הסליקה ב-SHIMI מונפקת דרך חברת האשראי חשבונית על העסקה עבור העסק (פירוש מדויק של המסמך הזה טרם אומת).

## 18.10.2026 - SHEM DOK 1.1
- Issued documents are immutable; corrections only via linked credit documents (off by default, needs accountant approval).
- Documents issued only from a verified signed SHIMI paid callback; automation and customer email off by default.
- Backed up on branch feature/shem-dok-1.1-staging-verified (not merged).

## 18.10.2026 - PDF design decision
- Owner approved keeping the new SHEM DOK PDF design as is (not restyled to the old black/gold generator look). Reference: SHEM-DOK/tests/pdf-compare/compare.png.

## 18.10.2026 - PDF delivery and returns section
- Owner rejected hiding the empty heading. Heading is now "זמני אספקה, משלוחים ותנאי החזרות" with fixed default text: shipping terms (courier 5-7 business days 49 ILS, courier 1 business day 199 ILS) and returns (14 days from delivery, courier pickup). Applies to new documents only; issued documents are immutable.

## 18.10.2026 - cleanup of test components
- Owner approved removing test components that are no longer needed, provided everything is backed up. Done after backup (local DB dumps and files). Reversible via SHEM-DOK/tests/fixtures/staging-fixtures.sh.

## 18.10.2026 (16:36) - Order status wording (owner decision)
- Payment options page (PayPal or card), above the options, in large type: "הזמנתך התקבלה". NOT implemented yet: this page does not exist on staging (default WordPress theme, no PayPal/card gateway). Goes to the theme and payments work. It may only show once the order actually exists (to verify when the gateway is built).
- Thank-you page after payment: large heading "לקוח יקר, תודה שרכשת בSHEM מותגים", below it slightly smaller but still large and clear "הזמנתך נקלטה, והתשלום בוצע!", then the invoice section. Implemented in SHEM DOK (settings thankyou_heading, thankyou_sub). Shown only for orders confirmed as paid (verified: hidden on unpaid order).
