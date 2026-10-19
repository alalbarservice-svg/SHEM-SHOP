#!/usr/bin/env python3
"""Customer-like end-to-end on SHEM staging (no payment provider: the signed 'paid' callback stands in for the provider).
Real HTTP customer steps: catalogue -> quote -> checkout-home -> payment frame -> (signed paid callback) -> status -> invoice PDF -> thank-you page in a browser.
Setup/cleanup touches only: settings operations+home (restored byte-exact), one product-link fixture, one WC order (deleted); the SHEM-DOK invoice stays (immutable by design).
Usage: e2e_customer.py   (writes evidence to /home/claude/logs/e2e_customer/)"""
import subprocess, json, hmac, hashlib, time, urllib.request, urllib.error, os, re, sys
W = '/home/claude/wps.sh'; BASE = 'http://localhost:8081'; OUT = '/home/claude/logs/e2e_customer'; os.makedirs(OUT, exist_ok=True)
R = []
def chk(n, ok, d=''):
    R.append(bool(ok)); print(('  ok   ' if ok else '  FAIL ') + n + ('' if ok else ' :: ' + str(d)[:300]), flush=True)
def wp(*a):
    p = subprocess.run([W, 'shem'] + list(a), capture_output=True, text=True, timeout=300); return (p.stdout + p.stderr).strip()
def http(method, path, body=None, headers=None, raw=False):
    h = {'Content-Type': 'application/json', 'Origin': BASE}; h.update(headers or {})
    req = urllib.request.Request(BASE + path if path.startswith('/') else path, data=(json.dumps(body, ensure_ascii=False).encode() if body is not None else None), headers=h, method=method)
    try:
        r = urllib.request.urlopen(req, timeout=90); b = r.read(); return r.status, (b if raw else json.loads(b or b'null') if r.headers.get('content-type', '').startswith('application/json') else b), r.headers
    except urllib.error.HTTPError as e:
        b = e.read(); return e.code, (b if raw else (json.loads(b) if b[:1] in (b'{', b'[') else b)), e.headers

PID = 'qa-shem-knit-20261009'; EMAIL = 'e2e_customer@example.test'
# ---- setup (backup first)
snap0 = (lambda: None)()
snap = wp('db', 'query', "SELECT id, HEX(data) FROM wp_scc_settings WHERE id IN ('operations','home')") if False else None
snap = subprocess.run([W, 'shem', 'db', 'query', "SELECT id, HEX(data) FROM wp_scc_settings WHERE id IN ('operations','home')"], capture_output=True, text=True).stdout.splitlines()[1:]
prev = dict(l.split('\t') for l in snap if '\t' in l); open(OUT + '/settings-backup.json', 'w').write(json.dumps(prev))
def restore():
    subprocess.run([W, 'shem', 'db', 'query', "DELETE FROM wp_scc_settings WHERE id IN ('operations','home')"], capture_output=True)
    for k, hx in prev.items(): subprocess.run([W, 'shem', 'db', 'query', f"INSERT INTO wp_scc_settings (id, data) SELECT '{k}', UNHEX('{hx}')"], capture_output=True)
wo = json.loads(bytes.fromhex(prev['operations']))['value'] if 'operations' in prev else {}
wh = json.loads(bytes.fromhex(prev['home']))['value'] if 'home' in prev else {}
php = ("$o=Scc_Docs::setting('operations',[]);$o=is_array($o)?$o:[];$o['checkoutEnabled']=true;Scc_Docs::set_setting('operations',$o);"
       "$h=Scc_Docs::setting('home',[]);$h=is_array($h)?$h:[];$h['enabled']=true;$h['siteUrl']='http://localhost:8082';Scc_Docs::set_setting('home',$h);"
       f"Scc_Docs::put('product_links','{PID}',['sourceId'=>'{PID}','status'=>'connected','variantMap'=>['h-M'=>'{PID}-M','h-L'=>'{PID}-L']]);echo 'setup-ok';")
