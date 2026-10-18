<?php
class FakeItem {
 function get_subtotal(){return '100.00';}function get_subtotal_tax(){return '18.00';}function get_total(){return '90.00';}function get_total_tax(){return '16.20';}function get_quantity(){return 1;}function get_product(){return false;}function get_name(){return 'מוצר בדיקה';}
}
class FakeOrder {
 public $total='155.20',$currency='ILS',$method='stripe';
 public $tax='23.67';function get_total_tax(){return $this->tax;}function get_currency(){return $this->currency;}function get_payment_method(){return $this->method;}function get_id(){return 99;}function get_billing_first_name(){return 'לקוח';}function get_billing_last_name(){return 'בדיקה';}function get_billing_phone(){return '';}function get_billing_email(){return 'test@example.test';}function get_billing_address_1(){return 'כתובת בדיקה';}function get_billing_address_2(){return '';}function get_billing_city(){return 'עיר בדיקה';}function get_date_created(){return new class('2026-10-16') extends DateTime {function date($f){return $this->format($f);}};}function get_order_number(){return 'TEST-99';}function get_transaction_id(){return 'test-transaction';}function get_shipping_total(){return '41.53';}function get_shipping_tax(){return '7.47';}function get_items(){return [new FakeItem()];}function get_coupon_codes(){return ['TEST10'];}function get_total(){return $this->total;}
}
