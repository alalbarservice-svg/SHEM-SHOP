#!/usr/bin/env python3
"""Live two-site scenario on staging (SHEM :8081 <-> SHIMI :8082, docker hostnames http://shem and http://shimi). Never prints a secret."""
import subprocess, json, sys, time, concurrent.futures as cf, tempfile, os
WPS = '/home/claude/wps.sh'; PLUG = 'wp-content/plugins/shem-shimi-commerce/tests/channel/live/live-php.php'
URL = {'shem': 'http://localhost:8081', 'shimi': 'http://localhost:8082'}
R = []   # (section, name, ok, detail)
sec = ''
def section(s):
    global sec; sec = s; print('\n== ' + s, flush=True)
def check(name, ok, detail=''):
    R.append((sec, name, bool(ok), detail)); print(('  ok   ' if ok else '  FAIL ') + name + ('' if ok else ' :: ' + str(detail)), flush=True)
def w(site, *a):
    r = subprocess.run([WPS, site, 'eval-file', PLUG, *a], capture_output=True, text=True)
    ls = [l for l in r.stdout.splitlines() if l.startswith('@@')]
    return json.loads(ls[-1][2:]) if ls else {'_raw': r.stdout[-600:], '_err': r.stderr[-300:]}
def wp(site, *a):
    r = subprocess.run([WPS, site, *a], capture_output=True, text=True); return r.stdout + r.stderr
def sign_many(site, path, bodies):
    f = tempfile.NamedTemporaryFile('w', suffix='.txt', delete=False, dir='/tmp/claude-0/-home-claude/e0ee4ec4-b7c1-587c-9ba4-49b726b70a07/scratchpad'); f.write('\n'.join(bodies) + '\n'); f.close()
    # the file lives on the host; copy into the plugin dir that the wp container sees
    dst = '/home/claude/shem-shop/WP-PLUGIN/shem-shimi-commerce/tests/channel/live/_bodies.txt'; subprocess.run(['cp', f.name, dst]); os.unlink(f.name)
    for c in ('shem', 'shimi'):
        subprocess.run(['docker', 'cp', dst, f'shem-staging-{c}-1:/var/www/html/wp-content/plugins/shem-shimi-commerce/tests/channel/live/_bodies.txt'], capture_output=True)
        subprocess.run(['docker', 'exec', f'shem-staging-{c}-1', 'chown', 'www-data:www-data', '/var/www/html/wp-content/plugins/shem-shimi-commerce/tests/channel/live/_bodies.txt'], capture_output=True)
    return w(site, 'sign-many', path, 'wp-content/plugins/shem-shimi-commerce/tests/channel/live/_bodies.txt')
def curl(site, path, body, ts, sig, extra=None):
    cmd = ['curl', '-s', '-m', '60', '-o', '-', '-w', '\n%{http_code}', '-H', 'content-type: application/json', '-H', 'x-channel-timestamp: ' + ts, '-H', 'x-channel-signature: ' + sig, '--data-binary', body, URL[site] + path]
    o = subprocess.run(cmd, capture_output=True, text=True).stdout
    b, _, c = o.rpartition('\n'); 
    try: j = json.loads(b)
    except Exception: j = {'_raw': b[:200]}
    return int(c or 0), j
def parallel(site, path, signed):
    with cf.ThreadPoolExecutor(max_workers=len(signed)) as ex:
        futs = [ex.submit(curl, site, path, s['body'], s['ts'], s['sig']) for s in signed]; return [f.result() for f in futs]

section('0. environment')
a = w('shem', 'role'); b = w('shimi', 'role')
check('SHEM is source, peer http://shimi, secret configured', a == {'role': 'source', 'peer': 'http://shimi', 'secret_configured': True}, a)
check('SHIMI is home, peer http://shem, secret configured', b == {'role': 'home', 'peer': 'http://shem', 'secret_configured': True}, b)
w('shem', 'cleanup'); w('shimi', 'cleanup')
check('fixtures created (14 SHEM products, 1 SHIMI card)', (w('shem', 'setup-source').get('created'), w('shimi', 'setup-home').get('created')) == (14, 1))

section('1. link a test product SHEM -> SHIMI over real HTTP (HMAC)')
l = w('shimi', 'link'); card = l.get('card') or {}
check('link flow returns the card', l.get('linked') is True, l)
check('SHIMI card mirrors SKU and price from SHEM', (card.get('sku'), float(card.get('price') or 0)) == ('T-CHANNEL-LIVE-A', 100.0), card)
check('SHIMI link connected', (card.get('link') or {}).get('status') == 'connected', card.get('link'))
src = w('shem', 'show', 't-channel-live-a')
check('SHEM registered the home card', (src.get('link') or {}).get('homeId') == 't-channel-live-h', src.get('link'))
hv = w('shimi', 'hydrate', 't-channel-live-h')
check('SHIMI availability comes from SHEM (3 units)', hv.get('stock') == 3 and not hv.get('syncUnavailable'), hv)

