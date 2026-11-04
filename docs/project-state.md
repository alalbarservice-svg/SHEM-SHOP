# PINNED - hosting servers and pre-purchase tests (2026-10-27)
- Waiting for owner approval to open hourly-billed test servers (Hetzner CPX22 for SHEM, Linode Shared 4GB for SHIMI, one per store). Nothing bought or deployed. Next important step on the hosting track, delayed 1 to 2 days by the owner.
- Full context, decisions, test plan T1-T8, costs and the open access problem: docs/hosting/HANDOFF-server-selection-and-prepurchase-tests.md. Do not let this drop between other tasks.

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

## Update 25.10.2026 (SHIMI linked items)
- Staging was rebuilt from the repo (Docker project shem-staging, WooCommerce 11.2.1). SHEM has 57 imported items; the 48 priced 1.00 are the real ones, the other 9 are demo and are not linked.
- Created on staging 48 real (non-demo) SHIMI items, each linked one-to-one to a real SHEM item (WP-TESTS/shimi-linked). SKU, price and previous price come from SHEM. Verified: 48 pairs, SKU equal, price equal, all connected, 48 distinct sources.
- Verified: a price change on a real SHEM item reaches the linked SHIMI item in about 1 second and reverts correctly; the discount (previous price) syncs; stock 0 on SHEM keeps the item listed on SHIMI.
- Regression on the rebuilt staging: unit 61/0, links 123/0, flow 98/0, payment 39/0 (1 not-run: real provider), woo 36/0, live two-site 49/0.
- Open: SHEM active=false hides the linked SHIMI item (owner rule says SHIMI is not bound to published+in-stock - needs owner answer); payment equality was tested with fixtures only, not an end-to-end order on the real 48 items; 43 of 48 SHIMI images are placeholders (image generator limit); the 30 old SHIMI-B-* items no longer exist; SHIMI descriptions are empty.

## Update 25.10.2026 (privacy fixes on staging)
- Merged to staging/shimi-linked-48 and pushed: (1) admin channel code removed from the public bundles and served to administrators only; (2) neutral callback route `/shem-dok/v1/pay/notify`. Staging payment secret rotated on both sites (new random value, identical, not stored in the repo).
- Evidence: see docs/hosting/privacy-and-hosting-requirements.md. Not merged: experiment/pay-same-origin-proxy.
- Open: hosting provider and plan (owner), same-origin proxy decision, 2 default "SHEM" strings in the SHIMI bundle, 43 placeholder images, real charged order, verified live backups, historical gateway-id mapping. No deploy approved.

## Update 27.10.2026 (order-pay, invoice, wording)
- Branch fix/order-complete-approved-wording: owner-approved thank-you wording restored in SHEM source and theme rebuilt; e2e_customer 42/0 on staging. Report: docs/reports/2026-10-27-order-pay-and-invoice-diagnosis.md.
- Open: merge approval, delete order 176, keep/revert shem_dok_settings.auto on staging, PDF engine packaging for production, SHIMI wording check.

## Update 28.10.2026 (SHEM-DOK 360)
- Branch test/shem-dok-360. SHEM-DOK installed and works (auto from signed callback, manual from WP-admin). React admin does NOT embed it (finding F1). Report: docs/reports/2026-10-28-shem-dok-360.md
- Open: approve F1 fix, real 3DS and HawkScan need owner-provided sandbox keys / StackHawk key, live WC taxes on.

## Update 28.10.2026 (F1 admin-DOK bridge)
- Done on staging: admin "issue document" now returns the SHEM-DOK invoice (no second legacy document). Merged locally (ff) into staging/shimi-linked-48, not pushed. Customer 42/0, legacy-template 21/21, manual payment 12/12 and 11/11, s4 42/42.
- Open: F2 (manual-external gets no DOK invoice, owner decision), HawkScan needs StackHawk key, real 3DS needs sandbox keys, master has unrelated history (do not push to it).
- Report: docs/reports/2026-10-28-f1-verification-and-plan.md

