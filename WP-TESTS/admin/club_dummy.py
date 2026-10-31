#!/usr/bin/env python3
"""
Club end-to-end check with a DUMMY customer (staging only). Usage: club_dummy.py shem|shimi
No real member is involved. All rows use the prefix t_club_ and are removed at the end; the settings rows
(club, loyalty, business, ...) are snapshotted first and restored. Audit rows are append-only by design and stay.
Reuses the helpers of admin_suite.py (login, call, st, eq, ok, sql, snapshot, restore, cleanup).
"""
import sys, os, json, re, subprocess, collections
src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'admin_suite.py')).read().rsplit('\nmain()', 1)[0]
src = src.replace('last-run-{SITE}.json', 'last-run-club-{SITE}.json')
exec(compile(src, 'admin_suite', 'exec'), globals())
P = 't_club_'
EMAIL = P + 'dummy@example.test'
SHOTS = os.environ.get('SHOTS', '/tmp/club-shots')
os.makedirs(SHOTS, exist_ok=True)

def pub(path, method='GET', data=None, origin=BASE, ctype='application/json'):
    h = {}
    if origin is not None: h['Origin'] = origin
    if ctype: h['Content-Type'] = ctype
    return Resp(requests.request(method, BASE + path, data=json.dumps(data) if data is not None else None, headers=h, allow_redirects=False))

def ledger(mid):
    out, _ = sql(f"SELECT id, data FROM {TBL('loyalty_ledger')} WHERE JSON_VALUE(data,'$.memberId')={q(mid)} ORDER BY updated_at")
    rows = []
    for ln in out.splitlines():
        i, d = ln.split('\t', 1); x = json.loads(d); x['_id'] = i; rows.append(x)
    return rows
def balance(mid):
    from datetime import datetime, timezone
    now = datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.%f')[:-3] + 'Z'
    return sum(l['deltaCents'] for l in ledger(mid) if l.get('availableAt', '') <= now and (not l.get('expiresAt') or l['expiresAt'] > now))
def count(table): return int(sql(f"SELECT COUNT(*) FROM {TBL(table)}")[0] or 0)

def shots():
    from playwright.sync_api import sync_playwright
    screens = [('כרטיסי מועדון', 'cards'), ('מועדון חברים', 'members'), ('לקוחות מהזמנות', 'customers'), ('הזמנות', 'orders'), ('יומן פעולות', 'audit')]
    with sync_playwright() as p:
        b = p.chromium.launch(); pg = b.new_page(viewport={'width': 1360, 'height': 900})
        errs = []
        pg.on('pageerror', lambda e: errs.append(str(e)[:120]))
        pg.goto(BASE + '/wp-login.php'); pg.fill('#user_login', 'scc_test_admin'); pg.fill('#user_pass', PW); pg.click('#wp-submit'); pg.wait_for_load_state()
        pg.goto(BASE + '/admin.html'); pg.wait_for_timeout(3000)
        for name, tag in screens:
            loc = pg.get_by_role('button', name=name, exact=True)
            if loc.count() == 0: loc = pg.locator(f'text="{name}"')
            try:
                loc.first.click(timeout=4000); pg.wait_for_timeout(1800)
                body = pg.inner_text('body')
                ok(f'admin UI screen "{name}" renders', len(body) > 200)
                ok(f'admin UI "{name}" shows the dummy customer' if tag in ('cards', 'members', 'customers', 'orders') else f'admin UI "{name}" shows club actions' , (P in body or EMAIL in body) if tag in ('cards', 'members', 'customers', 'orders') else ('POST' in body or 'loyalty' in body or 'מועדון' in body or 'club' in body.lower()), body[:200])
                pg.screenshot(path=f'{SHOTS}/{SITE}_{tag}.png')
            except Exception as e:
                rec('FAIL', f'admin UI screen "{name}"', str(e)[:150])
        ok('admin UI: no page errors', not errs, errs)
        b.close()

