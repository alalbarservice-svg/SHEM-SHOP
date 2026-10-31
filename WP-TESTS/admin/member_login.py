#!/usr/bin/env python3
"""
Customer / club-member login-to-account check with DUMMY users (staging only). Usage: member_login.py shem|shimi
Two throwaway WordPress subscribers (t_member_dummy, t_member_other) are created with a generated password kept outside the repo
(/home/claude/.stg-customer-pass, never printed), exercised through the real wp-login.php flow, and deleted at the end together
with every t_member_ row. Settings are snapshotted and restored. Audit rows are append-only by design and stay.
"""
import sys, os, json, re, subprocess, collections
src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'admin_suite.py')).read().rsplit('\nmain()', 1)[0]
src = src.replace('last-run-{SITE}.json', 'last-run-memberlogin-{SITE}.json')
exec(compile(src, 'admin_suite', 'exec'), globals())
P = 't_member_'
EMAIL = P + 'dummy@example.test'
OTHER = P + 'other@example.test'
CPW = open('/home/claude/.stg-customer-pass').read().strip()
SHOTS = os.environ.get('SHOTS', '/tmp/member-shots')
os.makedirs(SHOTS, exist_ok=True)
WPS = '/home/claude/wps.sh'

def wps(*a):
    r = subprocess.run([WPS, SITE, *a], capture_output=True, text=True)
    return (r.stdout or '').strip()

def pub(path, method='GET', data=None, origin=BASE, ctype='application/json', sess=None, headers=None):
    h = dict(headers or {})
    if origin is not None: h['Origin'] = origin
    if ctype: h['Content-Type'] = ctype
    s = sess or requests
    return Resp(s.request(method, BASE + path, data=json.dumps(data) if data is not None else None, headers=h, allow_redirects=False))

def wp_login(user, pw=CPW):
    s = requests.Session()
    s.cookies.set('wordpress_test_cookie', 'WP Cookie check')
    r = s.post(BASE + '/wp-login.php', data={'log': user, 'pwd': pw, 'wp-submit': 'Log In', 'testcookie': '1'}, allow_redirects=False)
    good = r.status_code == 302 and any(k.startswith('wordpress_logged_in') for k in s.cookies.keys())
    return s, good, r

def ui_flow():
    from playwright.sync_api import sync_playwright
    with sync_playwright() as p:
        b = p.chromium.launch(); ctx = b.new_context(viewport={'width': 1280, 'height': 900}); pg = ctx.new_page()
        errs = []; pg.on('pageerror', lambda e: errs.append(str(e)[:120]))
        pg.goto(BASE + '/#/account'); pg.wait_for_timeout(2500)
        body = pg.inner_text('body')
        ok('UI anonymous: account page offers sign-in', 'כניסה' in body, body[:200])
        pg.screenshot(path=f'{SHOTS}/{SITE}_1_anonymous_account.png')
        link = pg.locator('a[href*="signin-with-chatgpt"]')
        ok('UI anonymous: sign-in link present', link.count() >= 1)
        label = link.first.inner_text() if link.count() else ''
        ev['signin_link_label'] = label
        link.first.click(); pg.wait_for_load_state(); pg.wait_for_timeout(1200)
        ev['after_click_url'] = pg.url
        ok('UI sign-in link lands on wp-login.php (staging)', 'wp-login.php' in pg.url, pg.url)
        ev['login_page_title'] = pg.title()
        pg.screenshot(path=f'{SHOTS}/{SITE}_2_login_page.png')
        ev['login_has_register_link'] = pg.locator('a[href*="action=register"]').count() > 0
        ev['login_has_lostpassword_link'] = pg.locator('a[href*="lostpassword"]').count() > 0
        pg.fill('#user_login', P + 'dummy'); pg.fill('#user_pass', CPW); pg.click('#wp-submit'); pg.wait_for_load_state(); pg.wait_for_timeout(3500)
        ev['after_login_url'] = pg.url
        ok('UI after login returns to the account page', '#/account' in pg.url, pg.url)
        body = pg.inner_text('body')
        ev['account_text_sample'] = body[:900]
        ok('UI logged in: shows the e-mail', EMAIL in body, body[:300])
        ok('UI logged in: shows a club card block', ('BRAND' in body) or ('כרטיס' in body) or ('מועדון' in body), body[:300])
        pg.screenshot(path=f'{SHOTS}/{SITE}_3_account_logged_in.png', full_page=True)
        pg.set_viewport_size({'width': 390, 'height': 844}); pg.wait_for_timeout(800)
        pg.screenshot(path=f'{SHOTS}/{SITE}_4_account_mobile.png', full_page=True)
        ok('UI: no page errors', not errs, errs)
        b.close()

