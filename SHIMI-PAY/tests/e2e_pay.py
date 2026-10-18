#!/usr/bin/env python3
"""Staging e2e for the SHEM -> SHIMI -> PayPal Sandbox payment flow (no browser buyer login).
Secrets are read at run time from wp-config via WP-CLI and are never printed.
Run: python3 -I e2e_pay.py    (staging containers shem-staging-* must be up)"""
import json, hmac, hashlib, subprocess, time, urllib.request, urllib.error, re, sys
WPS = '/home/claude/wps.sh'
SHEM = 'http://localhost:8081'; SHIMI = 'http://localhost:8082'
res = []
def ok(name, cond, extra=''):
    res.append((name, bool(cond))); print(('PASS ' if cond else 'FAIL ') + name + (' | ' + str(extra) if extra and not cond else ''))
def wp(site, *a):
    p = subprocess.run([WPS, site, *a], capture_output=True, text=True, timeout=180)
    return p.stdout.strip()
def php(site, code):
    return subprocess.run([WPS, site, 'eval', code], capture_output=True, text=True, timeout=180).stdout.strip().splitlines()[-1:] or ['']
SECRET = wp('shem', 'config', 'get', 'SHEM_HOME_PAYMENT_SECRET')
def sign(raw, ts=None, secret=SECRET):
    ts = str(ts if ts is not None else int(time.time()))
    return ts, hmac.new(secret.encode(), (ts + '.').encode() + raw, hashlib.sha256).hexdigest()
def http(method, url, body=None, headers=None):
    req = urllib.request.Request(url, data=body, method=method, headers=headers or {})
    try:
        r = urllib.request.urlopen(req, timeout=60); return r.status, r.read(), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read(), dict(e.headers)
def session(body, **kw):
    raw = json.dumps(body).encode(); ts, sig = sign(raw, kw.get('ts'), kw.get('secret', SECRET))
    return http('POST', SHIMI + '/?rest_route=/shimi-pay/v1/session', raw, {'content-type': 'application/json', 'x-shem-timestamp': ts, 'x-shem-signature': sig})
def base(oid, amount=3500, items=None):
    return {'protocol': 'shem-home-v1', 'orderId': str(oid), 'currency': 'ILS', 'amountCents': amount, 'customer': {'name': 'Test', 'email': 'buyer1@example.test'},
            'items': items or [{'productId': '13', 'sku': 'X', 'qty': 1, 'priceCents': 2990}], 'shippingCents': 510, 'discountCents': 0}

# --- A. SHIMI session endpoint: signature and validation (no money involved)
b = base('t-neg-1')
ok('A1 bad signature -> 401', session(b, secret='x' * 64)[0] == 401)
ok('A2 stale timestamp -> 401', session(b, ts=int(time.time()) - 4000)[0] == 401)
ok('A3 totals mismatch -> 422', session(dict(b, amountCents=9999))[0] == 422)
ok('A4 non-ILS -> 400', session(dict(b, currency='USD'))[0] == 400)
ok('A5 no items -> 400', session(dict(b, items=[]))[0] == 400)
ok('A6 float amount -> 400', session(dict(b, amountCents=3500.0))[0] == 400)
st, body, _ = http('POST', SHIMI + '/?rest_route=/shimi-pay/v1/session', b'{}', {'content-type': 'application/json'})
ok('A7 unsigned -> 401', st == 401)

