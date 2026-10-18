import sys, json
from playwright.sync_api import sync_playwright
site=sys.argv[1]; port={'shem':8081,'shimi':8082}[site]; B=f'http://localhost:{port}'
pw=dict(l.strip().split('=',1) for l in open('/home/claude/.stg-admin-pass'))[site]
SCREENS=['סקירה','מוצרים','מותגים','קולקציות','מלאי','הזמנות','לקוחות מהזמנות','כרטיסי לקוח','כרטיסי מועדון','שירות והחזרות','מועדון חברים','מבצעים','תוכן האתר','הגדרות','פרטי העסק','בקרה והשקה','יומן פעולות','סליקה וחשבוניות']
res=[]
with sync_playwright() as p:
    b=p.chromium.launch(); pg=b.new_page(viewport={'width':1280,'height':900})
    errs=[]; bad=[]; cur=['login']
    pg.on('pageerror',lambda e:errs.append((cur[0],str(e)[:120])))
    pg.on('response',lambda r: bad.append((cur[0],r.status,r.url.split('localhost:%d'%port)[-1][:70])) if '/api/admin/' in r.url and r.status>=400 else None)
    pg.goto(B+'/wp-login.php'); pg.fill('#user_login','scc_test_admin'); pg.fill('#user_pass',pw); pg.click('#wp-submit'); pg.wait_for_load_state()
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
    b.close()
for r in res: print(r)
print('page errors',errs); print('admin api >=400',bad)
