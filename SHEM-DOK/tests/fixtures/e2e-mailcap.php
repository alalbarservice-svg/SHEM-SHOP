<?php
/* E2E only: captures wp_mail instead of sending. Active only when option shem_e2e_mailcap = 1. */
add_filter('pre_wp_mail', function($null,$a){
 if(get_option('shem_e2e_mailcap')!=='1')return $null;
 $att=[];foreach((array)($a['attachments']??[]) as $f){$att[]=['name'=>basename($f),'size'=>@filesize($f),'magic'=>@substr(@file_get_contents($f),0,5),'sha'=>@hash_file('sha256',$f)];}
 $log=get_option('shem_e2e_mails',[]);$log[]=['to'=>$a['to'],'subject'=>$a['subject'],'message'=>$a['message'],'att'=>$att];
 update_option('shem_e2e_mails',array_slice($log,-50),false);
 return true;
},10,2);
