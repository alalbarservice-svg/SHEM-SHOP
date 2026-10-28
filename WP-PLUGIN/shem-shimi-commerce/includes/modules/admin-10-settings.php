<?php
if (!defined('ABSPATH')) exit;
/**
 * Admin: settings, business details, control/launch status, integrations readiness, audit view.
 * Port of: api.js /api/admin/settings, operations.js /api/admin/{integration-status,audit,health},
 * management.js /api/admin/manage/{status,settings/*,template-preview,mail/process}.
 */
final class Scc_Admin_Settings {
    public static function invoice_complete(array $b): bool {
        return Scc_Admin::truthy($b['legalName'] ?? '') && Scc_Admin::truthy($b['registrationNumber'] ?? '') && Scc_Admin::truthy($b['address'] ?? '')
            && Scc_Admin::truthy($b['phone'] ?? '') && Scc_Admin::email((string) ($b['email'] ?? '')) && in_array($b['vatMode'] ?? null, ['included', 'exempt'], true);
    }
    public static function media_available(): bool { $u = wp_upload_dir(); return empty($u['error']) && is_writable($u['basedir']); }
    public static function mail_configured(): bool { return (bool) apply_filters('scc_admin_mail_configured', defined('SCC_RESEND_API_KEY') && defined('SCC_MAIL_FROM') && SCC_RESEND_API_KEY && SCC_MAIL_FROM); }
    public static function home_configured(): bool { return defined('SCC_HOME_CHECKOUT_URL') && defined('SCC_HOME_PAYMENT_SECRET') && SCC_HOME_CHECKOUT_URL && SCC_HOME_PAYMENT_SECRET; }

    public const DEFAULT_ITEM_TYPES = ['shirts', 'trousers', 'knitwear', 'jackets', 'shoes', 'accessories', 'clothing'];
    /** Configured item-category keys (store setting itemTypes), or the built-in 7 when none is configured. */
    public static function item_type_keys(): array {
        $st = Scc_Admin::setting('store');
        $t = is_array($st) && is_array($st['itemTypes'] ?? null) ? array_column($st['itemTypes'], 'key') : [];
        return $t ? array_values($t) : self::DEFAULT_ITEM_TYPES;
    }
    /** Validate [{key,he,en}]: key slug 1-30 [a-z0-9-], he/en 1-40 chars, unique, max 30. Fails with a Hebrew message. */
    public static function sanitize_item_types($in): array {
        if (!is_array($in) || !array_is_list($in)) Scc_Admin::fail('רשימת הקטגוריות אינה תקינה.');
        $out = []; $seen = [];
        foreach ($in as $t) {
            if (!is_array($t)) Scc_Admin::fail('רשימת הקטגוריות אינה תקינה.');
            $key = is_string($t['key'] ?? null) ? $t['key'] : '';
            $he = Scc_Admin::str($t['he'] ?? null, 40);
            $en = Scc_Admin::str($t['en'] ?? null, 40);
            if (!preg_match('/^[a-z0-9-]{1,30}\z/', $key)) Scc_Admin::fail('מזהה קטגוריה חייב להיות באותיות לועזיות קטנות, ספרות ומקף, עד 30 תווים.');
            if ($he === '') Scc_Admin::fail('לכל קטגוריה נדרש שם בעברית.');
            if (isset($seen[$key])) Scc_Admin::fail('מזהה קטגוריה כפול.');
            $seen[$key] = true;
            $out[] = ['key' => $key, 'he' => $he, 'en' => $en !== '' ? $en : $he];
        }
        if (!$out || count($out) > 30) Scc_Admin::fail('מספר הקטגוריות חייב להיות בין 1 ל-30.');
        return $out;
    }

