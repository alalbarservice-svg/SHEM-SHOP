# SHEM storefront and administration

SHEM menswear storefront and protected management interface in one React 19/Vite project with a Sites Worker, D1 and R2. Hebrew RTL and English, black/gold/ivory styling, locally bundled fonts, editorial imagery, three-image hero, new-arrival and end-of-season rails.

## Development and validation

```sh
npm ci
npm run build
npm run dev -- --host 0.0.0.0 --port 4173
npm test
npm run test:admin
npm run test:operations
npm run test:management
npm run test:sites
```

Build before starting local preview because the local API loads the compiled Worker. Local preview has isolated D1/R2 data and a visibly simulated identity. Never copy its customer/order records into production. Browser fixtures live under tests/. Runtime secrets and generated build/node_modules are excluded from Git.

## Source map

- src/App.jsx and src/components/: storefront, checkout, membership and module-specific administration.
- worker/api.js: authentication, catalogue and same-origin administration.
- worker/operations.js: public quotes, collection/customer-care operations.
- worker/management.js: management CRUD/archive, variant inventory, manual orders, immutable payment confirmation, loyalty and future home-site integration.
- worker/documents.js: branded A4 invoice/order PDFs, protected R2 document cache and printable HTML.
- worker/document-assets/: embedded Hebrew-capable font sources and license.
- drizzle/: immutable production migrations.
- public/assets/: brand, editorial and product assets.
- docs/home-site-protocol.md: payment callback, email and remaining integration contract.
- .openai/hosting.json: existing Sites project binding. Reuse it; do not create another production Site accidentally.

## Orders and pricing

Product prices include VAT. Show net items and the embedded 18% VAT component, then gross items, shipping and final total. Do not add VAT again. Standard home courier: 49 ILS / 5-7 business days; express: 199 ILS / one business day. Checkout collects name, phone, email and shipping address, selects delivery, and reviews details before embedded payment.

Paid confirmation atomically updates stock, creates an immutable transaction invoice if the manager has completed issuer settings, queues the customer's A4 PDF email and a separate manager order certificate, and applies configured loyalty earnings. Order details expose invoice links, shipment/customer details, provider reference and masked payment identification. Full PAN/CVV are never stored. Provider acceptance is distinct from inbox delivery.

## Production boundaries

The storefront is public; administration requires a platform-authenticated user ID and the authorized manager email your.shem88@gmail.com. Production bootstrap is disabled. The Sites owning account is alalbar.service@gmail.com; business contact settings do not transfer ownership. The GitHub source account requested by the user is alalbar.service@gmail.com.

The future home site, actual payment provider, verified email sender/credentials and warehouse integration have not been connected. Keep live checkout disabled until real sandbox reconciliation passes. No live charge or delivered email is claimed. Replace demonstration merchandise/photos and complete business/policy/accounting/accessibility review before commercial launch. Refund/chargeback automation and authenticated storefront club-credit redemption are not implemented. Archive preserves commercial records; paid financial documents/system audit cannot be destructively edited.

## Repository organization

For the requested SHEM SHOP monorepo, place this whole project under SHEM/. Keep the future home-store application in a separate sibling directory. The store and management share this source tree, not separate drifting copies. See AGENTS.md for approved design and operating constraints.
