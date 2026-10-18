<?php
if (!defined('ABSPATH') || !defined('WP_CLI')) exit;
/** Operations helpers. None of these deletes anything. */
class ShemDok_CLI {
 /** Issue (or return the existing) document for a paid order. */
 function issue($args){$o=wc_get_order((int)$args[0]);if(!$o)WP_CLI::error('order not found');try{$r=ShemDok::issue_for_order($o);}catch(Throwable $e){WP_CLI::error($e->getMessage());}WP_CLI::success(wp_json_encode($r['existing']??false?['existing'=>$r['id']]:['issued'=>$r['id']]));}
 /** List paid orders (last 30 days) without a document. */
 function reconcile(){WP_CLI::print_value(ShemDok::reconcile(),['format'=>'json']);}
 /** Verify the sha256 of every stored PDF. */
 function verify(){global $wpdb;$bad=0;$n=0;foreach($wpdb->get_results('SELECT id,pdf,pdf_sha256 FROM '.ShemDok::table().' WHERE deleted=0 AND pdf IS NOT NULL',ARRAY_A) as $r){$n++;if($r['pdf_sha256']&&!hash_equals($r['pdf_sha256'],hash('sha256',$r['pdf']))){$bad++;WP_CLI::warning('mismatch '.$r['id']);}}$bad?WP_CLI::error("$bad of $n documents failed integrity"):WP_CLI::success("$n documents verified");}
 /** Export the audit trail as JSON. */
 function audit(){global $wpdb;WP_CLI::print_value($wpdb->get_results('SELECT * FROM '.ShemDok::audit_table().' ORDER BY id',ARRAY_A),['format'=>'json']);}
}
WP_CLI::add_command('shem-dok', 'ShemDok_CLI');
