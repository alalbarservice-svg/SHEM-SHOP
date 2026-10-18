<?php
require __DIR__.'/archive.php';
require __DIR__.'/fixtures-order.php';
require_once __DIR__.'/../includes-customer.php';
if(!function_exists('get_current_user_id')){function get_current_user_id(){return 0;}}
class PaidOrder extends FakeOrder {
 public $paid=true,$transaction='TX-99',$meta=[],$notes=[];
 function is_paid(){return $this->paid;}
 function get_transaction_id(){return $this->transaction;}
 function update_meta_data($k,$v){$this->meta[$k]=$v;}
 function delete_meta_data($k){unset($this->meta[$k]);}
 function save(){}
 function add_order_note($n){$this->notes[]=$n;}
}
function wc_get_order($id){return $GLOBALS['paidOrder'];}
function as_schedule_single_action($time,$hook,$args,$group,$unique){$GLOBALS['scheduled'][]=[$hook,$args,$unique];}
$wpdb=new FakeDB();$GLOBALS['paidOrder']=new PaidOrder();$GLOBALS['scheduled']=[];
$GLOBALS['options']['shem_dok_settings']=['auto'=>false,'defaults'=>ShemDok::defaults()];ShemDok::paid(99);check(count($wpdb->rows)===0,'disabled automation issues nothing');
$GLOBALS['options']['shem_dok_settings']['auto']=true;$GLOBALS['paidOrder']->paid=false;ShemDok::paid(99);check(count($wpdb->rows)===0,'unpaid order issues nothing');
$GLOBALS['paidOrder']->paid=true;$GLOBALS['paidOrder']->transaction='';ShemDok::paid(99);check(count($wpdb->rows)===0,'missing transaction issues nothing');
$GLOBALS['paidOrder']->transaction='TX-99';ShemDok::paid(99);ShemDok::paid(99);check(count($wpdb->rows)===1&&$wpdb->next===7002,'duplicate payment events produce one PDF');
$wpdb=new FakeDB();$GLOBALS['paidOrder']->total='999.00';ShemDok::paid(99);check(count($wpdb->rows)===0&&isset($GLOBALS['paidOrder']->meta['_shem_dok_error']),'mapping failure logged without issuing');check(count($GLOBALS['scheduled'])===0,'permanent mapping failure does not schedule useless retries');check(count(ShemDok::alerts())>=1,'permanent mapping failure raises an alert');ShemDok::paid(99,3);check(count($GLOBALS['scheduled'])===0,'no retries after final failure');
$GLOBALS['paidOrder']->total='155.20';ShemDok::paid(99,1);check(count($wpdb->rows)===1&&!isset($GLOBALS['paidOrder']->meta['_shem_dok_error']),'successful retry issues once and clears error');
