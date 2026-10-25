<?php
if (!defined('ABSPATH')) exit;
/**
 * Core shop API - checkout: POST /api/quote and POST /api/checkout-home (SHEM open payment through the SHEM-PAY gateway).
 * Port of operations.js (quote) and management.js publicCommerce() (/api/checkout-home). The pre-payment review
 * (/api/checkout-review) and the SHIMI own checkout (/api/shimi/checkout) belong to the channel module; this module feeds them through the seams
 *   Scc_Core::quote(), filter scc_channel_make_order, action scc_channel_store_order.
 *
 * Open payment, step by step (same order as the worker):
 *   gate -> terms -> customer -> rate limit -> quote -> review token (Scc_Channel_Review::validate_token) -> order draft ->
 *   WooCommerce order (pending, legacy document in meta) -> stock holds (Scc_Channel_Stock::reserve) -> gateway shem_pay ->
 *   201 {embedUrl, orderId (legacy UUID), viewToken}. On any failure the holds are released and the worker's error is returned;
 *   the unpaid order stays (like the worker's unpaid order document).
 * A WooCommerce order becomes paid only from the gateway's signed callback (SHEM-DOK /shimi/paid -> payment_complete), see core-orders.php.
 *
 * Filters: scc_core_checkout_enabled (bool $enabled, array $operations, array $home) -> bool, scc_core_require_home_sku (bool, default false).
 */
require_once SCC_DIR . 'includes/lib/core-util.php';
require_once SCC_DIR . 'includes/lib/core-products.php';
require_once SCC_DIR . 'includes/lib/core-pricing.php';
require_once SCC_DIR . 'includes/lib/core-order-store.php';

final class Scc_Core_Checkout {
    const GATEWAY = 'shem_pay';

    private static function gateway(): ?object {
        if (!function_exists('WC') || !WC() || !WC()->payment_gateways()) return null;
        $g = WC()->payment_gateways()->payment_gateways();
        return isset($g[self::GATEWAY]) && is_object($g[self::GATEWAY]) ? $g[self::GATEWAY] : null;
    }
    private static function setting_arr(string $k): array { $v = Scc_Docs::setting($k, null); return is_array($v) ? $v : []; }

    /** POST /api/quote */
    public static function quote_route(Scc_Request $r): Scc_Response {
        Scc_Core_Util::guard_mutation($r);
        return Scc_Core_Util::json(Scc_Core_Pricing::quote(Scc_Core_Util::parse($r)));
    }

    /** The WooCommerce session is absent on API requests; wc_add_notice() inside the gateway would fail without one. */
    private static function ensure_session(): void {
        if (!function_exists('WC') || !WC()) return;
        if (WC()->session === null && class_exists('WC_Session_Handler')) WC()->session = new WC_Session_Handler();
    }

