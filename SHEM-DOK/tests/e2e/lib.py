"""Shared helpers for the SHEM DOK end-to-end suite (runs against the local staging WordPress only)."""
import subprocess, json, hmac, hashlib, time, urllib.request, urllib.error, urllib.parse, base64, uuid, re, os, sys, http.cookiejar, threading

BASE = 'http://localhost:8081'
WPS = '/home/claude/wps.sh'
SECRET = open('/home/claude/.stg-home-secret').read().strip()
APW = open('/home/claude/.stg-apppass').read().strip()
RESULTS = []
SECTION = ['']

def section(name):
    SECTION[0] = name
    print('\n== ' + name)

def check(name, cond, detail=''):
    ok = bool(cond)
    RESULTS.append({'section': SECTION[0], 'name': name, 'ok': ok, 'detail': str(detail)[:400]})
    print(('PASS ' if ok else 'FAIL ') + name + ('' if ok else '  -> ' + str(detail)[:300]))
    return ok

def blocked(name, reason):
    # surface not served by the migrated theme: recorded as a finding, never counted as a pass
    RESULTS.append({'section': SECTION[0], 'name': name, 'ok': None, 'detail': str(reason)[:400]})
    print('BLOCKED ' + name + '  -> ' + str(reason)[:300])

def sh(cmd, timeout=240, check_rc=True):
    r = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=timeout)
    if check_rc and r.returncode != 0:
        raise RuntimeError('cmd failed: %s\n%s\n%s' % (cmd[:200], r.stdout[-500:], r.stderr[-800:]))
    return r.stdout

def wp(*args, site='shem'):
    quoted = ' '.join("'" + a.replace("'", "'\\''") + "'" for a in args)
    return sh('%s %s %s' % (WPS, site, quoted))

def wp_eval(php, site='shem'):
    out = wp('eval', php, site=site)
    return out.strip()

def wp_json(php, site='shem'):
    out = wp_eval(php, site=site)
    # take the last line that parses as JSON
    for line in reversed(out.splitlines()):
        line = line.strip()
        if line.startswith('{') or line.startswith('['):
            return json.loads(line)
    raise RuntimeError('no json in output: ' + out[-400:])

def sql(q):
    return wp('db', 'query', q, '--skip-column-names').strip()

class Resp:
    def __init__(self, status, headers, body):
        self.status, self.headers, self.body = status, headers, body
    def json(self):
        return json.loads(self.body.decode('utf-8'))
    @property
    def text(self):
        return self.body.decode('utf-8', 'replace')

def http(method, path, body=None, headers=None, user=('localadmin', None), rest=True, data=None, opener=None, follow=True):
    url = BASE + ('/?rest_route=' + path.replace('?', '&') if rest else path)
    h = {'User-Agent': 'dok-e2e'}
    if user and user[0]:
        pw = user[1] if user[1] else APW
        h['Authorization'] = 'Basic ' + base64.b64encode(('%s:%s' % (user[0], pw)).encode()).decode()
    if headers:
        h.update(headers)
    payload = None
    if body is not None:
        payload = json.dumps(body, ensure_ascii=False).encode('utf-8')
        h['Content-Type'] = 'application/json'
    elif data is not None:
        payload = data if isinstance(data, bytes) else data.encode()
    req = urllib.request.Request(url, data=payload, headers=h, method=method)
    op = opener or urllib.request.build_opener(NoRedirect() if not follow else urllib.request.HTTPRedirectHandler())
    try:
        r = op.open(req, timeout=120)
        return Resp(r.status, dict(r.headers), r.read())
    except urllib.error.HTTPError as e:
        return Resp(e.code, dict(e.headers), e.read())

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None

def api(method, route, body=None, user=('localadmin', None)):
    return http(method, '/shem-dok/v1/' + route, body=body, user=user)

def sign(body_bytes, ts=None, secret=None):
    ts = str(ts if ts is not None else int(time.time()))
    sig = hmac.new((secret or SECRET).encode(), (ts + '.').encode() + body_bytes, hashlib.sha256).hexdigest()
    return {'x-shem-timestamp': ts, 'x-shem-signature': sig}

