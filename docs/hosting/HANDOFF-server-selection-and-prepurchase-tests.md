# HANDOFF - Server selection and pre-purchase tests (PINNED TASK)

Written 2026-10-27 so that any agent can continue this track cold. Read this file first, then `privacy-and-hosting-requirements.md` and `same-origin-pay-proxy.md` in this folder.

**Status: WAITING FOR OWNER APPROVAL to open hourly-billed test servers. Nothing has been bought, created or deployed.** The owner said the purchase and the tests are delayed one to two days. This is the next important step on the hosting track; do not let it drop between other tasks.

סיכום בעברית: המטרה היא לבחור שרתים לשתי החנויות (כל חנות על שרת וספק נפרדים), להסתיר כתובת שרת, בעלות ודומיין, ולהוכיח את זה ביום בדיקה על שרתים בחיוב שעתי לפני כל רכישה קבועה. ההמלצה: Hetzner CPX22 ל-SHEM ו-Linode Shared 4GB ל-SHIMI. הרכישה ממתינה לאישור הבעלים.

## 1. Owner and working rules (stated by the owner)
- Owner: Moshe, not technical. Replies in Hebrew only, short and direct, expert level.
- Iron rule: every claim rests on a checked source, or is labelled unverified (below 95 percent certainty). No guessing, no "probably".
- Nothing is deployed until everything is measured and proven. Anything needed to measure runs in a sterile area that affects nothing live.
- The owner decides the hosting provider and plan. No purchase, account creation, payment details or DNS change without his explicit approval. An agent never enters payment details or creates provider accounts (prohibited action): the owner does it.
- Deletion needs approval. Include "destroy the test servers at the end" inside the purchase approval so the end of the test is covered.
- Do not email the owner without telling him first and waiting for confirmation.

## 2. Requirements (owner, 2026-10-25 and 2026-10-27)
Once live, for BOTH stores:
1. Nobody can tell the server IP or location.
2. Nobody can tell who owns the domains.
3. SHIMI's business identity is not visible to customers.
4. Nobody can tell that SHEM and SHIMI are linked or synced.
5. The customer sees only SHEM during checkout.
6. Fast, high quality, reliable, cheap.
7. 2026-10-27: the two stores go on SEPARATE servers.
8. 2026-10-27: no outgoing email is needed. Customer service will be a bot at the end; it handles support and technical questions and forwards a case to the owner when needed, and the owner replies to the customer.
9. 2026-10-26: the SHEM-neutral statement/business name is already set in the SHIMI PayPal account (owner statement, not verified by an agent).

## 3. Decisions and recommendation
| Item | Value | Source |
|---|---|---|
| Topology | One server per store, different providers | owner goal 2026-10-27, agent recommendation |
| SHEM server | Hetzner CPX22 (2 shared vCPU, 4 GB, 80 GB NVMe) | agent recommendation, not approved |
| SHIMI server | Linode (Akamai) Shared 4GB (2 vCPU, 4 GB, 80 GB) | agent recommendation, not approved |
| Fallback if Hetzner refuses the new account | a second Linode, or DigitalOcean | agent, not verified |
| Origin hiding | Cloudflare Tunnel (outbound only), DNS moved to Cloudflare by nameserver change only | agent plan, UNPROVEN until test day |
| Registrar | stay at GoDaddy (privacy proxy already active) | verified, see section 4 |
| Hosting plan, provider, purchase | NOT approved | owner |

Why not shared or managed WordPress hosting: Tunnel needs the `cloudflared` program running on the server with outbound port 7844. Why not Hostinger: billing is monthly only, so no cheap one-day test. Live Hostinger API prices (2026-10-26): KVM 1 (1 vCPU, 4 GB) 9.99 USD first month then 19.49; KVM 2 (2 vCPU, 8 GB) 13.99 then 24.49. Refund terms not checked.

KVM means each server is a separate virtual machine with its own memory, CPU and disk and full root access, which is what installing `cloudflared`, WordPress and a database requires.

## 4. Findings and verification status
Verified (evidence in `privacy-and-hosting-requirements.md`):
- Both domains (registered 2026-10-12 at GoDaddy, 12 seconds apart) show only "Domains By Proxy, LLC, Tempe, Arizona" in public RDAP, including `.co`. The owner's name, email, address and country are not shown.
- SHIMI create-order sends `brand_name` "SHEM"; PayPal docs say it overrides the account business name on the PayPal site.
- On staging, the customer browser sees only the SHEM origin through checkout (Chromium e2e, 0 requests to the SHIMI host, order 175, run 2026-10-26).
- Staging public-surface leaks closed by `core-hardening.php`: X-Powered-By, REST users list, `?author=1`, xmlrpc (403), pingback. The REST users list is still available to a logged-in admin.

Not verified (do not present as fact):
- Cloudflare Tunnel behaviour: the sandbox blocks port 7844 and the SRV DNS needed. Environment limit, not a product finding.
- That the origin IP stays hidden. Only a real server can prove it.
- Whether two zones in one Cloudflare account share the same nameserver pair (a possible link signal). Check on test day and use separate Cloudflare accounts per store if they do.
- What the PayPal receipt shows about the seller, and the current value of the statement name (owner set it; not read back).
- Whether Hetzner accepts a new account (a third-party page reports restrictions on new cloud servers since 2026-06-26).
- Whether 4 GB per store is enough. Not measured.
- Certificate Transparency records, outgoing mail headers (mail is now out of scope), iOS Safari, real 3DS, HawkScan (no API key).
- Official provider price pages were blocked. Prices below come from third-party pages and are unverified against the providers.

Public link signals that cannot be changed: same registrar and creation time 12 seconds apart. Same two resolving IPs today; this disappears once each domain points to its own Cloudflare setup.