section('2. change price / stock on SHEM -> one event -> SHIMI follows')
w('shem', 'set-price', 't-channel-live-a', '90')
ob = w('shem', 'outbox', 't-channel-live-a')
ev = [r for r in ob if r['kind'] == 'refresh']
check('exactly one refresh event queued by the save', len(ev) == 1 and ev[0]['state'] == 'pending', ob)
before = w('shimi', 'show', 't-channel-live-h')
check('SHIMI not changed before delivery', float(before['price']) == 100.0, before)
out = wp('shem', 'action-scheduler', 'run', '--hooks=scc_channel_deliver')
ob = w('shem', 'outbox', 't-channel-live-a'); ev = [r for r in ob if r['kind'] == 'refresh']
check('Action Scheduler delivered it', len(ev) == 1 and ev[0]['state'] == 'delivered' and int(ev[0]['attempts']) == 1, (ev, out[-200:]))
after = w('shimi', 'show', 't-channel-live-h')
check('SHIMI card price follows (90)', float(after['price']) == 90.0, after)
check('SHEM link back to connected', (w('shem', 'show', 't-channel-live-a')['link'] or {}).get('status') == 'connected')
w('shem', 'set-stock', 't-channel-live-a', '4')
ob2 = [r for r in w('shem', 'outbox', 't-channel-live-a') if r['kind'] == 'refresh']
check('a stock-only change emits no new event', len(ob2) == 1, ob2)
check('SHIMI shows the new stock immediately (4)', w('shimi', 'hydrate', 't-channel-live-h').get('stock') == 4)

section('3. two parallel curl workers race for the last units (no oversell)')
# product a: stock 4, two workers each want 3 -> exactly one wins
bodies = [json.dumps({'reservationId': 'shimi:t-channel-live-w%d' % i, 'items': [{'productId': 't-channel-live-a', 'variantId': '', 'size': '', 'qty': 3}]}, separators=(',', ':')) for i in (1, 2)]
res = parallel('shem', '/api/channel/reserve', sign_many('shem', '/api/channel/reserve', bodies))
codes = sorted(c for c, _ in res)
check('stock 4, two workers x qty 3: exactly one 200 and one 409', codes == [200, 409], res)
h = w('shem', 'holds', 't-channel-live-a')
held = sum(int(r['q']) for r in h['by_state'] if r['state'] == 'held')
check('exactly 3 units held, sellable stock 1', held == 3 and h['product']['stock_sellable'] == 1, h)
check('Woo stock untouched by holds (4)', h['product']['stock_woo'] == 4)
# 12 rounds on stock-1 products
ok_rounds = 0; detail = []
bodies = []
for i in range(1, 13):
    for k in ('a', 'b'): bodies.append(json.dumps({'reservationId': 'shimi:t-channel-live-r%d%s' % (i, k), 'items': [{'productId': 't-channel-live-r%d' % i, 'variantId': '', 'size': '', 'qty': 1}]}, separators=(',', ':')))
signed = sign_many('shem', '/api/channel/reserve', bodies)
for i in range(12):
    pair = parallel('shem', '/api/channel/reserve', signed[2 * i:2 * i + 2]); cs = sorted(c for c, _ in pair)
    if cs == [200, 409]: ok_rounds += 1
    else: detail.append((i + 1, pair))
