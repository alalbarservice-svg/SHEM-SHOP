from lib import *
import concurrent.futures as cf
import http.cookiejar as cj
import urllib.parse as up, secrets, os

def cookie_login(user, pw):
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj.CookieJar()))
    http('GET', '/wp-login.php', rest=False, user=None, opener=op)
    data = up.urlencode({'log': user, 'pwd': pw, 'wp-submit': 'Log In', 'redirect_to': BASE + '/wp-admin/', 'testcookie': 1})
    http('POST', '/wp-login.php', rest=False, user=None, data=data, opener=op, headers={'Content-Type': 'application/x-www-form-urlencoded'})
    return op

def login_pw(user):
    f = '/home/claude/.stg-login-' + user
    if not os.path.exists(f):
        pw = secrets.token_urlsafe(18); open(f, 'w').write(pw); os.chmod(f, 0o600)
        wp('user', 'update', user, '--user_pass=' + pw)
    return open(f).read().strip()
from s2_bridge import ev, settle, T

def reset_rl():
    sql("DELETE FROM wp_options WHERE option_name LIKE '%shem_dok_rl_%'")
def mails():
    return json.loads(wp('option', 'get', 'shem_e2e_mails', '--format=json') or '[]')
def clear_mails():
    wp('option', 'update', 'shem_e2e_mails', '[]', '--format=json')

def paid_order(spec=None):
    spec = spec or {'items': [('T-1001', 1), ('T-1003', 2)], 'ship': 49}
    spec = dict(spec); spec['sess'] = 'sess-' + uuid.uuid4().hex[:8]
    o = make_orders([spec])[0]
    r = callback(ev(o['id'], T('TX-S3-' + uuid.uuid4().hex[:6]), o['total_cents']))
    assert r.status == 200, r.text
    assert settle(o['id']), 'no invoice'
    o['doc'] = int(sql("SELECT id FROM wp_shem_dok WHERE order_id=%d AND doc_type='invoice'" % o['id']))
    return o

def order_key(oid): return wp_eval("echo wc_get_order(%d)->get_order_key();" % oid)