## Update 28.10.2026 (single invoice path, staging)
- Branch fix/single-invoice-dok merged locally into staging/shimi-linked-48 (no push). SHEM invoices only via SHEM DOK; SHIMI legacy path unchanged.
- Verified on staging: DOK unit 152/0; manual-payment e2e 30/0; auto card/PayPal e2e 30/0 (1 info); customer e2e 42/0; admin suite SHEM 323 pass, SHIMI 318 pass (1 known fail each: test admin email .invalid vs .test); Playwright admin PASS on both.
- Open: owner approval to delete legacy staging data and to push; HawkScan needs HAWK_API_KEY; real 3DS needs sandbox keys; SCC_INVOICE_DELIVERY_MODE disappears from staging wp-config (set again on SHIMI, put it in the deployment files); PDF engine packaging for production.

## Update 28.10.2026 (admin redesign, legacy tests, handoff)
- SHEM admin luxury redesign (b3fa448) and two stale-test fixes (de97361) merged locally into staging/shimi-linked-48; branch at 863f434 pushed and equal to origin. Full 360 green on local staging (SHEM unit 32/32, SHIMI unit 21/21, customer e2e 42/42, admin_suite SHEM 324/0, SHIMI 319/0 with 4 blocked by design, sync 48/48, UI sweep 38 views 0 issues). Not verified: HawkScan, real 3DS, every modal state.
- Handoff package: handoff/SHEM-SHOP/2026-10-28_1248_admin-redesign-360-and-servers/ (servers decision in SERVERS_DECISION.md). Open questions file: HANDOFF_OPEN_QUESTIONS.md.
- Open: owner approval of hourly test servers plus access method, HawkScan key, 3DS sandbox keys, live tax 18 percent, PDF engine packaging, SCC_INVOICE_DELIVERY_MODE in deployment files, legacy invoice rows deletion approval. Local branches not pushed: feat/admin-tabs-redesign, fix/legacy-tests.

## פתוח: בוט תמיכה (29.10.2026)
- תזכורת: מספר הוואטסאפ של נציג השירות ריק בכוונה (הגדרות הבוט, wa_number). ההודעות ממתינות בתור (wp_ssb_notice, ערוץ whatsapp) עד שיחובר ערוץ. לחזור לזה, וגם למספר הוואטסאפ הקבוע באתר (052-9586766).

## Update 30.10.2026 (360 sweep, unperformed checks)
- Staging only, no commit, no push. Backups: pre-order-fix-1031-*, pre-admin-rerun-1030-*.
- Done: SHEM catalogue order now identical to live (57/57, meta _scc_updated_at, rollback in evidence/order-fix). Test products hidden (draft) on both stagings.
- Verified: SHEM desktop blocks live vs staging identical; mobile search, menu, cart, wishlist, forms identical except brand list (build version) and support chat bubble. SHIMI shop, club, cart, product on desktop and mobile: no overflow, no broken images, 0 console errors; add to cart (size select then button) works, VAT 18 percent and shipping 49 shown. Admin suites: SHEM 324 pass, SHIMI 319 pass (4 blocked by design), browser admin PASS on both.
- Differences by design: live SHIMI has 5 demo items (staging 48 real); live /admin.html redirects unauthenticated users to the storefront, staging shows an admin login gate (401).
- Not verified: live admin interior (needs owner login), SHEM mobile language toggle and account entry (times out on live and staging alike), real 3DS, HawkScan.
- Owner decision pending: keep repo behaviour (hide brands with no in-stock items) or show all brands like live. Recommendation: keep.

## Update 31.10.2026 - club check with a dummy customer (staging)
- WP-TESTS/admin/club_dummy.py shem|shimi: signup, card, loyalty adjust, birthday, earn on paid order, redeem, release, suspend/archive/restore, unsubscribe + re-consent, permissions, audit, admin UI. Result: SHEM 112/112, SHIMI 112/112. Test data (t_club_) removed, settings restored, audit rows remain (append-only).
- Not verified: member login (no such flow in code), mail to members (no provider), enabling the club on live (needs approved privacy link + mail).

