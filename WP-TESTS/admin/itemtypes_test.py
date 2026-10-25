#!/usr/bin/env python3
"""AC-088/AC-089: SHEM item categories (store.itemTypes) over the admin API, staging only. Restores the store setting at the end."""
import sys, json, subprocess, requests
BASE = 'http://localhost:8081'
PW = dict(l.strip().split('=', 1) for l in open('/home/claude/.stg-admin-pass'))['shem']
S = requests.Session(); NONCE = ['']; R = []
ROOT = dict(l.strip().split('=', 1) for l in open('/home/claude/staging/.env') if '=' in l)['DB_ROOT']
def sql(q):
    p = subprocess.run(['docker', 'exec', '-i', 'shem-staging-db-1', 'mariadb', '-uroot', '-p' + ROOT, 'wp_shem', '-N', '-B', '-e', q], capture_output=True, text=True, timeout=120)
    assert p.returncode == 0, p.stderr[:200]; return p.stdout.strip()
def call(path, method='GET', data=None):
    h = {'Origin': BASE, 'Content-Type': 'application/json'} if method != 'GET' else {}
    if method != 'GET': h['X-WP-Nonce'] = NONCE[0]
    r = S.request(method, BASE + '/api/admin/' + path, data=json.dumps(data) if data is not None else None, headers=h)
    try: j = r.json()
    except Exception: j = None
    return r.status_code, j
def chk(label, cond, detail=''):
    R.append(cond); print(('PASS ' if cond else 'FAIL ') + label + ('' if cond else f'  -> {detail}'))
D = [(k, h, e) for k, h, e in [('shirts','חולצות','Shirts'),('trousers','מכנסיים','Trousers'),('knitwear','סריגים','Knitwear'),('jackets','ז׳קטים','Jackets'),('shoes','נעליים','Shoes'),('accessories','אקססוריז','Accessories'),('clothing','בגדים נוספים','More')]]
DEF = [dict(key=k, he=h, en=e) for k, h, e in D]
def put(types=None, **extra):
    v = {'heroTitle': 'T', 'heroSubtitle': 'S', 'brands': ['Amiri']}
    if types is not None: v['itemTypes'] = types
    v.update(extra); return call('settings', 'PUT', {'key': 'store', 'value': v})
def public(): return requests.get(BASE + '/api/store').json()
S.get(BASE + '/wp-login.php')
r = S.post(BASE + '/wp-login.php', data={'log': 'scc_test_admin', 'pwd': PW, 'wp-submit': 'Log In', 'testcookie': '1'}, allow_redirects=False); assert r.status_code == 302
NONCE[0] = S.get(BASE + '/api/admin/session').json()['nonce']
snap = sql("SELECT HEX(data), updated_at FROM wp_scc_settings WHERE id='store'")
assert sql("SELECT COUNT(*) FROM wp_scc_products WHERE JSON_VALUE(data,'$.sku') LIKE 'T_IT%'") == '0', 'leftover test rows'
print('snapshot of store setting:', 'present' if snap else 'none (will delete row at end)')
pid = None
try:
    c, j = put(DEF + [dict(key='belts', he='חגורות', en='Belts')]); chk('add category belts -> 200', c == 200, (c, j))
    t = public().get('itemTypes') or []; chk('public /api/store serves 8 itemTypes incl belts', len(t) == 8 and t[-1]['key'] == 'belts', t)
    c, j = put(); chk('brands-form save (no itemTypes key) -> 200', c == 200)
    chk('itemTypes preserved when key absent', len(public().get('itemTypes') or []) == 8, public().get('itemTypes'))
    c, j = put([dict(x, he=x['he'] + 'X') if x['key'] == 'belts' else x for x in DEF + [dict(key='belts', he='חגורות', en='Belts')]])
    chk('rename belts -> 200', c == 200); chk('rename visible publicly', any(x['key'] == 'belts' and x['he'] == 'חגורותX' for x in public()['itemTypes']))
    # product with configured type
    body = dict(he='t_it_מוצר', en='t_it', brand='SHEM', cat='clothing', price=100, stock=2, sizes=['S'], sizeStock={'S': 2}, sku='T_IT_1', image='knit', active=True, productType='belts')
    c, j = call('products', 'POST', body); chk('create product productType=belts (configured) -> 200', c in (200, 201), (c, j))
    pid = (j or {}).get('id'); chk('productType saved as belts', (j or {}).get('productType') == 'belts', j)
    c, j = call('products', 'POST', dict(body, sku='T_IT_2', productType='zzz')); 
    chk('unknown productType stored as blank', (j or {}).get('productType') == '', j)
    if (j or {}).get('id'): sql(f"DELETE FROM wp_scc_products WHERE id='{j['id']}'")
    # removal guard
    c, j = put(DEF); chk('remove belts while a product uses it -> 409 Hebrew', c == 409 and 'קטגוריה' in str((j or {}).get('error')), (c, j))
    chk('belts still present after refused removal', any(x['key'] == 'belts' for x in public()['itemTypes']))
    c, j = put([x for x in DEF if x['key'] != 'shirts']); chk('remove shirts (items assigned) -> 409', c == 409, (c, j))
    if pid: sql(f"DELETE FROM wp_scc_products WHERE id='{pid}'")
    c, j = put(DEF); chk('remove belts after product removed -> 200', c == 200, (c, j))
    chk('belts gone publicly', not any(x['key'] == 'belts' for x in public()['itemTypes']))
    # validation
    for label, bad in [('bad key (space/uppercase)', [dict(key='Bad Key', he='x', en='x')]), ('duplicate key', [DEF[0], DEF[0]]), ('empty Hebrew name', [dict(key='aa', he='', en='x')]), ('empty list', []), ('not a list', 'x'), ('31 entries', [dict(key=f'k{i}', he='x', en='x') for i in range(31)])]:
        c, j = put(bad); chk(f'invalid: {label} -> 400', c == 400, (c, j))
finally:
    if pid: sql(f"DELETE FROM wp_scc_products WHERE id='{pid}'")
    sql("DELETE FROM wp_scc_settings WHERE id='store'")
    if snap:
        h, u = snap.split('\t'); sql(f"INSERT INTO wp_scc_settings (id,data,updated_at) VALUES ('store',UNHEX('{h}'),'{u}')")
print(f'\n{sum(R)}/{len(R)} passed'); sys.exit(0 if all(R) else 1)
