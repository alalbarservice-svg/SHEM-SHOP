# SHEM customer experience

SHEM only. No SHIMI checkout, catalogue, configuration, database or deployment changes.

## Customer access
- `/#/account` (the hash route `/account`): platform sign-in establishes a Site-scoped user ID and verified email; only orders for that authenticated email are listed.
- Guest access uses the existing private order token in the URL fragment, not an email/phone lookup. APIs use an Authorization bearer header. Guest invoice downloads use the same immutable PDF as the original completion page.
- No independent password/OTP service was invented. Guests can buy without signing in and save their private order link from the completion page.
- Order/payment status is separate. Shipment progression is never invented based on elapsed time.
- Customer fields, items, invoice availability and tracking metadata are projected explicitly. Internal notes, payment secrets, source mappings and tokens are not included in account listings.

## Product administration
- Up to three local uploaded photos, alt text, ordering and first-photo card display.
- A per-product editable measurement chart: up to six named columns, thirty rows, measuring instructions, fit and model information. Copy charts from other products.
- Colour names, swatches and an optional photo association. Actual SKU/stock selection remains the existing size/colour variant and source mapping.
- Missing real measurements are disclosed; no default body measurements are substituted.

## Checkout and recovery
- Existing canonical `/api/checkout-review` and `/api/checkout-home` remain authoritative.
- Guest details and shipping -> verified summary -> original embedded SHIMI payment -> original SHEM payment confirmation/PDF.
- Field-level validation, autocomplete, configured shipping choices, inclusive VAT and plain customer copy.
- Pending payment sessions and their exact accepted order snapshot are retained in session storage in the same browser tab. A page reload resumes the existing payment session rather than creating another. No automatic second charge is attempted when the outcome is unknown.
- A pending payment is not made successful by a client action. Real adapter and paid callbacks remain required.

## Help, retention and measurement
- Public `/api/help`; owner-authorized `/api/admin/experience` supports published/draft FAQ editing, placement, actions, on-site alert controls and aggregate reports.
- Back-in-stock watches are owned by authenticated Site users; current effective source availability is checked on account refresh. Notifications appear inside the site, not in email/WhatsApp.
- Existing loyalty ledger is displayed, including available and pending credit. Existing paid-only accrual remains authoritative. No new discount changes were made to SHIMI.
- Complementary products are selected from available catalogue items, favouring a complementary category and related brand/price.
- Visitor event capture requires affirmative session consent. Events exclude form contents and payment details, use an ephemeral session ID and are retained for 90 days; the management report covers 30 days. Consent-based visits are not all visitors. External messaging is not connected.
- Exchanges remain human-support operations. The existing return/cancellation request form remains a request, not an automatic refund or courier booking.

## Shipment adapter contract (inactive until connected)
Configure `SHIPPING_WEBHOOK_SECRET` using server-side Sites environment secrets only. Do not enter it in client content/settings.

`POST /api/shipment-events`, exact JSON payload:
```json
{"eventId":"carrier-event-unique-id","orderId":"existing-SHEM-order-id","status":"shipped","carrier":"Carrier name","tracking":"Tracking number","trackingUrl":"https://carrier.example/tracking","estimatedDelivery":"2026-10-20"}
```
Allowed statuses: `processing`, `shipped`, `completed`. Do not send estimates as actual completion.
- `x-shem-timestamp`: 13-digit Unix milliseconds, within five minutes.
- `x-shem-signature`: hex HMAC-SHA256 of `timestamp + "." + exactRawBody`.
- Unique event IDs, paid-order requirement, no status regression, atomic compare-and-swap, auditable updates. Amounts, SKUs, invoice, stock and payment state cannot be changed by shipment events.
- A provider-specific or warehouse adapter must translate real authenticated provider events into this contract. No carrier or warehouse is represented as connected.

## Validation
Run `npm run build` and `node --test tests/*.test.mjs`.
Isolated tests cover catalogue fields, private customer and guest access, invoice byte equality/A4, watches/preferences, FAQ authorisation, consent metrics, signed/idempotent shipment callbacks, UI rendering/validation and the existing inter-store review/payment/stock contract.
No real payment, production paid order, mail delivery or external carrier event is created by these tests.

## Live QA, 2026-10-13
Observed the public customer account and guest-access controls, FAQ category filtering and expanding answers, product variant selection, cart addition, field-level validation and a signed inter-store pre-payment review in the live browser. Test knit M: SKU QA-OCT09-KNIT-M, black, sale price ILS179, standard delivery ILS49, total ILS228. No payment or order was created. Fixed cart colour display, review-to-details default-submit behaviour, asynchronous loading messages and low-contrast light-background outline buttons discovered during browser QA.
Customer ownership, paid invoice and shipping callback tests ran against isolated D1/R2 fixtures; no real customer/order/payment data was created or modified. The browser session was anonymous. Actual signed-in customer history needs real user/order data, and carrier/warehouse/gateway external acceptance needs the corresponding connections. No native mobile browser viewport is available; narrow-layout CSS and rendered components are checked in code, without claiming a live phone/browser test.
