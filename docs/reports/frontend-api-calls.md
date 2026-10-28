# Frontend API calls (SHEM and SHIMI)

Source: grep of `SHEM/src` and `SHIMI/src` (fetch / adminApi / request helpers), 2026-10-18.
Status column: nothing is implemented yet in `WP-PLUGIN/shem-shimi-commerce` at the time of writing (only Scc_Router, Scc_Docs, Scc_Http exist, no modules). Every route below therefore answers 404 JSON from `Scc_Router` on staging (verified for `/api/nope` on :8081 and :8082). Spec for each = original `worker/*.js` + tests.
`adminApi(p)` = `fetch("/api/admin/" + p)` with JSON content-type, defined in `src/admin-api.js` (same file in both stores). Methods marked "dyn" are chosen at run time (PUT when a record has an id, otherwise POST).

## Public storefront (both stores unless noted)

| Path | Method | Called from (SHEM) | Called from (SHIMI) |
|---|---|---|---|
| /api/catalogue | GET | App.jsx:90 | ShimiApp.jsx:49 |
| /api/store | GET | App.jsx:111 | ShimiApp.jsx:50 |
| /api/collections | GET | App.jsx:107 | not called |
| /api/help | GET | components/HelpCenter.jsx:29 | not called |
| /api/experience | GET | components/InventoryFilters.jsx:74 | not called |
| /api/experience/events | POST | experience.js:113 | not called |
| /api/club | GET | components/Club.jsx:18 | components/Club.jsx:18 |
| /api/club | POST | components/Club.jsx:33 | components/Club.jsx:33 |
| /api/quote | POST | components/CouponQuote.jsx:21 | ShimiApp.jsx:67, components/CouponQuote.jsx:21 |
| /api/requests | POST | components/PolicyPages.jsx:24 | components/PolicyPages.jsx:24 |
| /api/checkout-review | POST | components/HomeCheckout.jsx:165 | components/HomeCheckout.jsx:68 |
| /api/checkout-home | POST | components/HomeCheckout.jsx:197 | not called |
| /api/shimi/checkout | POST | not called | components/HomeCheckout.jsx:91 |
| /api/checkout-status/{orderId}?token= | GET | components/HomeCheckout.jsx:100, components/OrderComplete.jsx:20 | components/HomeCheckout.jsx:38, components/OrderComplete.jsx:19 |
| /api/checkout-invoice/{orderId}?token= | GET (link/open, PDF) | components/OrderComplete.jsx:48 | components/OrderComplete.jsx:35 |

## Customer area (SHEM only, components/CustomerArea.jsx unless noted)

| Path | Method | Line |
|---|---|---|
| /api/customer/me | GET | 266 |
| /api/customer/orders/{id} | GET (Bearer view token; also polled every 30s) | 290, 306, 324 |
| /api/customer/orders/{id}/invoice | GET (Bearer, PDF blob) | 47, 60 |
| /api/customer/watches | POST | components/ProductExperience.jsx:180 |
| /api/customer/watches/{id} | DELETE | 411-414 |
| /api/customer/preferences | PUT | 434-435 |

SHIMI has no customer area calls.

## Session and admin (both stores, identical file layout; SHEM line / SHIMI line)

| Path | Method | Called from |
|---|---|---|
| /api/session | GET | Admin.jsx:124 / 115 |
| /api/admin/session | GET | Admin.jsx:126 / 117 |
| /api/admin/products | GET, POST (dyn: PUT /api/admin/products/{id}) | Admin.jsx:129,176,495 / 120,167,486; Management.jsx:654 / 638; ProductExperience.jsx:343 (SHEM) |
| /api/admin/products/{id} | PATCH | Admin.jsx:797 / 788 |
| /api/admin/orders | GET | Admin.jsx:130 / 121 |
| /api/admin/orders/{id} | PUT | Admin.jsx:1346 / 1313 |
| /api/admin/members | GET | Admin.jsx:131 / 122; Management.jsx:672 / 656 |
| /api/admin/members/{id} | PATCH | Management.jsx:1442 / 1450 |
| /api/admin/promotions | GET, POST (dyn: PUT /{id}) | Admin.jsx:132,1236 / 123,1203; Management.jsx:734 / 718 (def.endpoint) |
| /api/admin/settings | GET, PUT | Admin.jsx:133,187 / 124,178; AdminOperations.jsx:74,220; Integrations.jsx:15,36; Management.jsx:667,1414 |
| /api/admin/media | POST | Admin.jsx:1101 / 1068; Management.jsx:520 / 504,1246; ProductExperience.jsx:255 (SHEM) |
| /api/admin/health | GET | AdminOperations.jsx:75 |
| /api/admin/integration-status | GET | Integrations.jsx:18 |
| /api/admin/audit | GET | AdminOperations.jsx (endpoint map), Management.jsx:674 |
| /api/admin/collections, /customers, /member-profiles, /requests | GET, POST/PUT {id} (dyn) | AdminOperations.jsx:61-72,103 (endpoint map by section) |
| /api/admin/experience | GET, PUT | ExperienceAdmin.jsx:23,52 (SHEM only) |
| /api/admin/channel/status | GET | ChannelManagement.jsx:256 |
| /api/admin/channel/config | GET (home role only, line 257), PUT (line 283) | ChannelManagement.jsx:257,283 |
| /api/admin/channel/preview | POST | ChannelManagement.jsx:24,96 |
| /api/admin/channel/products/{id}/source | GET | ChannelManagement.jsx:17 |
| /api/admin/channel/products/{id}/link, /sync, /unlink | POST | ChannelManagement.jsx:137,157,172,191 |
| /api/admin/channel/reconcile | POST | ChannelManagement.jsx:368 |