ev = {}
def run():
    login(); snapshot()
    uid = wps('user', 'get', P + 'dummy', '--field=ID'); oid = wps('user', 'get', P + 'other', '--field=ID')
    if not uid: uid = wps('user', 'create', P + 'dummy', EMAIL, '--role=subscriber', f'--user_pass={CPW}', '--porcelain')
    else: wps('user', 'update', uid, f'--user_pass={CPW}')
    if not oid: oid = wps('user', 'create', P + 'other', OTHER, '--role=subscriber', f'--user_pass={CPW}', '--porcelain')
    else: wps('user', 'update', oid, f'--user_pass={CPW}')
    ev['wp_users'] = {'dummy': uid, 'other': oid}
    ok('dummy WordPress customers exist', uid.isdigit() and oid.isdigit(), ev['wp_users'])
    ev['wp_options'] = {'users_can_register': wps('option', 'get', 'users_can_register'), 'default_role': wps('option', 'get', 'default_role')}

    if SITE != 'shem':
        # SHIMI has no customer area by design (SCC_ROLE !== 'shem' returns before registering the routes)
        r = pub('/api/customer/me'); ev['shimi_me'] = [r.status, r.t[:120]]
        ok('SHIMI exposes no customer account API', r.status in (404, 405), (r.status, r.t[:120]))
        return

    screen('0 anonymous')
    r = pub('/api/session'); eq('anonymous /api/session', (r.j or {}).get('signedIn'), False)
    r = pub('/api/customer/me'); eq('anonymous /api/customer/me', (r.j or {}).get('signedIn'), False); eq('anonymous me has no orders', (r.j or {}).get('orders'), [])
    ok('anonymous me leaks no member block', 'member' not in (r.j or {}), r.t[:200])
    st('anonymous preferences write -> 401', pub('/api/customer/preferences', 'PUT', {'siteNotifications': False}), 401)
    st('anonymous watch add -> 401', pub('/api/customer/watches', 'POST', {'productId': 'x'}), 401)
    st('anonymous order open without token -> 404', pub('/api/customer/orders/none-1'), 404)

    screen('1 data: club member + paid order for the dummy')
    st('enable club (https privacy link)', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': True, 'title': BRAND + ' club', 'privacyUrl': 'https://example.com/privacy'}}), 200)
    st('dummy joins the club', pub('/api/club', 'POST', {'email': EMAIL, 'consent': True}), 200)
    mem = next((m for m in (call('members').j or []) if m.get('email') == EMAIL), {}); track('members', mem.get('id'))
    card = next((c for c in (call('manage/cards').j or []) if c.get('email') == EMAIL), {}); track('member_profiles', card.get('id')); cid = card.get('id')
    ok('club member + card created', bool(mem.get('id')) and bool(cid), (mem, card))
    loy = {'enabled': True, 'earnPercent': 10, 'maxRedeemPercent': 50, 'vipSpend': 300, 'vipOrders': 2, 'pendingDays': 7, 'expiryDays': 365, 'grossMarginPercent': 20, 'birthdayBenefit': 20, 'benefits': 'גישה מוקדמת (בדיקה)'}
    st('loyalty settings PUT (earn 10 percent, 7 days pending)', call('manage/settings/loyalty', 'PUT', loy), 200)
    r0 = call('manage/cards/' + cid, 'PUT', {**card, 'status': 'suspended'}); ev['signup_only_card_edit_without_name'] = [r0.status, r0.err()]
    st('admin edit card (add name) so it can be edited', call('member-profiles/' + mem['id'], 'PUT', dict(name=P + 'Dummy', tier='standard', phone='0500000001')), 200)
    st('manual +20.00 benefit', call('manage/loyalty-adjust', 'POST', {'memberId': cid, 'amount': 20, 'reason': 'Login check (test)'}), 201)
    vb = variant_body(); vb['he'] = P + 'מוצר'; vb['sku'] = 'T_MEMBER_V'; vb['homeSku'] = 'T_MEMBER_V'; vb['previousPrice'] = 0
    for i, v_ in enumerate(vb['variants']): v_['id'] = P + ['black-s', 'ivory-m'][i]; v_['sku'] = v_['homeSku'] = f'T_MEMBER_V-{i}'
    r = st('create dummy product', call('products', 'POST', vb), 201); prod = r.j or {}; track('products', prod.get('id'))
    cust = dict(name=P + 'Dummy', email=EMAIL, phone='0500000001', city='Test city', street='Test 1')
    items = [{'productId': prod['id'], 'variantId': P + 'black-s', 'size': 'S', 'qty': 1}]
    r = st('order A (dummy email)', call('manage/orders', 'POST', {'customer': cust, 'items': items, 'shippingCost': 0, 'discount': 0, 'status': 'draft'}), 201); oa = r.j or {}; track('orders', oa.get('id'))
    st('order A paid', call('manage/orders/' + oa['id'] + '/paid', 'POST', {'reference': 'T-MEMBER-PAY-A'}), 200)
    cust2 = dict(cust, email=OTHER, name=P + 'Other')
    r = st('order B (other email)', call('manage/orders', 'POST', {'customer': cust2, 'items': [dict(items[0], variantId=P + 'ivory-m', size='M')], 'shippingCost': 0, 'discount': 0, 'status': 'draft'}), 201); ob = r.j or {}; track('orders', ob.get('id'))
    for c in (sql(f"SELECT id FROM {TBL('customers')} WHERE LOWER(JSON_VALUE(data,'$.email')) IN ({q(EMAIL)},{q(OTHER)})")[0] or '').split():
        track('customers', c)
    ordA = (call('manage/orders/' + oa['id']).j or {}) if False else {}
    tokA = (sql(f"SELECT JSON_VALUE(data,'$.viewToken') FROM {TBL('orders')} WHERE id={q(oa['id'])}")[0] or '').strip()
    ev['has_view_token'] = bool(tokA)

    screen('2 login (real wp-login.php)')
    s_bad, good, r = wp_login(P + 'dummy', 'wrong-password-xyz'); ok('wrong password does not log in', not good and r.status_code in (200, 302) and not any(k.startswith('wordpress_logged_in') for k in s_bad.cookies.keys()), r.status_code)
    s, good, r = wp_login(P + 'dummy'); ok('correct password logs in (302 + cookie)', good, r.status_code); ev['login_redirect'] = r.headers.get('location')
    r = pub('/api/session', sess=s); eq('session signedIn', (r.j or {}).get('signedIn'), True); eq('session email', (r.j or {}).get('email'), EMAIL)
    r = pub('/api/customer/me', sess=s); me = r.j or {}
    eq('me signedIn', me.get('signedIn'), True); eq('me email', me.get('email'), EMAIL)
    eq('me lists exactly the dummy order', [o['id'] for o in me.get('orders', [])], [oa['id']])
    ok('me never leaks the other customer order', ob['id'] not in json.dumps(me))
    ok('me order view has no viewToken/notes', 'viewToken' not in json.dumps(me) and '"notes"' not in json.dumps(me))
    m = me.get('member') or {}
    ok('me has the member block', bool(m), me.get('member'))
    eq('member status', m.get('status'), 'active'); eq('member tier', m.get('tier'), 'standard')
    ok('member card number format', bool(re.match(r'^[A-Z]+-[0-9A-F]{12}$', str(m.get('cardNumber') or ''))), m.get('cardNumber'))
    eq('member available balance = 20.00 (earn is pending)', m.get('balanceCents'), 2000)
    ok('member pending credit > 0 after paid order', (m.get('pendingCents') or 0) > 0, m.get('pendingCents'))
    ok('member benefits text present', bool(m.get('benefits')), m.get('benefits'))
    eq('member block enabled flag', m.get('enabled'), True)
    ev['me_member'] = m

    screen('3 ownership and permissions')
    r = pub('/api/customer/orders/' + oa['id'], sess=s); st('own order opens', r, 200)
    r = pub('/api/customer/orders/' + ob['id'], sess=s); st("another customer's order -> 404", r, 404)
    ok('404 body does not reveal the order', ob['id'] not in r.t and OTHER not in r.t, r.t[:160])
    r = pub('/api/customer/orders/' + oa['id']); st('own order without login and without token -> 404', r, 404)
    if tokA:
        r = pub('/api/customer/orders/' + oa['id'], headers={'Authorization': 'Bearer ' + tokA}); st('own order with private link token (guest) -> 200', r, 200)
        r = pub('/api/customer/orders/' + ob['id'], headers={'Authorization': 'Bearer ' + tokA}); st("token of order A cannot open order B -> 404", r, 404)
        r = pub('/api/customer/orders/' + oa['id'], headers={'Authorization': 'Bearer ' + tokA + 'x'}); st('wrong token -> 404', r, 404)
    s2, good2, _ = wp_login(P + 'other'); ok('second customer logs in', good2)
    r = pub('/api/customer/orders/' + oa['id'], sess=s2); st("second customer cannot open the dummy's order -> 404", r, 404)
    me2 = pub('/api/customer/me', sess=s2).j or {}
    ok("second customer's me has no club member block (never joined)", me2.get('member') is None, me2.get('member'))
    eq("second customer's orders", [o['id'] for o in me2.get('orders', [])], [ob['id']])

    screen('4 preferences, watches, CSRF')
    st('preferences off (own)', pub('/api/customer/preferences', 'PUT', {'siteNotifications': False}, sess=s), 200)
    eq('preferences persisted', (pub('/api/customer/me', sess=s).j or {}).get('preferences'), {'siteNotifications': False})
    eq("second customer's preferences untouched", (pub('/api/customer/me', sess=s2).j or {}).get('preferences'), {'siteNotifications': True})
    st('write from a foreign Origin -> 403', pub('/api/customer/preferences', 'PUT', {'siteNotifications': True}, origin='https://evil.example', sess=s), 403)
    st('write without Origin -> 403', pub('/api/customer/preferences', 'PUT', {'siteNotifications': True}, origin=None, sess=s), 403)
    st('write with non-JSON content type -> 415', pub('/api/customer/preferences', 'PUT', {'siteNotifications': True}, ctype='text/plain', sess=s), 415)
    v0 = (prod.get('variants') or [{}])[0]
    r = pub('/api/customer/watches', 'POST', {'productId': prod['id'], 'variantId': P + 'black-s', 'size': 'S'}, sess=s); ev['watch_add'] = [r.status, r.t[:120]]
    wid = f"{uid}:{prod['id']}:{P}black-s"
    if r.status == 201:
        eq('watch saved for the dummy', [w['id'] for w in (pub('/api/customer/me', sess=s).j or {}).get('watches', [])], [wid])
        st("other customer cannot delete the dummy's watch (no-op, 200)", pub('/api/customer/watches/' + wid, 'DELETE', sess=s2), 200)
        eq('watch still there', len((pub('/api/customer/me', sess=s).j or {}).get('watches', [])), 1)
        st('owner deletes the watch', pub('/api/customer/watches/' + wid, 'DELETE', sess=s), 200)
        eq('watch gone', (pub('/api/customer/me', sess=s).j or {}).get('watches'), [])
    else: rec('FAIL', 'watch add', f'{r.status} {r.t[:160]}')

    screen('5 club state seen by the customer')
    st('suspend the card', call('manage/cards/' + cid, 'PUT', {**next(c for c in call('manage/cards').j if c['id'] == cid), 'status': 'suspended'}), 200)
    eq('suspended card is reported to the customer', (pub('/api/customer/me', sess=s).j or {}).get('member', {}).get('status'), 'suspended')
    st('reactivate the card', call('manage/cards/' + cid, 'PUT', {**next(c for c in call('manage/cards').j if c['id'] == cid), 'status': 'active'}), 200)
    st('archive the card', call('manage/cards/' + cid + '/archive', 'POST', {}), 200)
    ok('archived card disappears from the customer view', (pub('/api/customer/me', sess=s).j or {}).get('member') is None)
    st('restore the card', call('manage/cards/' + cid + '/restore', 'POST', {}), 200)
    ok('restored card is visible again', (pub('/api/customer/me', sess=s).j or {}).get('member') is not None)
    st('turn the benefits program off (loyalty.enabled=false)', call('manage/settings/loyalty', 'PUT', {**loy, 'enabled': False}), 200)
    eq('member.enabled is the BENEFITS-PROGRAM switch (loyalty.enabled), not the club switch', (pub('/api/customer/me', sess=s).j or {}).get('member', {}).get('enabled'), False)
    st('turn the benefits program on again', call('manage/settings/loyalty', 'PUT', loy), 200)
    st('disable only the club signup switch', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': False, 'title': BRAND + ' club', 'privacyUrl': 'https://example.com/privacy'}}), 200)
    eq('club switch off does not change what the member sees (enabled stays true)', (pub('/api/customer/me', sess=s).j or {}).get('member', {}).get('enabled'), True)
    st('re-enable the club for the UI check', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': True, 'title': BRAND + ' club', 'privacyUrl': 'https://example.com/privacy'}}), 200)

    screen('6 browser flow')
    try: ui_flow()
    except Exception as e: rec('FAIL', 'browser flow', repr(e)[:240])

    screen('7 logout and session end')
    r = s.get(BASE + '/wp-login.php?action=logout', allow_redirects=False); ev['logout_page'] = r.status_code
    m = re.search(r'href="([^"]*action=logout[^"]*_wpnonce=[^"]+)"', r.text or '')
    if m:
        r2 = s.get(m.group(1).replace('&amp;', '&'), allow_redirects=False); ev['logout_confirm'] = r2.status_code
    eq('after logout me is anonymous', (pub('/api/customer/me', sess=s).j or {}).get('signedIn'), False)
    st('after logout the order is closed again -> 404', pub('/api/customer/orders/' + oa['id'], sess=s), 404)
    st('disable the club (restore)', call('settings', 'PUT', {'key': 'club', 'value': {'enabled': False, 'title': BRAND + ' club', 'privacyUrl': 'https://example.com/privacy'}}), 200)
    json.dump(ev, open(f'{SHOTS}/{SITE}_evidence.json', 'w'), ensure_ascii=False, indent=1, default=str)

def wp_cleanup():
    ids = [x for x in (wps('user', 'get', P + 'dummy', '--field=ID'), wps('user', 'get', P + 'other', '--field=ID')) if x.isdigit()]
    if ids:
        sql(f"DELETE FROM {TBL('cx_watches')} WHERE JSON_VALUE(data,'$.userId') IN ({inlist(set(ids))})")
        sql(f"DELETE FROM {TBL('cx_preferences')} WHERE id IN ({inlist(set(ids))})")
        wps('user', 'delete', *ids, '--yes')
    left = [x for x in (wps('user', 'get', P + 'dummy', '--field=ID'), wps('user', 'get', P + 'other', '--field=ID')) if x.isdigit()]
    print('wp users left after cleanup:', left)

def main():
    try: run()
    except Exception as e:
        import traceback; traceback.print_exc(); rec('FAIL', 'suite crashed', repr(e))
    finally:
        try: cleanup()
        except Exception as e: print('cleanup problem', e)
        try: wp_cleanup()
        except Exception as e: print('wp cleanup problem', e)
    by = collections.OrderedDict()
    for sc, label, status, det in RES: by.setdefault(sc, collections.Counter())[status] += 1
    tot = collections.Counter(s for _, _, s, _ in RES)
    print(f'\n== {SITE}: ' + ', '.join(f'{k}={v}' for k, v in sorted(tot.items())) + f' (total {len(RES)})')
    for sc, c in by.items(): print(f'  {sc:55s}', dict(c))
    for sc, label, status, det in RES:
        if status != 'PASS': print('  !!', sc, '|', label, '|', str(det)[:200])
    json.dump([{'screen': a, 'check': b, 'status': c, 'detail': d} for a, b, c, d in RES], open(f'/home/claude/shem-shop/WP-TESTS/admin/last-run-memberlogin-{SITE}.json', 'w'), ensure_ascii=False, indent=1)
    sys.exit(1 if tot['FAIL'] else 0)
main()
