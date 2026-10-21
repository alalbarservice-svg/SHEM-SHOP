import io,json,sys
from PIL import Image
import api
api.login()
created=json.load(open('/tmp/claude-0/-home-claude/e0ee4ec4-b7c1-587c-9ba4-49b726b70a07/scratchpad/basics/created.json'))
for i in map(int,sys.argv[1:]):
    pid,sku,_=created[i]
    b=io.BytesIO(); Image.open(f'img/{i:02d}.png').convert('RGB').save(b,'JPEG',quality=88)
    r=api.call('media','POST',raw=b.getvalue(),ctype='image/jpeg'); assert r.status_code==201,(i,r.status_code)
    url=r.json()['imageUrl']
    g=api.call(f'products/{pid}'); assert g.status_code==200,(sku,g.status_code,g.text[:200])
    p=g.json(); p['imageUrl']=url
    u=api.call(f'products/{pid}','PUT',p); print(sku,u.status_code,url if u.status_code==200 else u.text[:200])
