<?php
global $wpdb; $t=Ssb_Db::t('notice'); $before=file_exists('/tmp/x');
$id=Ssb_Wa::queue('0507974021','staging log only','R-STG'); $r=$wpdb->get_row($wpdb->prepare("SELECT sent,subject FROM $t WHERE id=%d",$id),ARRAY_A);
echo "staging sent={$r['sent']} subj={$r['subject']} deliver=", var_export(Ssb_Wa::deliver($id),true), " scheduled=", var_export((bool)wp_next_scheduled('ssb_wa_send',[$id]),true), "\n";
