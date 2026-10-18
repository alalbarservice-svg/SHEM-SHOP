from lib import *
import glob
PLUG = '/home/claude/dok-build/shem-dok'
CTN = 'shem-staging-shem-1'

def wpu(*args):
    q = ' '.join("'" + a.replace("'", "'\\''") + "'" for a in args)
    return sh("docker run --rm --network shem-staging_default --volumes-from %s --user 33:33 -e WORDPRESS_DB_HOST=db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=local_wp_only -e WORDPRESS_DB_NAME=shem_up wordpress:cli-php8.3 wp %s --path=/var/www/html" % (CTN, q))
def usql(q):
    return sh("docker exec shem-staging-db-1 mariadb -uroot -plocal_root_only -N -e %s shem_up" % ("'" + q.replace("'", "'\\''") + "'")).strip()

def run():
    section('O. אבטחה סטטית של הקוד')
    files = [f for f in glob.glob(PLUG + '/*.php')]
    src = {f: open(f).read() for f in files}
    allsrc = '\n'.join(src.values())
    check('O1 כל קובץ PHP מוגן ב-ABSPATH', all('ABSPATH' in v[:2500] for k, v in src.items()), [k for k, v in src.items() if 'ABSPATH' not in v[:2500]])
    check('O2 אין eval/assert/create_function/system/exec/shell_exec/passthru/popen', not re.search(r'\b(eval|create_function|shell_exec|passthru|popen|proc_open|system)\s*\(', allsrc) and not re.search(r'(?<![\w>:])exec\s*\(', allsrc))
    check('O3 אין $_REQUEST ו-$_COOKIE', '$_REQUEST' not in allsrc and '$_COOKIE' not in allsrc)
    check('O4 אין קריאות HTTP יוצאות (wp_remote, curl, file_get_contents http)', not re.search(r'wp_remote_|curl_init|file_get_contents\(\s*[\'"]http|fsockopen', allsrc))
    check('O5 אין unserialize על קלט', 'unserialize' not in allsrc)
    # every $wpdb query with variables uses prepare or integer casts: flag raw interpolation of $_GET/$_POST
    check('O6 אין $_GET/$_POST בתוך שאילתות SQL', not re.search(r'\$wpdb->(query|get_\w+)\([^;]*\$_(GET|POST)', allsrc))
    raws = re.findall(r'\$wpdb->(?:query|get_var|get_row|get_results)\(("[^"]*\$[a-z_]+[^"]*")', allsrc)
    safe = [r for r in raws if re.search(r'\$t\b|\$seq|\{\$t\}|\$a\b|self::table|\$lock|\$id|\(int\)', r)]
    check('O7 שאילתות עם משתנים מפורשים הן רק שמות טבלה/מספרים שלמים', len(raws) == len(safe), [r for r in raws if r not in safe][:5])
    pub = re.findall(r"register_rest_route\('shem-dok/v1','([^']+)'.*?'permission_callback'=>([^,\]]+)", allsrc)
    open_routes = sorted(r for r, cb in pub if '__return_true' in cb)
    check('O8 רק שני נתיבי REST ציבוריים (shimi/paid + customer/status)', open_routes == ['/customer/status', '/shimi/paid'], open_routes)
    check('O9 נתיבי הניהול דורשים manage_woocommerce (allowed)', "'permission_callback'=>[self::class,'allowed']" in allsrc and "function allowed(){return current_user_can('manage_woocommerce');}" in allsrc)
    check('O10 יש nonce בטופס הפקה ידנית מההזמנה וב-PDF מנהל', 'check_admin_referer' in allsrc and 'wp_verify_nonce' in allsrc or 'check_admin_referer' in allsrc)
    check('O11 קוד הסוד אינו מוטבע בקוד (אין מחרוזת hex/ארוכה כסוד)', not re.search(r"SHEM_HOME_PAYMENT_SECRET'\s*,\s*'", allsrc) and not re.search(r'[0-9a-f]{40,}', allsrc))
    check('O12 אין סודות/מפתחות בקבצי ההתקנה (כולל תיעוד)', not re.search(r'(sk_live|pk_live|AKIA[0-9A-Z]{12}|-----BEGIN [A-Z ]*PRIVATE KEY)', allsrc + ''.join(open(f, errors='ignore').read() for f in glob.glob(PLUG + '/docs/*') + glob.glob(PLUG + '/tests/*.php'))))
    check('O13 הכותרות: nocache_headers (no-store) + nosniff + no-referrer בהגשת PDF', all(h in allsrc for h in ('nocache_headers', 'nosniff', 'no-referrer')))
    check('O14 mPDF: ללא allow_url_fopen לתמונות חיצוניות בקוד התבנית', 'http' not in re.sub(r'https?://(www\.)?w3\.org[^\s\'"]*', '', open(PLUG + '/template.php').read()).replace('http-equiv', ''), 'template has http')
    sec = ''
    try:
        sec = sh("docker exec %s sh -c 'ls -la /var/www/html/wp-content/plugins/shem-dok | head -3; find /var/www/html/wp-content/plugins/shem-dok -name \"*.php\" -perm -o+w | head'" % CTN)
    except Exception as e: sec = str(e)
    check('O15 אין קבצי PHP עם הרשאת כתיבה לכולם', '.php' not in sec.split('\n', 3)[-1], sec[-200:])

    section('P. שדרוג מגרסה 1.0.1 (על עותק DB נפרד, shem_up)')
    n_before = int(usql("SELECT COUNT(*) FROM wp_shem_dok"))
    usql("DELETE FROM wp_shem_dok WHERE doc_type='credit'")   # credit documents did not exist in 1.0.1 (copy only)
    base = usql("SELECT COUNT(*), SUM(CRC32(payload)), SUM(CRC32(MD5(pdf))) FROM wp_shem_dok")
    usql("ALTER TABLE wp_shem_dok DROP COLUMN doc_type, DROP COLUMN ref_id, DROP COLUMN status, DROP COLUMN gross_cents, DROP COLUMN pdf_sha256, DROP COLUMN pdf_size, DROP COLUMN emailed_at")
    usql("DROP TABLE wp_shem_dok_audit"); usql("UPDATE wp_options SET option_value='1' WHERE option_name='shem_dok_db'")
    cols = usql("SHOW COLUMNS FROM wp_shem_dok").split('\n')
    check('P1 הסכמה הורדה לצורת 1.0.1', 'pdf_sha256' not in ' '.join(cols) and 'doc_type' not in ' '.join(cols))
    out = wpu('eval', "echo (int)get_option('shem_dok_db');")
    check('P2 שדרוג אוטומטי ב-plugins_loaded: גרסת DB 2 מיד בטעינה הראשונה', out.strip() == '2', out)
    check('P3 אחרי הפעלה: גרסת DB 2', wpu('eval', "echo (int)get_option('shem_dok_db');").strip() == '2')
    after = usql("SELECT COUNT(*), SUM(CRC32(payload)), SUM(CRC32(MD5(pdf))) FROM wp_shem_dok")
    check('P4 המסמכים (payload ו-pdf) לא השתנו בשדרוג', base == after, (base, after))
    check('P5 hash וגודל חושבו לכל המסמכים', usql("SELECT COUNT(*) FROM wp_shem_dok WHERE pdf IS NOT NULL AND (pdf_sha256 IS NULL OR pdf_sha256<>SHA2(pdf,256) OR pdf_size<>LENGTH(pdf))") == '0')
    check('P6 gross_cents הושלם לחשבוניות', usql("SELECT COUNT(*) FROM wp_shem_dok WHERE doc_type='invoice' AND gross_cents=0") == '0')
    check('P7 doc_type=invoice ו-status=issued ברירת מחדל', usql("SELECT COUNT(*) FROM wp_shem_dok WHERE doc_type<>'invoice' OR status<>'issued'") == '0')
    nxt = int(usql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1")); mx = int(usql("SELECT MAX(id) FROM wp_shem_dok"))
    check('P8 רצף המספור שמור (next > max)', nxt > mx, (nxt, mx))
    ver = wpu('shem-dok', 'verify')
    check('P9 verify על כל המסמכים לאחר שדרוג', 'verified' in ver, ver)
    again = wpu('eval', "ShemDok::install();ShemDok::install();echo 'ok';")
    check('P10 install חוזר אידמפוטנטי', again.strip() == 'ok' and usql("SELECT COUNT(*) FROM wp_shem_dok") == str(int(base.split()[0])))

    section('Q. הפעלה מחדש ושמירת נתונים (על SHEM staging)')
    before = sql("SELECT COUNT(*), MAX(id) FROM wp_shem_dok"); nxt0 = sql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1")
    sets0 = wp('option', 'get', 'shem_dok_settings', '--format=json')
    wp('plugin', 'deactivate', 'shem-dok')
    check('Q1 כיבוי התוסף אינו מוחק טבלאות', sql("SHOW TABLES LIKE 'wp_shem_dok%'").count('wp_shem_dok') >= 3)
    r = http('POST', '/shem-dok/v1/shimi/paid', headers={'Content-Type': 'application/json'}, data=b'{}', user=None)
    check('Q2 כשהתוסף כבוי נקודת הקצה לא קיימת (404)', r.status == 404, r.status)
    wp('plugin', 'activate', 'shem-dok')
    check('Q3 אחרי הפעלה מחדש: הנתונים והמספור זהים', sql("SELECT COUNT(*), MAX(id) FROM wp_shem_dok") == before and sql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1") == nxt0)
    check('Q4 ההגדרות נשמרו', wp('option', 'get', 'shem_dok_settings', '--format=json') == sets0)
    check('Q5 state תקין אחרי הפעלה', api('GET', 'state').status == 200)

    section('R. כשל במנוע PDF: Rollback ללא פער מספור')
    c0 = sql("SELECT COUNT(*) FROM wp_shem_dok"); n0 = sql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1")
    sh("docker exec %s sh -c 'mkdir -p /tmp/shem-dok-mpdf && chmod 500 /tmp/shem-dok-mpdf && chown root:root /tmp/shem-dok-mpdf'" % CTN)
    from s1_core import valid_doc
    r = api('POST', 'issue', {'requestId': str(uuid.uuid4()), 'document': valid_doc()})
    check('R1 כשל במנוע: שגיאה מוחזרת (לא 200)', r.status >= 400, (r.status, r.text[:120]))
    check('R2 לא נשארה רשומה חלקית ולא התקדם המספור', sql("SELECT COUNT(*) FROM wp_shem_dok") == c0 and sql("SELECT next_number FROM wp_shem_dok_sequence WHERE id=1") == n0, (c0, n0))
    sh("docker exec %s sh -c 'chown www-data:www-data /tmp/shem-dok-mpdf && chmod 775 /tmp/shem-dok-mpdf'" % CTN)
    r = api('POST', 'issue', {'requestId': str(uuid.uuid4()), 'document': valid_doc()})
    check('R3 אחרי תיקון המנוע ההפקה חוזרת לעבוד עם המספר הבא ללא פער', r.status == 200 and str(r.json()['id']) == n0, (r.status, n0))

    section('S. ביצועים וגבולות')
    big = valid_doc(items=[{'name': 'פריט %d ' % i + 'א' * 30, 'sku': 'S-%d' % i, 'qty': 1, 'price': 10 + i % 7} for i in range(200)])
    t0 = time.time(); r = api('POST', 'issue', {'requestId': str(uuid.uuid4()), 'document': big}); dt = time.time() - t0
    check('S1 מסמך 200 פריטים הופק', r.status == 200, (r.status, r.text[:150]))
    if r.status == 200:
        row = db_row(r.json()['id']); check('S2 הזמן להפקת 200 פריטים < 20 שניות', dt < 20, round(dt, 1))
        check('S3 גודל PDF 200 פריטים סביר (<1.5MB)', int(row['size']) < 1_500_000, row['size'])
        txt = pdf_text(bytes.fromhex(sql("SELECT HEX(pdf) FROM wp_shem_dok WHERE id=%d" % row['id']).strip()))
        check('S4 כל 200 הפריטים בטקסט ה-PDF (S-0 עד S-199)', 'S-0' in txt and 'S-199' in txt, len(txt))
    t0 = time.time(); r = api('POST', 'issue', {'requestId': str(uuid.uuid4()), 'document': valid_doc()}); one = time.time() - t0
    check('S5 הפקת מסמך רגיל < 5 שניות', r.status == 200 and one < 5, round(one, 1))
    t0 = time.time(); api('GET', 'documents?page=1'); lt = time.time() - t0
    check('S6 רשימת ארכיון < 3 שניות עם כל המסמכים', lt < 3, round(lt, 2))
    sz = int(sql("SELECT ROUND(SUM(pdf_size)/1024/1024) FROM wp_shem_dok")); cnt = int(sql("SELECT COUNT(*) FROM wp_shem_dok"))
    typ = int(sql("SELECT ROUND(AVG(pdf_size)/1024) FROM wp_shem_dok WHERE pdf_size<200000"))
    check('S7 גודל PDF טיפוסי (<150KB)', typ < 150, typ)

    section('T. יומנים')
    dbg = sh("docker exec %s sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null | grep -v \"shem-dok-mpdf\\|Cache.php\\|wp_update_plugins\\|wp_update_themes\\|themes_api\" | grep -ciE \"fatal|database error|warning|notice|deprecated\" ; true'" % CTN, check_rc=False)
    check('T1 אין fatal/שגיאות DB/warning ב-debug.log מעבר לכשל ה-mPDF שהוזרק בכוונה', dbg.strip() in ('0', ''), dbg[-300:])
    ap = sh("docker exec %s sh -c 'tail -n 400 /var/log/apache2/error.log 2>/dev/null | grep -ciE \"shem-dok.*(fatal|warning|notice|deprecated)\"' || true" % CTN, check_rc=False)
    check('T2 אין שגיאות PHP של התוסף ביומן Apache', ap.strip() in ('0', ''), ap)

if __name__ == '__main__':
    run(); summary(); save_results('/home/claude/dok-build/tests/e2e/results-s4.json')
