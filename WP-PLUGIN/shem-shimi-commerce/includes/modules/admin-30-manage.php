<?php
if (!defined('ABSPATH')) exit;
/**
 * Admin: /api/admin/manage/* dispatcher (port of SHEM|SHIMI worker/management.js `management()`), orders, documents, mail,
 * inventory. Kinds that belong to the CRM screens (collections, customers, cards, requests, activities, birthday,
 * loyalty-adjust) are delegated to Scc_Admin_Crm (admin-40-crm.php).
 *
 * Seams to other modules (nothing is stubbed silently - when the seam is empty the route answers 501 with the hook name):
 *   scc_admin_confirm_payment  filter ($null,$orderId,$reference,$source,$details) -> array order | WP_Error | null (null = built-in document-store port)
 *   scc_admin_issue_document   filter ($null,$order)                              -> array {document,mail} | null (null = built-in port)
 *   scc_admin_document_render  filter ($null,$kind,$ctx)                          -> Scc_Response | null     kind: pdf|order-pdf|html|template-preview
 *   scc_admin_payment_link     filter ($null,$order,$origin)                      -> array {embedUrl,orderId} | null
 *   scc_admin_mail_process     filter ($null)                                     -> array {sent:int} | null
 */
final class Scc_Admin_Manage {
    const TABLES = ['collections' => 'collections', 'customers' => 'customers', 'cards' => 'member_profiles', 'requests' => 'requests', 'activities' => 'activities',
        'orders' => 'orders', 'products' => 'products', 'promotions' => 'promotions', 'stock' => 'stock_movements', 'loyalty' => 'loyalty_ledger',
        'documents' => 'invoice_documents', 'mail' => 'mail_jobs'];