# --- B. real WooCommerce order on SHEM through the gateway
oid = php('shem', r'''$o=wc_create_order();$o->add_product(wc_get_product(13),1);$o->set_billing_first_name("Test");$o->set_billing_last_name("Buyer");$o->set_billing_email("buyer1@example.test");$o->set_payment_method("shem_via_shimi");$o->set_currency("ILS");$s=new WC_Order_Item_Shipping();$s->set_method_title("Courier");$s->set_total(5.10);$o->add_item($s);$o->calculate_totals();$o->save();echo $o->get_id();''')[0]
ok('B1 order created', oid.isdigit(), oid)
pay = php('shem', f'$o=wc_get_order({oid});$g=new WC_Gateway_Shem_Via_Shimi();$r=$g->process_payment({oid});echo wp_json_encode(["r"=>$r["result"],"u"=>$r["redirect"]??"","e"=>wc_get_order({oid})->get_meta("_shem_pay_embed"),"s"=>wc_get_order({oid})->get_status(),"t"=>wc_get_order({oid})->get_total()]);')[0]
pj = json.loads(pay) if pay.startswith('{') else {}
ok('B2 gateway opened a SHIMI session', pj.get('r') == 'success' and 'shimi_pay=' in pj.get('e', ''), pay)
ok('B3 order is pending (not paid) after opening', pj.get('s') == 'pending')
embed = pj.get('e', ''); m = re.search(r'shimi_pay=([a-f0-9]{24})&token=([a-f0-9]{32})', embed.replace('&#038;', '&').replace('&amp;', '&'))
sid, tok = (m.group(1), m.group(2)) if m else ('', '')

# --- C. embeddable page
cid = wp('shimi', 'config', 'get', 'SHIMI_PAYPAL_CLIENT_ID'); sec = wp('shimi', 'config', 'get', 'SHIMI_PAYPAL_SECRET')
st, page, hd = http('GET', embed.replace('&#038;', '&'))
txt = page.decode('utf-8', 'replace'); H = {k.lower(): v for k, v in hd.items()}
ok('C1 page 200', st == 200)
ok('C2 frame-ancestors only SHEM', 'frame-ancestors http://localhost:8081' in H.get('content-security-policy', ''))
ok('C3 no-store', 'no-store' in H.get('cache-control', ''))
ok('C4 client id present, SECRET absent from page', cid in txt and sec not in txt and SECRET not in txt)
ok('C5 wrong token -> 404', http('GET', SHIMI + f'/?shimi_pay={sid}&token=' + '0' * 32)[0] == 404)
real = json.loads(php('shem', f'echo wp_json_encode(WC_Gateway_Shem_Via_Shimi::build_body(wc_get_order({oid})));')[0])
st6, b6, _ = session(real)
j6 = json.loads(b6 or b'{}'); ok('C6 same order again reuses session (idempotent, fresh token)', st6 == 200 and j6.get('reused') is True, (st6, b6[:120]))
bump = dict(real, amountCents=real['amountCents'] + 500, items=[dict(real['items'][0], priceCents=real['items'][0]['priceCents'] + 500)] + real['items'][1:])
st6b, _, _ = session(bump); ok('C7 same order with another amount -> 409', st6b == 409)
m = re.search(r'shimi_pay=([a-f0-9]{24})&token=([a-f0-9]{32})', j6.get('embedUrl', '').replace('&#038;', '&').replace('&amp;', '&')); tok = m.group(2) if m else tok
ok('C8 old token no longer valid after re-open', http('GET', embed.replace('&#038;', '&'))[0] == 404)

# --- D. PayPal Sandbox order (server side)
api = SHIMI + '/?rest_route=/shimi-pay/v1'
def jpost(path, d):
    st, b, _ = http('POST', api + path, json.dumps(d).encode(), {'content-type': 'application/json'}); return st, (json.loads(b) if b else {})
st, d = jpost('/paypal/create', {'sid': sid, 'token': '1' * 32}); ok('D1 create with bad token -> 404', st == 404)
st, d = jpost('/paypal/create', {'sid': sid, 'token': tok}); ppid = d.get('id', '')
ok('D2 PayPal Sandbox order created', st == 200 and len(ppid) > 8, (st, d))
st2, d2 = jpost('/paypal/create', {'sid': sid, 'token': tok}); ok('D3 create is idempotent (same PayPal order)', st2 == 200 and d2.get('id') == ppid)
st, d = jpost('/paypal/capture', {'sid': sid, 'token': tok, 'paypalOrderId': 'FAKE123'}); ok('D4 capture of another PayPal order id -> 409', st == 409)
st, d = jpost('/paypal/capture', {'sid': sid, 'token': tok, 'paypalOrderId': ppid}); ok('D5 capture before buyer approval is refused, nothing paid', st in (502, 409), (st, d))
paid = php('shem', f'echo wc_get_order({oid})->is_paid()?"paid":"unpaid";')[0]
ok('D6 SHEM order still unpaid', paid == 'unpaid', paid)
poll = http('GET', SHEM + f'/?rest_route=/shem-pay/v1/status&order={oid}&key=bad')[0]; ok('D7 status with wrong order key -> 404', poll == 404)


