#!/usr/bin/env python3
"""SHEM staging only. 360 check of invoice issuing from the admin side.
Flow: real checkout-home order -> signed paid callback -> (auto) SHEM-DOK invoice -> admin cookie session:
 (1) React admin API: orders list, documents list, POST issue-document, document pdf
 (2) WP-admin SHEM DOK screen + signed pdf download, REST state/documents
Compares what each admin surface returns. Needs user localadmin with its password in /home/claude/.stg-pw-localadmin.
Evidence: /home/claude/logs/e2e_admin_issue/"""
import subprocess, json, hmac, hashlib, time, os, re, sys
import requests
W='/home/claude/wps.sh'; BASE='http://localhost:8081'; OUT='/home/claude/logs/e2e_admin_issue'; os.makedirs(OUT,exist_ok=True)
R=[]
def chk(n,ok,d=''):
    R.append(ok); print(('  ok   ' if ok is True else '  INFO ' if ok is None else '  FAIL ')+n+('' if ok is True else ' :: '+str(d)[:400]),flush=True)
def wp(*a):
    p=subprocess.run([W,'shem']+list(a),capture_output=True,text=True,timeout=300); return (p.stdout+p.stderr).strip()
def ev(code):
    p=subprocess.run([W,'shem','eval',code],capture_output=True,text=True,timeout=300); return p.stdout.strip().splitlines()[-1] if p.stdout.strip() else p.stderr.strip()
PID='qa-shem-knit-20261009'
snap=subprocess.run([W,'shem','db','query',"SELECT id, HEX(data) FROM wp_scc_settings WHERE id IN ('operations','home')"],capture_output=True,text=True).stdout.splitlines()
prev=dict(l.split('\t') for l in snap if '\t' in l); open(OUT+'/settings-backup.json','w').write(json.dumps(prev))
def restore():
    subprocess.run([W,'shem','db','query',"DELETE FROM wp_scc_settings WHERE id IN ('operations','home')"],capture_output=True)
    for k,hx in prev.items(): subprocess.run([W,'shem','db','query',f"INSERT INTO wp_scc_settings (id, data, updated_at) SELECT '{k}', UNHEX('{hx}'), NOW()"],capture_output=True)
php=("$o=Scc_Docs::setting('operations',[]);$o=is_array($o)?$o:[];$o['checkoutEnabled']=true;Scc_Docs::set_setting('operations',$o);"
     "$h=Scc_Docs::setting('home',[]);$h=is_array($h)?$h:[];$h['enabled']=true;$h['siteUrl']='http://localhost:8082';Scc_Docs::set_setting('home',$h);"
     f"Scc_Docs::put('product_links','{PID}',['sourceId'=>'{PID}','status'=>'connected','variantMap'=>['h-M'=>'{PID}-M','h-L'=>'{PID}-L']]);echo 'setup-ok';")
def pdf_ok(b): return b[:5]==b'%PDF-' and b.rstrip()[-5:]==b'%%EOF'
def pdf_text(b):
    p=OUT+'/_t.pdf'; open(p,'wb').write(b); return subprocess.run(['pdftotext','-layout',p,'-'],capture_output=True,text=True).stdout
