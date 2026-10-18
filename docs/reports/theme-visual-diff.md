# Theme visual diff: shem-storefront / shimi-storefront (staging :8081 / :8082) vs live

Date 2026-10-18. Tools: `WP-THEMES/tools/shoot.py`, `imgdiff.py`, `run-all.sh` (Playwright chromium, PIL/numpy). Screenshots (not in repo): `/tmp/claude-0/-home-claude/e0ee4ec4-b7c1-587c-9ba4-49b726b70a07/scratchpad/vdiff/` (sets FA1/FA2 = live, FB1/FB2 = WordPress theme, FS1/FS2 = control).

## What was proven (byte level)
- Built from unmodified `SHEM/` and `SHIMI/` in a scratch copy (npm ci + vite build). The hashed bundles are byte-identical to what the live sites serve now: SHEM `index-CaHIeR_V.js`, `index-CD0ryVIm.css`; SHIMI `index-eXwzDDi_.js`, `index-DPMvC5z2.css` (sha256 compared with curl of live), plus `hero.webp`, `shem-share-black-v2.png`, `favicon.svg`.
- All 39 (SHEM) and 13 (SHIMI) static files in `public/` are byte-identical to vite output (`public.sha256` in each theme).
- Shell HTML = vite `index.html` + `prepare-admin-share.mjs` output (admin.html, order-complete.html). Only difference vs the originals: the legacy `https://shem-menswear.genial-comet-8090.chatgpt.site` in canonical/og/twitter URLs is replaced by the site's own home URL at serve time (needed for the target domain), and staging (blog_public=0) gets an extra `noindex` meta + `X-Robots-Tag`.

## Method
SHEM 30 routes x 2 viewports = 60 shots, SHIMI 15 x 2 = 30 (manifest routes plus `/`). Desktop 1440x900, mobile 390x844, DPR 1, full page. `/api/catalogue|store|collections|help` served from `/home/claude/export-2026-10-18/*.json` on every target; `/api/club` and (SHEM) `/api/experience` from two extra read-only GETs of the live API saved in `tools/fixtures-extra/`; every other `/api/*` answers a fixed 404 stub on every target.
Measurement settings, applied identically to every target: reduced-motion (pins the hero slide), `img[loading=lazy]` forced eager, wait for fonts and image decode, +3s. Without this the live site against itself already differs (late-painting brand logos, hero fade).
Metric: % of pixels with any channel difference ("any") and with a channel difference > 16 ("sig"); same-size check.
Controls: live vs live (noise floor), WP vs WP, and a plain static server serving the same build (no WordPress) = "static control", to separate theme effects from environment (localhost vs internet latency).

## Result (settled, reduced-motion set)
| Pair | SHEM (60 shots) | SHIMI (30 shots) |
|---|---|---|
| live vs live (noise) | 6 differ, 2 sig | 0 differ |
| WP vs WP (noise) | 10 differ, 8 sig | 1 differ (sig 0.0013%) |
| static vs static (noise) | 17 differ, 9 sig | 0 differ |
| **WP theme vs live** | 54 identical, 6 differ, 1 sig | 29 identical, 1 differ (sig 0.0013%) |
| **WP theme vs static control** | 56 identical, 4 differ, 1 sig | 30 identical |
Same size in every pair (no layout/height change in any of 90 shots).

Every non-identical WP-vs-live file, with its cause:
- shem-brand-amiri/dior/essentials-desktop, shem-shop-desktop, shem-help-mobile: max channel diff 1-2, 1 to 8 pixels (0.0001-0.0007%). Anti-aliasing noise; also seen live vs live and static vs static.
- shem-home-mobile (3.2% sig, region y 1853-3915): the brand-logo/slider strip, different logos painted at capture time. Differs equally WP vs WP, static vs static and static vs live, so it is capture timing, not theme. NOT proven pixel-equal in a single run (see limits).
- shimi-home-mobile (0.0013% sig, max diff 25, product-card photos): Chrome decodes/resamples the same image bytes two ways depending on load timing. 20 repeats: live 20/20 variant A; WP 16/20 variant B; plain static server 17/20 variant B (variant A on the rest). Same files, same CSS; the shift live to localhost is latency, and the static control without WordPress behaves exactly like WP.
- 1px horizontal hairline (x 22-367, 4 px tall band, max diff 228, mobile SHEM pages: brand-*, cancel, club, collections, terms, wishlist, accessibility, account): the same 1px rule lands one pixel up or down between runs. Present live vs live, WP vs WP, static vs static; it moves between runs on the same target, so it is rendering noise.

Conclusion: no difference attributable to the theme was found. Honest limit: with this noise a single run cannot show "0 differing pixels" on those files; equality was shown by byte-identical assets, same-size layouts, and by the WP distribution matching the static control and live-vs-live noise classes. No theme change was needed after the diff.

## Against the 2026-10-16 baseline (`/home/claude/baseline`, FB1 vs baseline)
SHIMI: 29 of 30 identical (home-mobile 0.0013% as above). SHEM: 24 of 60 identical; the rest show brand-logo and product-image differences, and account pages differ in height (baseline had the real customer API, here `/api/customer/me` is a 404 stub). Live-now vs the same baseline gives 23 of 60 identical, with the same files differing by the same amounts (only home-mobile differs by more than 0.05 pp: 9.9% live, 7.4% WP). So baseline differences come from baseline method (no image settling, data may have changed since 2026-10-16, not checked), not the theme.

## Behaviors that differ from the live host (decisions needed)
1. Live (Cloudflare assets) answers `/admin.html` with 307 to `/admin`, and `/order-complete.html` with 307 to `/order-complete`. The React code checks `location.pathname === "/admin.html"` (App.jsx:77,161, ShimiApp.jsx:18) and `"/order-complete.html"` (main.jsx), so on live (verified with Playwright on both stores) these URLs end up rendering the storefront home, and the admin is only reachable through the hash route. The theme serves both `.html` URLs directly with 200 (no redirect), so the intended admin screen and receipt screen render (verified on :8081/:8082: admin UI "STORE MANAGEMENT", receipt screen). Extensionless `/admin` and `/order-complete` redirect (307) to the `.html` forms; `/index.html` redirects to `/`.
2. Unknown paths: any GET/HEAD with `Accept: text/html` gets the home shell with 200 (as the worker did); other Accept values get a plain 404.
3. Admin and receipt shells: `noindex, nofollow` meta (original) plus `X-Robots-Tag`. Store pages are indexable by the theme itself; staging adds noindex via WordPress "discourage search engines".
4. `/media/*` is not served by the theme (404). `/api/*` and `/payment/*` stay with the commerce plugin.
