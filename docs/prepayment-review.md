# Pre-payment review

POST /api/checkout-review validates customer fields and terms, computes the canonical quote, and resolves current source SKU/variant data. SHEM sends a timestamped, path-bound signed request to SHIMI /api/channel/review. SHIMI selects products only through manually configured product_links and explicit variant maps, checks current availability and effective SKU/prices, and returns the identical SHEM summary after validating integer-cent arithmetic.

SHEM alone determines coupons, shipping, embedded 18% VAT and the final amount. SHIMI does not apply its independent shop coupons to a SHEM order. No customer data is transmitted to SHIMI at this stage. Public responses omit source links and SHIMI mapping identifiers.

The client invalidates confirmation after cart, shipping, coupon, customer or terms changes and aborts outdated review requests. Its summary shows authoritative SKUs, quantities, prices and final amount. A five-minute stateless HMAC review token accompanies the later payment request. If a provided token expires or the quoted order changes, the request returns 409 before creating an order/reservation. Legacy callers without a token still undergo normal canonical payment quote/mapping validation.

The review does not create orders, holds or payment sessions. Product price/SKU synchronization and link status updates are permitted. Payment remains disabled without an official provider integration. These reviews are transient channel selections; they do not write to a customer's independent SHIMI browser cart and do not create a second fulfilment order.

Scalability: one bulk signed source snapshot for a cart of up to 100 lines, one synchronization per unique source product. The regression test exercises 100 distinct linked products and asserts one bulk source request.

Acceptance boundaries: the automated test uses two isolated Miniflare D1 databases and the real API handlers, with synthetic stock/customer data. It is not a live browser/customer walkthrough. The Sites browser skill is unavailable in this session; the live public API probe returned HTTP 403. Current runtime payment adapter is disabled and SHIMI has custom/private access. Direct private SHIMI payment iframe access for anonymous SHEM customers remains to be resolved/verified before launching actual payment.
