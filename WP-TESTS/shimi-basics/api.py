import sys, json, requests
SITE='shimi'; BASE='http://localhost:8082'
PW = dict(l.strip().split('=',1) for l in open('/home/claude/.stg-admin-pass'))[SITE]
S=requests.Session(); N=['']
def login():
    S.get(BASE+'/wp-login.php')
    r=S.post(BASE+'/wp-login.php',data={'log':'scc_test_admin','pwd':PW,'wp-submit':'Log In','testcookie':'1'},allow_redirects=False)
    assert r.status_code==302
    N[0]=S.get(BASE+'/api/admin/session').json()['nonce']
def call(path,method='GET',data=None,raw=None,ctype='application/json'):
    h={}
    if method!='GET':
        h={'Origin':BASE,'Content-Type':ctype,'X-WP-Nonce':N[0]}
    body=raw if raw is not None else (json.dumps(data) if data is not None else None)
    return S.request(method,BASE+'/api/admin/'+path.lstrip('/'),data=body,headers=h)
