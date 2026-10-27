import os,re,sys,json,time,subprocess
from playwright.sync_api import sync_playwright
URL=sys.argv[1]; ORDER=sys.argv[2]; EXTRA=sys.argv[3:] 
proxy=os.environ.get('HTTPS_PROXY') or os.environ.get('https_proxy')
LEAK=re.compile(r'8082|shimi',re.I)
leaks=[];reqs=[];pp=[];polls=[]
with sync_playwright() as p:
    b=p.chromium.launch(args=['--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks']+EXTRA,proxy={'server':proxy,'bypass':'localhost,127.0.0.1'} if proxy else None)
    ctx=b.new_context(ignore_https_errors=True); pg=ctx.new_page()
    pg.add_init_script("(function(){var f=window.fetch;window.__polls=[];window.fetch=function(u){try{if(String(u).indexOf('shem-pay/v1/status')>-1)window.__polls.push(Date.now())}catch(e){}return f.apply(this,arguments)}})()")
    def onresp(r):
        u=r.url; reqs.append((r.status,u[:110]))
        if 'paypal' in u: pp.append((r.status,u[:100]))
        if 'localhost' in u:
            if LEAK.search(u): leaks.append(('url',u))
            for k,v in r.headers.items():
                if LEAK.search(k+v): leaks.append(('hdr',u[:70],k,v[:80]))
            try:
                if 'text' in (r.headers.get('content-type','')) or 'json' in (r.headers.get('content-type','')) or 'javascript' in (r.headers.get('content-type','')):
                    t=r.text()
                    for m in LEAK.finditer(t): leaks.append(('body',u[:70],t[max(0,m.start()-30):m.end()+30].replace('\n',' ')))
            except Exception: pass
    pg.on('response',onresp)
    pg.goto(URL,wait_until='load'); pg.wait_for_timeout(9000)
    frs=[f.url[:100] for f in pg.frames]
    print('FRAMES:',frs)
    ifr=[f for f in pg.frames if '/pay-gw/' in f.url]
    print('PAY-FRAME FOUND:',bool(ifr))
    if ifr:
        f=ifr[0]; st=f.evaluate("(function(){var m=document.getElementById('msg');return {msg:m?m.textContent:null,paypal:typeof paypal,btn:!!document.querySelector('[data-funding-source], .paypal-buttons, iframe[name^=__zoid__]'),cid:(window.PF||{}).cid}})()")
        print('IFRAME STATE:',st)
        # postMessage listener test
        n0=pg.evaluate("window.__polls.length"); 
        pg.wait_for_timeout(500)
        t0=pg.evaluate("window.__polls[window.__polls.length-1]||0"); now=pg.evaluate("Date.now()")
        f.evaluate("window.parent.postMessage({source:'pay-frame',status:'completed'},'http://localhost:8081')")
        pg.wait_for_timeout(700)
        polls_after=pg.evaluate("window.__polls.slice()"); 
        print('POLLS:',len(polls_after),'last-gap-ms:',[polls_after[i]-polls_after[i-1] for i in range(1,len(polls_after))])
        # signed callback
        open('/tmp/e2e/cb2.php','w').write(open('/tmp/e2e/cb.php').read().replace("getenv('ORDER_ID')","'"+ORDER+"'")); subprocess.run(['docker','cp','/tmp/e2e/cb2.php','shem-staging-shimi-1:/tmp/cb2.php']); out=subprocess.run(['/home/claude/wps.sh','shimi','eval-file','/tmp/cb2.php'],capture_output=True,text=True).stdout.strip().splitlines()
        print('CALLBACK:',out[-1] if out else None)
        pg.wait_for_timeout(6000)
        print('FINAL URL:',pg.url[:90])
    print('PAYPAL REQUESTS:',pp[:6])
    print('LEAKS:',len(leaks)); [print(' ',l) for l in leaks[:8]]
    b.close()
