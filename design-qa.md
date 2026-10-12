# SHEM design and interaction QA

final result: passed

## Evidence

- Visual truth: `qa/approved-reference.png` (1487 × 1058), the user's exact selected attachment.
- Implementation: `qa/desktop-final.png` (1348 × 926), captured from the cloud browser on the Hebrew home route, bag empty, no modal.
- Browser-reported CSS viewport: 1363 × 936; body width 1348. Screenshot service supplies a slightly reduced raster. Source was normalized to 1333 px content width and cropped to the 926 px captured height, with the implementation's scrollbar column excluded. This is a proportional comparison, not a claim of exact pixel replication at the original viewport.
- Full-view combined comparison: `qa/comparison.png`.
- Focused combined comparison: `qa/focused-comparison.png`, header/logo and hero headline. Focused review was necessary to inspect the tiny slogan and font wrapping.
- Mobile: `qa/mobile-final.png`; actual app rendered inside the 390 × 844 CSS-pixel test iframe. Body width 375 including the classic scrollbar allocation. No horizontal overflow. This checks responsive CSS, not physical-device/browser compatibility.
- Browser implementation opened at the required local preview address and marked as a deliverable. Preview remains running.

## Comparison history and fixes

1. Mobile inspection found a P1 asset crop problem: reusing the desktop diptych hid the model's face. Generated and installed a dedicated vertical hero asset; subsequent browser screenshot shows the whole head and upper body.
2. Mobile inspection then found a P2 contrast problem over the ivory trousers. Added a dark, flat translucent background under mobile hero copy. `qa/mobile-final.png` shows legible title, subline and CTA while the portrait remains visible above the copy. This is an intentional responsive adaptation; there was no supplied mobile mock.
3. Desktop inspection found a P2 first product-section spacing drift. Reduced the heading line height, heading link height, upper padding and heading-to-grid gap. The latest combined comparison shows the four-column composition and tighter reference rhythm.
4. Focused logo inspection found clipped edges of the source slogan. Expanded the supplied raster crop and adjusted desktop/mobile logo dimensions; focused comparison shows the full slogan.
5. Final combined comparison found no remaining actionable P0/P1/P2 differences for this frontend demonstration. P3 differences are recorded below. No visual fix was made after this final comparison.

## Required fidelity surfaces

- **Typography:** Playfair Display editorial hero and story headline; Montserrat Latin UI; Arimo Hebrew UI. Fonts bundled locally. Hero keeps the exact two-line wording and navigation/content hierarchy. The generated reference's serif metrics are not perfectly identical to Playfair Display; using the brand-specified font is intentional. Minor word-width and optical-weight differences remain P3. Hebrew product heading is readable and correctly ordered.
- **Spacing/layout:** centered logo; black header; photographic diptych; text on the left-hand knit; hero occupying the main viewport; four products on white below. Desktop uses fixed minimum accessible control sizes rather than scaling every pixel of the raster. Mobile uses two product columns and a responsive drawer. No horizontal overflow at either inspected width.
- **Colors/tokens:** black #0B0B0B, charcoal #1F1F1F, gold #C9A96A, beige #E8E4DC, white #FFFFFF are explicit CSS tokens. Gold is restrained to the supplied logo and small accents; body copy uses dark-on-white or white-on-black. No gold body text on white.
- **Image quality:** real source logo crop; individually generated hero, mobile hero and four product rasters. No CSS artwork or drawn replacements. Subject, colours, visual category, composition and texture match the chosen direction. Generated product photos are illustrations, not evidence of real branded products. Future licensed product photography must replace these demo assets.
- **Copy/content:** compared above-the-fold DOM copy against the selected mock. Exact headline `DRESS / WITH INTENT.`, Hebrew subline `הסטייל שלך. בלי פשרות.`, CTA `לקולקציה`, selection heading `הבחירות שלנו`, VIEW ALL and the four product names/prices are retained. Browser currency formatting/typographic apostrophe differ from raster glyph formatting without changing amounts. Demo notices live on product/catalogue/checkout pages and do not alter the selected hero's copy.

## Interaction verification in cloud browser

