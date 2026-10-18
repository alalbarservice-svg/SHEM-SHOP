<?php
/* E2E only: captures wp_mail instead of sending. Active only when option shem_e2e_mailcap = 1. */
add_filter('pre_wp_mail', function($null,$a){
 if(get_option('shem_e2e_mailcap')!=='1')return $null;
 $att=[];foreach((array)($a['attachments']??[]) as $f){$att[]=['name'=>basename($f),'size'=>@filesize($f),'magic'=>@substr(@file_get_contents($f),0,5),'sha'=>@hash_file('sha256',$f)];}
 $log=get_option('shem_e2e_mails',[]);$log[]=['to'=>$a['to'],'subject'=>$a['subject'],'message'=>$a['message'],'att'=>$att];
 update_option('shem_e2e_mails',array_slice($log,-50),false);
 return true;
},10,2);
/* E2E only: staging runs over plain http with WP_ENVIRONMENT_TYPE=staging, where Application Passwords are unavailable; the suite authenticates with one. */
add_filter('wp_is_application_passwords_available', function($v){ return get_option('shem_e2e_mailcap')==='1' ? true : $v; });
