<?php
if (!defined('ABSPATH')) exit;
/**
 * Admin: CRM kinds of /api/admin/manage/* (collections, customers, member cards, service requests, manual activities),
 * member-card list with effective tier, birthday benefit, manual loyalty adjustment.
 * Port of the corresponding branches of SHEM|SHIMI worker/management.js. Dispatch lives in admin-30-manage.php.
 */
final class Scc_Admin_Crm {
    /** GET manage/cards: member profiles with effectiveTier / annualSpend / paidOrders (last 365 days of paid orders). */
    public static function cards_list(): Scc_Response {
        $cards = Scc_Admin::store('member_profiles')->rows(); $orders = Scc_Admin::store('orders')->rows(); $rules = Scc_Admin::setting('loyalty'); $rules = is_array($rules) ? $rules : [];
        $since = Scc_Admin::iso_ms(Scc_Admin::ms() - 365 * 86400000);
        $out = [];
        foreach ($cards as $c) {
            $paid = 0; $spend = 0.0;
            foreach ($orders as $o) {
                if (($o['paymentStatus'] ?? null) === 'paid' && ($o['customer']['email'] ?? null) === ($c['email'] ?? null) && ($o['paidAt'] ?? '') >= $since) {
                    $paid++; $spend += ($o['subtotalCents'] ?? 0) - ($o['discountCents'] ?? 0);
                }
            }
            $spend /= 100;
            $vipSpend = Scc_Admin::num($rules['vipSpend'] ?? null); $vipOrders = Scc_Admin::num($rules['vipOrders'] ?? null);
            $vip = ($c['tier'] ?? null) === 'vip' || ($vipSpend > 0 && $spend >= $vipSpend) || ($vipOrders > 0 && $paid >= $vipOrders);
            $out[] = array_merge($c, ['effectiveTier' => $vip ? 'vip' : 'standard', 'annualSpend' => Scc_Admin::nv($spend), 'paidOrders' => $paid]);
        }
        return Scc_Admin::json($out);
    }

    /** POST manage/cards/:id/birthday */
    public static function birthday(string $id, array $old): Scc_Response {
        $rules = Scc_Admin::setting('loyalty'); $rules = is_array($rules) ? $rules : [];
        $date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jerusalem')))->format('Y-m-d');
        $cents = Scc_Admin::amount(Scc_Admin::truthy($rules['birthdayBenefit'] ?? null) ? $rules['birthdayBenefit'] : 0);
        $bd = is_string($old['birthday'] ?? null) ? $old['birthday'] : '';
        if (!empty($old['archived']) || ($old['status'] ?? null) !== 'active' || !preg_match('/^\d{4}-\d{2}-\d{2}\z/', $bd) || substr($bd, 5) !== substr($date, 5) || !($cents > 0))
            Scc_Admin::fail('הטבת יום הולדת דורשת חבר פעיל, יום הולדת היום וסכום מוגדר.');
        $entryId = 'birthday:' . $id . ':' . substr($date, 0, 4);
        $ledger = Scc_Admin::store('loyalty_ledger');
        if ($ledger->exists($entryId)) Scc_Admin::fail('הטבת יום הולדת כבר ניתנה השנה.', 409);
        $rev = Scc_Admin::now(); $stamp = Scc_Admin::stamp();
        $days = Scc_Admin::num(Scc_Admin::truthy($rules['expiryDays'] ?? null) ? $rules['expiryDays'] : 365);
        $entry = ['id' => $entryId, 'memberId' => $id, 'deltaCents' => Scc_Admin::nv($cents), 'type' => 'birthday', 'reason' => 'הטבת יום הולדת', 'availableAt' => $stamp, 'createdAt' => $stamp,
            'expiresAt' => Scc_Admin::iso_ms(Scc_Admin::ms() + (int) round($days * 86400000)), 'system' => true];
        if (!$ledger->insert($entryId, $entry, $rev)) Scc_Admin::fail(Scc_Admin::GENERIC_503, 503);
        return Scc_Admin::json($entry, 201);
    }

    /** POST manage/loyalty-adjust */
    public static function adjust(Scc_Request $r): Scc_Response {
        $d = Scc_Admin::body($r, 'he');
        $member = Scc_Admin::store('member_profiles')->get(Scc_Admin::str($d['memberId'] ?? null));
        $delta = Scc_Admin::amount($d['amount'] ?? null);
        if (!$member || !empty($member['archived']) || !Scc_Admin::is_int_val($delta) || $delta == 0 || abs($delta) > 1000000 || Scc_Admin::str($d['reason'] ?? null) === '') Scc_Admin::fail('נדרש חבר, סכום וסיבה תקינים.');
        $now = Scc_Admin::stamp(); $balance = 0;
        foreach (Scc_Admin::store('loyalty_ledger')->rows() as $l) if (($l['memberId'] ?? null) === $member['id'] && ($l['availableAt'] ?? '') <= $now && (empty($l['expiresAt']) || $l['expiresAt'] > $now)) $balance += $l['deltaCents'];
        if ($balance + $delta < 0) Scc_Admin::fail('היתרה לא יכולה להיות שלילית.');
        $rev = Scc_Admin::now();
        $entry = ['id' => Scc_Admin::uuid(), 'memberId' => $member['id'], 'deltaCents' => Scc_Admin::nv($delta), 'reason' => Scc_Admin::str($d['reason'] ?? null), 'type' => 'manual', 'availableAt' => Scc_Admin::stamp(), 'createdAt' => Scc_Admin::stamp(), 'system' => false];
        Scc_Admin::tx(function () use ($entry, $member, $rev) {
            Scc_Admin::store('loyalty_ledger')->upsert($entry['id'], $entry, $rev);
            $sum = 0; $at = Scc_Admin::stamp();
            foreach (Scc_Admin::store('loyalty_ledger')->rows() as $l) if (($l['memberId'] ?? null) === $member['id'] && ($l['availableAt'] ?? '') <= $at && (empty($l['expiresAt']) || $l['expiresAt'] > $at)) $sum += $l['deltaCents'];
            if ($sum < 0) throw new Scc_Http_Exception(Scc_Admin::GENERIC_503, 503);
        });
        return Scc_Admin::json(array_merge($entry, ['revision' => $rev]), 201);
    }

