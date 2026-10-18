from lib import *
import concurrent.futures as cf

def valid_doc(**over):
    d = {'business':'SHEM מותגים','businessId':'516067873','address':'דרך מצדה 259 באר שבע','phone':'052-958-6766','email':'','title':'חשבונית עסקה','vat':18,
         'customer':'דנה כהן','customerPhone':'050-1234567','customerEmail':'dana@example.test','customerAddress':'הרצל 1, תל אביב','orderDate':'2026-10-18','orderNumber':'1001',
         'method':'card','holder':'דנה כהן','brand':'Visa','last4':'4242','installments':'3','reference':'REF-123','paypalEmail':'','discount':0,'shipping':49,'description':'',
         'supply1':'שליח עד הבית 5-7 ימי עסקים','supply2':'','items':[{'name':'חולצה','sku':'T-1','qty':1,'price':199}]}
    d.update(over)
    return d

def run():
    N0 = int(sql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1"))
    section('A. התקנה וסכמה')
    check('A1 התוסף פעיל', 'shem-dok' in wp('plugin', 'list', '--status=active', '--field=name'))
    tabs = sql("SHOW TABLES LIKE 'wp_shem_dok%'").split()
    check('A2 שלוש טבלאות קיימות', set(tabs) >= {'wp_shem_dok', 'wp_shem_dok_sequence', 'wp_shem_dok_audit'}, tabs)
    cols = sql("SHOW COLUMNS FROM wp_shem_dok").split('\n')
    names = {c.split('\t')[0] for c in cols}
    check('A3 עמודות חדשות', {'doc_type','ref_id','status','gross_cents','pdf_sha256','pdf_size','emailed_at'} <= names, names)
    seq = int(sql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1"))
    check('A4 המספור לא נמוך מ-7001', seq >= 7001, seq)
    check('A5 גרסת סכמה 2', wp('option','get','shem_dok_db').strip()=='2')
    st = api('GET', 'state')
    check('A6 state מחזיר health ומנוע PDF מוכן', st.status==200 and st.json()['pdfReady'] is True and st.json()['health']['version']=='1.1.0', st.text[:200])
    check('A7 bridgeConfigured אמת', st.json()['health']['bridgeConfigured'] is True)

    section('B. הרשאות')
    wp('user','application-password','create','buyer1','e2e','--porcelain')
    # create app passwords for customer and shop manager
    pw_b = wp('user','application-password','create','buyer1','e2e-b','--porcelain').strip().splitlines()[-1]
    pw_m = wp('user','application-password','create','shopmgr','e2e-m','--porcelain').strip().splitlines()[-1]
    open('/home/claude/.stg-pw-buyer1','w').write(pw_b); open('/home/claude/.stg-pw-mgr','w').write(pw_m)
    routes = [('GET','state'),('POST','settings'),('GET','documents'),('POST','issue'),('GET','document/%d'%N0),('POST','document/%d/credit'%N0),('POST','document/%d/save'%N0),('POST','document/%d/delete'%N0),('GET','order/1'),('POST','order/1/retry'),('GET','alerts'),('POST','alerts/clear'),('GET','reconcile')]
    anon = [api(m, r, {} if m=='POST' else None, user=None).status for m, r in routes]
    check('B1 אנונימי: כל הנתיבים 401', all(s==401 for s in anon), anon)
    cust = [api(m, r, {} if m=='POST' else None, user=('buyer1', pw_b)).status for m, r in routes]
    check('B2 לקוח רשום: כל הנתיבים 403', all(s==403 for s in cust), cust)
    mgr = api('GET','state',user=('shopmgr', pw_m)).status
    check('B3 מנהל חנות (manage_woocommerce) מורשה', mgr==200, mgr)
    bad = api('GET','state',user=('localadmin','wrongwrongwrongwrongwrong')).status
    check('B4 סיסמה שגויה נדחית', bad==401, bad)
    r = http('GET','/wp-admin/admin-post.php?action=shem_dok_pdf&id=7001', rest=False, user=None)
    check('B5 הורדת מנהל ללא התחברות נדחית', r.status in (302,400,401,403) and b'%PDF' not in r.body[:8], r.status)
    r = http('GET','/wp-admin/admin-post.php?action=shem_dok_customer_pdf', rest=False, user=None)
    check('B6 הורדת לקוח ללא אסימון: 403', r.status==403, r.status)

    section('C. הפקה ידנית')
    rid = str(uuid.uuid4())
    r = api('POST','issue',{'requestId':rid,'document':valid_doc()})
    j = r.json() if r.status==200 else {}
    check('C1 הפקה ראשונה מחזירה את המספר הבא בתור', r.status==200 and j.get('id')==N0, r.text[:200])
    d = api('GET','document/%d'%N0).json()
    pdf = http('GET', d['downloadUrl'].replace(BASE,''), rest=False, user=('localadmin',None))
    # admin-post needs cookie auth, so use WP-CLI side check instead of HTTP
    row = db_row(N0)
    check('C2 שורה נשמרה עם hash וגודל', row and len(row['sha'])==64 and int(row['size'])>1000, row)
    pdfb = bytes.fromhex(sql("SELECT HEX(pdf) FROM wp_shem_dok WHERE id=%d"%N0+"").strip())
    check('C3 הקובץ הוא PDF', pdfb[:5]==b'%PDF-', pdfb[:8])
    check('C4 hash תואם לתוכן', hashlib.sha256(pdfb).hexdigest()==row['sha'])
    txt = pdf_text(pdfb)
    check('C5 עברית ופרטי עסקה ב-PDF', 'חשבונית עסקה' in txt and 'דנה כהן' in txt and '516067873' in txt and '4242' in txt and 'REF-123' in txt, txt[:300])
    check('C6 סכומים: 248.00 ₪ סה״כ', '248.00' in txt, txt[-600:])
    r2 = api('POST','issue',{'requestId':rid,'document':valid_doc()})
    check('C7 אותו requestId: אותו מסמך, existing', r2.status==200 and r2.json().get('id')==N0 and r2.json().get('existing') is True, r2.text[:200])
    check('C8 המספור לא התקדם בכפילות', int(sql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1"))==N0+1)

    bads = {
     'שיטת תשלום לא תקינה': valid_doc(method='bit'),
     'last4 לא ספרות': valid_doc(last4='ab12'),
     'last4 חמש ספרות': valid_doc(last4='12345'),
     'תשלומים 61': valid_doc(installments='61'),
     'תשלומים 0': valid_doc(installments='0'),
     'תאריך 30 בפברואר': valid_doc(orderDate='2026-02-30'),
     'מייל לקוח לא תקין': valid_doc(customerEmail='not-an-email'),
     'מחיר שלילי': valid_doc(items=[{'name':'x','sku':'','qty':1,'price':-5}]),
     'כמות אפס': valid_doc(items=[{'name':'x','sku':'','qty':0,'price':5}]),
     'הנחה מעל הסכום': valid_doc(discount=500),
     'אין פריטים': valid_doc(items=[]),
     '201 פריטים': valid_doc(items=[{'name':'x','sku':'','qty':1,'price':1}]*201),
     'אין שם לקוח': valid_doc(customer=''),
     'אין שם עסק': valid_doc(business=''),
     'lineGross לא שלם': valid_doc(items=[{'name':'x','sku':'','qty':1,'price':1,'lineGross':1.5}]),
     'taxCents גדול מהסכום': valid_doc(taxCents=999999),
     'מע״מ 101': valid_doc(vat=101),
     'שם פריט ריק': valid_doc(items=[{'name':'','sku':'','qty':1,'price':1}]),
    }
    before = int(sql("SELECT COUNT(*) FROM wp_shem_dok"))
    codes = {}
    for k, doc in bads.items():
        rr = api('POST','issue',{'requestId':str(uuid.uuid4()),'document':doc})
        codes[k] = rr.status
    check('C9 כל הקלטים הלא תקינים נדחים (400)', all(v==400 for v in codes.values()), {k:v for k,v in codes.items() if v!=400})
    check('C10 אף מסמך לא נוצר מקלט שגוי', int(sql("SELECT COUNT(*) FROM wp_shem_dok"))==before)
    check('C11 מספור ללא פער אחרי כשלונות', int(sql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1"))==N0+1)
    rr = api('POST','issue',{'document':valid_doc()}); check('C12 בלי requestId: 400', rr.status==400)
    rr = api('POST','issue',{'requestId':'1; DROP TABLE x','document':valid_doc()}); check('C13 requestId לא UUID: 400', rr.status==400)

    xss = valid_doc(customer='<script>alert(1)</script>ד"ר O\'Brien & Sons <img src="http://127.0.0.1:9/x">', description='<b>מבצע</b> <a href="http://evil.test">קישור</a>', items=[{'name':'<i>חולצה</i> & "מיוחדת"','sku':'<x>','qty':2,'price':10.5}])
    rr = api('POST','issue',{'requestId':str(uuid.uuid4()),'document':xss})
    check('C14 קלט עם HTML/XSS מתקבל אך מנוקה', rr.status==200, rr.text[:200])
    xid = rr.json()['id']
    stored = api('GET','document/%d'%xid).json()['document']
    check('C15 תגיות הוסרו מהשדות', '<script' not in json.dumps(stored) and '<img' not in json.dumps(stored) and '<a ' not in json.dumps(stored), json.dumps(stored, ensure_ascii=False)[:300])
    xb = bytes.fromhex(sql("SELECT HEX(pdf) FROM wp_shem_dok WHERE id=%d"%xid).strip())
    check('C16 ה-PDF אינו מכיל קישורים חיצוניים', b'/URI' not in xb and b'evil.test' not in xb)

    # sequential numbering
    ids = []
    for i in range(10):
        ids.append(api('POST','issue',{'requestId':str(uuid.uuid4()),'document':valid_doc(customer='לקוח %d'%i)}).json()['id'])
    check('C17 עשר הפקות רצופות: מספור רציף ללא פערים', ids==list(range(ids[0], ids[0]+10)), ids)

    # parallel distinct
    def one(i):
        return api('POST','issue',{'requestId':str(uuid.uuid4()),'document':valid_doc(customer='מקבילי %d'%i)})
    with cf.ThreadPoolExecutor(12) as ex:
        res = list(ex.map(one, range(12)))
    pids = sorted(r.json().get('id', -1) for r in res)
    check('C18 12 הפקות מקבילות: כולן הצליחו', all(r.status==200 for r in res), [r.status for r in res])
    check('C19 12 הפקות מקבילות: מספרים ייחודיים ורצופים', pids==list(range(pids[0], pids[0]+12)), pids)
    # parallel same request id
    same = str(uuid.uuid4())
    def two(_):
        return api('POST','issue',{'requestId':same,'document':valid_doc(customer='אותו בקשה')})
    with cf.ThreadPoolExecutor(8) as ex:
        res = list(ex.map(two, range(8)))
    sids = {r.json().get('id') for r in res if r.status==200}
    check('C20 8 בקשות מקבילות עם אותו requestId: מסמך אחד', len(sids)==1 and all(r.status==200 for r in res), [r.status for r in res])
    nxt = int(sql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1"))
    mx = int(sql("SELECT MAX(id) FROM wp_shem_dok"))
    check('C21 next_number = MAX(id)+1', nxt==mx+1, (nxt,mx))

    # immutability
    snap = sql("SELECT MD5(CONCAT(payload,pdf_sha256,version)) FROM wp_shem_dok WHERE id=%d"%N0+"")
    s1 = api('POST','document/%d/save'%N0,{'version':1,'document':valid_doc(customer='שינוי')}).status
    s2 = api('POST','document/%d/delete'%N0,{'version':1}).status
    check('C22 עריכה ומחיקה של מסמך שהונפק: 410', s1==410 and s2==410, (s1,s2))
    check('C23 המסמך לא השתנה', sql("SELECT MD5(CONCAT(payload,pdf_sha256,version)) FROM wp_shem_dok WHERE id=%d"%N0+"")==snap and db_row(N0)['deleted']==0)

    lst = api('GET','documents?page=1').json()
    check('C24 רשימת ארכיון: 30 לכל היותר, ללא payload', len(lst['rows'])<=30 and all('payload' not in x and 'pdf' not in x for x in lst['rows']), lst['total'])
    check('C25 סימון סוג מסמך וסטטוס ברשימה', all('doc_type' in x and 'status' in x for x in lst['rows']))
    rr = api('GET','documents?page=1;DROP')
    check('C26 פרמטר page זדוני מנוטרל', rr.status==200)

    section('C2. הגדרות')
    s = api('POST','settings',{'defaults':{'business':'SHEM מותגים','vat':18,'supply1':'טקסט'},'auto':False}).json()
    check('C27 שמירת הגדרות', s['defaults']['supply1']=='טקסט')
    rr = api('POST','settings',{'defaults':{'vat':'abc'}}); check('C28 ברירת מחדל מספרית שגויה נדחית', rr.status==400, rr.status)
    rr = api('POST','settings',{'defaults':{},'alert_email':'bad'}); check('C29 מייל התראות שגוי נדחה', rr.status==400, rr.status)
    rr = api('POST','settings',{'defaults':{'evil_key':'x'}}); check('C30 מפתח לא מוכר מתעלמים', rr.status==200 and 'evil_key' not in rr.json()['defaults'])
    api('POST','settings',{'defaults':{},'auto':False})

if __name__ == '__main__':
    run(); summary(); save_results('/home/claude/dok-build/tests/e2e/results-s1.json')
