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

## 19.10.2026 - Final fixes round: root causes and findings
- Nonce in admin (item 9): root cause was the nonce shim hooked only to wp_head while the migrated theme shell fires storefront_shell_head; writes returned 401. Fixed (both hooks). Browser PASS 3 cycles on both sites, writes and GETs carry X-WP-Nonce, no nonce console errors.
- Thank-you screen: the real customer screen is the SPA /order-complete.html. The WooCommerce order-received page renders the SPA home under the migrated theme (woocommerce_thankyou hooks never run). DOK tests I1 and I14 are recorded as BLOCKED, never as pass. Owner decision pending: align the SPA wording with the approved DOK wording.
- Emails: invoice email stays OFF by default; invoices download from the thank-you screen (SCC_INVOICE_DELIVERY_MODE=download, mail_jobs 0).
- Live sync regression (49 to 46/3 then 49/49): Action Scheduler ran as ActionScheduler_HybridStore because the data store migration status was never set to complete; the CLI claim with --group then raised "group does not exist". Fixed on staging with the official `wp action-scheduler migrate` (0 actions moved). What reset or never set the status is not proven. Production queue runners do not filter by group, the CLI test does.
- SHIMI admin documents: 3 BLOCKED items were a missing mPDF vendor on SHIMI (fallback to the SHEM-DOK vendor only works on SHEM). mPDF copied to the SHIMI plugin vendor dir on staging, 3 items now PASS. Packaging of mPDF for production SHIMI remains an owner decision.
- Application Passwords are unavailable under WP_ENVIRONMENT_TYPE=staging over http; test mu-plugin enables them for tests only.
- UI test harness: one login navigation timeout in 4 ui.js runs (server login measured 12 times at about 0.3 s). Harness changed to waitForURL; cause of the single timeout not proven.
- Test logs: T1 now reads only debug.log lines written during the run.
- acceptance status: docs/acceptance-status-2026-10-19.csv (34 pass, 23 partial, 16 not-run, 9 blocked). Payment-provider scenarios are blocked by the owner decision to skip the provider for now.

## 19.10.2026 (evening) - Owner answers and what was done
- Thank-you wording approved by the owner ("מאשר"): the SHEM SPA order-complete screen now uses the approved DOK wording (title, "הזמנתך נקלטה, והתשלום בוצע!", "חשבונית עסקה", "הורדת חשבונית עסקה", pending text). The WooCommerce order-received URL now points to /order-complete.html#order=..&token=.. (filter woocommerce_get_checkout_order_received_url, only for orders with a legacy id and view token). Customer e2e 42/42 on staging. SHIMI bundle not changed (customer invoices exist only on SHEM).
- HMAC secret of the staging payment bridge rotated on both staging wp-config files (new random value, identical on both, never printed). The owner typed a password in chat in reply to this question; it was NOT used and NOT stored. Real secrets for production go in a vault only.
- Owner decision for SHIMI: keep the existing products and add more basics-style items. 30 new demo products (10 shirts, 8 pants, 6 shoes, 6 hats, SKU SHIMI-B-*) created on STAGING only via the admin API and added to the collection "בייסיק ליום יום" (slug basics). Live SHIMI is untouched. 5 of 30 have generated photos; 25 carry a labeled "PHOTO PENDING" placeholder because the image generator hit its daily limit. Images to be replaced via the admin API when available.
- Products created on staging do not exist on the live SHIMI; they reach it only if the owner adds them there or approves an import at go-live.

## 25.10.2026 - SHIMI sync rule and real items (owner)
- The rule "item shown only if published and in stock" does not apply to SHIMI. SKU, price, discounts and promotions come from SHEM items. Links are created, copied and edited only in the admin interfaces. A SHEM purchase is cleared on SHIMI and must match the SHEM order exactly (SKUs, discounts, price, total).
- Real SHEM items are the 48 priced 1.00 ILS. The 9 others are demo and must not be linked. 48 real SHIMI items are linked one-to-one to them (staging).

## 25.10.2026 - SHEM brand and category visibility mechanism (owner requirement, recorded late)
- Required, SHEM storefront only: only brands with at least one shown item are displayed; within a brand only item categories (shoes, shirts, knitwear etc.) that have shown items are displayed. Empty brands or categories disappear by themselves and reappear by themselves when an item is added, with no design breakage.
- A shown item = active and in stock. A brand whose items all sell out disappears until restocked. Does not apply to SHIMI.
- Applies everywhere in the storefront: menu, home, filters, brand pages, designers page.
- A direct link to an empty brand or category redirects to the shop.
- The admin can add, edit and remove item categories. New brands are added only through work with the agent, not from the admin UI.
- Status found on 25.10.2026 (measured on staging): only the hiding of empty categories inside a brand page worked. Empty brand still shown on home, no redirect, stock not checked, no category management in admin. This requirement was never in docs or acceptance before this entry. Build tracked as AC-083 to AC-089.

## 2026-10-25 - verification of brand/category visibility (staging)
- Admin suite regression: SHEM 326 pass / 7 fail, SHIMI 317 / 5 fail. The same 7 SHEM failures reproduce byte-for-byte on the pre-change plugin (email domain example.invalid, mail jobs in download mode, payment-link sandbox 501, invoiceStatus queued, mail-off message). Environmental, unrelated to itemTypes. Not fixed here.
- Not tested: HawkScan (no API key), real iPad/iPhone, any live deploy.

## 2026-10-25 - privacy fixes approved by the owner
- Owner approved: keep the SHEM-SHIMI admin code out of public bundles (done), merge of that fix and of the neutral callback route into staging/shimi-linked-48 (done), rotation of the staging payment secret (done), documentation update (this entry).
- Owner has NOT approved: any deploy, any hosting provider or plan, DNS changes.

## 2026-10-26/27 - hosting decisions (owner)
- Owner goal: SHEM and SHIMI on separate servers. No outgoing email is needed; customer service will be a bot that forwards cases to the owner.
- Owner already set a SHEM-neutral statement/business name in the SHIMI PayPal account (owner statement, not verified by an agent).
- Owner approved merging fix/public-surface-hardening into staging/shimi-linked-48 (done, 1ec8a8b).
- Agent recommendation, NOT yet approved: Hetzner CPX22 (SHEM) and Linode Shared 4GB (SHIMI), Cloudflare Tunnel, one-day hourly test on both before any permanent purchase. Owner asked to pause the purchase 1 to 2 days. See docs/hosting/HANDOFF-server-selection-and-prepurchase-tests.md.

- 28.10.2026: owner approved merging fix/order-complete-approved-wording, test/shem-dok-360, fix/admin-dok-bridge and deleting staging test order 176. Done locally via fast-forward into staging/shimi-linked-48; master not used (unrelated history). shem_dok_settings.auto left true pending confirmation.

- 28.10.2026 (owner): SHEM has ONE invoice path, the SHEM DOK admin. Automatic invoice after a store purchase stays for card and PayPal only (shem_dok_settings.auto = true). A manually confirmed payment (cash, transfer, card, PayPal, other) is marked, never auto-invoiced and never creates a legacy document; the admin issues the PDF in the DOK editor with method-specific fields. Legacy invoice settings, template preview and issue path are closed on SHEM only (role gate SCC_ROLE=shem); SHIMI keeps its own path. Legacy rows (invoice_documents, the `invoice` setting row) were NOT deleted and wait for an explicit owner approval. Not pushed.
