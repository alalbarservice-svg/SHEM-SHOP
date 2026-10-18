<?php
// Isolated test harness. No WordPress site or production services are contacted.
define('ABSPATH',__DIR__.'/');
function get_option($k,$d=[]){return $GLOBALS['options'][$k]??$d;}
function update_option($k,$v,$a=false){$GLOBALS['options'][$k]=$v;return true;}
function sanitize_textarea_field($v){return strip_tags((string)$v);}
function sanitize_text_field($v){return strip_tags((string)$v);}
function is_email($v){return filter_var($v,FILTER_VALIDATE_EMAIL);}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_UNICODE);}
function get_temp_dir(){return sys_get_temp_dir().'/';}
function wp_mkdir_p($p){return is_dir($p)||mkdir($p,0700,true);}
function apply_filters($k,$p,$o){return $p;}
function wp_strip_all_tags($v){return strip_tags($v);}
function wc_display_item_meta($x,$a){return '';}
require dirname(__DIR__).'/includes.php';
function check($truth,$label){if(!$truth)throw new Exception($label);echo 'PASS '.$label."\n";}
function rejects($fn,$label){try{$fn();}catch(Throwable $e){check(true,$label);return;}throw new Exception('Expected rejection: '.$label);}
$p=ShemDok::defaults()+['customer'=>'לקוח בדיקה','customerPhone'=>'','customerEmail'=>'','customerAddress'=>'','orderDate'=>'2026-10-16','orderNumber'=>'TEST-1','method'=>'card','holder'=>'','brand'=>'','last4'=>'1234','installments'=>'1','reference'=>'TEST-REF','paypalEmail'=>'','shipping'=>0,'discount'=>0,'description'=>'נתונים סינתטיים לבדיקת התוכנה בלבד','items'=>[['name'=>'חולצה לבדיקה','sku'=>'TEST','qty'=>1,'price'=>118]]];
$p=ShemDok::clean($p);$t=ShemDok::totals($p);check($t['gross']===11800&&$t['net']===10000&&$t['tax']===1800,'VAT included: 118 = 100 + 18');
$q=$p;$q['items'][0]['qty']=2;$q['discount']=23.6;$q['shipping']=49;check(ShemDok::totals($q)['gross']===26140,'discount and gross shipping');
$q=$p;$q['items'][0]['lineGross']=11999;$q['items'][0]['qty']=3;check(ShemDok::totals(ShemDok::clean($q))['gross']===11999,'canonical line cents survive round trip');
$q=$p;$q['discount']=200;rejects(fn()=>ShemDok::clean($q),'reject excessive discount');
$q=$p;$q['last4']='1234567890123456';rejects(fn()=>ShemDok::clean($q),'reject full card number');
$q=$p;$q['orderDate']='2026-02-31';rejects(fn()=>ShemDok::clean($q),'reject invalid calendar date');
$q=$p;$q['method']='paypal';$q['paypalEmail']='payer@example.test';$q=ShemDok::clean($q);check($q['last4']===''&&$q['paypalEmail']==='payer@example.test','PayPal replaces card details');
$html=ShemDok::html($p,7001);check(str_contains($html,'7001')&&str_contains($html,'חשבונית עסקה')&&str_contains($html,'זמני אספקה, משלוחים ותנאי החזרות'),'document template and numbering');
$pdf=ShemDok::pdf($p,7001);check(str_starts_with($pdf,'%PDF-'),'real PDF generated');file_put_contents(sys_get_temp_dir().'/shem-dok-sample.pdf',$pdf);
$p['items']=array_fill(0,65,['name'=>'פריט בדיקה ארוך לבדיקה של טבלה מרובת עמודים','sku'=>'TEST-LONG','qty'=>1,'price'=>118,'lineGross'=>null]);file_put_contents(sys_get_temp_dir().'/shem-dok-long.pdf',ShemDok::pdf($p,7002));echo 'PDF samples: '.sys_get_temp_dir()."\n";

$q=$p;$q['items'][0]['lineGross']=1.5;rejects(fn()=>ShemDok::clean($q),'fractional cents rejected');

$q=$p;$q['taxCents']=1.5;rejects(fn()=>ShemDok::clean($q),'fractional tax cents rejected');$q['taxCents']=999999999;rejects(fn()=>ShemDok::clean($q),'tax cannot exceed gross');