    /** value-building for POST/PUT of collections | customers | cards | requests | activities ($value = {id,archived,createdAt}). */
    public static function build(string $kind, array $value, array $d, ?array $old): array {
        $S = static fn($k, $n = 500) => Scc_Admin::str($d[$k] ?? null, $n);
        $id = $value['id'];
        if ($kind === 'collections') {
            if ($S('title') === '') Scc_Admin::fail('נדרש שם קולקציה.');
            $ids = Scc_Admin::uniq(Scc_Admin::arr($d['productIds'] ?? null));
            $pos = Scc_Admin::num(Scc_Admin::truthy($d['position'] ?? null) ? $d['position'] : 0);
            $value = array_merge($value, ['title' => $S('title'), 'description' => $S('description', 2000), 'active' => ($d['active'] ?? null) === true, 'position' => Scc_Admin::nv($pos), 'productIds' => $ids]);
            $have = array_column(Scc_Admin::store('products')->rows(), 'id');
            foreach ($ids as $x) if (!in_array($x, $have, true)) Scc_Admin::fail('מוצר לא קיים בקולקציה.');
        } elseif ($kind === 'customers') {
            $c = Scc_Admin_Manage::clean_customer($d);
            foreach (Scc_Admin::store('customers')->rows() as $x) if ($x['id'] !== ($old['id'] ?? null) && ($x['email'] ?? null) === $c['email'] && empty($x['archived'])) Scc_Admin::fail('אימייל לקוח כבר קיים.', 409);
            $value = array_merge($value, $c, ['tags' => $S('tags'), 'notes' => $S('notes', 3000), 'birthday' => $S('birthday', 30), 'preferredSize' => $S('preferredSize', 30)]);
        } elseif ($kind === 'cards') {
            $c = Scc_Admin_Manage::clean_customer($d);
            if (!in_array($d['tier'] ?? null, ['standard', 'vip'], true)) Scc_Admin::fail('דרגת חברות לא תקינה.');
            foreach (Scc_Admin::store('member_profiles')->rows() as $x) if (($x['email'] ?? null) === $c['email'] && $x['id'] !== ($old['id'] ?? null) && empty($x['archived'])) Scc_Admin::fail('חבר עם אימייל זה כבר קיים.', 409);
            $value = array_merge($value, $c, ['tier' => $d['tier'], 'status' => in_array($d['status'] ?? null, ['active', 'suspended'], true) ? $d['status'] : 'active', 'customerId' => $S('customerId'),
                'birthday' => $S('birthday', 30), 'notes' => $S('notes', 2000),
                'cardNumber' => Scc_Admin::truthy($old['cardNumber'] ?? null) ? $old['cardNumber'] : Scc_Admin::brand() . '-' . strtoupper(substr(str_replace('-', '', $id), 0, 12)), 'marketingConsent' => false]);
        } elseif ($kind === 'requests') {
            $c = Scc_Admin_Manage::clean_customer($d);
            if (!in_array($d['type'] ?? null, ['support', 'cancel', 'return', 'exchange'], true) || !in_array($d['status'] ?? null, ['new', 'reviewing', 'collection', 'resolved', 'rejected'], true)) Scc_Admin::fail('סוג או מצב פנייה אינו תקין.');
            $value = array_merge($value, $c, ['type' => $d['type'], 'status' => $d['status'], 'orderNumber' => $S('orderNumber'), 'message' => $S('message', 2000), 'notes' => $S('notes', 3000),
                'collectionDate' => $S('collectionDate'), 'tracking' => $S('tracking'), 'resolution' => $S('resolution', 2000), 'source' => 'manual']);
        } elseif ($kind === 'activities') {
            if ($S('title') === '' || $S('reason') === '') Scc_Admin::fail('נדרשים כותרת וסיבה לפעילות ידנית.');
            $hist = [];
            if ($old) {
                $hist = Scc_Admin::arr($old['history'] ?? null);
                $hist[] = ['changedAt' => Scc_Admin::stamp(), 'actor' => Scc_Admin::user_label(), 'title' => $old['title'] ?? null, 'reason' => $old['reason'] ?? null, 'notes' => $old['notes'] ?? null, 'status' => $old['status'] ?? null];
                $hist = array_slice($hist, -50);
            }
            $value = array_merge($value, ['title' => $S('title'), 'reason' => $S('reason', 2000), 'notes' => $S('notes', 3000), 'dueDate' => $S('dueDate'),
                'status' => in_array($d['status'] ?? null, ['open', 'done'], true) ? $d['status'] : 'open', 'system' => false, 'history' => $hist]);
        } else Scc_Admin::fail('פעולה לא נתמכת.', 405);
        return $value;
    }
}

Scc_Admin::route('POST', '#^/api/admin/manage/loyalty-adjust$#', fn(Scc_Request $r) => Scc_Admin_Crm::adjust($r));
