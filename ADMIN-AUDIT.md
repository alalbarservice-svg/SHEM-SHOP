# SHEM - management verification, 2026-10-07

## Implemented and verified

| Area | Actual capability | Validation |
| --- | --- | --- |
| Authorization | Platform authenticated identity and exact manager email allowlist; anonymous users denied | Isolated Worker tests |
| Products and stock | Persistent catalogue, images, unique SKU, prices, sizes, stock by size, stale-edit rejection | Worker tests; screen navigation |
| Collections | Persistent draft/published collections with existing-product selection | Worker tests; browser create/save and navigation with populated records |
| Customers | Private service profiles, tags, notes, paid-order totals, CSV | Worker tests; browser create/save |
| Members | Consent-based enrollment, unsubscribe, separate standard/VIP profile and generated card number | Worker tests; no implied financial benefits |
| Promotions | Server-calculated quotes, current price, date window, minimum subtotal, exclusion of sale items | Worker tests including client tampering and insufficient stock |
| Service | Public cancellation/return request with reference; private administrator status and notes | Worker tests |
| Control | Real database/media checks, stock status, incomplete business details and disconnected providers | Worker tests; browser screen checks |
| Audit | Successful administrator mutations recorded with authenticated actor | Worker tests |
| Payment/invoice settings | Provider and merchant/account selection persisted on server; active status explicitly false | Worker tests; browser save and reload |
| Store information | Footer terms/privacy/returns/payments/accessibility/contact/cancellation pages, Instagram, support email and WhatsApp | Source review; pending full assistive-technology certification |

All 18 management sections opened in the isolated browser preview. A crash switching from populated customer profiles to collections was reproduced and fixed by remounting section-specific operations state. Browser retest created both records and switched between them successfully. Local test records are separate from production data.

Test commands: npm test, npm run test:admin, npm run test:operations, npm run test:sites. Eleven top-level tests passed; the two Worker tests contain multiple real API assertions and use isolated D1/R2. This is not proof of live payment-provider or invoice-provider behavior.

## Still blocked or not implemented

No payment adapter, verified payment webhook, invoice adapter, email delivery, warehouse synchronization, financial refund action or automatic order completion exists yet. The integration screen is configuration/readiness, not a working connection. No API key is stored there. No new GitHub repository or server project has been created under your.shem88@gmail.com because currently connected ownership belongs to a different account.

Business legal name, registration number, address and business contact details must be completed before commerce launch. Accessibility targets Israeli 5568 AA; no professional certification is asserted. Return collection fee/service areas need business confirmation. Demo catalogue must be replaced with real products.

## Required next implementation for automatic commerce

1. Identify exact payment provider and chosen invoice provider, official API documentation, merchant/account identifiers, sandbox credentials and production account approval. Supply credentials through server secrets only.
2. Create server-priced checkout and a stock reservation with expiration. Associate one immutable checkout identifier with one payment session; reject stale or changed stock.
3. Hosted payment page must keep card details with the provider. Browser success redirect never marks an order paid.
4. Verify the provider callback signature and independently verify paid transaction, currency, merchant and amount using the provider's documented protocol. Deduplicate the provider transaction/event identifier.
5. Commit order/payment/stock transition once and persist an invoice job in the same database transaction. Duplicate callbacks must not deduct stock twice or create another job.
6. Invoice worker uses a stable order-based idempotency key and reconciles uncertain provider responses before retries. Document type/tax calculations require the legal business status. Persist document number, provider identifier and secure customer link.
7. Retry transient invoice failures with bounded backoff; expose pending/failed invoices and audited safe retry in admin. Paid orders remain paid when invoicing fails. Never initiate a second payment as an invoice retry.
8. Connect transactional delivery, reconciliation and cancellation/refund/credit-note workflows. Avoid card data or credentials in logs, URLs and ordinary settings.
9. Sandbox acceptance: successful payment, refusal, abandonment, duplicate callbacks, wrong signature/amount/currency, insufficient stock, invoice timeout and recovery, refund and credit note. Then one authorized real transaction and reconciliation.

These are implementation acceptance criteria, not claims of currently running automation.

## Product roadmap

Immediate launch priorities: provider integrations; verified business identity; real catalogue/media; warehouse API ownership; delivery-service coverage; professional accessibility review; correct hosting/repository account. Follow-up: server pagination for growing datasets, atomic compare-and-swap editing, role-specific permissions, immutable financial ledger, scheduled reconciliation, backup/restore exercises, variant image galleries, order fulfillment labels/tracking, invoice and credit-note controls, explicit membership benefit rules and customer-data retention policy.

## Policy references reviewed

- Israel Consumer Council apparel guidance: https://www.consumers.org.il/category/clothing_issue
- Government consumer guide: https://www.gov.il/BlobFolder/generalpage/information-olim-consumerism/he/smart-consumerism-he.pdf
- Chamber of Commerce remote-purchase obligations: https://www.chamber.org.il/serviceslobby/legal/1857/2282/
- Knesset cancellation-channel amendment: https://main.knesset.gov.il/Activity/Legislation/Laws/Pages/LawBill.aspx?lawitemid=2002974
- Government website accessibility guidance: https://www.gov.il/he/pages/website_accessibility?chapterIndex=1
- Government accessibility declaration guidance: https://www.gov.il/he/pages/declaration_website_accessibility?chapterIndex=1
- Israeli 5568 part 1 September 2023: https://www.gov.il/BlobFolder/legalinfo/israeli_accessibility_standards_pdf/he/sitedocs_si-5568-1-september-2023.pdf
- Privacy Authority collection notice: https://www.gov.il/BlobFolder/legalinfo/duty_to_notify/he/notify13.pdf

Some government documents were available through indexed excerpts while direct retrieval returned 403. Policies paraphrase those sources; they are not an independent legal opinion or compliance certificate.
