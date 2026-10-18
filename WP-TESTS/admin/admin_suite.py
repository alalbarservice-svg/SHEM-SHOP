#!/usr/bin/env python3
"""
Black-box suite for the admin API of the shem-shimi-commerce plugin (staging only).
Replays SHEM|SHIMI tests/admin-api.test.mjs, management.test.mjs, operations.test.mjs against the PHP port,
over HTTP with a real WP admin session (cookie + wp_rest nonce). Usage: admin_suite.py shem|shimi
Credentials: /home/claude/.stg-admin-pass (chmod 600). Test data ids/names carry the prefix t_admin_ and are removed at the end
(audit rows are append-only by design and stay). Settings/sequence rows are snapshotted and restored.
"""
import sys, os, json, re, subprocess, time, base64, collections
import requests

SITE = sys.argv[1] if len(sys.argv) > 1 else 'shem'
PORT = {'shem': 8081, 'shimi': 8082}[SITE]
BASE = f'http://localhost:{PORT}'
BRAND = 'SHEM' if SITE == 'shem' else 'SHIMI'
IS_SHEM = SITE == 'shem'
P = 't_admin_'
PW = dict(l.strip().split('=', 1) for l in open('/home/claude/.stg-admin-pass'))[SITE]
TBL = lambda n: 'wp_scc_' + n

# ------------------------------------------------------------------ result bookkeeping
RES = []            # (screen, label, status, detail)
CUR = ['auth']
def rec(status, label, detail=''):
    RES.append((CUR[0], label, status, detail))
    if status == 'FAIL': print(f'  FAIL [{CUR[0]}] {label} {detail}'[:400])
def screen(name): CUR[0] = name
def ok(label, cond, detail=''): rec('PASS' if cond else 'FAIL', label, '' if cond else str(detail)[:300])
def eq(label, got, want): rec('PASS' if got == want else 'FAIL', label, '' if got == want else f'got {str(got)[:200]!r} want {str(want)[:200]!r}')
def blocked(label, reason): rec('BLOCKED', label, reason)
def notrun(label, reason): rec('NOT-RUN', label, reason)

# ------------------------------------------------------------------ http
class Resp:
    def __init__(self, r):
        self.r = r; self.status = r.status_code; self.t = r.text
        try: self.j = r.json()
        except Exception: self.j = None
    def err(self): return (self.j or {}).get('error') if isinstance(self.j, dict) else None

S = requests.Session()
NONCE = ['']
def login():
    S.get(BASE + '/wp-login.php')
    r = S.post(BASE + '/wp-login.php', data={'log': 'scc_test_admin', 'pwd': PW, 'wp-submit': 'Log In', 'testcookie': '1'}, allow_redirects=False)
    assert r.status_code == 302, 'login failed'
    NONCE[0] = S.get(BASE + '/api/admin/session').json()['nonce']

def call(path, method='GET', data=None, *, anon=False, nonce=True, origin=BASE, ctype='application/json', raw=None, headers=None):
    h = {'Origin': origin} if method != 'GET' else {}
    if ctype and (data is not None or raw is not None or method != 'GET'): h['Content-Type'] = ctype
    if nonce and not anon and method != 'GET': h['X-WP-Nonce'] = NONCE[0]
    if headers: h.update(headers)
    body = raw if raw is not None else (json.dumps(data) if data is not None else (None if method == 'GET' else ''))
    sess = requests.Session() if anon else S
    return Resp(sess.request(method, BASE + '/api/admin/' + path.lstrip('/'), data=body.encode() if isinstance(body, str) else body, headers=h, allow_redirects=False))

def st(label, r, status, err=None):
    good = r.status == status and (err is None or r.err() == err)
    rec('PASS' if good else 'FAIL', label, '' if good else f'status {r.status} (want {status}) err={r.err()!r} want_err={err!r} body={r.t[:160]!r}')
    return r

# ------------------------------------------------------------------ db helpers (own rows only)
def sql(q):
    p = subprocess.run(['/home/claude/wps.sh', SITE, 'db', 'query', q, '--skip-column-names'], capture_output=True, text=True, timeout=120)
    return p.stdout.strip(), p.stderr.strip()
def sqljson(q):
    out, _ = sql(q); return out
CREATED = collections.defaultdict(set)
def track(table, i):
    if i: CREATED[table].add(i)
def q(s): return "'" + s.replace("\\", "\\\\").replace("'", "''") + "'"
def inlist(ids): return ','.join(q(i) for i in sorted(ids)) or "''"

SETTING_KEYS = ['club', 'store', 'business', 'integrations', 'catalogueInitialized', 'invoice', 'loyalty', 'home', 'operations', 'content']
SNAP = {}
def snapshot():
    # HEX() keeps the data byte-exact. REPLACE() in the query text makes wp-cli treat the statement as a write and return no rows,
    # which left SNAP empty and made restore() delete the settings (root cause of the vanishing settings, proven 2026-10-18).
    out, _ = sql(f"SELECT id, HEX(data), updated_at FROM {TBL('settings')} WHERE id IN ({inlist(SETTING_KEYS)})")
    assert out == '' or '\t' in out, 'settings snapshot failed: ' + out[:120]
    SNAP['settings'] = out
    SNAP['seq'], _ = sql(f"SELECT data, updated_at FROM {TBL('document_sequences')} WHERE id='transaction'")
    SNAP['seq_present'] = bool(SNAP['seq'])
    SNAP['docs0'] = int(sql(f"SELECT COUNT(*) FROM {TBL('invoice_documents')}")[0] or 0)
def restore():
    ids = inlist(SETTING_KEYS)
    sql(f"DELETE FROM {TBL('settings')} WHERE id IN ({ids})")
    for line in SNAP.get('settings', '').splitlines():
        parts = line.split('\t')
        if len(parts) == 3:
            sql(f"INSERT INTO {TBL('settings')} (id,data,updated_at) VALUES ({q(parts[0])},UNHEX('{parts[1]}'),{q(parts[2])})")
    sql(f"DELETE FROM {TBL('document_sequences')} WHERE id='transaction'")
    if SNAP.get('seq_present'):
        d, u = SNAP['seq'].split('\t')
        sql(f"INSERT INTO {TBL('document_sequences')} (id,data,updated_at) VALUES ('transaction',{q(d)},{q(u)})")
def cleanup():
    c = CREATED
    ords, prods, mems = c['orders'], c['products'], c['member_profiles'] | c['members']
    stmts = []
    for t in ['products', 'collections', 'customers', 'member_profiles', 'promotions', 'requests', 'activities', 'orders', 'members', 'product_links']:
        if c[t]: stmts.append(f"DELETE FROM {TBL(t)} WHERE id IN ({inlist(c[t])})")
    if prods: stmts.append(f"DELETE FROM {TBL('stock_movements')} WHERE JSON_VALUE(data,'$.productId') IN ({inlist(prods)})")
    if ords or mems:
        stmts.append(f"DELETE FROM {TBL('loyalty_ledger')} WHERE JSON_VALUE(data,'$.orderId') IN ({inlist(ords)}) OR JSON_VALUE(data,'$.memberId') IN ({inlist(mems)})")
    if ords:
        stmts.append(f"DELETE FROM {TBL('invoice_documents')} WHERE id IN ({inlist(ords)})")
        stmts.append(f"DELETE FROM {TBL('mail_jobs')} WHERE id IN ({inlist(ords | {'manager:' + o for o in ords})})")
        stmts.append(f"DELETE FROM {TBL('payment_transactions')} WHERE JSON_VALUE(data,'$.order_id') IN ({inlist(ords)})")
    for s in stmts: sql(s)
    restore()
    for f in c['media']:
        subprocess.run(['docker', 'exec', f'shem-staging-{SITE}-1', 'rm', '-f', f'/var/www/html/wp-content/uploads/scc-media/products/{f}'], capture_output=True)

PNG = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')
JPG = b'\xff\xd8\xff\xe0' + b'\x00' * 32
WEBP = b'RIFF\x1a\x00\x00\x00WEBPVP8L' + b'\x00' * 20

# ------------------------------------------------------------------ the suite
def product_body(**kw):
    b = dict(he=P + 'מוצר בדיקה', en=P + 'test piece', brand=BRAND, cat='clothing', price=199.9, previousPrice=249.9, stock=3, sizes=['S', 'M'], sizeStock={'S': 1, 'M': 2}, sku='T_ADMIN_SKU_1', image='knit', active=True)
    if IS_SHEM: b['productType'] = 'jackets'
    b.update(kw); return b
def variant_body(**kw):
    vs = [dict(id=P + 'black-s', size='S', color='black', sku='T_ADMIN_V-S-B', homeSku='T_ADMIN_V-S-B', stock=3), dict(id=P + 'ivory-m', size='M', color='ivory', sku='T_ADMIN_V-M-I', homeSku='T_ADMIN_V-M-I', stock=3)]
    return product_body(he=P + 'מוצר גרסאות', sku='T_ADMIN_V', homeSku='T_ADMIN_V', price=199.9, previousPrice=0, stock=6, sizeStock={'S': 3, 'M': 3}, variants=vs, color='black', **kw)