# --- E. capture verification + signed callback + invoice. PayPal's CAPTURE response is MOCKED here (no buyer approval and no
#        card processing are available in this sandbox account); order creation, HMAC, the callback to SHEM and the invoice are real.
import random
def new_flow(tag):
    o = php('shem', r"""$o=wc_create_order();$o->add_product(wc_get_product(13),1);$o->set_billing_first_name("Test");$o->set_billing_last_name("Buyer");$o->set_billing_email("buyer1@example.test");$o->set_payment_method("shem_via_shimi");$o->set_currency("ILS");$o->calculate_totals();$o->save();$g=new WC_Gateway_Shem_Via_Shimi();$g->process_payment($o->get_id());echo wp_json_encode(["id"=>$o->get_id(),"cents"=>(int)round($o->get_total()*100),"e"=>wc_get_order($o->get_id())->get_meta("_shem_pay_embed")]);""")[0]
    j = json.loads(o); m = re.search(r'shimi_pay=([a-f0-9]{24})&token=([a-f0-9]{32})', j['e'].replace('&#038;', '&').replace('&amp;', '&'))
    s, k = m.group(1), m.group(2)
    st, d = jpost('/paypal/create', {'sid': s, 'token': k})
    return {'oid': j['id'], 'cents': j['cents'], 'sid': s, 'tok': k, 'pp': d.get('id', '')}
def mock_resp(f, cents=None, cur='ILS', status='COMPLETED', cap='COMPLETED', custom=None):
    c = f['cents'] if cents is None else cents
    return {'id': f['pp'], 'status': status, 'payer': {'name': {'given_name': 'Test', 'surname': 'Buyer'}},
            'purchase_units': [{'custom_id': custom or f['sid'], 'payments': {'captures': [{'id': 'CAP%08d' % random.randint(0, 10**8), 'status': cap, 'amount': {'currency_code': cur, 'value': '%.2f' % (c / 100)}, 'custom_id': custom or f['sid']}]}}]}
HOOK = r"""add_filter("pre_http_request",function($pre,$a,$u) use($mock,$cb){ if(strpos($u,"/capture")!==false) return ["response"=>["code"=>201,"message"=>"Created"],"body"=>$mock,"headers"=>[],"cookies"=>[]]; if($cb&&strpos($u,"shimi/paid")!==false) return ["response"=>["code"=>$cb,"message"=>"x"],"body"=>"{}","headers"=>[],"cookies"=>[]]; return $pre;},10,3);"""
def capture(f, mock, cbcode=None, ppid=None):
    code = '$mock=' + json.dumps(json.dumps(mock)) + ';$cb=' + (str(cbcode) if cbcode else 'null') + ';' + HOOK
    code += '$q=new WP_REST_Request("POST","/shimi-pay/v1/paypal/capture");$q->set_header("content-type","application/json");$q->set_body(wp_json_encode(["sid"=>"%s","token"=>"%s","paypalOrderId"=>"%s"]));$r=rest_do_request($q);echo wp_json_encode(["st"=>$r->get_status(),"d"=>$r->get_data()]);' % (f['sid'], f['tok'], ppid or f['pp'])
    return json.loads(php('shimi', code)[0])