check('12 rounds of 2 simultaneous workers on stock 1: exactly one winner every round', ok_rounds == 12, detail)
# 8 workers on stock 5
bodies = [json.dumps({'reservationId': 'shimi:t-channel-live-b%d' % i, 'items': [{'productId': 't-channel-live-big', 'variantId': '', 'size': '', 'qty': 1}]}, separators=(',', ':')) for i in range(8)]
res = parallel('shem', '/api/channel/reserve', sign_many('shem', '/api/channel/reserve', bodies))
n200 = sum(1 for c, _ in res if c == 200); n409 = sum(1 for c, _ in res if c == 409)
check('8 simultaneous workers on stock 5: exactly 5 succeed, 3 refused', (n200, n409) == (5, 3), res)
hb = w('shem', 'holds', 't-channel-live-big')
check('held 5, sellable 0, never negative', sum(int(r['q']) for r in hb['by_state'] if r['state'] == 'held') == 5 and hb['product']['stock_sellable'] == 0, hb)
# replay of an identical reservation (idempotent)
# which of the 8 simultaneous workers win is not deterministic: replay a body that actually won (200) and one that lost (409)
win_i = next(i for i, (c, _) in enumerate(res) if c == 200); lose_i = next(i for i, (c, _) in enumerate(res) if c == 409)
rep = parallel('shem', '/api/channel/reserve', sign_many('shem', '/api/channel/reserve', [bodies[win_i]]))
check('replaying a held reservation is idempotent (200, still 5 held)', rep[0][0] == 200 and sum(int(r['q']) for r in w('shem', 'holds', 't-channel-live-big')['by_state'] if r['state'] == 'held') == 5, rep)
rep2 = parallel('shem', '/api/channel/reserve', sign_many('shem', '/api/channel/reserve', [bodies[lose_i]]))
check('replaying a refused reservation stays refused (409) and holds nothing (still 5 held)', rep2[0][0] == 409 and sum(int(r['q']) for r in w('shem', 'holds', 't-channel-live-big')['by_state'] if r['state'] == 'held') == 5, rep2)

section('4. release and commit over HTTP')
# winner of the first race: find which id holds
winner = 'shimi:t-channel-live-w1' if res and any(True for _ in [0]) else ''
hrows = w('shem', 'holds', 't-channel-live-a')
t1 = sign_many('shem', '/api/channel/release', [json.dumps({'reservationId': 'shimi:t-channel-live-w1'}), json.dumps({'reservationId': 'shimi:t-channel-live-w2'})])
r1 = [curl('shem', '/api/channel/release', s['body'], s['ts'], s['sig']) for s in t1]
check('release (both ids; the loser has nothing to release)', [c for c, _ in r1] == [200, 200], r1)
h = w('shem', 'holds', 't-channel-live-a')
check('hold freed: sellable back to 4, Woo stock still 4', (h['product']['stock_sellable'], h['product']['stock_woo']) == (4, 4), h)
rb = json.dumps({'reservationId': 'shimi:t-channel-live-c1', 'items': [{'productId': 't-channel-live-a', 'variantId': '', 'size': '', 'qty': 1}]}, separators=(',', ':'))
s = sign_many('shem', '/api/channel/reserve', [rb])[0]; c, j = curl('shem', '/api/channel/reserve', s['body'], s['ts'], s['sig']); check('reserve 1 unit', c == 200, (c, j))
cb = json.dumps({'reservationId': 'shimi:t-channel-live-c1', 'orderId': 't-channel-live-c1'}, separators=(',', ':'))
s = sign_many('shem', '/api/channel/commit', [cb])[0]; c, j = curl('shem', '/api/channel/commit', s['body'], s['ts'], s['sig']); check('commit', (c, j) == (200, {'ok': True}), (c, j))
h = w('shem', 'holds', 't-channel-live-a'); check('Woo stock decremented exactly once (4 -> 3)', h['product']['stock_woo'] == 3, h)
s = sign_many('shem', '/api/channel/commit', [cb])[0]; c, j = curl('shem', '/api/channel/commit', s['body'], s['ts'], s['sig']); check('commit replay -> duplicate, no second decrement', (c, j.get('duplicate')) == (200, True), (c, j))
# two simultaneous commits of one reservation
rb2 = json.dumps({'reservationId': 'shimi:t-channel-live-c2', 'items': [{'productId': 't-channel-live-a', 'variantId': '', 'size': '', 'qty': 1}]}, separators=(',', ':'))
s = sign_many('shem', '/api/channel/reserve', [rb2])[0]; curl('shem', '/api/channel/reserve', s['body'], s['ts'], s['sig'])
cb2 = json.dumps({'reservationId': 'shimi:t-channel-live-c2', 'orderId': 't-channel-live-c2'}, separators=(',', ':'))
sg = sign_many('shem', '/api/channel/commit', [cb2, cb2]); res = parallel('shem', '/api/channel/commit', sg)
h = w('shem', 'holds', 't-channel-live-a')
check('two simultaneous commits of one reservation: both 200, stock 3 -> 2 exactly once', sorted(c for c, _ in res) == [200, 200] and h['product']['stock_woo'] == 2, (res, h['product']))

