<?php
/**
 * Customer-facing delivery of the transaction document: thank-you page button, owner-checked download,
 * My Account link, optional email with the PDF (off by default) and an order screen box for managers.
 * A customer can only reach the invoice of an order they can prove ownership of:
 *  - a short-lived signed token minted while rendering the WooCommerce order-received page (which itself checks the order key), or
 *  - a logged-in session whose user id equals the order's customer id.
 */
if (!defined('ABSPATH')) exit;

final class ShemDok_Customer {
 const TTL = 86400;

 /* ---------- tokens ---------- */
 static function token($scope,$order_id,$exp=null){
  $exp=$exp?:time()+self::TTL;
  $mac=hash_hmac('sha256',"shem-dok|$scope|$order_id|$exp",wp_salt('auth'));
  return rtrim(strtr(base64_encode("$order_id.$exp.$mac"),'+/','-_'),'=');
 }
 static function check_token($scope,$token){
  $raw=base64_decode(strtr((string)$token,'-_','+/'),true);if($raw===false)return 0;
  $parts=explode('.',$raw);if(count($parts)!==3)return 0;
  [$oid,$exp,$mac]=$parts;if(!ctype_digit($oid)||!ctype_digit($exp)||(int)$exp<time())return 0;
  $good=hash_hmac('sha256',"shem-dok|$scope|$oid|$exp",wp_salt('auth'));
  return hash_equals($good,$mac)?(int)$oid:0;
 }
 static function dl_url($order_id){return add_query_arg(['action'=>'shem_dok_customer_pdf','t'=>self::token('dl',$order_id)],admin_url('admin-post.php'));}

 /* ---------- throttling ---------- */
 private static function throttle(){
  $key='shem_dok_rl_'.md5(($_SERVER['REMOTE_ADDR']??'0'));$n=(int)get_transient($key);
  if($n>=30)wp_die('יותר מדי בקשות. נסו שוב מאוחר יותר.','',['response'=>429]);
  set_transient($key,$n+1,HOUR_IN_SECONDS);
 }

 /* ---------- download ---------- */
 static function download(){
  self::throttle();
  $order_id=0;
  if(!empty($_GET['t']))$order_id=self::check_token('dl',wp_unslash($_GET['t']));
  elseif(is_user_logged_in()&&!empty($_GET['order'])){
   $o=wc_get_order(absint($_GET['order']));
   if($o&&(int)$o->get_customer_id()>0&&(int)$o->get_customer_id()===get_current_user_id())$order_id=$o->get_id();
  }
  if(!$order_id)wp_die('אין הרשאה להורדת המסמך','',['response'=>403]);
  $o=wc_get_order($order_id);
  if(!$o||!$o->is_paid())wp_die('אין הרשאה להורדת המסמך','',['response'=>403]);
  $doc=ShemDok::invoice_for_order($order_id);
  if(!$doc)wp_die('החשבונית טרם הופקה','',['response'=>404]);
  try{$pdf=ShemDok::stored_pdf((int)$doc['id']);}catch(ShemDokHttp $e){wp_die(esc_html($e->getMessage()),'',['response'=>$e->getCode()]);}
  ShemDok::audit((int)$doc['id'],'customer_download',[],$order_id);
  ShemDok::send_pdf($pdf,(int)$doc['id']);
 }

