# SHIMI instructions
SHIMI is an independent basic menswear storefront and the home payment server for SHEM. Preserve its white/grey basic design, SHIMI branding and separate Site/database. Do not publish SHEM luxury brand names as SHIMI catalogue entries.
Reuse existing protected management modules. ADMIN_EMAILS is your.shem88@gmail.com; platform authenticated identity is required and bootstrap remains false. Preserve owner-only access until the user authorizes sharing.
Products may be independent or manually linked by a protected source URL copied from a SHEM product card. Every SHEM product/variant must be mapped before payment. Linked SKU, current price and previous price are source-controlled. Do not persist copies of SHEM inventory; signed availability and central reservations are authoritative. Independent items have local stock.
Checkout from SHEM must preserve its entire canonical order snapshot, discounts, shipping, embedded VAT and final cents. Require signed, verified payment callbacks, separate provider credentials, deduplication and reconciliation. Never store PAN/CVV or enable a generic adapter without provider-specific integration/sandbox checks. No actual provider, sender domain or verified legal issuer is configured.
Run build, test:sites and meaningful cross-store tests for channel changes. Browser QA requires the supported control-browser skill; unavailable infrastructure is not evidence of passing visual QA.

## Demo catalogue 2026-10-08
User requested SHIMI catalogue media and an inspectable product-link demonstration. 0004 adds three visible SHIMI demo products at zero stock and one hidden linked demo pair. Keep source/target hidden, pending until a real sync, and preserve existing records/settings. SHIMI access now includes the explicitly requested manager email your.shem88@gmail.com; retain that custom audience.

## Pre-payment review QA 2026-10-09
The checkout review must obtain a canonical quote and validate linked products/variants on SHIMI before showing verified confirmation. Reviews create no orders, payment sessions or stock reservations and never enable the payment adapter. Signed review tokens expire after five minutes; provided tokens must reject changed prices/coupons before payment creation. SHIMI keeps SHEM coupon/shipping totals rather than recalculating against independent SHIMI promotions. Reviews batch source reads for up to 100 cart lines. Browser and live-site access limitations must be reported honestly; local isolated tests are not production/browser acceptance.

## Customer invoice download 2026-10-12
After server-confirmed payment, navigate to /order-complete.html with order credentials in the fragment. Personal PDF access requires the order view token and paid status. Keep A4 immutable invoice; no fake invoice when business details are missing. INVOICE_DELIVERY_MODE=download disables outgoing mail. Administration lives at /admin.html with no storefront link. Custom domains: shem-shop.com for SHEM and shimishop.co for SHIMI; activate only after DNS validation. Preserve protected admin APIs.

## Separate seller identities 2026-10-13
User explicitly authorizes SHEM terms and transaction invoice to use 516067873, and SHIMI terms alone to use 043644418. Preserve leading zeros as text. Each site has its own business settings row. SHIMI invoice settings, payment, catalogue and administration remain unchanged. Invoice-only registration scope on SHEM remains in place for separate manager documents.
