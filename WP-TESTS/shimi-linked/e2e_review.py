"""Real-item review check on STAGING: for each of the 48 linked items (and one mixed basket) SHEM builds the quote (with and without a promo code),
SHIMI independently verifies the summary (SKUs, sizes, prices, discount, shipping, total) and both must be identical. No order, no hold, no charge.
Usage: e2e_review.py [PROMO_CODE]. The promo must exist on staging SHEM (percent)."""
import json, os, sys
import api
HERE = os.path.dirname(os.path.abspath(__file__))
CODE = sys.argv[1] if len(sys.argv) > 1 else ''
CUST = {'name': 'Test Buyer', 'email': 'qa@example.test', 'phone': '0500000000', 'city': 'Tel Aviv', 'street': 'Test 1'}
s = api.Client('shem').login(); h = api.Client('shimi').login()
C = json.load(open(os.path.join(HERE, 'created.json')))
SP = {p['id']: p for p in s.call('products').json()}; HP = {p['id']: p for p in h.call('products').json()}
def review(items, code):
    r = s.s.post(s.base + '/api/checkout-review', json={'items': items, 'shipping': 'standard', 'code': code, 'termsAccepted': True, 'customer': CUST},
                 headers={'Origin': s.base, 'X-WP-Nonce': s.nonce}); return r.status_code, r.json()
def check(items, code, label):
    st, j = review(items, code); bad = []
    if st != 200 or j.get('verified') is not True: return [f'{label}: status {st} verified {j.get("verified")} {str(j)[:160]}']
    src, home = j['source'], j['home']
    if json.dumps(src, sort_keys=True) != json.dumps(home, sort_keys=True): bad.append(f'{label}: SHEM and SHIMI summaries differ')
    sub = sum(i['priceCents'] * i['qty'] for i in src['items']) if 'priceCents' in src['items'][0] else src['subtotalCents']
    exp_disc = int(min(src['subtotalCents'], (src['subtotalCents'] * j['quote']['promotion']['discount'] + 50) // 100)) if code else 0
    if abs(src['discountCents'] - exp_disc) > 1: bad.append(f'{label}: discount {src["discountCents"]} expected {exp_disc}')
    if src['totalCents'] != src['subtotalCents'] - src['discountCents'] + src['shippingCents']: bad.append(f'{label}: total arithmetic')
    for i in src['items']:
        pid = i.get('productId') or i.get('id'); sk = (SP.get(pid) or {}).get('sku')
        if i.get('sku') and i['sku'] != sk: bad.append(f'{label}: sku {i.get("sku")} != SHEM {sk}')
    return bad
problems = []; n = 0
for c in C:
    p = SP[c['shem_id']]; items = [{'id': c['shem_id'], 'size': p['sizes'][0], 'qty': 1}]
    for code in ('', CODE) if CODE else ('',):
        problems += check(items, code, f'{c["shem_sku"]} code={code or "-"}'); n += 1
mix = [{'id': c['shem_id'], 'size': SP[c['shem_id']]['sizes'][0], 'qty': 2} for c in C[:5]]
for code in ('', CODE) if CODE else ('',):
    problems += check(mix, code, f'mixed basket code={code or "-"}'); n += 1
print('checks', n, 'problems', len(problems)); [print(' -', x) for x in problems[:10]]
sys.exit(1 if problems else 0)