 /* ---------- status polling for the thank-you page ---------- */
 static function routes(){
  register_rest_route('shem-dok/v1','/customer/status',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>function($r){
   $oid=self::check_token('st',(string)$r->get_param('t'));
   if(!$oid)return new WP_REST_Response(['ready'=>false],403);
   $doc=ShemDok::invoice_for_order($oid);
   return new WP_REST_Response($doc?['ready'=>true,'url'=>self::dl_url($oid)]:['ready'=>false],200);
  }]);
 }

 /* ---------- thank-you page ---------- */
 static function greeting($order_id){
  $o=wc_get_order($order_id);if(!$o||!$o->is_paid())return;
  $s=ShemDok::settings();
  echo '<style>.shem-greeting{text-align:center;margin:1.5em 0 2em}.shem-greeting h1{font-size:clamp(1.9rem,4.5vw,2.8rem);font-weight:700;line-height:1.25;margin:0 0 .4em}.shem-greeting p{font-size:clamp(1.3rem,3vw,1.75rem);font-weight:600;line-height:1.4;margin:0}</style>';
  echo '<section class="shem-greeting" dir="rtl" lang="he"><h1>'.esc_html($s['thankyou_heading']).'</h1><p>'.esc_html($s['thankyou_sub']).'</p></section>';
 }
 static function thankyou($order_id){
  $s=ShemDok::settings();if(!$s['auto']||!$s['thankyou'])return;
  $o=wc_get_order($order_id);if(!$o||!$o->is_paid())return;
  try{ShemDok::method_for_order($o);}catch(Throwable $e){return;}
  $doc=ShemDok::invoice_for_order($o->get_id());
  echo '<section class="shem-dok-thankyou" data-shem-dok="1" dir="rtl" lang="he"><h2>'.esc_html($s['thankyou_title']).'</h2>';
  if($doc){
   echo '<p><a class="button shem-dok-download" href="'.esc_url(self::dl_url($o->get_id())).'">'.esc_html($s['thankyou_button']).'</a></p>';
  }else{
   echo '<p class="shem-dok-pending">'.esc_html($s['thankyou_pending']).'</p>';
   $api=esc_url_raw(rest_url('shem-dok/v1/customer/status'));$t=self::token('st',$o->get_id());
   $label=wp_json_encode($s['thankyou_button']);
   echo '<script>(function(){var n=0,box=document.querySelector(".shem-dok-pending");if(!box)return;var u='.wp_json_encode($api.'?t='.$t).';var label='.$label.';function tick(){fetch(u,{credentials:"omit"}).then(function(r){return r.json()}).then(function(d){if(d&&d.ready){var a=document.createElement("a");a.className="button shem-dok-download";a.href=d.url;a.textContent=label;box.replaceWith(a);}else if(++n<20)setTimeout(tick,3000)}).catch(function(){if(++n<20)setTimeout(tick,5000)})}setTimeout(tick,2500)})();</script>';
  }
  echo '</section>';
 }

 static function account_link($order){
  $s=ShemDok::settings();if(!$s['auto']||!$s['thankyou']||!is_user_logged_in())return;
  if((int)$order->get_customer_id()!==get_current_user_id())return;
  $doc=ShemDok::invoice_for_order($order->get_id());if(!$doc)return;
  $url=add_query_arg(['action'=>'shem_dok_customer_pdf','order'=>$order->get_id()],admin_url('admin-post.php'));
  echo '<p class="shem-dok-account"><a class="button" href="'.esc_url($url).'">'.esc_html($s['thankyou_button']).'</a></p>';
 }

 /* ---------- email (off by default) ---------- */
 static function after_issue($doc_id,$order){
  $s=ShemDok::settings();if(!$s['email_customer'])return;
  try{self::email($doc_id,$order,$s);}
  catch(Throwable $e){ShemDok::alert('email_failed','שליחת החשבונית ללקוח נכשלה: '.$e->getMessage(),$order->get_id(),$doc_id);}
 }
 static function email($doc_id,$order,$s){
  global $wpdb;$t=ShemDok::table();
  $to=$order->get_billing_email();if(!is_email($to))throw new Exception('כתובת מייל של הלקוח חסרה או לא תקינה');
  $done=$wpdb->get_var($wpdb->prepare("SELECT emailed_at FROM $t WHERE id=%d",$doc_id));if($done)return;
  $claimed=$wpdb->query($wpdb->prepare("UPDATE $t SET emailed_at=%s WHERE id=%d AND emailed_at IS NULL",current_time('mysql',true),$doc_id));
  if(!$claimed)return;
  $pdf=ShemDok::stored_pdf($doc_id);
  $dir=get_temp_dir().'shem-dok-mail';if(!is_dir($dir))wp_mkdir_p($dir);
  $file=$dir.'/SHEM-'.$doc_id.'-'.wp_generate_password(8,false).'.pdf';
  file_put_contents($file,$pdf);
  $repl=['{number}'=>$doc_id,'{order}'=>$order->get_order_number(),'{name}'=>trim($order->get_billing_first_name().' '.$order->get_billing_last_name())];
  $ok=wp_mail($to,strtr($s['email_subject'],$repl),strtr($s['email_body'],$repl),['Content-Type: text/plain; charset=UTF-8'],[$file]);
  @unlink($file);
  if(!$ok){$wpdb->query($wpdb->prepare("UPDATE $t SET emailed_at=NULL WHERE id=%d",$doc_id));throw new Exception('wp_mail החזיר כשל');}
  ShemDok::audit($doc_id,'emailed',['to_domain'=>substr(strrchr($to,'@'),1)],$order->get_id());
 }

 /* ---------- manager box on the order screen ---------- */
 static function metabox(){
  if(!function_exists('wc_get_page_screen_id'))return;
  add_meta_box('shem-dok-order','SHEM DOK',[self::class,'box'],wc_get_page_screen_id('shop-order'),'side','default');
 }
 static function box($post_or_order){
  $order=($post_or_order instanceof WC_Order)?$post_or_order:wc_get_order($post_or_order->ID);
  if(!$order||!ShemDok::allowed())return;
  $doc=ShemDok::invoice_for_order($order->get_id());
  if($doc){
   echo '<p>חשבונית עסקה מספר <b>'.(int)$doc['id'].'</b> ('.esc_html($doc['status']).')</p>';
   echo '<p><a class="button" href="'.esc_url(ShemDok::download_url((int)$doc['id'])).'">הורדת PDF</a></p>';
  }else{
   $err=(string)$order->get_meta('_shem_dok_error');
   echo '<p>טרם הופקה חשבונית עסקה.</p>';
   if($err)echo '<p style="color:#a02222">'.esc_html($err).'</p>';
   if($order->is_paid()&&$order->get_transaction_id()){
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('shem_dok_order_issue_'.$order->get_id(),'_wpnonce',true,false).'<input type="hidden" name="action" value="shem_dok_order_issue"><input type="hidden" name="order" value="'.(int)$order->get_id().'"><button class="button button-primary">הפקה / ניסיון חוזר</button></form>';
   }
  }
 }
 static function order_issue(){
  if(!ShemDok::allowed())wp_die('אין הרשאה','',['response'=>403]);
  $oid=absint($_POST['order']??0);check_admin_referer('shem_dok_order_issue_'.$oid);
  $o=wc_get_order($oid);if(!$o)wp_die('הזמנה לא נמצאה','',['response'=>404]);
  try{$res=ShemDok::issue_for_order($o);ShemDok::audit($res['id'],'manual_retry',[],$oid);$o->delete_meta_data('_shem_dok_error');$o->save();}
  catch(Throwable $e){wp_die(esc_html($e->getMessage()),'',['response'=>400]);}
  wp_safe_redirect($o->get_edit_order_url());exit;
 }
}
