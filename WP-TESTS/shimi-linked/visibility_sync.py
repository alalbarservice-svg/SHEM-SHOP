"""Staging test of the owner's SHIMI rules: (1) SHIMI shows a linked item even when SHEM stock is 0 or the SHEM item is inactive (the SHEM-only rule
'published and in stock' does not apply); (2) previousPrice (discount) follows SHEM. Restores the SHEM doc afterwards. Usage: visibility_sync.py [SKU]"""
import copy, json, sys, time, requests, api
sku = sys.argv[1] if len(sys.argv) > 1 else 'LP-TEE-02'
shem = api.Client('shem').login()
c = [x for x in json.load(open('created.json')) if x['shem_sku'] == sku][0]
orig = shem.call('products/' + c['shem_id']).json()
def put(**ch):
    d = copy.deepcopy(orig); d.update(ch); d['revision'] = shem.call('products/' + c['shem_id']).json().get('revision', d.get('revision'))
    r = shem.call('products/' + c['shem_id'], 'PUT', d); assert r.status_code == 200, (r.status_code, r.text[:300])
def view():
    L = {p['id']: p for p in requests.get(api.SITES['shimi'] + '/api/catalogue').json()['products']}
    p = L.get(c['shimi_id']); return None if p is None else {k: p.get(k) for k in ('price', 'previousPrice', 'stock', 'active', 'syncUnavailable')}
def shem_view():
    L = {p['id']: p for p in requests.get(api.SITES['shem'] + '/api/catalogue').json()['products']}
    p = L.get(c['shem_id']); return None if p is None else {k: p.get(k) for k in ('price', 'stock', 'active')}
print('baseline   SHEM', shem_view(), '| SHIMI', view())
try:
    put(price=100, previousPrice=150); time.sleep(2); print('discount   SHEM', shem_view(), '| SHIMI', view())
    put(price=1, previousPrice=0, stock=0, sizeStock={k: 0 for k in orig['sizeStock']}); time.sleep(2); print('stock 0    SHEM', shem_view(), '| SHIMI', view())
    put(price=1, previousPrice=0, active=False, stock=orig['stock'], sizeStock=orig['sizeStock']); time.sleep(2); print('inactive   SHEM', shem_view(), '| SHIMI', view())
finally:
    d = copy.deepcopy(orig); d['revision'] = shem.call('products/' + c['shem_id']).json().get('revision'); r = shem.call('products/' + c['shem_id'], 'PUT', d); print('restore', r.status_code)
    time.sleep(2); print('restored   SHEM', shem_view(), '| SHIMI', view())