Note on the channel helper: `api(p, d)` sends `{}` options (GET) when `d` is undefined and POST otherwise; so `status`, `config`, `products/{id}/source` are GET and the rest POST, except `config` write at line 283 which uses adminApi directly with PUT.

## Management area `/api/admin/manage/*` (helper `api(p, method = "GET", d)` in Management.jsx; SHEM line / SHIMI line differ by about 16)

| Path | Method | Source (SHEM Management.jsx) |
|---|---|---|
| manage/settings/{kind} (kinds seen: loyalty, content, operations, business, home, invoice) | GET, PUT | 445, 457 |
| manage/cards | GET | 657 |
| manage/documents | GET | 658, 677 |
| manage/mail | GET | 659, 678 |
| manage/stock | GET | 662 |
| manage/loyalty | GET | 665 |
| manage/status | GET | 676, 1400, 1726, 1741, 1756, 1871, 1884 |
| manage/orders/{id}/issue-document | POST | 1050 |
| manage/orders/{id}/release-credit | POST | 1077 |
| manage/orders/{id}/payment-link | POST | 1105 |
| manage/orders/{id}/redeem | POST | 1474 |
| manage/orders/{id}/paid | POST | 1687 |
| manage/orders/{id}/payment-details | POST | 1649 |
| manage/loyalty-adjust | POST | 1474 |
| manage/cards/{id}/birthday | POST | 1139 |
| manage/{kind}/{id}/{action} | POST (revision in body) | 1178 (kind and action are run-time values from the record type) |
| manage/mail/process | POST | 1811 |
| manage/mail/{id}/retry | POST | 1834 |
| manage/inventory | POST | 2003 |

Dyn segments (`{kind}/{id}/{action}`, settings kinds, AdminOperations endpoint map) are resolved at run time: the authoritative complete list of admin routes is the route table in `SHEM/worker/*.js` and `SHIMI/worker/*.js`.

## Direct links (browser navigation, not fetch)

| Path | Method | Source |
|---|---|---|
| /api/admin/manage/template-preview.pdf | GET (PDF) | Management.jsx:611 / 595 |
| /api/admin/manage/documents/{id}/pdf | GET (PDF) | Management.jsx:1032,1607,1853 / 1015,1615,1861 |
| /api/admin/manage/orders/{id}/order-pdf | GET (PDF) | Management.jsx:1618 / 1626 |

## Not under /api but needed by the storefront

| Path | Who | Status |
|---|---|---|
| /payment/* | handled by Scc_Router prefix (original worker routed it to api()); no reference in src (grep) | plugin, not implemented |
| /media/products/{key} | original worker served R2 objects here; no reference in src (grep); not verified whether media upload responses contain /media/ URLs | NOT served by the theme (plain 404); needs plugin or uploads rewrite |
| /order-complete.html#params | redirected to by HomeCheckout.jsx:125 after payment | served by the theme (200, shell) |

## Update 28.10.2026 (SHEM single invoice path)
- SHEM Management.jsx no longer calls `manage/settings/invoice` or `manage/template-preview(.pdf)`. The server answers 410 for both on SHEM. SHIMI is unchanged (its own copy of the UI still uses them).
- `manage/orders/{id}/issue-document` on SHEM returns `{document:null, editorUrl}` for manual orders; the admin button opens the SHEM DOK editor (`/wp-admin/admin.php?page=shem-dok&order=<woo id>`).