    /* ---- PUT /api/admin/settings (club|store|business|integrations) ---- */
    public static function put_setting(Scc_Request $r): Scc_Response {
        $d = Scc_Admin::body($r, 'en');
        $key = $d['key'] ?? null;
        if (!in_array($key, ['club', 'store', 'business', 'integrations'], true)) Scc_Admin::fail('Invalid setting');
        $v = is_array($d['value'] ?? null) ? $d['value'] : [];
        $s = static fn($k, $n) => Scc_Admin::str($v[$k] ?? null, $n);
        if ($key === 'integrations') {
            $out = []; foreach (['paymentProvider', 'merchantId', 'invoiceProvider', 'invoiceAccount'] as $k) $out[$k] = $s($k, 150);
        } elseif ($key === 'business') {
            $out = []; foreach (['legalName', 'registrationNumber', 'address', 'phone', 'accessibilityContact', 'serviceAreas', 'returnCollectionFee'] as $k) $out[$k] = $s($k, 500);
        } elseif ($key === 'club') {
            if (($v['enabled'] ?? null) && !preg_match('/^(https:\/\/[^\s]+|#\/privacy)\z/', (string) (is_string($v['privacyUrl'] ?? null) ? $v['privacyUrl'] : '')))
                Scc_Admin::fail('כדי להפעיל הרשמה יש להזין קישור למדיניות פרטיות.');
            $out = ['title' => $s('title', 150), 'description' => $s('description', 1000), 'benefits' => $s('benefits', 2000), 'enabled' => ($v['enabled'] ?? null) === true, 'privacyUrl' => $s('privacyUrl', 500)];
        } else { // store
            $cur = Scc_Admin::setting('store'); if (!is_array($cur)) $cur = [];
            if (array_key_exists('itemTypes', $v)) {
                $types = self::sanitize_item_types($v['itemTypes']);
                $removed = array_diff(self::item_type_keys(), array_column($types, 'key'));
                if ($removed) {
                    foreach (Scc_Admin::store('products')->rows() as $p) {
                        if (!empty($p['archived'])) continue;
                        if (in_array($p['productType'] ?? '', $removed, true)) Scc_Admin::fail('לא ניתן להסיר קטגוריה שיש בה פריטים. העבירו את הפריטים לקטגוריה אחרת ונסו שוב.', 409);
                    }
                }
            } else {
                $types = is_array($cur['itemTypes'] ?? null) ? $cur['itemTypes'] : null;
            }
            $brands = [];
            if (is_array($v['brands'] ?? null) && array_is_list($v['brands'])) foreach ($v['brands'] as $b) { $b = Scc_Admin::str($b, 80); if ($b !== '') $brands[] = $b; }
            $out = ['heroTitle' => $s('heroTitle', 100), 'heroSubtitle' => $s('heroSubtitle', 200), 'contactEmail' => Scc_Admin::cfg_email(), 'warehouseSystem' => $s('warehouseSystem', 200), 'brands' => array_slice($brands, 0, 100)];
            if ($types !== null) $out['itemTypes'] = $types;
        }
        Scc_Admin::save_setting($key, $out);
        return Scc_Admin::json(['ok' => true]);
    }

    /* ---- GET /api/admin/settings ---- */
    public static function get_settings(): Scc_Response {
        $o = [];
        foreach (['club', 'store', 'catalogueInitialized', 'business', 'integrations'] as $k) { $v = Scc_Docs::setting($k, null); if ($v !== null) $o[$k] = $v; }
        return Scc_Admin::json(Scc_Admin::obj($o));
    }

    /* ---- GET /api/admin/health ---- */
    public static function health(): Scc_Response {
        $b = Scc_Admin::setting('business'); if (!is_array($b)) $b = [];
        return Scc_Admin::json([
            'database' => true, 'media' => self::media_available(), 'payments' => false, 'warehouse' => false,
            'businessComplete' => Scc_Admin::truthy($b['legalName'] ?? '') && Scc_Admin::truthy($b['registrationNumber'] ?? '') && Scc_Admin::truthy($b['address'] ?? '') && Scc_Admin::truthy($b['phone'] ?? ''),
            'supportEmail' => Scc_Admin::cfg_email(), 'checkedAt' => gmdate('Y-m-d\TH:i:s') . '.' . sprintf('%03d', Scc_Admin::ms() % 1000) . 'Z',
        ]);
    }

