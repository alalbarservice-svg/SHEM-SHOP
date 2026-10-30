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
