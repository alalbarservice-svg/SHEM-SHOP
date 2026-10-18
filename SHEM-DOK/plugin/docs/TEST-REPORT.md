# SHEM DOK 1.0.1 - verification

Verified on 2026-10-16. No live site, store, gateway or external messaging service was installed, modified or connected.

## Fixes made during QA

- Serialize default-field saves, including two fast checkbox changes while an earlier request is pending. Preserve new edits instead of replacing local defaults with an older response.
- Block interacting with the editor during a pending document operation; restore interaction after success or error.
- Validate issuer/customer, calendar date, email, quantities, two-decimal inputs, discounts, card last four and instalments before issuing. The local preview uses the same editor validation as the server interface. A shipping charge cannot conceal a discount greater than the items subtotal.
- Return and display the canonical saved document after server normalization.
- Support per-item defaults through item index 199, matching the 200-item limit. Removing a row shifts its defaults correctly.
- Check transaction start/commit errors and archive lookup errors instead of reporting successful issuance after a failed DB operation.
- Explicitly map supported gateway IDs; unknown IDs no longer become credit cards by assumption. Woo snapshots never inherit another payment's saved manual default fields.
- Preserve original Woo included-tax cents across normal line-rounding differences, while rejecting significant rate/tax mismatches. Financial edits remove the tax override and recalculate it.
- Recheck source order currency, gateway, paid/reference state and both gross/tax before linked manual issuance.
- Add a standards-compliant cryptographic UUID fallback for browsers without crypto.randomUUID.
- Serialize local preview writes with Web Locks where available. This is a convenience preview, not a substitute for the server's archive/numbering.

## Executed evidence

| Check | Result | Evidence |
| --- | --- | --- |
| PHP domain validation/math/template | PASS | tests/domain.php; VAT 118 = 100 + 18, discount/shipping, canonical cents, invalid dates/card numbers/discount/fractional cents |
| Archive and numbering service | PASS, isolated DB double | tests/archive.php; 7001 onward, duplicate event, same-number edit, stale revision, delete/no reuse, transaction start/commit failure rollback, capability gate |
| Woo order mapping | PASS, order/item doubles | tests/woocommerce.php; exact gross/tax/coupon/shipping, source order, rejected unsupported gateway/currency/fees/tax, preserved tax rounding, no manual payment-default leak |
| Automatic paid flow | PASS, Woo/scheduler/DB doubles | tests/paid.php; off/unpaid/no-reference do nothing, duplicate paid event issues once, bounded retry, error clear on successful retry |
| Server editor interactions | PASS, jsdom/mocked REST | tests/editor.cjs; VAT, payment block replacement, delayed double default save, issue/edit/archive/new |
| Actual local HTML browser flow | PASS | tests/preview-browser.cjs; file:// in Chromium 145, 1440x1000 and 390x844, first screen, editing, validation, local persistence after reload, archive reopen, JSON backup download, reprint/no new number, deletion/no reuse |
| Cross-tab local preview | PASS | Two Chromium tabs issue simultaneously -> 7001/7002; stale edit rejected without overwrite |
| Page identity, meaningful render, overlay, runtime errors | PASS | Expected Hebrew page title, rendered form/document, no error overlay or JavaScript page errors |
| Responsive visual inspection | PASS, sampled viewports | Desktop, mobile form and mobile document inspected; no viewport-wide horizontal overflow |
| Browser print path | PASS | Embedded image decoded, print iframe contains document only; print invocation intercepted in test; its actual markup rendered to one-page A4 Chromium PDF. Native Windows print dialog itself not tested |
| Server PDF engine | PASS | Real mPDF 8.3.1; one-page A4 and 65-line/four-page PDF, repeated table headers, Hebrew/logo/totals/footer inspected |
| Distribution | PASS | ZIP integrity; PDF engine/fonts bundled; original logo embedded in offline preview |

Environment: PHP 8.5.11 standalone runtime, mPDF 8.3.1, Chromium 145 headless with Playwright. Browser plugin unavailable, so regular Playwright was used. Local browser tests needed no preview server; they opened the actual downloadable HTML file. Screenshots and temporary QA fixtures are not packaged.

## Repeat tests

Run domain.php, archive.php, woocommerce.php and paid.php using PHP CLI with gd/mbstring and the bundled vendor directory. Run `node tests/editor.cjs` with jsdom available (`JSDOM_PATH` may point to its module).

Generate the standalone file with `python3 tools/build-preview.py /absolute/path/SHEM-DOK-preview.html`. Browser test: `node tests/preview-browser.cjs /absolute/path/SHEM-DOK-preview.html`, with Playwright/Chromium installed. Optional environment variables: `PLAYWRIGHT_PATH` and `CHROME_PATH`. The browser test clears only the local test browser profile's preview data.

## Not run / installer acceptance required

- Actual WordPress/WooCommerce/MySQL or MariaDB installation, activation/dbDelta and HPOS runtime. No DB daemon/site/credentials were available. Doubles do not prove real storage engine behavior.
- Concurrent PHP processes against a real InnoDB database; DB reconnect/unknown COMMIT outcome; host memory/max_allowed_packet limits.
- Authenticated WordPress REST/nonces, real role boundaries, anonymous/stale-nonce denial and private download end-to-end.
- Installed gateway's sandbox payment and webhook, gateway-specific masked metadata, server cron/Action Scheduler runner and duplicate invoice-provider coordination.
- Windows/Chrome native print dialog, Safari/Firefox/iOS and target WordPress admin visual integration. Preview data is local to its browser/file origin; deletion of browser data/file-origin changes can remove access to it.
- Customer self-service PDF access, outgoing email/WhatsApp and refunds are not implemented in this manager-only document package and were not requested for activation.

The corrected package is ready for the integrator's staging validation, not certified for unattended production before those checks pass. Automation remains off by default.
