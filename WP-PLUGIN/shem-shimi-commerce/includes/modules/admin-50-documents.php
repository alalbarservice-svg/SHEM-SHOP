<?php
/**
 * Admin documents bridge (Round 2, items 5/7/8).
 *
 * Connects the admin interface to a document engine through the filters declared in admin-30-manage.php:
 *   scc_admin_document_render  kind: html | pdf | order-pdf | template-preview  -> Scc_Response
 *   scc_admin_payment_link     unpaid order -> {embedUrl, orderId}
 *   scc_admin_mail_process     mail is OFF while SCC_INVOICE_DELIVERY_MODE === 'download' (admin-30 already short-circuits); no sender is registered.
 *
 * With SHEM-DOK active the SHEM-DOK invoice is THE document of a paid order: the admin list/links read it (Scc_Core_Admin_InvoiceDocs),
 * the PDF kinds serve its stored, integrity-checked PDF (byte-identical to the customer copy) and "issue document" issues it through
 * ShemDok::issue_for_order (idempotent per order, so it never makes a second document or number). Legacy documents stay readable.
 * Without SHEM-DOK, or for an unpaid order / template preview, this module renders the legacy document and issues nothing.
 * PDF engine: mPDF from this plugin's vendor dir, otherwise from the SHEM-DOK plugin's vendor dir (same site). If neither exists
 * the PDF kinds answer 501 with the reason; html and the other kinds still work.
 */
if (!defined('ABSPATH')) exit;

final class Scc_Admin_Documents {
    private static function mpdf_loader(): ?string {
        foreach ([SCC_DIR . 'vendor/autoload.php', WP_PLUGIN_DIR . '/shem-dok/vendor/autoload.php'] as $f) if (is_file($f)) return $f;
        return null;
    }
    private static function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
    private static function money($cents): string { return number_format(((float) $cents) / 100, 2, '.', ',') . ' ₪'; }

    /** Normalise a legacy document or an unpaid order (admin-30 render() context) into one printable structure. */
    private static function model(array $ctx, bool $preview): array {
        $doc = $ctx['doc'] ?? [];
        $b = (array) ($doc['business'] ?? Scc_Admin::setting('invoice') ?? []);
        $c = (array) ($doc['customer'] ?? []);
        $items = [];
        foreach ((array) ($doc['items'] ?? []) as $i) {
            $qty = (float) ($i['qty'] ?? 1); $unit = (int) ($i['priceCents'] ?? 0);
            $label = (string) ($i['name'] ?? $i['title'] ?? $i['productId'] ?? '');
            $extra = trim(implode(' ', array_filter([(string) ($i['color'] ?? ''), (string) ($i['size'] ?? '')])));
            $items[] = ['name' => $label . ($extra !== '' ? ' - ' . $extra : ''), 'sku' => (string) ($i['sku'] ?? ''), 'qty' => $qty, 'unit' => $unit, 'line' => (int) round($unit * $qty)];
        }
        $total = (int) ($doc['totalCents'] ?? 0);
        return [
            'title' => (string) ($b['documentTitle'] ?? '') !== '' ? (string) $b['documentTitle'] : 'חשבונית עסקה',
            'number' => (string) ($doc['number'] ?? $doc['orderId'] ?? $ctx['id'] ?? ''),
            'issued' => (string) ($doc['issuedAt'] ?? ''),
            'business' => (string) ($b['legalName'] ?? $b['displayName'] ?? ''),
            'regno' => (string) ($b['registrationNumber'] ?? ''),
            'address' => (string) ($b['address'] ?? ''), 'phone' => (string) ($b['phone'] ?? ''), 'email' => (string) ($b['email'] ?? ''),
            'footer' => (string) ($b['documentFooter'] ?? ''),
            'customer' => (string) ($c['name'] ?? ''), 'caddr' => trim(((string) ($c['street'] ?? '')) . ' ' . ((string) ($c['city'] ?? ''))),
            'cphone' => (string) ($c['phone'] ?? ''), 'cemail' => (string) ($c['email'] ?? ''),
            'items' => $items,
            'subtotal' => (int) ($doc['subtotalCents'] ?? 0), 'shipping' => (int) ($doc['shippingCents'] ?? 0), 'discount' => (int) ($doc['discountCents'] ?? 0),
            'total' => $total, 'vat' => (int) ($doc['vatCents'] ?? 0), 'reference' => (string) ($doc['paymentReference'] ?? ''),
            'unpaid' => ($ctx['order'] ?? null) !== null && (($ctx['order']['paymentStatus'] ?? null) !== 'paid'),
            'preview' => $preview,
        ];
    }

