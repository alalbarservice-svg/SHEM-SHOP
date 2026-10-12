# SHEM / SHIMI - invoice download and domain preparation

Release: 2026-10-12
SHEM version 27: 64db0dfb1ee24538c5dda2fe49b79395e90f6754
SHIMI version 7: 8c69fe6998bd9dd0fd0d8d02d88d251e70359bac

After server-confirmed payment, the checkout navigates to /order-complete.html with a private order token in the URL fragment. SHEM uses the requested heading "תודה שרכשת אצלנו SHEM מותגים" and the line "לצפייה והורדה של החשבונית לחצו כאן", with a PDF viewing button and a download action. SHIMI independent checkout uses its own store name. Unpaid/unverified orders do not render purchase-success copy. Missing invoice business configuration displays document-unavailable state rather than an invented invoice.

PDF access requires both paid order status and its private view token. Viewing is inline; downloading uses attachment disposition. Both deliver the same stored A4 PDF. Missing or wrong tokens return 404; unpaid or missing documents return 409. INVOICE_DELIVERY_MODE=download disables outgoing emails. Generation remains automatic on verified payment when the business template is complete. PDFs remain available through protected manager order records.

The two separate admin entries remain /admin.html under the respective storefront host. Storefront links to administration were removed. Existing server-side manager authorization and noindex admin metadata remain. SHIMI retains its prior custom audience; this release does not widen site access.

Domains shem-shop.com and shimishop.co were attached to the existing Sites and are awaiting DNS and SSL validation. DNS-CONFIG.txt contains the exact returned A and TXT records. Existing server-to-server service origins remain operational while DNS is pending. Trusted public domain aliases are now accepted for product link URLs, payment callback validation and iframe frame-ancestors. Unconfigured origins remain rejected.

Validation: both production builds passed. Before final changes 23 SHEM and 19 SHIMI automated tests passed; domain-specific tests then passed for each. After final document issuance changes, 12 relevant tests per store passed, covering customer invoice protection, unavailable-business-details recovery, mail suppression, custom domains, product linking, payment callbacks, management and Sites routing. No real payment, live paid-order record, live invoice issuance or external mail delivery was triggered. Browser visual QA remains unavailable because control-browser is absent.

Remaining setup: complete invoice business identity and configure DNS at the domain registrar. Live payment provider remains disabled pending its own integration.
