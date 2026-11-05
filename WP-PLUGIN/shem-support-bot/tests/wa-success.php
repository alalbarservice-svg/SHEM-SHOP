<?php
define('SSB_WA_LIVE', true); define('SSB_WA_URL','http://172.18.0.1:9099/'); define('SSB_WA_KEY', getenv('K') ?: 'TESTKEY');
global $wpdb; $t = Ssb_Db::t('notice');
echo "dbver=", get_option('ssb_db_version'), "\n";
$cols = $wpdb->get_col("SHOW COLUMNS FROM $t"); echo "cols=", implode(',', $cols), "\n";
// full ticket flow
$tk = Ssb_Tickets::create(['name'=>'בדיקה','contact'=>'0501234567','summary'=>'לקוח מבקש שיחה','reason'=>'customer_request','order_number'=>'']);
$num = is_array($tk) ? $tk['number'] : '?'; echo "ticket=$num\n";
$row = $wpdb->get_row("SELECT * FROM $t WHERE channel='whatsapp' ORDER BY id DESC LIMIT 1", ARRAY_A);
echo "queued to=", $row['to_addr'], " sent=", $row['sent'], " subj=", $row['subject'], "\n";
$ok = Ssb_Wa::deliver($row['id']);
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d",$row['id']), ARRAY_A);
echo "deliver=", var_export($ok,true), " sent=", $row['sent'], " attempts=", $row['attempts'], " err=", $row['last_error'], "\n";
echo "bad number queue=", Ssb_Wa::queue('123','x','R'), "\n";
