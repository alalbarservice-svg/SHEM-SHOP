<?php
/**
 * Plugin Name: SHEM Pay Gateway (staging prototype)
 * Description: WooCommerce gateway "shem_pay". Opens a signed payment session at SHIMI (protocol shem-home-v1) and shows the SHIMI payment page embedded inside SHEM. An order becomes paid ONLY from SHIMI's signed callback (shem-dok /shimi/paid), never from the browser. Sandbox only.
 * Version: 0.1.0
 * Requires Plugins: woocommerce
 *
 * wp-config.php constants (no secrets in the database):
 *   SHEM_HOME_PAYMENT_SECRET   shared HMAC secret (already used by shem-dok callback)
 *   SHEM_PAY_SESSION_URL       server-to-server URL of SHIMI /shimi-pay/v1/session
 *   SHEM_PAY_ALLOWED_ORIGIN    the only origin an embedUrl may have
 */
if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', function () {
 if (!class_exists('WC_Payment_Gateway')) return;

 class WC_Gateway_Shem_Via_Shimi extends WC_Payment_Gateway {
  function __construct() {
   $this->id = 'shem_pay';
   $this->method_title = 'SHEM via SHIMI';
   $this->method_description = 'Payment is collected by SHIMI and embedded in SHEM.';
   $this->has_fields = false;
   $this->supports = ['products'];
   $this->title = 'PayPal';
   $this->description = '';
   $this->init_form_fields(); $this->init_settings(); $this->enabled = $this->get_option('enabled', 'yes');
   add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
  }
  function init_form_fields() { $this->form_fields = ['enabled' => ['title' => 'Enable', 'type' => 'checkbox', 'label' => 'Offer payment via SHIMI', 'default' => 'yes']]; }
  function is_available() { return $this->enabled === 'yes' && defined('SHEM_HOME_PAYMENT_SECRET') && strlen((string)SHEM_HOME_PAYMENT_SECRET) >= 32 && defined('SHEM_PAY_SESSION_URL'); }

  static function cents($v) { return (int)round(((float)$v) * 100); }

  /* Items carry the VAT-inclusive, post-discount line amount. qty is 1 per line so the sum is exact. */
  static function build_body($order) {
   $items = []; $sum = 0;
   foreach ($order->get_items(['line_item', 'fee']) as $it) {
    $line = self::cents($it->get_total() + $it->get_total_tax());
    $isProd = $it instanceof WC_Order_Item_Product;
    $p = $isProd ? $it->get_product() : null;
    $items[] = ['productId' => (string)($isProd ? $it->get_product_id() : 'fee'), 'sku' => (string)($p ? $p->get_sku() : 'fee'), 'variantId' => (string)($isProd ? $it->get_variation_id() : ''), 'qty' => 1, 'priceCents' => $line, 'name' => mb_substr($it->get_name(), 0, 80), 'unitQty' => $isProd ? (int)$it->get_quantity() : 1];
    $sum += $line;
   }
   $ship = self::cents($order->get_shipping_total() + $order->get_shipping_tax());
   $total = self::cents($order->get_total());
   if ($sum + $ship !== $total) return new WP_Error('totals', 'Order lines do not add up to the order total');
   return ['protocol' => 'shem-home-v1', 'orderId' => (string)$order->get_id(), 'currency' => 'ILS', 'amountCents' => $total,
    'customer' => ['name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()), 'email' => $order->get_billing_email()],
    'items' => $items, 'shippingCents' => $ship, 'discountCents' => 0];
  }

  function process_payment($order_id) {
   $order = wc_get_order($order_id);
   if (!$order) return ['result' => 'failure'];
   if ($order->get_currency() !== 'ILS') { wc_add_notice('התשלום זמין בשקלים בלבד.', 'error'); return ['result' => 'failure']; }
   if ($order->is_paid()) return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
   $body = self::build_body($order);
   if (is_wp_error($body)) { wc_add_notice('לא ניתן לפתוח תשלום להזמנה זו.', 'error'); $order->add_order_note('פתיחת תשלום נכשלה: ' . $body->get_error_message()); return ['result' => 'failure']; }
   $raw = wp_json_encode($body); $ts = (string)time();
   $sig = hash_hmac('sha256', $ts . '.' . $raw, SHEM_HOME_PAYMENT_SECRET);
   $r = wp_remote_post(SHEM_PAY_SESSION_URL, ['timeout' => 20, 'headers' => ['Content-Type' => 'application/json', 'x-shem-timestamp' => $ts, 'x-shem-signature' => $sig] + (defined('SHEM_PAY_PROXY_KEY') ? ['x-pay-gw' => SHEM_PAY_PROXY_KEY] : []), 'body' => $raw]);
   $code = is_wp_error($r) ? 0 : (int)wp_remote_retrieve_response_code($r);
   $j = is_wp_error($r) ? [] : (json_decode(wp_remote_retrieve_body($r), true) ?: []);
   $embed = (string)($j['embedUrl'] ?? '');
   $okOrigin = defined('SHEM_PAY_ALLOWED_ORIGIN') && $embed !== '' && strpos($embed, rtrim(SHEM_PAY_ALLOWED_ORIGIN, '/') . '/') === 0;
   if ($code !== 200 || !$okOrigin) {
    $order->add_order_note('פתיחת תשלום מול SHIMI נכשלה (HTTP ' . $code . ')');
    wc_add_notice('אירעה שגיאה בפתיחת התשלום. לא בוצע חיוב. נסו שוב.', 'error');
    return ['result' => 'failure'];
   }
   $order->update_meta_data('_shem_pay_embed', $embed);
   $order->update_status('pending', 'נפתח תשלום מאובטח ב-SHIMI. ממתין לאישור חתום.');
   $order->save();
   return ['result' => 'success', 'redirect' => $order->get_checkout_payment_url(true)];
  }

  /* The payment-options page: "הזמנתך התקבלה" above the options (the order already exists here). */
  function receipt_page($order_id) {
   static $rendered = false; if ($rendered) return; $rendered = true; /* block themes can run the page content filter several times per request */
   $order = wc_get_order($order_id); if (!$order) return;
   if ($order->is_paid()) { echo '<p>ההזמנה שולמה. <a href="' . esc_url($this->get_return_url($order)) . '">המשך</a></p>'; return; }
   $embed = (string)$order->get_meta('_shem_pay_embed');
   if ($embed === '') { echo '<p dir="rtl">התשלום טרם נפתח. חזרו לעמוד התשלום.</p>'; return; }
   $u = wp_parse_url(SHEM_PAY_ALLOWED_ORIGIN); $origin = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
   $cfg = ['status' => esc_url_raw(rest_url('shem-pay/v1/status')), 'order' => $order->get_id(), 'key' => $order->get_order_key(), 'origin' => $origin, 'src' => (!empty($u['path']) && $u['path'] !== '/') ? 'pay-frame' : 'shimi-pay'];
   echo '<style>.shem-received{text-align:center;margin:1.2em 0 1.4em}.shem-received h1{font-size:clamp(1.9rem,4.5vw,2.8rem);font-weight:700;line-height:1.25;margin:0}.shem-payframe{width:100%;min-height:560px;border:0}</style>';
   echo '<section class="shem-received" dir="rtl" lang="he"><h1>' . esc_html(self::heading()) . '</h1></section>';
   echo '<iframe class="shem-payframe" title="תשלום מאובטח" src="' . esc_url($embed) . '" sandbox="allow-scripts allow-forms allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer"></iframe>';
   echo '<script>(function(){var C=' . wp_json_encode($cfg) . ';var done=false;function poll(){if(done)return;fetch(C.status+"?order="+C.order+"&key="+encodeURIComponent(C.key),{cache:"no-store"}).then(function(r){return r.json()}).then(function(d){if(d&&d.paid&&d.redirect){done=true;location.href=d.redirect}}).catch(function(){})}';
   echo 'window.addEventListener("message",function(e){if(e.origin===C.origin&&e.data&&e.data.source===C.src&&e.data.status==="completed")poll()});setInterval(poll,4000);})();</script>';
  }
  static function heading() { $s = get_option('shem_pay_received_heading', ''); return $s !== '' ? $s : 'הזמנתך התקבלה'; }
 }


 /* In-WordPress pass-through for same-origin payment mode. The customer's browser talks only to this site (/pay-gw/...);
    this code forwards the two allowed request shapes to the payment host with the shared key header. No web-server
    (Apache/nginx/CDN) configuration is needed, so it works on any host. Requires SHEM_PAY_BACKEND and SHEM_PAY_PROXY_KEY. */
 add_action('init', function () {
  if (!defined('SHEM_PAY_BACKEND') || !defined('SHEM_PAY_PROXY_KEY') || SHEM_PAY_PROXY_KEY === '') return;
  $path = (string) wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
  if (!preg_match('#^/pay-gw/(?:p/([a-f0-9]{24})/([a-f0-9]{16,64})|api/paypal/(create|capture))$#', $path, $m)) return;
  $isApi = !empty($m[3]);
  $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
  if ($method !== ($isApi ? 'POST' : 'GET')) { status_header(405); header('Allow: ' . ($isApi ? 'POST' : 'GET')); exit; }
  $base = rtrim((string) SHEM_PAY_BACKEND, '/');
  $args = ['timeout' => 25, 'redirection' => 0, 'method' => $method, 'headers' => ['x-pay-gw' => SHEM_PAY_PROXY_KEY, 'Accept' => $isApi ? 'application/json' : 'text/html']];
  if ($isApi) {
   $body = (string) file_get_contents('php://input', false, null, 0, 65536);
   $args['body'] = $body; $args['headers']['Content-Type'] = 'application/json';
   $url = $base . '/wp-json/shimi-pay/v1/paypal/' . $m[3];
  } else {
   $url = $base . '/?shimi_pay=' . $m[1] . '&token=' . $m[2];
  }
  $r = wp_remote_request($url, $args);
  if (is_wp_error($r)) { status_header(502); header('Cache-Control: no-store'); exit; }
  status_header((int) wp_remote_retrieve_response_code($r));
  foreach (['content-type', 'content-security-policy', 'referrer-policy'] as $h) { $v = wp_remote_retrieve_header($r, $h); if (is_string($v) && $v !== '') header($h . ': ' . $v); }
  header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
  echo wp_remote_retrieve_body($r);
  exit;
 }, 0);

 add_filter('woocommerce_payment_gateways', function ($g) { $g[] = 'WC_Gateway_Shem_Via_Shimi'; return $g; });

 /* Block checkout support: show the same gateway in the WooCommerce Blocks checkout. Payment still runs through process_payment() ->
    receipt page (iframe); the block only lists the method and hands over to the server. */
 add_action('woocommerce_blocks_payment_method_type_registration', function ($reg) {
  if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) return;
  if (!class_exists('Shem_Pay_Blocks')) {
   final class Shem_Pay_Blocks extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    protected $name = 'shem_pay';
    public function initialize() {}
    public function is_active() { $g = new WC_Gateway_Shem_Via_Shimi(); return $g->is_available(); }
    public function get_payment_method_script_handles() {
     wp_register_script('shem-pay-blocks', plugins_url('blocks.js', __FILE__), ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities'], '0.1.2', true);
     return ['shem-pay-blocks'];
    }
    public function get_payment_method_data() { return ['title' => 'PayPal', 'description' => '', 'supports' => ['products']]; }
   }
  }
  $reg->register(new Shem_Pay_Blocks());
 });

 /* Read-only status: "paid" is true only after SHIMI's signed callback marked the order paid. */
 add_action('rest_api_init', function () {
  register_rest_route('shem-pay/v1', '/status', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
   $o = wc_get_order((int)$r->get_param('order'));
   if (!$o || $o->get_payment_method() !== 'shem_pay' || !hash_equals($o->get_order_key(), (string)$r->get_param('key'))) return new WP_REST_Response(['paid' => false], 404);
   $paid = $o->is_paid();
   return new WP_REST_Response(['paid' => $paid, 'redirect' => $paid ? $o->get_checkout_order_received_url() : ''], 200);
  }]);
 });
}, 20);

/* Hide the signed callback route from the public REST index (route stays callable, HMAC-protected). */
add_filter('rest_index', function ($resp) {
    if (is_user_logged_in() && current_user_can('manage_options')) return $resp;
    $d = $resp->get_data();
    if (isset($d['routes'])) foreach (array_keys($d['routes']) as $k) if (strpos($k, '/shem-dok/v1/shimi') === 0) unset($d['routes'][$k]);
    $resp->set_data($d);
    return $resp;
});
add_filter('rest_post_dispatch', function ($result, $server, $request) {
    if (strpos((string)$request->get_route(), '/shem-dok/v1') !== 0 && $request->get_route() !== '/shem-dok') return $result;
    if (is_user_logged_in() && current_user_can('manage_options')) return $result;
    if ($result instanceof WP_REST_Response && $request->get_route() === '/shem-dok/v1') {
        $d = $result->get_data();
        if (isset($d['routes'])) foreach (array_keys($d['routes']) as $k) if (strpos($k, '/shem-dok/v1/shimi') === 0) unset($d['routes'][$k]);
        $result->set_data($d);
    }
    return $result;
}, 10, 3);
