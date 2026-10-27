import urllib.request, urllib.error, json, re, sys
def req(u, method="GET", data=None, hdr=None):
    r=urllib.request.Request(u, data=data, method=method, headers=hdr or {})
    try:
        x=urllib.request.urlopen(r, timeout=15); return x.status, dict(x.headers), x.read(200000).decode("utf-8","replace")
    except urllib.error.HTTPError as e: return e.code, dict(e.headers), e.read(50000).decode("utf-8","replace")
    except Exception as e: return 0, {}, repr(e)
for name,base in (("SHEM","http://localhost:8081"),("SHIMI","http://localhost:8082")):
    print("=====",name)
    s,h,b=req(base+"/")
    print("home",s,"| headers of interest:",{k:v for k,v in h.items() if k.lower() in("server","x-powered-by","x-pingback","link","x-redirect-by","via")})
    print("generator meta:",re.findall(r'<meta name="generator"[^>]*>',b)[:2])
    s,h,b=req(base+"/wp-json/"); 
    try: j=json.loads(b); print("rest root:",s,"name=",repr(j.get("name")),"url=",j.get("url"),"home=",j.get("home"),"ns=",j.get("namespaces"))
    except Exception: print("rest root:",s,b[:80])
    for p in ("/wp-json/wp/v2/users","/?author=1","/xmlrpc.php","/readme.html","/license.txt","/wp-content/debug.log","/.git/HEAD","/.env","/wp-config.php.bak","/wp-content/uploads/","/wp-json/oembed/1.0/embed?url="+base+"/"):
        s,h,b=req(base+p, "POST" if p=="/xmlrpc.php" else "GET", b'<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>' if p=="/xmlrpc.php" else None)
        print(f"{p:55s} {s}", (b[:70].replace("\n"," ") if s==200 else ""))
