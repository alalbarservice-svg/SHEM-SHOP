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

## 18.10.2026 - Payment flow on staging (owner approved: "מאושר")
- PayPal button shows only in SHEM. The whole customer journey stays in SHEM; only the payment itself runs on SHIMI, embedded as an iframe. SHIMI holds the PayPal secret, creates and captures the order, then sends the signed paid callback (HMAC, shem-home-v1) to SHEM. SHEM issues the invoice only after that callback. Browser redirects and iframe messages never mark an order paid.
- The large "הזמנתך התקבלה" heading is now implemented above the payment options (SHEM order-pay/receipt page, option shem_pay_received_heading). Staging, default theme only; the live theme look is not verified.
- Card (ACDC) is not enabled on the PayPal Sandbox business account (PAYEE_NOT_ENABLED_FOR_CARD_PROCESSING). The card button appears in the PayPal SDK but a card capture was not tested.
- Staging-only scoped forwarder (CONNECT to *.paypal.com / *.paypalobjects.com:443 only) gives the SHIMI container internet access to PayPal. Not part of go-live.

## 18.10.2026 (17:23) - Owner replies
- Accountant approval for credit documents: approved by owner.
- Issuer email: your.shem88@gmail.com (other issuer details still not provided).
- Hero slides 2 and 3 mobile images: owner is handling.
- Owner asked for a sample of the email and thank-you texts for approval, and asked for explanations of buyer2 and the 13.10 backup key. Owner asked why block checkout, live-theme look and PayPal reconciliation were left unhandled: they are to be handled.

## 18.10.2026 (17:40) - Payment page text, block checkout, reconcile
- Owner: removed the text "הסליקה מתבצעת באמצעות SHIMI. הפרטים אינם נשמרים ב-SHEM." from the payment frame. Under "תשלום מאובטח" it now reads: "פרטי האשראי אינם נשמרים במערכת."
- Block checkout: the gateway did not appear in the WooCommerce block checkout ("no payment methods available"). Fixed by registering it for Blocks (SHEM-PAY blocks.js). An order placed through the block checkout now reaches the "הזמנתך התקבלה" page with the PayPal frame. Also fixed the receipt block rendering 4 times on block themes.
- PayPal reconcile: polling (hourly, `wp shimi-pay reconcile`) for sessions left at pp_created for over 15 minutes; verifies at PayPal (COMPLETED, or captures if APPROVED) and sends the signed callback. Chosen over a webhook because staging has no public URL; a webhook can be added at go-live as a second trigger of the same function.

- 2026-10-18: Gateway enable switch in SHEM-PAY was ignored (found by test G9); fixed (init_form_fields, enabled option). Payment suite now 58/58 on staging (incl. block checkout: 1 h1, 1 iframe). Not verified: real wallet approval, card (ACDC), real webhook, real theme look.
