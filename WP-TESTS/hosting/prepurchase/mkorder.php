<?php
$ids = wc_get_products(['limit'=>1,'status'=>'publish','return'=>'ids']);
if(!$ids){ $p=new WC_Product_Simple(); $p->set_name('Test item'); $p->set_regular_price('100'); $p->set_status('publish'); $ids=[$p->save()]; }
$o = wc_create_order(); $o->add_product(wc_get_product($ids[0]),1);
$o->set_currency('ILS'); $o->set_billing_first_name('Test'); $o->set_billing_last_name('Staging'); $o->set_billing_email('test@example.invalid');
$o->set_payment_method('shem_pay'); $o->calculate_totals(); $o->set_status('pending'); $o->save();
$g = WC()->payment_gateways->payment_gateways()['shem_pay'];
$r = $g->process_payment($o->get_id());
echo json_encode(['order'=>$o->get_id(),'total'=>$o->get_total(),'currency'=>get_option('woocommerce_currency'),'avail'=>$g->is_available(),'result'=>$r]);
