import re,subprocess,sys
from playwright.sync_api import sync_playwright
E=subprocess.run(['python3','-I','/home/claude/shem-shop/WP-TESTS/hosting/mk_session.py'],capture_output=True,text=True).stdout.strip()
H='<!doctype html><meta charset=utf-8><iframe src="%s" sandbox="allow-scripts allow-forms allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe><script>window.got=null;window.addEventListener("message",function(e){window.got=[e.origin,e.data&&e.data.source,e.data&&e.data.status]})</script>'%E
with sync_playwright() as p:
    br=p.chromium.launch(args=['--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks']); pg=br.new_page()
    pg.route('http://localhost:8081/h.html',lambda r:r.fulfill(status=200,content_type='text/html',body=H))
    pg.goto('http://localhost:8081/h.html'); pg.wait_for_timeout(1500)
    fr=[f for f in pg.frames if f!=pg.main_frame][0]
    fr.evaluate("()=>window.parent.postMessage({source:window.PF.src,status:'completed',sid:window.PF.sid},window.PF.parent)")
    pg.wait_for_timeout(500); print('parent received (origin, source, status):',pg.evaluate('window.got'))
    br.close()
