# SHEM checkout and document integration

Approved 2026-10-08: payment appears inside SHEM; a future home site performs the charge. A manager maps products and size/color variant SKUs. No home site is connected yet.

## Server secrets

- `HOME_CHECKOUT_URL`: HTTPS session creation URL, on the origin saved in management.
- `HOME_PAYMENT_SECRET`: shared HMAC signing secret.
- `RESEND_API_KEY`: Resend email adapter credential.
- `MAIL_FROM`: sender on a verified sending domain.

Never store credentials in client code or ordinary management settings. Enable checkout only after sandbox verification with the actual home site and provider.

## Session creation

SHEM POSTs JSON to the home checkout URL, signed with `x-shem-timestamp` (Unix seconds) and `x-shem-signature` (hex HMAC-SHA256 of `timestamp + '.' + rawBody`).

```json
{
  "protocol": "shem-home-v1",
  "orderId": "stable-order-id",
  "currency": "ILS",
  "amountCents": 24800,
  "customer": { "name": "Example", "email": "example@example.test" },
  "items": [{ "productId": "shem-id", "sku": "123405", "homeSku": "123405-S-B", "variantId": "black-s", "size": "S", "color": "black", "qty": 1, "priceCents": 19900 }],
  "shippingCents": 4900,
  "discountCents": 0,
  "callbackUrl": "https://shem-menswear.genial-comet-8090.chatgpt.site/api/home/payment-callback"
}
```

The home site must validate the signature and timestamp, deduplicate by order ID, reserve available stock, and match exact SKUs, variants, prices, shipping, discount, currency and total. Reject mismatches. Return `{ "embedUrl": "https://home.example/checkout/session" }` on the configured HTTPS origin. The payment provider must support embedded checkout and 3-D Secure. Do not store or proxy card data in SHEM. Clearly display the actual collecting merchant. The relationship between collecting merchant and document issuer requires business/accounting confirmation before launch.

## Payment callback

After provider verification, the home server POSTs the same signing headers with a timestamp within five minutes:

```json
{ "orderId": "stable-order-id", "status": "paid", "currency": "ILS", "amountCents": 24800, "transactionId": "unique-provider-reference" }
```

A browser redirect or iframe message never marks an order paid. SHEM validates the callback and atomically updates stock, records payment, creates a transaction document/email job if issuer details are complete, and credits configured loyalty earnings. Repeated matching callbacks do not repeat deductions or documents. The home site must retry failed callbacks and reconcile payments that succeeded at the provider but could not be applied in SHEM, including stock conflicts. Refund/chargeback callbacks are not implemented; cancellation status does not issue a refund.

## Documents and email

Fill issuer fields under management > payments and invoices > SHEM transaction invoice template. Documents snapshot issuer, customer, SKU/size/color, items, shipping, discount, total and payment reference. A transaction invoice is not a tax invoice or receipt. A paid-order action can create a missing document after issuer details are filled.

Payment confirmation attempts email automatically through Resend. The invoice is generated as a branded, embedded-font A4 PDF and attached to the customer email. The identical immutable PDF is cached in protected R2 storage and linked from authenticated order details. A separate A4 order certificate, containing customer/shipping/payment identification, is emailed to ADMIN_CONTACT_EMAIL (default your.shem88@gmail.com). Printable HTML remains available. Pending/failed jobs can be processed manually with backoff, a sending lease and provider idempotency. Provider acceptance is not inbox delivery. No background schedule or delivery webhook is configured. Retries outside the idempotency window require reconciliation.

## Loyalty and verification

Gross margin defaults to editable 20%. Earn rate, redemption cap/minimum, sale exclusion, stacking, waiting days, expiry, VIP thresholds and birthday amount are editable. VIP evaluation uses paid orders in the preceding 365 days. Managers can reserve/release member credit on unpaid manual orders; automatic storefront redemption awaits authenticated member ownership. Birthday credit is a manager action on the birthday, once per year. Marketing consent is separate.

Tests use isolated Miniflare D1/R2 and signed sample callbacks. Browser checks use local preview records. Actual charging, email sending/delivery, warehouse sync, accounting approval and production accessibility certification remain unverified.

## Pricing and payment identification (2026-10-08)

User clarified that existing product prices include VAT. Keep prices unchanged. Extract embedded items VAT at 18/118 from discounted gross item cents, with integer-cent rounding; display net items, embedded 18% VAT, gross items, shipping separately, and final gross items plus shipping. Shipping defaults: standard 4900 cents / 5-7 business days; express 19900 cents / one business day. Checkout collects name, email, phone, city/street and optional apartment/postcode, then reviews details before showing payment.

The signed paid callback may include `paymentDetails`: `{ "method":"card", "last4":"1234", "cardholderName":"Example", "brand":"Visa", "authorizationCode":"provider-approval", "installments":1 }`. Only these masked identification fields are stored; last4 must be exactly four digits. Full PAN/CVV are never collected by SHEM. The actual collecting provider must return masked card identification and holder name when supported. Missing data is displayed as unavailable; managers may record confirmed transaction identification on paid orders with an audited edit. This edit does not change amount, settlement status or the immutable invoice.

## Implemented SHIMI channel
The home-store implementation now lives in the separate SHIMI project. The expanded inventory/link/provider contract is in shop-channel.md. Actual provider charging remains disabled.