def run():
    login(); snapshot()
    ev = {}
    # ------------------------------------------------------------ 0. baseline and gating
    screen('0 baseline')
    mail0, aud0 = count('mail_jobs'), count('audit')
    cs = call('settings').j or {}
    ev['club_initial'] = cs.get('club')
    r = pub('/api/club'); ev['club_get_initial'] = r.j
    st('club signup while the club is not active -> 409', pub('/api/club', 'POST', {'email': EMAIL, 'consent': True}), 409, 'ההרשמה למועדון עדיין אינה פעילה.')
    st('admin: enable club without privacy link -> 400', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': True, 'title': 'Club', 'privacyUrl': ''}}), 400, 'כדי להפעיל הרשמה יש להזין קישור למדיניות פרטיות.')
    st('admin: enable club with http link -> 400', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': True, 'title': 'Club', 'privacyUrl': 'http://x.test/p'}}), 400)
    st('admin: enable club (https privacy link)', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': True, 'title': BRAND + ' club', 'privacyUrl': 'https://example.com/privacy'}}), 200)
    r = pub('/api/club'); ok('public GET /api/club reflects enabled', (r.j or {}).get('enabled') is True, r.t[:200])
    # business details are needed for invoices on mark-paid (same as the admin suite)
    call('settings', 'PUT', {'key': 'business', 'value': {'legalName': 'Test company', 'registrationNumber': 'TEST', 'address': 'Test address', 'phone': '0500000000'}})

    # ------------------------------------------------------------ 1. public signup (dummy customer)
    screen('1 signup')
    st('signup invalid e-mail -> 400', pub('/api/club', 'POST', {'email': 'nope', 'consent': True}), 400)
    st('signup without consent -> 400', pub('/api/club', 'POST', {'email': EMAIL, 'consent': False}), 400)
    st('signup consent as string -> 400 (strict true)', pub('/api/club', 'POST', {'email': EMAIL, 'consent': 'true'}), 400)
    st('signup foreign origin -> 403', pub('/api/club', 'POST', {'email': EMAIL, 'consent': True}, origin='https://evil.test'), 403)
    st('signup without origin -> 403', pub('/api/club', 'POST', {'email': EMAIL, 'consent': True}, origin=None), 403)
    st('signup non-JSON content type -> 415', pub('/api/club', 'POST', None, ctype='text/plain'), 415)
    ok('nothing created by the rejected attempts', int(sql(f"SELECT COUNT(*) FROM {TBL('members')} WHERE JSON_VALUE(data,'$.email')={q(EMAIL)}")[0] or 0) == 0)
    r = st('signup dummy customer -> 200', pub('/api/club', 'POST', {'email': EMAIL, 'consent': True}), 200); ev['signup'] = r.j
    out, _ = sql(f"SELECT id, data FROM {TBL('members')} WHERE JSON_VALUE(data,'$.email')={q(EMAIL)}")
    rows = [ln.split('\t', 1) for ln in out.splitlines() if ln]
    eq('exactly one member row', len(rows), 1)
    mid = rows[0][0]; mdoc = json.loads(rows[0][1]); track('members', mid)
    eq('member status/consent', (mdoc.get('status'), bool(mdoc.get('consent_at'))), ('active', True))
    r = st('signup again (same e-mail) is idempotent -> 200', pub('/api/club', 'POST', {'email': EMAIL, 'consent': True}), 200)
    eq('still one member row', int(sql(f"SELECT COUNT(*) FROM {TBL('members')} WHERE JSON_VALUE(data,'$.email')={q(EMAIL)}")[0] or 0), 1)
    st('signup upper-case e-mail does not create a duplicate', pub('/api/club', 'POST', {'email': EMAIL.upper(), 'consent': True}), 200)
    eq('still one member row after case variant', int(sql(f"SELECT COUNT(*) FROM {TBL('members')} WHERE LOWER(JSON_VALUE(data,'$.email'))={q(EMAIL)}")[0] or 0), 1)
    out, _ = sql(f"SELECT id, data FROM {TBL('member_profiles')} WHERE JSON_VALUE(data,'$.email')={q(EMAIL)}")
    prow = [ln.split('\t', 1) for ln in out.splitlines() if ln]
    ok('card (member profile) created by signup', len(prow) == 1, out[:200])
    pid = prow[0][0]; pdoc = json.loads(prow[0][1]); track('member_profiles', pid)
    ok('card number format BRAND-<12 hex>', re.fullmatch(re.escape(BRAND) + r'-[0-9A-F]{12}', pdoc.get('cardNumber', '')) is not None, pdoc.get('cardNumber'))
    eq('card defaults', (pdoc.get('tier'), pdoc.get('status'), pdoc.get('marketingConsent')), ('standard', 'active', False))
    ok('signup sent no e-mail (no mail job added)', count('mail_jobs') == mail0, (mail0, count('mail_jobs')))
    ev['card'] = pdoc

    # ------------------------------------------------------------ 2. admin visibility
    screen('2 admin visibility')
    ml = [m for m in (call('members').j or []) if m['id'] == mid]
    eq('admin members list shows dummy', [(m['email'], m['status']) for m in ml], [(EMAIL, 'active')])
    cl = [c for c in (call('manage/cards').j or []) if c['id'] == pid]
    ok('admin cards list shows dummy with effectiveTier', len(cl) == 1 and 'effectiveTier' in cl[0], cl)
    r = st('admin edit card (name, phone, vip)', call('member-profiles/' + mid, 'PUT', dict(name=P + 'Dummy', tier='vip', phone='0500000001')), 200)
    ok('member-profiles PUT keeps the card number or creates one for a member id', str((r.j or {}).get('cardNumber', '')).startswith(BRAND + '-'), r.t[:200])
    # NOTE: member-profiles/<member id> may create/update a profile keyed by the member id; track both
    track('member_profiles', mid)
    prof = [c for c in (call('manage/cards').j or []) if c.get('email') == EMAIL]
    ev['cards_after_edit'] = [(c['id'], c.get('tier'), c.get('status')) for c in prof]
    ok('no duplicate card for the dummy e-mail', len(prof) == 1, ev['cards_after_edit'])
    for c in prof: track('member_profiles', c['id'])
    card = prof[0]; cid = card['id']
    st('admin cards: anonymous -> 401', call('manage/cards', anon=True), 401)
    st('admin members: anonymous -> 401', call('members', anon=True), 401)
    st('admin member-profiles PUT: anonymous -> 401', call('member-profiles/' + mid, 'PUT', {}, anon=True), 401)
    st('admin loyalty-adjust: anonymous -> 401', call('manage/loyalty-adjust', 'POST', {}, anon=True), 401)
    st('admin loyalty: no nonce -> 401', call('manage/loyalty-adjust', 'POST', {'memberId': cid, 'amount': 1, 'reason': 'x'}, nonce=False), 401)
    st('admin loyalty: foreign origin -> 403', call('manage/loyalty-adjust', 'POST', {'memberId': cid, 'amount': 1, 'reason': 'x'}, origin='https://evil.test'), 403)

    # ------------------------------------------------------------ 3. loyalty rules and manual adjustments
    screen('3 loyalty')
    loy = {'enabled': True, 'earnPercent': 10, 'maxRedeemPercent': 50, 'vipSpend': 300, 'vipOrders': 2, 'pendingDays': 0, 'expiryDays': 365, 'grossMarginPercent': 20, 'birthdayBenefit': 20}
    st('loyalty settings PUT (earn 10 percent, no waiting period)', call('manage/settings/loyalty', 'PUT', loy), 200)
    st('adjust without reason -> 400', call('manage/loyalty-adjust', 'POST', {'memberId': cid, 'amount': 5, 'reason': ''}), 400, 'נדרש חבר, סכום וסיבה תקינים.')
    st('adjust zero -> 400', call('manage/loyalty-adjust', 'POST', {'memberId': cid, 'amount': 0, 'reason': 'x'}), 400)
    st('adjust unknown member -> 400', call('manage/loyalty-adjust', 'POST', {'memberId': 'nope', 'amount': 5, 'reason': 'x'}), 400)
    st('adjust negative below zero balance -> 400', call('manage/loyalty-adjust', 'POST', {'memberId': cid, 'amount': -5, 'reason': 'x'}), 400, 'היתרה לא יכולה להיות שלילית.')
    r = st('adjust +20 (welcome credit) -> 201', call('manage/loyalty-adjust', 'POST', {'memberId': cid, 'amount': 20, 'reason': 'Welcome credit (test)'}), 201)
    eq('adjust stored in cents, manual, not system', ((r.j or {}).get('deltaCents'), (r.j or {}).get('type'), (r.j or {}).get('system')), (2000, 'manual', False))
    eq('balance = 20.00', balance(cid), 2000)
    st('adjust -5 -> 201', call('manage/loyalty-adjust', 'POST', {'memberId': cid, 'amount': -5, 'reason': 'Correction (test)'}), 201)
    eq('balance = 15.00', balance(cid), 1500)
    ledg = call('manage/loyalty').j or []
    ok('admin loyalty ledger lists the dummy entries', len([l for l in ledg if l.get('memberId') == cid]) == 2, len(ledg))
    # birthday
    from datetime import datetime
    from zoneinfo import ZoneInfo
    today = datetime.now(ZoneInfo('Asia/Jerusalem')).strftime('%Y-%m-%d')
    st('birthday not today -> 400', call('manage/cards/' + cid + '/birthday', 'POST', {}), 400)
    cc = next(c for c in call('manage/cards').j if c['id'] == cid)
    st('set birthday to today', call('manage/cards/' + cid, 'PUT', {**cc, 'birthday': '1990' + today[4:]}), 200)
    r = st('birthday benefit -> 201', call('manage/cards/' + cid + '/birthday', 'POST', {}), 201)
    eq('birthday +20.00', (r.j or {}).get('deltaCents'), 2000)
    st('birthday benefit twice -> 409', call('manage/cards/' + cid + '/birthday', 'POST', {}), 409)
    eq('balance = 35.00', balance(cid), 3500)

    # ------------------------------------------------------------ 4. orders: earn, redeem, release
    screen('4 orders and benefits')
    vb = variant_body(); vb['he'] = P + 'מוצר'; vb['sku'] = 'T_CLUB_V'; vb['homeSku'] = 'T_CLUB_V'; vb['previousPrice'] = 0
    for i, v_ in enumerate(vb['variants']): v_['id'] = P + ['black-s', 'ivory-m'][i]; v_['sku'] = v_['homeSku'] = f'T_CLUB_V-{i}'
    r = st('create dummy product', call('products', 'POST', vb), 201); prod = r.j or {}; track('products', prod.get('id'))
    cust = dict(name=P + 'Dummy', email=EMAIL, phone='0500000001', city='Test city', street='Test 1')
    items = [{'productId': prod['id'], 'variantId': P + 'black-s', 'size': 'S', 'qty': 2}]
    r = st('order #1 for the dummy customer', call('manage/orders', 'POST', {'customer': cust, 'items': items, 'shippingCost': 0, 'discount': 0, 'status': 'draft'}), 201); o1 = r.j or {}; track('orders', o1.get('id'))
    r = st('order #1 mark paid (manual-external)', call('manage/orders/' + o1['id'] + '/paid', 'POST', {'reference': 'T-CLUB-PAY-1'}), 200)
    earned = [l for l in ledger(cid) if l.get('orderId') == o1['id'] and l.get('type') == 'earned']
    eq('exactly one earned entry for order #1', len(earned), 1)
    if earned: eq('earned = 10 percent of 399.80', earned[0]['deltaCents'], round(39980 * 0.10))
    eq('balance = 35.00 + 39.98', balance(cid), 3500 + 3998)
    ev['balance_after_paid'] = balance(cid)
    st('mark paid again is idempotent (no second earned entry)', call('manage/orders/' + o1['id'] + '/paid', 'POST', {'reference': 'T-CLUB-PAY-1'}), 200)
    eq('still one earned entry', len([l for l in ledger(cid) if l.get('orderId') == o1['id'] and l.get('type') == 'earned']), 1)
    r = st('order #2 for the dummy customer', call('manage/orders', 'POST', {'customer': cust, 'items': [dict(items[0], qty=1)], 'shippingCost': 0, 'discount': 0, 'status': 'draft'}), 201); o2 = r.j or {}; track('orders', o2.get('id'))
    st('loyalty: lower the redeem cap to 20 percent', call('manage/settings/loyalty', 'PUT', {**loy, 'maxRedeemPercent': 20}), 200)
    st('redeem above the 20 percent cap (45.00 of 199.90, balance is enough) -> 400', call('manage/orders/' + o2['id'] + '/redeem', 'POST', {'memberId': cid, 'amount': 45}), 400, 'סכום המימוש אינו עומד במינימום או בתקרה.')
    st('loyalty: cap back to 50 percent', call('manage/settings/loyalty', 'PUT', loy), 200)
    r = st('redeem 30.00 on order #2', call('manage/orders/' + o2['id'] + '/redeem', 'POST', {'memberId': cid, 'amount': 30}), 200); rd = r.j or {}
    eq('redeem totals', (rd.get('loyaltyDiscountCents'), rd.get('totalCents')), (3000, o2['totalCents'] - 3000))
    eq('balance after redeem', balance(cid), 3500 + 3998 - 3000)
    st('redeem twice on the same order -> 409', call('manage/orders/' + o2['id'] + '/redeem', 'POST', {'memberId': cid, 'amount': 1}), 409)
    st('release-credit on order #2', call('manage/orders/' + o2['id'] + '/release-credit', 'POST', {}), 200)
    eq('balance restored after release', balance(cid), 3500 + 3998)
    st('release twice -> 409', call('manage/orders/' + o2['id'] + '/release-credit', 'POST', {}), 409)
    st('redeem again then pay order #2', call('manage/orders/' + o2['id'] + '/redeem', 'POST', {'memberId': cid, 'amount': 10}), 200)
    st('order #2 mark paid', call('manage/orders/' + o2['id'] + '/paid', 'POST', {'reference': 'T-CLUB-PAY-2'}), 200)
    e2 = [l for l in ledger(cid) if l.get('orderId') == o2['id'] and l.get('type') == 'earned']
    ok('order #2 earned entry exists', len(e2) == 1, e2)
    st('release-credit after paid -> 409', call('manage/orders/' + o2['id'] + '/release-credit', 'POST', {}), 409)
    ev['balance_after_o2'] = balance(cid)

    # ------------------------------------------------------------ 5. suspension, unsubscribe, re-consent
    screen('5 status controls')
    cc = next(c for c in call('manage/cards').j if c['id'] == cid)
    st('suspend the card', call('manage/cards/' + cid, 'PUT', {**cc, 'status': 'suspended'}), 200)
    r = st('order #3 for a suspended card', call('manage/orders', 'POST', {'customer': cust, 'items': [{'productId': prod['id'], 'variantId': P + 'ivory-m', 'size': 'M', 'qty': 1}], 'shippingCost': 0, 'discount': 0, 'status': 'draft'}), 201); o3 = r.j or {}; track('orders', o3.get('id'))
    st('redeem with a suspended card -> 400', call('manage/orders/' + o3['id'] + '/redeem', 'POST', {'memberId': cid, 'amount': 1}), 400)
    st('order #3 mark paid', call('manage/orders/' + o3['id'] + '/paid', 'POST', {'reference': 'T-CLUB-PAY-3'}), 200)
    eq('suspended card earns nothing', len([l for l in ledger(cid) if l.get('orderId') == o3['id'] and l.get('type') == 'earned']), 0)
    cc = next(c for c in call('manage/cards').j if c['id'] == cid)
    st('reactivate the card', call('manage/cards/' + cid, 'PUT', {**cc, 'status': 'active'}), 200)
    st('unsubscribe the member (members PATCH)', call('members/' + mid, 'PATCH', {'status': 'unsubscribed'}), 200)
    eq('member unsubscribed', [m['status'] for m in call('members').j if m['id'] == mid], ['unsubscribed'])
    st('admin cannot silently re-activate mailing -> 409', call('members/' + mid, 'PATCH', {'status': 'active'}), 409, 'הפעלת דיוור מחדש דורשת הסכמה חדשה מהלקוח.')
    st('invalid member status -> 400', call('members/' + mid, 'PATCH', {'status': 'x'}), 400)
    r = st('customer re-joins with new consent -> 200', pub('/api/club', 'POST', {'email': EMAIL, 'consent': True}), 200)
    eq('member active again after new consent', [m['status'] for m in call('members').j if m['id'] == mid], ['active'])
    eq('re-join did not create a second card', len([c for c in call('manage/cards').j if c.get('email') == EMAIL]), 1)
    st('archive the card', call('manage/cards/' + cid + '/archive', 'POST', {}), 200)
    st('adjust on an archived card -> 400', call('manage/loyalty-adjust', 'POST', {'memberId': cid, 'amount': 1, 'reason': 'x'}), 400)
    st('restore the card', call('manage/cards/' + cid + '/restore', 'POST', {}), 200)
    st('adjust works again after restore', call('manage/loyalty-adjust', 'POST', {'memberId': cid, 'amount': 1, 'reason': 'after restore'}), 201)

    # ------------------------------------------------------------ 6. audit trail, ledger integrity
    screen('6 audit and integrity')
    a = call('audit').j or []
    acts = [x['action'] for x in a]
    ok('audit has the loyalty-adjust writes', sum(1 for x in acts if x.startswith('POST /api/admin/manage/loyalty-adjust')) >= 4, acts[:6])
    ok('audit has the card writes', any('manage/cards' in x for x in acts))
    ok('audit has the club setting write', any(x.startswith('PUT /api/admin/settings') for x in acts))
    ok('audit has redeem and release', any('/redeem' in x for x in acts) and any('/release-credit' in x for x in acts))
    ok('audit grew', count('audit') > aud0, (aud0, count('audit')))
    ok('audit never stores secrets', not any(PW in json.dumps(x) for x in a))
    final = ledger(cid)
    ok('no negative balance at any time (ledger sum >= 0)', sum(l['deltaCents'] for l in final) >= 0, [(l['type'], l['deltaCents']) for l in final])
    ev['ledger'] = [(l.get('type'), l['deltaCents']) for l in final]

    # ------------------------------------------------------------ 7. screenshots of the admin UI while the dummy data exists
    screen('7 admin UI')
    try: shots()
    except Exception as e: rec('FAIL', 'admin UI screenshots', repr(e)[:200])

    # ------------------------------------------------------------ 8. disable again
    screen('8 disable')
    st('admin: disable club', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': False, 'title': BRAND + ' club', 'privacyUrl': 'https://example.com/privacy'}}), 200)
    st('signup after disable -> 409', pub('/api/club', 'POST', {'email': P + 'late@example.test', 'consent': True}), 409)
    ok('late signup created nothing', int(sql(f"SELECT COUNT(*) FROM {TBL('members')} WHERE JSON_VALUE(data,'$.email') LIKE 't\\_club\\_late%'")[0] or 0) == 0)
    json.dump(ev, open(f'{SHOTS}/{SITE}_evidence.json', 'w'), ensure_ascii=False, indent=1, default=str)

main()
