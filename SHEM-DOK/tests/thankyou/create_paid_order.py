import subprocess,json,hmac,hashlib,time,urllib.request,sys
W='/home/claude/wps.sh'
def wp(*a):
    return subprocess.run([W]+list(a),capture_output=True,text=True,timeout=240).stdout.strip()
secret=wp('shem','config','get','SHEM_HOME_PAYMENT_SECRET').splitlines()[-1].strip()
php='''$o=wc_create_order();$o->add_product(wc_get_product(13),1);
$o->set_billing_first_name("דנה");$o->set_billing_last_name("כהן");$o->set_billing_email("dana@example.test");$o->set_billing_country("IL");
$o->set_payment_method("shem_pay");$o->set_payment_method_title("SHIMI");$o->calculate_totals(true);$o->set_status("pending");$o->save();
echo json_encode(["id"=>$o->get_id(),"cents"=>(int)round($o->get_total()*100),"url"=>$o->get_checkout_order_received_url()]);'''
out=wp('shem','eval',php).splitlines()[-1]; o=json.loads(out); print('order',o['id'])
e={'orderId':str(o['id']),'status':'paid','currency':'ILS','amountCents':o['cents'],'transactionId':'TX-TY-%d'%int(time.time()),'paymentDetails':{'method':'card','last4':'4242','cardholderName':'דנה כהן','brand':'Visa','authorizationCode':'AUTH77','installments':1}}
body=json.dumps(e,ensure_ascii=False,separators=(',',':')).encode()
ts=str(int(time.time())); sig=hmac.new(secret.encode(),(ts+'.').encode()+body,hashlib.sha256).hexdigest()
req=urllib.request.Request('http://localhost:8081/?rest_route=/shem-dok/v1/pay/notify',data=body,headers={'Content-Type':'application/json','x-shem-timestamp':ts,'x-shem-signature':sig},method='POST')
try: print('cb',urllib.request.urlopen(req,timeout=60).status)
except Exception as ex: print('cb err',ex, getattr(ex,'read',lambda:b'')()[:200])
for _ in range(10):
    n=wp('shem','db','query',"SELECT COUNT(*) FROM wp_shem_dok WHERE order_id=%d AND doc_type='invoice'"%o['id']).split()[-1]
    if n!='0': break
    wp('shem','cron','event','run','--due-now'); time.sleep(1)
print('invoice rows',n); open('ty_url.txt','w').write(o['url'])
