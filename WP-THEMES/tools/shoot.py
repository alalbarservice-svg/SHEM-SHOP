"""Render every baseline route of one store with /api/* served from a fixed export (no network data drift).
usage: shoot.py <shem|shimi> <base_url> <outdir> [--reduced]
Same procedure as /home/claude/shot2.py (one page per viewport, sequential hash routes, networkidle + 1200ms,
full-page PNG) so results are comparable with /home/claude/baseline."""
import sys, json, re, os
from playwright.sync_api import sync_playwright
site, base, out = sys.argv[1:4]
reduced = "--reduced" in sys.argv
only_vp = next((a.split("=")[1] for a in sys.argv if a.startswith("--vp=")), None)
os.makedirs(out, exist_ok=True)
EXP = "/home/claude/export-2026-10-18/"
name = {"shem": "shem-menswear", "shimi": "shimi-basics"}[site]
fx = {}
for k in ("catalogue", "store", "collections", "help"):
    fx["/api/" + k] = open(f"{EXP}{name}-{k}.json", "rb").read()
for k in ("club", "experience"):  # extra read-only GETs of the live public API (saved once, see report)
    f = f"{os.path.dirname(os.path.abspath(__file__))}/fixtures-extra/{name}-{k}.json"
    if k == "experience" and site == "shimi": continue  # live returns 404 for SHIMI: stays unmapped (404)
    fx["/api/" + k] = open(f, "rb").read()
man = json.load(open("/home/claude/baseline/manifest.json"))
routes = [""]
for m in man:
    if m["site"] == site and m["route"] not in routes: routes.append(m["route"])
log = {"unmapped": {}, "external": set(), "console": []}
def handler(route):
    u = route.request.url
    p = re.sub(r"^https?://[^/]+", "", u).split("?")[0]
    if route.request.method == "GET" and p in fx:
        return route.fulfill(status=200, content_type="application/json; charset=utf-8", body=fx[p])
    key = route.request.method + " " + p
    log["unmapped"][key] = log["unmapped"].get(key, 0) + 1
    return route.fulfill(status=404, content_type="application/json; charset=utf-8", body='{"error":"fixture-not-mapped"}')
with sync_playwright() as p:
    b = p.chromium.launch()
    for vp, (w, h) in {"desktop": (1440, 900), "mobile": (390, 844)}.items():
        if only_vp and vp != only_vp: continue
        ctx = b.new_context(viewport={"width": w, "height": h}, device_scale_factor=1, reduced_motion="reduce" if reduced else "no-preference")
        ctx.route(re.compile(r".*/api/.*"), handler)
        pg = ctx.new_page()
        origin = re.match(r"https?://[^/]+", base).group(0)
        pg.on("request", lambda r: log["external"].add(r.url[:80]) if not r.url.startswith(origin) and not r.url.startswith("data:") else None)
        pg.on("console", lambda m: log["console"].append(m.type + ": " + m.text[:120]) if m.type in ("error", "warning") else None)
        for r in routes:
            slug = re.sub(r"[^a-z0-9]+", "-", r[2:].lower()).strip("-")[:40] or "home"
            fn = f"{out}/{site}-{slug}-{vp}.png"
            try:
                pg.goto(base + r, wait_until="networkidle", timeout=45000); pg.wait_for_timeout(1200)
                pg.screenshot(path=fn, full_page=True)
            except Exception as e:
                print("FAIL", site, r, vp, str(e)[:100])
        ctx.close()
    b.close()
log["external"] = sorted(log["external"]); log["console"] = sorted(set(log["console"]))[:20]
json.dump(log, open(f"{out}/_log-{only_vp or 'all'}.json", "w"), ensure_ascii=False, indent=1)
print(site, base, "done", len(routes), "routes")