    /* ---- GET /api/admin/manage/status ---- */
    public static function status(): Scc_Response {
        $b = Scc_Admin::setting('invoice'); $home = Scc_Admin::setting('home'); $op = Scc_Admin::setting('operations');
        $products = Scc_Admin::store('products')->rows(); $orders = Scc_Admin::store('orders')->rows();
        $thr = Scc_Admin::num(is_array($op) ? ($op['lowStockThreshold'] ?? 3) : 3);
        $low = []; $out = 0; $arch = 0; $unmapped = 0;
        foreach ($products as $p) {
            if (!empty($p['archived'])) { $arch++; continue; }
            $hv = false; foreach (($p['variants'] ?? []) as $v) if (empty($v['homeSku'])) { $hv = true; break; }
            if (empty($p['homeSku']) || $hv) $unmapped++;
            $st = $p['stock'] ?? 0;
            if ($st > 0 && $st <= $thr) $low[] = ['id' => $p['id'], 'name' => $p['he'] ?? null, 'stock' => $st];
            if ($st === 0) $out++;
        }
        $reqs = 0; foreach (Scc_Admin::store('requests')->rows() as $q) if (empty($q['archived']) && ($q['status'] ?? '') !== 'resolved' && ($q['status'] ?? '') !== 'rejected') $reqs++;
        $unpaid = 0; foreach ($orders as $o) if (($o['paymentStatus'] ?? '') !== 'paid' && empty($o['archived'])) $unpaid++;
        $failed = 0; foreach (Scc_Admin::store('mail_jobs')->rows() as $j) if (($j['status'] ?? '') === 'failed') $failed++;
        return Scc_Admin::json([
            'database' => true, 'media' => self::media_available(), 'invoiceComplete' => (SCC_ROLE === 'shem' && class_exists('ShemDok')) ? (ShemDok::engine_ready() && trim((string) (array_merge((array) ShemDok::defaults(), (array) (ShemDok::settings()['defaults'] ?? []))['businessId'] ?? '')) !== '') : self::invoice_complete(is_array($b) ? $b : []),
            'mailConfigured' => self::mail_configured(), 'homeConfigured' => self::home_configured(), 'homeEnabled' => (is_array($home) ? ($home['enabled'] ?? null) : null) === true,
            'unmapped' => $unmapped, 'lowStock' => $low, 'outOfStock' => $out, 'archivedProducts' => $arch, 'openReturns' => $reqs, 'unpaid' => $unpaid, 'failedMail' => $failed,
            'checkedAt' => gmdate('Y-m-d\TH:i:s') . '.' . sprintf('%03d', Scc_Admin::ms() % 1000) . 'Z',
        ]);
    }