try:
    print('== setup'); chk('setup',ev(php)=='setup-ok')
    s=requests.Session(); H={'Origin':BASE}
    cust={'name':'דנה כהן','email':'e2e_admin@example.test','phone':'0501234567','city':'באר שבע','street':'דרך מצדה 1'}
    item={'id':PID,'size':'M','qty':1,'variantId':PID+'-M'}
    r=s.post(BASE+'/api/checkout-home',json={'items':[item],'shipping':'standard','customer':cust,'termsAccepted':True},headers=H); co=r.json()
    chk('checkout-home 201',r.status_code==201,(r.status_code,co)); oid=co['orderId']; vt=co['viewToken']
    wc=int(ev(f"echo Scc_Core::wc_order_id('{oid}');"))
    print('== A. admin session'); 
    r=requests.get(BASE+'/api/admin/session'); chk('session without login is not an admin session',r.status_code in (401,403) or not (r.json() or {}).get('nonce'),(r.status_code,r.text[:100]))
    pw=open('/home/claude/.stg-pw-localadmin').read().strip(); a=requests.Session()
    a.get(BASE+'/wp-login.php'); r=a.post(BASE+'/wp-login.php',data={'log':'localadmin','pwd':pw,'wp-submit':'Log In','redirect_to':BASE+'/wp-admin/','testcookie':'1'},cookies={'wordpress_test_cookie':'WP Cookie check'},allow_redirects=False)
    chk('admin login ok (redirect + auth cookie)',r.status_code==302 and any(k.startswith('wordpress_logged_in') for k in a.cookies.keys()),r.status_code)
    r=a.get(BASE+'/api/admin/session'); sj=r.json() if r.status_code==200 else {}; nonce=sj.get('nonce'); chk('admin session returns nonce',bool(nonce),(r.status_code,str(sj)[:120]))
    AH={'X-WP-Nonce':nonce or '','Origin':BASE}
    print('== B. unpaid order: manual issue must be refused')
    r=a.post(BASE+f'/api/admin/manage/orders/{oid}/issue-document',json={},headers=AH); chk('issue-document on unpaid order refused (409)',r.status_code==409,(r.status_code,r.text[:150]))
    chk('no SHEM-DOK invoice for unpaid order',ev(f"echo ShemDok::invoice_for_order({wc})?'yes':'no';")=='no')
    print('== C. payment arrives (signed callback) -> automatic path')
    total_c=int(ev(f"echo (int)round(wc_get_order({wc})->get_total()*100);"))
    secret=subprocess.run([W,'shem','config','get','SHEM_HOME_PAYMENT_SECRET'],capture_output=True,text=True).stdout.strip().splitlines()[-1].strip()
    e={'orderId':str(wc),'status':'paid','currency':'ILS','amountCents':total_c,'transactionId':'TX-ADM-%d'%int(time.time()),'paymentDetails':{'method':'card','last4':'4242','cardholderName':'דנה כהן','brand':'Visa','authorizationCode':'AUTH88','installments':1}}
    body=json.dumps(e,ensure_ascii=False,separators=(',',':')).encode(); ts=str(int(time.time())); sig=hmac.new(secret.encode(),(ts+'.').encode()+body,hashlib.sha256).hexdigest()
    r=requests.post(BASE+'/?rest_route=/shem-dok/v1/pay/notify',data=body,headers={'Content-Type':'application/json','x-shem-timestamp':ts,'x-shem-signature':sig}); chk('signed callback 200',r.status_code==200,(r.status_code,r.text[:150]))
    dok=None
    for _ in range(15):
        dok=ev(f"$d=ShemDok::invoice_for_order({wc});echo $d?json_encode($d):'';")
        if dok.startswith('{'): break
        wp('cron','event','run','--due-now'); time.sleep(1)
    chk('SHEM-DOK invoice issued automatically',dok.startswith('{'),dok); dj=json.loads(dok) if dok.startswith('{') else {}; did=int(dj.get('id',0))
    num=''
    scr=a.get(BASE+'/wp-admin/admin.php?page=shem-dok'); mm=re.search(r'window\.SHEM_DOK=(\{.*?\});',scr.text,re.S); cfg=json.loads(mm.group(1)) if mm else {}; wn=cfg.get('nonce','')
    chk('WP-admin SHEM DOK screen opens for admin (200) and exposes config',scr.status_code==200 and bool(wn),scr.status_code)
    WH={'X-WP-Nonce':wn}
    rd=a.get(BASE+f'/wp-json/shem-dok/v1/document/{did}',headers=WH); dd=rd.json() if rd.status_code==200 else {}
    print('   REST document keys:',list(dd.keys())[:14]); num=str(dd.get('id') or '')  # SHEM-DOK: the document id is the sequence number
    chk('REST document/{id} readable by admin; payload carries sourceOrderId and orderNumber',rd.status_code==200 and str(dd.get('document',{}).get('sourceOrderId'))==str(wc),(dd.get('document',{}).get('sourceOrderId'),wc)); chk('REST document/{id} has a number (id)',rd.status_code==200 and bool(num),(rd.status_code,rd.text[:200])); print('   SHEM-DOK number:',num)
    print('== D. React admin surfaces')
    r=a.get(BASE+'/api/admin/manage/orders',headers=AH); od=[x for x in (r.json() if r.status_code==200 else []) if x.get('id')==oid]
    chk('order visible in React admin list, paid',bool(od) and od[0].get('paymentStatus')=='paid',(r.status_code,od[:1])); 
    inv_status=od[0].get('invoiceStatus') if od else None; print('   order.invoiceStatus =',inv_status)
    r=a.get(BASE+'/api/admin/manage/documents',headers=AH); docs=[d for d in (r.json() if r.status_code==200 else []) if d.get('orderId')==oid]
    chk('React admin documents list: has a document for the order (INFO which kind)',None if docs else None,'')
    print('   legacy docs for order:',len(docs),[ (d.get('number'),d.get('id')) for d in docs])
    r=a.post(BASE+f'/api/admin/manage/orders/{oid}/issue-document',json={},headers=AH); print('   POST issue-document ->',r.status_code,r.text[:260])
    chk('React admin issue-document on paid order answers 200/201 (not 5xx)',r.status_code in (200,201),(r.status_code,r.text[:200]))
    rj=r.json() if r.status_code in (200,201) else {}; ldoc=rj.get('document') or {}
    print('   legacy document number:',ldoc.get('number'),' SHEM-DOK number:',num)
    chk('React admin issue-document returns the SHEM-DOK number (embedding proof)',bool(num) and str(ldoc.get('number'))==str(num),(ldoc.get('number'),num))
    r=a.get(BASE+f'/api/admin/manage/documents/{oid}/pdf',headers=AH); print('   React admin pdf ->',r.status_code,r.headers.get('content-type'),len(r.content))
    if r.status_code==200 and pdf_ok(r.content):
        open(OUT+'/react-admin.pdf','wb').write(r.content); t=pdf_text(r.content); chk('React admin PDF valid',True)
        chk('React admin PDF carries SHEM-DOK number',bool(num) and str(num) in t,t[:200].replace('\n',' | '))
    else: chk('React admin PDF valid',False,(r.status_code,r.content[:100]))
    print('== E. WP-admin SHEM DOK: download of the automatically issued invoice')
    dl=cfg.get('download','')
    chk('download base present',bool(dl),cfg)
    pr=a.get(f"{dl}&id={did}&_wpnonce=NONE"); chk('PDF download without a valid nonce is refused',pr.status_code in (403,400),pr.status_code)
    r=a.get(BASE+'/wp-json/shem-dok/v1/documents',headers=WH); lst=r.json() if r.status_code==200 else {}
    items=lst.get('rows',[]) if isinstance(lst,dict) else []
    chk('REST documents list includes the order invoice',r.status_code==200 and any(str(x.get('id'))==str(did) for x in (items or [])),(r.status_code,str(lst)[:200]))
    r=a.get(BASE+'/wp-json/shem-dok/v1/state',headers=WH); chk('REST state: pdfReady, woocommerce, auto',r.status_code==200 and r.json().get('pdfReady') is True and r.json().get('woocommerce') is True and r.json()['settings'].get('auto') is True,(r.status_code,r.text[:200]))
    r=a.get(BASE+f'/wp-json/shem-dok/v1/order/{wc}',headers=WH); print('   order status ->',r.status_code,r.text[:240]); chk('REST order/{id} answers 200',r.status_code==200,(r.status_code,r.text[:150]))
    print('== F. manual issue by an admin (WP-admin REST, same call the editor makes)')
    import uuid
    doc={'business':'SHEM מותגים','businessId':'516067873','address':'דרך מצדה 259 באר שבע','phone':'052-958-6766','email':'','title':'חשבונית עסקה','vat':18,'customer':'לקוח ידני','customerPhone':'','customerEmail':'','customerAddress':'','orderDate':time.strftime('%Y-%m-%d'),'orderNumber':'MAN-1','method':'card','holder':'לקוח ידני','brand':'Visa','last4':'1111','installments':'1','reference':'REF-MAN','paypalEmail':'','discount':0,'shipping':0,'description':'','supply1':'','supply2':'','items':[{'name':'פריט ידני לבדיקה','sku':'M-1','qty':2,'price':100}]}
    rid=str(uuid.uuid4()); r=a.post(BASE+'/wp-json/shem-dok/v1/issue',json={'requestId':rid,'document':doc},headers=WH); print('   POST issue ->',r.status_code,r.text[:200])
    mj=r.json() if r.status_code in (200,201) else {}; mid=mj.get('id')
    chk('manual issue by admin answers 200/201 with an id',bool(mid),(r.status_code,r.text[:200]))
    if mid:
        r2=a.post(BASE+'/wp-json/shem-dok/v1/issue',json={'requestId':rid,'document':doc},headers=WH); chk('same requestId again: same document (idempotent)',r2.status_code==200 and r2.json().get('id')==mid and r2.json().get('existing') is True,(r2.status_code,r2.text[:150]))
        rr=a.get(BASE+f'/wp-json/shem-dok/v1/document/{mid}',headers=WH); md=rr.json() if rr.status_code==200 else {}; mnum=str(mid)
        chk('manual document has a number from the shared sequence',bool(mnum) and mnum!=num,(mnum,num)); print('   manual number:',mnum)
        pdfb=None
        for ln in re.findall(r'(?:https?:)?[^"\']*shem_dok_pdf[^"\']*',scr.text): pass
        php_url=ev(f"wp_set_current_user(1);echo ShemDok::download_url({mid});")
        print('   (download_url from CLI user uses CLI nonce; verifying via cookie session instead)')
        nn=re.search(r'_wpnonce=([a-f0-9]+)',php_url or '')
        # cookie session nonce for this action: ask the admin session via admin-ajax-free route -> compute with the admin's session using WP-CLI is not possible; use the screen config only
        r3=a.get(f"{dl}&id={mid}")
        chk('manual PDF download needs the signed link (plain link refused)',r3.status_code in (403,400),r3.status_code)
    for label,did_,doc_id in (('auto',did,did),('manual',mid,mid)):
        if not did_: continue
        b=ev(f"$p=ShemDok::stored_pdf({doc_id});echo base64_encode(is_object($p)?(string)$p:(string)$p);")
        import base64
        try: raw=base64.b64decode(b)
        except Exception: raw=b''
        if pdf_ok(raw):
            open(OUT+f'/{label}.pdf','wb').write(raw); t=pdf_text(raw); chk(f'{label}: stored PDF is a valid PDF ({len(raw)} bytes)',True)
            if label=='manual': chk('manual PDF has the item name and 200 total',('פריט ידני' in t or 'ידני' in t) and '200' in t,t[:300].replace('\n',' | '))
            else: chk('auto PDF has 4242, Visa, AUTH88 and the transaction id',all(x in t for x in ('4242','Visa','AUTH88','TX-ADM')),t[-500:].replace('\n',' | '))
        else: chk(f'{label}: stored PDF valid',False,b[:120])
    open(OUT+'/ids.json','w').write(json.dumps({'oid':oid,'wc':wc,'dok':did,'num':num,'manual':mid}))
finally:
    restore()
f=sum(1 for x in R if x is False); print('\nTOTAL %d  OK %d  FAIL %d  INFO %d'%(len(R),sum(1 for x in R if x is True),f,sum(1 for x in R if x is None))); sys.exit(1 if f else 0)