## Update 31.10.2026 - member login and account check (staging)
- WP-TESTS/admin/member_login.py shem|shimi: login (wrong/correct password), session, /api/customer/me with club member block, order ownership (own, other's = 404, viewToken), preferences/watches/CSRF, card suspended/archived/restored, logout, browser flow with screenshots. SHEM 77/77, SHIMI 2/2 (customer API not exposed on SHIMI by design).
- Finding: club.enabled (signup) and loyalty.enabled (member block, benefits) are two separate switches. Both must be on for the club to work end to end.
- Finding: a card created by signup only has no name; admin cannot edit it until a name is added.
- Finding: staging login is stock wp-login (English, unstyled, no self-registration); button label says ChatGPT; live uses OAuth. Owner decision pending on the customer login method.
- Correction: earlier club test left 84 WooCommerce test orders (suite cleanup did not remove WC orders). Backed up, deleted, cleanup fixed; order count back to 179.
- Mail: plugin has no sender registered (scc_admin_mail_process filter / Resend constants). Free options researched, owner choice pending. Nothing sent.
- Live club activation: prepared as checklist only, nothing executed on live.
- Uncommitted, waiting for "דחוף".

## Update 31.10.2026 (separation of stores)
- Scan (read-only, 40 routes per store, staging and live): SHEM shows no mention of SHIMI. SHIMI shows only the contact email your.shem88@gmail.com on contact, accessibility, cancel, payments and privacy pages (owner decision pending: separate email for SHIMI).
- SHIMI club hidden on staging (source SHIMI/src/ShimiApp.jsx plus compiled bundle in WP-THEMES/shimi-storefront). Backup: backups-staging/shimi-theme-before-club-hide-1031. Live untouched. SHIMI privacy page still mentions the club (text, decision pending). Production needs a proper vite rebuild (bundle hash changes).
- Club activation checklist is now SHEM only.

## Update 31.10.2026 (SHIMI real build, privacy text, 404 fix)
- SHIMI privacy page: the two club sentences removed (owner approval 31.10). Source SHIMI/src/components/PolicyPages.jsx and theme bundle.
- Real vite build of the patched SHIMI source (scratch copy, not the repo dist): new bundle index-CAk6XJtZ.js, admin chunk ChannelManagement-Vf8OusoL.js. CSS, fonts, images and favicon are byte-identical to the previous theme (no design change). Theme shell html (3 files) and public.sha256 updated consistently; manifest check 0 failures. Deployed to SHIMI staging only. Old files and the previous theme are in backups-staging/shimi-theme-before-real-build-1031.
- Browser check on staging (desktop 1440 and mobile 390): no club link, /#/club shows home, no console errors, no HTTP 4xx, 49 product images load. Tests: SHIMI npm test 5/5, test:sites 4/4, admin_suite shimi green except 1 NOT-RUN and 4 BLOCKED (unchanged from before).
- The 2 SHIMI staging image 404s: WooCommerce test products created by admin_suite (t_admin_, sku T_ADMIN_IMG) in the 30.10 runs. Not on live (live has 5 products). Root cause: since the migration products live in WooCommerce and admin_suite cleanup only deleted legacy rows. Fixed in admin_suite.py (tracks the POST id and deletes WC products and variations by _scc_legacy_id). Own t_ test products removed (SHIMI 27 + 4, SHEM 54, backup pre-delete-t-admin-img-1031-*). Full suite rerun: products 48 and orders 30 before and after.
- Still open: separate SHIMI customer email (owner decision made, address NOT given yet; blocks SUPPORT_EMAIL, OrderComplete mailto, worker fallbacks, plugin and support-bot defaults, per-store SCC_ADMIN_CONTACT_EMAIL), mail sender for SHEM club (owner choice pending, recommended Resend free), live build and deploy (needs approval), SHIMI admin still shows club panels (admin-only).
- Uncommitted, waiting for "דחוף".

## Update 31.10.2026 (club mailing, router 403, PDF engine, staging only)
- New SHEM-only module WP-PLUGIN/shem-shimi-commerce/includes/modules/admin-60-club-mail.php: club broadcast ("הודעת תפוצה") through Resend, plus public opt-out page. API: GET/POST /api/admin/manage/club/broadcast (dry run by default, real send only with confirm:true), GET/POST /api/club/unsubscribe. SHIMI answers 404 on all of it.
- Safeguards: only active members with consent and a valid unique address; subject starts with "פרסומת:"; footer with advertiser name, address, phone, email (send is blocked 409 until all four are filled in invoice settings); one-click List-Unsubscribe headers; per-member HMAC opt-out link (GET shows a button, POST unsubscribes); daily cap 100 (Resend free), 429 beyond it; campaign resume never double-sends; batches of 50.
- Staging runs in SINK mode (SCC_MAIL_SINK in staging wp-config, sender club@example.invalid): messages are recorded, nothing is sent. WP-TESTS/admin/club_mail.py: 63/63 PASS (gates, dry run, test message, broadcast, opt-out, cap and resume, stubbed Resend request shape, SHIMI 404, audit). Test data (t_cm_) removed, settings restored.
- Router: logged-in user without manage_options now gets 403 with the original message (was 401). The 3 PDF tests that were BLOCKED were caused by mPDF missing on SHIMI staging (vendor copied from SHEM); admin_suite labels fixed. admin_suite: SHEM 325 PASS + 1 NOT-RUN (SHIMI-only guard, by design), SHIMI 323 PASS + 1 BLOCKED (payment link, owner decision).
- NOT verified: real Resend API call (needs key and verified domain), Resend rate limit (sources conflict), delivery to a real mailbox, legal wording (not legal advice).
- Needs owner: Resend API key via vault only, DNS verification of shem-shop.com, advertiser details in invoice settings, final sender address, explicit confirmation before the first real send, approval of the public opt-out page wording and of any admin UI for the broadcast (new design). mPDF vendor must be part of the production deployment.
- Uncommitted, waiting for "דחוף".

## Update 31.10.2026 (mPDF committed to repo, owner request)
- PDF engine mPDF v8.3.1 is now in the repo as a trimmed vendor folder: WP-PLUGIN/shem-shimi-commerce/vendor (used by SHEM and SHIMI admin documents) and SHEM-DOK/plugin/vendor (SHEM-DOK plugin). 27 MB each (original 240 MB: nested .git, tests, utils and unused fonts removed; DejaVu and GNUFree fonts kept). Test document rendered with full and trimmed vendor: identical size, text and fonts; repo copy also renders (69953 bytes). Note file inside each vendor folder.
- Deploy: copy the plugin folders as they are, no composer step needed.

## Update 01.11.2026 (SHIMI customer contact email, staging)
- Owner decision 01.11: SHIMI shows no SHEM address to customers and sends no mail to customers. barlieon341@gmail.com is added to SHIMI only as the contact address for contact requests. Admin login (your.shem88) unchanged and not visible to customers.
- Changed: SHIMI/src/components/PolicyPages.jsx (SUPPORT_EMAIL) and OrderComplete.jsx (mailto). Real vite build in a scratch copy; compared with the previous bundle: only 3 email strings and the admin chunk name differ. Theme updated (bundle index-D8TN8B-B.js, admin chunk ChannelManagement-BhNOVKlv.js, 3 shell files, public.sha256), deployed to SHIMI staging only. Backup: backups-staging/shimi-theme-before-contact-email-1101.
- Browser check desktop 1440 and mobile 390, 8 customer routes: new address shown (contact page 3 times, other pages 2), old address 0, no console errors, no HTTP 4xx.
- Not touched (admin-only or out of scope): Admin.jsx and worker defaults (admin identity), support-bot and plugin defaults (SHEM), WhatsApp link in the SHIMI bundle (WhatsApp number is out of scope).
- Open: mail sending from SHIMI stays off (no provider on SHIMI); whether Israeli law requires a visible email for an online store was not verified.
- Uncommitted, waiting for "דחוף".

## Update 01.11.2026 (WhatsApp removal, SHIMI mail guard)
- Owner decision 01.11: remove the WhatsApp link from SHIMI; SHEM-only mail defaults (your.shem88) stay for SHEM; SHIMI sends no mail to customers.
- SHIMI (staging only): WhatsApp link, button and phone removed from policy/contact pages (bundle index-BSXSmqoA.js). The admin operations setting "whatsapp" (admin-only, validated field) is unchanged.
- Plugin: new module core-mail-guard.php. On SCC_ROLE=shimi every wp_mail to a non-administrator is suppressed (counter option scc_mail_blocked). cfg_email default is barlieon341@gmail.com on SHIMI and your.shem88@gmail.com on SHEM. SHEM unaffected (tested).
- Tests: admin_suite shem 325 PASS + 1 NOT-RUN, shimi 323 PASS + 1 BLOCKED (by design, no provider); club_mail 63/63 earlier run, re-run subset PASS.
- Live SHIMI admin: no signed-in session in the browser, so only the public page was read (live still shows the old email and WhatsApp, nothing changed on live).
- Staging infra: the db container had a broken layer after restart; recreated with volumes kept. Volume backups: backups-staging/volumes-1101.
- Still open: real e-mail send test (needs readiness approval and a send path), then commit and push (owner pre-approved after all checks).

## בדיקת ממשק ניהול חי SHIMI (01.11.2026, קריאה בלבד, נכנס כ-your.shem88@gmail.com)
- נסרקו כל 18 מדורי הניהול: נטענו ללא שגיאה, בלי שינוי נתונים.
- הגדרות: שדה וואטסאפ בניהול = 972529586766 (הגדרת אדמין בלבד; בחנות החיה הקישור עדיין מוצג עד go-live).
- פרטי העסק: רק מספר עסק מלא. שם משפטי, כתובת, טלפון, אימייל, שעות ואיש קשר לנגישות ריקים - לטפל לפני go-live.
- בקרה והשקה: חסרים פרטי תבנית חשבונית, שירות מייל, סודות חיבור לאתר הבית ואישור חיבור. 6 מוצרים ללא מיפוי, 4 אזלו, 0 מיילים שנכשלו.
- "הפעלת רכישה" מסומן on בעוד חיבור הסליקה חסר: לבדוק לפני go-live (לא שונה).
- מייל בדיקה אחד נשלח ונקלט ב-alalbar.service@gmail.com.
- ביקורת אבטחה סטטית של הפלאגין: ראו דוח. HawkScan דינמי לא הורץ (אין מפתח).

## מספר וואטסאפ תמיכה (03.11.2026)
- הוחלף ל-0507974021 בקוד SHEM (עמודי מדיניות, צור קשר, פרטי הזמנה, ברירת מחדל בניהול), בתוסף ובהגדרת הבוט. נבנה ונפרס ל-staging בלבד, אומת בדפדפן. לא בוצע commit. SHIMI (ברירת מחדל אדמין ישנה 972529586766, לא מוצגת ללקוח) לא שונה.

## 04.11.2026 - PayPal Sandbox (wallet) on SHIMI staging
- Sandbox keys (from the owner's local file, verified against the Sandbox token endpoint) set as wp-config constants on SHIMI staging only. Not in repo. Card (ACDC) is not available for Israel per PayPal eligibility page; wallet only.
- Staging-only PayPal forwarder + proxy constants set (SHIMI_HTTP_PROXY, SHIMI_CA_BUNDLE); SHIMI_SHEM_ORIGIN / CALLBACK_URL / CALLBACK_HOST restored. Remove forwarder and proxy constants before go-live.
- e2e_pay.py (04.11.2026, re-run after fixes): 58/58 PASS. Root cause of the earlier invoice failures: staging had woocommerce_calc_taxes=no and the test orders had no billing country, so WooCommerce VAT was 0 and SHEM DOK refused by design. Fix is in the test only (VAT on during the run and restored to the original value at exit; test orders billed to IL with prices including VAT). Other test fixes: reconcile helper handled an empty PHP result (JSON list); E4/F4 mock amount 777 cents because product 13 costs exactly 1.00 ILS. PayPal buyer approval and capture response are mocked (no password entry); order creation against the PayPal Sandbox is real.
- Not tested: real buyer approval in Sandbox, 3DS, card, webhook (none exists; polling), Live.
