import io, json, os, sys
from PIL import Image, ImageDraw, ImageFont
import spec, api
HAVE={2,4,8,9,10}
COL={'לבן':(245,243,238),'שחור':(28,28,30),'אפור':(150,150,152),'נייבי':(30,42,74),'אבן':(190,176,150),'תכלת':(160,190,220),'אופוויט':(236,230,214),'אפור פחם':(70,72,76),"בז'":(205,185,150),'כחול כהה':(34,44,84),'חום':(92,60,40)}
def img_bytes(i, d):
    p=f'img/{i:02d}.png'
    if i in HAVE:
        im=Image.open(p).convert('RGB')
    else:
        im=Image.new('RGB',(1168,880),(217,196,166)); dr=ImageDraw.Draw(im)
        c=COL[d[2]]; dr.rounded_rectangle((334,190,834,690),60,fill=c,outline=(120,100,75),width=4)
        try: f=ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',34)
        except Exception: f=ImageFont.load_default()
        t='PHOTO PENDING'; w=dr.textlength(t,font=f); dr.text(((1168-w)/2,775),t,fill=(90,72,50),font=f)
    b=io.BytesIO(); im.save(b,'JPEG',quality=88); return b.getvalue()
DESC='פריט הדגמה להצגת החנות. התמונה, המחיר והמפרט מיועדים להמחשה ויש לעדכן אותם לפני מכירה.'
api.login()
existing={p['sku'] for p in api.call('products').json()}
out=[]
for i,d in enumerate(spec.items):
    he,en,col,kind,price,prev=d[:6]
    sku=f"{spec.PREFIX[kind]}-{i+1:02d}"
    if sku in existing: print('skip',sku); continue
    r=api.call('media','POST',raw=img_bytes(i,d),ctype='image/jpeg'); assert r.status_code==201,(i,r.status_code,r.text[:200])
    url=r.json()['imageUrl']
    sizes=spec.SIZES[kind]; n=10
    body=dict(he=he,en=en,brand='SHIMI',cat=spec.CAT[kind],price=price,previousPrice=prev or 0,stock=n*len(sizes),sizes=sizes,sizeStock={s:n for s in sizes},sku=sku,image='',imageUrl=url,color=col,active=True,demo=True,description=DESC,material='הרכב הבד יעודכן לפני מכירה.',care='הוראות הטיפול יעודכנו לפי תווית המוצר.')
    r=api.call('products','POST',body); assert r.status_code==201,(i,r.status_code,r.text[:300])
    out.append((r.json()['id'],sku,i in HAVE)); print('ok',sku,r.json()['id'][:8],'real' if i in HAVE else 'placeholder')
json.dump(out,open('created.json','w'))
