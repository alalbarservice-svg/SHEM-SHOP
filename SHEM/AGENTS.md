# Prototype Instructions

Run the local server yourself and open the preview in the browser available to this environment. Do not give the user server-start instructions when you can run it.

Before making substantial visual changes, use the Product Design plugin's `get-context` skill when the visual source is unclear or no longer matches the current goal. When the user gives durable prototype-specific design feedback, preferences, or decisions, record them in `AGENTS.md`.

When implementing from a selected generated mock, treat that image as the source of truth for layout, component anatomy, density, spacing, color, typography, visible content, and hierarchy.

Build app UI in `src/`. Keep `.openai/hosting.json`, `worker/index.js`, `scripts/prepare-sites-build.mjs`, and `tests/sites-worker.test.mjs` intact so the same local prototype can be handed to Sites. Before a Sites handoff, run `npm run build` and `npm run test:sites`; the build must leave `dist/client/index.html`, `dist/server/index.js`, and `dist/.openai/hosting.json`.

## Approved SHEM direction

User selected the dark Night Atelier attachment on 2026-10-06. Preserve black header, centered gold S/white SHEM raster logo, dark diptych hero with `DRESS / WITH INTENT.`, and a white four-column product area. Palette: #0B0B0B / #1F1F1F / #C9A96A / #E8E4DC / #FFFFFF. Brand fonts: Playfair Display and Montserrat; use Arimo for Hebrew. Men only; Hebrew default plus English. Shipping standard ₪49/5–7 business days, express ₪199/one business day. Returns within 14 days of receipt by home courier collection. Demo products/photos must be replaced before commerce launch; no claims of verified products/official affiliation. Never turn demo review into a fake paid-order success. Keep account/mailing/stock/payment integration status explicit until connected.

## User refinement 2026-10-06
Use ASCII hyphen only in UI copy. Premium dimensional high resolution monogram, responsive premium buttons, subtle pointer feedback honoring reduced motion, new arrivals and compact end-of-season carousels. Members club in navigation and local demonstration editor; no live enrollment claims.

## Server-backed management
User authorized server/admin implementation and requested admin contact your.shem88@gmail.com. Owner remains the current Sites account; do not claim ownership transfer. Maintain owner-only Site access while OWNER_PRIVATE_BOOTSTRAP is true; initial dispatch-authenticated user is permanently bound in D1, other IDs are denied. Never make the Site public or add viewers before reviewing authorization. D1/R2 and worker changes are authorized for this scope. Production migrations are immutable once applied. Local preview uses isolated simulated identity/data, not production records. No fake orders/payments or warehouse integration claims.

## Audience correction
Latest get_site confirms public audience. Production OWNER_PRIVATE_BOOTSTRAP must remain false. Management is denied until ADMIN_USER_IDS contains a verified Site-scoped authenticated user ID. Never deploy a public Site with first-user bootstrap enabled. /api/session exposes only the requesting authenticated identity for explicit provisioning, without administrative access. Current requested email is a contact, not a verified identity or ownership transfer.

## Hero carousel 2026-10-07
Keep three coordinated black/ivory menswear images cycling every 5000ms in a continuous loop with a slow elegant crossfade. Preserve fixed hero copy, responsive crops, accessible pause/manual selection, and reduced-motion support.

## Manager email authorization 2026-10-07
User explicitly authorized your.shem88@gmail.com as manager. ADMIN_EMAILS grants access only when both platform-authenticated user ID and exact normalized platform email are present. ADMIN_CONTACT_EMAIL alone never grants access. Preserve public access with bootstrap false.

