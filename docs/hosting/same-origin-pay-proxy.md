# Same-origin payment proxy - staging experiment (2026-10-25)

Status: EXPERIMENT on branch `experiment/pay-same-origin-proxy`. Not merged, not deployed.

## Goal
Customers' browsers should not see the SHIMI domain during checkout (iframe src, API calls, headers, JS identifiers).

## Verified (real Chromium, local staging)
- Baseline (original plugin): 12 leak hits for `8082|shimi` across URLs, headers and bodies (control: scan finds them).
- Bare unauthenticated request to the SHIMI payment URL returns a CSP header `frame-ancestors <SHEM origin>`, which reveals the SHEM link.
- Proxy mode: iframe and API both under the SHEM origin at `/pay-gw/...`: 0 leak hits.
- API round trip reaches the backend (503 "PayPal not configured" proves routing and the gate).
- postMessage delivered with origin = SHEM, source `pay-frame`.
- Direct hits on the SHIMI domain to the payment endpoints return a bare 404 without the proxy key header.

## Apache config used on the SHEM host (key is a placeholder)
```
RewriteEngine On
RewriteRule "^/pay-gw/p/([a-f0-9]{24})/([a-f0-9]{16,64})$" "http://shimi/?shimi_pay=$1&token=$2" [P,L,E=PAYGW:1]
RewriteRule "^/pay-gw/api/(paypal/(?:create|capture))$" "http://shimi/wp-json/shimi-pay/v1/$1" [P,L,E=PAYGW:1]
RequestHeader set X-Pay-Gw "<KEY>" env=PAYGW
```
Modules: proxy, proxy_http, rewrite, headers.
Notes: `Header unset` does not strip backend headers on proxied responses (fixed in the plugin). `ProxyPassMatch` with `?` encodes it as `%3F` and WP then 301-redirects, leaking the backend hostname, so use `RewriteRule [P]`.

## Plugin changes (SHIMI-PAY)
Constants `SHIMI_PAY_PUBLIC_BASE` and `SHIMI_PAY_PROXY_KEY`. When set: embed URL uses the public base, gate returns 404 without the key, JS names are neutral (`PF`, source `pay-frame`), `Link` header removed on the pay namespace.

## NOT verified
- Real PayPal JS SDK, buttons, popup and 3DS under the SHEM origin (no sandbox keys; PayPal-side origin settings unknown).
- SHEM-PAY gateway changes (`SHEM_PAY_ALLOWED_ORIGIN` for a path origin, accept source `pay-frame`). SHEM-PAY is not installed on local staging.
- Server-to-server paid callback through the proxy.
- Cloudflare Worker/rules equivalent, nginx, hosts without Apache config access.
- PayPal merchant name shown to customers.
- iOS Safari and third-party cookies; latency of the extra hop.
- Residual: the SHIMI domain still lists the `shimi-pay/v1` namespace under `/wp-json/`.

## Test results, staging, 2026-10-25 (experiment branch, not merged)

Measured in the staging containers (SHEM :8081, SHIMI :8082) with Chromium (Playwright) and curl.

| Check | Result |
|---|---|
| Pay iframe served under the SHEM origin (/pay-gw/p/...) | pass, 0 hits for "shimi" or "8082" in URLs, headers and bodies |
| PayPal SDK loads under the SHEM origin, buttons render | pass (client-id `sb`, demo id) |
| postMessage listener with a path-based origin | pass |
| Signed paid callback, then redirect to order-received | pass |
| Gateway id renamed to `shem_pay` | pass (same E2E, order 159) |
| SHIMI `/wp-json/`: no `shimi-pay/v1`, session and create endpoints return 404 without the key | pass; 200 with the key; `wp/v2` unaffected |
| SHEM public REST index and `/wp-json/shem-dok/v1` listing no longer show the callback route | pass; the route still answers (401 unsigned) |
| nginx proxy, same behavior as Apache | pass, body identical; needs `resolver` when `proxy_pass` has variables |
| Third-party cookies blocked (Chromium flag) | pass; the pay frame sets 0 cookies |
| Latency, 200 requests, 20 concurrent: direct / Apache / nginx p50 | 234 / 231 / 242 ms |
| PayPal popup | opens from the SHEM-origin frame; order creation fails (502) because staging has no real sandbox REST credentials |

Facts that cannot be tested from this sandbox: Cloudflare Tunnel (port 7844 and SRV DNS blocked), real PayPal order, capture and 3DS (needs sandbox client-id and secret from the owner), iOS Safari (webkit not installed), outgoing mail headers, Certificate Transparency (crt.sh blocked).

Known residual: the callback path `/wp-json/shem-dok/v1/shimi/paid` contains the word "shimi" and is discoverable only by guessing or OPTIONS on that exact path. Public bundles of the live sites mention the link in admin UI strings (SHEM bundle: 5 mentions of SHIMI; SHIMI bundle: 10 mentions of SHEM).
