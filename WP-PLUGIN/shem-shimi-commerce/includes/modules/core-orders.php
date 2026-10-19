<?php
if (!defined('ABSPATH')) exit;
/**
 * Core shop API - orders: GET /api/checkout-status/{id}?token=, GET /api/checkout-invoice/{id}?token=&download=1,
 * and the WooCommerce hooks that keep the legacy order document in step with WooCommerce (paid, stock reduced).
 * Port of management.js publicCommerce(). Orders are WooCommerce orders; the legacy JSON lives in order meta (lib/core-order-store.php).
 * The invoice PDF is produced and stored by the SHEM-DOK plugin (ShemDok::invoice_for_order / ShemDok::stored_pdf); nothing is rebuilt here.
 */
require_once SCC_DIR . 'includes/lib/core-util.php';
require_once SCC_DIR . 'includes/lib/core-products.php';
require_once SCC_DIR . 'includes/lib/core-order-store.php';

final class Scc_Core_Order_Routes {
    private static function not_found(): Scc_Response { return Scc_Core_Util::json(['error' => 'Not found'], 404); }
    private static function last_segment(string $rest): string { $p = explode('/', $rest); return (string) end($p); }

    /** The order for {id} when the view token matches, else null. */
    private static function viewable(Scc_Request $r): ?array {
        $id = self::last_segment((string) ($r->params[1] ?? ''));
        $token = isset($r->query['token']) && is_string($r->query['token']) ? wp_unslash($r->query['token']) : null;
        if ($id === '' || $token === null || $token === '') return null;
        $order = Scc_Core_Orders::doc($id);
        if (!$order) return null;
        $vt = $order['viewToken'] ?? null;
        return is_string($vt) && $vt !== '' && hash_equals($vt, $token) ? $order : null;
    }

    public static function status(Scc_Request $r): Scc_Response {
        $order = self::viewable($r);
        if (!$order) return self::not_found();
        $ready = ($order['paymentStatus'] ?? null) === 'paid' && Scc_Core_Orders::invoice_ready($order);
        return Scc_Core_Util::json(['paymentStatus' => $order['paymentStatus'] ?? null, 'orderId' => $order['id'], 'invoiceReady' => $ready]);
    }

    public static function invoice(Scc_Request $r): Scc_Response {
        $order = self::viewable($r);
        if (!$order) return self::not_found();
        if (($order['paymentStatus'] ?? null) !== 'paid') return Scc_Core_Util::json(['error' => 'התשלום עדיין לא אושר.'], 409);
        if (!Scc_Core_Orders::invoice_ready($order)) return Scc_Core_Util::json(['error' => 'חשבונית העסקה עדיין אינה זמינה.'], 409);
        $pdf = Scc_Core_Orders::invoice_pdf($order);
        if ($pdf === null) return Scc_Core_Util::json(['error' => 'חשבונית העסקה עדיין אינה זמינה.'], 409);
        $brand = defined('SCC_ROLE') && SCC_ROLE === 'shimi' ? 'SHIMI' : 'SHEM';
        $name = $brand . '-invoice-' . preg_replace('/[^a-zA-Z0-9-]/', '', (string) $order['id']) . '.pdf';
        $dl = ($r->query['download'] ?? null) === '1';
        return Scc_Response::raw($pdf, 'application/pdf', 200, [
            'content-disposition' => ($dl ? 'attachment' : 'inline') . '; filename="' . $name . '"',
            'cache-control' => 'private,no-store', 'referrer-policy' => 'no-referrer', 'x-content-type-options' => 'nosniff',
        ]);
    }
}

Scc_Router::add('GET', '#^/api/checkout-status/(.*)$#', ['Scc_Core_Order_Routes', 'status']);
Scc_Router::add('GET', '#^/api/checkout-invoice/(.*)$#', ['Scc_Core_Order_Routes', 'invoice']);

/* WooCommerce -> legacy document. Priority 20 runs before SHEM-DOK (30), which issues the invoice from the same order. */
add_action('woocommerce_payment_complete', function ($order_id) { Scc_Core_Orders::on_paid((int) $order_id); }, 20, 1);
foreach (['woocommerce_order_status_processing', 'woocommerce_order_status_completed'] as $h) add_action($h, function ($order_id) { Scc_Core_Orders::on_paid((int) $order_id); }, 20, 1);
add_action('woocommerce_reduce_order_stock', function ($order) { if ($order instanceof WC_Order) Scc_Core_Orders::on_stock_reduced($order); }, 20, 1);

/* WooCommerce order-received URL -> the storefront thank-you page (/order-complete.html), which carries the approved wording and the invoice buttons.
 * Only for orders that have a legacy id and view token; any other order keeps the WooCommerce URL. */
add_filter('woocommerce_get_checkout_order_received_url', function ($url, $order) {
    if (!$order instanceof WC_Order) return $url;
    $id = (string) $order->get_meta('_scc_legacy_id'); $tok = (string) $order->get_meta('_scc_view_token');
    if ($id === '' || $tok === '') return $url;
    return home_url('/order-complete.html') . '#order=' . rawurlencode($id) . '&token=' . rawurlencode($tok);
}, 20, 2);