def state(f): return json.loads(php('shimi', 'echo wp_json_encode(Shimi_Pay::load("%s"));' % f['sid'])[0])
def paid(f): return php('shem', 'echo wc_get_order(%s)->is_paid()?"paid":"unpaid";' % f['oid'])[0]
def invoices(f): return wp('shem', 'db', 'query', "SELECT COUNT(*) FROM wp_shem_dok WHERE order_id=%s AND doc_type='invoice'" % f['oid']).split()[-1]
orig_auto = php('shem', r'$s=get_option("shem_dok_settings",[]);echo !empty($s["auto"])?"1":"0";')[0]
php('shem', r'$s=get_option("shem_dok_settings",[]);$s["auto"]=true;update_option("shem_dok_settings",$s);echo "ok";')
try:
    f = new_flow('e1'); ok('E0 flow setup (real PayPal Sandbox order)', len(f['pp']) > 8, f)
    r = capture(f, mock_resp(f)); s1 = state(f)
    ok('E1 verified capture -> 200 ok', r['st'] == 200 and r['d'].get('ok') is True, r)
    ok('E1b SHIMI state callback_ok after signed callback', s1['state'] == 'callback_ok' and s1['cbAttempts'] == 1, s1['state'])
    ok('E1c SHEM order paid with the PayPal capture id', paid(f) == 'paid' and php('shem', 'echo wc_get_order(%s)->get_transaction_id();' % f['oid'])[0] == s1['txn'])
    ok('E1d SHEM recorded method=paypal', php('shem', 'echo wc_get_order(%s)->get_meta("_shem_pay_method");' % f['oid'])[0] == 'paypal')
    for _ in range(10):
        n = invoices(f)
        if n != '0': break
        wp('shem', 'cron', 'event', 'run', '--due-now'); time.sleep(1)
    ok('E1e invoice issued by SHEM DOK after the signed callback (exactly 1)', n == '1', n)
    r2 = capture(f, mock_resp(f)); ok('E2 replay of capture -> duplicate, no second callback', r2['st'] == 200 and r2['d'].get('duplicate') is True and state(f)['cbAttempts'] == 1, r2)
    ok('E2b still exactly 1 invoice', invoices(f) == '1')
    for name, kw in [('E3 PENDING capture', dict(cap='PENDING')), ('E4 wrong amount', dict(cents=100)), ('E5 wrong currency', dict(cur='USD')), ('E6 custom_id of another session', dict(custom='f' * 24)), ('E7 order status not COMPLETED', dict(status='APPROVED'))]:
        g = new_flow('x'); r = capture(g, mock_resp(g, **kw))
        ok(name + ' -> refused, SHEM unpaid, no invoice', r['st'] == 409 and paid(g) == 'unpaid' and state(g)['state'] == 'pp_created' and invoices(g) == '0', (r, state(g)['state']))
    g = new_flow('cb503'); r = capture(g, mock_resp(g), cbcode=503); sg = state(g)
    ok('E8 SHEM unreachable (503): payment verified, callback pending, SHEM unpaid', r['st'] == 200 and sg['state'] == 'callback_pending' and paid(g) == 'unpaid', (r, sg['state']))
    ev = php('shimi', 'echo count(array_filter((array)_get_cron_array(),function($h){return isset($h["shimi_pay_retry"]);}));')[0]
    ok('E8b retry event scheduled', ev.isdigit() and int(ev) >= 1, ev)
    wp('shimi', 'shimi-pay', 'resend'); sg = state(g)
    ok('E8c reconcile (wp shimi-pay resend) delivers the callback -> SHEM paid', sg['state'] == 'callback_ok' and paid(g) == 'paid', sg['state'])
    g = new_flow('amt'); php('shem', r'''$o=wc_get_order(%s);$f=new WC_Order_Item_Fee();$f->set_name("late fee");$f->set_total(10);$o->add_item($f);$o->calculate_totals();$o->save();echo "ok";''' % g['oid'])
    r = capture(g, mock_resp(g)); sg = state(g)
    ok('E9 order changed after session: SHEM rejects the callback (amount mismatch) -> callback_rejected, unpaid', sg['state'] == 'callback_rejected' and paid(g) == 'unpaid' and 'amount_mismatch' in sg['cbError'], (sg['state'], sg['cbError'][:120]))
finally:
    php('shem', r'$s=get_option("shem_dok_settings",[]);$s["auto"]=%s;update_option("shem_dok_settings",$s);echo "ok";' % ('true' if orig_auto == '1' else 'false'))


