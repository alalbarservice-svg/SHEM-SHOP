"""Staging test: change a real SHEM item's price 1 -> X and check the linked SHIMI card follows (public catalogue + stored WooCommerce price),
then restore 1 and check again. Usage: price_propagation.py [SKU] [price]"""
import json, sys, time, subprocess, requests, api
sku = sys.argv[1] if len(sys.argv) > 1 else 'LP-TEE-01'; newp = float(sys.argv[2]) if len(sys.argv) > 2 else 123
shem = api.Client('shem').login(); shimi = api.Client('shimi').login()
c = [x for x in json.load(open('created.json')) if x['shem_sku'] == sku][0]
def hp(): return {p['id']: p for p in requests.get(api.SITES['shimi'] + '/api/catalogue').json()['products']}[c['shimi_id']]
def stored():  # price stored in the SHIMI card itself (WooCommerce), not the hydrated view
    o = subprocess.run(['/home/claude/wps.sh', 'shimi', 'eval', 'global $wpdb; $r=$wpdb->get_var($wpdb->prepare("SELECT pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->postmeta} k ON k.post_id=pm.post_id AND k.meta_key=\'_sku\' WHERE pm.meta_key=\'_regular_price\' AND k.meta_value=%s",\''+sku+'\')); echo $r;'], capture_output=True, text=True).stdout.strip()
    return o
def put(price):
    d = shem.call('products/' + c['shem_id']).json(); d['price'] = price
    r = shem.call('products/' + c['shem_id'], 'PUT', d); assert r.status_code == 200, (r.status_code, r.text[:300]); return r.json()
def wait(price, secs=40):
    t0 = time.time()
    while time.time() - t0 < secs:
        if float(hp()['price']) == price and float(stored() or -1) == price: return round(time.time() - t0, 1)
        time.sleep(2)
    return None
print('before: view', hp()['price'], 'stored', stored())
put(newp); t = wait(newp); print(f'SHEM price -> {newp}: SHIMI view+stored follow in', t, 's', '| view', hp()['price'], 'stored', stored())
ok1 = t is not None
put(1); t2 = wait(1.0); print('restore 1: follow in', t2, 's', '| view', hp()['price'], 'stored', stored())
sys.exit(0 if ok1 and t2 is not None else 1)
