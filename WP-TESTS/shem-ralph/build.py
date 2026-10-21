import json
import api
SRC='/tmp/claude-0/-home-claude/e0ee4ec4-b7c1-587c-9ba4-49b726b70a07/scratchpad/rl/'
TEE=["S","M","L","XL"]
# (key, he, en, productType, sku, color, sizes, image count)
ITEMS=[("tee1","חולצה קצרה לבנה 1","White short-sleeve tee 1","shirts","RL-TEE-01","לבן",TEE,3),
("tee2","חולצה קצרה שחורה 2","Black short-sleeve tee 2","shirts","RL-TEE-02","שחור",TEE,3),
("tee3","חולצה קצרה לבנה 3","White short-sleeve tee 3","shirts","RL-TEE-03","כחול נייבי",TEE,3),
("bd1","מכופתרת 1","Button-down 1","shirts","RL-BD-01","לבן",TEE,3),
("bd2","מכופתרת 2","Button-down 2","shirts","RL-BD-02","תכלת",TEE,3),
("bd3","מכופתרת 3","Button-down 3","shirts","RL-BD-03","אינדיגו",TEE,3),
("bd4","מכופתרת 4","Button-down 4","shirts","RL-BD-04","שחור",TEE,3),
("kn1","סריג 1","Knit 1","knitwear","RL-KN-01","שנהב",TEE,3),
("kn2","סריג 2","Knit 2","knitwear","RL-KN-02","שחור",TEE,3),
("kn3","סריג 3","Knit 3","knitwear","RL-KN-03","אפור",TEE,3),
("kn4","סריג 4","Knit 4","knitwear","RL-KN-04","נייבי",TEE,3),
("kn5","סריג 5","Knit 5","knitwear","RL-KN-05","חול",TEE,3),
("kn6","סריג 6","Knit 6","knitwear","RL-KN-06","שנהב",TEE,3),
("kn7","סריג 7","Knit 7","knitwear","RL-KN-07","נייבי",TEE,3)]
DESC='פריט הדגמה בלבד. השם, המחיר והמפרט זמניים ויש לעדכן אותם לפני מכירה.'
api.login()
existing={p.get('sku') for p in api.call('products').json()}
out=[]
for key,he,en,pt,sku,col,sizes,n in ITEMS:
    if sku in existing: print('skip',sku); continue
    urls=[]
    for k in range(1,n+1):
        r=api.call('media','POST',raw=open(f'{SRC}{key}-{k}.png','rb').read(),ctype='image/png'); assert r.status_code==201,(key,k,r.status_code,r.text[:200])
        urls.append(r.json()['imageUrl'])
    body=dict(he=he,en=en,brand='Ralph Lauren',cat='clothing',productType=pt,price=1,previousPrice=0,stock=10*len(sizes),sizes=sizes,sizeStock={s:10 for s in sizes},sku=sku,image='',imageUrl=urls[0],
      images=[{'url':u,'alt':f'Ralph Lauren {he} {i+1}'} for i,u in enumerate(urls)],color=col,active=True,demo=True,description=DESC)
    r=api.call('products','POST',body); assert r.status_code==201,(key,r.status_code,r.text[:300])
    out.append((r.json()['id'],sku,urls)); print('ok',sku,r.json()['id'][:8],len(urls))
json.dump(out,open('created.json','w'),ensure_ascii=False)
