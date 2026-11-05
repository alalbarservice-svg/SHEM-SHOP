<?php
define('SSB_WA_LIVE', true); define('SSB_WA_URL','http://172.18.0.1:9099/'); define('SSB_WA_KEY','WRONG');
global $wpdb; $t = Ssb_Db::t('notice');
$id = Ssb_Wa::queue('0507974021','בדיקת כשל','R-FAIL');
for ($i=1;$i<=5;$i++){ Ssb_Wa::deliver($id); $r=$wpdb->get_row($wpdb->prepare("SELECT sent,attempts,last_error,subject FROM $t WHERE id=%d",$id),ARRAY_A); echo "try$i sent={$r['sent']} att={$r['attempts']} err={$r['last_error']} subj={$r['subject']}\n"; }
echo "after max: scheduled=", var_export((bool)wp_next_scheduled('ssb_wa_send',[$id]),true), "\n";
Ssb_Wa::deliver($id); $r=$wpdb->get_row($wpdb->prepare("SELECT attempts FROM $t WHERE id=%d",$id),ARRAY_A); echo "6th call attempts=", $r['attempts'], "\n";
