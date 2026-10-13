# SHEM / SHIMI channel

SHEM is the authoritative inventory and price source. SHIMI has a separate catalogue, management interface and database. Independent SHIMI products retain local SKU/prices/inventory. Linking is explicit and one-to-one; product titles, brands, images and descriptions are never copied.

## Link workflow
Copy the protected stable URL `/api/channel/source/{productId}` from the SHEM management product card. Paste it in a SHIMI card, preview it, map every size/color variant explicitly and confirm. Source origin is pinned to `SHEM_ORIGIN`; arbitrary URLs, extra queries, different origins and unauthenticated exports are rejected. URLs themselves carry no credential and are not returned by public catalogue APIs. Disconnecting unpublishes the SHIMI product.

SHEM price edits push an authenticated refresh. SHIMI catalogue/quote/checkout reads refresh linked prices and fetch transient availability. Failed refresh fails closed with zero purchase availability. SKU, current effective price and previous/list price synchronize from SHEM. A variant or size mapping change requires an explicit unlink/relink.

## Authentication
`CHANNEL_SECRET` is a server-only shared HMAC-SHA256 key, separate from `HOME_PAYMENT_SECRET`. For channel POSTs, sign `timestamp + '.' + method + '.' + pathname + '.' + rawBody`; use `x-channel-timestamp`/`x-channel-signature`. Signatures expire after five minutes and are compared without early character exit. Source/target origins are set in server environment, never from user-supplied request host values. Private SHIMI access also requires its platform service credential in SHEM's server environment, not browser code.

## Source price and order ownership
A SHEM-origin payment session verifies the exact customer/items and final amount/shipping/discount cents against the persisted SHEM order over an authenticated server request. SHIMI does not recalculate or stack its local promotions on SHEM orders. All calculations retain VAT-inclusive product prices and embedded VAT; shipping is added once. New pricing causes a fresh checkout, not mutation of an already-created provider session.

## Inventory
Reservations use D1 rows with a 20-minute expiry, atomic CHECK guards and per-product/per-variant constraints. Both stores consume the same SHEM stock for linked products. Independent SHIMI products reserve local stock. Reservations are released on rejected session creation and expire automatically in availability queries. Verified payment commits stock once. Late payments after reservation expiry, source downtime, and distributed commit conflicts are reconciliation cases, never success fabricated by the browser. Central-first commits are idempotent; failed local completion can retry without deducting central stock again. Fulfilment of SHEM-origin orders remains in SHEM.

## Provider adapter boundary
No real provider is connected. The manager can save provider name and merchant identifier, but that does not enable charging. `PAYMENT_ADAPTER_ENABLED=true` must only be set after an official provider-specific adapter and sandbox tests pass. Configure `PAYMENT_ADAPTER_URL`, `PAYMENT_ADAPTER_SECRET` and `PAYMENT_CHECKOUT_ORIGIN` as server environment values. The adapter receives ILS amount cents, customer, stable idempotency key and callback URL; it returns an HTTPS `checkoutUrl` on the approved provider origin. It must itself verify the actual provider webhook before signing a callback. The storefront never collects PAN/CVV. Collector/business identity and accounting/document responsibilities must be confirmed before activation.

Provider callbacks use the existing payment signature format `timestamp + '.' + rawBody`, with `x-shem-timestamp`/`x-shem-signature`, signed using the separate adapter key. Event fields: `orderId` (payment session ID), `status=paid`, `currency=ILS`, exact `amountCents`, unique `transactionId`, optional masked `paymentDetails` (last4/holder/brand/approval/installments). Browser redirects/messages never mark payment paid.

Sessions persist amount, source, order ID, creation state, provider reference and reconciliation state. Confirmed-but-unsynchronized payments can be retried from management. Duplicate provider callbacks do not charge or deduct stock again. Automatic recurring retry schedules, automated refunds/chargebacks and provider-specific settlement queries remain future work; do not describe these as implemented.

## Production boundaries
Both Sites are separate deployments/D1/R2 resources. SHEM sharing remains public, SHIMI owner-only until sharing is authorized. Management requires the platform-authenticated manager email your.shem88@gmail.com with bootstrap false. New SHIMI private audience access is separate from manager authorization. No catalogue was populated with assumed stock, affiliation or invented real merchandise; populate real SHIMI cards and manually link SHEM cards before enabling checkout. Email delivery remains disabled until actual sender-domain credentials are configured.