    public static function html(array $m): string {
        $e = [self::class, 'e']; $mo = [self::class, 'money'];
        $rows = '';
        foreach ($m['items'] as $x) $rows .= '<tr><td>' . $e($x['name']) . ($x['sku'] !== '' ? '<br><span class="muted">' . $e($x['sku']) . '</span>' : '') . '</td><td>' . $e(rtrim(rtrim(number_format($x['qty'], 2, '.', ''), '0'), '.')) . '</td><td>' . $mo($x['unit']) . '</td><td>' . $mo($x['line']) . '</td></tr>';
        $note = $m['preview'] ? '<p class="muted">תצוגה מקדימה של תבנית המסמך עם נתוני דוגמה בלבד.</p>' : ($m['unpaid'] ? '<p class="muted">הזמנה שטרם שולמה. מסמך זה אינו חשבונית עסקה; חשבונית עסקה מופקת לאחר אישור תשלום.</p>' : '');
        $css = 'body{font-family:dejavusans,Arial,sans-serif;font-size:9pt;color:#171717;direction:rtl}h1{font-size:20pt;font-weight:normal;margin:6px 0}.muted{color:#666;font-size:9pt}table{width:100%;border-collapse:collapse}.items th{background:#111;color:#fff;font-weight:normal;padding:8px 5px;text-align:right}.items td{padding:8px 5px;border-bottom:0.4pt solid #ddd;vertical-align:top}.tot td{padding:4px 5px}.tot td:last-child{white-space:nowrap}.final td{border-top:1pt solid #111;font-size:12pt;font-weight:bold;padding-top:10px}.box{border:0.6pt solid #111;padding:12px}';
        $tot = '<tr><td>סכום ביניים</td><td>' . $mo($m['subtotal']) . '</td></tr>' . ($m['discount'] ? '<tr><td>הנחה</td><td>-' . $mo($m['discount']) . '</td></tr>' : '') . '<tr><td>משלוח</td><td>' . $mo($m['shipping']) . '</td></tr>' . ($m['vat'] ? '<tr><td>מתוכו מע״מ</td><td>' . $mo($m['vat']) . '</td></tr>' : '') . '<tr class="final"><td>סה״כ לתשלום</td><td>' . $mo($m['total']) . '</td></tr>';
        return '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><title>' . $e($m['title'] . ' ' . $m['number']) . '</title><style>' . $css . '</style></head><body><div class="box">'
            . '<table><tr><td style="width:60%"><h1>' . $e($m['title']) . '</h1><div class="muted">מספר ' . $e($m['number']) . ($m['issued'] !== '' ? ' | ' . $e($m['issued']) : '') . '</div></td><td><b>' . $e($m['business']) . '</b><br>' . ($m['regno'] !== '' ? 'ח״פ ' . $e($m['regno']) . '<br>' : '') . $e($m['address']) . '<br>' . $e($m['phone']) . ($m['email'] !== '' ? '<br>' . $e($m['email']) : '') . '</td></tr></table>'
            . '<p><b>לכבוד ' . $e($m['customer']) . '</b><br>' . $e($m['caddr']) . '<br>' . $e($m['cphone']) . ' ' . $e($m['cemail']) . '</p>' . $note
            . '<table class="items"><thead><tr><th style="width:43%">תיאור</th><th style="width:10%">כמות</th><th style="width:23%">מחיר ליחידה</th><th style="width:24%">סכום</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<table class="tot">' . $tot . '</table>' . ($m['reference'] !== '' ? '<p class="muted">אסמכתת תשלום: ' . $e($m['reference']) . '</p>' : '') . ($m['footer'] !== '' ? '<p class="muted">' . nl2br($e($m['footer'])) . '</p>' : '')
            . '</div></body></html>';
    }

