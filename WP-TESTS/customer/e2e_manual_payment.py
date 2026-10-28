#!/usr/bin/env python3
"""SHEM staging only. ONE invoice path: SHEM-DOK. A payment the admin confirmed by hand (cash/transfer/other) is never invoiced
automatically and never produces a legacy document; the admin issues it from the SHEM-DOK editor with the method's own details.
Also checks the removed legacy surface (settings/invoice, template-preview) answers 410, and every method's PDF text.
Backs up and restores the settings rows 'operations','home','invoice'. Needs localadmin password in /home/claude/.stg-pw-localadmin.
Evidence: /home/claude/logs/e2e_manual_payment/"""
import subprocess, json, hmac, hashlib, time, os, re, sys
import requests
W = '/home/claude/wps.sh'; BASE = 'http://localhost:8081'; OUT = f'/home/claude/logs/e2e_manual_payment'; os.makedirs(OUT, exist_ok=True)
R = []
def chk(n, ok, d=''):
    R.append(ok); print(('  ok   ' if ok is True else '  INFO ' if ok is None else '  FAIL ') + n + ('' if ok is True else ' :: ' + str(d)[:400]), flush=True)
def wp(*a):
    p = subprocess.run([W, 'shem'] + list(a), capture_output=True, text=True, timeout=300); return (p.stdout + p.stderr).strip()
def ev(code):
    p = subprocess.run([W, 'shem', 'eval', code], capture_output=True, text=True, timeout=300); return p.stdout.strip().splitlines()[-1] if p.stdout.strip() else p.stderr.strip()
def q(sql):
    return subprocess.run([W, 'shem', 'db', 'query', sql, '--skip-column-names'], capture_output=True, text=True).stdout.strip()
PID = 'qa-shem-knit-20261009'; KEYS = ('operations', 'home', 'invoice')
inlist = ",".join(f"'{k}'" for k in KEYS)
snap = subprocess.run([W, 'shem', 'db', 'query', f"SELECT id, HEX(data) FROM wp_scc_settings WHERE id IN ({inlist})"], capture_output=True, text=True).stdout.splitlines()
prev = dict(l.split('\t') for l in snap if '\t' in l); open(OUT + '/settings-backup.json', 'w').write(json.dumps(prev))
def restore():
    subprocess.run([W, 'shem', 'db', 'query', f"DELETE FROM wp_scc_settings WHERE id IN ({inlist})"], capture_output=True)
    for k, hx in prev.items(): subprocess.run([W, 'shem', 'db', 'query', f"INSERT INTO wp_scc_settings (id, data, updated_at) SELECT '{k}', UNHEX('{hx}'), NOW()"], capture_output=True)
php = ("$o=Scc_Docs::setting('operations',[]);$o=is_array($o)?$o:[];$o['checkoutEnabled']=true;Scc_Docs::set_setting('operations',$o);"
       "$h=Scc_Docs::setting('home',[]);$h=is_array($h)?$h:[];$h['enabled']=true;$h['siteUrl']='http://localhost:8082';Scc_Docs::set_setting('home',$h);"
       "Scc_Docs::set_setting('invoice',['legalName'=>'TEST Ltd','registrationNumber'=>'TEST-000000','address'=>'Test St 1','phone'=>'0500000000','email'=>'biz@example.test','vatMode'=>'included','vatRate'=>18,'numberPrefix'=>'DOC-','documentTitle'=>'חשבונית עסקה']);"
       f"Scc_Docs::put('product_links','{PID}',['sourceId'=>'{PID}','status'=>'connected','variantMap'=>['h-M'=>'{PID}-M','h-L'=>'{PID}-L']]);echo 'setup-ok';")
def pdf_ok(b): return b[:5] == b'%PDF-' and b.rstrip()[-5:] == b'%%EOF'
def pdf_text(b):
    p = OUT + '/_t.pdf'; open(p, 'wb').write(b); return subprocess.run(['pdftotext', '-layout', p, '-'], capture_output=True, text=True).stdout