## 5. Cost (all unverified against official price pages)
| Item | Price | Source |
|---|---|---|
| Hetzner CPX22 | 0.0349 USD per hour (Falkenstein, Helsinki, Nuremberg), 0.0476 Singapore. Hourly, rounded up to the hour, capped at the monthly price, still billed while powered off until deleted. Monthly price not published on the source; about 25 USD by calculation | sparecores.com 2026-10-26, agentdeals.dev |
| Linode Shared 4GB | 24 USD per month, 0.036 USD per hour | vpsbenchmarks.com, trial 2025-10-17 |
| DigitalOcean (reference) | per-second billing, minimum 60 seconds or 0.01 USD, billing stops on destroy (official docs); the 2 GB size listed at 0.027 USD per hour, 18 USD per month on a third-party page | docs.digitalocean.com, pcr.cloud-mercato.com |
| Test day | about 0.84 USD (Hetzner) plus about 0.86 USD (Linode) for 24 hours each, calculated | calculation |
| Two servers per month | about 49 to 50 USD | calculation |

Not included: Cloudflare plan (official page blocked, so free status of Tunnel is unverified), VAT (Hetzner prices are net of VAT; treatment for this owner unknown), provider backups, bot service for support, domain renewals and the GoDaddy privacy add-on, PayPal transaction fees.
A single shared 4 GB server would cost about 25 USD per month but removes provider separation. Not recommended given requirement 4.

## 6. Pre-purchase test plan v1 (defined 2026-10-27)
Earlier chat messages mentioned "six acceptance tests" without listing them. This is the first written list. Run it on one hourly test server per chosen provider (two servers, about 1.7 USD in total), then destroy both.

| # | Test | Pass criterion |
|---|---|---|
| T1 | Tunnel works from the provider's network | `cloudflared` connects outbound (port 7844) and the site loads through the Cloudflare hostname |
| T2 | Origin has no public entry | All inbound ports closed on the server firewall; HTTP and HTTPS to the raw server IP fail; no page, header, feed, sitemap or oEmbed contains the server IP; `Server` header is the Cloudflare one |
| T3 | Origin is not discoverable by DNS | Public lookups return only Cloudflare addresses; no DNS record was ever created pointing at the origin (create zones and records fresh, tunnel hostnames only) |
| T4 | Separation of the two stores | Different provider, different IP ranges, and a check on whether the two Cloudflare zones expose the same nameserver pair; owner decides on separate Cloudflare accounts if they do |
| T5 | Checkout through the real topology | With SHEM and SHIMI on different servers: `/pay-gw/` proxy reaches SHIMI server-side, signed callback reaches SHEM, order reaches order-received, Chromium shows 0 requests to the SHIMI host (reuse `WP-TESTS/hosting/prepurchase/e2e.py`) |
| T6 | Server baseline | `idscan.py` clean on both sites; web-server rules in place (deny `readme.html`, `license.txt`, `xmlrpc.php`, theme `private/` folder; hide server version); WooCommerce emails off |
| T7 | Sizing and speed | Memory, CPU and response times during a checkout run on 4 GB with PHP and MariaDB tuned; record numbers. If 4 GB is not enough, report the next size and its price |
| T8 | PayPal (needs the owner's sandbox keys, not a server test) | Sandbox order and capture; record what the receipt and PayPal page show about the seller |

Everything not run is reported as not-run. A passing build is not a browser test, and a local test is not a sandbox charge.

## 7. Test-day procedure and open access problem
1. Owner approves in chat: provider names, instance sizes, region, maximum spend, and "destroy both servers when the tests end".
2. Owner opens the provider accounts and payment method himself.
3. OPEN: how the agent reaches the test server. The agent sandbox blocks SSH and non-allowlisted hosts, and the device shell had no connected folder in this session. Resolve before test day with one of: a least-privilege provider API token kept in a vault (never in chat or repo), commands the owner runs from a script the agent writes, or linking the owner's computer in the desktop app so the device shell is available. Ask the owner which one.
4. Create the server, harden, install the stack from the repo staging build (never real customer data, `noindex`, no live provider keys, no real charges or messages), run T1 to T7.
5. Destroy the servers (delete, not power off), confirm in the provider list that none remain, report cost.
6. Decision gate: owner reviews results, then separately approves the permanent purchase. The go-live checklist in `privacy-and-hosting-requirements.md` applies.

## 8. Code state (all on `staging/shimi-linked-48`, pushed)
- `1ec8a8b` merge of `fix/public-surface-hardening` (module `WP-PLUGIN/shem-shimi-commerce/includes/modules/core-hardening.php`).
- `cbe0e77` same-origin PHP pay proxy inside the gateway (`SHEM_PAY_BACKEND`, `SHEM_PAY_PROXY_KEY`), merged. This replaces the earlier web-server-config experiment, so older text in `privacy-and-hosting-requirements.md` saying the proxy is only an experiment is out of date.
- Neutral callback route `/shem-dok/v1/pay/notify` and admin-chunk gating, merged earlier.
- Test helpers: `WP-TESTS/hosting/prepurchase/`.

## 9. Still open on the wider project
Real charged order; verified live backups; production secrets; mapping of historical orders from the old gateway id `shem_via_shimi`; 2 default "SHEM" strings in the SHIMI public bundle (needs owner approval); 43 SHIMI placeholder images; 7 environmental admin test failures; `shem-storefront` order-pay iframe renders only under `twentytwentyfive`; admin "מותגים" textarea; HawkScan.

## 10. Corrections to earlier chat statements
- The "six acceptance tests" were never listed; section 6 replaces that phrase.
- An earlier price of about 6.49 EUR per month for Hetzner referred to a 1 GB plan and does not apply to the recommended CPX22.
- The outgoing-mail exposure item is removed because the owner does not need email.
