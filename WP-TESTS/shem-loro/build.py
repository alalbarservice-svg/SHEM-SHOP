import json
import api
SRC='/tmp/claude-0/-home-claude/e0ee4ec4-b7c1-587c-9ba4-49b726b70a07/scratchpad/lp/'
TOP=["S","M","L","XL"]; SH=["40","41","42","43","44","45"]; TR=["30","32","34","36"]
SHOE_COLORS=["שחור","שחור","טאופ","אפור כסוף","אבן","זית","שחור","חול","נייבי","בז'"]
ITEMS=[]
for i,c in enumerate(SHOE_COLORS,1):
    ITEMS.append((f"sh{i}",f"נעל {i}",f"Loafer {i}","shoes","shoes",f"LP-SHO-{i:02d}",c,SH,2))
for i,(he,c) in enumerate([("חולצה קצרה 1","שנהב"),("חולצה קצרה 2","שנהב וטאופ"),("חולצה קצרה 3","שחור"),("חולצה קצרה 4","נייבי ושנהב"),("חולצה קצרה 5","שחור")],1):
    ITEMS.append((f"tee{i}",he,f"Short-sleeve top {i}","clothing","shirts",f"LP-TEE-{i:02d}",c,TOP,3))
for i,c in enumerate(["שיבולת שועל","אינדיגו","אינדיגו","תכלת","תכלת","שנהב","לבן"],1):
    ITEMS.append((f"bd{i}",f"מכופתרת {i}",f"Button-down {i}","clothing","shirts",f"LP-BD-{i:02d}",c,TOP,3))
for i,c in enumerate(["פחם","טאופ","אפור מרווה"],1):
    ITEMS.append((f"lt{i}",f"חולצה ארוכה {i}",f"Long-sleeve top {i}","clothing","shirts",f"LP-LT-{i:02d}",c,TOP,3))
ITEMS.append(("tr1","מכנס אלגנטי","Tailored trousers","clothing","trousers","LP-TR-01","נייבי",TR,3))
DESC='פריט הדגמה בלבד. השם, המחיר והמפרט זמניים ויש לעדכן אותם לפני מכירה.'
api.login()
existing={p.get('sku') for p in api.call('products').json()}
out=[]
for key,he,en,cat,pt,sku,col,sizes,n in ITEMS:
    if sku in existing: print('skip',sku); continue
    urls=[]
    for k in range(1,n+1):
        r=api.call('media','POST',raw=open(f'{SRC}{key}-{k}.png','rb').read(),ctype='image/png'); assert r.status_code==201,(key,k,r.status_code,r.text[:200])
        urls.append(r.json()['imageUrl'])
    body=dict(he=he,en=en,brand='Loro Piana',cat=cat,productType=pt,price=1,previousPrice=0,stock=10*len(sizes),sizes=sizes,sizeStock={s:10 for s in sizes},sku=sku,image='',imageUrl=urls[0],
      images=[{'url':u,'alt':f'Loro Piana {he} {i+1}'} for i,u in enumerate(urls)],color=col,active=True,demo=True,description=DESC)
    r=api.call('products','POST',body); assert r.status_code==201,(key,r.status_code,r.text[:300])
    out.append((r.json()['id'],sku,urls,key)); print('ok',sku,r.json()['id'][:8],len(urls))
json.dump(out,open('created.json','w'),ensure_ascii=False)
