"""Admin API client for the two staging sites (cookie + nonce). Passwords come from /home/claude/.stg-admin-pass (never printed)."""
import json, requests
SITES = {'shem': 'http://localhost:8081', 'shimi': 'http://localhost:8082'}
PW = dict(l.strip().split('=', 1) for l in open('/home/claude/.stg-admin-pass'))
class Client:
    def __init__(self, site):
        self.site = site; self.base = SITES[site]; self.s = requests.Session(); self.nonce = ''
    def login(self):
        self.s.get(self.base + '/wp-login.php')
        r = self.s.post(self.base + '/wp-login.php', data={'log': 'scc_test_admin', 'pwd': PW[self.site], 'wp-submit': 'Log In', 'testcookie': '1'}, allow_redirects=False)
        assert r.status_code == 302, r.status_code
        self.nonce = self.s.get(self.base + '/api/admin/session').json()['nonce']
        return self
    def call(self, path, method='GET', data=None, raw=None, ctype='application/json'):
        h = {}
        if method != 'GET': h = {'Origin': self.base, 'Content-Type': ctype, 'X-WP-Nonce': self.nonce}
        body = raw if raw is not None else (json.dumps(data) if data is not None else None)
        return self.s.request(method, self.base + '/api/admin/' + path.lstrip('/'), data=body, headers=h, timeout=120)
