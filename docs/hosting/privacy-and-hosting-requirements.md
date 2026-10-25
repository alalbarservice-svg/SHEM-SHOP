# Hosting and privacy requirements - SHEM and SHIMI (2026-10-25)

Status: requirements and current state. No hosting provider, plan, payment provider or DNS plan has been chosen. These are owner decisions and nothing is purchased or deployed until the owner approves.

## Owner requirements (stated 2026-10-25)
Once live, for BOTH stores: no one can tell the server IP or location, who owns the domains (registrant), SHIMI's business identity on the customer side, or that SHEM and SHIMI are linked and synced.

## Where each requirement stands

| Requirement | State | Evidence |
|---|---|---|
| Public JS bundles do not carry the SHEM-SHIMI admin strings | Done on branch staging/shimi-linked-48 (commit e731760) | SHEM public bundle: 0 SHIMI strings (was 5). Chunk served only to a logged-in administrator: anonymous 404, subscriber 404, admin 200. Admin screenshots identical before and after. |
| SHIMI public bundle still has 2 default "SHEM" strings | Open | `OrderComplete` default prop. Fix needs owner approval (text/design area). |
| Server-to-server callback route has a neutral name | Done on staging/shimi-linked-48 (commit 014d68d) | New route `/shem-dok/v1/pay/notify`: signed callback ok and SHEM order moves to processing; unsigned 401; old route 404. |
| Customer browser does not see the SHIMI domain during checkout (iframe, API) | EXPERIMENT only, branch experiment/pay-same-origin-proxy, not merged | 0 leak hits in Chromium with a same-origin proxy under `/pay-gw/`. Needs web-server config on the SHEM host (Apache and nginx both tested), or an equivalent rule at the CDN. |
| Server IP and location hidden | Depends on hosting decision | Cloudflare Tunnel could not be tested from the sandbox (port 7844 and SRV DNS blocked). |
| Domain registrant hidden | Depends on the registrar (WHOIS privacy) | Not checked: registrar not chosen. |
| PayPal merchant name shown to customers | Not checked | Needs the PayPal account and sandbox keys. |

## Server requirements found during testing
- `private/` folder inside each storefront theme holds admin-only JS. Apache: the shipped `.htaccess` denies it. nginx ignores `.htaccess`, so add: `location ~ ^/wp-content/themes/[^/]+/private/ { deny all; }`
- nginx proxy for `/pay-gw/` needs `resolver 127.0.0.11` (or the real resolver) when the upstream is a variable, and quoted regex locations.
- Apache proxy: use `RewriteRule [P]`, not `ProxyPassMatch` (leaks the backend host through a redirect).

## Before go-live (checklist, none done on production)
1. Set `SHIMI_SHEM_CALLBACK_URL` on SHIMI to the new `/shem-dok/v1/pay/notify` path.
2. Create new production secrets (HMAC, payment). Staging secrets must not be reused.
3. One-time mapping of historical orders that carry the old gateway id (`shem_via_shimi`) if the gateway rename is merged.
4. Decide and configure the same-origin pay proxy on the chosen host.
5. nginx rule for `private/` if the host is nginx.
6. Verified live backups, then the deploy-readiness gate, then the owner's explicit "פרוס".

## Not verifiable from the sandbox (environment facts)
Cloudflare Tunnel, real PayPal order/capture/3DS, iOS Safari, outgoing mail headers, Certificate Transparency lookups, HawkScan (no API key).