    /** POST /api/checkout-home */
    public static function checkout_home(Scc_Request $r): Scc_Response {
        Scc_Core_Util::guard_mutation($r);
        if (defined('SCC_ROLE') && SCC_ROLE === 'shimi') Scc_Core_Util::fail('הרכישה עדיין אינה פעילה. אתר הבית טרם חובר.', 503);
        $config = self::setting_arr('operations'); $home = self::setting_arr('home');
        $gw = self::gateway();
        $enabled = !empty($config['checkoutEnabled']) && !empty($home['enabled']) && $gw && $gw->is_available();
        if (!apply_filters('scc_core_checkout_enabled', $enabled, $config, $home)) Scc_Core_Util::fail('הרכישה עדיין אינה פעילה. אתר הבית טרם חובר.', 503);
        $d = Scc_Core_Util::obj(Scc_Core_Util::parse($r));
        if (($d['termsAccepted'] ?? null) !== true) Scc_Core_Util::fail('יש לאשר תנאי רכישה.');
        $customer = Scc_Core_Pricing::clean_customer($d['customer'] ?? []);
        if ($customer['phone'] === '' || $customer['city'] === '' || $customer['street'] === '') Scc_Core_Util::fail('נדרשים טלפון וכתובת משלוח.');
        $recent = wc_get_orders(['limit' => 10, 'return' => 'ids', 'type' => 'shop_order', 'status' => array_keys(wc_get_order_statuses()), 'meta_key' => Scc_Core_Orders::M_EMAIL, 'meta_value' => $customer['email'], 'date_created' => '>' . (time() - 3600)]);
        if (count($recent) >= 10) Scc_Core_Util::fail('נוצרו הזמנות רבות בזמן קצר.', 429);

        $quote = Scc_Core_Pricing::quote(['items' => $d['items'] ?? null, 'shipping' => $d['shipping'] ?? null, 'code' => $d['code'] ?? null]);
        if (class_exists('Scc_Channel_Review')) Scc_Channel_Review::validate_token($d, $quote);

        $items = [];
        foreach ((array) ($d['items'] ?? []) as $i) {
            $i = is_array($i) ? $i : [];
            $pid = Scc_Core_Util::truthy($i['id'] ?? null) ? $i['id'] : ($i['productId'] ?? null);
            $items[] = ['productId' => $pid] + $i;
        }
        $draft = Scc_Core_Pricing::make_order(array_merge($d, ['items' => $items, 'shippingCost' => ($d['shipping'] ?? null) === 'express' ? ($config['expressPrice'] ?? 199) : ($config['standardPrice'] ?? 49),
            'discount' => $quote['discount'], 'shippingMethod' => $d['shipping'] ?? null]));
        if (apply_filters('scc_core_require_home_sku', false)) foreach ($draft['items'] as $i) if (($i['homeSku'] ?? '') === '') Scc_Core_Util::fail('חסר מיפוי מק״ט לאתר הבית.', 409);
        if (class_exists('Scc_Channel') && Scc_Channel::secret() !== '' && class_exists('Scc_Channel_Links')) {
            foreach ($draft['items'] as $i) {
                $link = Scc_Channel_Links::get((string) $i['productId']);
                if (!$link || ($link['status'] ?? null) !== 'connected') Scc_Core_Util::fail('המוצר אינו מקושר ל-SHIMI.', 409);
                if (($i['variantId'] ?? '') !== '' && !in_array($i['variantId'], array_values((array) ($link['variantMap'] ?? [])), true)) Scc_Core_Util::fail('גרסת המוצר אינה מקושרת.', 409);
            }
        }
        $id = Scc_Core_Util::uuid();
        $order = array_merge($draft, ['id' => $id, 'source' => 'storefront', 'status' => 'new', 'paymentStatus' => 'unpaid', 'paid' => false,
            'createdAt' => Scc_Core_Util::now_iso(), 'viewToken' => Scc_Core_Util::uuid() . Scc_Core_Util::uuid()]);

        $code = (string) ($quote['promotion']['code'] ?? '');
        $uid = is_user_logged_in() ? get_current_user_id() : 0;
        $wc = Scc_Core_Orders::create_wc_order($order, $code, $uid);
        $held = false;
        try {
            if (class_exists('Scc_Channel_Stock')) { Scc_Channel_Stock::reserve($id, $draft['items']); $held = true; }
            self::ensure_session();
            $res = $gw->process_payment($wc->get_id());
            $wc = wc_get_order($wc->get_id());
            $embed = $wc ? (string) $wc->get_meta('_shem_pay_embed') : '';
            if (!is_array($res) || ($res['result'] ?? '') !== 'success' || $embed === '') throw new Scc_Http_Exception('אתר הבית לא אישר יצירת תשלום.', 502);
        } catch (Throwable $e) {
            if ($held) Scc_Channel_Stock::release($id);
            if (function_exists('wc_clear_notices') && function_exists('WC') && WC() && WC()->session) wc_clear_notices();
            if ($e instanceof Scc_Http_Exception) throw $e;
            error_log('[scc-core] checkout-home: ' . $e->getMessage());
            throw new Scc_Http_Exception('אתר הבית לא אישר יצירת תשלום.', 502);
        }
        if (function_exists('wc_clear_notices') && WC()->session) wc_clear_notices();
        return Scc_Core_Util::json(['embedUrl' => $embed, 'orderId' => $id, 'viewToken' => $order['viewToken']], 201);
    }

    /** filter scc_channel_make_order: the channel module's SHIMI own checkout asks the core for the order draft. */
    public static function make_order_seam($null, array $d) { return $null === null ? Scc_Core_Pricing::make_order($d) : $null; }
    /** action scc_channel_store_order: persist the new unpaid order as a WooCommerce order (so payment_complete etc. work). */
    public static function store_order_seam(array $order): void {
        $quoteCode = (string) ($order['couponCode'] ?? '');
        Scc_Core_Orders::create_wc_order($order, $quoteCode, 0);
    }
}

Scc_Router::add('POST', '#^/api/quote$#', ['Scc_Core_Checkout', 'quote_route']);
Scc_Router::add('POST', '#^/api/checkout-home$#', ['Scc_Core_Checkout', 'checkout_home']);
add_filter('scc_channel_make_order', ['Scc_Core_Checkout', 'make_order_seam'], 10, 2);
add_action('scc_channel_store_order', ['Scc_Core_Checkout', 'store_order_seam'], 10, 1);
