#!/usr/bin/env python3
"""
SHEM club mailing (Resend integration) end-to-end check, staging only, SINK mode: nothing is ever sent.
Usage: club_mail.py   (always runs on SHEM; SHIMI is checked to answer 404)
All demo rows carry the prefix t_cm_ and are removed at the end; settings are snapshotted and restored.
Audit rows are append-only by design and stay. The real Resend transport is checked with a stubbed HTTP layer
(filter pre_http_request, dummy key): the payload shape is verified, no network call is made.
"""
import sys, os, json, re, subprocess, collections, html as htmlmod
sys.argv = [sys.argv[0], 'shem']
src = open(os.path.join(os.path.dirname(os.path.abspath(__file__)), 'admin_suite.py')).read().rsplit('\nmain()', 1)[0]
src = src.replace('last-run-{SITE}.json', 'last-run-clubmail-{SITE}.json')
exec(compile(src, 'admin_suite', 'exec'), globals())
P = 't_cm_'
WPS = '/home/claude/wps.sh'
def wp(*a, site='shem'):
    p = subprocess.run([WPS, site, *a], capture_output=True, text=True, timeout=180); return p.stdout.strip()
def php(code, site='shem'): return wp('eval', code, site=site)
def sink():
    raw = wp('option', 'get', 'scc_mail_sink', '--format=json')
    try: return json.loads(raw) or []
    except Exception: return []
def reset_day(): wp('option', 'delete', 'scc_mail_day')
def reset_sink(): wp('option', 'delete', 'scc_mail_sink')
def seed(i, status='active', consent=True, email=None):
    d = {'email': email or f'{P}{i}@example.test', 'status': status, 'created_at': '2026-10-31T00:00:00.000Z'}
    if consent: d['consent_at'] = '2026-10-31T00:00:00.000Z'
    sql(f"INSERT INTO {TBL('members')} (id,data,updated_at) VALUES ({q(P + str(i))},{q(json.dumps(d))},'2026-10-31T00:00:00Z') ON DUPLICATE KEY UPDATE data=VALUES(data)")
    track('members', P + str(i))
def set_advertiser(on):
    if on:
        php("$b=Scc_Admin::setting('invoice'); $b=array_merge(is_array($b)?$b:[],['legalName'=>'t_cm Demo Ltd','address'=>'Demo St 1, Demo City','phone'=>'0500000000','email'=>'office@example.test']); Scc_Admin::save_setting('invoice',$b);")
    else:
        php("$b=Scc_Admin::setting('invoice'); $b=is_array($b)?$b:[]; foreach(['legalName','address','phone','email'] as $k) unset($b[$k]); Scc_Admin::save_setting('invoice',$b);")
ENDPOINT = 'manage/club/broadcast'
SUBJ, TXT = 't_cm הטבה לחברי מועדון', 'שלום, הטבה חדשה למועדון.\nשורה שנייה <b>לא מודגש</b> & סימנים'

