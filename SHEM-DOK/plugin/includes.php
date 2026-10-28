<?php
/**
 * SHEM DOK core. Version 1.1.0.
 * Issued documents are immutable. Corrections are made only by a new linked credit document.
 */
if (!defined('ABSPATH')) exit;

class ShemDokHttp extends Exception {}
/* Order data that no retry can fix (unmapped gateway, foreign currency, special fees, VAT mismatch). Alert at once, do not retry. */
class ShemDokPermanent extends Exception {}

final class ShemDok {
 const VERSION = '1.1.0';
 const DB_VERSION = 2;
 const GATEWAY = 'shem_pay';

 /* ---------- storage ---------- */
 static function table(){global $wpdb;return $wpdb->prefix.'shem_dok';}
 static function audit_table(){return self::table().'_audit';}

 static function install(){
  global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';
  $t=self::table();$cs=$wpdb->get_charset_collate();
  dbDelta("CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_key varchar(191) NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  version int(10) unsigned NOT NULL DEFAULT 1,
  deleted tinyint(4) NOT NULL DEFAULT 0,
  doc_type varchar(16) NOT NULL DEFAULT 'invoice',
  ref_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'issued',
  gross_cents bigint(20) NOT NULL DEFAULT 0,
  payload longtext NULL,
  pdf longblob NULL,
  pdf_sha256 char(64) NULL,
  pdf_size int(10) unsigned NULL,
  emailed_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_key (source_key),
  KEY order_id (order_id),
  KEY ref_id (ref_id)
) ENGINE=InnoDB AUTO_INCREMENT=7001 $cs;");
  if((int)$wpdb->get_var("SELECT COUNT(*) FROM $t")==0)$wpdb->query("ALTER TABLE $t AUTO_INCREMENT=7001");
  $seq=$t.'_sequence';
  dbDelta("CREATE TABLE $seq (
  id tinyint(3) unsigned NOT NULL,
  next_number bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id)
) ENGINE=InnoDB $cs;");
  $start=max(7001,(int)$wpdb->get_var("SELECT MAX(id) FROM $t")+1);
  $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $seq (id,next_number) VALUES (1,%d)",$start));
  $a=self::audit_table();
  dbDelta("CREATE TABLE $a (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  doc_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(40) NOT NULL,
  actor bigint(20) unsigned NOT NULL DEFAULT 0,
  detail text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY doc_id (doc_id),
  KEY order_id (order_id)
) ENGINE=InnoDB $cs;");
  self::backfill();
  update_option('shem_dok_db',self::DB_VERSION,false);
 }

 /* Upgrade from 1.0.x: documents issued before the integrity columns existed get their hash, size and gross amount computed once.
    Idempotent and never touches the document itself (payload and pdf stay as they were). */
 static function backfill(){
  global $wpdb;$t=self::table();
  $wpdb->query("UPDATE $t SET pdf_sha256=SHA2(pdf,256),pdf_size=LENGTH(pdf) WHERE pdf IS NOT NULL AND pdf_sha256 IS NULL");
  $last=0;
  do{
   $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT id,payload FROM $t WHERE id>%d AND gross_cents=0 AND payload IS NOT NULL AND doc_type='invoice' AND deleted=0 ORDER BY id LIMIT 100",$last),ARRAY_A);
   foreach($rows as $r){
    $last=(int)$r['id'];
    $p=json_decode((string)$r['payload'],true);if(!is_array($p))continue;
    try{$g=self::totals($p)['gross'];}catch(Throwable $e){continue;}
    if($g>0)$wpdb->update($t,['gross_cents'=>$g],['id'=>$last]);
   }
  }while(count($rows)===100);
 }
 static function maybe_upgrade(){if((int)get_option('shem_dok_db',0)<self::DB_VERSION)self::install();}

 static function audit($doc_id,$event,$detail=[],$order_id=0){
  global $wpdb;
  $wpdb->insert(self::audit_table(),['doc_id'=>(int)$doc_id,'order_id'=>(int)$order_id,'event'=>substr($event,0,40),'actor'=>(int)get_current_user_id(),'detail'=>wp_json_encode($detail,JSON_UNESCAPED_UNICODE),'created_at'=>current_time('mysql',true)]);
 }

 /* ---------- settings ---------- */
 static function defaults(){return ['business'=>'SHEM מותגים','businessId'=>'516067873','address'=>'דרך מצדה 259 באר שבע','phone'=>'052-958-6766','email'=>'','title'=>'חשבונית עסקה','vat'=>18,'supply1'=>"תנאי משלוח:\nשליח עד הבית 5-7 ימי עסקים 49₪\nשליח עד הבית 1 יום עסקים 199₪",'supply2'=>"החלפות והחזרות: תוך 14 ימים מיום קבלת המשלוח \nעל ידי איסוף שליח עד הבית."];}
 static function base_settings(){return ['defaults'=>self::defaults(),'auto'=>false,'email_customer'=>false,'email_subject'=>'חשבונית עסקה מספר {number} להזמנה {order}','email_body'=>"שלום {name},\nמצורפת חשבונית עסקה מספר {number} להזמנה {order}.",'allow_credit'=>false,'thankyou'=>true,'thankyou_heading'=>'לקוח יקר, תודה שרכשת בSHEM מותגים','thankyou_sub'=>'הזמנתך נקלטה, והתשלום בוצע!','thankyou_title'=>'חשבונית עסקה','thankyou_button'=>'הורדת חשבונית עסקה','thankyou_pending'=>'החשבונית בהפקה. הכפתור יופיע כאן בעוד רגע.','alert_email'=>''];}
 static function settings(){return array_merge(self::base_settings(),get_option('shem_dok_settings',[]));}
 static function allowed(){return current_user_can('manage_woocommerce');}
 static function engine_ready(){return is_file(__DIR__.'/vendor/autoload.php')&&extension_loaded('gd')&&extension_loaded('mbstring');}

 /* ---------- REST ---------- */
 static function routes(){
  $map=[
   'state'=>'GET','settings'=>'POST','documents'=>'GET','issue'=>'POST',
   'document/(?P<id>\d+)'=>'GET','document/(?P<id>\d+)/credit'=>'POST',
   'document/(?P<id>\d+)/save'=>'POST','document/(?P<id>\d+)/delete'=>'POST',
   'order/(?P<id>\d+)'=>'GET','order/(?P<id>\d+)/retry'=>'POST',
   'alerts'=>'GET','alerts/clear'=>'POST','reconcile'=>'GET',
  ];
  foreach($map as $route=>$method){
   register_rest_route('shem-dok/v1','/'.$route,['methods'=>$method,'permission_callback'=>[self::class,'allowed'],'callback'=>function($r)use($route){
    try{return self::request($route,$r);}
    catch(ShemDokHttp $e){return new WP_Error('shem_dok_error',$e->getMessage(),['status'=>$e->getCode()?:400]);}
    catch(Throwable $e){return new WP_Error('shem_dok_error',$e->getMessage(),['status'=>400]);}
   }]);
  }
 }

 static function request($route,$r){
  global $wpdb;$t=self::table();$body=$r->get_json_params()??[];
  switch($route){
   case 'state': return ['settings'=>self::settings(),'health'=>self::health(),'pdfReady'=>self::engine_ready(),'woocommerce'=>function_exists('wc_get_order'),'alerts'=>self::alerts()];
   case 'settings': return self::save_settings($body);
   case 'documents':
    $page=max(1,(int)$r->get_param('page'));
    $rows=$wpdb->get_results($wpdb->prepare("SELECT id,order_id,version,doc_type,ref_id,status,gross_cents,payload,created_at,updated_at FROM $t WHERE deleted=0 ORDER BY id DESC LIMIT 30 OFFSET %d",($page-1)*30),ARRAY_A);
    return ['rows'=>array_map(function($row){$p=json_decode((string)$row['payload'],true)?:[];unset($row['payload']);$row['customer']=$p['customer']??'';$row['total']=(int)$row['gross_cents']?:self::totals($p)['gross'];return $row;},$rows),'page'=>$page,'total'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE deleted=0")];
   case 'issue': return self::issue_request($body);
   case 'alerts': return ['alerts'=>self::alerts()];
   case 'alerts/clear': self::clear_alerts(isset($body['id'])?(string)$body['id']:null);return ['alerts'=>self::alerts()];
   case 'reconcile': return ['missing'=>self::reconcile()];
  }
  $id=(int)$r['id'];
  if($route==='order/(?P<id>\d+)'){
   if(!function_exists('wc_get_order'))throw new Exception('WooCommerce אינו פעיל');
   $order=wc_get_order($id);if(!$order)throw new Exception('הזמנה לא נמצאה');
   if(!$order->is_paid()||(!$order->get_transaction_id()&&!self::is_manual($order)))throw new Exception('להזמנה עדיין אין תשלום מאומת ואסמכתה');
   return self::from_order($order,true);
  }
  if($route==='order/(?P<id>\d+)/retry'){
   if(!function_exists('wc_get_order'))throw new Exception('WooCommerce אינו פעיל');
   $order=wc_get_order($id);if(!$order)throw new Exception('הזמנה לא נמצאה');
   $res=self::issue_for_order($order);self::audit($res['id'],'manual_retry',[],$id);return $res;
  }
  if(str_ends_with($route,'/save')||str_ends_with($route,'/delete'))
   throw new ShemDokHttp('מסמך שהונפק אינו ניתן לעריכה או למחיקה. לתיקון יש להפיק מסמך זיכוי או מסמך חדש.',410);
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d AND deleted=0",$id),ARRAY_A);
  if(!$row)throw new ShemDokHttp('המסמך אינו זמין',404);
  if(str_ends_with($route,'/credit'))return self::credit($row,$body);
  return ['id'=>$id,'version'=>(int)$row['version'],'docType'=>$row['doc_type'],'status'=>$row['status'],'refId'=>(int)$row['ref_id'],'downloadUrl'=>self::download_url($id),'document'=>json_decode((string)$row['payload'],true)];
 }

 static function save_settings($body){
  $s=self::settings();$defs=[];
  foreach(($body['defaults']??[]) as $key=>$value){
   if(!in_array($key,self::default_keys(),true)&&!preg_match('/^items\.(?:[0-9]|[1-9][0-9]|1[0-9]{2})\.(name|sku|qty|price)$/',$key))continue;
   $defs[$key]=($key==='vat'||preg_match('/^items\.\d+\.(qty|price)$/',$key))?self::decimal($value,$key==='vat'?100:10000000):sanitize_textarea_field($value);
  }
  $s['defaults']=$defs;
  $s['auto']=!empty($body['auto']);
  foreach(['email_customer','allow_credit','thankyou'] as $k)if(array_key_exists($k,$body))$s[$k]=!empty($body[$k]);
  foreach(['email_subject','email_body','thankyou_heading','thankyou_sub','thankyou_title','thankyou_button','thankyou_pending'] as $k)if(isset($body[$k]))$s[$k]=sanitize_textarea_field(mb_substr((string)$body[$k],0,500));
  if(isset($body['alert_email'])){$e=trim((string)$body['alert_email']);if($e!==''&&!is_email($e))throw new Exception('כתובת מייל להתראות אינה תקינה');$s['alert_email']=$e;}
  if(($s['auto']||$s['email_customer'])&&(!self::engine_ready()||!function_exists('wc_get_order')))throw new Exception('יש להשלים התקנת מנוע PDF לפני הפעלת אוטומציה');
  if(!update_option('shem_dok_settings',$s,false)&&get_option('shem_dok_settings')!==$s)throw new Exception('שמירת ההגדרות נכשלה. נסו שוב.');
  self::audit(0,'settings',['auto'=>$s['auto'],'email_customer'=>$s['email_customer'],'allow_credit'=>$s['allow_credit']]);
  return $s;
 }

 static function health(){
  global $wpdb;
  $mp=(int)$wpdb->get_var("SELECT @@max_allowed_packet");
  return ['version'=>self::VERSION,'php'=>PHP_VERSION,'engine'=>self::engine_ready(),'gd'=>extension_loaded('gd'),'mbstring'=>extension_loaded('mbstring'),'memoryLimit'=>ini_get('memory_limit'),'maxAllowedPacket'=>$mp,'actionScheduler'=>function_exists('as_schedule_single_action'),'wpCronDisabled'=>defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON,'bridgeConfigured'=>ShemDok_Bridge::secret()!==null,'dbVersion'=>(int)get_option('shem_dok_db',0),'nextNumber'=>(int)$wpdb->get_var('SELECT next_number FROM '.self::table().'_sequence WHERE id=1')];
 }

 /* ---------- validation helpers ---------- */
 static function default_keys(){return array_merge(array_keys(self::defaults()),['orderNumber','customer','customerPhone','customerEmail','customerAddress','orderDate','method','holder','brand','last4','installments','reference','paypalEmail','bank','branch','account','transferDate','receivedDate','methodLabel','shipping','discount','description']);}
 const METHODS=['card','paypal','transfer','cash','other'];
 static function valid_date($d){return is_string($d)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)&&checkdate((int)substr($d,5,2),(int)substr($d,8,2),(int)substr($d,0,4));}
 static function is_manual($o){return $o&&$o->get_meta('_shem_manual_payment')==='1';}
 static function decimal($v,$max=10000000){if(!is_numeric($v)||!is_finite((float)$v)||(float)$v<0||(float)$v>$max)throw new Exception('ערך מספרי אינו תקין');return round((float)$v,2);}
 static function cents($n){return (int)round((float)$n*100);}

 static function clean($input){
  $p=[];
  foreach(array_merge(self::default_keys(),['orderNumber']) as $key){
   if(in_array($key,['vat','shipping','discount'],true))$p[$key]=self::decimal($input[$key]??0,$key==='vat'?100:10000000);
   else $p[$key]=sanitize_textarea_field($input[$key]??'');
  }
  if(!in_array($p['method'],self::METHODS,true))throw new Exception('יש לבחור אמצעי תשלום');
  $keep=['card'=>['holder','brand','last4','installments'],'paypal'=>['holder','paypalEmail'],'transfer'=>['holder','bank','branch','account','transferDate'],'cash'=>['receivedDate'],'other'=>['methodLabel']][$p['method']];
  foreach(['holder','brand','last4','installments','paypalEmail','bank','branch','account','transferDate','receivedDate','methodLabel'] as $k)if(!in_array($k,$keep,true))$p[$k]='';
  foreach(['bank','methodLabel','holder','brand','reference'] as $k)if(mb_strlen($p[$k])>120)throw new Exception('אחד מפרטי התשלום ארוך מדי');
  if($p['branch']!==''&&!preg_match('/^[0-9\-]{1,20}$/',$p['branch']))throw new Exception('מספר סניף אינו תקין');
  if($p['account']!==''&&!preg_match('/^[0-9\-]{1,20}$/',$p['account']))throw new Exception('מספר חשבון אינו תקין');
  foreach(['transferDate','receivedDate'] as $k)if($p[$k]!==''&&!self::valid_date($p[$k]))throw new Exception('תאריך התשלום אינו תקין');
  if($p['method']==='other'&&trim($p['methodLabel'])==='')throw new Exception('יש לתאר את אמצעי התשלום');
  if($p['last4']!==''&&!preg_match('/^\d{4}$/',$p['last4']))throw new Exception('יש להזין ארבע ספרות אחרונות בלבד');
  if($p['installments']!==''&&(!ctype_digit($p['installments'])||(int)$p['installments']<1||(int)$p['installments']>60))throw new Exception('מספר תשלומים אינו תקין');
  if(!self::valid_date($p['orderDate']))throw new Exception('יש להזין תאריך');
  foreach(['email','customerEmail','paypalEmail'] as $key)if($p[$key]!==''&&!is_email($p[$key]))throw new Exception('כתובת המייל אינה תקינה');
  if(!$p['business']||!$p['businessId']||!$p['customer']||!$p['address'])throw new Exception('יש להשלים שם ומספר עסק, כתובת עסק ושם לקוח');
  $items=$input['items']??[];
  if(!is_array($items)||count($items)<1||count($items)>200)throw new Exception('נדרשים 1 עד 200 פריטים');
  $p['items']=[];
  foreach($items as $x){
   $qty=self::decimal($x['qty']??0,10000);if($qty<=0)throw new Exception('כמות חייבת להיות גדולה מאפס');
   $name=sanitize_text_field($x['name']??'');if(!$name)throw new Exception('יש להזין תיאור פריט');
   if(isset($x['lineGross'])&&(!is_numeric($x['lineGross'])||(float)$x['lineGross']!==(float)(int)$x['lineGross']))throw new Exception('סכום שורה חייב להיות באגורות שלמות');
   $p['items'][]=['name'=>$name,'sku'=>sanitize_text_field($x['sku']??''),'qty'=>$qty,'price'=>self::decimal($x['price']??0),'lineGross'=>isset($x['lineGross'])?(int)self::decimal($x['lineGross'],1000000000):null];
  }
  if(isset($input['taxCents'])){
   if(!is_numeric($input['taxCents'])||(float)$input['taxCents']!==(float)(int)$input['taxCents']||(float)$input['taxCents']<0)throw new Exception('מס חייב להיות באגורות שלמות');
   $p['taxCents']=(int)$input['taxCents'];
  }
  self::totals($p);
  return $p;
 }

 static function totals($p){
  $subtotal=0;foreach($p['items']??[] as $x)$subtotal+=isset($x['lineGross'])?(int)$x['lineGross']:(int)round(self::cents($x['price'])*(float)$x['qty']);
  $discount=self::cents($p['discount']??0);if($discount>$subtotal)throw new Exception('הנחה גבוהה מסכום הפריטים');
  $shipping=self::cents($p['shipping']??0);$gross=$subtotal-$discount+$shipping;$vat=(float)($p['vat']??18);
  $tax=(int)round(($subtotal-$discount)*$vat/(100+$vat))+(int)round($shipping*$vat/(100+$vat));
  if(isset($p['taxCents'])){$tax=(int)$p['taxCents'];if($tax<0||$tax>$gross)throw new Exception('סכום המע״מ אינו תקין');}
  return compact('subtotal','discount','shipping','gross','tax')+['net'=>$gross-$tax];
 }

 /* ---------- issuing ---------- */
 static function issue_request($body){
  if(!wp_is_uuid($body['requestId']??''))throw new Exception('מזהה הפקה לא תקין');
  if(!empty($body['document']['sourceOrderId'])){
   $oid=absint($body['document']['sourceOrderId']);
   $o=function_exists('wc_get_order')?wc_get_order($oid):false;
   if(!$o||!$o->is_paid()||(!$o->get_transaction_id()&&!self::is_manual($o)))throw new Exception('ההזמנה אינה משולמת');
   self::from_order($o,true);
   $p=self::clean($body['document']);$p['sourceOrderId']=$oid;
   if(self::totals($p)['tax']!==self::cents($o->get_total_tax()))throw new Exception('המע״מ במסמך חייב להתאים להזמנה');
   if(self::totals($p)['gross']!==self::cents($o->get_total()))throw new Exception('סכום המסמך חייב להתאים להזמנה');
   $res=self::issue($p,'woo:'.$oid,$oid);self::audit($res['id'],empty($res['existing'])?'issued_manual_order':'issue_existing',[],$oid);return $res;
  }
  $res=self::issue(self::clean($body['document']??[]),'manual:'.$body['requestId']);
  self::audit($res['id'],empty($res['existing'])?'issued_manual':'issue_existing');
  return $res;
 }

 static function issue($p,$key,$order_id=0,$extra=[]){
  global $wpdb;$t=self::table();
  $lock='sd:'.substr(hash('sha256',$key),0,50);
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1)throw new Exception('הפקה אחרת בתהליך. נסו שוב.');
  try{
   $old=$wpdb->get_row($wpdb->prepare("SELECT id,version,deleted FROM $t WHERE source_key=%s",$key),ARRAY_A);
   if(!empty($wpdb->last_error))throw new Exception('קריאת הארכיון נכשלה');
   if($old){
    if($old['deleted'])throw new Exception('המסמך להזמנה זו נמחק ולא יופק שוב אוטומטית');
    return ['id'=>(int)$old['id'],'version'=>(int)$old['version'],'existing'=>true,'downloadUrl'=>self::download_url((int)$old['id'])];
   }
   if($wpdb->query('START TRANSACTION')===false)throw new Exception('פתיחת פעולת שמירה נכשלה');
   $id=(int)$wpdb->get_var("SELECT next_number FROM {$t}_sequence WHERE id=1 FOR UPDATE");
   if($id<7001)throw new Exception('טבלת מספור חסרה. יש להפעיל מחדש את התוסף');
   $now=current_time('mysql',true);
   $gross=self::totals($p)['gross'];
   if(!$wpdb->insert($t,['id'=>$id,'source_key'=>$key,'order_id'=>$order_id,'doc_type'=>$extra['doc_type']??'invoice','ref_id'=>$extra['ref_id']??0,'gross_cents'=>$gross,'payload'=>wp_json_encode($p),'created_at'=>$now,'updated_at'=>$now]))throw new Exception('שמירת המסמך נכשלה');
   $pdf=self::pdf($p,$id);
   self::assert_pdf_fits($pdf);
   if(!$wpdb->update($t,['pdf'=>$pdf,'pdf_sha256'=>hash('sha256',$pdf),'pdf_size'=>strlen($pdf)],['id'=>$id]))throw new Exception('שמירת PDF נכשלה');
   if(!$wpdb->update($t.'_sequence',['next_number'=>$id+1],['id'=>1]))throw new Exception('עדכון המספור נכשל');
   if($wpdb->query('COMMIT')===false)throw new Exception('אישור שמירת המסמך נכשל');
   return ['id'=>$id,'version'=>1,'document'=>$p,'downloadUrl'=>self::download_url($id)];
  }catch(Throwable $e){
   $wpdb->query('ROLLBACK');throw $e;
  }finally{
   $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
  }
 }

 static function assert_pdf_fits($pdf){
  global $wpdb;$mp=(int)$wpdb->get_var('SELECT @@max_allowed_packet');
  if($mp>0&&strlen($pdf)*2.2>$mp)throw new Exception('קובץ ה-PDF גדול מדי עבור הגדרת max_allowed_packet של בסיס הנתונים');
 }

 /* ---------- WooCommerce mapping ---------- */
 static function method_for_order($o){
  $gateway=$o->get_payment_method();
  if($gateway===self::GATEWAY){
   if($o->get_meta('_shem_pay_verified')!=='1')throw new ShemDokPermanent('התשלום לא אומת מחתימת SHIMI');
   $m=$o->get_meta('_shem_pay_method');
   if(!in_array($m,['card','paypal'],true))throw new ShemDokPermanent('אמצעי התשלום מ-SHIMI אינו אשראי או PayPal');
   return $m;
  }
  $methods=apply_filters('shem_dok_gateway_methods',['stripe'=>'card','woocommerce_payments'=>'card','paypal'=>'paypal','ppcp-gateway'=>'paypal'],$o);
  $method=$methods[$gateway]??null;
  if(!in_array($method,['card','paypal'],true))throw new ShemDokPermanent('אמצעי התשלום אינו ממופה לאשראי או PayPal');
  return $method;
 }

 static function from_order($o,$manual=false){
  if($o->get_currency()!=='ILS')throw new ShemDokPermanent('המערכת מוגדרת לשקלים בלבד');
  $manual=$manual&&self::is_manual($o);
  $method=$manual?'other':self::method_for_order($o);
  $p=array_merge(self::defaults(),self::settings()['defaults'],[
   'customer'=>trim($o->get_billing_first_name().' '.$o->get_billing_last_name()),
   'customerPhone'=>$o->get_billing_phone(),'customerEmail'=>$o->get_billing_email(),
   'customerAddress'=>trim($o->get_billing_address_1().' '.$o->get_billing_address_2().', '.$o->get_billing_city()),
   'orderDate'=>$o->get_date_created()->date('Y-m-d'),'sourceOrderId'=>$o->get_id(),'orderNumber'=>(string)$o->get_order_number(),
   'method'=>$method,'methodLabel'=>$manual?'תשלום ידני':'','holder'=>'','brand'=>'','last4'=>'','installments'=>'','paypalEmail'=>'','bank'=>'','branch'=>'','account'=>'','transferDate'=>'','receivedDate'=>'','reference'=>$o->get_transaction_id(),
   'discount'=>0,'shipping'=>(float)$o->get_shipping_total()+(float)$o->get_shipping_tax(),'items'=>[],
  ]);
  if($o->get_payment_method()===self::GATEWAY){
   $p['holder']=(string)$o->get_meta('_shem_pay_holder');
   if($method==='card'){
    $p['brand']=(string)$o->get_meta('_shem_pay_brand');$p['last4']=(string)$o->get_meta('_shem_pay_last4');$p['installments']=(string)$o->get_meta('_shem_pay_installments');
   }
   $auth=(string)$o->get_meta('_shem_pay_auth');
   if($auth!=='')$p['reference']=$o->get_transaction_id().' | אישור '.$auth;
  }
  $discount=0;$expectedTax=0;
  foreach($o->get_items() as $item){
   $sub=self::cents($item->get_subtotal())+self::cents($item->get_subtotal_tax());
   $after=self::cents($item->get_total())+self::cents($item->get_total_tax());
   $discount+=$sub-$after;
   $expectedTax+=(int)round($after*(float)$p['vat']/(100+(float)$p['vat']));
   $product=$item->get_product();
   $meta=wc_display_item_meta($item,['echo'=>false,'autop'=>false,'separator'=>', ']);
   $p['items'][]=['name'=>$item->get_name().($meta?' - '.wp_strip_all_tags($meta):''),'sku'=>$product?$product->get_sku():'','qty'=>$item->get_quantity(),'price'=>$sub/100/max(0.01,$item->get_quantity()),'lineGross'=>$sub];
  }
  $p['discount']=$discount/100;
  $p['description']=implode(', ',$o->get_coupon_codes());
  $p=apply_filters('shem_dok_order_snapshot',$p,$o);
  $sourceId=$o->get_id();
  $p=self::clean($p);$p['sourceOrderId']=$sourceId;
  if(self::totals($p)['gross']!==self::cents($o->get_total()))throw new ShemDokPermanent('סכום ההזמנה אינו תואם. בדקו עמלות ומסים לפני הפקה.');
  $actualTax=self::cents($o->get_total_tax());
  $expectedTax+=(int)round(self::cents($p['shipping'])*(float)$p['vat']/(100+(float)$p['vat']));
  if(abs($expectedTax-$actualTax)>count($p['items'])+1)throw new ShemDokPermanent('המע״מ במסמך אינו תואם להזמנה. בדקו שיעור מע״מ או פטור.');
  $p['taxCents']=$actualTax;
  self::totals($p);
  return $p;
 }

 static function order_ready($o){
  if(!$o||!$o->is_paid())return false;
  if(!$o->get_transaction_id())return false;
  return true;
 }

 static function issue_for_order($o){
  $res=self::issue(self::from_order($o),'woo:'.$o->get_id(),$o->get_id());
  if(empty($res['existing']))self::audit($res['id'],'issued_auto',['order'=>$o->get_id()],$o->get_id());
  return $res;
 }

 static function paid($order_id,$attempt=0){
  if(!self::settings()['auto']||!function_exists('wc_get_order'))return;
  $o=wc_get_order($order_id);if(!$o||!$o->is_paid())return;
  if(self::is_manual($o))return; // paid by hand: the admin issues it from the editor, never automatically
  if(!$o->get_transaction_id()){
   if($o->get_payment_method()===self::GATEWAY)self::alert('no_txn','להזמנה ששולמה דרך SHIMI אין אסמכתת תשלום',$order_id);
   return;
  }
  try{
   $res=self::issue_for_order($o);
   $o->delete_meta_data('_shem_dok_error');$o->save();
   if(empty($res['existing']))ShemDok_Customer::after_issue((int)$res['id'],$o);
  }catch(Throwable $e){
   $o->update_meta_data('_shem_dok_error',$e->getMessage());$o->save();
   if(function_exists('wc_get_logger'))wc_get_logger()->error('PDF issue failed for order '.(int)$order_id.' attempt '.(int)$attempt.': '.$e->getMessage(),['source'=>'shem-dok']);
   if(!($e instanceof ShemDokPermanent)&&$attempt<3){
    $args=[$order_id,$attempt+1];
    if(function_exists('as_schedule_single_action'))as_schedule_single_action(time()+60*(2**$attempt),'shem_dok_retry',$args,'shem-dok',true);
    elseif(!wp_next_scheduled('shem_dok_retry',$args))wp_schedule_single_event(time()+60*(2**$attempt),'shem_dok_retry',$args);
   }else{
    self::alert('issue_failed',($e instanceof ShemDokPermanent?'הפקת חשבונית עסקה נעצרה (לא ניתן לתקן בניסיון חוזר): ':'הפקת חשבונית עסקה נכשלה לאחר ניסיונות חוזרים: ').$e->getMessage(),$order_id);
    $o->add_order_note('SHEM DOK: הפקת חשבונית עסקה נעצרה. נדרש טיפול ידני.');
   }
  }
 }

 /* ---------- credit / cancellation (new linked document, original is never changed) ---------- */
 static function credit($row,$body){
  global $wpdb;$t=self::table();
  if(!self::settings()['allow_credit'])throw new ShemDokHttp('הנפקת מסמך זיכוי כבויה בהגדרות. נדרש אישור רואה החשבון לפני הפעלה.',403);
  if($row['doc_type']!=='invoice')throw new Exception('ניתן לזכות רק חשבונית עסקה');
  if(!wp_is_uuid($body['requestId']??''))throw new Exception('מזהה הפקה לא תקין');
  $reason=sanitize_text_field($body['reason']??'');if($reason==='')throw new Exception('יש לציין סיבת זיכוי');
  $orig=(int)$row['id'];$lock='sd:orig:'.$orig;
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1)throw new Exception('פעולה אחרת בתהליך. נסו שוב.');
  try{
   $p0=json_decode((string)$row['payload'],true);$t0=self::totals($p0);
   $done=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(gross_cents),0) FROM $t WHERE ref_id=%d AND doc_type='credit' AND deleted=0",$orig));
   $remaining=$t0['gross']-$done;
   $amount=isset($body['amountCents'])?$body['amountCents']:$remaining;
   if(!is_numeric($amount)||(float)$amount!==(float)(int)$amount||(int)$amount<=0)throw new Exception('סכום זיכוי חייב להיות באגורות שלמות וחיובי');
   $amount=(int)$amount;
   if($amount>$remaining)throw new Exception('סכום הזיכוי גבוה מהיתרה הניתנת לזיכוי');
   $full=($amount===$t0['gross']&&$done===0);
   $p=$p0;
   $p['docType']='credit';$p['refDocId']=$orig;$p['reason']=$reason;
   $p['title']=($amount===$remaining)?'ביטול חשבונית עסקה':'זיכוי לחשבונית עסקה';
   if(!$full){
    $p['items']=[['name'=>'זיכוי לחשבונית עסקה מספר '.$orig.' - '.$reason,'sku'=>'','qty'=>1,'price'=>$amount/100,'lineGross'=>$amount]];
    $p['discount']=0;$p['shipping']=0;unset($p['taxCents']);
   }
   $res=self::issue($p,'credit:'.$orig.':'.$body['requestId'],(int)$row['order_id'],['doc_type'=>'credit','ref_id'=>$orig]);
   if(empty($res['existing'])){
    if($amount===$remaining)$wpdb->update($t,['status'=>'cancelled'],['id'=>$orig]);
    self::audit($res['id'],'credit_issued',['ref'=>$orig,'amountCents'=>$amount,'reason'=>$reason],(int)$row['order_id']);
    self::audit($orig,'credited',['credit'=>$res['id'],'amountCents'=>$amount],(int)$row['order_id']);
   }
   return $res;
  }finally{
   $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
  }
 }

 /* ---------- alerts ---------- */
 static function alerts(){return array_values(get_option('shem_dok_alerts',[]));}
 static function alert($kind,$message,$order_id=0,$doc_id=0){
  $list=get_option('shem_dok_alerts',[]);
  $id=substr(hash('sha256',$kind.'|'.$order_id.'|'.$doc_id),0,16);
  $list[$id]=['id'=>$id,'kind'=>$kind,'message'=>$message,'order'=>(int)$order_id,'doc'=>(int)$doc_id,'time'=>gmdate('c')];
  if(count($list)>100)$list=array_slice($list,-100,null,true);
  update_option('shem_dok_alerts',$list,false);
  $to=self::settings()['alert_email'];
  if($to&&is_email($to)&&get_transient('shem_dok_alert_mail_'.$id)===false){
   set_transient('shem_dok_alert_mail_'.$id,1,HOUR_IN_SECONDS);
   wp_mail($to,'SHEM DOK - התראה',$message."\nהזמנה: ".(int)$order_id);
  }
 }
 static function clear_alerts($id=null){
  if($id===null){delete_option('shem_dok_alerts');return;}
  $list=get_option('shem_dok_alerts',[]);unset($list[$id]);update_option('shem_dok_alerts',$list,false);
 }
 static function admin_notice(){
  if(!self::allowed())return;
  $n=count(self::alerts());if(!$n)return;
  echo '<div class="notice notice-error"><p>SHEM DOK: יש '.(int)$n.' התראות פתוחות. <a href="'.esc_url(admin_url('admin.php?page=shem-dok')).'">למסך SHEM DOK</a></p></div>';
 }
 static function on_refund($order_id,$refund_id){
  $doc=self::invoice_for_order((int)$order_id);
  if($doc)self::alert('refund','בוצע החזר כספי להזמנה '.(int)$order_id.'. יש לבדוק אם נדרש מסמך זיכוי לחשבונית '.(int)$doc['id'].'. המערכת אינה מנפיקה זיכוי אוטומטית.',(int)$order_id,(int)$doc['id']);
 }
 static function reconcile(){
  global $wpdb;if(!function_exists('wc_get_orders'))return [];
  $ids=wc_get_orders(['limit'=>200,'type'=>'shop_order','status'=>['processing','completed'],'date_created'=>'>'.(time()-30*DAY_IN_SECONDS),'return'=>'ids']);
  $out=[];
  foreach($ids as $oid){
   $o=wc_get_order($oid);if(!($o instanceof WC_Order)||$o instanceof WC_Order_Refund||!self::order_ready($o))continue;
   try{self::method_for_order($o);}catch(Throwable $e){continue;}
   $exists=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.self::table().' WHERE source_key=%s',"woo:$oid"));
   if(!$exists)$out[]=['order'=>(int)$oid,'error'=>(string)$o->get_meta('_shem_dok_error')];
  }
  return $out;
 }

 /* ---------- lookups ---------- */
 static function invoice_for_order($order_id){
  global $wpdb;
  return $wpdb->get_row($wpdb->prepare('SELECT id,order_id,status,emailed_at FROM '.self::table()." WHERE order_id=%d AND doc_type='invoice' AND deleted=0 ORDER BY id ASC LIMIT 1",$order_id),ARRAY_A)?:null;
 }

 /* ---------- PDF ---------- */
 static function html($p,$number){ob_start();include __DIR__.'/template.php';return ob_get_clean();}
 static function pdf($p,$number){
  $loader=__DIR__.'/vendor/autoload.php';if(!is_file($loader))throw new Exception('מנוע PDF אינו מותקן');
  require_once $loader;
  $temp=get_temp_dir().'shem-dok-mpdf';if(!is_dir($temp))wp_mkdir_p($temp);
  $m=new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4','default_font'=>'dejavusans','margin_left'=>12,'margin_right'=>12,'margin_top'=>12,'margin_bottom'=>14,'tempDir'=>$temp]);
  $m->SetDirectionality('rtl');$m->SetTitle('SHEM DOK '.$number);
  $m->SetHTMLFooter('<div style="text-align:center;font-size:8pt;direction:ltr">{PAGENO} / {nbpg}</div>');
  $m->WriteHTML(self::html($p,$number));
  return $m->Output('','S');
 }

 /** Returns the stored PDF after an integrity check, or throws. */
 static function stored_pdf($id){
  global $wpdb;
  $row=$wpdb->get_row($wpdb->prepare('SELECT pdf,pdf_sha256,order_id FROM '.self::table().' WHERE id=%d AND deleted=0',$id),ARRAY_A);
  if(!$row||!$row['pdf'])throw new ShemDokHttp('מסמך אינו זמין',404);
  if($row['pdf_sha256']&&!hash_equals($row['pdf_sha256'],hash('sha256',$row['pdf']))){
   self::alert('integrity','שלמות קובץ ה-PDF של מסמך '.(int)$id.' נפגמה. הקובץ לא נמסר.',(int)$row['order_id'],(int)$id);
   throw new ShemDokHttp('שלמות המסמך נפגמה',500);
  }
  return $row['pdf'];
 }

 static function send_pdf($pdf,$id){
  nocache_headers();
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="SHEM-'.(int)$id.'.pdf"');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
  header('Content-Length: '.strlen($pdf));
  echo $pdf;exit;
 }

 static function download(){
  if(!self::allowed())wp_die('אין הרשאה','',['response'=>403]);
  $id=absint($_GET['id']??0);check_admin_referer('shem_dok_pdf_'.$id);
  try{$pdf=self::stored_pdf($id);}catch(ShemDokHttp $e){wp_die(esc_html($e->getMessage()),'',['response'=>$e->getCode()]);}
  self::send_pdf($pdf,$id);
 }

 /* ---------- admin screen ---------- */
 static function menu(){
  $parent=apply_filters('shem_dok_menu_parent','woocommerce');
  add_submenu_page($parent,'SHEM DOK','SHEM DOK','manage_woocommerce','shem-dok',['ShemDok','screen']);
 }
 static function screen(){
  if(!self::allowed())return;
  $asset=plugin_dir_url(__FILE__).'assets/';
  wp_enqueue_style('shem-dok',$asset.'editor.css',[],self::VERSION);
  wp_enqueue_script('shem-dok',$asset.'editor.js',[],self::VERSION,true);
  wp_add_inline_script('shem-dok','window.SHEM_DOK='.wp_json_encode(['api'=>rest_url('shem-dok/v1/'),'nonce'=>wp_create_nonce('wp_rest'),'logo'=>$asset.'logo.png','download'=>admin_url('admin-post.php?action=shem_dok_pdf'),'order'=>isset($_GET['order'])?absint(wp_unslash($_GET['order'])):0]).';','before');
  echo '<div id="shem-dok-root" dir="rtl"></div>';
 }
 static function download_url($id){return add_query_arg(['action'=>'shem_dok_pdf','id'=>(int)$id,'_wpnonce'=>wp_create_nonce('shem_dok_pdf_'.(int)$id)],admin_url('admin-post.php'));}
}