def run():
    reset_rl(); clear_mails()
    section('I. עמוד תודה והורדת לקוח')
    o = paid_order(); oid, doc = o['id'], o['doc']
    key = order_key(oid)
    page = http('GET', '/?page_id=7&order-received=%d&key=%s' % (oid, key), rest=False, user=None)
    html = page.body.decode('utf-8', 'replace')
    m = re.search(r'href="([^"]*shem_dok_customer_pdf[^"]*)"', html)
    check('I1 עמוד התודה מציג כפתור הורדה', page.status == 200 and m is not None, (page.status, html[:100]))
    url = m.group(1).replace('&amp;', '&').replace('&#038;', '&') if m else ''
    path = url.replace(BASE, '')
    r = http('GET', path, rest=False, user=None)
    check('I2 הורדה עם אסימון: 200 ו-PDF', r.status == 200 and r.body[:5] == b'%PDF-', (r.status, r.body[:30]))
    hdr = {k.lower(): v for k, v in r.headers.items()} if hasattr(r, 'headers') else {}
    check('I3 כותרות אבטחה (no-store, nosniff)', 'no-store' in hdr.get('cache-control', '') and hdr.get('x-content-type-options', '') == 'nosniff', hdr)
    check('I4 hash התוכן תואם לרשומה', hashlib.sha256(r.body).hexdigest() == db_row(doc)['sha'])
    check('I5 ההורדה נרשמה בלוג ביקורת', int(sql("SELECT COUNT(*) FROM wp_shem_dok_audit WHERE doc_id=%d AND event='customer_download'" % doc)) >= 1)
    tok = re.search(r't=([^&]+)', url).group(1)
    r = http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&t=' + tok[:-3] + 'AAA', rest=False, user=None); check('I6 אסימון משובש: 403', r.status == 403, r.status)
    # scope confusion: status token must not work as download token
    st = http('GET', '/?rest_route=/shem-dok/v1/customer/status&t=' + tok, user=None)
    check('I7 אסימון הורדה אינו תקף ל-status (scope נפרד)', st.status == 403, st.status)
    parts = base64.urlsafe_b64decode(tok + '=' * (-len(tok) % 4)).decode().split('.')
    forged = base64.urlsafe_b64encode(('%d.%s.%s' % (oid + 1, parts[1], parts[2])).encode()).decode().rstrip('=')
    r = http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&t=' + forged, rest=False, user=None); check('I8 אסימון מזויף להזמנה אחרת: 403', r.status == 403, r.status)
    expired = wp_eval("echo ShemDok_Customer::token('dl',%d,time()-10);" % oid)
    r = http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&t=' + expired, rest=False, user=None); check('I9 אסימון שפג תוקפו: 403', r.status == 403, r.status)
    # page for wrong key must not leak
    bad = http('GET', '/?page_id=7&order-received=%d&key=wc_order_wrong' % oid, rest=False, user=None)
    check('I10 מפתח הזמנה שגוי: אין קישור הורדה', b'shem_dok_customer_pdf' not in bad.body)
    # unpaid order never gets a download
    up = make_orders([{'items': [('T-1001', 1)], 'sess': 'sess-' + uuid.uuid4().hex[:8]}])[0]
    tk = wp_eval("echo ShemDok_Customer::token('dl',%d);" % up['id'])
    r = http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&t=' + tk, rest=False, user=None); check('I11 הזמנה שלא שולמה: 403 גם עם אסימון חתום', r.status == 403, r.status)
    # paid but no doc yet -> 404
    nd = make_orders([{'items': [('T-1001', 1)], 'sess': 'sess-' + uuid.uuid4().hex[:8]}])[0]
    wp_eval("update_option('shem_dok_tmp_auto_off',1);")
    api('POST', 'settings', {'defaults': {}, 'auto': False, 'thankyou': True})
    callback(ev(nd['id'], T('TX-ND-' + uuid.uuid4().hex[:5]), nd['total_cents']))
    tk = wp_eval("echo ShemDok_Customer::token('dl',%d);" % nd['id'])
    r = http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&t=' + tk, rest=False, user=None); check('I12 שולם אך אין חשבונית: 404', r.status == 404, r.status)
    page = http('GET', '/?page_id=7&order-received=%d&key=%s' % (nd['id'], order_key(nd['id'])), rest=False, user=None)
    check('I13 אוטומציה כבויה: אין בלוק בעמוד התודה', b'shem-dok-thankyou' not in page.body)
    api('POST', 'settings', {'defaults': {}, 'auto': True, 'thankyou': True})
    # pending state with polling then ready
    wp_eval("ShemDok::paid(%d);" % nd['id']); time.sleep(1)
    page = http('GET', '/?page_id=7&order-received=%d&key=%s' % (nd['id'], order_key(nd['id'])), rest=False, user=None)
    check('I14 אחרי הפקה הכפתור מופיע', b'shem_dok_customer_pdf' in page.body)

    section('J. בעלות לקוח מחובר')
    reset_rl()
    uid = int(wp_eval("echo get_user_by('login','buyer1')->ID;"))
    mine = paid_order({'items': [('T-1001', 1)], 'customer': uid}); other = paid_order({'items': [('T-1001', 1)]})
    pwb = open('/home/claude/.stg-pw-buyer1').read().strip()
    ob = cookie_login('buyer1', login_pw('buyer1'))
    r = http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&order=%d' % mine['id'], rest=False, user=None, opener=ob)
    check('J1 בעל ההזמנה (מחובר) מקבל PDF', r.status == 200 and r.body[:5] == b'%PDF-', r.status)
    r = http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&order=%d' % other['id'], rest=False, user=None, opener=ob)
    check('J2 לקוח מחובר על הזמנה של אחר: 403', r.status == 403, r.status)
    r = http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&order=%d' % mine['id'], rest=False, user=None)
    check('J3 ללא התחברות ואסימון: 403', r.status == 403, r.status)

    section('K. מייל ללקוח (מצולם, לא נשלח)')
    clear_mails(); api('POST', 'settings', {'defaults': {}, 'auto': True, 'thankyou': True, 'email_customer': True,
        'email_subject': 'חשבונית עסקה מספר {number}', 'email_body': 'שלום {name}, מצורפת חשבונית להזמנה {order}.'})
    em = paid_order({'items': [('T-1001', 1)], 'email': 'cust@example.test'}); time.sleep(1)
    ml = mails(); mine_m = [x for x in ml if x['to'] == 'cust@example.test' and x['att']]
    check('K1 נשלח מייל אחד בדיוק', len(mine_m) == 1, ml)
    if mine_m:
        mm = mine_m[0]
        check('K2 נושא ותוכן עם משתנים', str(em['doc']) in mm['subject'] and 'דנה' in mm['message'], mm['subject'])
        check('K3 קובץ PDF מצורף ותקין', mm['att'] and mm['att'][0]['magic'] == '%PDF-' and mm['att'][0]['sha'] == db_row(em['doc'])['sha'], mm['att'])
    check('K4 emailed_at נקבע', db_row(em['doc'])['emailed'] not in ('', 'NULL'))
    wp_eval("ShemDok::paid(%d);" % em['id']); time.sleep(1)
    check('K5 הפעלה חוזרת לא שולחת מייל נוסף', len([x for x in mails() if x['to'] == 'cust@example.test' and x['att']]) == 1)
    bm = paid_order({'items': [('T-1001', 1)], 'email': ''}); time.sleep(1)
    al = api('GET', 'alerts').json().get('alerts', [])
    check('K6 מייל לקוח לא תקין: התראה ולא קריסה', any(a['kind'] == 'email_failed' and a['order'] == bm['id'] for a in al), al[-2:])
    api('POST', 'settings', {'defaults': {}, 'auto': True, 'thankyou': True, 'email_customer': False})
    api('POST', 'alerts/clear', {})

    section('L. זיכוי וביטול')
    cr = paid_order({'items': [('T-1002', 2)], 'ship': 30}); d = cr['doc']; gross = db_row(d)['gross']
    r = api('POST', 'document/%d/credit' % d, {'requestId': str(uuid.uuid4()), 'reason': 'בדיקה'})
    check('L1 זיכוי כבוי כברירת מחדל: 403', r.status == 403, r.status)
    api('POST', 'settings', {'defaults': {}, 'auto': True, 'thankyou': True, 'allow_credit': True})
    r = api('POST', 'document/%d/credit' % d, {'requestId': str(uuid.uuid4())}); check('L2 בלי סיבה: 400', r.status == 400, r.status)
    r = api('POST', 'document/%d/credit' % d, {'reason': 'x'}); check('L3 בלי requestId: 400', r.status == 400, r.status)
    for bad in (0, -5, 1.5, 'abc', gross + 1):
        r = api('POST', 'document/%d/credit' % d, {'requestId': str(uuid.uuid4()), 'reason': 'x', 'amountCents': bad})
        check('L4 סכום זיכוי לא חוקי נדחה (%r)' % (bad,), r.status in (400, 409), (r.status, r.text[:80]))
    rid = str(uuid.uuid4()); part = 5000
    r = api('POST', 'document/%d/credit' % d, {'requestId': rid, 'reason': 'החזר חלקי', 'amountCents': part})
    cj = r.json() if r.status == 200 else {}
    check('L5 זיכוי חלקי הצליח', r.status == 200 and cj.get('id'), r.text[:200])
    cid = cj.get('id'); crow = db_row(cid) if cid else {}
    check('L6 מסמך זיכוי מקושר לחשבונית המקורית', crow.get('doc_type') == 'credit' and crow.get('ref_id') == d and crow.get('gross') == part, crow)
    check('L7 המקור לא השתנה והוא עדיין issued', db_row(d)['status'] == 'issued' and db_row(d)['version'] == 1)
    ctext = pdf_text(bytes.fromhex(sql("SELECT HEX(pdf) FROM wp_shem_dok WHERE id=%d" % cid).strip()))
    check('L8 PDF זיכוי כולל הפניה לחשבונית, סיבה וסימן מינוס', str(d) in ctext and 'החזר חלקי' in ctext and '-' in ctext, ctext[-500:])
    r2 = api('POST', 'document/%d/credit' % d, {'requestId': rid, 'reason': 'החזר חלקי', 'amountCents': part})
    check('L9 אותו requestId: אותו זיכוי (idempotent)', r2.status == 200 and r2.json().get('id') == cid, r2.text[:150])
    r = api('POST', 'document/%d/credit' % d, {'requestId': str(uuid.uuid4()), 'reason': 'x', 'amountCents': gross - part + 1})
    check('L10 זיכוי מעל היתרה נדחה', r.status in (400, 409), (r.status, r.text[:100]))
    r = api('POST', 'document/%d/credit' % cid, {'requestId': str(uuid.uuid4()), 'reason': 'x'})
    check('L11 אי אפשר לזכות מסמך זיכוי', r.status in (400, 409, 422), (r.status, r.text[:100]))
    r = api('POST', 'document/%d/credit' % cid, {'requestId': str(uuid.uuid4()), 'reason': 'x'})
    r = api('POST', 'document/%d/credit' % d, {'requestId': str(uuid.uuid4()), 'reason': 'יתרה'})
    cj2 = r.json() if r.status == 200 else {}
    check('L12 זיכוי ללא סכום = יתרה מלאה', r.status == 200 and db_row(cj2.get('id', 0) or 0).get('gross') == gross - part, (r.status, r.text[:150]))
    check('L13 אחרי זיכוי מלא המקור מסומן cancelled', db_row(d)['status'] == 'cancelled', db_row(d)['status'])
    r = api('POST', 'document/%d/credit' % d, {'requestId': str(uuid.uuid4()), 'reason': 'x'})
    check('L14 אחרי ביטול מלא אין זיכוי נוסף', r.status in (400, 409), (r.status, r.text[:100]))
    tot = int(sql("SELECT SUM(gross_cents) FROM wp_shem_dok WHERE ref_id=%d AND doc_type='credit'" % d))
    check('L15 סכום הזיכויים = סכום החשבונית', tot == gross, (tot, gross))
    # race: two parallel full credits on a fresh invoice
    rc = paid_order({'items': [('T-1001', 1)]}); g2 = db_row(rc['doc'])['gross']
    def tryc(i): return api('POST', 'document/%d/credit' % rc['doc'], {'requestId': str(uuid.uuid4()), 'reason': 'מרוץ %d' % i})
    with cf.ThreadPoolExecutor(6) as ex: res = list(ex.map(tryc, range(6)))
    tot = int(sql("SELECT COALESCE(SUM(gross_cents),0) FROM wp_shem_dok WHERE ref_id=%d AND doc_type='credit'" % rc['doc']))
    check('L16 6 זיכויים מקבילים: הסכום לא עולה על החשבונית', tot == g2 and sum(1 for r in res if r.status == 200) == 1, (tot, g2, [r.status for r in res]))
    # refund alert (no automatic credit)
    rf = paid_order({'items': [('T-1001', 1)]})
    wp_eval("wc_create_refund(['order_id'=>%d,'amount'=>10,'reason'=>'e2e']);" % rf['id'])
    al = api('GET', 'alerts').json().get('alerts', [])
    check('L17 החזר ב-WooCommerce יוצר התראה', any(a['kind'] == 'refund' and a['order'] == rf['id'] for a in al) or any('refund' in a['kind'] for a in al), [a['kind'] for a in al])
    check('L18 אין זיכוי אוטומטי מהחזר', sql("SELECT COUNT(*) FROM wp_shem_dok WHERE ref_id=%d AND doc_type='credit'" % rf['doc']).strip() == '0')
    api('POST', 'settings', {'defaults': {}, 'auto': True, 'thankyou': True, 'allow_credit': False}); api('POST', 'alerts/clear', {})

    section('M. שלמות, ביקורת ועקביות')
    chk = paid_order({'items': [('T-1001', 1)]}); dd = chk['doc']
    sql("DROP TABLE IF EXISTS wp_e2e_pdfbak"); sql("CREATE TABLE wp_e2e_pdfbak AS SELECT id,pdf FROM wp_shem_dok WHERE id=%d" % dd)
    rc = sh('/home/claude/wps.sh shem shem-dok verify', check_rc=False)
    check('M1 verify תקין לפני שינוי', 'verified' in str(rc) and 'failed' not in str(rc), str(rc)[:200])
    sql("UPDATE wp_shem_dok SET pdf=CONCAT(UNHEX('00'),SUBSTRING(pdf,2)) WHERE id=%d" % dd)
    tk = wp_eval("echo ShemDok_Customer::token('dl',%d);" % chk['id']); reset_rl()
    r = http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&t=' + tk, rest=False, user=None)
    check('M2 PDF שהשתנה ב-DB לא מוגש ללקוח', r.status >= 400 and r.body[:5] != b'%PDF-', (r.status, r.body[:40]))
    r3 = subprocess.run('/home/claude/wps.sh shem shem-dok verify', shell=True, capture_output=True, text=True)
    check('M3 verify מזהה שינוי (קוד יציאה ≠ 0 והודעת mismatch)', r3.returncode != 0 and 'mismatch %d' % dd in (r3.stdout + r3.stderr), (r3.returncode, (r3.stdout + r3.stderr)[-200:]))
    sql("UPDATE wp_shem_dok d JOIN wp_e2e_pdfbak b ON b.id=d.id SET d.pdf=b.pdf"); sql("DROP TABLE wp_e2e_pdfbak")
    r = http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&t=' + tk, rest=False, user=None)
    check('M4 אחרי שחזור ההורדה חוזרת לעבוד', r.status == 200 and r.body[:5] == b'%PDF-', r.status)
    al = api('GET', 'alerts').json().get('alerts', [])
    # audit trail append-only: no UPDATE/DELETE paths in code
    code = open('/home/claude/dok-build/shem-dok/includes.php').read() + open('/home/claude/dok-build/shem-dok/includes-customer.php').read()
    check('M5 אין UPDATE/DELETE על טבלת הביקורת בקוד', not re.search(r'(UPDATE|DELETE FROM)\s+[^;\n]*_audit', code, re.I))
    reconcile = api('GET', 'reconcile').json()
    check('M6 reconcile מחזיר רשימה תקינה', isinstance(reconcile, dict) and 'orders' in reconcile or isinstance(reconcile, list) or 'missing' in reconcile, str(reconcile)[:200])
    # numbering invariants
    mx = int(sql("SELECT MAX(id) FROM wp_shem_dok")); nxt = int(sql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1"))
    check('M7 אין כפילויות במספור ואין פערים', sql("SELECT COUNT(*)-COUNT(DISTINCT id) FROM wp_shem_dok").strip() == '0' and nxt == mx + 1, (mx, nxt))
    dup = sql("SELECT COUNT(*) FROM (SELECT order_id FROM wp_shem_dok WHERE doc_type='invoice' AND order_id>0 AND deleted=0 GROUP BY order_id HAVING COUNT(*)>1) t")
    check('M8 לכל הזמנה חשבונית עסקה אחת', dup.strip() == '0', dup)

    section('N. מצערת בקשות')
    reset_rl(); tk = wp_eval("echo ShemDok_Customer::token('dl',%d);" % chk['id'])
    codes = [http('GET', '/wp-admin/admin-post.php?action=shem_dok_customer_pdf&t=' + tk, rest=False, user=None).status for _ in range(33)]
    check('N1 אחרי 30 בקשות בשעה: 429', codes[:30].count(200) == 30 and 429 in codes[30:], codes[28:])
    reset_rl()

if __name__ == '__main__':
    run(); summary(); save_results('/home/claude/dok-build/tests/e2e/results-s3.json')
