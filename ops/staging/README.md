# Staging helpers (local Docker, no secrets)

Source: copied 2026-10-28 from /home/claude (the outgoing agent environment). Scripts reference /home/claude paths; adjust when moving.

## Secrets
None in this folder. docker-compose.yml reads 5 variables from a `.env` next to it: DB_ROOT, DB_PASS, WP_ADMIN_PASS, CHANNEL_SECRET, HOME_PAY_SECRET (see env.example). Generate each as a fresh random staging-only value (for example `openssl rand -hex 24`). CHANNEL_SECRET and HOME_PAY_SECRET must be identical on both sites; compose injects them into wp-config as SCC_CHANNEL_SECRET and SCC_HOME_PAYMENT_SECRET. Never reuse production values. The e2e tests also read two chmod 600 files, /home/claude/.stg-pw-localadmin and /home/claude/.stg-admin-pass, holding a staging admin login (created with wp-cli user create or password reset on the staging container).

## Start
1. Put docker-compose.yml, initdb.sql, .env in /home/claude/staging, plus the two downloaded artifacts below (wp.phar and woocommerce.zip are not in the repo).
2. `bash dstart.sh` (prints UP). Sites: :8081 SHEM, :8082 SHIMI.
3. `deploy-scc.sh` installs the plugin and themes from the repo into both sites. `backup-staging.sh <label>` dumps and verifies both DBs. `wps.sh shem|shimi <wp-cli args>` runs wp-cli (optional env `WPS_DB=<db>` points wp-cli at another database, used by the seed selftest).

## Downloaded artifacts (measured 2026-10-28 in /home/claude/staging)
| File | Version | sha256 | Size | Source |
|---|---|---|---|---|
| wp.phar | WP-CLI 2.12.0 (`wp cli version`) | ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c | 7142777 bytes | NOT DOCUMENTED, not verified |
| woocommerce.zip | WooCommerce 11.2.1 (plugin header `Version`; installed plugin reports 11.2.1; the zip's readme.txt says Stable tag 11.2.0; requires WordPress 7.0) | 5317434cb381b5fdc7c1dd6ee258324f08c390976aaf8d1199f2a0e9a3ab8586 | 18060641 bytes (6409 entries) | NOT DOCUMENTED, not verified |

Where each was downloaded from is not recorded anywhere in this repo or in the session, so it is not known. The official publishers are WP-CLI (github.com/wp-cli/wp-cli/releases, file wp-cli-2.12.0.phar) and WordPress.org (downloads.wordpress.org/plugin/woocommerce.11.2.1.zip). Those URLs are the expected origins and are NOT confirmed: the hashes above were not compared with the publisher's checksums (outbound requests to those hosts were blocked in the measuring session). To rebuild, download from the official pages, then compare the sha256 above; a mismatch means a different build. The container PHP is 8.3.36.

## QA product qa-shem-knit-20261009 (e2e fixture)
What it is (from the fixture WP-PLUGIN/shem-shimi-commerce/tests/fixtures/shem-menswear-catalogue.json): variable product, SKU QA-OCT09-KNIT, regular 219, sale 179, sizes M and L (variants qa-shem-knit-20261009-M and -L), stock 10 each, category clothing, brand SHEM, color שחור. The e2e tests in WP-TESTS/customer use it.

How to create it from zero on a new staging: `bash seed-qa-product.sh` (run from this folder, SHEM site must have WooCommerce and the shem-shimi-commerce plugin active; refuses any non-local siteurl).
- default: creates it when missing; when it exists, only verifies it against the fixture (no write; exit 3 on mismatch).
- `--dry-run`: prints would_create / would_update / unchanged, writes nothing.
- `--reset`: force-rewrites it to the fixture baseline (stock 10/10), then verifies. This writes to the live staging DB, back up first (`backup-staging.sh <label>`).
- `--selftest`: proves the create-from-zero path on a scratch clone database (clone of wp_shem, product removed in the clone, created, verified, clone dropped; the live wp_shem tables are checksum-compared before and after). Refuses to run on wp_shem or wp_shimi.
It uses the plugin's own `Scc_Core::upsert_product_from_legacy` (same code as `wp scc import`), quietly, so it does not emit a cross-store event.

IDs are not stable: on the outgoing staging the product is post 58 and the variations are 59 (M) and 60 (L); a new staging gets other ids (the selftest clone got 423, 424, 425). Tests must find the variation by the meta `_scc_legacy_variant_id`, never by a fixed post id. The script prints the ids it finds.

How the product was ORIGINALLY created on the outgoing staging: NOT KNOWN, not verified. The evidence is only that post 58 carries the plugin's import-style meta (`_scc_doc`, `_scc_doc_hash`, `_scc_legacy_id`, `_scc_sizes`, `_scc_stock_sync`), which means it was written through the plugin code path, but no record shows which command or script did it. `seed-qa-product.sh --dry-run` on that product reports `would_update` (its stored hash differs from a fresh import of the fixture), so even the content is not byte-identical to a fresh import. The script here is a reconstruction from the fixture, not the original procedure.

Test status of the script (run 2026-10-28): default verify-only and `--dry-run` ran on the live staging (no write); `--selftest` ran on a scratch clone (created, verified, live checksum unchanged). `--reset` on the live staging was NOT run.

## Not documented / not verified
Full from-zero rebuild (WooCommerce install, import of 57 SHEM items, creation of the 48 linked SHIMI items via WP-TESTS/shimi-linked) was not re-run in this session. The seed script covers only the QA product. The `SCC_INVOICE_DELIVERY_MODE=download` constant is not injected by compose and must be added to wp-config.
