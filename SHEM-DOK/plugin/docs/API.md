# SHEM DOK integration contract

Version: 1.0.1.

Base path: `/wp-json/shem-dok/v1/`.
All endpoints require the `manage_woocommerce` capability. WordPress cookie requests must carry a REST nonce. External server-to-server clients may use WordPress's normal HTTPS Application Password authentication with an appropriately scoped WordPress account; never ship its credentials to browser JavaScript.

| Method | Route | Result |
| --- | --- | --- |
| GET | state | Settings and engine availability |
| POST | settings | Persist `{ defaults, auto }`; automation off initially |
| GET | documents?page=1 | Private archive metadata, 30 rows per page |
| GET | document/{id} | Payload, version, authenticated PDF download URL |
| POST | issue | `{requestId: UUID, document: snapshot}`; retries with same UUID return existing document |
| POST | document/{id}/save | `{version, document}`; optimistic concurrency, same number |
| POST | document/{id}/delete | `{version}`; permanently removes payload/PDF, reserves number/source key |
| GET | order/{id} | Paid WooCommerce order mapped to a snapshot, including sourceOrderId |

A snapshot has issuer fields `business`, `businessId`, `address`, `phone`, `email`, `title`, `vat`; customer fields `customer`, `customerPhone`, `customerEmail`, `customerAddress`; `orderDate` in YYYY-MM-DD, `orderNumber`; `items` with `name`, `sku`, positive `qty`, VAT-inclusive `price`; `discount`, VAT-inclusive `shipping`, `description`, `supply1`, `supply2`; and `method` card/paypal with `holder`, `brand`, `last4`, `installments`, `reference`, `paypalEmail` as applicable.

`lineGross` is optional **integer cents**, used for canonical WooCommerce line totals whose division by quantity may not yield an exact two-decimal unit price. It is removed when unit price/quantity changes in the manual editor. All displayed/charged totals are derived from integer cents; VAT is extracted, never added a second time. Changing a snapshot does not update the WooCommerce order itself.

`taxCents` is optional canonical integer-cent included tax, retained from a WooCommerce order to preserve line-rounding differences. It must be between zero and the gross total. The editor removes this override when a financial input, item quantity/price or the item list changes, then recalculates tax. Nonfinancial edits preserve it. Woo mapping rejects significant discrepancies against the configured VAT rate rather than silently relabeling exempt or mixed-rate orders.

A sourceOrderId obtained from the order endpoint makes issue use the same `woo:{id}` deduplication key as automatic payment handling. The server rechecks paid status, currency, mapped gateway, transaction reference, gross total and included tax before linked issuance. Reusing a deleted source key does not regenerate a deleted document.

Gateway IDs are explicitly mapped using the `shem_dok_gateway_methods` PHP filter. Built-ins: `stripe` / `woocommerce_payments` -> card and `paypal` / `ppcp-gateway` -> paypal. Unknown IDs fail closed. The installer must verify that the enabled payment modes of the chosen gateway match this mapping; a gateway that also provides bank transfers or wallets needs a per-order mapping.

For gateway-specific masked fields, use the `shem_dok_order_snapshot` PHP filter. No generic gateway meta key is assumed. Payment confirmation remains the gateway's responsibility; the plugin never accepts a browser redirect as payment evidence.

Downloads use the returned WordPress admin-post URL, a fresh per-document nonce and an authenticated manager session. This is not a public customer document API. Add a separately authorized customer-ownership endpoint if customer self-service downloads are required.

No third-party webhook is exposed anonymously. For external automation, use the authenticated API or the WooCommerce payment-complete event. The installer owns scheduler monitoring, gateway mapping and duplicate-issuer checks.