section('5. duplicate event replay at SHIMI (refresh inbox)')
eb = json.dumps({'id': 't-channel-live-a', 'eventId': 't-channel-live-evt-1'}, separators=(',', ':'))
s1 = sign_many('shimi', '/api/channel/refresh', [eb])[0]
c1, j1 = curl('shimi', '/api/channel/refresh', s1['body'], s1['ts'], s1['sig'])
c2, j2 = curl('shimi', '/api/channel/refresh', s1['body'], s1['ts'], s1['sig'])
check('first delivery processed', (c1, j1) == (200, {'ok': True}), (c1, j1)); check('replay answered as duplicate', (c2, j2.get('duplicate')) == (200, True), (c2, j2))
eb2 = json.dumps({'id': 't-channel-live-a', 'eventId': 't-channel-live-evt-2'}, separators=(',', ':'))
sg = sign_many('shimi', '/api/channel/refresh', [eb2, eb2]); res = parallel('shimi', '/api/channel/refresh', sg)
codes = sorted(c for c, _ in res)
ok_first = sum(1 for c, j in res if c == 200 and not j.get('duplicate'))
in_flight = all(c == 200 or (c == 409 and 'בעיבוד' in str(j.get('error'))) for c, j in res)
check('two simultaneous deliveries of one new event: exactly one processes; the other is duplicate-200 or retryable in-flight 409 (never 5xx)', ok_first == 1 and in_flight, res)
replay = curl('shimi', '/api/channel/refresh', sg[0]['body'], sg[0]['ts'], sg[0]['sig'])
check('after the race, replay of that event is a clean duplicate', (replay[0], replay[1].get('duplicate')) == (200, True), replay)

section('6. signature and route hardening over real HTTP')
c, j = curl('shem', '/api/channel/source', '{"id":"t-channel-live-a"}', str(int(time.time())), 'a' * 64); check('wrong signature -> 401', (c, j.get('error')) == (401, 'חתימה שגויה.'), (c, j))
s = sign_many('shem', '/api/channel/source', ['{"id":"t-channel-live-a"}'])[0]
c, j = curl('shem', '/api/channel/source', s['body'], str(int(s['ts']) - 400), s['sig']); check('stale timestamp -> 401', c == 401, (c, j))
c, j = curl('shem', '/api/channel/source', s['body'], s['ts'], s['sig']); check('valid signed read -> 200', c == 200 and j.get('id') == 't-channel-live-a', (c, j))
c, j = curl('shem', '/api/channel/sources', s['body'], s['ts'], s['sig']); check('signature bound to the path -> 401', c == 401, (c, j))
c = subprocess.run(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', URL['shem'] + '/api/channel/source'], capture_output=True, text=True).stdout; check('unsigned GET -> 404', c == '404', c)
c = subprocess.run(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', '-X', 'POST', '-d', '{}', URL['shimi'] + '/api/channel/reserve'], capture_output=True, text=True).stdout; check('unsigned POST to a source-only route on SHIMI -> 401/404', c in ('401', '404'), c)

section('7. peer down -> retry with backoff -> dead letter -> admin retry')
d = w('shem', 'deadletter', '77')
log = d.get('log') or []
check('real connection failure triggers retry (attempt 1)', bool(log) and log[0]['result'] == 'retry' and log[0]['status'] == 503, log[:1])
check('attempts back off exponentially', [x['backoff_s'] for x in log[:4]] and all(x['backoff_s'] is not None for x in log[:4]) and log[1]['backoff_s'] > log[0]['backoff_s'] * 1.4, [x['backoff_s'] for x in log])
check('dead letter after 8 attempts', log and log[-1]['state'] == 'dead' and log[-1]['attempts'] == 8, log[-1:] if log else d)
check('link flagged error', d.get('link_status') == 'error', d.get('link_status'))
check('SHIMI still has the old price meanwhile (90)', float(w('shimi', 'show', 't-channel-live-h')['price']) == 90.0)
ar = w('shem', 'admin-retry', d.get('event', ''))
check('admin retry (real peer) delivers', ar.get('requeued') is True and ar.get('delivered') == 'delivered' and ar.get('link_status') == 'connected', ar)
check('SHIMI price follows after the retry (77)', float(w('shimi', 'show', 't-channel-live-h')['price']) == 77.0)

section('8. cleanup (only t-channel-live-* data)')
c1 = w('shem', 'cleanup'); c2 = w('shimi', 'cleanup')
check('SHEM fixtures removed', c1.get('products_deleted') == 14, c1); check('SHIMI fixtures removed', c2.get('products_deleted') == 1, c2)
if os.path.exists('/home/claude/shem-shop/WP-PLUGIN/shem-shimi-commerce/tests/channel/live/_bodies.txt'): os.unlink('/home/claude/shem-shop/WP-PLUGIN/shem-shimi-commerce/tests/channel/live/_bodies.txt')
p = sum(1 for r in R if r[2]); f = len(R) - p
print('\n== LIVE SUMMARY: pass=%d fail=%d' % (p, f))
for r in R:
    if not r[2]: print('  FAILED: %s: %s' % (r[0], r[1]))