## Operations and checkout 2026-10-07
Share links must display SHEM branding. Keep static Open Graph and Twitter image metadata in index.html, using the public HTTPS logo asset and real deployment domain. Include branded browser/touch icons. Never rely on client-side React updates for link crawlers.
Management share link is /admin.html, generated from the compiled app shell by scripts/prepare-admin-share.mjs with management title/description, logo and noindex. The frontend opens /admin when this entry has no hash. Sharing this public shell never grants API permissions; preserve all server-side authorization.
Share-image correction: opaque black background and generous safe margins around the complete gold monogram; use /assets/shem-share-black-v2.png for both shop and management. Use a square summary card to avoid forcing a landscape crop. User rejected clipped/transparent share branding.
Maintain real server-backed collections, customer and member profiles, service/cancellation requests, inventory by size, server-priced coupons and administrator audit records. Support email is your.shem88@gmail.com; technical WhatsApp is 052-9586766 (https://wa.me/972529586766); Instagram is new.shem. Policies are Hebrew and must disclose incomplete business identity and pending accessibility review. Never claim certified accessibility from implementation checks.
User requires automatic payment and invoices and connection controls in admin. Current integration form stores provider selection/merchant identifiers only. It is not an implemented provider adapter, payment webhook, invoice issuer, or automatic checkout. Keep those statuses inactive until provider-specific official integration and sandbox tests pass. Never collect API secrets in ordinary client settings. Exact payment provider, invoice provider and legal business identity remain required. Correct-account GitHub/server creation remains blocked: connected GitHub/Sites owner is alalbar.service@gmail.com, not requested your.shem88@gmail.com; do not imply email settings transfer ownership.

## Approved management expansion 2026-10-08
User approved module-specific create/edit/archive/restore, manual orders, immutable financial/system audit, embedded future home-site checkout and variant SKU mapping. SHEM transaction invoice template must be filled by manager; issue only after paid confirmation, populate order/customer/shipping, queue automatic email. Gross margin ~20%, editable loyalty benefits/percentages. Preserve recoverable archive semantics and atomic stock/payment/document updates. Implementation contract and remaining integration boundaries are in docs/home-site-protocol.md. No actual home site or verified email credentials are available; never claim live charge or delivered emails. Refunds and automatic member checkout redemption remain unimplemented. Browser QA data must remain separate from production.

## Invoice footer refinement 2026-10-08
User explicitly requested removal of the fixed footer sentence stating that the transaction invoice is not a tax invoice or receipt. Keep the transaction invoice title and existing document behavior; omit that sentence from generated document HTML and email.

## A4 invoice and checkout clarification 2026-10-08
User approved preserving existing VAT-inclusive product prices. Show embedded 18% item VAT, never add another 18%; shipping 49/199 is added afterward. Invoice email must attach branded A4 PDF. Orders expose customer, shipment, masked payment identification, immutable customer invoice and manager order certificate. Never store PAN/CVV. User also requested GitHub account alalbar.service@gmail.com repository display name SHEM SHOP, code under SHEM/, reserving a separate future home-store directory.

## Instagram presentation 2026-10-08
Display Instagram as a refined gold outline Instagram symbol with the configured page handle beneath it, in a centered black section above the footer. Remove the text-only Instagram utility link. Preserve accessible external-link labeling, keyboard focus, mobile spacing and reduced motion.

## SHIMI channel 2026-10-08
SHEM owns linked inventory and all SHEM checkout prices/discounts/shipping/final amount. SHIMI has an independent basic public identity and separate Site/database. Protected source product URLs are manually pasted in SHIMI cards, with one-to-one explicit variant mapping. Preserve authenticated origins, server-only HMAC secrets and public metadata filtering. Exact order snapshots are verified over the channel; reserve/commit stock once. Generic provider adapter stays disabled pending an official provider integration. SHIMI independent inventory remains local. Contract/tests and known limitations: docs/shop-channel.md, tests/channel.test.mjs.

## Demo catalogue 2026-10-08
User requested SHIMI catalogue media and an inspectable product-link demonstration. 0004 adds three visible SHIMI demo products at zero stock and one hidden linked demo pair. Keep source/target hidden, pending until a real sync, and preserve existing records/settings. SHIMI access now includes the explicitly requested manager email your.shem88@gmail.com; retain that custom audience.

## Pre-payment review QA 2026-10-09
The checkout review must obtain a canonical quote and validate linked products/variants on SHIMI before showing verified confirmation. Reviews create no orders, payment sessions or stock reservations and never enable the payment adapter. Signed review tokens expire after five minutes; provided tokens must reject changed prices/coupons before payment creation. SHIMI keeps SHEM coupon/shipping totals rather than recalculating against independent SHIMI promotions. Reviews batch source reads for up to 100 cart lines. Browser and live-site access limitations must be reported honestly; local isolated tests are not production/browser acceptance.

## Designer collections 2026-10-11
User requires six supplied Cloudinary logo pairs, logos only for customer-facing designer selectors, black logos on white menus titled מותגי SHEM, and a separate home rail with a black/white-logo band for each designer. Each /brand/:slug page groups actual catalogue products by explicit productType. Existing shirt/trousers/knit asset keys support legacy classification; unknown clothing stays generic. Preserve mixed-brand end-of-season rail and all checkout/channel behavior. Never crop, recolor or regenerate supplied logos. Keep accessible brand names in alt/aria labels and textual brand input in administration.

## Luxury layout refinement 2026-10-11
Brand bands must be pure black (#000), with a single centered high-resolution white source logo and no visible collection CTA. Give each of the six brands generous whitespace across the menu, home selectors and brand pages. Preserve original sculpted logo details and contain the entire artwork. Product cards use spacious editorial framing and subtle hover/focus lift, image zoom and a reveal link; no automatic cart addition or invented imagery. Respect reduced motion and show the link without hover on touch devices. Sparse brand rails are centered instead of leaving all products on one side.

## Edge-to-edge brand refinement 2026-10-11
Brand bands span the full page width without viewport-width overflow hacks. Retain logo dimensions and contained artwork while reducing only band vertical padding to 8px desktop / 6px mobile. White logo glow is subtle and static. The brand drawer uses two horizontal hairline separators across its three rows, no boxed logo borders, and a refined מותגי SHEM title with the SHEM wordmark in Playfair Display. Preserve accessible title naming and identical desktop/mobile behavior.

## Dedicated brand page design 2026-10-11
All six brand pages must carry a centered original white logo at the top on a full-width black masthead with subtle halo. Load that first logo eagerly. Below it use a restrained editorial collection heading, underline category tabs, a quiet sort control, numbered category sections, spacious product galleries and logo-only navigation to other designers. Keep actual catalogue products and functional filters/sorting; give empty collections a polished forthcoming state. Apply the same hierarchy on mobile without clipping logos or adding fictional brand stories/products.

## Embedded brand assets 2026-10-11
User requests logos embedded into the site instead of individual or external image requests. DesignerLogo and the SHEM header monogram use Vite ?inline imports of lossless WebP assets in src/assets/embedded-logos through src/brand-logo-assets.js. Every encoded image retains the original PNG dimensions and exact RGBA pixels. Keep source PNGs as originals, and use eager logo decoding/display rather than lazy loading. These embedded data URLs increase the initial app payload but eliminate individual logo requests; do not claim the website needs no initial download. Share preview images remain publicly hosted files for crawler compatibility.

## Header breathing room 2026-10-11
Keep the full SHEM logo and tagline inside a pure-black header with clear space above and below. Header minimum height is 108px desktop and 94px mobile; logo height follows its content with 10px vertical padding, and the monogram must not flex-shrink. Preserve the full tagline on one line without overlapping the hero or announcement.

## Customer invoice download 2026-10-12
After server-confirmed payment, navigate to /order-complete.html with order credentials in the fragment. Personal PDF access requires the order view token and paid status. Keep A4 immutable invoice; no fake invoice when business details are missing. INVOICE_DELIVERY_MODE=download disables outgoing mail. Administration lives at /admin.html with no storefront link. Custom domains: shem-shop.com for SHEM and shimishop.co for SHIMI; activate only after DNS validation. Preserve protected admin APIs.

## Registration number scope 2026-10-12
User supplied 516067873 for the SHEM transaction invoice and explicitly requires approval before adding it elsewhere. Migration 0005 updates only invoice settings and keeps other fields intact. registrationNumberScope=invoice prevents its automatic display on the separate manager order certificate. Preserve that scope when the invoice template is edited. Do not add this number to generic business settings, terms, storefront, SHIMI or other records without explicit approval. Existing immutable documents are not rewritten.

## Separate seller identities 2026-10-13
User explicitly authorizes SHEM terms and transaction invoice to use 516067873, and SHIMI terms alone to use 043644418. Preserve leading zeros as text. Each site has its own business settings row. SHIMI invoice settings, payment, catalogue and administration remain unchanged. Invoice-only registration scope on SHEM remains in place for separate manager documents.

## Customer experience approved 2026-10-13
Development and publication apply to SHEM only. Demo products will be replaced after development; do not treat their presence as a request to delete them. Preserve SHIMI SKU/variant links, canonical shared totals, embedded SHIMI payment and SHEM paid-only transaction invoice; no SHIMI source/configuration/publication changes. Add a real protected customer order area, guest private-order access, up to three product images, editable product-specific measurement/colour tables, short guest checkout, on-site help/alerts, search and conversion measurement. Exchanges always go to human support; existing returns/cancellation requests do not perform refunds. External carrier, warehouse, payment and messaging integrations stay explicitly inactive until connected. Never fabricate shipment progression, real measurements, successful charges or delivered notifications. Analytics uses affirmative visitor consent; no form/payment contents. Account sign-in uses existing Sites identity rather than inventing an email verification service.