def _stock0():
    try:
        vid = int(ev("global $wpdb; echo (int)$wpdb->get_var(\"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_scc_legacy_variant_id' AND meta_value='%s-M'\");" % PID) or 0)
        return {'vid': vid, 'qty': ev('echo wc_get_product(%d)->get_stock_quantity();' % vid) if vid else None}
    except Exception: return {}
STOCK0 = _stock0()
try:
    print('== setup (old invoice template made COMPLETE on purpose: it must be ignored)'); chk('setup', ev(php) == 'setup-ok')
    H = {'Origin': BASE}; s = requests.Session()
    cust = {'name': 'דנה כהן', 'email': 'e2e_manual_%d@example.test' % int(time.time()), 'phone': '0501234567', 'city': 'באר שבע', 'street': 'דרך מצדה 1'}
    item = {'id': PID, 'size': 'M', 'qty': 1, 'variantId': PID + '-M'}
    r = s.post(BASE + '/api/checkout-home', json={'items': [item], 'shipping': 'standard', 'customer': cust, 'termsAccepted': True}, headers=H); co = r.json()
    chk('checkout-home 201', r.status_code == 201, (r.status_code, co)); oid = co['orderId']; vt = co['viewToken']
    wc = int(ev(f"echo Scc_Core::wc_order_id('{oid}');"))
    pw = open('/home/claude/.stg-pw-localadmin').read().strip(); a = requests.Session()
    a.get(BASE + '/wp-login.php'); r = a.post(BASE + '/wp-login.php', data={'log': 'localadmin', 'pwd': pw, 'wp-submit': 'Log In', 'redirect_to': BASE + '/wp-admin/', 'testcookie': '1'}, cookies={'wordpress_test_cookie': 'WP Cookie check'}, allow_redirects=False)
    chk('admin login ok', r.status_code == 302 and any(k.startswith('wordpress_logged_in') for k in a.cookies.keys()), r.status_code)
    nonce = a.get(BASE + '/api/admin/session').json().get('nonce'); chk('admin nonce', bool(nonce)); AH = {'X-WP-Nonce': nonce or '', 'Origin': BASE}
    D = lambda route: BASE + '/wp-json/shem-dok/v1/' + route

    print('== removed legacy surface answers 410')
    r = a.put(BASE + '/api/admin/manage/settings/invoice', json={'legalName': 'X'}, headers=AH); chk('PUT settings/invoice -> 410', r.status_code == 410, (r.status_code, r.text[:120]))
    r = a.get(BASE + '/api/admin/manage/template-preview.pdf', headers=AH); chk('template-preview.pdf -> 410', r.status_code == 410, (r.status_code, r.text[:120]))
    r = a.get(BASE + '/api/admin/manage/status', headers=AH); chk('status.invoiceComplete reflects SHEM-DOK readiness', r.status_code == 200 and r.json().get('invoiceComplete') is True, (r.status_code, r.text[:200]))

    print('== manual external payment confirmed by the admin (POST orders/<id>/paid)')
    r = a.post(BASE + f'/api/admin/manage/orders/{oid}/paid', json={'reference': 'MANUAL-%d' % int(time.time())}, headers=AH)
    chk('admin confirm-payment answers 200', r.status_code == 200, (r.status_code, r.text[:300]))
    leg = int(q(f"SELECT COUNT(*) FROM wp_scc_invoice_documents WHERE id='{oid}'") or 0)
    dokn = ev(f"$d=ShemDok::invoice_for_order({wc});echo $d?json_encode($d):'none';")
    chk('no legacy document and no automatic SHEM-DOK invoice for a manual payment', leg == 0 and dokn == 'none', (leg, dokn))
    chk('order carries the manual-payment marker', ev(f"echo wc_get_order({wc})->get_meta('_shem_manual_payment');") == '1')
    r = a.get(BASE + '/api/admin/manage/orders', headers=AH); row = [o for o in (r.json() if r.status_code == 200 else []) if o.get('id') == oid]
    chk('legacy order row has no invoiceDocumentId / invoiceStatus', bool(row) and not row[0].get('invoiceDocumentId') and not row[0].get('invoiceStatus'), row[:1])
    r1 = a.post(BASE + f'/api/admin/manage/orders/{oid}/issue-document', json={}, headers=AH)
    chk('issue-document does not build a legacy document: answers the editor link', r1.status_code == 201 and (r1.json().get('editorUrl') or '').endswith(f'page=shem-dok&order={wc}') and r1.json().get('document') is None, (r1.status_code, r1.text[:300]))
    chk('still nothing issued by that click', int(q(f"SELECT COUNT(*) FROM wp_scc_invoice_documents WHERE id='{oid}'") or 0) == 0 and ev(f"echo ShemDok::invoice_for_order({wc})?'1':'0';") == '0')
    r = a.get(BASE + f'/wp-admin/admin.php?page=shem-dok&order={wc}'); chk('editor page carries the order to preload', r.status_code == 200 and re.search(r'"order"\s*:\s*%d' % wc, r.text) is not None, r.status_code)

    print('== admin issues it from the SHEM-DOK editor: cash')
    r = a.get(D(f'order/{wc}'), headers=AH); chk('prefill answers for a manual order', r.status_code == 200, (r.status_code, r.text[:200]))
    doc = r.json() if r.status_code == 200 else {}
    chk('prefill is an editable "other" draft (never auto-mapped to card/PayPal)', doc.get('method') == 'other', doc.get('method'))
    doc.update({'method': 'cash', 'receivedDate': '2026-10-28', 'reference': 'שולם במזומן בחנות'})
    rid = '%08x-0000-4000-8000-%012x' % (int(time.time()) & 0xffffffff, int(time.time() * 1000) & 0xffffffffffff)
    r = a.post(D('issue'), json={'document': doc, 'requestId': rid}, headers=AH); chk('issue (cash) answers 200/201', r.status_code in (200, 201), (r.status_code, r.text[:300]))
    res = r.json() if r.status_code in (200, 201) else {}
    r2 = a.post(D('issue'), json={'document': doc, 'requestId': rid.replace('0000-4000', '1111-4000')}, headers=AH)
    chk('second click returns the SAME document (idempotent per order)', r2.status_code in (200, 201) and r2.json().get('id') == res.get('id'), (r2.status_code, r2.text[:200]))
    pdf = a.get(res.get('downloadUrl', BASE + '/nope'))
    t = pdf_text(pdf.content) if pdf_ok(pdf.content) else ''
    import re as _re
    tn = _re.sub('[\u200e\u200f\u202a-\u202e\u2066-\u2069]', '', t)
    chk('cash PDF is valid and shows cash + received date, no card/PayPal lines', bool(t) and 'מזומן' in tn and ('2026-10-28' in tn or '28/10/2026' in tn.replace('.', '/')) and 'Visa' not in tn and 'PayPal' not in tn, tn[:400])
    r = a.get(BASE + '/api/admin/manage/documents', headers=AH); ld = [d for d in (r.json() if r.status_code == 200 else []) if d.get('orderId') == oid]
    chk('React admin documents list shows exactly one document for the order (the DOK one)', len(ld) == 1 and ld[0].get('engine') == 'shem-dok', ld)
    rp = a.get(BASE + f'/api/admin/manage/documents/{oid}/pdf', headers=AH)
    chk('admin order PDF is the stored DOK PDF', rp.status_code == 200 and pdf_ok(rp.content) and rp.content == pdf.content, rp.status_code)
    cp = s.get(BASE + f'/api/checkout-invoice/{oid}?token={vt}')
    chk('customer invoice link serves the manual DOK document', cp.status_code == 200 and cp.content[:5] == b'%PDF-' and cp.content == pdf.content, (cp.status_code, cp.headers.get('content-type'), len(cp.content)))
    leg2 = int(q(f"SELECT COUNT(*) FROM wp_scc_invoice_documents WHERE id='{oid}'") or 0)
    chk('no legacy row appeared at any point', leg2 == 0, leg2)

    print('== standalone manual documents, one per method')
    base = {k: doc[k] for k in ('business', 'businessId', 'address', 'phone', 'email', 'title', 'vat', 'customer', 'customerPhone', 'customerEmail', 'customerAddress', 'items', 'supply1', 'supply2') if k in doc}
    base.update({'orderDate': '2026-10-28', 'orderNumber': 'M1', 'discount': 0, 'shipping': 0, 'description': ''})
    base['items'] = [{'name': 'חולצה', 'sku': 'T-1', 'qty': 1, 'price': 118}]
    cases = [('transfer', {'holder': 'דנה כהן', 'bank': 'לאומי', 'branch': '800', 'account': '123456', 'transferDate': '2026-10-27', 'reference': 'ASM-77'}, ['העברה', 'לאומי', '800', '123456', 'ASM-77']),
             ('card', {'holder': 'דנה כהן', 'brand': 'Visa', 'last4': '4242', 'installments': '1', 'reference': 'REF-9'}, ['Visa', '4242', 'REF-9']),
             ('paypal', {'holder': 'דנה כהן', 'paypalEmail': 'payer@example.test', 'reference': 'PP-5'}, ['PayPal', 'payer@example.test', 'PP-5']),
             ('other', {'methodLabel': 'שיק דחוי', 'reference': 'CHK-1'}, ['שיק דחוי', 'CHK-1'])]
    for i, (m, extra, needles) in enumerate(cases):
        d = dict(base); d.update({'method': m}); d.update(extra)
        r = a.post(D('issue'), json={'document': d, 'requestId': '%08x-2222-4000-8000-%012x' % (int(time.time()) & 0xffffffff, i)}, headers=AH)
        ok = r.status_code in (200, 201); rr = r.json() if ok else {}
        pr = a.get(rr.get('downloadUrl', BASE + '/nope')); tt = pdf_text(pr.content) if pdf_ok(pr.content) else ''
        chk(f'{m}: issued and PDF shows its own details', ok and all(n in tt for n in needles), (r.status_code, r.text[:200], [n for n in needles if n not in tt]))
        if m == 'transfer': chk('transfer PDF has no card line', 'Visa' not in tt and '4242' not in tt, tt[:200])
    bad = dict(base); bad.update({'method': 'transfer', 'branch': 'abc', 'account': '12 34', 'holder': 'x'})
    r = a.post(D('issue'), json={'document': bad, 'requestId': '%08x-3333-4000-8000-%012x' % (int(time.time()) & 0xffffffff, 1)}, headers=AH); chk('invalid bank details rejected (400)', r.status_code == 400, (r.status_code, r.text[:200]))
    bad = dict(base); bad.update({'method': 'bitcoin'})
    r = a.post(D('issue'), json={'document': bad, 'requestId': '%08x-3333-4000-8000-%012x' % (int(time.time()) & 0xffffffff, 2)}, headers=AH); chk('unknown method rejected (400)', r.status_code == 400, (r.status_code, r.text[:200]))
    open(OUT + '/ids.json', 'w').write(json.dumps({'oid': oid, 'wc': wc, 'doc': res.get('id')}))
finally:
    restore()
    # restore the QA variation stock the test consumed (value read before the run)
    if STOCK0.get('vid') and STOCK0.get('qty') not in (None, ''):
        ev('wc_update_product_stock(wc_get_product(%d),%d,"set");echo "ok";' % (STOCK0['vid'], int(STOCK0['qty'])))
f = sum(1 for x in R if x is False); print('\nTOTAL %d  OK %d  FAIL %d  INFO %d' % (len(R), sum(1 for x in R if x is True), f, sum(1 for x in R if x is None))); sys.exit(1 if f else 0)