def run():
    login(); snapshot()
    reset_day(); reset_sink()
    # ---- demo members: 3 deliverable, plus rows that must be skipped
    for i in (1, 2, 3): seed(i)
    seed('unsub', status='unsubscribed'); seed('noconsent', consent=False); seed('dup', email=f'{P}1@example.test'); seed('badmail', email='not-an-email')

    screen('club mail: access + gates')
    st('GET status anonymous -> 401', call(ENDPOINT, anon=True), 401)
    st('POST broadcast anonymous -> 401', call(ENDPOINT, 'POST', {'subject': 'x', 'text': 'y'}, anon=True), 401)
    s = call(ENDPOINT); st('GET status', s, 200)
    eq('configured + sink mode', (s.j or {}).get('configured'), True); eq('sink flag', (s.j or {}).get('sink'), True)
    eq('daily cap is 100 (Resend free plan)', (s.j or {}).get('dailyCap'), 100)
    eq('only 3 deliverable recipients (unsubscribed, no consent, duplicate, bad address skipped)', (s.j or {}).get('activeRecipients'), 3)
    set_advertiser(False)
    s = call(ENDPOINT); ok('missing advertiser fields are reported', set((s.j or {}).get('missingAdvertiserFields', [])) >= {'name', 'address', 'phone', 'email'}, s.t[:200])
    st('empty subject -> 400', call(ENDPOINT, 'POST', {'subject': '', 'text': 'x'}), 400)
    st('empty text -> 400', call(ENDPOINT, 'POST', {'subject': 'x', 'text': ''}), 400)
    st('confirm without advertiser details -> 409', call(ENDPOINT, 'POST', {'subject': SUBJ, 'text': TXT, 'confirm': True}), 409)
    eq('nothing recorded by the blocked attempt', len(sink()), 0)
    st('cross-origin write rejected', call(ENDPOINT, 'POST', {'subject': 'x', 'text': 'y'}, origin='https://evil.test'), 403, 'Origin rejected')
    set_advertiser(True)

    screen('club mail: dry run')
    r = call(ENDPOINT, 'POST', {'subject': SUBJ, 'text': TXT}); st('dry run (no confirm)', r, 200)
    j = r.j or {}
    ok('dry run flags dryRun and counts', j.get('dryRun') is True and j.get('recipients') == 3 and j.get('wouldSendNow') == 3 and j.get('deferred') == 0, j)
    ok('dry run subject carries the word פרסומת', str(j.get('subject', '')).startswith('פרסומת'), j.get('subject'))
    ok('dry run sample is masked', all('***@' in x for x in j.get('sample', [])) and not any(P + '1@' in x for x in j.get('sample', [])), j.get('sample'))
    eq('dry run recorded nothing', len(sink()), 0)
    eq('dry run did not touch the daily counter', (call(ENDPOINT).j or {}).get('sentToday'), 0)
    r = call(ENDPOINT, 'POST', {'subject': SUBJ, 'text': TXT, 'confirm': 'yes'}); ok('confirm must be boolean true (string "yes" stays a dry run)', (r.j or {}).get('dryRun') is True, r.t[:160])

    screen('club mail: test message')
    r = call(ENDPOINT, 'POST', {'subject': SUBJ, 'text': TXT, 'testTo': f'{P}tester@example.test'}); ok('test dry run', r.status == 200 and (r.j or {}).get('test') and (r.j or {}).get('dryRun'), r.t[:200])
    eq('test dry run recorded nothing', len(sink()), 0)
    st('test to invalid address -> 400', call(ENDPOINT, 'POST', {'subject': SUBJ, 'text': TXT, 'testTo': 'nope'}), 400)
    r = call(ENDPOINT, 'POST', {'subject': SUBJ, 'text': TXT, 'testTo': f'{P}tester@example.test', 'confirm': True}); st('test send (sink)', r, 200)
    eq('test sent exactly 1', ((r.j or {}).get('sent'), (r.j or {}).get('failed')), (1, 0))
    sk = sink(); ok('test message recorded for the tester', len(sk) == 1 and sk[0]['to'] == f'{P}tester@example.test' and sk[0]['subject'].startswith('פרסומת: בדיקה'), sk[:1])
    reset_sink(); reset_day()

    screen('club mail: real broadcast to the demo club')
    r = call(ENDPOINT, 'POST', {'subject': SUBJ, 'text': TXT, 'confirm': True}); st('broadcast (sink)', r, 200)
    j = r.j or {}; cid = j.get('campaignId')
    eq('sent 3, failed 0, deferred 0', (j.get('sent'), j.get('failed'), j.get('deferred')), (3, 0, 0))
    sk = sink()
    eq('sink holds exactly the 3 deliverable members', sorted(m['to'] for m in sk), [f'{P}{i}@example.test' for i in (1, 2, 3)])
    m0 = sk[0] if sk else {}
    ok('subject starts with פרסומת', all(m['subject'].startswith('פרסומת: ') for m in sk), [m['subject'] for m in sk])
    ok('List-Unsubscribe + one-click headers on every message', all(m['headers'].get('List-Unsubscribe', '').startswith('<') and m['headers'].get('List-Unsubscribe-Post') == 'List-Unsubscribe=One-Click' for m in sk), m0.get('headers'))
    ok('each message has its own personal opt-out link', len({m['headers']['List-Unsubscribe'] for m in sk}) == 3, 'links not unique')
    ok('advertiser identity in text and html', all(x in m0.get('text', '') for x in ('t_cm Demo Ltd', 'Demo St 1', '0500000000', 'office@example.test')) and 't_cm Demo Ltd' in m0.get('html', ''), m0.get('text', '')[-300:])
    ok('html is RTL Hebrew and escapes user text', 'dir="rtl"' in m0.get('html', '') and '<b>' not in m0.get('html', '') and '&lt;b&gt;' in m0.get('html', ''), m0.get('html', '')[:300])
    eq('reply-to is the advertiser e-mail (opt-out by e-mail channel)', m0.get('reply_to'), 'office@example.test')
    eq('daily counter counts the 3 sent', (call(ENDPOINT).j or {}).get('sentToday'), 3)
    r2 = call(ENDPOINT, 'POST', {'subject': 'ignored', 'text': 'ignored', 'confirm': True, 'campaignId': cid}); st('resend same campaign', r2, 200)
    eq('same campaign again sends nobody (no double sending)', ((r2.j or {}).get('sent'), len(sink())), (0, 3))
    st('unknown campaign id -> 404', call(ENDPOINT, 'POST', {'subject': 'x', 'text': 'y', 'confirm': True, 'campaignId': '00000000-0000-4000-8000-000000000000'}), 404)
    st('malformed campaign id -> 400', call(ENDPOINT, 'POST', {'subject': 'x', 'text': 'y', 'campaignId': 'zz'}), 400)

    screen('club mail: opt-out link')
    link = m0.get('headers', {}).get('List-Unsubscribe', '').strip('<>')
    path = link.split('/api/', 1)[-1] if '/api/' in link else ''
    g = requests.get(BASE + '/api/' + path); ok('opt-out page opens (GET) and shows a button', g.status_code == 200 and 'אישור הסרה' in g.text and 'noindex' in g.text, g.status_code)
    victim = re.search(r'm=([^&]+)&', link).group(1)
    eq('GET alone does not unsubscribe (link scanners are harmless)', json.loads(sql(f"SELECT data FROM {TBL('members')} WHERE id={q(victim)}")[0])['status'], 'active')
    bad = BASE + '/api/club/unsubscribe?m=' + victim + '&t=' + '0' * 64
    ok('wrong token -> 400', requests.post(bad).status_code == 400 and requests.get(bad).status_code == 400, 'accepted a wrong token')
    other = [x for x in (P + '1', P + '2', P + '3') if x != victim][0]
    ok('token of one member does not work for another', requests.post(BASE + '/api/club/unsubscribe?m=' + other + '&t=' + re.search(r't=([0-9a-f]+)', link).group(1)).status_code == 400, 'cross-member token accepted')
    p = requests.post(BASE + '/api/' + path, data='List-Unsubscribe=One-Click', headers={'Content-Type': 'application/x-www-form-urlencoded'}); ok('one-click POST (no Origin header, like mail clients) succeeds', p.status_code == 200 and 'הוסרתם' in p.text, (p.status_code, p.text[:120]))
    d = json.loads(sql(f"SELECT data FROM {TBL('members')} WHERE id={q(victim)}")[0]); ok('member is now unsubscribed with a timestamp', d['status'] == 'unsubscribed' and d.get('unsubscribed_at'), d)
    eq('the other members stay active', sorted(json.loads(sql(f"SELECT data FROM {TBL('members')} WHERE id={q(x)}")[0])['status'] for x in (P + '1', P + '2', P + '3') if x != victim), ['active', 'active'])
    eq('recipients drop to 2', (call(ENDPOINT).j or {}).get('activeRecipients'), 2)
    reset_sink(); reset_day()

    screen('club mail: daily cap + resume')
    for i in range(10, 110): seed(i)           # 100 more -> 102 deliverable in total
    reset_day()
    r = call(ENDPOINT, 'POST', {'subject': 't_cm cap', 'text': 'cap test'}); j = r.j or {}
    ok('dry run: 100 now, rest deferred', j.get('recipients') == 102 and j.get('wouldSendNow') == 100 and j.get('deferred') == 2, j)
    r = call(ENDPOINT, 'POST', {'subject': 't_cm cap', 'text': 'cap test', 'confirm': True}); j = r.j or {}; cid2 = j.get('campaignId')
    eq('first run sends exactly 100 and defers 2', (j.get('sent'), j.get('deferred'), j.get('remainingToday')), (100, 2, 0))
    st('a further send today is refused (cap) -> 429', call(ENDPOINT, 'POST', {'subject': 'x', 'text': 'y', 'confirm': True, 'campaignId': cid2}), 429)
    eq('sink has 100 messages', len(sink()), 100)
    reset_day()                                  # simulate the next UTC day
    r = call(ENDPOINT, 'POST', {'subject': 'ignored', 'text': 'ignored', 'confirm': True, 'campaignId': cid2}); j = r.j or {}
    eq('next day: the remaining 2 are sent', (j.get('sent'), j.get('deferred')), (2, 0))
    to = [m['to'] for m in sink()]
    eq('102 messages in total, no recipient twice', (len(to), len(set(to))), (102, 102))
    ok('resumed campaign keeps its original content', all(m['subject'] == 'פרסומת: t_cm cap' for m in sink()), 'subject changed on resume')
    reset_sink(); reset_day()

    screen('club mail: real transport (stubbed HTTP, dummy key, no network)')
    out = php(r"""
define('SCC_RESEND_API_KEY','re_dummy_not_a_key'); $seen=[];
add_filter('scc_admin_mail_sink','__return_false');
add_filter('pre_http_request', function($pre,$args,$url) use (&$seen){ $seen[]=['url'=>$url,'auth'=>$args['headers']['Authorization'] ?? '','body'=>json_decode($args['body'],true)];
  $n=count(json_decode($args['body'],true)); $d=[]; for($i=0;$i<$n;$i++) $d[]=['id'=>'stub-'.$i];
  return ['response'=>['code'=>200],'headers'=>[],'body'=>json_encode(['data'=>$d]),'cookies'=>[],'filename'=>null]; },10,3);
$m=[Scc_Admin_Mail::build('x1','a@example.test','s','t'),Scc_Admin_Mail::build('x2','b@example.test','s','t')];
$r=Scc_Admin_Mail::deliver($m);
echo json_encode(['res'=>$r,'calls'=>count($seen),'url'=>$seen[0]['url'],'authPrefix'=>substr($seen[0]['auth'],0,10),'n'=>count($seen[0]['body']),'keys'=>array_keys($seen[0]['body'][0]),'to'=>$seen[0]['body'][0]['to'],'from'=>$seen[0]['body'][0]['from'],'hdr'=>array_keys($seen[0]['body'][0]['headers'])]);
""")
    try: t = json.loads(out)
    except Exception: t = {}; print('  transport stub output:', out[:300])
    ok('one batch request to the Resend API', t.get('calls') == 1 and t.get('url') == 'https://api.resend.com/emails/batch', t)
    ok('Bearer auth header is sent', t.get('authPrefix') == 'Bearer re_', t.get('authPrefix'))
    ok('payload has from/to(array)/subject/html/text/headers/reply_to', t.get('keys') == ['from', 'to', 'subject', 'html', 'text', 'headers', 'reply_to'] and t.get('to') == ['a@example.test'] and t.get('from') == 'SHEM <club@example.invalid>', t)
    ok('both messages reported ok with provider ids', [x.get('ok') for x in (t.get('res') or [])] == [True, True] and (t.get('res') or [{}])[0].get('id') == 'stub-0', t.get('res'))
    out = php(r"""
define('SCC_RESEND_API_KEY','re_dummy_not_a_key'); add_filter('scc_admin_mail_sink','__return_false');
add_filter('pre_http_request', fn($p,$a,$u)=>['response'=>['code'=>422],'headers'=>[],'body'=>json_encode(['message'=>'invalid from address']),'cookies'=>[],'filename'=>null],10,3);
echo json_encode(Scc_Admin_Mail::deliver([Scc_Admin_Mail::build('x1','a@example.test','s','t')]));""")
    try: f = json.loads(out)
    except Exception: f = []
    ok('provider error (422) -> reported failed, not sent, key not leaked', f and f[0].get('ok') is False and 're_dummy' not in out, out[:200])

    screen('club mail: SHIMI has no club mailing')
    sp = subprocess.run([WPS, 'shimi', 'eval', "echo json_encode(['role'=>SCC_ROLE,'ok'=>Scc_Admin_Mail::role_ok()]);"], capture_output=True, text=True).stdout.strip()
    eq('SHIMI role is not allowed to mail the club', json.loads(sp or '{}').get('ok'), False)
    sb = requests.get('http://localhost:8082/api/club/unsubscribe?m=x&t=y'); eq('SHIMI public opt-out route answers 404', sb.status_code, 404)

    screen('club mail: audit')
    a = [x for x in (call('audit').j or []) if 'club/broadcast' in str(x.get('action', ''))]
    ok('every confirmed/dry POST is audited without the message body', a and not any(TXT[:12] in json.dumps(x, ensure_ascii=False) for x in a), a[:1])

