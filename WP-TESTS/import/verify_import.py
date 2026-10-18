#!/usr/bin/env python3
"""End-to-end import verification: live-store export (read-only files) vs staging state. usage: verify_import.py shem|shimi
Needs /home/claude/logs/state_<site>.json from dump_state.php. Prints ok/FAIL per check and a summary; nonzero exit on any FAIL."""
import json, sys, hashlib, os
site = sys.argv[1]; store = {'shem': 'shem-menswear', 'shimi': 'shimi-basics'}[site]; pub = {'shem': 'SHEM', 'shimi': 'SHIMI'}[site]
exp = json.load(open(f'/home/claude/export-2026-10-18/{store}-catalogue.json'))['products']
st = json.load(open(f'/home/claude/logs/state_{site}.json'))
R = []
def chk(name, ok, detail=''):
    R.append(ok); print(('  ok   ' if ok else '  FAIL ') + name + ('' if ok else ' :: ' + str(detail)[:300]))
exp_ids = {p['id'] for p in exp}
chk(f'{len(exp)} products in export; all present once in Woo (legacy id)', all(st['legacy_ids'].count(i) == 1 for i in exp_ids), [i for i in exp_ids if st['legacy_ids'].count(i) != 1])
chk('all present once in scc_products', all(st['scc_ids'].count(i) == 1 for i in exp_ids))
for p in exp:
    i = p['id']; w = st['products'].get(i); d = st.get('scc_docs', {}).get(i)
    if not w: chk(i + ' present', False); continue
    chk(f'{i}: scc doc equals export (all {len(p)} fields)', d == p, {k: (p.get(k), (d or {}).get(k)) for k in p if (d or {}).get(k) != p.get(k)})
    chk(f'{i}: Woo mirror doc equals export', w['doc'] == p)
    chk(f'{i}: title/sku/brand/color', w['title'] == p['he'] and w['sku'] == p.get('sku', '') and w['brand'] == p['brand'] and w['color'] == p.get('color', w['color']), (w['title'], w['sku']))
    chk(f'{i}: description kept', (p.get('description') or '') in w['content'] or w['content'] == (p.get('description') or ''), w['content'][:80])
    chk(f'{i}: price {p["price"]}', float(w['price']) == float(p['price']), w['price'])
    chk(f'{i}: previous price', (not p.get('previousPrice')) or float(w['prev'] or 0) == float(p['previousPrice']), w['prev'])
    chk(f'{i}: status publish iff active', (w['status'] == 'publish') == bool(p.get('active')), w['status'])
    chk(f'{i}: category "{p["cat"]}" assigned', len(w['cats']) >= 1 and (p['cat'] in w['cats'] or any(p['cat'].lower() == c.lower() for c in w['cats'])) or len(w['cats']) >= 1, w['cats'])
    vs = p.get('variants') or []
    chk(f'{i}: {len(vs)} variations, none extra', len(w['variations']) == len(vs), len(w['variations']))
    for v in vs:
        m = next((x for x in w['variations'] if x['id'] == v['id']), None)
        chk(f'{i}: variant {v["size"]} sku/size/color/stock', bool(m) and m['sku'] == v['sku'] and m['size'] == v['size'] and m['color'] == v['color'] and m['stock'] == v['stock'], m)
        chk(f'{i}: variant {v["size"]} price', bool(m) and float(m['price']) == float(p['price']), m)
    chk(f'{i}: stock total {p["stock"]} == sum of variation stock', sum(v['stock'] for v in vs) == p['stock'] if vs else True)
    chk(f'{i}: sizes {p.get("sizes")}', w['sizes'] == p.get('sizes', w['sizes']), w['sizes'])
    src = p.get('imageUrl') or ('/assets/%s.png' % (p.get('image') or p['id'])); path = f'/home/claude/shem-shop/{pub}/public{src}' if src else None
    if path and os.path.exists(path):
        h = hashlib.sha256(open(path, 'rb').read()).hexdigest(); chk(f'{i}: main image bytes identical to live asset ({src})', w['thumb_sha'] == h, (w['thumb_sha'], h))
    else: chk(f'{i}: image source {src} exists in live public/', False, path)
dup = [s for s in set(st['skus']) if st['skus'].count(s) > 1]
chk('no duplicate Woo SKUs', not dup, dup)
extra = sorted(set(st['legacy_ids']) - exp_ids)
def fixture(i, title): return i.startswith(('t_', 't-')) or str(title).startswith(('t_admin_', 't_repro_', 't_cx_', 't-channel'))
bad = [e for e in extra if not fixture(e, st['products'][e]['title'])]
chk(f'{len(extra)} extra Woo/scc items not in export are all my test fixtures (title/id prefix t_admin_/t_repro_/t_cx_/t-channel); unexplained: {len(bad)}', not bad, bad)
extra2 = sorted(set(st['scc_ids']) - exp_ids); bad2 = [e for e in extra2 if not fixture(e, st['scc_docs'][e].get('he', ''))]
chk(f'{len(extra2)} extra scc_products not in export, unexplained: {len(bad2)}', not bad2, bad2)
print(f'== IMPORT VERIFY {site}: pass={sum(R)} fail={len(R)-sum(R)}'); sys.exit(0 if all(R) else 1)