    /* ------------------------------------------------------------ helpers (original: str/email/amount/cleanPayment/cleanCustomer/save) */
    /** String(v) for scalars; '' otherwise. */
    public static function jsstr(mixed $v): string { return is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string) $v : ''); }
    public static function stamp(): string { return Scc_Admin::stamp(); }
    public static function plus_days(float $days): string { return Scc_Admin::iso_ms(Scc_Admin::ms() + (int) round($days * 86400000)); }
    public static function delivery_download(): bool { return defined('SCC_INVOICE_DELIVERY_MODE') && SCC_INVOICE_DELIVERY_MODE === 'download'; }
    /** Date.parse for ISO-ish strings (ms), NaN when unparsable. */
    public static function date_ms(mixed $s): float { if (!is_string($s) || $s === '') return NAN; $t = strtotime($s); if ($t === false) return NAN; $ms = 0; if (preg_match('/\.(\d{1,3})\d*(Z|[+-]\d\d:?\d\d)?$/', $s, $m)) $ms = (int) str_pad($m[1], 3, '0'); return $t * 1000 + $ms; }

    public static function clean_payment(mixed $d): array {
        $d = is_array($d) ? $d : [];
        $method = in_array($d['method'] ?? null, ['card', 'bank-transfer', 'cash', 'other'], true) ? $d['method'] : 'other';
        $last4 = $d['last4'] ?? null;
        if (Scc_Admin::truthy($last4) && !preg_match('/^\d{4}\z/', self::jsstr($last4))) Scc_Admin::fail('יש להזין ארבע ספרות אחרונות בלבד.');
        $inst = Scc_Admin::num(Scc_Admin::truthy($d['installments'] ?? null) ? $d['installments'] : 1);
        if (!Scc_Admin::is_int_val($inst) || $inst < 1 || $inst > 36) Scc_Admin::fail('מספר תשלומים לא תקין.');
        return ['method' => $method, 'last4' => Scc_Admin::str(Scc_Admin::truthy($last4) ? self::jsstr($last4) : '', 4), 'cardholderName' => Scc_Admin::str($d['cardholderName'] ?? null, 150),
            'brand' => Scc_Admin::str($d['brand'] ?? null, 40), 'authorizationCode' => Scc_Admin::str($d['authorizationCode'] ?? null, 60), 'installments' => Scc_Admin::nv($inst)];
    }
    public static function clean_customer(array $d): array {
        $c = ['name' => Scc_Admin::str($d['name'] ?? null, 150), 'email' => mb_strtolower(Scc_Admin::str($d['email'] ?? null, 254)), 'phone' => Scc_Admin::str($d['phone'] ?? null, 40),
            'city' => Scc_Admin::str($d['city'] ?? null, 150), 'street' => Scc_Admin::str($d['street'] ?? null, 300), 'apartment' => Scc_Admin::str($d['apartment'] ?? null, 50), 'postcode' => Scc_Admin::str($d['postcode'] ?? null, 30)];
        if ($c['name'] === '' || !Scc_Admin::email($c['email'])) Scc_Admin::fail('שם ואימייל תקינים נדרשים.');
        return $c;
    }
    /** Original save(): revision compare-and-swap when an expected revision is known, plain upsert otherwise. */
    public static function save(string $table, string $id, array $data, ?string $expected): array {
        $st = Scc_Admin::store($table); $rev = Scc_Admin::now(); unset($data['revision']);
        if ($expected !== null && $expected !== '') { if (!$st->cas($id, $data, $expected, $rev)) Scc_Admin::fail('הרשומה השתנתה. רעננו לפני שמירה.', 409); }
        else $st->upsert($id, $data, $rev);
        return array_merge($data, ['id' => $id, 'revision' => $rev]);
    }
    /** d.revision || old.revision */
    public static function expected(array $d, ?array $old): ?string {
        $e = Scc_Admin::truthy($d['revision'] ?? null) ? $d['revision'] : ($old['revision'] ?? null);
        return is_scalar($e) && $e !== '' && $e !== false ? self::jsstr($e) : null;
    }
    /** record without server-side keys (revision) for re-saving */
    public static function plain(array $r): array { unset($r['revision']); return $r; }
    /** sizeStock must stay a JSON object even when its keys look like a list (0,1,2...). */
    public static function fix_sizes(array $p): array { if (isset($p['sizeStock']) && is_array($p['sizeStock'])) $p['sizeStock'] = (object) $p['sizeStock']; return $p; }
    public static function recount(array &$p): void {
        $p['stock'] = array_sum(array_column($p['variants'], 'stock'));
        $ss = [];
        foreach ($p['sizes'] as $s) { $n = 0; foreach ($p['variants'] as $v) if ($v['size'] === $s) $n += $v['stock']; $ss[(string) $s] = $n; }
        $p['sizeStock'] = $ss;
    }

    /* ------------------------------------------------------------ orders: makeOrder */
    public static function shipping_label(array $d): string {
        $l = $d['shippingLabel'] ?? null; if (Scc_Admin::truthy($l) && is_string($l)) return $l;
        $m = $d['shippingMethod'] ?? null;
        if (is_string($m) && isset(['standard' => 1, 'express' => 1][$m])) return ['standard' => 'שליח עד הבית - 5-7 ימי עסקים', 'express' => 'שליח עד הבית - יום עסקים אחד'][$m];
        return is_string($m) && $m !== '' ? $m : 'שליח עד הבית';
    }
    public static function make_order(array $d): array {
        $customer = self::clean_customer(is_array($d['customer'] ?? null) ? $d['customer'] : []);
        if (!is_array($d['items'] ?? null) || !array_is_list($d['items']) || !$d['items'] || count($d['items']) > 100) Scc_Admin::fail('יש להוסיף פריטים להזמנה.');
        $products = Scc_Admin::store('products')->rows();
        $items = []; $seen = [];
        foreach ($d['items'] as $x) {
            $x = is_array($x) ? $x : [];
            $p = null; foreach ($products as $q) if (($q['id'] ?? null) === ($x['productId'] ?? null) && empty($q['archived'])) { $p = $q; break; }
            $qty = Scc_Admin::num($x['qty'] ?? null);
            if (!$p || !in_array($x['size'] ?? null, $p['sizes'] ?? [], true) || !Scc_Admin::is_int_val($qty) || $qty < 1 || $qty > 1000) Scc_Admin::fail('פריט, מידה או כמות אינם תקינים.');
            $variant = null; foreach (($p['variants'] ?? []) as $v) if (($v['id'] ?? null) === ($x['variantId'] ?? null)) { $variant = $v; break; }
            if (!empty($p['variants']) && !$variant) Scc_Admin::fail('יש לבחור גרסת מוצר.');
            if ($variant && $variant['size'] !== $x['size']) Scc_Admin::fail('המידה אינה תואמת לגרסה.');
            $key = $p['id'] . ':' . ($variant['id'] ?? $x['size']);
            if (isset($seen[$key])) Scc_Admin::fail('יש לאחד פריטים כפולים.');
            $seen[$key] = true;
            $items[] = ['productId' => $p['id'], 'variantId' => $variant['id'] ?? '', 'name' => $p['he'], 'sku' => Scc_Admin::truthy($variant['sku'] ?? null) ? $variant['sku'] : ($p['sku'] ?? null),
                'homeSku' => Scc_Admin::truthy($variant['homeSku'] ?? null) ? $variant['homeSku'] : ($p['homeSku'] ?? ''), 'size' => $x['size'],
                'color' => Scc_Admin::truthy($variant['color'] ?? null) ? $variant['color'] : ($p['color'] ?? null), 'qty' => Scc_Admin::nv($qty), 'priceCents' => Scc_Admin::nv(Scc_Admin::amount($p['price']))];
        }
        $subtotal = 0; foreach ($items as $i) $subtotal += $i['qty'] * $i['priceCents'];
        $shipping = Scc_Admin::amount(array_key_exists('shippingCost', $d) && $d['shippingCost'] !== null ? $d['shippingCost'] : 49);
        $discount = Scc_Admin::amount(array_key_exists('discount', $d) && $d['discount'] !== null ? $d['discount'] : 0);
        if (!Scc_Admin::is_int_val($shipping) || $shipping < 0 || $shipping > 100000 || !Scc_Admin::is_int_val($discount) || $discount < 0 || $discount > $subtotal) Scc_Admin::fail('משלוח או הנחה אינם תקינים.');
        $total = $subtotal + $shipping - $discount;
        return ['customer' => $customer, 'items' => $items, 'subtotalCents' => $subtotal, 'shippingCents' => Scc_Admin::nv($shipping), 'discountCents' => Scc_Admin::nv($discount), 'vatRate' => 18,
            'vatItemsCents' => Scc_Admin::nv(Scc_Admin::round((($subtotal - $discount) * 18) / 118)), 'vatShippingCents' => Scc_Admin::nv(Scc_Admin::round(($shipping * 18) / 118)),
            'shippingLabel' => self::shipping_label($d), 'totalCents' => Scc_Admin::nv($total), 'total' => Scc_Admin::nv($total / 100),
            'shippingMethod' => Scc_Admin::str($d['shippingMethod'] ?? null) ?: 'standard', 'notes' => Scc_Admin::str($d['notes'] ?? null, 2000)];
    }

    /* ------------------------------------------------------------ mail */
    /** processMail(): the sender itself belongs to the mail module (filter). $strict: 501 when mail is configured but nothing processes it. */
    public static function process_mail(bool $strict = true): array {
        if (self::delivery_download()) return ['sent' => 0, 'blocked' => 'מסמכי ההזמנה זמינים להורדה באתר; דיוור כבוי.'];
        if (!Scc_Admin_Settings::mail_configured()) return ['sent' => 0, 'blocked' => 'נדרשים שירות מייל ודומיין שולח מאומת.'];
        $r = apply_filters('scc_admin_mail_process', null);
        if (is_array($r)) return $r;
        if ($strict) Scc_Admin::fail('שירות הדיוור אינו מחובר עדיין (scc_admin_mail_process).', 501);
        return ['sent' => 0, 'blocked' => 'שירות הדיוור אינו מחובר עדיין.'];
    }

    /* ------------------------------------------------------------ documents / sequences */
    public static function next_sequence(): int {
        global $wpdb; $t = Scc_Docs::t('document_sequences'); $now = Scc_Admin::now();
        $wpdb->query($wpdb->prepare("INSERT INTO $t (id,data,updated_at) VALUES ('transaction','{\"value\":1}',%s) ON DUPLICATE KEY UPDATE data=JSON_SET(data,'$.value',CAST(JSON_VALUE(data,'$.value') AS UNSIGNED)+1),updated_at=VALUES(updated_at)", $now));
        return (int) $wpdb->get_var("SELECT JSON_VALUE(data,'$.value') FROM $t WHERE id='transaction'");
    }
    /** Invoice document of a paid order (same keys as the worker). */
    public static function build_document(array $order, array $business, string $number, string $issuedAt): array {
        $vatRate = Scc_Admin::num($business['vatRate'] ?? 0); if (is_nan($vatRate)) $vatRate = 0.0;
        $doc = ['id' => $order['id'], 'number' => $number, 'business' => $business, 'customer' => $order['customer'] ?? null, 'items' => $order['items'] ?? null, 'orderId' => $order['id'], 'issuedAt' => $issuedAt];
        foreach (['subtotalCents', 'shippingCents', 'discountCents', 'totalCents', 'shippingMethod', 'shippingLabel', 'vatRate', 'vatItemsCents', 'vatShippingCents'] as $k) if (array_key_exists($k, $order)) $doc[$k] = $order[$k];
        $doc['vatCents'] = ($business['vatMode'] ?? null) === 'included' ? Scc_Admin::nv(Scc_Admin::round(((float) $order['totalCents'] * $vatRate) / (100 + $vatRate))) : 0;
        $doc['paymentReference'] = $order['paymentReference'] ?? null;
        return $doc;
    }
    private static function number_prefix(array $b): string { return Scc_Admin::truthy($b['numberPrefix'] ?? null) ? (string) $b['numberPrefix'] : Scc_Admin::brand() . '-'; }

    /* ------------------------------------------------------------ payment confirmation */
    public static function confirm_payment(string $orderId, string $reference, string $source, mixed $details): array {
        $r = apply_filters('scc_admin_confirm_payment', null, $orderId, $reference, $source, $details);
        if ($r instanceof WP_Error) { $data = $r->get_error_data(); Scc_Admin::fail($r->get_error_message(), is_array($data) ? (int) ($data['status'] ?? 409) : 409); }
        if (is_array($r)) return $r;
        return self::confirm_payment_docs($orderId, $reference, $source, $details);
    }
    /** Port of confirmPayment() over the document tables (single DB transaction + revision checks instead of a D1 batch). */
    private static function confirm_payment_docs(string $orderId, string $reference, string $source, mixed $details): array {
        $orders = Scc_Admin::store('orders'); $products = Scc_Admin::store('products');
        $order = $orders->get($orderId);
        if (!$order || !empty($order['archived'])) Scc_Admin::fail('הזמנה לא נמצאה.', 404);
        if (($order['paymentStatus'] ?? null) === 'paid') return $order;
        if ($reference === '') Scc_Admin::fail('נדרשת אסמכתת תשלום.');
        $generic = static fn() => new Scc_Http_Exception(Scc_Admin::GENERIC_503, 503);
        return Scc_Admin::tx(function () use ($orderId, $reference, $source, $details, $orders, $products, $order, $generic) {
            $rev = Scc_Admin::now(); $stamp = Scc_Admin::stamp(); $changed = []; $oldRev = [];
            foreach (($order['items'] ?? []) as $i) {
                $p = $changed[$i['productId']] ?? $products->get($i['productId']);
                if (!$p || !empty($p['archived'])) Scc_Admin::fail('מוצר אינו זמין.');
                if (Scc_Admin::is_home() && Scc_Admin_Catalog::linked($p['id'])) {
                    if (empty($order['centralInventoryConfirmed'])) Scc_Admin::fail('יש לאמת מלאי מרכזי ב-SHEM לפני אישור תשלום.');
                    continue;
                }
                $oldRev[$p['id']] ??= $p['revision'];
                $av = class_exists('Scc_Channel_Stock') ? Scc_Channel_Stock::view($p, $orderId) : $p;
                $avVariant = null; foreach (($av['variants'] ?? []) as $v) if ($v['id'] === ($i['variantId'] ?? null)) { $avVariant = $v; break; }
                $avStock = $avVariant['stock'] ?? ($av['sizeStock'][$i['size']] ?? $av['stock']);
                if ($avStock < $i['qty'] || $av['stock'] < $i['qty']) Scc_Admin::fail('המלאי שמור להזמנה אחרת.', 409);
                $vi = null; foreach (($p['variants'] ?? []) as $k => $v) if ($v['id'] === ($i['variantId'] ?? null)) { $vi = $k; break; }
                $stock = $vi !== null ? $p['variants'][$vi]['stock'] : ($p['sizeStock'][$i['size']] ?? $p['stock']);
                if ($stock < $i['qty'] || $p['stock'] < $i['qty']) Scc_Admin::fail('אין מספיק מלאי להשלמת ההזמנה.', 409);
                if ($vi !== null) { $p['variants'][$vi]['stock'] -= $i['qty']; self::recount($p); }
                else { $p['stock'] -= $i['qty']; if (isset($p['sizeStock'])) $p['sizeStock'][$i['size']] -= $i['qty']; }
                $changed[$p['id']] = $p;
                $mid = Scc_Admin::uuid();
                Scc_Admin::store('stock_movements')->upsert($mid, ['productId' => $p['id'], 'size' => $i['size'], 'variantId' => $i['variantId'] ?? '', 'delta' => -$i['qty'], 'reason' => 'תשלום הזמנה ' . $orderId, 'createdAt' => $stamp, 'system' => true], $rev);
            }
            foreach ($changed as $pid => $p) {
                if (!$products->cas($pid, self::fix_sizes(self::plain($p)), $oldRev[$pid], $rev)) throw $generic();
                Scc_Admin_Catalog::guard_stock($p, $orderId);
            }
            $paid = array_merge(self::plain($order), ['paymentStatus' => 'paid', 'paid' => true, 'paidAt' => $stamp, 'paymentReference' => $reference, 'paymentSource' => $source,
                'paymentDetails' => self::clean_payment($details), 'invoiceDocumentId' => $orderId, 'status' => 'processing']);
            $business = Scc_Admin::setting('invoice'); $business = is_array($business) ? $business : [];
            $complete = Scc_Admin_Settings::invoice_complete($business);
            if (!$complete) $paid['invoiceStatus'] = 'missing-business-details';
            if (!$orders->cas($orderId, $paid, $order['revision'], $rev)) throw $generic();
            if (!Scc_Admin::store('payment_transactions')->insert($source . ':' . $reference, ['order_id' => $orderId], $rev)) throw $generic();
            $download = self::delivery_download();
            if ($complete) {
                $doc = self::build_document($paid, $business, self::number_prefix($business) . str_pad((string) self::next_sequence(), 6, '0', STR_PAD_LEFT), $stamp);
                if (!Scc_Admin::store('invoice_documents')->insert($orderId, $doc, $rev)) throw $generic();
                if (!$download) Scc_Admin::store('mail_jobs')->upsert($orderId, ['id' => $orderId, 'documentId' => $orderId, 'recipient' => $order['customer']['email'], 'status' => 'pending', 'attempts' => 0, 'createdAt' => $stamp], $rev);
            }
            if (!$download) Scc_Admin::store('mail_jobs')->upsert('manager:' . $orderId, ['id' => 'manager:' . $orderId, 'kind' => 'manager-order', 'orderId' => $orderId,
                'documentData' => array_merge($paid, ['business' => $business, 'orderId' => $orderId, 'number' => $orderId, 'issuedAt' => $stamp]), 'recipient' => Scc_Admin::cfg_email(), 'status' => 'pending', 'attempts' => 0, 'createdAt' => $stamp], $rev);
            $loyalty = Scc_Admin::setting('loyalty'); $loyalty = is_array($loyalty) ? $loyalty : [];
            $profile = null;
            foreach (Scc_Admin::store('member_profiles')->rows() as $pr) if (($pr['email'] ?? null) === ($order['customer']['email'] ?? null) && ($pr['status'] ?? null) === 'active' && empty($pr['archived'])) { $profile = $pr; break; }
            if ($profile && !empty($loyalty['enabled'])) {
                $eligible = 0;
                foreach (($order['items'] ?? []) as $i) if (empty($loyalty['excludeSale']) || empty($changed[$i['productId']]['previousPrice'])) $eligible += $i['qty'] * $i['priceCents'];
                $sub = (float) ($order['subtotalCents'] ?? 0);
                $base = $sub ? Scc_Admin::round(($eligible * ($sub - (float) ($order['discountCents'] ?? 0))) / $sub) : 0;
                $earn = Scc_Admin::round($base * Scc_Admin::num($loyalty['earnPercent'] ?? 0) / 100);
                if ($earn) Scc_Admin::store('loyalty_ledger')->upsert($orderId, ['memberId' => $profile['id'], 'orderId' => $orderId, 'deltaCents' => Scc_Admin::nv($earn), 'type' => 'earned',
                    'availableAt' => self::plus_days(Scc_Admin::num($loyalty['pendingDays'] ?? 14)), 'expiresAt' => self::plus_days(Scc_Admin::num(Scc_Admin::truthy($loyalty['expiryDays'] ?? null) ? $loyalty['expiryDays'] : 365)), 'createdAt' => $stamp, 'system' => true], $rev);
            }
            if (class_exists('Scc_Channel_Stock')) { global $wpdb; $wpdb->query($wpdb->prepare('UPDATE ' . Scc_Channel_Stock::t('reservations') . " SET state='committed' WHERE reservation_id=%s AND state='held'", $orderId)); }
            return array_merge($paid, ['id' => $orderId, 'revision' => $rev]);
        });
    }

    /* ------------------------------------------------------------ GET */
    public static function get(string $kind, string $table, ?string $id, ?string $action): Scc_Response {
        if ($action === 'pdf' || $action === 'order-pdf' || $action === 'html') return self::render($kind, $id, $action);
        if ($kind === 'cards' && !$id) return Scc_Admin_Crm::cards_list();
        $st = Scc_Admin::store($table);
        if ($id) { $row = $st->get($id); return Scc_Admin::json($row); } // original returns null (200) for an unknown id
        return Scc_Admin::json($st->rows());
    }
    private static function render(string $kind, ?string $id, string $action): Scc_Response {
        if (!$id || ($action === 'order-pdf' && $kind !== 'orders') || ($action !== 'order-pdf' && $kind !== 'documents')) Scc_Admin::fail('פעולה לא נתמכת.', 405);
        if ($action === 'html') { $doc = Scc_Admin::store('invoice_documents')->get($id); if (!$doc) Scc_Admin::fail('מסמך לא נמצא.', 404); $ctx = ['doc' => $doc, 'id' => $id]; }
        elseif ($action === 'pdf') { $doc = Scc_Admin::store('invoice_documents')->get($id); if (!$doc) Scc_Admin::fail('מסמך לא נמצא.', 404); $ctx = ['doc' => $doc, 'id' => $id]; }
        else {
            $order = Scc_Admin::store('orders')->get($id); if (!$order) Scc_Admin::fail('הזמנה לא נמצאה.', 404);
            $inv = Scc_Admin::store('invoice_documents')->get($id);
            $b = $inv['business'] ?? Scc_Admin::setting('invoice');
            $ctx = ['id' => $id, 'order' => $order, 'invoice' => $inv, 'doc' => array_merge($order, ['business' => $b, 'orderId' => $id, 'number' => $id, 'issuedAt' => $order['paidAt'] ?? $order['createdAt'] ?? null])];
        }
        $res = apply_filters('scc_admin_document_render', null, $action, $ctx);
        if ($res instanceof Scc_Response) return $res;
        Scc_Admin::fail('מנוע המסמכים אינו מחובר עדיין (scc_admin_document_render).', 501);
    }

    /* ------------------------------------------------------------ POST/PUT dispatcher */
    public static function dispatch(Scc_Request $r): Scc_Response {
        $kind = $r->params[1]; $id = ($r->params[2] ?? '') !== '' ? $r->params[2] : null; $action = ($r->params[3] ?? '') !== '' ? $r->params[3] : null;
        $table = self::TABLES[$kind];
        if ($r->method === 'GET') return self::get($kind, $table, $id, $action);
        if (!in_array($r->method, ['POST', 'PUT'], true)) Scc_Admin::fail('פעולה לא נתמכת.', 405);
        $d = Scc_Admin::body($r, 'he');
        $old = $id ? Scc_Admin::store($table)->get($id) : null;
        if ($id && !$old) Scc_Admin::fail('רשומה לא נמצאה.', 404);
        $exp = self::expected($d, $old);

        if ($kind === 'orders' && $action === 'payment-details') {
            if (($old['paymentStatus'] ?? null) !== 'paid') Scc_Admin::fail('פרטי תשלום מתועדים לאחר אישור תשלום.', 409);
            return Scc_Admin::json(self::save('orders', $id, array_merge(self::plain($old), ['paymentDetails' => self::clean_payment($d['paymentDetails'] ?? []), 'paymentDetailsUpdatedAt' => self::stamp()]), $exp));
        }
        if ($kind === 'cards' && $action === 'birthday') return Scc_Admin_Crm::birthday($id, $old);
        if ($kind === 'orders' && $action === 'issue-document') return self::issue_document($id, $old);
        if ($kind === 'orders' && $action === 'redeem') return self::redeem($id, $old, $d);
        if ($kind === 'orders' && $action === 'release-credit') return self::release_credit($id, $old);
        if ($kind === 'mail' && $action === 'retry') {
            if (($old['status'] ?? null) === 'sent') Scc_Admin::fail('המייל כבר התקבל אצל שירות המייל.', 409);
            if (Scc_Admin::truthy($old['firstAttemptAt'] ?? null) && (Scc_Admin::ms() - self::date_ms($old['firstAttemptAt'])) > 23 * 3600000) Scc_Admin::fail('חלון מניעת כפילות פג. יש לאמת מול שירות המייל לפני שליחה נוספת.', 409);
            self::save($table, $id, array_merge(self::plain($old), ['status' => 'pending', 'attempts' => 0, 'nextAttemptAt' => null]), $old['revision']);
            return Scc_Admin::json(self::process_mail());
        }
        if ($action === 'archive' || $action === 'restore') return self::archive($kind, $table, $id, $old, $action, $exp);
        if ($kind === 'orders' && $action === 'paid') {
            if (($old['status'] ?? null) === 'cancelled') Scc_Admin::fail('הזמנה מבוטלת אינה ניתנת לתשלום.', 409);
            $result = self::confirm_payment($id, Scc_Admin::str($d['reference'] ?? null), 'manual-external', Scc_Admin::truthy($d['paymentDetails'] ?? null) ? $d['paymentDetails'] : []);
            self::process_mail(false);
            return Scc_Admin::json($result);
        }
        if ($kind === 'orders' && $action === 'payment-link') {
            if (($old['paymentStatus'] ?? null) === 'paid') Scc_Admin::fail('ההזמנה כבר שולמה.', 409);
            $res = apply_filters('scc_admin_payment_link', null, $old, home_url());
            if (is_array($res)) return Scc_Admin::json($res);
            Scc_Admin::fail('חיבור התשלום אינו זמין עדיין (scc_admin_payment_link).', 501);
        }
        if (in_array($kind, ['products', 'promotions', 'stock', 'loyalty', 'documents', 'mail'], true)) Scc_Admin::fail('יש להשתמש בפעולה הייעודית.', 405);
        if ($action !== null) Scc_Admin::fail('פעולה לא נתמכת.', 405);

        $value = ['id' => $id ?: Scc_Admin::uuid(), 'archived' => false, 'createdAt' => Scc_Admin::truthy($old['createdAt'] ?? null) ? $old['createdAt'] : self::stamp()];
        if ($kind === 'orders') $value = self::order_value($value, $d, $old);
        else $value = Scc_Admin_Crm::build($kind, $value, $d, $old);
        return Scc_Admin::json(self::save($table, $value['id'], $value, $exp), $id ? 200 : 201);
    }

    private static function order_value(array $value, array $d, ?array $old): array {
        $trackingUrl = Scc_Admin::str($d['trackingUrl'] ?? null, 500);
        if (SCC_ROLE === 'shem') {
            if ($trackingUrl !== '') {
                $bad = !str_starts_with($trackingUrl, 'https://');
                if (!$bad) { $u = wp_parse_url($trackingUrl); $bad = $u === false || empty($u['host']) || isset($u['user']) || isset($u['pass']); }
                if ($bad) Scc_Admin::fail('קישור המעקב חייב להיות כתובת HTTPS תקינה.');
            }
            $ed = $d['estimatedDelivery'] ?? null;
            if (Scc_Admin::truthy($ed) && (!is_string($ed) || !preg_match('/^\d{4}-\d{2}-\d{2}\z/', $ed) || !Scc_Admin_Catalog::js_date_ok($ed))) Scc_Admin::fail('תאריך אספקה אינו תקין.');
        }
        if (Scc_Admin::truthy($old['redemptionId'] ?? null) && ($old['paymentStatus'] ?? null) !== 'paid') Scc_Admin::fail('יש לבטל את מימוש ההטבה לפני עריכת הזמנה.', 409);
        if (($old['paymentStatus'] ?? null) === 'paid') {
            $value = array_merge(self::plain($old), ['status' => in_array($d['status'] ?? null, ['processing', 'shipped', 'completed', 'cancelled'], true) ? $d['status'] : $old['status'] ?? null,
                'tracking' => Scc_Admin::str($d['tracking'] ?? null), 'notes' => Scc_Admin::str($d['notes'] ?? null, 2000)]);
        } else {
            $value = array_merge($value, self::make_order($d), ['paymentStatus' => 'unpaid', 'paid' => false, 'status' => in_array($d['status'] ?? null, ['draft', 'new', 'cancelled'], true) ? $d['status'] : 'draft',
                'source' => 'manual', 'tracking' => Scc_Admin::str($d['tracking'] ?? null)]);
        }
        if (SCC_ROLE === 'shem') {
            $value['carrier'] = Scc_Admin::str($d['carrier'] ?? null, 100);
            $value['trackingUrl'] = $trackingUrl;
            $value['estimatedDelivery'] = Scc_Admin::str($d['estimatedDelivery'] ?? null, 10);
            $hist = $old['shipmentHistory'] ?? [];
            if (($old['status'] ?? null) !== ($value['status'] ?? null)) $hist[] = ['status' => $value['status'], 'at' => self::stamp()];
            $value['shipmentHistory'] = array_slice($hist, -30);
        }
        return $value;
    }

    private static function archive(string $kind, string $table, ?string $id, ?array $old, string $action, ?string $exp): Scc_Response {
        if (in_array($kind, ['stock', 'loyalty', 'documents', 'mail'], true)) Scc_Admin::fail('תיעוד זה נשמר ללא מחיקה.', 409);
        if ($kind === 'orders' && Scc_Admin::truthy($old['redemptionId'] ?? null) && ($old['paymentStatus'] ?? null) !== 'paid') Scc_Admin::fail('יש לבטל את מימוש ההטבה לפני ארכוב.', 409);
        if ($kind === 'orders' && ($old['paymentStatus'] ?? null) === 'paid' && $action === 'archive') Scc_Admin::fail('הזמנה ששולמה נשמרת לתיעוד. אפשר לעדכן מצב טיפול.', 409);
        $next = array_merge(self::plain($old), ['archived' => $action === 'archive', 'archivedAt' => $action === 'archive' ? self::stamp() : null]);
        if (in_array($kind, ['products', 'collections', 'promotions'], true) && $action === 'archive') { $next['previousActive'] = $next['active'] ?? null; $next['active'] = false; }
        if ($action === 'restore' && array_key_exists('previousActive', $next)) $next['active'] = $next['previousActive'];
        return Scc_Admin::json(self::save($table, $id, self::fix_sizes($next), $exp));
    }

    /* ------------------------------------------------------------ issue-document / redeem / release-credit */
    private static function issue_document(string $id, array $old): Scc_Response {
        if (($old['paymentStatus'] ?? null) !== 'paid') Scc_Admin::fail('מסמך מופק רק להזמנה ששולמה.', 409);
        $existing = Scc_Admin::store('invoice_documents')->get($id);
        if ($existing) return Scc_Admin::json(['document' => $existing, 'mail' => self::process_mail(false)]);
        $res = apply_filters('scc_admin_issue_document', null, $old);
        if (is_array($res)) return Scc_Admin::json($res, 201);
        $business = Scc_Admin::setting('invoice'); $business = is_array($business) ? $business : [];
        if (!Scc_Admin_Settings::invoice_complete($business)) Scc_Admin::fail('יש להשלים תחילה את פרטי העסק בתבנית חשבונית העסקה.', 409);
        $doc = Scc_Admin::tx(function () use ($id, $old, $business) {
            $rev = Scc_Admin::now(); $stamp = Scc_Admin::stamp();
            $doc = self::build_document($old, $business, self::number_prefix($business) . str_pad((string) self::next_sequence(), 6, '0', STR_PAD_LEFT), $stamp);
            if (!Scc_Admin::store('invoice_documents')->insert($id, $doc, $rev)) throw new Scc_Http_Exception(Scc_Admin::GENERIC_503, 503);
            $download = self::delivery_download();
            if (!$download) Scc_Admin::store('mail_jobs')->upsert($id, ['id' => $id, 'documentId' => $id, 'recipient' => $old['customer']['email'], 'status' => 'pending', 'attempts' => 0, 'createdAt' => $stamp], $rev);
            $o = Scc_Admin::store('orders')->get($id); $o = self::plain($o); $o['invoiceStatus'] = $download ? 'available' : 'queued';
            Scc_Admin::store('orders')->upsert($id, $o, $rev); // original: json_set + new revision, no revision check
            return $doc;
        });
        return Scc_Admin::json(['document' => $doc, 'mail' => self::process_mail(false)], 201);
    }

    private static function ledger_available(string $memberId, string $now): array {
        $out = [];
        foreach (Scc_Admin::store('loyalty_ledger')->rows() as $l) if (($l['memberId'] ?? null) === $memberId && ($l['availableAt'] ?? '') <= $now && (empty($l['expiresAt']) || $l['expiresAt'] > $now)) $out[] = $l;
        return $out;
    }
    private static function redeem(string $id, array $old, array $d): Scc_Response {
        if (($old['paymentStatus'] ?? null) === 'paid' || Scc_Admin::truthy($old['redemptionId'] ?? null) || !empty($old['archived'])) Scc_Admin::fail('ההזמנה אינה זמינה למימוש הטבה.', 409);
        $rules = Scc_Admin::setting('loyalty'); $rules = is_array($rules) ? $rules : [];
        $member = Scc_Admin::store('member_profiles')->get(Scc_Admin::str($d['memberId'] ?? null));
        $cents = Scc_Admin::amount($d['amount'] ?? null);
        if (empty($rules['enabled']) || !$member || !empty($member['archived']) || ($member['status'] ?? null) !== 'active' || ($member['email'] ?? null) !== ($old['customer']['email'] ?? null) || !Scc_Admin::is_int_val($cents) || $cents <= 0) Scc_Admin::fail('חבר, הזמנה או סכום הטבה אינם תקינים.');
        if (Scc_Admin::truthy($old['discountCents'] ?? null) && empty($rules['allowStacking'])) Scc_Admin::fail('לא הוגדר כפל הטבות.');
        $now = self::stamp();
        $avail = self::ledger_available($member['id'], $now);
        $available = 0; foreach ($avail as $l) $available += $l['deltaCents'];
        if ($cents > $available) Scc_Admin::fail('אין יתרת הטבה זמינה בסכום המבוקש.');
        $ps = Scc_Admin::store('products')->rows(); $eligible = 0;
        foreach (($old['items'] ?? []) as $i) {
            $prev = null; foreach ($ps as $p) if ($p['id'] === $i['productId']) { $prev = $p['previousPrice'] ?? null; break; }
            if (empty($rules['excludeSale']) || !Scc_Admin::truthy($prev)) $eligible += $i['qty'] * $i['priceCents'];
        }
        $cap = floor(($eligible * Scc_Admin::num($rules['maxRedeemPercent'] ?? 0)) / 100);
        if ($cents > $cap || $cents < Scc_Admin::amount($rules['minRedemption'] ?? 0) || $cents > $old['subtotalCents'] - $old['discountCents']) Scc_Admin::fail('סכום המימוש אינו עומד במינימום או בתקרה.');
        $rev = Scc_Admin::now();
        $entry = ['id' => Scc_Admin::uuid(), 'memberId' => $member['id'], 'orderId' => $id, 'deltaCents' => Scc_Admin::nv(-$cents), 'type' => 'redeemed', 'availableAt' => $rev, 'createdAt' => $rev, 'system' => true];
        $buckets = [];
        foreach ($avail as $l) { $k = $l['expiresAt'] ?? ''; $buckets[$k] = ($buckets[$k] ?? 0) + $l['deltaCents']; }
        uksort($buckets, fn($a, $b) => strcmp($a === '' ? '9999' : $a, $b === '' ? '9999' : $b));
        $remainder = $cents; $allocs = [];
        foreach ($buckets as $exp => $balance) {
            if ($balance <= 0 || !$remainder) continue;
            $used = min($balance, $remainder); $remainder -= $used;
            $a = array_merge($entry, ['id' => $allocs ? Scc_Admin::uuid() : $entry['id'], 'deltaCents' => Scc_Admin::nv(-$used)]);
            if ($exp !== '') $a['expiresAt'] = $exp;
            $allocs[] = $a;
        }
        if ($remainder) Scc_Admin::fail('יש לבדוק את התאמות היתרה לפני מימוש.', 409);
        $allocs[0]['allocations'] = array_map(fn($a) => ['id' => $a['id'], 'deltaCents' => $a['deltaCents'], 'expiresAt' => $a['expiresAt'] ?? null], $allocs);
        $next = array_merge(self::plain($old), ['redemptionId' => $entry['id'], 'loyaltyDiscountCents' => Scc_Admin::nv($cents),
            'vatItemsCents' => Scc_Admin::nv(Scc_Admin::round((($old['subtotalCents'] - $old['discountCents'] - $cents) * 18) / 118)),
            'discountCents' => Scc_Admin::nv($old['discountCents'] + $cents), 'totalCents' => Scc_Admin::nv($old['totalCents'] - $cents), 'total' => Scc_Admin::nv(($old['totalCents'] - $cents) / 100)]);
        Scc_Admin::tx(function () use ($id, $old, $next, $allocs, $rev, $member) {
            if (!Scc_Admin::store('orders')->cas($id, $next, $old['revision'], $rev)) throw new Scc_Http_Exception(Scc_Admin::GENERIC_503, 503);
            foreach ($allocs as $a) Scc_Admin::store('loyalty_ledger')->upsert($a['id'], self::plain($a), $rev);
            $sum = 0; foreach (self::ledger_available($member['id'], $rev) as $l) $sum += $l['deltaCents'];
            if ($sum < 0) throw new Scc_Http_Exception(Scc_Admin::GENERIC_503, 503);
        });
        return Scc_Admin::json(array_merge($next, ['revision' => $rev]));
    }
    private static function release_credit(string $id, array $old): Scc_Response {
        if (($old['paymentStatus'] ?? null) === 'paid' || !Scc_Admin::truthy($old['redemptionId'] ?? null)) Scc_Admin::fail('אין מימוש פתוח לביטול.', 409);
        $entry = Scc_Admin::store('loyalty_ledger')->get($old['redemptionId']);
        if (!$entry) Scc_Admin::fail(Scc_Admin::GENERIC_503, 503);
        $rev = Scc_Admin::now(); $lc = $old['loyaltyDiscountCents'] ?? 0;
        $next = array_merge(self::plain($old), ['redemptionId' => null,
            'vatItemsCents' => Scc_Admin::nv(Scc_Admin::round((($old['subtotalCents'] - $old['discountCents'] + $lc) * 18) / 118)),
            'discountCents' => Scc_Admin::nv($old['discountCents'] - $lc), 'totalCents' => Scc_Admin::nv($old['totalCents'] + $lc), 'total' => Scc_Admin::nv(($old['totalCents'] + $lc) / 100), 'loyaltyDiscountCents' => 0]);
        Scc_Admin::tx(function () use ($id, $old, $next, $entry, $rev) {
            if (!Scc_Admin::store('orders')->cas($id, $next, $old['revision'], $rev)) throw new Scc_Http_Exception(Scc_Admin::GENERIC_503, 503);
            foreach (($entry['allocations'] ?? [$entry]) as $a) {
                $row = ['memberId' => $entry['memberId'], 'orderId' => $id, 'deltaCents' => Scc_Admin::nv(-$a['deltaCents']), 'type' => 'released', 'availableAt' => $rev, 'createdAt' => $rev, 'system' => true];
                if (!empty($a['expiresAt'])) $row['expiresAt'] = $a['expiresAt'];
                Scc_Admin::store('loyalty_ledger')->upsert('release:' . $a['id'], $row, $rev);
            }
        });
        return Scc_Admin::json(array_merge($next, ['revision' => $rev]));
    }

    /* ------------------------------------------------------------ inventory */
    public static function inventory(Scc_Request $r): Scc_Response {
        $d = Scc_Admin::body($r, 'he'); $st = Scc_Admin::store('products');
        $p = $st->get(Scc_Admin::str($d['productId'] ?? null));
        if (!$p || !empty($p['archived'])) Scc_Admin::fail('מוצר לא נמצא.', 404);
        if (Scc_Admin::is_home() && Scc_Admin_Catalog::linked($p['id'])) Scc_Admin::fail('המלאי של מוצר מקושר מנוהל ב-SHEM.', 409);
        $delta = Scc_Admin::num($d['delta'] ?? null);
        if (!Scc_Admin::is_int_val($delta) || $delta == 0 || abs($delta) > 100000 || Scc_Admin::str($d['reason'] ?? null) === '') Scc_Admin::fail('נדרשת כמות שינוי וסיבה.');
        $delta = (int) $delta;
        $size = is_string($d['size'] ?? null) ? $d['size'] : null;
        $vi = null; foreach (($p['variants'] ?? []) as $k => $v) if (($v['id'] ?? null) === ($d['variantId'] ?? null)) { $vi = $k; break; }
        if (!empty($p['variants']) && $vi === null) Scc_Admin::fail('בחרו גרסת מוצר.');
        $stock = $vi !== null ? $p['variants'][$vi]['stock'] : (($size !== null ? ($p['sizeStock'][$size] ?? null) : null) ?? $p['stock']);
        if ($stock + $delta < 0 || $p['stock'] + $delta < 0) Scc_Admin::fail('המלאי לא יכול להיות שלילי.');
        if ($vi !== null) { $p['variants'][$vi]['stock'] += $delta; self::recount($p); }
        else {
            if (isset($p['sizeStock'])) { if ($size === null || !in_array($size, $p['sizes'], true)) Scc_Admin::fail('מידה אינה קיימת.'); $p['sizeStock'][$size] += $delta; }
            $p['stock'] += $delta;
        }
        $oldrev = $p['revision']; $expected = Scc_Admin::truthy($d['revision'] ?? null) ? self::jsstr($d['revision']) : $oldrev; $rev = Scc_Admin::now();
        $movement = ['id' => Scc_Admin::uuid(), 'productId' => $p['id'], 'size' => Scc_Admin::str($d['size'] ?? null), 'variantId' => Scc_Admin::str($d['variantId'] ?? null), 'delta' => Scc_Admin::nv($delta),
            'reason' => Scc_Admin::str($d['reason'] ?? null, 1000), 'createdAt' => $rev, 'system' => false];
        Scc_Admin::tx(function () use ($st, $p, $expected, $rev, $movement) {
            if (!$st->cas($p['id'], self::fix_sizes(self::plain($p)), $expected, $rev)) Scc_Admin::fail('המלאי השתנה. רעננו לפני עדכון.', 409);
            Scc_Admin::store('stock_movements')->insert($movement['id'], $movement, $rev);
            Scc_Admin_Catalog::guard_stock($p, '');
        });
        return Scc_Admin::json(['product' => $st->get($p['id']), 'movement' => $movement]);
    }
}

Scc_Admin::route('POST', '#^/api/admin/manage/inventory$#', fn(Scc_Request $r) => Scc_Admin_Manage::inventory($r));
Scc_Admin::route('*', '#^/api/admin/manage/(collections|customers|cards|requests|activities|orders|products|promotions|stock|loyalty|documents|mail)(?:/([^/]+))?(?:/(archive|restore|paid|payment-link|html|retry|redeem|release-credit|issue-document|birthday|pdf|order-pdf|payment-details))?$#',
    fn(Scc_Request $r) => Scc_Admin_Manage::dispatch($r));