# --- F. reconcile (polling): payments approved/captured at PayPal whose browser flow never completed. PayPal GET/capture responses are MOCKED.
def backdate(f, secs=1200):
    php('shimi', 'update_option("shimi_pay_s_%s",wp_json_encode(array_merge(Shimi_Pay::load("%s"),["created"=>time()-%d])),false);echo "ok";' % (f['sid'], f['sid'], secs))
def reconcile(f, get_mock, cap_mock=None):
    code = '$g=%s;$c=%s;' % (json.dumps(json.dumps(get_mock)), json.dumps(json.dumps(cap_mock)) if cap_mock else 'null')
    code += 'add_filter("pre_http_request",function($pre,$a,$u) use($g,$c){ if(strpos($u,"/orders/")!==false){ if(strpos($u,"/capture")!==false&&$c)return ["response"=>["code"=>201,"message"=>"x"],"body"=>$c,"headers"=>[],"cookies"=>[]]; if(strpos($u,"/capture")===false)return ["response"=>["code"=>200,"message"=>"OK"],"body"=>$g,"headers"=>[],"cookies"=>[]]; } return $pre;},10,3);'
    code += 'echo wp_json_encode(Shimi_Pay::reconcile());'
    return json.loads(php('shimi', code)[0]).get(f['sid'], 'not-scanned')
php('shem', r'$s=get_option("shem_dok_settings",[]);$s["auto"]=true;update_option("shem_dok_settings",$s);echo "ok";')
try:
    f = new_flow('r1'); r = reconcile(f, mock_resp(f)); sf = state(f)
    ok('F0 younger than 15 minutes -> not touched', r == 'not-scanned' and sf['state'] == 'pp_created', (r, sf['state']))
    backdate(f); r = reconcile(f, mock_resp(f)); sf = state(f)
    ok('F1 captured at PayPal (COMPLETED) but never reached SHEM -> reconciled, callback_ok, SHEM paid', r == 'reconciled' and sf['state'] == 'callback_ok' and paid(f) == 'paid', (r, sf['state']))
    for _ in range(10):
        n = invoices(f)
        if n != '0': break
        wp('shem', 'cron', 'event', 'run', '--due-now'); time.sleep(1)
    ok('F1b exactly 1 invoice after reconcile', n == '1', n)
    g = new_flow('r2'); backdate(g); appr = mock_resp(g); appr['status'] = 'APPROVED'; appr['purchase_units'][0]['payments'] = {}
    r = reconcile(g, appr, mock_resp(g)); sg = state(g)
    ok('F2 APPROVED but not captured -> server captures, then reconciled and paid', r == 'reconciled' and sg['state'] == 'callback_ok' and paid(g) == 'paid', (r, sg['state']))
    h = new_flow('r3'); backdate(h); op = mock_resp(h); op['status'] = 'CREATED'; op['purchase_units'][0]['payments'] = {}
    r = reconcile(h, op); sh = state(h)
    ok('F3 still CREATED at PayPal (buyer never approved) -> left alone, unpaid', r == 'open_CREATED' and sh['state'] == 'pp_created' and paid(h) == 'unpaid', (r, sh['state']))
    k = new_flow('r4'); backdate(k); r = reconcile(k, mock_resp(k, cents=100)); sk = state(k)
    ok('F4 wrong amount at PayPal -> not verified, unpaid, no invoice', r == 'not_verified' and paid(k) == 'unpaid' and invoices(k) == '0', (r, sk['state']))
    r = reconcile(f, mock_resp(f)); ok('F5 already settled session is not scanned again', r == 'not-scanned', r)
finally:
    php('shem', r'$s=get_option("shem_dok_settings",[]);$s["auto"]=%s;update_option("shem_dok_settings",$s);echo "ok";' % ('true' if orig_auto == '1' else 'false'))

print(json.dumps({'oid': oid, 'sid': sid, 'ppid': ppid}))
f = [n for n, c in res if not c]; print(f'\n{len(res)-len(f)}/{len(res)} passed'); sys.exit(1 if f else 0)