    public static function pdf(string $html, string $title): string {
        $loader = self::mpdf_loader();
        if ($loader === null) Scc_Admin::fail('מנוע PDF אינו מותקן באתר זה.', 501);
        require_once $loader;
        $temp = get_temp_dir() . 'scc-admin-mpdf'; if (!is_dir($temp)) wp_mkdir_p($temp);
        $m = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 12, 'margin_bottom' => 14, 'tempDir' => $temp]);
        $m->SetDirectionality('rtl'); $m->SetTitle($title);
        $m->WriteHTML($html);
        return $m->Output('', 'S');
    }

    private static function sample(): array {
        $b = (array) (Scc_Admin::setting('invoice') ?? []);
        return ['id' => 'preview', 'doc' => ['number' => ($b['numberPrefix'] ?? 'DOC-') . '000000', 'business' => $b, 'issuedAt' => gmdate('Y-m-d'),
            'customer' => ['name' => 'לקוח לדוגמה', 'street' => 'רחוב לדוגמה 1', 'city' => 'עיר לדוגמה', 'phone' => '0500000000', 'email' => 'example@example.test'],
            'items' => [['name' => 'פריט לדוגמה', 'sku' => 'SAMPLE-1', 'qty' => 2, 'priceCents' => 10000]], 'subtotalCents' => 20000, 'shippingCents' => 4900, 'discountCents' => 0, 'totalCents' => 24900,
            'vatCents' => (($b['vatMode'] ?? '') === 'included') ? (int) round(24900 * (float) ($b['vatRate'] ?? 0) / (100 + (float) ($b['vatRate'] ?? 0))) : 0]];
    }

    /** SHEM-DOK is the document engine when the plugin is loaded. */
    private static function dok(): bool { return class_exists('ShemDok') && method_exists('ShemDok', 'issue_for_order'); }

    /** Admin "issue document" for a paid order: the SHEM-DOK invoice (an existing one is returned by the store overlay before this runs). */
    public static function issue($null, $order) {
        if ($null !== null || !self::dok() || !is_array($order) || !function_exists('wc_get_order')) return $null;
        // A payment the admin confirmed by hand was not verified by a signed SHIMI callback, so SHEM-DOK refuses to invoice it by design:
        // that order keeps the legacy document path (template check, legacy number) exactly as before.
        if (($order['paymentSource'] ?? '') === 'manual-external') return $null;
        $wc = Scc_Core_Orders::wc_id((string) ($order['id'] ?? '')); $o = $wc ? wc_get_order($wc) : null;
        if (!$o) return $null; // document-table-only order: no WooCommerce order to invoice, the legacy path applies
        try { ShemDok::issue_for_order($o); }
        catch (Throwable $e) { Scc_Admin::fail('הפקת החשבונית נכשלה: ' . $e->getMessage(), $e instanceof ShemDokPermanent ? 409 : 502); }
        $doc = Scc_Admin::store('invoice_documents')->get((string) $order['id']);
        if (!$doc) Scc_Admin::fail('החשבונית לא נמצאה לאחר ההפקה.', 502);
        return ['document' => $doc, 'mail' => Scc_Admin_Manage::process_mail(false)];
    }

    /** PDF of a SHEM-DOK invoice: the stored copy, never re-rendered and never a new number. */
    private static function dok_pdf(array $doc, string $kind) {
        if ($kind === 'html') Scc_Admin::fail('תצוגת HTML אינה זמינה למסמך SHEM-DOK. יש להשתמש ב-PDF.', 501);
        try { $bytes = ShemDok::stored_pdf((int) $doc['dokId']); }
        catch (ShemDokHttp $e) { Scc_Admin::fail($e->getMessage(), (int) $e->getCode() ?: 502); }
        return Scc_Response::raw($bytes, 'application/pdf', 200, ['content-disposition' => 'inline; filename="SHEM-' . (int) $doc['dokId'] . '.pdf"', 'x-content-type-options' => 'nosniff']);
    }

    public static function render($null, string $kind, array $ctx) {
        if ($null !== null) return $null;
        $dd = $kind === 'order-pdf' ? ($ctx['invoice'] ?? null) : ($ctx['doc'] ?? null);
        if (self::dok() && is_array($dd) && ($dd['engine'] ?? '') === 'shem-dok' && in_array($kind, ['html', 'pdf', 'order-pdf'], true)) return self::dok_pdf($dd, $kind);
        if (!in_array($kind, ['html', 'pdf', 'order-pdf', 'template-preview'], true)) return null;
        $preview = $kind === 'template-preview';
        if ($preview) { $want_pdf = !empty($ctx['pdf']); $ctx = self::sample(); }
        else $want_pdf = $kind !== 'html';
        $m = self::model($ctx, $preview);
        $html = self::html($m);
        if (!$want_pdf) return Scc_Response::raw($html, 'text/html; charset=utf-8', 200, ['x-content-type-options' => 'nosniff']);
        $bytes = self::pdf($html, $m['title'] . ' ' . $m['number']);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $m['number']);
        return Scc_Response::raw($bytes, 'application/pdf', 200, ['content-disposition' => 'inline; filename="' . ($safe !== '' ? $safe : 'document') . '.pdf"', 'x-content-type-options' => 'nosniff']);
    }

    /** Admin "payment link" for an unpaid SHEM order: a gateway session over the matching WooCommerce order. Sandbox/provider only; never charges here. */
    public static function payment_link($null, $order, $origin) {
        if ($null !== null || !is_array($order)) return $null;
        if (!function_exists('WC') || !WC() || !WC()->payment_gateways()) return null;
        $gws = WC()->payment_gateways()->payment_gateways(); $gw = $gws['shem_pay'] ?? null;
        if (!is_object($gw) || !$gw->is_available()) return null;
        $found = wc_get_orders(['limit' => 1, 'return' => 'objects', 'meta_key' => '_scc_legacy_id', 'meta_value' => (string) ($order['id'] ?? '')]);
        $wc = $found[0] ?? null; if (!$wc || $wc->is_paid()) return null;
        if (WC()->session === null && class_exists('WC_Session_Handler')) WC()->session = new WC_Session_Handler();
        $res = $gw->process_payment($wc->get_id());
        $wc = wc_get_order($wc->get_id()); $embed = $wc ? (string) $wc->get_meta('_shem_pay_embed') : '';
        if (function_exists('wc_clear_notices') && WC()->session) wc_clear_notices();
        if (!is_array($res) || ($res['result'] ?? '') !== 'success' || $embed === '') Scc_Admin::fail('אתר הבית לא אישר יצירת קישור תשלום.', 502);
        return ['embedUrl' => $embed, 'orderId' => (string) $order['id']];
    }
}
add_filter('scc_admin_document_render', ['Scc_Admin_Documents', 'render'], 10, 3);
add_filter('scc_admin_issue_document', ['Scc_Admin_Documents', 'issue'], 10, 2);
add_filter('scc_admin_payment_link', ['Scc_Admin_Documents', 'payment_link'], 10, 3);
