#!/usr/bin/env python3
"""SHEM staging only. What happens when the OLD invoice template (settings 'invoice') is complete and an admin clicks "issue document"
on a paid order that already has a SHEM-DOK invoice?
usage: e2e_legacy_template.py legacy   -> characterise current behaviour (a second, legacy document is expected = the defect)
       e2e_legacy_template.py dok      -> after the fix: issue-document returns the SHEM-DOK document, no legacy document, PDF is the DOK one
Backs up and restores the settings rows 'operations','home','invoice'. Needs localadmin password in /home/claude/.stg-pw-localadmin.
Evidence: /home/claude/logs/e2e_legacy_template_<mode>/"""
import subprocess, json, hmac, hashlib, time, os, re, sys
import requests
MODE = sys.argv[1] if len(sys.argv) > 1 else 'legacy'
assert MODE in ('legacy', 'dok')
W = '/home/claude/wps.sh'; BASE = 'http://localhost:8081'; OUT = f'/home/claude/logs/e2e_legacy_template_{MODE}'; os.makedirs(OUT, exist_ok=True)
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
try:
    print('== setup (old invoice template made COMPLETE, mode=%s)' % MODE); chk('setup', ev(php) == 'setup-ok')
    chk('template reads as complete', ev("echo Scc_Admin_Settings::invoice_complete((array)Scc_Admin::setting('invoice'))?'yes':'no';") == 'yes')
    H = {'Origin': BASE}; s = requests.Session()
    cust = {'name': 'דנה כהן', 'email': 'e2e_legacy_%d@example.test' % int(time.time()), 'phone': '0501234567', 'city': 'באר שבע', 'street': 'דרך מצדה 1'}
    item = {'id': PID, 'size': 'M', 'qty': 1, 'variantId': PID + '-M'}
    r = s.post(BASE + '/api/checkout-home', json={'items': [item], 'shipping': 'standard', 'customer': cust, 'termsAccepted': True}, headers=H); co = r.json()
    chk('checkout-home 201', r.status_code == 201, (r.status_code, co)); oid = co['orderId']; vt = co['viewToken']
    wc = int(ev(f"echo Scc_Core::wc_order_id('{oid}');"))
    pw = open('/home/claude/.stg-pw-localadmin').read().strip(); a = requests.Session()
    a.get(BASE + '/wp-login.php'); r = a.post(BASE + '/wp-login.php', data={'log': 'localadmin', 'pwd': pw, 'wp-submit': 'Log In', 'redirect_to': BASE + '/wp-admin/', 'testcookie': '1'}, cookies={'wordpress_test_cookie': 'WP Cookie check'}, allow_redirects=False)
    chk('admin login ok', r.status_code == 302 and any(k.startswith('wordpress_logged_in') for k in a.cookies.keys()), r.status_code)
    nonce = a.get(BASE + '/api/admin/session').json().get('nonce'); chk('admin nonce', bool(nonce)); AH = {'X-WP-Nonce': nonce or '', 'Origin': BASE}
    r = a.post(BASE + f'/api/admin/manage/orders/{oid}/issue-document', json={}, headers=AH); chk('unpaid order: issue-document refused 409', r.status_code == 409, (r.status_code, r.text[:150]))
    print('== payment arrives (signed callback) -> SHEM-DOK auto issue')
    total_c = int(ev(f"echo (int)round(wc_get_order({wc})->get_total()*100);"))
    secret = subprocess.run([W, 'shem', 'config', 'get', 'SHEM_HOME_PAYMENT_SECRET'], capture_output=True, text=True).stdout.strip().splitlines()[-1].strip()
    e = {'orderId': str(wc), 'status': 'paid', 'currency': 'ILS', 'amountCents': total_c, 'transactionId': 'TX-LEG-%d' % int(time.time()), 'paymentDetails': {'method': 'card', 'last4': '4242', 'cardholderName': 'דנה כהן', 'brand': 'Visa', 'authorizationCode': 'AUTH77', 'installments': 1}}
    body = json.dumps(e, ensure_ascii=False, separators=(',', ':')).encode(); ts = str(int(time.time())); sig = hmac.new(secret.encode(), (ts + '.').encode() + body, hashlib.sha256).hexdigest()
    r = requests.post(BASE + '/?rest_route=/shem-dok/v1/pay/notify', data=body, headers={'Content-Type': 'application/json', 'x-shem-timestamp': ts, 'x-shem-signature': sig}); chk('signed callback 200', r.status_code == 200, (r.status_code, r.text[:150]))
    dok = ''
    for _ in range(15):
        dok = ev(f"$d=ShemDok::invoice_for_order({wc});echo $d?json_encode($d):'';")
        if dok.startswith('{'): break
        wp('cron', 'event', 'run', '--due-now'); time.sleep(1)
    chk('SHEM-DOK invoice exists for the order', dok.startswith('{'), dok); did = int(json.loads(dok)['id']) if dok.startswith('{') else 0
    legacy_before = int(q(f"SELECT COUNT(*) FROM wp_scc_invoice_documents WHERE id='{oid}'") or 0)
    print('   legacy documents for the order BEFORE any click:', legacy_before, '| SHEM-DOK number:', did)
    chk('before click: no legacy document was auto-created', legacy_before == 0, legacy_before)
    r = a.get(BASE + '/api/admin/manage/documents', headers=AH); ld = [d for d in (r.json() if r.status_code == 200 else []) if d.get('orderId') == oid]
    if MODE == 'legacy': chk('before click: React admin documents list has no entry for the order (so no PDF link in the admin)', len(ld) == 0, ld[:1])
    else: chk('before click: React admin documents list already shows the SHEM-DOK invoice of the order', len(ld) == 1 and str(ld[0].get('number')) == str(did), ld[:1])
    print('== admin clicks "הפקת מסמך" (POST issue-document)')
    r1 = a.post(BASE + f'/api/admin/manage/orders/{oid}/issue-document', json={}, headers=AH); print('   click 1 ->', r1.status_code, r1.text[:200].replace('\n', ' '))
    r2 = a.post(BASE + f'/api/admin/manage/orders/{oid}/issue-document', json={}, headers=AH); print('   click 2 ->', r2.status_code, r2.text[:120].replace('\n', ' '))
    j1 = r1.json() if r1.status_code in (200, 201) else {}; num1 = str((j1.get('document') or {}).get('number', ''))
    dok_rows = int(q(f"SELECT COUNT(*) FROM wp_shem_dok WHERE order_id={wc} AND deleted=0") or 0)
    legacy_after = int(q(f"SELECT COUNT(*) FROM wp_scc_invoice_documents WHERE id='{oid}'") or 0)
    print('   after clicks: SHEM-DOK rows =', dok_rows, '| legacy rows =', legacy_after, '| number returned to the admin =', num1)
    chk('clicks answer 200/201', r1.status_code in (200, 201) and r2.status_code in (200, 201), (r1.status_code, r2.status_code))
    chk('second click is idempotent (no third document)', dok_rows + legacy_after <= 2 and legacy_after <= 1, (dok_rows, legacy_after))
    chk('SHEM-DOK still exactly one invoice for the order', dok_rows == 1, dok_rows)
    rp = a.get(BASE + f'/api/admin/manage/documents/{oid}/pdf', headers=AH); pb = rp.content
    t = pdf_text(pb) if rp.status_code == 200 and pdf_ok(pb) else ''
    if t: open(OUT + '/admin-pdf.pdf', 'wb').write(pb)
    cp = s.get(BASE + f'/api/checkout-invoice/{oid}?token={vt}'); ct = pdf_text(cp.content) if cp.status_code == 200 and pdf_ok(cp.content) else ''
    if ct: open(OUT + '/customer-pdf.pdf', 'wb').write(cp.content)
    print('   admin pdf ->', rp.status_code, len(pb), '| customer pdf ->', cp.status_code, len(cp.content))
    chk('customer receives a valid PDF', bool(ct), (cp.status_code, cp.content[:80]))
    chk('customer PDF carries the SHEM-DOK number', bool(ct) and str(did) in ct, ct[:200].replace('\n', ' | '))
    if MODE == 'legacy':
        chk('DEFECT CONFIRMED: a second (legacy) document was created for the same order', legacy_after == 1 and dok_rows == 1, (dok_rows, legacy_after))
        chk('DEFECT CONFIRMED: legacy number differs from the SHEM-DOK number', legacy_after == 1 and num1 != str(did), (num1, did))
        chk('admin PDF is NOT the SHEM-DOK document (does not carry its number as the document number)', bool(t) and ('DOC-' in t), t[:200].replace('\n', ' | '))
        chk('customer PDF does NOT carry the legacy number', bool(ct) and 'DOC-' not in ct, ct[:200].replace('\n', ' | '))
    else:
        chk('FIX: no legacy document created', legacy_after == 0, legacy_after)
        chk('FIX: admin answer carries the SHEM-DOK number', num1 == str(did), (num1, did))
        chk('FIX: admin PDF is a valid PDF', rp.status_code == 200 and pdf_ok(pb), (rp.status_code, pb[:60]))
        chk('FIX: admin PDF carries the SHEM-DOK number and payment details', bool(t) and str(did) in t and '4242' in t and 'DOC-' not in t, t[:300].replace('\n', ' | '))
        chk('FIX: admin PDF is byte-identical to the customer PDF', rp.status_code == 200 and pb == cp.content, (len(pb), len(cp.content)))
        r = a.get(BASE + '/api/admin/manage/documents', headers=AH); ld = [d for d in (r.json() if r.status_code == 200 else []) if d.get('orderId') == oid]
        chk('FIX: React admin documents list shows the order document (so the PDF link appears)', len(ld) == 1 and str(ld[0].get('number')) == str(did), ld[:1])
    open(OUT + '/ids.json', 'w').write(json.dumps({'oid': oid, 'wc': wc, 'dok': did, 'legacy_number': num1, 'dok_rows': dok_rows, 'legacy_rows': legacy_after}))
finally:
    restore()
f = sum(1 for x in R if x is False); print('\nTOTAL %d  OK %d  FAIL %d  INFO %d' % (len(R), sum(1 for x in R if x is True), f, sum(1 for x in R if x is None))); sys.exit(1 if f else 0)
