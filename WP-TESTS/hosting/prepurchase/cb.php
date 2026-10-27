<?php
$o = getenv('ORDER_ID'); $r = Shimi_Pay::find_by_order($o);
if(!$r){ echo json_encode(['err'=>'no record']); return; }
$r['state']='paid'; $r['txn']='TEST-TXN-'.$o; $r['method']='paypal'; $r['holder']='Test Staging'; $r['cbAttempts']=0; Shimi_Pay::save($r);
Shimi_Pay::send_callback($r['id']); $r2=Shimi_Pay::find_by_order($o);
echo json_encode(['state'=>$r2['state'],'cbError'=>$r2['cbError']??'','attempts'=>$r2['cbAttempts']??null]);
