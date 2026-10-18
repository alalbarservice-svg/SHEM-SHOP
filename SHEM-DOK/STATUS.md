# SHEM DOK 1.1 - staging status (18.10.2026)

Source: SHEM-DOK/plugin (no vendor, no secrets). Tests: unit (php), e2e (python, 193 checks, local staging only), ui (playwright, 12 checks).

Verified on local Docker staging only (WooCommerce 11.2.1, HPOS on, PHP 8.3):
- unit: domain 12, archive 26, paid 35, woocommerce 23 - all pass
- e2e s1-s4: 193 checks pass
- ui: desktop + mobile admin editor, no console errors, no horizontal overflow, no delete control

Not verified: real PayPal/card sandbox charge, legacy (non-HPOS) order storage, visual PDF comparison vs old generator (needs owner approval), issued-document read-only UI via browser, production hosting.

Must not ship: e2e mail-capture mu-plugin, test users, coming-soon override, shots/ screenshots contain staging data only.
Pending owner: accountant approval for credit documents, issuer details, Hebrew wording, hosting/payment provider, DNS window.
Open finding: /api/admin/manage/status is unauthenticated on both live sites (unchanged).

## Update 18.10.2026 (13:50)
- Fixed 2 editor bugs found by browser test: (1) archive opened the alerts dialog instead of the archive (querySelector('dialog')); (2) archive list failed with plain permalinks (second "?" in REST URL).
- ui3: 16/16 pass (archive, issued read-only view, alerts dialog; desktop+mobile).
- PDF comparison old (pdf-lib, SHEM/worker/documents.js) vs new: amounts identical; design differs (see tests/pdf-compare/compare.png) - awaiting owner decision.
- The 1 open alert on staging is a leftover of the intentional tamper test; wp shem-dok verify: 193 documents intact.