def cleanup2():
    sql(f"DELETE FROM {TBL('members')} WHERE id LIKE 't\\_cm\\_%'")
    sql(f"DELETE FROM {TBL('member_profiles')} WHERE id LIKE 't\\_cm\\_%'")
    ids = wp('option', 'list', '--search=scc_mail_campaign_*', '--field=option_name').split()
    for o in ids: wp('option', 'delete', o)
    reset_sink(); reset_day(); restore()

def main():
    try: run()
    except Exception as e:
        import traceback; traceback.print_exc(); rec('FAIL', 'suite crashed', repr(e))
    finally:
        try: cleanup2()
        except Exception as e: print('cleanup problem', e)
    by = collections.OrderedDict()
    for sc, label, status, det in RES: by.setdefault(sc, collections.Counter())[status] += 1
    tot = collections.Counter(s for _, _, s, _ in RES)
    print('\n== club mail (SHEM staging, sink): ' + ', '.join(f'{k}={v}' for k, v in sorted(tot.items())) + f' (total {len(RES)})')
    for sc, c in by.items(): print(f'  {sc:60s}', dict(c))
    json.dump([{'screen': a, 'check': b, 'status': c, 'detail': d} for a, b, c, d in RES], open('/home/claude/shem-shop/WP-TESTS/admin/last-run-clubmail-shem.json', 'w'), ensure_ascii=False, indent=1)
    sys.exit(1 if tot['FAIL'] else 0)
main()
