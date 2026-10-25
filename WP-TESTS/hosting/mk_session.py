import json,hmac,hashlib,time,urllib.request,sys
S=open('/tmp/paysecret').read().strip()
b={'protocol':'shem-home-v1','orderId':'probe-'+str(time.time_ns()),'currency':'ILS','amountCents':3500,'customer':{'name':'T'},'items':[{'productId':'1','sku':'X','qty':1,'priceCents':2990}],'shippingCents':510,'discountCents':0}
raw=json.dumps(b).encode();ts=str(int(time.time()));sig=hmac.new(S.encode(),(ts+'.').encode()+raw,hashlib.sha256).hexdigest()
r=urllib.request.urlopen(urllib.request.Request('http://localhost:8082/?rest_route=/shimi-pay/v1/session',data=raw,headers={'content-type':'application/json','x-shem-timestamp':ts,'x-shem-signature':sig}))
print(json.loads(r.read())['embedUrl'])
