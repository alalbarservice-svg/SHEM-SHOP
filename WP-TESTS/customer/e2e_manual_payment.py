#!/usr/bin/env python3
"""SHEM staging only (manual external payment route). Originally: What happens when the OLD invoice template (settings 'invoice') is complete and an admin clicks "issue document"
on a paid order that already has a SHEM-DOK invoice?
usage: e2e_legacy_template.py legacy   -> characterise current behaviour (a second, legacy document is expected = the defect)
       e2e_legacy_template.py dok      -> after the fix: issue-document returns the SHEM-DOK document, no legacy document, PDF is the DOK one
Backs up and restores the settings rows 'operations','home','invoice'. Needs localadmin password in /home/claude/.stg-pw-localadmin.
Evidence: /home/claude/logs/e2e_legacy_template_<mode>/"""
import subprocess, json, hmac, hashlib, time, os, re, sys
import requests
MODE='manual'; INCOMPLETE = os.environ.get('TEMPLATE') == 'incomplete'
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
    print('== setup (old invoice template made COMPLETE, mode=%s)' % MODE); chk('setup', ev(php) == 'setup-ok')
    if INCOMPLETE: q("DELETE FROM wp_scc_settings WHERE id='invoice'")
    chk('template state as requested (%s)' % ('INCOMPLETE' if INCOMPLETE else 'complete'), (ev("echo Scc_Admin_Settings::invoice_complete((array)Scc_Admin::setting('invoice'))?'yes':'no';") == 'yes') != INCOMPLETE)
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

    print('== manual external payment confirmed by the admin (POST orders/<id>/paid) with a COMPLETE old template')
    r = a.post(BASE + f'/api/admin/manage/orders/{oid}/paid', json={'reference': 'MANUAL-%d' % int(time.time())}, headers=AH)
    chk('admin confirm-payment answers 200', r.status_code == 200, (r.status_code, r.text[:300]))
    leg = int(q(f"SELECT COUNT(*) FROM wp_scc_invoice_documents WHERE id='{oid}'") or 0)
    dokn = ev(f"$d=ShemDok::invoice_for_order({wc});echo $d?json_encode($d):'none';")
    print('   legacy rows:', leg, '| SHEM-DOK invoice:', dokn)
    chk('manual payment: at most one document exists for the order (never both)', leg + (1 if dokn.startswith('{') else 0) <= 1, (leg, dokn))
    r = a.get(BASE + '/api/admin/manage/documents', headers=AH); ld = [d for d in (r.json() if r.status_code == 200 else []) if d.get('orderId') == oid]
    r1 = a.post(BASE + f'/api/admin/manage/orders/{oid}/issue-document', json={}, headers=AH); r2 = a.post(BASE + f'/api/admin/manage/orders/{oid}/issue-document', json={}, headers=AH)
    leg2 = int(q(f"SELECT COUNT(*) FROM wp_scc_invoice_documents WHERE id='{oid}'") or 0); dok2 = ev(f"$d=ShemDok::invoice_for_order({wc});echo $d?'1':'0';")
    rp = a.get(BASE + f'/api/admin/manage/documents/{oid}/pdf', headers=AH)
    if INCOMPLETE:
        # manual payment keeps the legacy path: incomplete template -> the legacy 409, nothing is created
        chk('INCOMPLETE template + manual payment: no document, list empty', leg + len(ld) == 0 and dokn == 'none', (leg, ld, dokn))
        chk('issue-document answers the legacy 409 (complete the business details), twice, nothing created', r1.status_code == 409 and r2.status_code == 409 and 'פרטי העסק' in r1.text and leg2 == 0 and dok2 == '0', (r1.status_code, r2.status_code, r1.text[:200]))
        chk('admin PDF 404 (no document)', rp.status_code == 404, rp.status_code)
    else:
        chk('React admin documents list shows exactly one document for the order', len(ld) == 1, ld)
        chk('issue-document twice: 200/200 and same number', r1.status_code == 200 and r2.status_code == 200 and r1.json()['document'].get('number') == r2.json()['document'].get('number'), (r1.status_code, r2.status_code, r1.text[:200]))
        chk('still exactly one document after the clicks', leg2 + int(dok2) == 1, (leg2, dok2))
        chk('admin PDF for the order is a valid PDF', rp.status_code == 200 and pdf_ok(rp.content), (rp.status_code, rp.content[:80]))
    cp = s.get(BASE + f'/api/checkout-invoice/{oid}?token={vt}'); print('   customer invoice ->', cp.status_code, cp.headers.get('content-type'), len(cp.content))
    chk('customer invoice endpoint answers (200 PDF or a clean 4xx, never 5xx)', cp.status_code < 500, cp.status_code)
    open(OUT + '/ids.json', 'w').write(json.dumps({'oid': oid, 'wc': wc, 'legacy_rows': leg2, 'dok_rows': dok2, 'customer_status': cp.status_code}))
finally:
    restore()
    # restore the QA variation stock the test consumed (value read before the run)
    if STOCK0.get('vid') and STOCK0.get('qty') not in (None, ''):
        ev('wc_update_product_stock(wc_get_product(%d),%d,"set");echo "ok";' % (STOCK0['vid'], int(STOCK0['qty'])))
f = sum(1 for x in R if x is False); print('\nTOTAL %d  OK %d  FAIL %d  INFO %d' % (len(R), sum(1 for x in R if x is True), f, sum(1 for x in R if x is None))); sys.exit(1 if f else 0)
