# SHIMI-PAY and SHEM-PAY (staging)

Flow: SHEM gateway (`shem_pay`) signs a session to SHIMI (`/wp-json/shimi-pay/v1/session`) -> SHIMI returns an embed URL -> iframe in SHEM shows PayPal buttons -> SHIMI creates and captures the PayPal order, verifies status/amount/currency/custom_id -> SHIMI sends the signed paid callback to SHEM `/shem-dok/v1/shimi/paid` -> SHEM marks paid and DOK issues the invoice.

Secrets live only in wp-config.php constants on each site (never in the repo). Staging only: `tools/paypal-proxy-forwarder.py` is a scoped forwarder for PayPal hosts; remove before go-live.

Tests: `python3 -I tests/e2e_pay.py` (reads secrets from wp-config at run time). Section E mocks only the PayPal capture response.
Reconcile: `wp shimi-pay reconcile` (hourly cron). Known gaps: no PayPal webhook endpoint (polling instead); ACDC card capture untested.