def callback(event, ts=None, secret=None, sig_override=None, headers_override=None, raw=None):
    body = raw if raw is not None else json.dumps(event, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
    hd = sign(body, ts, secret)
    if sig_override is not None:
        hd['x-shem-signature'] = sig_override
    if headers_override is not None:
        hd = headers_override
    hd = dict(hd)
    hd['Content-Type'] = 'application/json'
    return http('POST', '/shem-dok/v1/shimi/paid', headers=hd, data=body, user=None)

ORDER_PHP = r'''
function mk($s){
 $o=wc_create_order(['customer_id'=>$s['customer']??0]);
 foreach($s['items'] as $it){$o->add_product(wc_get_product(wc_get_product_id_by_sku($it[0])),$it[1]);}
 $o->set_billing_first_name($s['first']??'דנה');$o->set_billing_last_name($s['last']??'כהן');
 $o->set_billing_email($s['email']??'dana@example.test');$o->set_billing_phone('050-1234567');
 $o->set_billing_address_1($s['addr']??'הרצל 1');$o->set_billing_city('תל אביב');$o->set_billing_country('IL');
 if(!empty($s['coupon'])){$o->apply_coupon($s['coupon']);}
 if(!empty($s['ship'])){$si=new WC_Order_Item_Shipping();$si->set_method_title('שליח עד הבית');$si->set_method_id('flat_rate');$si->set_total($s['ship']);$o->add_item($si);}
 if(!empty($s['fee'])){$f=new WC_Order_Item_Fee();$f->set_name('עמלה');$f->set_total($s['fee']);$o->add_item($f);}
 $o->set_currency($s['currency']??'ILS');
 $o->set_payment_method($s['gw']??'shem_via_shimi');$o->set_payment_method_title('SHIMI');
 if(!empty($s['sess']))$o->update_meta_data('_shem_session_id',$s['sess']);
 $o->calculate_totals(true);
 if(!empty($s['notax'])){foreach($o->get_items() as $i){$i->set_taxes(false);}$o->set_cart_tax(0);$o->set_total($o->get_subtotal());}
 $o->set_status('pending');$o->save();
 return ['id'=>$o->get_id(),'total_cents'=>(int)round($o->get_total()*100),'tax_cents'=>(int)round($o->get_total_tax()*100)];
}
'''

def make_orders(specs):
    php = ORDER_PHP + '$specs=json_decode(%s,true);$out=[];foreach($specs as $s)$out[]=mk($s);echo json_encode($out);' % json.dumps(json.dumps(specs, ensure_ascii=False))
    return wp_json(php)

def db_row(doc_id):
    out = sql("SELECT id,doc_type,ref_id,status,gross_cents,order_id,version,deleted,pdf_sha256,pdf_size,IFNULL(emailed_at,''),source_key FROM wp_shem_dok WHERE id=%d" % doc_id)
    if not out:
        return None
    c = out.split('\t')
    return dict(id=int(c[0]), doc_type=c[1], ref_id=int(c[2]), status=c[3], gross=int(c[4]), order_id=int(c[5]), version=int(c[6]), deleted=int(c[7]), sha=c[8], size=c[9], emailed=c[10], key=c[11])

def pdf_text(pdf_bytes):
    p = '/tmp/_dok_t_%s.pdf' % uuid.uuid4().hex
    open(p, 'wb').write(pdf_bytes)
    try:
        return sh('pdftotext -layout %s -' % p)
    finally:
        os.remove(p)

def save_results(path):
    json.dump(RESULTS, open(path, 'w'), ensure_ascii=False, indent=1)

def summary():
    p = sum(1 for r in RESULTS if r['ok'] is True)
    b = sum(1 for r in RESULTS if r['ok'] is None)
    f = len(RESULTS) - p - b
    print('\nTOTAL %d  PASS %d  FAIL %d  BLOCKED %d' % (len(RESULTS), p, f, b))
    for r in RESULTS:
        if r['ok'] is False:
            print('  FAIL [%s] %s :: %s' % (r['section'], r['name'], r['detail'][:200]))
    return f
