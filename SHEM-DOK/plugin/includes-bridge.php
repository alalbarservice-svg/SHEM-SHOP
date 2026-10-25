<?php
/**
 * SHIMI paid-callback adapter.
 *
 * Contract (from SHEM/docs/home-site-protocol.md and shop-channel.md):
 *   POST /wp-json/shem-dok/v1/pay/notify
 *   headers  x-shem-timestamp (unix seconds, max 5 minutes old), x-shem-signature (hex HMAC-SHA256 of "timestamp.rawBody")
 *   body     { orderId, status:"paid", currency:"ILS", amountCents, transactionId, paymentDetails?:{ method, last4, cardholderName, brand, authorizationCode, installments } }
 * Only a validly signed callback can mark a shem_pay order paid. A browser redirect never does.
 * The secret is read from the constant SHEM_HOME_PAYMENT_SECRET or the environment variable HOME_PAYMENT_SECRET. It is never stored in the database.
 * If no secret of at least 32 characters is configured, the endpoint answers 503 and does nothing (fail closed).
 */
if (!defined('ABSPATH')) exit;

final class ShemDok_Bridge {
 static function secret(){
  $s=defined('SHEM_HOME_PAYMENT_SECRET')?constant('SHEM_HOME_PAYMENT_SECRET'):getenv('HOME_PAYMENT_SECRET');
  return (is_string($s)&&strlen($s)>=32)?$s:null;
 }

 static function routes(){
  register_rest_route('shem-dok/v1','/pay/notify',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>[self::class,'handle']]);
 }

 private static function fail($status,$code,$message){return new WP_REST_Response(['ok'=>false,'code'=>$code,'message'=>$message],$status);}

 static function handle(WP_REST_Request $r){
  if(!function_exists('wc_get_order'))return self::fail(503,'woocommerce_inactive','WooCommerce inactive');
  $secret=self::secret();
  if($secret===null)return self::fail(503,'not_configured','Payment callback is not configured');
  $raw=$r->get_body();
  if(strlen($raw)>16384)return self::fail(413,'too_large','Body too large');
  $ts=(string)$r->get_header('x-shem-timestamp');$sig=strtolower((string)$r->get_header('x-shem-signature'));
  if(!ctype_digit($ts)||abs(time()-(int)$ts)>300)return self::fail(401,'stale','Invalid or stale timestamp');
  if(!preg_match('/^[0-9a-f]{64}$/',$sig)||!hash_equals(hash_hmac('sha256',$ts.'.'.$raw,$secret),$sig))return self::fail(401,'bad_signature','Invalid signature');
  $e=json_decode($raw,true);
  if(!is_array($e))return self::fail(400,'bad_json','Invalid JSON');
  $orderRef=(string)($e['orderId']??'');$txn=trim((string)($e['transactionId']??''));
  if($orderRef===''||strlen($orderRef)>64||$txn===''||strlen($txn)>128)return self::fail(400,'bad_fields','Missing orderId or transactionId');
  if(($e['status']??'')!=='paid')return self::fail(400,'not_paid','Only status=paid is accepted');
  if(($e['currency']??'')!=='ILS')return self::fail(400,'currency','Only ILS is accepted');
  if(!isset($e['amountCents'])||!is_int($e['amountCents'])||$e['amountCents']<=0)return self::fail(400,'amount','amountCents must be a positive integer');

  $order=self::find_order($orderRef);
  if(!$order)return self::fail(404,'order_not_found','Order not found');
  if($order->get_payment_method()!==ShemDok::GATEWAY)return self::fail(409,'wrong_gateway','Order is not a SHIMI order');
  global $wpdb;$lock='sdp:'.(int)$order->get_id();
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1)return self::fail(503,'busy','Order is being processed. Retry.');
  try{
   $order=self::fresh($order->get_id());
   if(!$order)return self::fail(404,'order_not_found','Order not found');
   return self::apply($order,$e,$txn);
  }finally{
   $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
  }
 }

 private static function apply($order,$e,$txn){
  if($order->get_currency()!=='ILS')return self::fail(409,'currency_mismatch','Order currency mismatch');
  if(ShemDok::cents($order->get_total())!==$e['amountCents'])return self::fail(409,'amount_mismatch','Amount does not match the order');

  if($order->is_paid()){
   if($order->get_transaction_id()===$txn)return new WP_REST_Response(['ok'=>true,'duplicate'=>true],200);
   return self::fail(409,'already_paid','Order is already paid with a different transaction');
  }
  $other=wc_get_orders(['limit'=>1,'transaction_id'=>$txn,'exclude'=>[$order->get_id()],'return'=>'ids']);
  if($other)return self::fail(409,'txn_reuse','Transaction already used by another order');

  $pd=is_array($e['paymentDetails']??null)?$e['paymentDetails']:[];
  $method=in_array($pd['method']??'',['card','paypal'],true)?$pd['method']:'';
  if($method==='')return self::fail(400,'method','paymentDetails.method must be card or paypal');
  $last4=(string)($pd['last4']??'');if(!preg_match('/^\d{4}$/',$last4))$last4='';
  $inst=isset($pd['installments'])?(int)$pd['installments']:0;if($inst<1||$inst>60)$inst=0;
  $clip=function($v,$n){return mb_substr(sanitize_text_field((string)$v),0,$n);};
  $order->update_meta_data('_shem_pay_verified','1');
  $order->update_meta_data('_shem_pay_method',$method);
  $order->update_meta_data('_shem_pay_last4',$method==='card'?$last4:'');
  $order->update_meta_data('_shem_pay_holder',$clip($pd['cardholderName']??'',80));
  $order->update_meta_data('_shem_pay_brand',$method==='card'?$clip($pd['brand']??'',24):'');
  $order->update_meta_data('_shem_pay_auth',$clip($pd['authorizationCode']??'',64));
  $order->update_meta_data('_shem_pay_installments',$method==='card'&&$inst?(string)$inst:'');
  $order->add_order_note('תשלום אומת מחתימת SHIMI. אמצעי: '.($method==='card'?'אשראי':'PayPal'));
  $order->save();
  $order->payment_complete($txn);
  return new WP_REST_Response(['ok'=>true,'duplicate'=>false],200);
 }

 /* The order was loaded once before the lock (find_order). A request that waited for the lock must not trust that copy:
    another request may have paid the order meanwhile. Drop every cached copy and read from the database again. */
 private static function fresh($id){
  $id=(int)$id;
  wp_cache_delete($id,'orders');wp_cache_delete($id,'order_meta');wp_cache_delete($id,'post_meta');wp_cache_delete($id,'posts');
  if(function_exists('clean_post_cache'))clean_post_cache($id);
  if(function_exists('wc_delete_shop_order_transients'))wc_delete_shop_order_transients($id);
  return wc_get_order($id);
 }

 static function find_order($ref){
  $found=wc_get_orders(['limit'=>1,'meta_key'=>'_shem_session_id','meta_value'=>$ref,'return'=>'objects']);
  if($found)return $found[0];
  if(ctype_digit($ref)){$o=wc_get_order((int)$ref);if($o&&$o->get_payment_method()===ShemDok::GATEWAY)return $o;}
  return null;
 }
}