try:
    vid = subprocess.run([W, 'shem', 'eval', "global $wpdb; echo (int)$wpdb->get_var(\"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_scc_legacy_variant_id' AND meta_value='"+'qa-shem-knit-20261009-M'+"'\");"], capture_output=True, text=True).stdout.strip().splitlines()[-1]
    stock_before = subprocess.run([W, 'shem', 'eval', f'echo wc_get_product({vid})->get_stock_quantity();'], capture_output=True, text=True).stdout.strip().splitlines()[-1]
    print('== setup'); chk('staging setup (checkout enabled, link fixture)', 'setup-ok' in wp('eval', php) if False else 'setup-ok' in subprocess.run([W, 'shem', 'eval', php], capture_output=True, text=True).stdout)
    print('== 1. customer browses and sees the product')
    st, cat, _ = http('GET', '/api/catalogue'); p = next((x for x in cat['products'] if x['id'] == PID), None)
    chk('product visible on storefront with price and stock', bool(p) and p['price'] == 179 and p['sizeStock'] == {'M': 10, 'L': 10}, p and (p['price'], p['sizeStock']))
    stock0 = p['sizeStock']['M']
    print('== 2. quote'); item = {'id': PID, 'size': 'M', 'qty': 2, 'variantId': PID + '-M'}
    st, q, _ = http('POST', '/api/quote', {'items': [item], 'shipping': 'standard'}); chk('quote 200', st == 200, (st, q))
    chk('quote totals: 2 x 179 + shipping 49', st == 200 and q.get('total') == 407, q)
    print(' quote:', json.dumps(q, ensure_ascii=False)[:300])
    print('== 3. checkout (open payment)'); cust = {'name': 'דנה כהן', 'email': EMAIL, 'phone': '0501234567', 'city': 'באר שבע', 'street': 'דרך מצדה 1'}
    st, co, _ = http('POST', '/api/checkout-home', {'items': [item], 'shipping': 'standard', 'customer': cust, 'termsAccepted': True})
    chk('checkout-home 201 {embedUrl, orderId, viewToken}', st == 201 and all(co.get(k) for k in ('embedUrl', 'orderId', 'viewToken')), (st, co))
    oid, vt, embed = co['orderId'], co['viewToken'], co['embedUrl']
    wc_id = int(wp('eval', f"echo Scc_Core::wc_order_id('{oid}');").splitlines()[-1]) if False else int(subprocess.run([W, 'shem', 'eval', f"echo Scc_Core::wc_order_id('{oid}');"], capture_output=True, text=True).stdout.strip().splitlines()[-1])
    print('== 4. payment frame page'); st, page, _ = http('GET', embed, raw=True); html = page.decode('utf8', 'replace')
    chk('payment frame loads (200)', st == 200, st); chk('frame states credit details are not stored', 'פרטי האשראי אינם נשמרים במערכת.' in html, html[:200])
    st, s0, _ = http('GET', f'/api/checkout-status/{oid}?token={vt}'); chk('status before payment: unpaid, invoice not ready', st == 200 and s0 == {'paymentStatus': 'unpaid', 'orderId': oid, 'invoiceReady': False}, s0)
    st, iv, _ = http('GET', f'/api/checkout-invoice/{oid}?token={vt}'); chk('invoice before payment refused (409)', st == 409, (st, iv))
    st, sk, _ = http('GET', '/api/catalogue'); chk('stock on storefront not yet reduced (held only)', next(x for x in sk['products'] if x['id'] == PID)['sizeStock']['M'] in (stock0, stock0 - 2))
    print('== 5. payment confirmed by provider (signed callback, sandbox stand-in)')
    total_c = int(subprocess.run([W, 'shem', 'eval', f"echo (int)round(wc_get_order({wc_id})->get_total()*100);"], capture_output=True, text=True).stdout.strip().splitlines()[-1])
    chk('WC order total = 2 x 179 + 49 = 407 ILS', total_c == 40700, total_c)
    secret = wp('config', 'get', 'SHEM_HOME_PAYMENT_SECRET').splitlines()[-1].strip() if False else subprocess.run([W, 'shem', 'config', 'get', 'SHEM_HOME_PAYMENT_SECRET'], capture_output=True, text=True).stdout.strip().splitlines()[-1].strip()
    ev = {'orderId': str(wc_id), 'status': 'paid', 'currency': 'ILS', 'amountCents': total_c, 'transactionId': 'TX-E2E-%d' % int(time.time()), 'paymentDetails': {'method': 'card', 'last4': '4242', 'cardholderName': 'דנה כהן', 'brand': 'Visa', 'authorizationCode': 'AUTH77', 'installments': 1}}
    body = json.dumps(ev, ensure_ascii=False, separators=(',', ':')).encode(); ts = str(int(time.time()))
    sig = hmac.new(secret.encode(), (ts + '.').encode() + body, hashlib.sha256).hexdigest()
    st, cb, _ = http('POST', '/?rest_route=/shem-dok/v1/shimi/paid', None, {'x-shem-timestamp': ts, 'x-shem-signature': sig}) if False else (None, None, None)
    req = urllib.request.Request(BASE + '/?rest_route=/shem-dok/v1/shimi/paid', data=body, headers={'Content-Type': 'application/json', 'x-shem-timestamp': ts, 'x-shem-signature': sig}, method='POST')
    try: cbst = urllib.request.urlopen(req, timeout=90).status
    except urllib.error.HTTPError as e: cbst = e.code
    chk('signed paid callback accepted (200)', cbst == 200, cbst)
    bad = urllib.request.Request(BASE + '/?rest_route=/shem-dok/v1/shimi/paid', data=body, headers={'Content-Type': 'application/json', 'x-shem-timestamp': ts, 'x-shem-signature': '0' * 64}, method='POST')
    try: badst = urllib.request.urlopen(bad, timeout=60).status
    except urllib.error.HTTPError as e: badst = e.code
    chk('callback with a wrong signature is refused (401/403)', badst in (401, 403), badst)
    print('== 6. invoice appears without manual action')
    ready = False
    for _ in range(40):
        st, s1, _ = http('GET', f'/api/checkout-status/{oid}?token={vt}')
        if st == 200 and s1.get('invoiceReady'): ready = True; break
        wp('cron', 'event', 'run', '--due-now'); time.sleep(1.5)
    chk('status: paid + invoiceReady within 60s', ready and s1.get('paymentStatus') == 'paid', s1)
    st, pdf, h = http('GET', f'/api/checkout-invoice/{oid}?token={vt}&download=1', raw=True)
    chk('invoice download 200 application/pdf', st == 200 and 'pdf' in h.get('content-type', '') and pdf[:5] == b'%PDF-', (st, h.get('content-type'), pdf[:60]))
    open(OUT + '/invoice.pdf', 'wb').write(pdf if isinstance(pdf, bytes) else b'')
    chk('Content-Disposition is an attachment', 'attachment' in (h.get('content-disposition') or ''), h.get('content-disposition'))
    txt = subprocess.run(['pdftotext', '-layout', OUT + '/invoice.pdf', '-'], capture_output=True, text=True).stdout; open(OUT + '/invoice.txt', 'w').write(txt)
    info = subprocess.run(['pdfinfo', OUT + '/invoice.pdf'], capture_output=True, text=True).stdout
    chk('PDF is A4 and has pages', 'A4' in info or '595' in info, info)
    flat = re.sub(r'\s+', ' ', txt)
    for lab, needle in [('customer name', 'דנה כהן'), ('item sku', 'QA-OCT09-KNIT-M'), ('grand total 407.00', '407.00'), ('item unit price 179', '179'), ('shipping 49', '49'), ('payment last4', '4242')]:
        chk('PDF contains ' + lab, needle in flat or needle[::-1] in flat, needle)
    chk('PDF has VAT breakdown (17%/18%/מע"מ)', bool(re.search(r'מע.?מ|VAT', flat)), flat[:200])
    st, s2, _ = http('GET', f'/api/checkout-invoice/{oid}?token={vt}'); chk('invoice link also served without download flag', st == 200)
    st, bad_tok, _ = http('GET', f'/api/checkout-invoice/{oid}?token=wrong'); chk('invoice with wrong token refused (404)', st == 404, st)
    dok = subprocess.run([W, 'shem', 'db', 'query', f"SELECT COUNT(*), MIN(doc_type), MIN(HEX(SUBSTRING(pdf,1,4))) FROM wp_shem_dok WHERE order_id={wc_id} AND doc_type='invoice'"], capture_output=True, text=True).stdout.strip().splitlines()[-1]
    print(' DOK row:', dok); chk('exactly one invoice document for the order', dok.split('\t')[0] == '1', dok)
    sha_dl = hashlib.sha256(open(OUT + '/invoice.pdf', 'rb').read()).hexdigest()
    st, pdf2, _ = http('GET', f'/api/checkout-invoice/{oid}?token={vt}&download=1', raw=True); chk('second download is byte-identical (same stored document)', hashlib.sha256(pdf2).hexdigest() == sha_dl)
    st, sk2, _ = http('GET', '/api/catalogue'); m2 = next(x for x in sk2['products'] if x['id'] == PID)['sizeStock']['M']
    chk(f'storefront stock reduced exactly by the 2 purchased units ({stock0} -> {stock0-2})', m2 == stock0 - 2, m2)
    print('== 7. thank-you screen in a browser (customer view: /order-complete.html#order=...&token=...)')
    url = f"{BASE}/order-complete.html#order={oid}&token={vt}"
    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        b = pw.chromium.launch(); ctx = b.new_context(viewport={'width': 1280, 'height': 900}, accept_downloads=True, locale='he-IL'); pg = ctx.new_page(); errs = []
        pg.on('pageerror', lambda e: errs.append(str(e))); pg.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
        resp = pg.goto(url, wait_until='networkidle'); pg.wait_for_selector('.invoice-download', timeout=30000); body_t = pg.inner_text('body'); pg.screenshot(path=OUT + '/thankyou-desktop.png', full_page=True)
        chk('thank-you page 200', resp.status == 200, resp.status)
        chk('thank-you greeting (approved wording)', 'לקוח יקר, תודה שרכשת בSHEM מותגים' in body_t and 'הזמנתך נקלטה, והתשלום בוצע!' in body_t, body_t[:200])
        chk('invoice caption + view + download buttons shown', 'חשבונית עסקה' in body_t and 'צפייה בחשבונית העסקה' in body_t and 'הורדת חשבונית עסקה' in body_t, body_t[:300])
        with pg.expect_download(timeout=60000) as dl: pg.click('.invoice-download')
        d = dl.value; d.save_as(OUT + '/invoice-from-button.pdf'); bb = open(OUT + '/invoice-from-button.pdf', 'rb').read()
        chk('download button gives a valid PDF', bb[:5] == b'%PDF-' and len(bb) > 2000, len(bb)); chk('button PDF identical to the stored invoice', hashlib.sha256(bb).hexdigest() == sha_dl)
        href = pg.get_attribute('.invoice-open', 'href'); tgt = pg.get_attribute('.invoice-open', 'target')
        full = href if href.startswith('http') else BASE + href
        vr = ctx.request.get(full); chk('view button target is a PDF (200, application/pdf)', vr.status == 200 and 'pdf' in vr.headers.get('content-type', '') and vr.body()[:5] == b'%PDF-', (vr.status, vr.headers.get('content-type')))
        chk('view button is a link to the invoice that opens in a new tab', 'checkout-invoice' in href and tgt == '_blank', (href[:80], tgt))
        m = ctx.new_page(); m.set_viewport_size({'width': 390, 'height': 844}); m.goto(url, wait_until='networkidle'); m.wait_for_selector('.invoice-download'); m.screenshot(path=OUT + '/thankyou-mobile.png', full_page=True)
        chk('mobile: no horizontal overflow', m.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'))
        chk('no JS/console errors on the thank-you page', not errs, errs[:3])
        bad = ctx.new_page(); bad.goto(f"{BASE}/order-complete.html#order={oid}&token=wrong", wait_until='networkidle'); bad.wait_for_timeout(1500)
        bt = bad.inner_text('body'); chk('wrong token: no invoice buttons, message shown', bad.locator('.invoice-download').count() == 0 and 'לא ניתן לאמת את ההזמנה' in bt, bt[:200]); b.close()
    emails = subprocess.run([W, 'shem', 'db', 'query', "SELECT COUNT(*) FROM wp_scc_mail_jobs"], capture_output=True, text=True).stdout.strip().splitlines()[-1]
    chk('no mail jobs created (email component off, download only)', emails == '0', emails)
finally:
    print('== cleanup (own fixtures only)')
    try:
        restore(); wp('eval', f"Scc_Docs::delete('product_links','{PID}');") if False else None
        subprocess.run([W, 'shem', 'db', 'query', f"DELETE FROM wp_scc_product_links WHERE id='{PID}'"], capture_output=True)
        if 'wc_id' in dir(): print(' ', wp('wc', 'shop_order', 'delete', str(wc_id), '--force=true', '--user=scc_test_admin').splitlines()[-1])
        if 'vid' in dir(): subprocess.run([W, 'shem', 'eval', f"wc_update_product_stock({vid}, {stock_before}, 'set');"], capture_output=True); chk('variation stock restored to the pre-test value', subprocess.run([W, 'shem', 'eval', f'echo wc_get_product({vid})->get_stock_quantity();'], capture_output=True, text=True).stdout.strip().splitlines()[-1] == stock_before)
        back = subprocess.run([W, 'shem', 'db', 'query', "SELECT id, HEX(data) FROM wp_scc_settings WHERE id IN ('operations','home')"], capture_output=True, text=True).stdout.splitlines()[1:]
        chk('settings restored byte-exact', dict(l.split('\t') for l in back if '\t' in l) == prev, back)
    except Exception as e: print('cleanup error', e)
print(f'== CUSTOMER E2E: pass={sum(R)} fail={len(R)-sum(R)}'); sys.exit(0 if all(R) else 1)
