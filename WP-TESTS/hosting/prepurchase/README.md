# Pre-purchase test helpers (staging, written 2026-10-25/26)

Staging-only helpers. Hard-coded to the local Docker staging (SHEM :8081, SHIMI :8082). Adapt the base URLs before reuse on a test server.

- `idscan.py`: public-surface scan of both sites (headers, generator meta, REST root, users endpoint, ?author=1, xmlrpc, readme.html, license.txt, debug.log, .git, .env, wp-config backup, uploads, oEmbed). Read-only GET/POST probes.
- `mkorder.php`, `cb.php`, `e2e.py`: payment e2e on staging. mkorder creates a test order, e2e.py drives Chromium through the SHEM checkout and records every request host, cb.php fires the signed server-to-server callback. Pass condition: pay frame found, callback ok, final URL order-received, 0 requests to the SHIMI host. Last run on 2026-10-26: order 175, all pass. These scripts contain no secrets; the secrets are read at runtime from the staging environment and must never be written here.