    /* ---- PUT /api/admin/manage/settings/<key> ---- */
    public static function put_manage_setting(string $key, Scc_Request $r): Scc_Response {
        $d = Scc_Admin::body($r, 'he'); $v = [];
        $s = static fn($k, $n = 500) => Scc_Admin::str($d[$k] ?? null, $n);
        $or = static fn($k) => Scc_Admin::truthy($d[$k] ?? null) ? $d[$k] : 0;
        if ($key === 'invoice' && Scc_Admin_Manage::legacy_invoice_off()) Scc_Admin::fail('פרטי החשבונית מנוהלים ב-SHEM DOK בלבד.', 410);
        if ($key === 'invoice') {
            $cur = Scc_Admin::setting('invoice');
            if (SCC_ROLE === 'shem' && is_array($cur) && ($cur['registrationNumberScope'] ?? null) === 'invoice') $v['registrationNumberScope'] = 'invoice';
            foreach (['legalName', 'registrationNumber', 'address', 'phone', 'email', 'displayName', 'documentTitle', 'documentFooter', 'numberPrefix', 'vatMode'] as $k) $v[$k] = $s($k, $k === 'documentFooter' ? 1500 : 500);
            $rate = Scc_Admin::num($or('vatRate')); $v['vatRate'] = Scc_Admin::nv($rate);
            if (!in_array($v['vatMode'], ['included', 'exempt'], true) || !is_finite($rate) || $rate < 0 || $rate > 100) Scc_Admin::fail('מצב מע״מ אינו תקין.');
            if ($v['email'] !== '' && !Scc_Admin::email($v['email'])) Scc_Admin::fail('אימייל עסק אינו תקין.');
        }
        if ($key === 'loyalty') {
            $n = [];
            foreach (['earnPercent', 'maxRedeemPercent', 'vipSpend', 'vipOrders', 'pendingDays', 'expiryDays', 'birthdayBenefit', 'minRedemption'] as $k) {
                $n[$k] = Scc_Admin::num($or($k));
                if (!is_finite($n[$k]) || $n[$k] < 0 || $n[$k] > 100000) Scc_Admin::fail('הגדרת הטבה אינה תקינה.');
                $v[$k] = Scc_Admin::nv($n[$k]);
            }
            if ($n['expiryDays'] < 1 || !Scc_Admin::is_int_val($n['pendingDays']) || !Scc_Admin::is_int_val($n['expiryDays']) || !Scc_Admin::is_int_val($n['vipOrders'])) Scc_Admin::fail('ימי המתנה, תוקף ומספר הזמנות אינם תקינים.');
            if ($n['earnPercent'] > 100 || $n['maxRedeemPercent'] > 100) Scc_Admin::fail('אחוז הטבה חייב להיות בין 0 ל-100.');
            foreach (['enabled', 'excludeSale', 'allowStacking'] as $k) $v[$k] = ($d[$k] ?? null) === true;
            $v['grossMarginPercent'] = Scc_Admin::nv(Scc_Admin::num(array_key_exists('grossMarginPercent', $d) && $d['grossMarginPercent'] !== null ? $d['grossMarginPercent'] : 20));
            $v['benefits'] = $s('benefits', 2000);
        }
        if ($key === 'home') {
            foreach (['siteUrl', 'displayName', 'protocolVersion'] as $k) $v[$k] = $s($k);
            if ($v['siteUrl'] !== '' && !preg_match('#^https://#', $v['siteUrl'])) Scc_Admin::fail('כתובת אתר הבית חייבת להיות HTTPS.');
            $v['protocolVersion'] = $v['protocolVersion'] ?: 'shem-home-v1';
            if ($v['protocolVersion'] !== 'shem-home-v1') Scc_Admin::fail('גרסת החיבור הנתמכת היא shem-home-v1.');
            if ($v['siteUrl'] !== '') {
                $u = wp_parse_url($v['siteUrl']);
                if (!$u || empty($u['host']) || isset($u['user']) || isset($u['pass'])) Scc_Admin::fail('כתובת אתר הבית אינה תקינה.');
            }
            $v['enabled'] = ($d['enabled'] ?? null) === true; $v['priceAuthority'] = 'manager'; $v['embedded'] = true;
        }
        if ($key === 'operations') {
            $def = ['standardPrice' => 49, 'expressPrice' => 199, 'standardDays' => 7, 'expressDays' => 1, 'lowStockThreshold' => 3];
            foreach ($def as $k => $dv) {
                $x = Scc_Admin::num(array_key_exists($k, $d) && $d[$k] !== null ? $d[$k] : $dv);
                if (!is_finite($x) || $x < 0 || $x > 100000) Scc_Admin::fail('הגדרה תפעולית אינה תקינה.');
                $v[$k] = Scc_Admin::nv($x);
            }
            $v['checkoutEnabled'] = ($d['checkoutEnabled'] ?? null) === true;
            $v['instagram'] = Scc_Admin::str(Scc_Admin::truthy($d['instagram'] ?? null) ? $d['instagram'] : 'new.shem', 100);
            $v['whatsapp'] = Scc_Admin::str(Scc_Admin::truthy($d['whatsapp'] ?? null) ? $d['whatsapp'] : '972529586766', 40);
            if (!preg_match('/^[a-zA-Z0-9_.]{1,100}\z/', $v['instagram']) || !preg_match('/^[0-9]{8,15}\z/', $v['whatsapp'])) Scc_Admin::fail('פרטי אינסטגרם או וואטסאפ אינם תקינים.');
            $v['serviceAreas'] = $s('serviceAreas', 1000);
        }
        if ($key === 'business') {
            foreach (['legalName', 'registrationNumber', 'address', 'phone', 'accessibilityContact', 'serviceAreas', 'returnCollectionFee', 'email', 'displayName', 'openingHours', 'website', 'taxStatus'] as $k) $v[$k] = $s($k, 1000);
        }
        if ($key === 'content') {
            $v['heroTitle'] = $s('heroTitle', 100); $v['heroSubtitle'] = $s('heroSubtitle', 300);
            $v['arrivalsTitle'] = Scc_Admin::str(Scc_Admin::truthy($d['arrivalsTitle'] ?? null) ? $d['arrivalsTitle'] : 'חדשים באתר שלנו', 100);
            $v['saleTitle'] = Scc_Admin::str(Scc_Admin::truthy($d['saleTitle'] ?? null) ? $d['saleTitle'] : 'פריטים בסוף עונה', 100);
            $v['announcement'] = $s('announcement', 300);
            $imgs = [];
            foreach (Scc_Admin::arr($d['heroImages'] ?? null) as $x) if (is_string($x) && preg_match('#^/(assets|media)/[\w./-]+\z#', $x) && !str_contains($x, '..')) $imgs[] = $x;
            $v['heroImages'] = array_slice($imgs, 0, 3);
            $blocks = [];
            foreach (array_slice(Scc_Admin::arr($d['blocks'] ?? null), 0, 30) as $i => $b) {
                $b = is_array($b) ? $b : [];
                $pos = Scc_Admin::num($b['position'] ?? null); if (!array_key_exists('position', $b)) $pos = NAN; // Number(undefined)
                $link = is_string($b['link'] ?? null) ? $b['link'] : '';
                $blocks[] = [
                    'id' => Scc_Admin::str($b['id'] ?? null) ?: Scc_Admin::uuid(), 'title' => Scc_Admin::str($b['title'] ?? null, 150), 'text' => Scc_Admin::str($b['text'] ?? null, 2000),
                    'link' => str_starts_with($link, '#/') ? Scc_Admin::str($link, 200) : '', 'label' => Scc_Admin::str($b['label'] ?? null, 100),
                    'active' => ($b['active'] ?? null) === true, 'position' => is_finite($pos) ? Scc_Admin::nv($pos) : $i,
                ];
            }
            $v['blocks'] = $blocks;
        }
        Scc_Admin::save_setting($key, $v);
        return Scc_Admin::json($v);
    }
}

