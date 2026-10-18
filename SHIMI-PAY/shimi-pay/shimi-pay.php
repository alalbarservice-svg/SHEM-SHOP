<?php
/**
 * Plugin Name: SHIMI Pay (staging prototype)
 * Description: Home-site payment for SHEM orders (protocol shem-home-v1). Signed session creation, embeddable PayPal page, server-side PayPal Orders create/capture, signed paid callback to SHEM. Staging prototype - sandbox only.
 * Version: 0.1.0
 * Requires PHP: 8.1
 *
 * Secrets live ONLY in wp-config.php constants (never in the database, client code or repo):
 *   SHIMI_HOME_PAYMENT_SECRET   shared HMAC secret with SHEM (>= 32 chars)
 *   SHIMI_PAYPAL_CLIENT_ID      PayPal REST app client id (public; shown to the browser by the PayPal SDK)
 *   SHIMI_PAYPAL_SECRET         PayPal REST app secret (server only)
 *   SHIMI_PAYPAL_ENV            'sandbox' (default). 'live' is refused unless SHIMI_PAYPAL_ALLOW_LIVE is true.
 *   SHIMI_SHEM_CALLBACK_URL     server-to-server URL of SHEM /shem-dok/v1/shimi/paid
 *   SHIMI_SHEM_ORIGIN           browser origin of SHEM (frame-ancestors + postMessage target)
 */
if (!defined('ABSPATH')) exit;

final class Shimi_Pay {
 const NS = 'shimi-pay/v1';
 const OPT = 'shimi_pay_s_';
 const MAX_CB = 8;

 /* ---------- config ---------- */
 static function secret(){ $s=defined('SHIMI_HOME_PAYMENT_SECRET')?SHIMI_HOME_PAYMENT_SECRET:''; return (is_string($s)&&strlen($s)>=32)?$s:null; }
 static function env(){ $e=defined('SHIMI_PAYPAL_ENV')?SHIMI_PAYPAL_ENV:'sandbox'; if($e==='live'&&!(defined('SHIMI_PAYPAL_ALLOW_LIVE')&&SHIMI_PAYPAL_ALLOW_LIVE===true))return null; return $e==='live'?'live':'sandbox'; }
 static function pp_base(){ return self::env()==='live'?'https://api-m.paypal.com':'https://api-m.sandbox.paypal.com'; }
 static function pp_ready(){ return self::env()!==null&&defined('SHIMI_PAYPAL_CLIENT_ID')&&defined('SHIMI_PAYPAL_SECRET')&&SHIMI_PAYPAL_CLIENT_ID!==''&&SHIMI_PAYPAL_SECRET!==''; }
 static function shem_origin(){ return defined('SHIMI_SHEM_ORIGIN')?rtrim(SHIMI_SHEM_ORIGIN,'/'):''; }

