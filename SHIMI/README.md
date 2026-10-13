# SHIMI
Independent basic storefront and protected administration. Source checkout is separate from SHEM; no production records or secrets are included.

## Development
`npm ci`, `npm run build`. Management entry: `/admin.html`; storefront entry: `/`. The protected admin authorizes platform-authenticated your.shem88@gmail.com. Source Site private sharing is a separate authorization boundary.

## Products
Independent products use their own SKU, prices and stock. Linked products use a source URL copied from SHEM management, with explicit per-variant mapping. Title, brand, images and descriptions stay independent. Availability is read from SHEM; it is not stored as a second stock ledger. Disconnecting a product unpublishes it.

## Payments
The provider selection and merchant identifier panel is available. Charging stays disabled until an official provider-specific adapter is connected and sandbox-tested. The generic server adapter contract is described in `docs/shop-channel.md`; no live payment success or email delivery is claimed.

## Checks
`npm run test:channel` exercises both roles with isolated databases and a mock provider. `npm run test:management`, `npm run test:operations`, `npm run test:admin`, `npm run test:sites` cover inherited management/storage behavior.
