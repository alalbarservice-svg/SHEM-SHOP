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

## Pre-purchase test results (2026-10-26)

Measured with: RDAP (rdap.godaddy.com, rdap.verisign.com), Python HTTP scan of both staging sites, Chromium e2e, PayPal developer docs.

### Domains (live RDAP, both registered 2026-10-12 at GoDaddy)
- shem-shop.com and shimishop.co: registrant and technical contact show "Domains By Proxy, LLC, Tempe, Arizona" (GoDaddy privacy proxy). The owner's name, email, address and country are NOT in public RDAP. The country shown is the proxy's, not the owner's.
- Keep the registrar at GoDaddy and only point the nameservers at Cloudflare. A registrar transfer is not needed.
- Public link signals that cannot be changed: both domains were created 12 seconds apart at the same registrar. Both currently resolve to the same two GoDaddy-side IPs (this changes when DNS moves to the new servers).
- .co: IANA has no RDAP for .co and port 43 is blocked in the sandbox; the .co record above came from the registrar RDAP.

### PayPal
- SHIMI create-order already sends experience_context.brand_name = "SHEM". PayPal docs: brand_name overrides the business name from the PayPal account on the PayPal site.
- Not covered by brand_name: the card statement name is a separate field in the PayPal account (Business information, "Credit Card Statement Name"); set it to a SHEM-neutral name in the SHIMI PayPal account. Receipts: the PayPal docs checked do not state which seller fields appear; verify with one real sandbox or small live payment before go-live.

### Staging public-surface scan (before and after fix/public-surface-hardening)
Before: X-Powered-By PHP version, /wp-json/wp/v2/users listing "admin", /?author=1 redirect, xmlrpc.php answering, readme.html and license.txt public.
After (core-hardening.php module): no X-Powered-By on PHP responses, users endpoint 404 for anonymous (200 for admin), ?author=1 404, xmlrpc 403, no X-Pingback, no outbound self-pings, no generator tags. Payment e2e re-run: pay frame found, callback ok, order-received, 0 requests to SHIMI.
Still web-server level (go-live checklist): deny readme.html, license.txt, xmlrpc.php; ServerTokens Prod / hide Server header; Cloudflare replaces the Server header at the edge (confirm in the post-setup tests).

### Tunnel
- cloudflared needs outbound TCP/UDP 7844 to region1.v2.argotunnel.com and region2.v2.argotunnel.com. The sandbox cannot resolve these hosts, so Tunnel cannot run here. It can only be proven on a real server.
- Proof plan: 1-day hourly VPS (a few cents), full stack, all post-setup tests, then destroy before buying permanent servers.