 /* ---------- storage ---------- */
 static function load($sid){ if(!preg_match('/^[a-f0-9]{24}$/',(string)$sid))return null; $v=get_option(self::OPT.$sid,null); $a=is_string($v)?json_decode($v,true):null; return is_array($a)?$a:null; }
 static function save($rec){ update_option(self::OPT.$rec['id'],wp_json_encode($rec),false); }
 static function find_by_order($orderId){ global $wpdb; $v=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s LIMIT 1",$wpdb->esc_like(self::OPT).'%','%"orderId":'.wp_json_encode((string)$orderId).'%')); $a=is_string($v)?json_decode($v,true):null; return (is_array($a)&&($a['orderId']??'')===(string)$orderId)?$a:null; }
 static function locked($sid,$fn){ global $wpdb; $l='shimipay:'.$sid; if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$l))!==1)return new WP_REST_Response(['ok'=>false,'code'=>'busy','message'=>'Busy, retry'],503); try{ return $fn(); } finally{ $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$l)); } }
 static function fail($st,$code,$msg){ return new WP_REST_Response(['ok'=>false,'code'=>$code,'message'=>$msg],$st); }
 static function cents_to_str($c){ return number_format($c/100,2,'.',''); }

 /* ---------- routes ---------- */
 static function routes(){
  register_rest_route(self::NS,'/session',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>[self::class,'create_session']]);
  register_rest_route(self::NS,'/paypal/create',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>[self::class,'pp_create']]);
  register_rest_route(self::NS,'/paypal/capture',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>[self::class,'pp_capture']]);
 }

 /* ---------- 1. signed session creation (SHEM -> SHIMI) ---------- */
 static function create_session(WP_REST_Request $r){
  $secret=self::secret(); if($secret===null)return self::fail(503,'not_configured','Not configured');
  $raw=$r->get_body(); if(strlen($raw)>65536)return self::fail(413,'too_large','Body too large');
  $ts=(string)$r->get_header('x-shem-timestamp'); $sig=strtolower((string)$r->get_header('x-shem-signature'));
  if(!ctype_digit($ts)||abs(time()-(int)$ts)>300)return self::fail(401,'stale','Invalid or stale timestamp');
  if(!preg_match('/^[0-9a-f]{64}$/',$sig)||!hash_equals(hash_hmac('sha256',$ts.'.'.$raw,$secret),$sig))return self::fail(401,'bad_signature','Invalid signature');
  $b=json_decode($raw,true); if(!is_array($b))return self::fail(400,'bad_json','Invalid JSON');
  if(($b['protocol']??'')!=='shem-home-v1')return self::fail(400,'protocol','Unsupported protocol');
  $orderId=(string)($b['orderId']??''); if($orderId===''||strlen($orderId)>64||!preg_match('/^[A-Za-z0-9_-]+$/',$orderId))return self::fail(400,'order_id','Bad orderId');
  if(($b['currency']??'')!=='ILS')return self::fail(400,'currency','Only ILS');
  $amount=$b['amountCents']??null; if(!is_int($amount)||$amount<=0||$amount>100000000)return self::fail(400,'amount','amountCents must be a positive integer');
  $items=$b['items']??null; if(!is_array($items)||count($items)<1||count($items)>200)return self::fail(400,'items','1-200 items required');
  $ship=$b['shippingCents']??0; $disc=$b['discountCents']??0; if(!is_int($ship)||$ship<0||!is_int($disc)||$disc<0)return self::fail(400,'totals','Bad shipping/discount');
  $sum=0; foreach($items as $it){ if(!is_array($it)||!isset($it['qty'],$it['priceCents'])||!is_int($it['qty'])||$it['qty']<1||$it['qty']>1000||!is_int($it['priceCents'])||$it['priceCents']<0)return self::fail(400,'items','Bad item'); $sum+=$it['qty']*$it['priceCents']; }
  if($sum-$disc+$ship!==$amount)return self::fail(422,'total_mismatch','Items, shipping and discount do not add up to amountCents');
  $c=is_array($b['customer']??null)?$b['customer']:[];
  return self::locked('o'.md5($orderId),function() use($orderId,$amount,$c,$sum,$ship,$disc){
   $ex=self::find_by_order($orderId);
   if($ex){
    if($ex['state']==='paid'||$ex['state']==='callback_ok'||$ex['state']==='callback_pending'||$ex['state']==='callback_rejected')return self::fail(409,'already_paid','Order already paid');
    if($ex['amountCents']!==$amount)return self::fail(409,'amount_changed','Order already has a session with another amount');
    $tok=self::rotate_token($ex); self::save($ex);
    return new WP_REST_Response(['embedUrl'=>self::embed_url($ex['id'],$tok),'reused'=>true],200);
   }
   $sid=bin2hex(random_bytes(12)); $tok=bin2hex(random_bytes(16));
   $rec=['id'=>$sid,'th'=>hash('sha256',$tok),'orderId'=>$orderId,'amountCents'=>$amount,'currency'=>'ILS','customer'=>['name'=>mb_substr(sanitize_text_field((string)($c['name']??'')),0,80)],'state'=>'created','ppOrderId'=>'','txn'=>'','created'=>time(),'cbAttempts'=>0,'cbError'=>''];
   self::save($rec);
   return new WP_REST_Response(['embedUrl'=>self::embed_url($sid,$tok),'reused'=>false],200);
  });
 }
 static function embed_url($sid,$tok){ return add_query_arg(['shimi_pay'=>$sid,'token'=>$tok],home_url('/')); }

 /* A reused session cannot return the original token (only its hash is stored): issue a fresh token and replace the hash. */
 static function rotate_token(&$rec){ $tok=bin2hex(random_bytes(16)); $rec['th']=hash('sha256',$tok); return $tok; }

 static function auth_session($sid,$tok){ $rec=self::load($sid); if(!$rec||!is_string($tok)||!hash_equals($rec['th'],hash('sha256',$tok)))return null; return $rec; }

 /* ---------- 2. embeddable page ---------- */
 static function render_page(){
  if(!isset($_GET['shimi_pay']))return;
  $sid=(string)$_GET['shimi_pay']; $tok=(string)($_GET['token']??'');
  $rec=self::auth_session($sid,$tok);
  nocache_headers(); header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff');
  $fa=self::shem_origin(); $pp="https://*.paypal.com https://www.paypalobjects.com";
  header("Content-Security-Policy: default-src 'none'; base-uri 'none'; form-action 'none'; script-src 'self' 'unsafe-inline' $pp; frame-src $pp; connect-src 'self' $pp; img-src data: $pp; style-src 'unsafe-inline'; frame-ancestors ".($fa!==''?$fa:"'none'"));
  header('Content-Type: text/html; charset=utf-8');
  if(!$rec){ status_header(404); echo '<!doctype html><meta charset="utf-8"><body dir="rtl" style="font-family:Arial">קישור התשלום אינו תקף.'; exit; }
  $paid=in_array($rec['state'],['paid','callback_ok','callback_pending'],true);
  $cfg=['api'=>esc_url_raw(rest_url(self::NS)),'sid'=>$sid,'token'=>$tok,'parent'=>$fa,'cid'=>(self::pp_ready()?SHIMI_PAYPAL_CLIENT_ID:''),'paid'=>$paid];
  echo '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>תשלום מאובטח</title>';
  echo '<style>body{font-family:Arial,Helvetica,sans-serif;margin:0;padding:16px;color:#111}h2{margin:.2em 0}.amt{font-size:1.3rem;font-weight:700;margin:.6em 0}.msg{margin:1em 0;padding:.8em;border-radius:6px}.ok{background:#e8f5e9}.err{background:#fdecea}</style></head><body>';
  echo '<h2>תשלום מאובטח</h2><p>פרטי האשראי אינם נשמרים במערכת.</p><div class="amt">סכום לתשלום: '.esc_html(number_format($rec['amountCents']/100,2)).' ₪</div><div id="btns"></div><div id="msg"></div>';
  echo '<script>window.SHIMI_PAY='.wp_json_encode($cfg).';</script>';
  echo '<script>'.self::page_js().'</script>';
  if(!$paid&&self::pp_ready()) echo '<script src="https://www.paypal.com/sdk/js?client-id='.esc_attr(SHIMI_PAYPAL_CLIENT_ID).'&amp;currency=ILS&amp;intent=capture&amp;components=buttons&amp;locale=he_IL" onload="shimiInit()"></script>';
  elseif(!$paid) echo '<script>shimiMsg("err","אפשרות התשלום אינה זמינה כרגע.")</script>';
  echo '</body></html>'; exit;
 }
 static function page_js(){ return <<<'JS'
(function(){var C=window.SHIMI_PAY;
function m(c,t){var e=document.getElementById('msg');e.className='msg '+c;e.textContent=t}
window.shimiMsg=m;
function post(p,b){return fetch(C.api+p,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(b)}).then(function(r){return r.json().then(function(j){j._http=r.status;return j})})}
function done(){m('ok','התשלום בוצע. ממשיכים לאישור ההזמנה...');if(C.parent&&window.parent!==window){window.parent.postMessage({source:'shimi-pay',status:'completed',sid:C.sid},C.parent)}}
window.shimiInit=function(){if(typeof paypal==='undefined'){m('err','טעינת PayPal נכשלה.');return}
paypal.Buttons({
 createOrder:function(){return post('/paypal/create',{sid:C.sid,token:C.token}).then(function(d){if(!d.id)throw new Error(d.message||'create failed');return d.id})},
 onApprove:function(d){return post('/paypal/capture',{sid:C.sid,token:C.token,paypalOrderId:d.orderID}).then(function(r){if(r.ok)done();else m('err','התשלום לא הושלם. לא בוצע חיוב או שהתשלום ממתין לאישור.')})},
 onCancel:function(){m('err','התשלום בוטל. לא בוצע חיוב.')},
 onError:function(){m('err','אירעה שגיאה בתשלום. אם בוצע חיוב, ההזמנה תתעדכן אוטומטית.')}
}).render('#btns')};
if(C.paid){m('ok','התשלום בוצע.')}
})();
JS; }

 /* ---------- PayPal REST ---------- */
 static function pp_token(){
  $t=get_transient('shimi_pay_pp_tok'); if(is_string($t)&&$t!=='')return $t;
  $r=wp_remote_post(self::pp_base().'/v1/oauth2/token',['timeout'=>20,'headers'=>['Authorization'=>'Basic '.base64_encode(SHIMI_PAYPAL_CLIENT_ID.':'.SHIMI_PAYPAL_SECRET),'Content-Type'=>'application/x-www-form-urlencoded'],'body'=>'grant_type=client_credentials']);
  if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)return null;
  $j=json_decode(wp_remote_retrieve_body($r),true); if(empty($j['access_token']))return null;
  set_transient('shimi_pay_pp_tok',$j['access_token'],max(60,(int)($j['expires_in']??300)-120)); return $j['access_token'];
 }
 static function pp($method,$path,$body,$reqId){
  $tok=self::pp_token(); if(!$tok)return [0,['message'=>'paypal auth failed']];
  $h=['Authorization'=>'Bearer '.$tok,'Content-Type'=>'application/json','PayPal-Request-Id'=>$reqId,'Prefer'=>'return=representation'];
  $r=wp_remote_request(self::pp_base().$path,['method'=>$method,'timeout'=>30,'headers'=>$h,'body'=>$body===null?null:wp_json_encode($body)]);
  if(is_wp_error($r))return [0,['message'=>$r->get_error_message()]];
  return [(int)wp_remote_retrieve_response_code($r),json_decode(wp_remote_retrieve_body($r),true)?:[]];
 }

 /* ---------- 3. create PayPal order (amount comes from the server-side session, never from the browser) ---------- */
 static function pp_create(WP_REST_Request $r){
  if(!self::pp_ready())return self::fail(503,'not_configured','PayPal not configured');
  $p=$r->get_json_params()?:[]; $rec=self::auth_session((string)($p['sid']??''),(string)($p['token']??''));
  if(!$rec)return self::fail(404,'session','Invalid session');
  return self::locked($rec['id'],function() use($rec){
   $rec=self::load($rec['id']);
   if($rec['state']!=='created'&&$rec['state']!=='pp_created')return self::fail(409,'state','Session is not payable');
   if($rec['ppOrderId']!=='')return new WP_REST_Response(['id'=>$rec['ppOrderId'],'reused'=>true],200);
   $body=['intent'=>'CAPTURE','purchase_units'=>[['reference_id'=>'shem-'.$rec['orderId'],'custom_id'=>$rec['id'],'invoice_id'=>'SHEM-'.$rec['orderId'].'-'.substr($rec['id'],0,6),'description'=>'SHEM order '.$rec['orderId'],'amount'=>['currency_code'=>'ILS','value'=>self::cents_to_str($rec['amountCents'])]]],'payment_source'=>['paypal'=>['experience_context'=>['shipping_preference'=>'NO_SHIPPING','user_action'=>'PAY_NOW','brand_name'=>'SHEM','locale'=>'he-IL']]]];
   [$st,$j]=self::pp('POST','/v2/checkout/orders',$body,'shimi-create-'.$rec['id']);
   if($st<200||$st>=300||empty($j['id']))return self::fail(502,'paypal_create','PayPal order creation failed ('.$st.')');
   $rec['ppOrderId']=$j['id']; $rec['state']='pp_created'; self::save($rec);
   return new WP_REST_Response(['id'=>$j['id']],200);
  });
 }

 /* ---------- 4. capture + verification + signed callback ---------- */
 static function pp_capture(WP_REST_Request $r){
  if(!self::pp_ready())return self::fail(503,'not_configured','PayPal not configured');
  $p=$r->get_json_params()?:[]; $rec=self::auth_session((string)($p['sid']??''),(string)($p['token']??''));
  if(!$rec)return self::fail(404,'session','Invalid session');
  $ppId=(string)($p['paypalOrderId']??'');
  return self::locked($rec['id'],function() use($rec,$ppId){
   $rec=self::load($rec['id']);
   if(in_array($rec['state'],['paid','callback_ok','callback_pending'],true))return new WP_REST_Response(['ok'=>true,'duplicate'=>true],200);
   if($rec['state']!=='pp_created'||$rec['ppOrderId']===''||!hash_equals($rec['ppOrderId'],$ppId))return self::fail(409,'order_mismatch','PayPal order does not belong to this session');
   [$st,$j]=self::pp('POST','/v2/checkout/orders/'.rawurlencode($ppId).'/capture',(object)[],'shimi-capture-'.$rec['id']);
   if($st<200||$st>=300)return self::fail(502,'paypal_capture','PayPal capture failed ('.$st.')');
   if(!self::finalize($rec,$j))return self::fail(409,'not_completed','Payment not completed or does not match');
   self::send_callback($rec['id']);
   return new WP_REST_Response(['ok'=>true],200);
  });
 }

 /* verify a PayPal order payload against the server-side session; on success mark paid (callback is sent by the caller) */
 static function finalize(&$rec,$j){
   $pu=$j['purchase_units'][0]??[]; $cap=$pu['payments']['captures'][0]??[];
   $ok=($j['status']??'')==='COMPLETED'&&($cap['status']??'')==='COMPLETED'&&($cap['amount']['currency_code']??'')==='ILS'&&($cap['amount']['value']??'')===self::cents_to_str($rec['amountCents'])&&($pu['custom_id']??($cap['custom_id']??''))===$rec['id']&&!empty($cap['id']);
   if(!$ok){ $rec['cbError']='capture not verified: status='.($cap['status']??($j['status']??'?')); self::save($rec); return false;}
   $src=$j['payment_source']??[]; $payer=$j['payer']['name']??[]; $rec['txn']=(string)$cap['id'];
   if(isset($src['card'])&&is_array($src['card'])){ $rec['method']='card'; $rec['last4']=preg_match('/^\d{4}$/',(string)($src['card']['last_digits']??''))?$src['card']['last_digits']:''; $rec['brand']=mb_substr((string)($src['card']['brand']??''),0,24); $rec['holder']=mb_substr((string)($src['card']['name']??''),0,80); $rec['auth']=mb_substr((string)($cap['processor_response']['response_code']??''),0,64); }
   else { $rec['method']='paypal'; $rec['holder']=trim(($payer['given_name']??'').' '.($payer['surname']??'')); }
   $rec['state']='paid'; self::save($rec);
   return true;
 }

 /* ---------- 5. paid callback (SHIMI -> SHEM), retried until accepted ---------- */
 static function send_callback($sid){
  $rec=self::load($sid); if(!$rec||!in_array($rec['state'],['paid','callback_pending'],true))return;
  $secret=self::secret(); $url=defined('SHIMI_SHEM_CALLBACK_URL')?SHIMI_SHEM_CALLBACK_URL:''; if($secret===null||$url==='')return;
  $body=wp_json_encode(['orderId'=>$rec['orderId'],'status'=>'paid','currency'=>'ILS','amountCents'=>$rec['amountCents'],'transactionId'=>$rec['txn'],'paymentDetails'=>(($rec['method']??'paypal')==='card'?['method'=>'card','last4'=>(string)($rec['last4']??''),'cardholderName'=>mb_substr($rec['holder']??'',0,80),'brand'=>(string)($rec['brand']??''),'authorizationCode'=>(string)($rec['auth']??'')]:['method'=>'paypal','cardholderName'=>mb_substr($rec['holder']??'',0,80)])]);
  $ts=(string)time(); $sig=hash_hmac('sha256',$ts.'.'.$body,$secret);
  $headers=['Content-Type'=>'application/json','x-shem-timestamp'=>$ts,'x-shem-signature'=>$sig];
  if(defined('SHIMI_SHEM_CALLBACK_HOST'))$headers['Host']=SHIMI_SHEM_CALLBACK_HOST;
  $r=wp_remote_post($url,['timeout'=>20,'headers'=>$headers,'body'=>$body]);
  $rec['cbAttempts']=(int)$rec['cbAttempts']+1;
  if(is_wp_error($r)){ $code=0; $err=$r->get_error_message(); } else { $code=(int)wp_remote_retrieve_response_code($r); $err='HTTP '.$code.' '.mb_substr((string)wp_remote_retrieve_body($r),0,200); }
  if($code>=200&&$code<300){ $rec['state']='callback_ok'; $rec['cbError']=''; }
  elseif($code>=400&&$code<500&&$code!==408&&$code!==429){ $rec['state']='callback_rejected'; $rec['cbError']=$err; /* permanent: needs a human, no retry */ }
  else { $rec['state']='callback_pending'; $rec['cbError']=$err; if($rec['cbAttempts']<self::MAX_CB)wp_schedule_single_event(time()+min(3600,60*(2**$rec['cbAttempts'])),'shimi_pay_retry',[$sid]); }
  self::save($rec);
 }
 /* ---------- 6. reconcile: payments that were approved/captured at PayPal but never reached the callback (browser closed, crash).
    Polling, so it needs no public webhook URL. Only sessions still in pp_created, older than 15 minutes and younger than 7 days. ---------- */
 static function reconcile(){
  global $wpdb; $out=[];
  $rows=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like(self::OPT).'%'));
  foreach($rows as $n){
   $sid=substr($n,strlen(self::OPT)); $rec=self::load($sid);
   if(!$rec||$rec['state']!=='pp_created'||$rec['ppOrderId']===''||$rec['created']>time()-900||$rec['created']<time()-7*86400)continue;
   $res=self::locked($sid,function() use($sid){
    $rec=self::load($sid); if(!$rec||$rec['state']!=='pp_created')return 'skip';
    [$st,$j]=self::pp('GET','/v2/checkout/orders/'.rawurlencode($rec['ppOrderId']),null,'shimi-rec-get-'.$sid.'-'.time());
    if($st<200||$st>=300)return 'paypal_get_'.$st;
    $ps=$j['status']??'';
    if($ps==='APPROVED'){ [$st,$j]=self::pp('POST','/v2/checkout/orders/'.rawurlencode($rec['ppOrderId']).'/capture',(object)[],'shimi-capture-'.$sid); if($st<200||$st>=300)return 'capture_'.$st; }
    elseif($ps!=='COMPLETED')return 'open_'.$ps;
    if(!self::finalize($rec,$j))return 'not_verified';
    self::send_callback($sid); return 'reconciled';
   });
   $out[$sid]=is_string($res)?$res:'locked';
  }
  return $out;
 }
}
/* Staging only: containers have no direct internet. When SHIMI_HTTP_PROXY is defined, PayPal requests (and only PayPal) go through it,
   trusting SHIMI_CA_BUNDLE. Not defined on a real host. */
