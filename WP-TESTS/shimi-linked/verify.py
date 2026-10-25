"""Read-only checks of the 48 linked pairs on staging: 1:1 links, SKU/price equality, no demo flag, link status."""
import json, sys, requests, api
shem = api.Client('shem').login(); shimi = api.Client('shimi').login()
SP = {p['sku']: p for p in requests.get(api.SITES['shem'] + '/api/catalogue').json()['products'] if p.get('sku') and p.get('price') is not None}
HP = {p['id']: p for p in requests.get(api.SITES['shimi'] + '/api/catalogue').json()['products']}
real = {k: v for k, v in SP.items() if v.get('demo') is not None or True}
created = json.load(open('created.json'))
res = {'pairs': 0, 'sku_eq': 0, 'price_eq': 0, 'linked': 0, 'demo': 0, 'bad': []}
srcids = set()
for c in created:
    h = HP.get(c['shimi_id']); s = SP.get(c['shem_sku']); res['pairs'] += 1
    if not h or not s: res['bad'].append((c['shem_sku'], 'missing in public catalogue')); continue
    res['sku_eq'] += h.get('sku') == s['sku']; res['price_eq'] += float(h['price']) == float(s['price'])
    res['demo'] += bool(h.get('demo'))
    d = shimi.call(f"channel/products/{c['shimi_id']}/source").json(); l = d.get('link') or {}
    ok = l.get('sourceId') == c['shem_id'] and l.get('status') == 'connected'; res['linked'] += ok; srcids.add(l.get('sourceId'))
    if not ok or h.get('sku') != s['sku']: res['bad'].append((c['shem_sku'], l.get('status'), h.get('sku')))
res['distinct_sources'] = len(srcids)
print(json.dumps(res, ensure_ascii=False))
sys.exit(1 if res['bad'] or res['pairs'] != 48 or res['linked'] != 48 or res['distinct_sources'] != 48 else 0)