Scc_Admin::route('GET', '#^/api/admin/settings$#', fn() => Scc_Admin_Settings::get_settings());
Scc_Admin::route('PUT', '#^/api/admin/settings$#', fn(Scc_Request $r) => Scc_Admin_Settings::put_setting($r));
Scc_Admin::route('GET', '#^/api/admin/integration-status$#', fn() => Scc_Admin::json(['payment' => false, 'invoicing' => false, 'automatic' => false, 'reason' => 'נדרשים בחירת ספקים, מתאמים רשמיים ופרטי גישה.']));
Scc_Admin::route('GET', '#^/api/admin/health$#', fn() => Scc_Admin_Settings::health());
Scc_Admin::route('GET', '#^/api/admin/audit$#', function () {
    global $wpdb; $out = [];
    foreach ($wpdb->get_results('SELECT data FROM ' . Scc_Docs::t('audit') . ' ORDER BY updated_at DESC, id DESC LIMIT 200', ARRAY_A) as $r) {
        $d = json_decode($r['data'], true) ?: [];
        $out[] = ['id' => $d['id'] ?? null, 'actor' => $d['actor'] ?? null, 'action' => $d['action'] ?? null, 'created_at' => $d['created_at'] ?? null];
    }
    return Scc_Admin::json($out);
});
Scc_Admin::route('GET', '#^/api/admin/manage/status$#', fn() => Scc_Admin_Settings::status());
Scc_Admin::route('GET', '#^/api/admin/manage/settings/(invoice|loyalty|home|operations|content|business)$#', function (Scc_Request $r) {
    return Scc_Admin::json(is_array($v = Scc_Admin::setting($r->params[1])) ? Scc_Admin::obj($v) : new stdClass());
});
Scc_Admin::route('PUT', '#^/api/admin/manage/settings/(invoice|loyalty|home|operations|content|business)$#', fn(Scc_Request $r) => Scc_Admin_Settings::put_manage_setting($r->params[1], $r));
// Document rendering is owned by the documents module (filter scc_admin_document_render); until it registers, these answer 501.
Scc_Admin::route('GET', '#^/api/admin/manage/template-preview(\.pdf)?$#', function (Scc_Request $r) {
    if (Scc_Admin_Manage::legacy_invoice_off()) Scc_Admin::fail('תצוגת התבנית הוסרה. חשבוניות מנוהלות ב-SHEM DOK.', 410);
    $res = apply_filters('scc_admin_document_render', null, 'template-preview', ['pdf' => !empty($r->params[1])]);
    if ($res instanceof Scc_Response) return $res;
    Scc_Admin::fail('מנוע המסמכים אינו מחובר עדיין (scc_admin_document_render).', 501);
});
Scc_Admin::route('POST', '#^/api/admin/manage/mail/process$#', fn() => Scc_Admin::json(Scc_Admin_Manage::process_mail()));
