import sys, json
from playwright.sync_api import sync_playwright
site=sys.argv[1]; port={'shem':8081,'shimi':8082}[site]; B=f'http://localhost:{port}'
pw=dict(l.strip().split('=',1) for l in open('/home/claude/.stg-admin-pass'))[site]
SCREENS=['סקירה','מוצרים','מותגים','קולקציות','מלאי','הזמנות','לקוחות מהזמנות','כרטיסי לקוח','כרטיסי מועדון','שירות והחזרות','מועדון חברים','מבצעים','תוכן האתר','הגדרות','פרטי העסק','בקרה והשקה','יומן פעולות','סליקה וחשבוניות']
res=[]
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_page(viewport={'width':1280,'height':900})
    errs=[]; bad=[]; cur=['login']; conerr=[]; adm=[]
    pg.on('pageerror',lambda e:errs.append((cur[0],str(e)[:120])))
    pg.on('response',lambda r: bad.append((cur[0],r.status,r.url.split('localhost:%d'%port)[-1][:70])) if '/api/admin/' in r.url and r.status>=400 else None)
    pg.on('console',lambda m: conerr.append((cur[0],m.text[:140])) if m.type=='error' else None)
    pg.on('request',lambda r: adm.append((r.method,'x-wp-nonce' in {k.lower() for k in r.headers})) if '/api/admin/' in r.url else None)
    pg.goto(B+'/wp-login.php'); pg.fill('#user_login','scc_test_admin'); pg.fill('#user_pass',pw); pg.click('#wp-submit'); pg.wait_for_load_state(); print('login url:',pg.url.split('localhost:%d'%port)[-1],'| login cookie:',any(c['name'].startswith('wordpress_logged_in') for c in pg.context.cookies()),'| login error:',pg.locator('#login_error').count())
    pg.goto(B+'/admin.html'); pg.wait_for_timeout(3000)
    for s in SCREENS:
        cur[0]=s
        loc=pg.get_by_role('button',name=s,exact=True)
        if loc.count()==0: loc=pg.locator(f'text="{s}"').first
        try:
            loc.first.click(timeout=4000); pg.wait_for_timeout(1500)
            txt=pg.inner_text('body'); res.append((s,'rendered',len(txt)))
        except Exception as e:
            res.append((s,'CLICK-FAIL',str(e)[:80]))
    nonce=pg.evaluate("fetch('/api/admin/session',{credentials:'include'}).then(r=>r.json()).then(j=>j.nonce)")
    rq=pg.context.request
    w0=rq.post(B+'/api/admin/manage/loyalty-adjust',data='{}',headers={'content-type':'application/json','origin':B})
    w1=rq.post(B+'/api/admin/manage/loyalty-adjust',data='{}',headers={'content-type':'application/json','origin':B,'x-wp-nonce':nonce})
    w2=rq.post(B+'/api/admin/manage/loyalty-adjust',data='{}',headers={'content-type':'application/json','origin':B,'x-wp-nonce':'0000000000'})
    cur[0]='probe'
    shim=pg.evaluate("!!document.getElementById('scc-admin-nonce')")
    pg.on('request',lambda r: adm.append((r.method,'x-wp-nonce' in {k.lower() for k in r.headers})) if '/api/admin/' in r.url else None)
    ui=pg.evaluate("fetch('/api/admin/manage/loyalty-adjust',{method:'POST',credentials:'same-origin',headers:{'content-type':'application/json'},body:'{}'}).then(r=>r.status)")
    b.close()
for r in res: print(r)
print('page errors',errs); bad=[x for x in bad if x[0]!='probe']; conerr=[x for x in conerr if x[0]!='probe']
print('admin api >=400 (screens)',bad)

w=[a for a in adm if a[0]!='GET']; g=[a for a in adm if a[0]=='GET']
print('admin requests: total',len(adm),'| writes',len(w),'writes with X-WP-Nonce',sum(1 for a in w if a[1]),'| GET with nonce',sum(1 for a in g if a[1]),'of',len(g))
print('write without nonce ->',w0.status,'| with valid nonce ->',w1.status,'(validation, not auth) | with forged nonce ->',w2.status)
print('nonce shim present in admin shell:',shim,'| in-page browser write (as the admin bundle sends it) ->',ui)
print('console errors',conerr)
ok=(not errs and not bad and not conerr and w0.status==401 and w1.status not in (401,403) and shim and ui not in (401,403) and w2.status==401 and all(a[1] for a in w) and all(r[1]=='rendered' for r in res))
print('RESULT','PASS' if ok else 'FAIL')
