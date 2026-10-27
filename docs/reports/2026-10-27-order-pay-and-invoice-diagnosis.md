# Report 2026-10-27 - order-pay under real theme, invoice, thank-you wording

Scope: SHEM staging only (Docker). Branch fix/order-complete-approved-wording. Nothing deployed, no live change.

## Root causes (all verified on staging)
1. WC-native `/checkout/order-pay/ID/` is swallowed by the SPA shell in `shem-storefront` (inc/shell.php serves shell/index.html for any HTML GET outside /api, /payment, /media, /wp-json, /wp-admin, /wp-content, /wp-includes). Hence `WP-TESTS/hosting/prepurchase/e2e.py` passes only under `twentytwentyfive`. The real storefront does not use that page: React calls `POST /api/checkout-home`, which calls the gateway `process_payment`, stores `_shem_pay_embed` and returns it as `embedUrl`.
2. `embedUrl` origin: the gateway rejects any embed URL that does not start with `SHEM_PAY_ALLOWED_ORIGIN` (staging value `http://localhost:8081/pay-gw`, same origin as SHEM). So the customer-facing iframe URL is the same-origin `/pay-gw/...` path, not the SHIMI host (code: shem-pay-gateway.php lines 66-72; config read on staging). Not observed on a live response in this task; the earlier Chromium e2e (0 requests to SHIMI host) is the behavioural evidence.
3. No invoice on staging: the PDF engine (mPDF vendor dir) is absent from the repo by design, and auto-issue (`shem_dok_settings.auto`) defaults to off.
4. Wording regression: commit 1ff61c0 rebuilt the SHEM bundle from `SHEM/src`, which never contained the owner-approved thank-you wording (decisions.md line 64); it existed only in the compiled bundle of 56c860c.

## Changes
- `SHEM/src/components/OrderComplete.jsx`: approved wording restored (greeting "לקוח יקר, תודה שרכשת ב...", "הזמנתך נקלטה, והתשלום בוצע!", "חשבונית עסקה", "הורדת חשבונית עסקה", "החשבונית בהפקה. הכפתור יופיע כאן בעוד רגע."). Token-level diff of old vs new compiled bundle shows only these strings changed.
- Theme rebuilt with `WP-THEMES/build-theme.sh SHEM` (45 static files byte-identical to vite output).
- `WP-TESTS/customer/e2e_customer.py`: `restore()` now supplies `updated_at` (the old INSERT failed silently, so settings were not restored).
- Staging only (not in repo): mPDF 8.3.1 copied into the SHEM container; option `shem_dok_settings={"auto":true}` set.

## Tests
- e2e_customer on SHEM staging: pass=42 fail=0 (run 6, log /home/claude/logs/e2e_customer_run6.log), including invoice buttons, valid PDF, no mail jobs, no JS errors, mobile overflow.
- Not run: real PayPal/3DS, order-pay under the real theme through WooCommerce endpoint (it is not a customer path), SHIMI theme.

## Go-live additions
- Ship the PDF engine (composer install or the bundled distribution ZIP) with SHEM-DOK on the production server.
- Decide and enable auto invoice issue (`shem_dok_settings.auto`).
- Run the payment e2e under the real theme and the `/api/checkout-home` path.
- SHIMI bundle: same wording regression check not done.

## Waiting for owner approval
- Merge of this branch (wording + test fix).
- Delete of leftover staging order 176 (older run; order 182 was deleted by the test itself).
- Keep or revert `shem_dok_settings.auto=true` on staging.
