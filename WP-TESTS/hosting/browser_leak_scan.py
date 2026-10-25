import json, hmac, hashlib, time, urllib.request, re, sys, uuid
from playwright.sync_api import sync_playwright
SECRET=open('/tmp/paysecret').read().strip()
MODE=sys.argv[1]  # direct | proxy
def mk_session(oid):
    b={'protocol':'shem-home-v1','orderId':oid,'currency':'ILS','amountCents':3500,'customer':{'name':'Test'},'items':[{'productId':'13','sku':'X','qty':1,'priceCents':2990}],'shippingCents':510,'discountCents':0}
    raw=json.dumps(b).encode(); ts=str(int(time.time())); sig=hmac.new(SECRET.encode(),(ts+'.').encode()+raw,hashlib.sha256).hexdigest()
    req=urllib.request.Request('http://localhost:8082/?rest_route=/shimi-pay/v1/session',data=raw,headers={'content-type':'application/json','x-shem-timestamp':ts,'x-shem-signature':sig})
    r=urllib.request.urlopen(req,timeout=30); return json.loads(r.read())
sess=mk_session('t-'+uuid.uuid4().hex[:8])
embed=sess['embedUrl']
print('embedUrl-host-part:',re.match(r'https?://[^/]+',embed).group(0) if embed.startswith('http') else embed[:30])
if MODE=='proxy':
    # SHEM server would hand the browser a SHEM-origin URL; here we map the returned path onto the SHEM public origin
    embed=embed
HOST_HTML='''<!doctype html><meta charset=utf-8><body><h1>SHEM checkout (test host)</h1>
<iframe id=f title="תשלום מאובטח" src="%s" sandbox="allow-scripts allow-forms allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" width=500 height=400></iframe>
<script>window.addEventListener('message',function(e){document.title='msg:'+e.origin})</script>'''%embed
log=[]; bodies=[]
with sync_playwright() as p:
    br=p.chromium.launch(args=['--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks']); ctx=br.new_context(); pg=ctx.new_page()
    pg.route('http://localhost:8081/paytest-host.html',lambda r:r.fulfill(status=200,content_type='text/html',body=HOST_HTML))
    def on_resp(r):
        try:
            h=r.all_headers()
        except Exception: h={}
        ct=h.get('content-type','')
        body=''
        try:
            if re.search(r'text|json|javascript',ct): body=r.text()
        except Exception: pass
        log.append({'url':r.url,'status':r.status,'headers':h,'ct':ct}); bodies.append((r.url,body))
    pg.on('response',on_resp)
    pg.goto('http://localhost:8081/paytest-host.html'); pg.wait_for_timeout(2500)
    # exercise same-origin API call from inside the payment frame (pp not configured -> expect error JSON, proves routing)
    fr=[f for f in pg.frames if f!=pg.main_frame]
    api_result=None
    if fr:
        try: api_result=fr[0].evaluate("()=>fetch((window.PF||window.SHIMI_PAY).api+'/paypal/create',{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({sid:(window.PF||window.SHIMI_PAY).sid,token:(window.PF||window.SHIMI_PAY).token})}).then(r=>r.text().then(t=>[r.status,t.slice(0,160),location.origin]))")
        except Exception as e: api_result=['ERR',str(e)[:200]]
    frames=[f.url for f in pg.frames]
    fr_text=fr[0].inner_text('body') if fr else ''
    br.close()
print('frames:',frames)
print('frame text:',fr_text.replace('\n',' | ')[:160])
print('api from frame:',api_result)
print('requests:')
for l in log: print(' ',l['status'],l['url'][:110])
# leak scan over everything the browser received: URLs, headers, text bodies
pat=re.compile(r'8082|shimi',re.I)
hits=[]
for l in log:
    if pat.search(l['url']): hits.append(('url',l['url'][:100]))
    for k,v in l['headers'].items():
        if pat.search(k+' '+v): hits.append(('header '+k,v[:140]))
for u,b in bodies:
    if u.endswith('paytest-host.html'): continue
    for m in pat.finditer(b): hits.append(('body of '+u[:60],b[max(0,m.start()-40):m.end()+40].replace('\n',' ')))
print('LEAK HITS (8082|shimi):',len(hits))
seen=set()
for h in hits:
    if h not in seen: print('  ',h); seen.add(h)
print('frame-ancestors/CSP headers:')
for l in log:
    c=l['headers'].get('content-security-policy')
    if c: print('  ',l['url'][:70],'->',c[:300])