add_action('http_api_curl',function($h,$args,$url){
 if(!defined('SHIMI_HTTP_PROXY'))return;
 $host=strtolower((string)parse_url($url,PHP_URL_HOST)); if(!($host==='paypal.com'||substr($host,-11)==='.paypal.com'))return;
 curl_setopt($h,CURLOPT_PROXY,SHIMI_HTTP_PROXY);
 if(defined('SHIMI_CA_BUNDLE'))curl_setopt($h,CURLOPT_CAINFO,SHIMI_CA_BUNDLE);
},10,3);
add_action('rest_api_init',['Shimi_Pay','routes']);
add_action('init',['Shimi_Pay','render_page'],1);
add_action('shimi_pay_retry',['Shimi_Pay','send_callback']);
add_action('shimi_pay_reconcile',['Shimi_Pay','reconcile']);
add_action('init',function(){ if(!wp_next_scheduled('shimi_pay_reconcile'))wp_schedule_event(time()+300,'hourly','shimi_pay_reconcile'); });

if (defined('WP_CLI') && WP_CLI) {
 WP_CLI::add_command('shimi-pay', function($args){
  global $wpdb; $sub=$args[0]??'status';
  if($sub==='reconcile'){ foreach(Shimi_Pay::reconcile() as $k=>$v)WP_CLI::line($k.' '.$v); return; }
  $rows=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like(Shimi_Pay::OPT).'%'));
  foreach($rows as $n){ $sid=substr($n,strlen(Shimi_Pay::OPT)); $rec=Shimi_Pay::load($sid); if(!$rec)continue;
   if($sub==='resend'&&in_array($rec['state'],['paid','callback_pending'],true)){ Shimi_Pay::send_callback($sid); $rec=Shimi_Pay::load($sid); }
   WP_CLI::line($sid.' order='.$rec['orderId'].' state='.$rec['state'].' attempts='.$rec['cbAttempts'].($rec['cbError']?' err='.$rec['cbError']:'')); }
 });
}
