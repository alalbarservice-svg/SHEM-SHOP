"""Create 48 real (non-demo) SHIMI items on STAGING, one per real SHEM item (price 1.00 = real, owner 25.10.2026), and link each
one-to-one through the channel link (SHEM -> SHIMI). SKU, price and previous price are NOT sent as truth: the link sync overwrites them
from SHEM. Re-runnable: items whose SKU already exists are skipped, and an existing card is only linked when it has no link yet.
Images: img/NN.png (generated) when present, else a labeled placeholder (replace later with swap.py). Staging only."""
import io, json, os, sys
from PIL import Image, ImageDraw, ImageFont
import api, spec

RGB = {'שחור': (28, 28, 30), 'שנהב': (236, 230, 214), 'לבן': (245, 243, 238), 'נייבי': (30, 42, 74), 'כחול נייבי': (30, 42, 74), 'תכלת': (160, 190, 220),
       'אינדיגו': (52, 66, 110), 'טאופ': (150, 130, 112), 'חול': (205, 185, 150), 'אפור': (150, 150, 152), 'אפור מרווה': (140, 150, 135), 'פחם': (70, 72, 76),
       'שיבולת שועל': (214, 200, 172), 'נייבי ושנהב': (30, 42, 74), 'שנהב וטאופ': (236, 230, 214), "בז'": (205, 185, 150), 'זית': (96, 104, 62),
       'אבן': (190, 176, 150), 'אפור כסוף': (176, 178, 182), 'שנהב וירוק': (236, 230, 214)}
HERE = os.path.dirname(os.path.abspath(__file__))

def img_bytes(i, color):
    p = os.path.join(HERE, 'img', f'{i:02d}.png')
    if os.path.isfile(p):
        im = Image.open(p).convert('RGB'); real = True
    else:
        real = False
        im = Image.new('RGB', (1168, 880), (217, 196, 166)); dr = ImageDraw.Draw(im)
        dr.rounded_rectangle((334, 190, 834, 690), 60, fill=RGB.get(color, (150, 150, 150)), outline=(120, 100, 75), width=4)
        try: f = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', 34)
        except Exception: f = ImageFont.load_default()
        t = 'PHOTO PENDING'; w = dr.textlength(t, font=f); dr.text(((1168 - w) / 2, 775), t, fill=(90, 72, 50), font=f)
    b = io.BytesIO(); im.save(b, 'JPEG', quality=88); return b.getvalue(), real

def main():
    shem = api.Client('shem').login(); shimi = api.Client('shimi').login()
    S = {p['sku']: p for p in shem.call('products').json() if p.get('sku') and p.get('price') == 1}
    its = spec.items()
    assert len(S) == 48 and {x['shem_sku'] for x in its} == set(S), ('SHEM real set differs from spec', len(S))
    have = {p['sku']: p for p in shimi.call('products').json()}
    out = []; bad = 0
    for x in its:
        sku = x['shem_sku']; s = S[sku]; rec = {'i': x['i'], 'shem_sku': sku, 'shem_id': s['id'], 'name': x['he']}
        try:
            if sku in have: hid = have[sku]['id']; rec['created'] = False
            else:
                data, real = img_bytes(x['i'], x['color'])
                r = shimi.call('media', 'POST', raw=data, ctype='image/jpeg'); assert r.status_code == 201, ('media', r.status_code, r.text[:200])
                url = r.json()['imageUrl']; sizes = s['sizes']
                body = dict(he=x['he'], en=x['en'], brand='SHIMI', cat=s['cat'], price=s['price'], previousPrice=0, stock=10 * len(sizes), sizes=sizes,
                            sizeStock={z: 10 for z in sizes}, sku=sku, image='', imageUrl=url, color=s['color'], active=True, description='', material='', care='')
                r = shimi.call('products', 'POST', body); assert r.status_code == 201, ('create', r.status_code, r.text[:300])
                hid = r.json()['id']; rec.update(created=True, real_image=real)
            rec['shimi_id'] = hid
            u = shem.call(f"channel/products/{s['id']}/source"); assert u.status_code == 200, ('source', u.status_code, u.text[:200])
            r = shimi.call(f'channel/products/{hid}/link', 'POST', {'sourceUrl': u.json()['url'], 'variantMap': {}})
            rec['link'] = r.status_code
            if r.status_code != 200: rec['link_error'] = r.text[:300]; bad += 1
        except AssertionError as e:
            rec['error'] = str(e); bad += 1
        out.append(rec); print(rec.get('shimi_id', '-')[:8], sku, rec.get('link'), rec.get('error') or rec.get('link_error') or '')
    json.dump(out, open(os.path.join(HERE, 'created.json'), 'w'), ensure_ascii=False, indent=1)
    print('items', len(out), 'problems', bad)
    return 1 if bad else 0

if __name__ == '__main__': sys.exit(main())
