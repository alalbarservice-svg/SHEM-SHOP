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
$q=$p;$q['method']='transfer';$q['holder']='ישראל ישראלי';$q['bank']='12';$q['branch']='345';$q['account']='123456-7';$q['transferDate']='2026-10-20';$q['last4']='1234';$q['paypalEmail']='x@example.test';$q['reference']='TR-1';$q=ShemDok::clean($q);
check($q['bank']==='12'&&$q['account']==='123456-7'&&$q['last4']===''&&$q['paypalEmail']==='','transfer keeps bank fields and drops card and PayPal fields');
$h=ShemDok::html($q,7002);check(str_contains($h,'העברה בנקאית')&&str_contains($h,'בנק 12')&&str_contains($h,'סניף 345')&&str_contains($h,'חשבון 123456-7')&&str_contains($h,'תאריך העברה 2026-10-20')&&str_contains($h,'אסמכתת תשלום: TR-1'),'transfer details render in the document');
$q2=$p;$q2['method']='transfer';$q2['branch']='ab';rejects(fn()=>ShemDok::clean($q2),'reject non numeric branch');
$q2=$p;$q2['method']='transfer';$q2['account']='12 34';rejects(fn()=>ShemDok::clean($q2),'reject account with spaces');
$q2=$p;$q2['method']='transfer';$q2['transferDate']='2026-13-01';rejects(fn()=>ShemDok::clean($q2),'reject invalid transfer date');
$q=$p;$q['method']='cash';$q['receivedDate']='2026-10-21';$q['reference']='שולם במזומן בחנות';$q['bank']='12';$q['brand']='visa';$q=ShemDok::clean($q);
check($q['receivedDate']==='2026-10-21'&&$q['bank']===''&&$q['brand']==='','cash keeps its date and drops other methods fields');
$h=ShemDok::html($q,7003);check(str_contains($h,'מזומן')&&str_contains($h,'התקבל בתאריך 2026-10-21')&&str_contains($h,'הערה: שולם במזומן בחנות')&&!str_contains($h,'אשראי'),'cash details render in the document');
$q2=$p;$q2['method']='cash';$q2['receivedDate']='2026-02-30';rejects(fn()=>ShemDok::clean($q2),'reject invalid cash date');
$q=$p;$q['method']='other';$q['methodLabel']='שיק דחוי';$q['reference']='שיק 55';$q=ShemDok::clean($q);
$h=ShemDok::html($q,7004);check(str_contains($h,'שיק דחוי')&&str_contains($h,'אסמכתת תשלום: שיק 55'),'other method renders its free description');
$q2=$p;$q2['method']='other';$q2['methodLabel']='';rejects(fn()=>ShemDok::clean($q2),'other method requires a description');
$q2=$p;$q2['method']='bitcoin';rejects(fn()=>ShemDok::clean($q2),'reject unknown payment method');
$q=$p;$q['method']='card';$q['brand']='visa';$q['last4']='4242';$q['installments']='3';$q['holder']='דנה';$q=ShemDok::clean($q);$h=ShemDok::html($q,7005);
check(str_contains($h,'אשראי')&&str_contains($h,'מסתיים ב-4242')&&str_contains($h,'3 תשלומים')&&str_contains($h,'דנה'),'card details render in the document');
$q=$p;$q['method']='paypal';$q['holder']='דנה';$q['paypalEmail']='payer@example.test';$q=ShemDok::clean($q);$h=ShemDok::html($q,7006);
check(str_contains($h,'PayPal')&&str_contains($h,'payer@example.test'),'PayPal details render in the document');
$html=ShemDok::html($p,7001);check(str_contains($html,'7001')&&str_contains($html,'חשבונית עסקה')&&str_contains($html,'זמני אספקה, משלוחים ותנאי החזרות'),'document template and numbering');
$pdf=ShemDok::pdf($p,7001);check(str_starts_with($pdf,'%PDF-'),'real PDF generated');file_put_contents(sys_get_temp_dir().'/shem-dok-sample.pdf',$pdf);
$p['items']=array_fill(0,65,['name'=>'פריט בדיקה ארוך לבדיקה של טבלה מרובת עמודים','sku'=>'TEST-LONG','qty'=>1,'price'=>118,'lineGross'=>null]);file_put_contents(sys_get_temp_dir().'/shem-dok-long.pdf',ShemDok::pdf($p,7002));echo 'PDF samples: '.sys_get_temp_dir()."\n";

$q=$p;$q['items'][0]['lineGross']=1.5;rejects(fn()=>ShemDok::clean($q),'fractional cents rejected');

$q=$p;$q['taxCents']=1.5;rejects(fn()=>ShemDok::clean($q),'fractional tax cents rejected');$q['taxCents']=999999999;rejects(fn()=>ShemDok::clean($q),'tax cannot exceed gross');