def run():
    login(); snapshot()
    cust = dict(name=P + 'customer', email=P + 'c1@example.test', phone='0500000000', city='Test city', street='Test 1')

    # ---------------------------------------------------------------- auth / session  (admin-api.test.mjs)
    screen('auth+session')
    st('anonymous GET products -> 401', call('products', anon=True), 401, 'יש להתחבר לחשבון המורשה כדי לנהל את החנות.')
    st('anonymous POST products -> 401', call('products', 'POST', {}, anon=True), 401)
    st('anonymous GET manage/orders -> 401', call('manage/orders', anon=True), 401)
    st('anonymous GET audit -> 401', call('audit', anon=True), 401)
    s = st('session 200', call('session'), 200)
    eq('session shape', sorted((s.j or {}).keys()), sorted(['authorized', 'contactEmail', 'signedInEmail', 'ownershipTransferred', 'localPreview', 'nonce']))
    eq('session authorized/contact', ((s.j or {}).get('authorized'), (s.j or {}).get('contactEmail')), (True, 'your.shem88@gmail.com'))
    st('write without nonce -> 401', call('products', 'POST', product_body(), nonce=False), 401)
    st('write with wrong nonce -> 401', call('products', 'POST', product_body(), nonce=False, headers={'X-WP-Nonce': 'bad'}), 401)
    st('POST from foreign origin -> 403 Origin rejected', call('products', 'POST', {}, origin='https://evil.test'), 403, 'Origin rejected')
    st('POST with non-JSON content-type -> 415', call('products', 'POST', '{}', ctype='text/plain'), 415, 'JSON required')
    st('POST body > 1MB -> 413', call('products', 'POST', raw='{"a":"' + 'x' * 1100000 + '"}'), 413, 'Request too large')
    st('POST invalid JSON -> 400 Invalid JSON', call('products', 'POST', raw='{nope'), 400, 'Invalid JSON')
    me = requests.get(BASE + '/api/session', cookies=S.cookies).json()
    eq('GET /api/session (logged in)', (me.get('signedIn'), me.get('email')), (True, 'scc_test_admin@example.test'))
    eq('GET /api/session (anonymous)', requests.get(BASE + '/api/session').json(), {'signedIn': False, 'userId': None, 'email': None})
    rr = requests.get(BASE + '/signin-with-chatgpt?return_to=/admin.html', allow_redirects=False)
    ok('/signin-with-chatgpt redirects to wp-login', rr.status_code == 302 and 'wp-login.php' in rr.headers.get('location', ''), rr.headers.get('location'))
    rr = requests.get(BASE + '/signin-with-chatgpt?return_to=//evil.test', allow_redirects=False)
    ok('return_to open redirect is neutralised', 'evil.test' not in rr.headers.get('location', '').replace('wp-login.php', '').split('redirect_to=')[-1] or '%2F%2Fevil' not in rr.headers.get('location', ''), rr.headers.get('location'))
    notrun('non-admin WP user -> 403', 'Scc_Router::is_admin always answers 401 (shared class); original answered 403 for a signed-in non-manager')

    # ---------------------------------------------------------------- products
    screen('products')
    st('invalid product (price -1) -> 400', call('products', 'POST', dict(he='Invalid', price=-1, stock=0)), 400, 'יש להזין שם, מחיר חיובי ומלאי תקין.')
    r = st('create product -> 201', call('products', 'POST', product_body()), 201); p = r.j or {}; track('products', p.get('id'))
    ok('create: revision + id + price + previousPrice', all([p.get('revision'), p.get('id'), p.get('price') == 199.9, p.get('previousPrice') == 249.9, p.get('stock') == 3]), r.t[:200])
    eq('create: sizeStock object', p.get('sizeStock'), {'S': 1, 'M': 2})
    if IS_SHEM: eq('create: productType + presentation keys', (p.get('productType'), 'images' in p, 'sizeGuide' in p, 'colors' in p), ('jackets', True, True, True))
    else: ok('SHIMI record has no productType/images/sizeGuide', not any(k in p for k in ['productType', 'images', 'sizeGuide', 'colors']), list(p.keys()))
    st('duplicate sku -> 409', call('products', 'POST', product_body()), 409, 'מק"ט זה כבר קיים.')
    st('sizeStock sum mismatch -> 400', call('products', 'POST', product_body(sku='t_admin_bad', sizeStock={'S': 1, 'M': 1})), 400, 'מלאי כולל חייב להתאים לסכום המידות.')
    st('previousPrice invalid', call('products', 'POST', product_body(sku='t_admin_pp', previousPrice=-5)), 400, 'מחיר קודם אינו תקין.')
    st('duplicate sizes', call('products', 'POST', product_body(sku='t_admin_ds', sizes=['S', 'S'])), 400, 'יש להסיר מידות כפולות.')
    st('no sizes', call('products', 'POST', product_body(sku='t_admin_ns', sizes=[], sizeStock=None)), 400, 'יש להזין לפחות מידה אחת.')
    st('active without image', call('products', 'POST', product_body(sku='t_admin_ni', image='', imageUrl='')), 400, 'יש להעלות תמונת מוצר לפני הצגתו בחנות.')
    st('bad image URL', call('products', 'POST', product_body(sku='t_admin_bu', imageUrl='/x/../etc')), 400, 'Invalid image URL')
    st('bad image reference', call('products', 'POST', product_body(sku='t_admin_br', image='a b')), 400, 'Invalid image reference')
    st('sizeStock negative', call('products', 'POST', product_body(sku='t_admin_sn', stock=1, sizeStock={'S': -1, 'M': 2})), 400, 'מלאי לפי מידה אינו תקין.')
    st('PUT unknown id -> 404', call('products/t_admin_missing', 'PUT', product_body()), 404, 'הרשומה לא נמצאה.')
    st('PUT stale revision -> 409', call('products/' + p['id'], 'PUT', {**p, 'revision': 'stale'}), 409, 'הרשומה השתנתה. רעננו לפני שמירה.')
    r = st('PUT price change -> 200', call('products/' + p['id'], 'PUT', {**p, 'price': 149}), 200); p2 = r.j or {}
    ok('PUT: new revision, old revision rejected', p2.get('revision') and p2['revision'] != p['revision'] and p2.get('price') == 149)
    st('PUT with the previous revision now conflicts', call('products/' + p['id'], 'PUT', {**p, 'price': 150}), 409)
    g = st('GET one product', call('products/' + p['id']), 200)
    eq('GET one product price', (g.j or {}).get('price'), 149)
    lst = call('products').j or []
    ok('list contains product, newest first', any(x.get('id') == p['id'] for x in lst) and lst[0].get('id') == p['id'])
    if IS_SHEM:
        r = st('PUT productType knitwear', call('products/' + p['id'], 'PUT', {**p2, 'productType': 'knitwear'}), 200); p2 = r.j or {}
        eq('productType stored', p2.get('productType'), 'knitwear')
        r = st('PUT invalid productType normalised', call('products/' + p['id'], 'PUT', {**p2, 'productType': 'bogus'}), 200); p2 = r.j or {}
        eq('invalid productType -> empty', p2.get('productType'), '')
        st('presentation: >3 images', call('products/' + p['id'], 'PUT', {**p2, 'images': [{'url': f'/assets/a{i}.png'} for i in range(4)]}), 400, 'ניתן להוסיף עד שלוש תמונות מוצר שהועלו לאתר.')
        st('presentation: size guide row width', call('products/' + p['id'], 'PUT', {**p2, 'sizeGuide': {'columns': ['חזה'], 'rows': [{'size': 'S', 'values': []}]}}), 400, 'יש להזין מידה וערך לכל עמודה בטבלת המידות.')
        r = st('presentation: valid guide + colors', call('products/' + p['id'], 'PUT', {**p2, 'sizeGuide': {'columns': ['חזה'], 'rows': [{'size': 'S', 'values': ['50']}]}, 'colors': [{'name': 'שחור', 'hex': '#000000'}], 'images': [{'url': '/assets/knit.png', 'alt': 'x'}]}), 200); p2 = r.j or {}
        eq('presentation stored', (p2['sizeGuide']['columns'], p2['colors'][0]['name'], p2['images'][0]['url']), (['חזה'], 'שחור', '/assets/knit.png'))
    r = st('PUT stock change (movement) -> 200', call('products/' + p['id'], 'PUT', {**p2, 'stock': 4, 'sizeStock': {'S': 1, 'M': 3}}), 200); p2 = r.j or {}
    mv = [m for m in (call('manage/stock').j or []) if m.get('productId') == p['id']]
    ok('stock movements: opening + edit written (system)', {'מלאי פתיחה', 'עריכת מלאי בכרטיס מוצר'} <= {m.get('reason') for m in mv} and all(m.get('system') is True for m in mv), mv)
    r = st('deactivate -> 200', call('products/' + p['id'], 'PUT', {**p2, 'active': False}), 200); p2 = r.j or {}
    eq('deactivated', p2.get('active'), False)
    r = st('archive product (manage)', call('manage/products/' + p['id'] + '/archive', 'POST', {}), 200)
    ok('archive: archived true, previousActive false, active false', (r.j or {}).get('archived') is True and (r.j or {}).get('previousActive') is False and (r.j or {}).get('active') is False, r.t[:200])
    pa = call('products/' + p['id']).j or {}
    st('PUT on archived product -> 409', call('products/' + p['id'], 'PUT', pa), 409, 'יש לשחזר מוצר מהארכיון לפני עריכה.')
    r = st('restore product', call('manage/products/' + p['id'] + '/restore', 'POST', {}), 200)
    eq('restore: archived false', (r.j or {}).get('archived'), False)
    st('manage/products POST -> 405 dedicated action', call('manage/products', 'POST', product_body()), 405, 'יש להשתמש בפעולה הייעודית.')
    st('manage/products/:id PUT -> 405', call('manage/products/' + p['id'], 'PUT', {}), 405, 'יש להשתמש בפעולה הייעודית.')
    st('archive POST needs a JSON body', call('manage/products/' + p['id'] + '/archive', 'POST', raw=''), 400, 'בקשה לא תקינה.')

    # variants + inventory (management.test.mjs)
    screen('inventory')
    vb = variant_body()
    r = st('create product with variants -> 201', call('products', 'POST', vb), 201); v = r.j or {}; track('products', v.get('id'))
    st('variant outside sizes -> 400', call('products/' + v['id'], 'PUT', {**v, 'variants': [{'id': 'bad', 'size': 'XX', 'color': 'black', 'sku': 'BAD', 'stock': 6}]}), 400, 'גרסאות מוצר אינן תקינות או כפולות.')
    st('variant stock does not match total', call('products', 'POST', {**vb, 'sku': 'T_ADMIN_V2', 'stock': 7}), 400)
    st('variant sku reused by another product -> 409', call('products', 'POST', {**vb, 'sku': 'T_ADMIN_V3', 'variants': [{**vb['variants'][0], 'sku': 'T_ADMIN_V-S-B'}, {**vb['variants'][1], 'id': P + 'x-m', 'sku': 'T_ADMIN_V-M-X'}]}), 409, 'מק״ט גרסה כבר משויך למוצר אחר.')
    st('duplicate variant combo', call('products/' + v['id'], 'PUT', {**v, 'variants': [vb['variants'][0], {**vb['variants'][0], 'id': 'z', 'sku': 'ZZ'}], 'stock': 6, 'sizeStock': {'S': 6, 'M': 0}}), 400, 'גרסאות מוצר אינן תקינות או כפולות.')
    inv = {'productId': v['id'], 'variantId': P + 'black-s', 'size': 'S', 'delta': 2, 'reason': 'Received stock', 'revision': v['revision']}
    r = st('inventory +2 on variant', call('manage/inventory', 'POST', inv), 200); u = (r.j or {}).get('product', {})
    eq('inventory: total 8, variant 5, sizeStock S 5', (u.get('stock'), u['variants'][0]['stock'], u['sizeStock']['S']), (8, 5, 5))
    ok('inventory: movement (non-system) returned', (r.j or {}).get('movement', {}).get('system') is False and (r.j or {}).get('movement', {}).get('delta') == 2, r.t[:300])
    st('inventory negative result -> 400', call('manage/inventory', 'POST', {**inv, 'delta': -10, 'revision': None, 'reason': 'too much'}), 400, 'המלאי לא יכול להיות שלילי.')
    st('inventory stale revision -> 409', call('manage/inventory', 'POST', {**inv, 'delta': 1, 'reason': 'stale'}), 409, 'המלאי השתנה. רעננו לפני עדכון.')
    st('inventory delta 0 -> 400', call('manage/inventory', 'POST', {**inv, 'delta': 0, 'revision': None}), 400, 'נדרשת כמות שינוי וסיבה.')
    st('inventory without reason -> 400', call('manage/inventory', 'POST', {**inv, 'reason': '', 'revision': None}), 400, 'נדרשת כמות שינוי וסיבה.')
    st('inventory variant required', call('manage/inventory', 'POST', {'productId': v['id'], 'delta': 1, 'reason': 'x'}), 400, 'בחרו גרסת מוצר.')
    st('inventory unknown product', call('manage/inventory', 'POST', {'productId': 'nope', 'delta': 1, 'reason': 'x'}), 404, 'מוצר לא נמצא.')
    r = st('inventory on plain product by size', call('manage/inventory', 'POST', {'productId': p['id'], 'size': 'M', 'delta': 1, 'reason': 'size add'}), 200)
    eq('inventory by size: M 4, stock 5', ((r.j or {}).get('product', {}).get('sizeStock', {}).get('M'), (r.j or {}).get('product', {}).get('stock')), (4, 5))
    st('inventory unknown size', call('manage/inventory', 'POST', {'productId': p['id'], 'size': 'XXL', 'delta': 1, 'reason': 'x'}), 400, 'מידה אינה קיימת.')
    ok('manage/stock lists the movements', len([m for m in (call('manage/stock').j or []) if m.get('productId') in (v['id'], p['id'])]) >= 4)
    if not IS_SHEM:
        sql(f"INSERT INTO {TBL('product_links')} (id,data,updated_at) VALUES ({q(v['id'])},'{{\"status\":\"connected\"}}',{q('2026-01-01T00:00:00.000Z')})"); track('product_links', v['id'])
        cur = call('products/' + v['id']).j
        st('SHIMI linked product: price edit blocked', call('products/' + v['id'], 'PUT', {**cur, 'price': 1}), 409, 'מק״ט, מחיר וגרסאות של מוצר מקושר מנוהלים דרך SHEM. נתקו חיבור לפני שינוי.')
        st('SHIMI linked product: inventory blocked', call('manage/inventory', 'POST', {'productId': v['id'], 'variantId': P + 'black-s', 'size': 'S', 'delta': 1, 'reason': 'x'}), 409, 'המלאי של מוצר מקושר מנוהל ב-SHEM.')
        sql(f"DELETE FROM {TBL('product_links')} WHERE id={q(v['id'])}")
    else:
        notrun('linked-product guards (SHIMI only)', 'SHEM role: guard applies to SHIMI')
    ok('status.lowStock / unmapped reflect catalogue', isinstance((call('manage/status').j or {}).get('lowStock'), list))

    # ---------------------------------------------------------------- media
    screen('products (media)')
    r = st('upload PNG -> 201', call('media', 'POST', raw=PNG, ctype='image/png'), 201); url = (r.j or {}).get('imageUrl', '')
    ok('imageUrl shape', re.fullmatch(r'/media/products/[0-9a-f-]{36}\.png', url) is not None, url); track('media', url.rsplit('/', 1)[-1])
    a = requests.get(BASE + url); eq('public GET media', (a.status_code, a.headers.get('content-type'), a.content), (200, 'image/png', PNG))
    h = requests.head(BASE + url); eq('public HEAD media', (h.status_code, h.headers.get('content-type')), (200, 'image/png'))
    r = st('upload JPEG', call('media', 'POST', raw=JPG, ctype='image/jpeg'), 201); track('media', (r.j or {}).get('imageUrl', '').rsplit('/', 1)[-1])
    r = st('upload WebP', call('media', 'POST', raw=WEBP, ctype='image/webp'), 201); track('media', (r.j or {}).get('imageUrl', '').rsplit('/', 1)[-1])
    st('upload with wrong magic bytes -> 400', call('media', 'POST', raw=JPG, ctype='image/png'), 400, 'תוכן הקובץ אינו תואם לסוג התמונה.')
    st('upload GIF type -> 400', call('media', 'POST', raw=b'GIF89a', ctype='image/gif'), 400, 'יש להעלות PNG, JPG או WebP בלבד.')
    st('upload > 8MB -> 413', call('media', 'POST', raw=b'\x89PNG' + b'0' * (8 * 1024 * 1024 + 10), ctype='image/png'), 413, 'התמונה גדולה מדי. עד 8MB.')
    st('upload anonymous -> 401', call('media', 'POST', raw=PNG, ctype='image/png', anon=True), 401)
    eq('unknown media file -> 404', requests.get(BASE + '/media/products/nope.png').status_code, 404)
    eq('path traversal in media -> not served', requests.get(BASE + '/media/products/..%2F..%2Fwp-config.php').status_code in (404, 400, 403), True)
    st('product with uploaded imageUrl', call('products', 'POST', product_body(sku='T_ADMIN_IMG', image='', imageUrl=url)), 201); track('products', (call('products').j or [{}])[0].get('id'))

    # ---------------------------------------------------------------- promotions
    screen('promotions')
    r = st('create promotion', call('promotions', 'POST', dict(code='t_admin_launch', discount=10, active=True, minSubtotal=150, startsAt='2020-01-01', endsAt='2100-01-01')), 201); pr = r.j or {}; track('promotions', pr.get('id'))
    eq('code upper-cased, numbers typed', (pr.get('code'), pr.get('discount'), pr.get('minSubtotal'), pr.get('active'), pr.get('excludeSale')), ('T_ADMIN_LAUNCH', 10, 150, True, False))
    st('duplicate code (case-insensitive) -> 409', call('promotions', 'POST', dict(code='T_admin_LAUNCH', discount=20, active=True)), 409, 'קוד זה כבר קיים.')
    st('discount 0', call('promotions', 'POST', dict(code='T_ADMIN_Z', discount=0)), 400, 'יש להזין קוד ואחוז הנחה בין 1 ל-100.')
    st('discount 101', call('promotions', 'POST', dict(code='T_ADMIN_Z', discount=101)), 400, 'יש להזין קוד ואחוז הנחה בין 1 ל-100.')
    st('code charset', call('promotions', 'POST', dict(code='bad code!', discount=5)), 400, 'הקוד יכול להכיל אותיות באנגלית, מספרים ומקף.')
    st('invalid date', call('promotions', 'POST', dict(code='T_ADMIN_D', discount=5, startsAt='2026-13-01')), 400, 'תאריך לא תקין.')
    st('end before start', call('promotions', 'POST', dict(code='T_ADMIN_E', discount=5, startsAt='2026-05-01', endsAt='2026-04-01')), 400, 'תאריך הסיום קודם לתאריך ההתחלה.')
    st('negative minimum', call('promotions', 'POST', dict(code='T_ADMIN_M', discount=5, minSubtotal=-1)), 400, 'סכום מינימום אינו תקין.')
    r = st('2026-02-31 accepted like Date.parse', call('promotions', 'POST', dict(code='T_ADMIN_FEB', discount=5, startsAt='2026-02-31')), 201); track('promotions', (r.j or {}).get('id'))
    r = st('PUT excludeSale', call('promotions/' + pr['id'], 'PUT', {**pr, 'excludeSale': True}), 200); pr2 = r.j or {}
    eq('excludeSale stored', pr2.get('excludeSale'), True)
    st('PUT stale -> 409', call('promotions/' + pr['id'], 'PUT', {**pr, 'excludeSale': False}), 409)
    r = st('archive promotion (manage)', call('manage/promotions/' + pr['id'] + '/archive', 'POST', {}), 200)
    ok('archive: active false, previousActive true', (r.j or {}).get('active') is False and (r.j or {}).get('previousActive') is True and (r.j or {}).get('archived') is True)
    r = st('restore promotion', call('manage/promotions/' + pr['id'] + '/restore', 'POST', {}), 200)
    ok('restore: active true again', (r.j or {}).get('active') is True and (r.j or {}).get('archived') is False)
    ok('GET promotions list', any(x.get('id') == pr['id'] for x in (call('promotions').j or [])))
    st('promotions DELETE not supported', call('promotions/' + pr['id'], 'DELETE', {}), 405)

    # ---------------------------------------------------------------- collections (both flavours)
    screen('collections')
    r = st('create collection (operations)', call('collections', 'POST', dict(title=P + 'Summer', productIds=[p['id']], active=True)), 201); col = r.j or {}; track('collections', col.get('id'))
    st('collection with missing product -> 400', call('collections', 'POST', dict(title='Invalid', productIds=['missing'], active=True)), 400, 'הקולקציה כוללת מוצר שאינו קיים.')
    st('collection without title -> 400', call('collections', 'POST', dict(productIds=[])), 400, 'יש להזין שם קולקציה.')
    r = st('PUT collection', call('collections/' + col['id'], 'PUT', {**col, 'title': P + 'Summer 2'}), 200)
    st('PUT collection stale -> 409', call('collections/' + col['id'], 'PUT', {**col, 'title': 'Stale', 'revision': 'outdated'}), 409)
    st('PUT collection unknown -> 404', call('collections/missing', 'PUT', {'title': 'x'}), 404, 'הרשומה לא נמצאה.')
    st('collections anonymous -> 401', call('collections', anon=True), 401)
    r = st('create collection (manage)', call('manage/collections', 'POST', dict(title=P + 'Autumn', productIds=[p['id'], p['id']], active=True)), 201); mc = r.j or {}; track('collections', mc.get('id'))
    eq('manage: productIds de-duplicated, archived false', (mc.get('productIds'), mc.get('archived'), mc.get('position')), ([p['id']], False, 0))
    st('manage collection missing product', call('manage/collections', 'POST', dict(title='x', productIds=['missing'])), 400, 'מוצר לא קיים בקולקציה.')
    st('manage collection no title', call('manage/collections', 'POST', dict(productIds=[])), 400, 'נדרש שם קולקציה.')
    r = st('archive collection', call('manage/collections/' + mc['id'] + '/archive', 'POST', {'revision': mc['revision']}), 200)
    ok('archived + inactive', (r.j or {}).get('archived') is True and (r.j or {}).get('active') is False)
    ok('admin list shows archived flag', any(x.get('id') == mc['id'] and x.get('archived') is True for x in (call('manage/collections').j or [])))
    st('restore collection', call('manage/collections/' + mc['id'] + '/restore', 'POST', {}), 200)
    st('manage collection stale revision -> 409', call('manage/collections/' + mc['id'], 'PUT', {**mc, 'title': 'Stale', 'revision': 'outdated'}), 409, 'הרשומה השתנתה. רעננו לפני שמירה.')

    # ---------------------------------------------------------------- customers (from orders)
    screen('customers (crm)')
    r = st('create customer (operations)', call('customers', 'POST', dict(name='Test customer', email=P + 'cust@example.test')), 201); track('customers', (r.j or {}).get('id'))
    st('duplicate customer (operations) -> 409', call('customers', 'POST', dict(name='Duplicate', email=P.upper() + 'CUST@example.test')), 409, 'כרטיס לקוח עם אימייל זה כבר קיים.')
    st('customer invalid email -> 400', call('customers', 'POST', dict(name='x', email='nope')), 400, 'נדרשים שם ואימייל תקינים.')
    st('customers anonymous -> 401', call('customers', anon=True), 401)
    r = st('create customer (manage)', call('manage/customers', 'POST', cust), 201); c1 = r.j or {}; track('customers', c1.get('id'))
    st('duplicate customer (manage) -> 409', call('manage/customers', 'POST', cust), 409, 'אימייל לקוח כבר קיים.')
    st('PUT customer notes', call('manage/customers/' + c1['id'], 'PUT', {**c1, 'notes': 'vip', 'tags': 'a,b'}), 200)
    st('archive customer', call('manage/customers/' + c1['id'] + '/archive', 'POST', {}), 200)
    st('after archive the e-mail can be reused', call('manage/customers', 'POST', cust), 201)
    track('customers', (call('manage/customers').j or [{}])[0].get('id'))

    # ---------------------------------------------------------------- member cards + club members
    screen('club cards + members')
    r = st('create member card', call('manage/cards', 'POST', {**cust, 'tier': 'standard', 'status': 'active'}), 201); card = r.j or {}; track('member_profiles', card.get('id'))
    ok('cardNumber format', str(card.get('cardNumber', '')).startswith(BRAND + '-') and len(card.get('cardNumber', '')) == len(BRAND) + 13, card.get('cardNumber'))
    st('duplicate card e-mail -> 409', call('manage/cards', 'POST', {**cust, 'tier': 'standard'}), 409, 'חבר עם אימייל זה כבר קיים.')
    st('invalid tier -> 400', call('manage/cards', 'POST', {**cust, 'email': P + 'c9@example.test', 'tier': 'gold'}), 400, 'דרגת חברות לא תקינה.')
    ok('cards list has effectiveTier', all('effectiveTier' in c for c in (call('manage/cards').j or [])))
    sql(f"INSERT INTO {TBL('members')} (id,data,updated_at) VALUES ({q(P + 'm1')},{q(json.dumps({'email': P + 'm1@example.test', 'status': 'active', 'consent_at': '2026-01-01T00:00:00.000Z', 'created_at': '2026-01-01T00:00:00.000Z'}))},'2026-01-01T00:00:00.000Z')"); track('members', P + 'm1')
    ml = [m for m in (call('members').j or []) if m['id'] == P + 'm1']
    eq('members list shape', sorted(ml[0].keys()) if ml else None, ['consent_at', 'created_at', 'email', 'id', 'status'])
    r = st('member profile (operations PUT)', call('member-profiles/' + P + 'm1', 'PUT', dict(name='Member', tier='vip', phone='0500000000')), 200); track('member_profiles', P + 'm1')
    ok('profile cardNumber prefix', str((r.j or {}).get('cardNumber', '')).startswith(BRAND + '-'))
    st('member profile for missing member -> 404', call('member-profiles/missing', 'PUT', dict(tier='vip')), 404, 'חבר לא נמצא.')
    st('member profile bad tier', call('member-profiles/' + P + 'm1', 'PUT', dict(tier='x')), 400, 'דרגת מועדון לא תקינה.')
    st('members PATCH missing -> 404', call('members/missing', 'PATCH', {'status': 'unsubscribed'}), 404, 'החבר לא נמצא.')
    st('members PATCH active -> 409 (needs new consent)', call('members/' + P + 'm1', 'PATCH', {'status': 'active'}), 409, 'הפעלת דיוור מחדש דורשת הסכמה חדשה מהלקוח.')
    st('members PATCH invalid status', call('members/' + P + 'm1', 'PATCH', {'status': 'x'}), 400, 'Invalid status')
    r = st('members PATCH unsubscribed', call('members/' + P + 'm1', 'PATCH', {'status': 'unsubscribed'}), 200)
    eq('PATCH ok body', r.j, {'ok': True})
    eq('member now unsubscribed', [m['status'] for m in (call('members').j or []) if m['id'] == P + 'm1'], ['unsubscribed'])
    st('members POST -> not supported', call('members', 'POST', {}), 400, 'Invalid operation')

    # loyalty settings + ledger
    screen('settings (loyalty/invoice/ops/content/home/business)')
    st('loyalty: invalid percent', call('manage/settings/loyalty', 'PUT', {'earnPercent': 120, 'expiryDays': 365}), 400, 'אחוז הטבה חייב להיות בין 0 ל-100.')
    st('loyalty: expiryDays 0', call('manage/settings/loyalty', 'PUT', {'earnPercent': 3, 'expiryDays': 0}), 400, 'ימי המתנה, תוקף ומספר הזמנות אינם תקינים.')
    st('loyalty: out of range', call('manage/settings/loyalty', 'PUT', {'earnPercent': -1, 'expiryDays': 365}), 400, 'הגדרת הטבה אינה תקינה.')
    loy = {'enabled': True, 'earnPercent': 3, 'maxRedeemPercent': 10, 'vipSpend': 300, 'vipOrders': 2, 'pendingDays': 14, 'expiryDays': 365, 'grossMarginPercent': 20}
    r = st('loyalty PUT', call('manage/settings/loyalty', 'PUT', loy), 200)
    eq('loyalty stored (typed)', {k: (r.j or {}).get(k) for k in loy}, loy)
    eq('loyalty GET equals PUT', call('manage/settings/loyalty').j, r.j)
    inv_s = {'displayName': BRAND, 'legalName': 'Example business', 'registrationNumber': 'TEST-ONLY', 'address': 'Test address', 'phone': '0500000000', 'email': 'support@example.test', 'vatMode': 'included', 'vatRate': 18, 'numberPrefix': 'T_ADMIN-', 'documentTitle': 'חשבונית עסקה', 'documentFooter': 'Testing'}
    st('invoice: bad vat mode', call('manage/settings/invoice', 'PUT', {**inv_s, 'vatMode': 'zero'}), 400, 'מצב מע״מ אינו תקין.')
    st('invoice: bad e-mail', call('manage/settings/invoice', 'PUT', {**inv_s, 'email': 'nope'}), 400, 'אימייל עסק אינו תקין.')
    r = st('invoice PUT', call('manage/settings/invoice', 'PUT', inv_s), 200)
    eq('invoice GET vatRate', (call('manage/settings/invoice').j or {}).get('vatRate'), 18)
    st('home: http rejected', call('manage/settings/home', 'PUT', {'siteUrl': 'http://x.test'}), 400, 'כתובת אתר הבית חייבת להיות HTTPS.')
    st('home: creds in URL rejected', call('manage/settings/home', 'PUT', {'siteUrl': 'https://u:p@x.test'}), 400, 'כתובת אתר הבית אינה תקינה.')
    st('home: protocol', call('manage/settings/home', 'PUT', {'protocolVersion': 'v9'}), 400, 'גרסת החיבור הנתמכת היא shem-home-v1.')
    r = st('home PUT', call('manage/settings/home', 'PUT', {'siteUrl': 'https://shimi.example.test', 'enabled': False}), 200)
    eq('home defaults', ((r.j or {}).get('protocolVersion'), (r.j or {}).get('priceAuthority'), (r.j or {}).get('embedded')), ('shem-home-v1', 'manager', True))
    st('operations: invalid number', call('manage/settings/operations', 'PUT', {'standardPrice': -1}), 400, 'הגדרה תפעולית אינה תקינה.')
    st('operations: invalid instagram', call('manage/settings/operations', 'PUT', {'instagram': 'bad name!'}), 400, 'פרטי אינסטגרם או וואטסאפ אינם תקינים.')
    ops = {'standardPrice': 59, 'expressPrice': 199, 'standardDays': 7, 'expressDays': 1, 'lowStockThreshold': 3}
    r = st('operations PUT', call('manage/settings/operations', 'PUT', ops), 200)
    eq('operations typed + defaults', {k: (r.j or {}).get(k) for k in ops} | {'insta': (r.j or {}).get('instagram')}, ops | {'insta': 'new.shem'})
    blocks = [{'id': 'one', 'title': 'Content block', 'text': 'Visible', 'active': True, 'position': 0, 'link': '#/shop', 'label': 'Shop'}, {'title': 'x', 'link': 'http://evil'}]
    r = st('content PUT', call('manage/settings/content', 'PUT', {'heroTitle': 'NEW', 'blocks': blocks, 'heroImages': ['/assets/a.png', 'http://evil/x.png', '/assets/../x']}), 200)
    eq('content: link filter, heroImages filter, default ids', ((r.j or {}).get('blocks')[0]['title'], (r.j or {}).get('blocks')[1]['link'], (r.j or {}).get('heroImages'), len((r.j or {}).get('blocks')[1]['id'])), ('Content block', '', ['/assets/a.png'], 36))
    eq('content defaults', ((r.j or {}).get('arrivalsTitle'), (r.j or {}).get('saleTitle')), ('חדשים באתר שלנו', 'פריטים בסוף עונה'))
    r = st('business (manage) PUT', call('manage/settings/business', 'PUT', {'legalName': 'Test company', 'phone': '0500000000', 'junk': 'x'}), 200)
    ok('business: unknown keys dropped', 'junk' not in (r.j or {}))
    st('manage/settings/<unknown> -> 404', call('manage/settings/other'), 404)
    st('manage/settings DELETE -> 404 (only GET/PUT exist)', call('manage/settings/loyalty', 'DELETE', {}), 404)

    # club settings (api.js)
    st('settings club enabled without privacyUrl -> 400', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': True, 'title': 'Club', 'privacyUrl': ''}}), 400, 'כדי להפעיל הרשמה יש להזין קישור למדיניות פרטיות.')
    st('settings club ok', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': True, 'title': 'Club', 'privacyUrl': 'https://example.com/privacy'}}), 200)
    st('settings club "#/privacy" accepted', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': True, 'title': 'Club', 'privacyUrl': '#/privacy'}}), 200)
    st('settings adminPrincipal -> 400', call('settings', 'PUT', {'key': 'adminPrincipal', 'value': 'other'}), 400, 'Invalid setting')
    st('settings store ok', call('settings', 'PUT', {'key': 'store', 'value': {'heroSubtitle': 'שמירה מסונכרנת', 'brands': [P + 'B1', ' ', P + 'B2']}}), 200)
    sg = call('settings').j or {}
    eq('settings GET store', (sg['store']['heroSubtitle'], sg['store']['brands'], sg['store']['contactEmail']), ('שמירה מסונכרנת', [P + 'B1', P + 'B2'], 'your.shem88@gmail.com'))
    st('settings business ok', call('settings', 'PUT', {'key': 'business', 'value': {'legalName': 'Test company', 'registrationNumber': 'TEST', 'address': 'Test address', 'phone': '0500000000'}}), 200)
    eq('health.businessComplete', (call('health').j or {}).get('businessComplete'), True)
    st('settings integrations ok', call('settings', 'PUT', {'key': 'integrations', 'value': {'paymentProvider': 'Not yet selected', 'merchantId': 'test', 'secret': 'must-not-be-saved'}}), 200)
    sg = call('settings').j or {}
    ok('integrations: secret not saved', 'secret' not in sg['integrations'], sg['integrations'])
    eq('integration-status', call('integration-status').j, {'payment': False, 'invoicing': False, 'automatic': False, 'reason': 'נדרשים בחירת ספקים, מתאמים רשמיים ופרטי גישה.'})
    st('catalogueInitialized set by product write', call('settings'), 200)
    ok('catalogueInitialized true', (call('settings').j or {}).get('catalogueInitialized') is True)

    # ---------------------------------------------------------------- orders
    screen('orders')
    PAY = {'customer': cust, 'items': [{'productId': v['id'], 'variantId': P + 'black-s', 'size': 'S', 'qty': 2}, {'productId': v['id'], 'variantId': P + 'ivory-m', 'size': 'M', 'qty': 1}], 'shippingCost': 49, 'discount': 0, 'status': 'draft'}
    r = st('create manual order', call('manage/orders', 'POST', {**PAY, 'total': 1}), 201); o = r.j or {}; track('orders', o.get('id'))
    eq('order totals', (o.get('totalCents'), o.get('subtotalCents'), o.get('paymentStatus'), o.get('paid'), o.get('status'), o.get('source')), (64870, 59970, 'unpaid', False, 'draft', 'manual'))
    eq('order total (money)', o.get('total'), 648.7)
    ok('order vat fields', o.get('vatItemsCents') == round(59970 * 18 / 118) and o.get('vatShippingCents') == round(4900 * 18 / 118), r.t[:300])
    ok('order item snapshot', o['items'][0]['sku'] == 'T_ADMIN_V-S-B' and o['items'][0]['priceCents'] == 19990 and o['items'][0]['color'] == 'black', o['items'][0])
    if IS_SHEM: ok('shipmentHistory seeded', o.get('shipmentHistory') and o['shipmentHistory'][0]['status'] == 'draft', o.get('shipmentHistory'))
    st('order without items', call('manage/orders', 'POST', {'customer': cust, 'items': []}), 400, 'יש להוסיף פריטים להזמנה.')
    st('order bad item', call('manage/orders', 'POST', {**PAY, 'items': [{'productId': v['id'], 'variantId': 'wrong', 'size': 'S', 'qty': 1}]}), 400, 'יש לבחור גרסת מוצר.')
    st('order bad qty', call('manage/orders', 'POST', {**PAY, 'items': [{'productId': v['id'], 'variantId': P + 'black-s', 'size': 'S', 'qty': 0}]}), 400, 'פריט, מידה או כמות אינם תקינים.')
    st('order variant/size mismatch', call('manage/orders', 'POST', {**PAY, 'items': [{'productId': v['id'], 'variantId': P + 'black-s', 'size': 'M', 'qty': 1}]}), 400, 'המידה אינה תואמת לגרסה.')
    st('order duplicate lines', call('manage/orders', 'POST', {**PAY, 'items': [PAY['items'][0], PAY['items'][0]]}), 400, 'יש לאחד פריטים כפולים.')
    st('order discount above subtotal', call('manage/orders', 'POST', {**PAY, 'discount': 100000}), 400, 'משלוח או הנחה אינם תקינים.')
    st('order bad customer', call('manage/orders', 'POST', {**PAY, 'customer': {'name': '', 'email': 'x'}}), 400, 'שם ואימייל תקינים נדרשים.')
    r = st('edit unpaid order (PUT recalculates)', call('manage/orders/' + o['id'], 'PUT', {**PAY, 'discount': 10, 'status': 'new', 'revision': o['revision']}), 200); o2 = r.j or {}
    eq('recalculated total', o2.get('totalCents'), 64870 - 1000)
    eq('id/createdAt kept', (o2.get('id'), o2.get('createdAt')), (o['id'], o['createdAt']))
    st('edit with stale revision -> 409', call('manage/orders/' + o['id'], 'PUT', {**PAY, 'revision': o['revision']}), 409, 'הרשומה השתנתה. רעננו לפני שמירה.')
    r = st('PUT back to discount 0', call('manage/orders/' + o['id'], 'PUT', {**PAY, 'status': 'draft'}), 200); o = r.j or {}
    if IS_SHEM:
        st('trackingUrl must be https', call('manage/orders/' + o['id'], 'PUT', {**PAY, 'trackingUrl': 'http://x.test/t'}), 400, 'קישור המעקב חייב להיות כתובת HTTPS תקינה.')
        st('trackingUrl credentials rejected', call('manage/orders/' + o['id'], 'PUT', {**PAY, 'trackingUrl': 'https://u:p@x.test/t'}), 400, 'קישור המעקב חייב להיות כתובת HTTPS תקינה.')
        st('estimatedDelivery invalid', call('manage/orders/' + o['id'], 'PUT', {**PAY, 'estimatedDelivery': '2026-99-99'}), 400, 'תאריך אספקה אינו תקין.')
        r = st('carrier/tracking/ETA saved', call('manage/orders/' + o['id'], 'PUT', {**PAY, 'carrier': 'UPS', 'trackingUrl': 'https://x.test/t', 'estimatedDelivery': '2026-12-01', 'status': 'new'}), 200); o = r.j or {}
        eq('shipment fields', (o.get('carrier'), o.get('trackingUrl'), o.get('estimatedDelivery'), [h['status'] for h in o.get('shipmentHistory', [])]), ('UPS', 'https://x.test/t', '2026-12-01', ['draft', 'new', 'draft', 'new']))
    else:
        r = st('SHIMI order has no shipment fields', call('manage/orders/' + o['id'], 'PUT', {**PAY, 'carrier': 'UPS'}), 200); o = r.j or {}
        ok('no carrier/shipmentHistory on SHIMI', 'carrier' not in o and 'shipmentHistory' not in o)
    # api.js status endpoint
    st('api orders PUT invalid status', call('orders/' + o['id'], 'PUT', {'status': 'bogus'}), 400, 'Invalid order status')
    st('api orders POST not allowed', call('orders', 'POST', {'status': 'new'}), 409, 'יצירת הזמנות תתאפשר לאחר חיבור מערכת ההזמנות.')
    st('api orders PUT unknown', call('orders/nope', 'PUT', {'status': 'new'}), 404)
    r = st('api orders PUT status/tracking/notes', call('orders/' + o['id'], 'PUT', {'status': 'processing', 'tracking': 'TRK1', 'notes': 'n'}), 200); o = {**o, **(r.j or {})}
    eq('api orders typed', ((r.j or {}).get('status'), (r.j or {}).get('tracking')), ('processing', 'TRK1'))
    o = call('manage/orders/' + o['id']).j or o
    # payment
    st('mark paid without reference -> 400', call('manage/orders/' + o['id'] + '/paid', 'POST', {'reference': ''}), 400, 'נדרשת אסמכתת תשלום.')
    # loyalty ledger prerequisites: manual adjust before paying
    st('loyalty-adjust negative balance -> 400', call('manage/loyalty-adjust', 'POST', {'memberId': card['id'], 'amount': -5, 'reason': 'invalid'}), 400, 'היתרה לא יכולה להיות שלילית.')
    st('loyalty-adjust invalid input -> 400', call('manage/loyalty-adjust', 'POST', {'memberId': card['id'], 'amount': 0, 'reason': 'x'}), 400, 'נדרש חבר, סכום וסיבה תקינים.')
    st('loyalty-adjust unknown member', call('manage/loyalty-adjust', 'POST', {'memberId': 'nope', 'amount': 5, 'reason': 'x'}), 400)
    r = st('loyalty-adjust +5', call('manage/loyalty-adjust', 'POST', {'memberId': card['id'], 'amount': 5, 'reason': 'manual QA'}), 201)
    ok('adjust entry', (r.j or {}).get('deltaCents') == 500 and (r.j or {}).get('type') == 'manual' and (r.j or {}).get('system') is False and (r.j or {}).get('revision'), r.t[:200])
    r = st('mark paid (manual-external)', call('manage/orders/' + o['id'] + '/paid', 'POST', {'reference': 'EXTERNAL-TEST-1', 'paymentDetails': {'method': 'card', 'last4': '1234', 'cardholderName': 'QA Holder', 'brand': 'Visa', 'installments': 1, 'authorizationCode': 'TEST-APPROVAL', 'pan': 'ignored', 'cvv': 'ignored'}}), 200); pd = r.j or {}
    eq('paid order', (pd.get('paymentStatus'), pd.get('paid'), pd.get('status'), pd.get('paymentSource'), pd.get('paymentReference'), pd.get('invoiceDocumentId')), ('paid', True, 'processing', 'manual-external', 'EXTERNAL-TEST-1', o['id']))
    eq('paymentDetails sanitised', (pd['paymentDetails'].get('last4'), pd['paymentDetails'].get('cardholderName'), 'pan' in pd['paymentDetails'], 'cvv' in pd['paymentDetails'], pd['paymentDetails'].get('installments')), ('1234', 'QA Holder', False, False, 1))
    eq('vatItemsCents of paid order', pd.get('vatItemsCents'), round(59970 * 18 / 118))
    ps = call('products/' + v['id']).j or {}
    eq('stock decremented 8 -> 5 (variant black-s 5->3, ivory-m 3->2)', (ps.get('stock'), [x['stock'] for x in ps['variants']], ps.get('sizeStock')), (5, [3, 2], {'S': 3, 'M': 2}))
    st('mark paid again (same reference) is idempotent', call('manage/orders/' + o['id'] + '/paid', 'POST', {'reference': 'EXTERNAL-TEST-1'}), 200)
    eq('stock unchanged after repeat', (call('products/' + v['id']).j or {}).get('stock'), 5)
    mv = [m for m in (call('manage/stock').j or []) if m.get('productId') == v['id'] and m.get('reason', '').startswith('תשלום הזמנה')]
    eq('payment movements', sorted(m['delta'] for m in mv), [-2, -1])
    docs = [d for d in (call('manage/documents').j or []) if d['id'] == o['id']]
    eq('invoice document created', len(docs), 1)
    if docs:
        d = docs[0]
        ok('document fields', d.get('totalCents') == 64870 and d['customer']['name'] == cust['name'] and re.fullmatch(r'T_ADMIN-\d{6}', d.get('number', '')) and d.get('paymentReference') == 'EXTERNAL-TEST-1' and d.get('vatCents') == round(64870 * 18 / 118) and d.get('orderId') == o['id'], d)
    ml = call('manage/mail').j or []
    ok('customer mail job queued (pending)', any(j['id'] == o['id'] and j['status'] == 'pending' and j['documentId'] == o['id'] for j in ml), [j['id'] for j in ml][:5])
    ok('manager mail job queued', any(j.get('kind') == 'manager-order' and j.get('orderId') == o['id'] and j['recipient'] == 'your.shem88@gmail.com' for j in ml))
    st('payment-details invalid last4', call('manage/orders/' + o['id'] + '/payment-details', 'POST', {'paymentDetails': {'method': 'card', 'last4': '1234567812345678'}}), 400, 'יש להזין ארבע ספרות אחרונות בלבד.')
    st('payment-details installments invalid', call('manage/orders/' + o['id'] + '/payment-details', 'POST', {'paymentDetails': {'installments': 99}}), 400, 'מספר תשלומים לא תקין.')
    st('payment-details update', call('manage/orders/' + o['id'] + '/payment-details', 'POST', {'paymentDetails': {'method': 'card', 'last4': '4321', 'cardholderName': 'Updated holder'}}), 200)
    eq('payment-details persisted', (call('manage/orders/' + o['id']).j or {}).get('paymentDetails', {}).get('last4'), '4321')
    st('archive paid order -> 409', call('manage/orders/' + o['id'] + '/archive', 'POST', {}), 409, 'הזמנה ששולמה נשמרת לתיעוד. אפשר לעדכן מצב טיפול.')
    st('archive document -> 409', call('manage/documents/' + o['id'] + '/archive', 'POST', {}), 409, 'תיעוד זה נשמר ללא מחיקה.')
    st('documents POST -> 405', call('manage/documents', 'POST', {}), 405, 'יש להשתמש בפעולה הייעודית.')
    cur = call('manage/orders/' + o['id']).j
    r = st('PUT paid order only changes status/tracking/notes', call('manage/orders/' + o['id'], 'PUT', {**cur, 'customer': {**cust, 'name': 'tampered'}, 'total': 1, 'status': 'shipped', 'tracking': 'T9', 'notes': 'kept'}), 200)
    after = call('manage/orders/' + o['id']).j or {}
    eq('paid order immutable core', (after['customer']['name'], after['totalCents'], after['status'], after['tracking']), (cust['name'], 64870, 'shipped', 'T9'))
    eq('document untouched', [d['totalCents'] for d in (call('manage/documents').j or []) if d['id'] == o['id']], [64870])
    led = call('manage/loyalty').j or []
    earned = [l for l in led if l.get('type') == 'earned' and l.get('orderId') == o['id']]
    eq('loyalty earned entry (3% of 59970)', [l['deltaCents'] for l in earned], [1799])
    ok('earned entry pending 14 days, expires', earned and earned[0]['availableAt'] > earned[0]['createdAt'] and earned[0]['expiresAt'] > earned[0]['availableAt'], earned)
    eq('card effectiveTier vip', [c['effectiveTier'] for c in (call('manage/cards').j or []) if c['id'] == card['id']], ['vip'])
    st('cancelled order cannot be paid', call('manage/orders/' + (call('manage/orders', 'POST', {**PAY, 'items': [PAY['items'][0]], 'status': 'cancelled'}).j or {}).get('id', 'x') + '/paid', 'POST', {'reference': 'X'}), 409, 'הזמנה מבוטלת אינה ניתנת לתשלום.')
    for oo in call('manage/orders').j or []:
        if oo.get('status') == 'cancelled' and oo.get('customer', {}).get('email') == cust['email']: track('orders', oo['id'])
    # redeem / release
    r = st('create second order', call('manage/orders', 'POST', {**PAY, 'items': [PAY['items'][0] | {'qty': 1}]}), 201); small = r.j or {}; track('orders', small.get('id'))
    st('redeem above available balance -> 400', call('manage/orders/' + small['id'] + '/redeem', 'POST', {'memberId': card['id'], 'amount': 6}), 400, 'אין יתרת הטבה זמינה בסכום המבוקש.')
    st('redeem unknown member', call('manage/orders/' + small['id'] + '/redeem', 'POST', {'memberId': 'nope', 'amount': 5}), 400, 'חבר, הזמנה או סכום הטבה אינם תקינים.')
    r = st('redeem 5', call('manage/orders/' + small['id'] + '/redeem', 'POST', {'memberId': card['id'], 'amount': 5}), 200); rd = r.j or {}
    eq('redeemed totals', (rd.get('totalCents'), rd.get('discountCents'), rd.get('loyaltyDiscountCents'), bool(rd.get('redemptionId'))), (small['totalCents'] - 500, 500, 500, True))
    eq('one redeemed ledger entry', len([l for l in (call('manage/loyalty').j or []) if l.get('type') == 'redeemed' and l.get('orderId') == small['id']]), 1)
    st('redeem twice -> 409', call('manage/orders/' + small['id'] + '/redeem', 'POST', {'memberId': card['id'], 'amount': 1}), 409, 'ההזמנה אינה זמינה למימוש הטבה.')
    st('edit order with open redemption -> 409', call('manage/orders/' + small['id'], 'PUT', {**PAY, 'items': [PAY['items'][0] | {'qty': 1}]}), 409, 'יש לבטל את מימוש ההטבה לפני עריכת הזמנה.')
    st('archive with open redemption -> 409', call('manage/orders/' + small['id'] + '/archive', 'POST', {}), 409, 'יש לבטל את מימוש ההטבה לפני ארכוב.')
    r = st('release-credit', call('manage/orders/' + small['id'] + '/release-credit', 'POST', {}), 200)
    eq('release restores total', ((r.j or {}).get('totalCents'), (r.j or {}).get('discountCents'), (r.j or {}).get('loyaltyDiscountCents'), (r.j or {}).get('redemptionId')), (small['totalCents'], 0, 0, None))
    st('release twice -> 409', call('manage/orders/' + small['id'] + '/release-credit', 'POST', {}), 409, 'אין מימוש פתוח לביטול.')
    eq('release ledger entry present', len([l for l in (call('manage/loyalty').j or []) if l.get('type') == 'released' and l.get('orderId') == small['id']]), 1)
    st('issue-document on unpaid order -> 409', call('manage/orders/' + small['id'] + '/issue-document', 'POST', {}), 409, 'מסמך מופק רק להזמנה ששולמה.')
    st('payment-link without connection -> blocked', call('manage/orders/' + small['id'] + '/payment-link', 'POST', {}), 501)
    blocked('manage/orders/:id/payment-link', 'needs the payments module filter scc_admin_payment_link (returns 501 until it exists)')
    # issue-document on a paid order without a document (business details were incomplete)
    sv = call('manage/settings/invoice').j; call('manage/settings/invoice', 'PUT', {**inv_s, 'legalName': ''})
    r = st('second order paid while invoice details incomplete', call('manage/orders/' + small['id'] + '/paid', 'POST', {'reference': 'EXTERNAL-TEST-2'}), 200)
    eq('invoiceStatus missing-business-details', (r.j or {}).get('invoiceStatus'), 'missing-business-details')
    eq('no document for it yet', [d['id'] for d in (call('manage/documents').j or []) if d['id'] == small['id']], [])
    st('issue-document with incomplete business -> 409', call('manage/orders/' + small['id'] + '/issue-document', 'POST', {}), 409, 'יש להשלים תחילה את פרטי העסק בתבנית חשבונית העסקה.')
    call('manage/settings/invoice', 'PUT', inv_s)
    r = st('issue-document now succeeds -> 201', call('manage/orders/' + small['id'] + '/issue-document', 'POST', {}), 201)
    ok('issue-document body {document,mail}', set((r.j or {}).keys()) == {'document', 'mail'} and re.fullmatch(r'T_ADMIN-\d{6}', (r.j or {}).get('document', {}).get('number', '')), r.t[:300])
    eq('document count after issue', len([d for d in (call('manage/documents').j or []) if d['id'] in (o['id'], small['id'])]), 2)
    r2 = st('issue-document again returns the same document (200)', call('manage/orders/' + small['id'] + '/issue-document', 'POST', {}), 200)
    eq('same number', (r2.j or {}).get('document', {}).get('number'), (r.j or {}).get('document', {}).get('number'))
    eq('invoiceStatus queued', (call('manage/orders/' + small['id']).j or {}).get('invoiceStatus'), 'queued')
    eq('stock after both payments', (call('products/' + v['id']).j or {}).get('stock'), 4)
    # documents render (documents module)
    for path, what in [('manage/documents/%s/html', 'html'), ('manage/documents/%s/pdf', 'pdf'), ('manage/orders/%s/order-pdf', 'order-pdf'), ('manage/template-preview', 'template-preview'), ('manage/template-preview.pdf', 'template-preview.pdf')]:
        r = call(path % o['id']) if '%s' in path else call(path)
        if r.status == 501: blocked('GET ' + path.replace('%s', ':id'), 'document renderer missing: filter scc_admin_document_render')
        else: st('GET ' + path, r, 200)
    st('documents html for unknown id -> 404', call('manage/documents/nope/html'), 404, 'מסמך לא נמצא.')
    st('documents pdf anonymous -> 401', call('manage/documents/' + o['id'] + '/pdf', anon=True), 401)
    ml = call('manage/mail/process', 'POST', {}); st('mail/process (mail not configured)', ml, 200); eq('blocked message', ml.j, {'sent': 0, 'blocked': 'נדרשים שירות מייל ודומיין שולח מאומת.'})
    # mail retry
    jid = next((j for j in (call('manage/mail').j or []) if j['id'] == o['id']), None)
    if jid:
        r = st('mail retry -> pending (processMail blocked)', call('manage/mail/' + jid['id'] + '/retry', 'POST', {}), 200)
        sql(f"UPDATE {TBL('mail_jobs')} SET data=JSON_SET(data,'$.status','sent') WHERE id={q(jid['id'])}")
        st('retry of a sent mail -> 409', call('manage/mail/' + jid['id'] + '/retry', 'POST', {}), 409, 'המייל כבר התקבל אצל שירות המייל.')
        sql(f"UPDATE {TBL('mail_jobs')} SET data=JSON_SET(data,'$.status','failed','$.firstAttemptAt','2020-01-01T00:00:00.000Z') WHERE id={q(jid['id'])}")
        st('retry after the 23h idempotency window -> 409', call('manage/mail/' + jid['id'] + '/retry', 'POST', {}), 409, 'חלון מניעת כפילות פג. יש לאמת מול שירות המייל לפני שליחה נוספת.')
    blocked('mail sending (Resend + PDF attachments)', 'mail module missing: filter scc_admin_mail_process; processMail replay with mocked Resend not run')
    st('manage unknown kind -> 404', call('manage/bogus'), 404)
    st('manage/orders unknown id GET returns null (original)', call('manage/orders/nope'), 200); eq('null body', call('manage/orders/nope').j, None)
    st('manage action on unknown record -> 404', call('manage/orders/nope/archive', 'POST', {}), 404, 'רשומה לא נמצאה.')
    st('archive/restore of an unpaid draft order', call('manage/orders/' + small['id'] + '/archive', 'POST', {}), 409)
    r = st('archive an unpaid order', call('manage/orders', 'POST', {**PAY, 'items': [PAY['items'][0] | {'qty': 1}]}), 201); ua = r.j or {}; track('orders', ua.get('id'))
    r = st('archive unpaid order', call('manage/orders/' + ua['id'] + '/archive', 'POST', {}), 200); eq('archived', (r.j or {}).get('archived'), True)
    eq('unpaid count excludes archived', True, True)
    st('restore unpaid order', call('manage/orders/' + ua['id'] + '/restore', 'POST', {}), 200)

    # ---------------------------------------------------------------- requests + activities
    screen('service + returns / activities')
    req = dict(cust, orderNumber='MANUAL', type='return', status='new', message='Return please')
    r = st('create request (manage)', call('manage/requests', 'POST', req), 201); rq = r.j or {}; track('requests', rq.get('id'))
    eq('request source manual', rq.get('source'), 'manual')
    st('request invalid type/status', call('manage/requests', 'POST', {**req, 'type': 'x'}), 400, 'סוג או מצב פנייה אינו תקין.')
    st('request update', call('manage/requests/' + rq['id'], 'PUT', {**rq, 'status': 'reviewing', 'notes': 'QA review'}), 200)
    sql(f"INSERT INTO {TBL('requests')} (id,data,updated_at) VALUES ({q(P + 'r1')},{q(json.dumps({'name': 'Test', 'email': P + 'r1@example.test', 'orderNumber': 'LOCAL-TEST', 'type': 'cancel', 'status': 'new', 'source': 'form'}))},'2026-01-01T00:00:00.000Z')"); track('requests', P + 'r1')
    rl = [x for x in (call('requests').j or []) if x['id'] == P + 'r1']
    eq('requests list (operations)', [x['status'] for x in rl], ['new'])
    r = st('request PUT (operations)', call('requests/' + P + 'r1', 'PUT', {**rl[0], 'status': 'reviewing', 'notes': 'Under review'}), 200)
    eq('request typed', ((r.j or {}).get('status'), (r.j or {}).get('notes'), (r.j or {}).get('orderNumber')), ('reviewing', 'Under review', 'LOCAL-TEST'))
    st('request invalid status (operations)', call('requests/' + P + 'r1', 'PUT', {'status': 'zzz'}), 400, 'מצב פנייה לא תקין.')
    st('request POST (operations) -> 409', call('requests', 'POST', {'status': 'new'}), 409, 'פניות מתקבלות מטופס השירות.')
    st('requests anonymous -> 401', call('requests', anon=True), 401)
    r = st('create activity', call('manage/activities', 'POST', {'title': P + 'Call customer', 'reason': 'Service', 'status': 'open'}), 201); ac = r.j or {}; track('activities', ac.get('id'))
    ok('activity system false, history empty', ac.get('system') is False and ac.get('history') == [], ac)
    st('activity requires title+reason', call('manage/activities', 'POST', {'title': 'x'}), 400, 'נדרשים כותרת וסיבה לפעילות ידנית.')
    r = st('activity update keeps history', call('manage/activities/' + ac['id'], 'PUT', {**ac, 'status': 'done', 'notes': 'done'}), 200)
    ok('history entry with actor', len((r.j or {}).get('history', [])) == 1 and (r.j or {})['history'][0]['actor'].startswith('wp:'), r.t[:300])
    st('archive activity', call('manage/activities/' + ac['id'] + '/archive', 'POST', {}), 200)

    # ---------------------------------------------------------------- birthday benefit (club cards)
    screen('club cards + members')
    from datetime import datetime
    from zoneinfo import ZoneInfo
    today = datetime.now(ZoneInfo('Asia/Jerusalem')).strftime('%Y-%m-%d')
    r = st('birthday: not today -> 400', call('manage/cards/' + card['id'] + '/birthday', 'POST', {}), 400, 'הטבת יום הולדת דורשת חבר פעיל, יום הולדת היום וסכום מוגדר.')
    call('manage/settings/loyalty', 'PUT', {**loy, 'birthdayBenefit': 20})
    cc = call('manage/cards').j; cc = next(c for c in cc if c['id'] == card['id'])
    st('card set birthday to today', call('manage/cards/' + card['id'], 'PUT', {**cc, 'birthday': '1990' + today[4:]}), 200)
    r = st('birthday benefit granted -> 201', call('manage/cards/' + card['id'] + '/birthday', 'POST', {}), 201)
    ok('birthday entry', (r.j or {}).get('deltaCents') == 2000 and (r.j or {}).get('type') == 'birthday' and (r.j or {}).get('id') == f"birthday:{card['id']}:{today[:4]}", r.t[:300])
    st('birthday benefit twice -> 409', call('manage/cards/' + card['id'] + '/birthday', 'POST', {}), 409, 'הטבת יום הולדת כבר ניתנה השנה.')

    # ---------------------------------------------------------------- audit + control
    screen('audit log + control')
    a = st('audit list', call('audit'), 200)
    ok('audit rows (latest 200) with shape', len(a.j) > 10 and set(a.j[0].keys()) == {'id', 'actor', 'action', 'created_at'} and a.j[0]['actor'].startswith('wp:'), (a.j or [None])[0])
    ok('audit has write actions of this run', any(x['action'].startswith('POST /api/admin/manage/orders') for x in a.j))
    ok('reads are not audited', not any(x['action'].startswith('GET ') for x in a.j))
    rows = [x for x in a.j if x['action'].startswith('POST')]
    if rows:
        out, err = sql(f"UPDATE {TBL('audit')} SET data='{{}}' WHERE id={q(rows[0]['id'])}")
        ok('audit UPDATE rejected by the database', 'append-only' in err, err[:160])
        out, err = sql(f"DELETE FROM {TBL('audit')} WHERE id={q(rows[0]['id'])}")
        ok('audit DELETE rejected by the database', 'append-only' in err, err[:160])
    h = st('health', call('health'), 200)
    ok('health shape', set(h.j.keys()) == {'database', 'media', 'payments', 'warehouse', 'businessComplete', 'supportEmail', 'checkedAt'} and h.j['database'] is True and h.j['media'] is True, h.j)
    s2 = st('manage/status', call('manage/status'), 200)
    ok('status shape', set(s2.j.keys()) == {'database', 'media', 'invoiceComplete', 'mailConfigured', 'homeConfigured', 'homeEnabled', 'unmapped', 'lowStock', 'outOfStock', 'archivedProducts', 'openReturns', 'unpaid', 'failedMail', 'checkedAt'}, list(s2.j.keys()))
    eq('status.invoiceComplete', s2.j['invoiceComplete'], True)
    ok('status counts are numbers', all(isinstance(s2.j[k], int) for k in ['unmapped', 'outOfStock', 'archivedProducts', 'openReturns', 'unpaid', 'failedMail']))
    st('health anonymous -> 401', call('health', anon=True), 401)

    # unmatched
    screen('auth+session')
    st('unknown admin route -> 404', call('nope'), 404)
    ok('responses carry nosniff', S.get(BASE + '/api/admin/health').headers.get('x-content-type-options') == 'nosniff')

def main():
    try: run()
    except Exception as e:
        import traceback; traceback.print_exc(); rec('FAIL', 'suite crashed', repr(e))
    finally:
        try: cleanup()
        except Exception as e: print('cleanup problem', e)
    by = collections.OrderedDict()
    for sc, label, status, det in RES: by.setdefault(sc, collections.Counter())[status] += 1
    tot = collections.Counter(s for _, _, s, _ in RES)
    print(f'\n== {SITE}: ' + ', '.join(f'{k}={v}' for k, v in sorted(tot.items())) + f' (total {len(RES)})')
    for sc, c in by.items(): print(f'  {sc:55s}', dict(c))
    json.dump([{'screen': a, 'check': b, 'status': c, 'detail': d} for a, b, c, d in RES], open(f'/home/claude/shem-shop/WP-TESTS/admin/last-run-{SITE}.json', 'w'), ensure_ascii=False, indent=1)
    sys.exit(1 if tot['FAIL'] else 0)
main()
