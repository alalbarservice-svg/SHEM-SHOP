import json,sys
import api
SRC='/tmp/claude-0/-home-claude/e0ee4ec4-b7c1-587c-9ba4-49b726b70a07/scratchpad/amiri/'
TEE=["S","M","L","XL"]; SH=["40","41","42","43","44","45"]
# (key, he, en, cat, sku, color, sizes, image count)
ITEMS=[("tee1","חולצה קצרה לבנה 1","White short-sleeve tee 1","clothing","AMIRI-TEE-01","לבן",TEE,3),
("tee2","חולצה קצרה שחורה 2","Black short-sleeve tee 2","clothing","AMIRI-TEE-02","שחור",TEE,3),
("tee3","חולצה קצרה לבנה 3","White short-sleeve tee 3","clothing","AMIRI-TEE-03","לבן",TEE,3),
("sh1","נעל 1","Sneaker 1","shoes","AMIRI-SHO-01","שנהב",SH,2),
("sh2","נעל 2","Sneaker 2","shoes","AMIRI-SHO-02","שנהב",SH,2),
("sh3","נעל 3","Sneaker 3","shoes","AMIRI-SHO-03","שנהב וירוק",SH,2),
("sh4","נעל 4","Sneaker 4","shoes","AMIRI-SHO-04","שחור",SH,2),
("sh5","נעל 5","Sneaker 5","shoes","AMIRI-SHO-05","אפור",SH,2)]
DESC='פריט הדגמה בלבד. השם, המחיר והמפרט זמניים ויש לעדכן אותם לפני מכירה.'
api.login()
existing={p.get('sku') for p in api.call('products').json()}
out=[]
for key,he,en,cat,sku,col,sizes,n in ITEMS:
    if sku in existing: print('skip',sku); continue
    urls=[]
    for k in range(1,n+1):
        r=api.call('media','POST',raw=open(f'{SRC}{key}-{k}.png','rb').read(),ctype='image/png'); assert r.status_code==201,(key,k,r.status_code,r.text[:200])
        urls.append(r.json()['imageUrl'])
    body=dict(he=he,en=en,brand='Amiri',cat=cat,productType=('shirts' if cat=='clothing' else 'shoes'),price=1,previousPrice=0,stock=10*len(sizes),sizes=sizes,sizeStock={s:10 for s in sizes},sku=sku,image='',imageUrl=urls[0],
      images=[{'url':u,'alt':f'AMIRI {he} {i+1}'} for i,u in enumerate(urls)],color=col,active=True,demo=True,description=DESC)
    r=api.call('products','POST',body); assert r.status_code==201,(key,r.status_code,r.text[:300])
    out.append((r.json()['id'],sku,urls)); print('ok',sku,r.json()['id'][:8],len(urls))
json.dump(out,open('created.json','w'),ensure_ascii=False)
