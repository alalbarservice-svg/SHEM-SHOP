from lib import *
import concurrent.futures as cf

RUN = uuid.uuid4().hex[:6]
def T(x): return x + '-' + RUN

def ev(oid, txn, cents, **kw):
    e = {'orderId': str(oid), 'status': 'paid', 'currency': 'ILS', 'amountCents': cents, 'transactionId': txn,
         'paymentDetails': {'method': 'card', 'last4': '4242', 'cardholderName': 'דנה כהן', 'brand': 'Visa', 'authorizationCode': 'AUTH77', 'installments': 3}}
    e.update(kw); return e

def settle(oid, tries=12):
    """wait for automation (sync or cron) to produce an invoice"""
    for _ in range(tries):
        n = sql("SELECT COUNT(*) FROM wp_shem_dok WHERE order_id=%d AND doc_type='invoice'" % oid)
        if n.strip() not in ('', '0'): return True
        wp('cron', 'event', 'run', '--due-now'); time.sleep(1)
    return False

def run():
    section('D. הגדרת אוטומציה והגנות המגשר')
    wp_eval("if(!wc_get_coupon_id_by_code('E2E10')){$c=new WC_Coupon();$c->set_code('E2E10');$c->set_discount_type('percent');$c->set_amount(10);$c->save();}")
    api('POST', 'settings', {'defaults': {}, 'auto': True, 'thankyou': True})
    o = make_orders([{'items': [('T-1001', 1)], 'ship': 49, 'sess': 'sess-' + uuid.uuid4().hex[:8]},
                     {'items': [('T-1002', 2), ('T-1003', 3)], 'ship': 0, 'coupon': 'E2E10', 'sess': 'sess-' + uuid.uuid4().hex[:8]},
                     {'items': [('T-1001', 1)], 'gw': 'bacs'},
                     {'items': [('T-1001', 1)], 'currency': 'USD'},
                     {'items': [('T-1001', 1)], 'fee': 12.5}])
    A, B, C, U, F = o
    # signature / timestamp / format negatives (no order changes expected)
    base = ev(A['id'], T('TX-NEG'), A['total_cents'])
    r = callback(base, sig_override='0' * 64); check('D1 חתימה שגויה: 401', r.status == 401, r.status)
    r = callback(base, sig_override='zz'); check('D2 חתימה לא הקסדצימלית: 401', r.status == 401, r.status)
    r = callback(base, ts=int(time.time()) - 400); check('D3 חותמת זמן ישנה: 401', r.status == 401, r.status)
    r = callback(base, ts=int(time.time()) + 400); check('D4 חותמת זמן עתידית: 401', r.status == 401, r.status)
    r = callback(base, headers_override={}); check('D5 בלי כותרות: 401', r.status == 401, r.status)
    r = callback(base, secret='x' * 40); check('D6 סוד שגוי: 401', r.status == 401, r.status)
    body = json.dumps(base).encode(); hd = sign(body)
    r = http('POST', '/shem-dok/v1/pay/notify', headers={**hd, 'Content-Type': 'application/json'}, data=body + b' ', user=None)
    check('D7 גוף ששונה אחרי החתימה: 401', r.status == 401, r.status)
    r = callback(None, raw=b'{not json'); check('D8 JSON שבור (חתום): 400', r.status == 400, r.status)
    r = callback(None, raw=json.dumps({'pad': 'x' * 20000}).encode()); check('D9 גוף גדול: 413', r.status == 413, r.status)
    r = callback({**base, 'status': 'failed'}); check('D10 status שאינו paid: 400', r.status == 400, r.status)
    r = callback({**base, 'currency': 'USD'}); check('D11 מטבע לא ILS: 400', r.status == 400, r.status)
    r = callback({**base, 'amountCents': 100.5}); check('D12 amountCents לא שלם: 400', r.status == 400, r.status)
    r = callback({**base, 'amountCents': '12000'}); check('D13 amountCents כמחרוזת: 400', r.status == 400, r.status)
    r = callback({**base, 'amountCents': 0}); check('D14 amountCents אפס: 400', r.status == 400, r.status)
    r = callback({**base, 'transactionId': ''}); check('D15 transactionId ריק: 400', r.status == 400, r.status)
    r = callback({**base, 'orderId': '999999'}); check('D16 הזמנה לא קיימת: 404', r.status == 404, r.status)
    r = callback({**base, 'amountCents': A['total_cents'] + 1}); check('D17 סכום לא תואם: 409', r.status == 409, r.status)
    r = callback(ev(C['id'], T('TX-C'), C['total_cents'])); check('D18 הזמנה ב-gateway אחר: 409/404', r.status in (404, 409), r.status)
    bad_method = ev(A['id'], T('TX-NEG2'), A['total_cents']); bad_method['paymentDetails']['method'] = 'bit'
    r = callback(bad_method); check('D19 שיטת תשלום לא מוכרת: 400', r.status == 400, r.status)
    check('D20 אף הזמנה לא סומנה כשולמה מהבקשות השליליות', all(wp_eval("echo wc_get_order(%d)->is_paid()?'1':'0';" % x['id']) == '0' for x in (A, B, C)))
    check('D21 אין חשבוניות לפני תשלום', sql("SELECT COUNT(*) FROM wp_shem_dok WHERE order_id IN (%d,%d)" % (A['id'], B['id'])).strip() == '0')

    section('E. תשלום חתום והפקה אוטומטית')
    r = callback(ev(A['id'], T('TX-A-1'), A['total_cents']))
    check('E1 callback חתום תקין: 200', r.status == 200 and r.json().get('ok') is True, r.text[:200])
    check('E2 ההזמנה סומנה כשולמה עם מזהה עסקה', wp_eval("$o=wc_get_order(%d);echo ($o->is_paid()?'1':'0').'|'.$o->get_transaction_id();" % A['id']) == '1|' + T('TX-A-1'))
    check('E3 חשבונית הופקה אוטומטית', settle(A['id']))
    docA = int(sql("SELECT id FROM wp_shem_dok WHERE order_id=%d AND doc_type='invoice'" % A['id']))
    row = db_row(docA); check('E4 סטטוס issued, סכום ברוטו תואם', row['status'] == 'issued' and row['gross'] == A['total_cents'], row)
    pdf = bytes.fromhex(sql("SELECT HEX(pdf) FROM wp_shem_dok WHERE id=%d" % docA).strip()); txt = pdf_text(pdf)
    check('E5 PDF כולל 4242, Visa, אסמכתה ואישור', '4242' in txt and 'Visa' in txt and T('TX-A-1') in txt and 'AUTH77' in txt, txt[-700:])
    check('E6 PDF כולל תשלומים (3) ושם בעל כרטיס', '3' in txt and 'דנה כהן' in txt)
    check('E7 אין מספר כרטיס מלא/CVV בשום מטא', wp_eval("$o=wc_get_order(%d);$m=json_encode($o->get_meta_data());echo preg_match('/\\\\d{13,19}/',$m)?'BAD':'ok';" % A['id']) == 'ok')
    r = callback(ev(A['id'], T('TX-A-1'), A['total_cents'])); check('E8 callback כפול עם אותה עסקה: 200 duplicate', r.status == 200 and r.json().get('duplicate') is True, r.text)
    r = callback(ev(A['id'], T('TX-A-OTHER'), A['total_cents'])); check('E9 עסקה אחרת על הזמנה ששולמה: 409', r.status == 409, r.status)
    r = callback(ev(B['id'], T('TX-A-1'), B['total_cents'])); check('E10 שימוש חוזר במזהה עסקה בהזמנה אחרת: 409', r.status == 409, r.status)
    time.sleep(2); wp('cron', 'event', 'run', '--due-now')
    check('E11 חשבונית אחת בלבד להזמנה', sql("SELECT COUNT(*) FROM wp_shem_dok WHERE order_id=%d" % A['id']).strip() == '1')

    # B: multi-line, coupon, rounding
    r = callback(ev(B['id'], T('TX-B-1'), B['total_cents'])); check('E12 הזמנה עם קופון ושורות מרובות: 200', r.status == 200, r.text)
    check('E13 חשבונית להזמנת הקופון', settle(B['id']))
    docB = int(sql("SELECT id FROM wp_shem_dok WHERE order_id=%d AND doc_type='invoice'" % B['id'])); rowB = db_row(docB)
    check('E14 סכום ברוטו = סכום ההזמנה (עיגול)', rowB['gross'] == B['total_cents'], (rowB['gross'], B['total_cents']))
    tB = pdf_text(bytes.fromhex(sql("SELECT HEX(pdf) FROM wp_shem_dok WHERE id=%d" % docB).strip()))
    check('E15 שורות מוצר ומק״ט בחשבונית', 'T-1002' in tB and 'T-1003' in tB, tB[-500:])
    check('E16 הקופון אכן הופעל בהזמנה (הנחה > 0)', wp_eval('echo wc_get_order(%d)->get_discount_total();' % B['id']) not in ('', '0'))

    section('F. כשלים, ניסיונות חוזרים והתראות')
    # unmapped gateway -> bridge rejects; simulate payment_complete directly on bacs order (unmapped) and USD / fee orders
    for lbl, x in (('gateway לא ממופה', C), ('מטבע USD', U), ('עמלה מיוחדת', F)):
        wp_eval("$o=wc_get_order(%d);$o->payment_complete('TX-F-%d-%s');" % (x['id'], x['id'], RUN))
    time.sleep(2)
    for _ in range(4): wp('cron', 'event', 'run', '--due-now'); time.sleep(1)
    for lbl, x in (('gateway לא ממופה', C), ('מטבע USD', U), ('עמלה מיוחדת', F)):
        n = sql("SELECT COUNT(*) FROM wp_shem_dok WHERE order_id=%d" % x['id']).strip()
        err = wp_eval("echo wc_get_order(%d)->get_meta('_shem_dok_error');" % x['id'])
        check('F %s: לא הופקה חשבונית שגויה' % lbl, n == '0', n)
        check('F %s: שגיאה תועדה בהזמנה' % lbl, err != '', err)
    al = api('GET', 'alerts').json()
    kinds = {a.get('kind') or a.get('type') for a in al.get('alerts', al if isinstance(al, list) else [])}
    check('F1 התראות כשל נרשמו', len(al.get('alerts', [])) >= 3 if isinstance(al, dict) else len(al) >= 3, al if len(str(al)) < 400 else str(al)[:400])
    # retry after fixing mapping via filter is not available over REST; test retry on a failed order => still fails but safe & idempotent
    r = api('POST', 'order/%d/retry' % C['id'], {}); check('F2 ניסיון חוזר ידני על הזמנה לא ממופה נדחה בבטחה', r.status in (400, 409, 422), (r.status, r.text[:150]))
    check('F3 עדיין אין חשבונית להזמנה הלא ממופה', sql("SELECT COUNT(*) FROM wp_shem_dok WHERE order_id=%d" % C['id']).strip() == '0')
    r = api('POST', 'alerts/clear', {}); check('F4 ניקוי התראות', r.status == 200)
    check('F5 אחרי ניקוי אין התראות', len(api('GET', 'alerts').json().get('alerts', [])) == 0)

    section('G. הזמנה ידנית דרך retry (תשלום שכבר אומת)')
    o2 = make_orders([{'items': [('T-1001', 1)], 'sess': 'sess-' + uuid.uuid4().hex[:8]}])[0]
    r = api('POST', 'order/%d/retry' % o2['id'], {}); check('G1 retry על הזמנה שלא שולמה נדחה', r.status in (400, 409, 422), (r.status, r.text[:150]))
    callback(ev(o2['id'], T('TX-G-1'), o2['total_cents'])); settle(o2['id'])
    r = api('POST', 'order/%d/retry' % o2['id'], {}); 
    check('G2 retry על הזמנה שכבר יש לה חשבונית: idempotent', r.status == 200 and sql("SELECT COUNT(*) FROM wp_shem_dok WHERE order_id=%d" % o2['id']).strip() == '1', (r.status, r.text[:150]))

    section('H. מקביליות במגשר')
    o3 = make_orders([{'items': [('T-1001', 1)], 'sess': 'sess-' + uuid.uuid4().hex[:8]}])[0]
    def hit(_): return callback(ev(o3['id'], T('TX-H-1'), o3['total_cents']))
    with cf.ThreadPoolExecutor(10) as ex: res = list(ex.map(hit, range(10)))
    codes = sorted(r.status for r in res)
    check('H1 10 callbacks מקבילים: כולם 200 או 503 busy', all(c in (200, 503) for c in codes), codes)
    check('H2 לפחות אחד הצליח וההזמנה שולמה פעם אחת', 200 in codes and wp_eval("echo wc_get_order(%d)->get_transaction_id();" % o3['id']) == T('TX-H-1'))
    settle(o3['id']); time.sleep(2)
    check('H3 חשבונית אחת בלבד אחרי 10 callbacks', sql("SELECT COUNT(*) FROM wp_shem_dok WHERE order_id=%d" % o3['id']).strip() == '1')
    # two different txns concurrently
    o4 = make_orders([{'items': [('T-1001', 1)], 'sess': 'sess-' + uuid.uuid4().hex[:8]}])[0]
    def hit2(i): return callback(ev(o4['id'], T('TX-H2-%d' % i), o4['total_cents']))
    with cf.ThreadPoolExecutor(6) as ex: res = list(ex.map(hit2, range(6)))
    ok = [r for r in res if r.status == 200]
    check('H4 6 עסקאות שונות במקביל: בדיוק אחת התקבלה', len(ok) == 1, sorted(r.status for r in res))

    api('POST', 'settings', {'defaults': {}, 'auto': True, 'thankyou': True})
    json.dump({'A': A, 'B': B, 'docA': docA, 'docB': docB, 'o2': o2, 'o3': o3}, open('/home/claude/dok-build/tests/e2e/state-s2.json', 'w'))

if __name__ == '__main__':
    run(); summary(); save_results('/home/claude/dok-build/tests/e2e/results-s2.json')