- Hero CTA opens the catalogue.
- Dior filter returns one product; clearing returns four.
- Product link opens detail. Add without a size displays an error; choosing M adds the correct line.
- Quantity increase updates subtotal; two ₪199 products total ₪398.
- Standard shipping produces ₪447; express produces ₪597. Express radio change updates order summary.
- Required fields accept fictional test details; review clearly states no charge/order was submitted.
- Language switching renders English LTR copy and Hebrew RTL copy.
- Search for `shirt` returns the matching shirt.
- Mobile menu/language/close controls work; mobile size S creates a separate line from M.
- Wishlist toggle and wishlist page show the saved item; removal creates the empty state.
- Bag removal works; test lines were removed before final preview.
- Modal initially focuses close. Shift+Tab wraps to the last search result; Escape closes and restores focus to Search. Background uses inert while the dialog is open.
- Browser error/warning logs checked: no application-origin errors observed. Chrome-extension metadata errors were present and identified as browser-extension noise, not application errors.
- All inspected images loaded successfully.
- Automated commerce tests: four passed (variant merging, shipping totals, invalid options, stale persisted lines). Production build passed; Sites runtime tests passed. This is validation of the frontend foundation, not live-commerce certification.

## Follow-up polish and limits

- P3: exact editorial font width, regenerated photo framing and garment folds vary slightly from the generated mock.
- P3: large-catalogue filter URL state, per-variant stock, server search/pagination and production SEO routes remain integration work; this mock catalogue contains four items.
- Authentication, payment, inventory, mailing, support channel and real policies are intentionally unconnected. No backend success is simulated as a real transaction.
- The support page carries the known shipping/returns information and a notice that contact details are pending.
- Physical devices, other browser engines and live warehouse/payment responses have not been tested.

## Implementation checklist

- [x] Selected visual source resolved and inspected.
- [x] Custom imagery placed, desktop/mobile inspected.
- [x] Full and focused combined comparisons reviewed.
- [x] P0/P1/P2 visual findings fixed and recaptured.
- [x] Core purchase-interface journey tested without live transactions.
- [x] Build and commerce checks pass.
- [x] Preview kept open; demo scope documented.

## Refinement pass 2026-10-06
User requested updates override the previous section heading and button treatment while retaining the approved dark layout. Generated 1254px transparent dimensional gold monogram with live font wordmark replaces 180px raster composite. Shared button styles updated across routes; focus, pressed feedback and reduced-motion rules included. New arrivals and compact clearance rails support horizontal keyboard/touch scrolling and arrow controls. Pointer-reactive drawer background, menu row movement and restrained hero parallax added. No long-dash punctuation remains in src.

Browser checks passed: desktop updated heading/navigation/monogram; rail moved from scrollLeft 0 to 96; mobile width 375 equals scrollWidth 375; menu opens, Escape closes; club enrollment validates without transmission; local editor save confirmation; sale product image and crossed previous price render, bag subtotal is 149, test item removed afterward. Screenshot: qa/refinement-desktop.png. Membership backend, authenticated admin and live promotions remain unconnected prototype features. No conversion improvement was measured.

## Server-backed admin pass 2026-10-06
Added branded RTL administration with products, brands, product-level inventory, images, orders, customers, membership, promotion records, store copy and settings. D1 replaces local content authority; R2 handles uploaded product photos. Private Site identity binds the first authorized owner once, subsequently rejecting other stable IDs. Owner-private hosting policy preserved; contact email is configured, ownership transfer is explicitly not claimed.

Checks passed: protected API rejects anonymous and foreign identities, cross-origin writes, invalid price and invalid membership consent. D1 product editing and hiding propagate to public catalogue; club consent is stored once per email; R2 upload/read returns a PNG. Browser demonstrated product save and persistence after reload, server-saved club copy and mobile navigation at 375px with equal content width. Local preview data is isolated and never seeded into production. Live orders/payment/warehouse synchronization and coupon redemption remain pending integrations. Screenshot: qa/admin-desktop.png.

Publication check found that the user had made the storefront public. Production first-user bootstrap was therefore disabled before deployment; management requires an explicit server-side ADMIN_USER_IDS allowlist. No management identity has yet been provisioned for the requested new email, and ownership was not transferred. Public sign-in/account verification is the remaining setup step.
