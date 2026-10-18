<?php
/** SHEM help centre and consent-based experience metrics: /api/help, /api/experience, /api/experience/events, /api/admin/experience. Port of customerExperience() (public + admin part). */
if (!defined('ABSPATH')) exit;
if (SCC_ROLE !== 'shem') return;
require_once __DIR__ . '/cx-base.php';

final class Scc_Cx_Experience {
    const EVENTS = ['product_view', 'cart_add', 'checkout_start', 'review_ready', 'checkout_error', 'payment_open', 'purchase_confirmed', 'search_empty', 'help_open'];

    public static function help(Scc_Request $r): Scc_Response {
        $items = Scc_Cx::setting('help')['items'] ?? null;
        if (is_array($items)) return Scc_Cx::reply(array_values(array_filter($items, fn($i) => is_array($i) && Scc_Cx::truthy($i['active'] ?? null))));
        return Scc_Cx::reply(Scc_Cx::default_help());
    }

    public static function config(Scc_Request $r): Scc_Response {
        $c = Scc_Cx::setting('experience');
        return Scc_Cx::reply([
            'analyticsEnabled' => ($c['analyticsEnabled'] ?? null) !== false,
            'siteAlertsEnabled' => ($c['siteAlertsEnabled'] ?? null) !== false,
            'externalMessagingConnected' => false,
        ]);
    }

    /** Consent-gated, whitelisted, rate limited (120/session/hour), 90-day retention. No personal data is stored. */
    public static function event(Scc_Request $r): Scc_Response {
        global $wpdb;
        Scc_Cx::guard_mutation($r);
        $d = Scc_Cx::parse($r);
        $sid = $d['sessionId'] ?? null;
        if (($d['consent'] ?? null) !== true || !is_string($d['event'] ?? null) || !in_array($d['event'], self::EVENTS, true) || !is_string($sid) || !preg_match('/^[a-zA-Z0-9-]{16,80}$/', $sid))
            Scc_Cx::fail('אירוע לא תקין.');
        if ((Scc_Cx::setting('experience')['analyticsEnabled'] ?? null) === false) return Scc_Cx::reply(['recorded' => false]);
        $t = Scc_Docs::t('cx_events');
        $start = Scc_Cx::stamp(microtime(true) - 3600);
        $cnt = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE id LIKE %s AND " . Scc_Cx::json_col('createdAt') . ' > %s', $wpdb->esc_like($sid . '|') . '%', $start));
        if ($cnt >= 120) return Scc_Cx::reply(['recorded' => false], 429);
        $routes = ['home', 'product', 'shop', 'checkout', 'account', 'help'];
        $reasons = ['network', 'validation', 'payment', 'no-results', 'unknown'];
        $detail = [
            'route' => is_string($d['route'] ?? null) && in_array($d['route'], $routes, true) ? $d['route'] : 'other',
            'productId' => is_string($d['productId'] ?? null) && preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $d['productId']) ? $d['productId'] : '',
            'reason' => is_string($d['reason'] ?? null) && in_array($d['reason'], $reasons, true) ? $d['reason'] : '',
        ];
        Scc_Docs::put('cx_events', $sid . '|' . wp_generate_uuid4(), ['sessionId' => $sid, 'event' => $d['event'], 'detail' => $detail, 'createdAt' => Scc_Cx::stamp()]);
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE updated_at < %s", gmdate('c', time() - 90 * 86400)));
        return Scc_Cx::reply(['recorded' => true], 201);
    }

    public static function admin_get(Scc_Request $r): Scc_Response {
        global $wpdb;
        $help = Scc_Cx::setting('help'); $c = Scc_Cx::setting('experience');
        $since = gmdate('c', time() - 30 * 86400);
        $ev = $wpdb->get_results($wpdb->prepare('SELECT ' . Scc_Cx::json_col('event') . ' AS event, COUNT(*) AS events, COUNT(DISTINCT ' . Scc_Cx::json_col('sessionId') . ') AS sessions FROM ' . Scc_Docs::t('cx_events') . ' WHERE updated_at > %s GROUP BY event', $since), ARRAY_A);
        $events = array_map(fn($x) => ['event' => $x['event'], 'events' => (int) $x['events'], 'sessions' => (int) $x['sessions']], $ev);
        $rq = $wpdb->get_results($wpdb->prepare('SELECT ' . Scc_Cx::json_col('type') . ' AS type, COUNT(*) AS count FROM ' . Scc_Docs::t('requests') . ' WHERE updated_at > %s GROUP BY type', $since), ARRAY_A);
        $requests = array_map(fn($x) => ['type' => $x['type'], 'count' => (int) $x['count']], $rq);
        return Scc_Cx::reply([
            'help' => is_array($help['items'] ?? null) ? $help['items'] : Scc_Cx::default_help(),
            'config' => [
                'analyticsEnabled' => ($c['analyticsEnabled'] ?? null) !== false,
                'siteAlertsEnabled' => ($c['siteAlertsEnabled'] ?? null) !== false,
            ],
            'events' => $events,
            'requests' => $requests,
            'externalMessagingConnected' => false,
            'trackingConnected' => Scc_Cx_Shipment::secret() !== '',
        ]);
    }

    public static function admin_put(Scc_Request $r): Scc_Response {
        global $wpdb;
        Scc_Cx::guard_mutation($r);
        $d = Scc_Cx::parse($r);
        if (!is_array($d['help'] ?? null) || !array_is_list($d['help']) || count($d['help']) > 100) Scc_Cx::fail('ניתן להוסיף עד מאה תשובות.');
        $help = [];
        foreach ($d['help'] as $i) {
            $i = is_array($i) ? $i : [];
            $id = Scc_Cx::text($i['id'] ?? null, 100);
            $help[] = [
                'id' => $id !== '' ? $id : wp_generate_uuid4(),
                'title' => Scc_Cx::text($i['title'] ?? null, 200),
                'answer' => Scc_Cx::text($i['answer'] ?? null, 4000),
                'category' => in_array($i['category'] ?? null, ['product', 'orders', 'delivery', 'returns', 'payment', 'club'], true) ? $i['category'] : 'orders',
                'placement' => in_array($i['placement'] ?? null, ['all', 'product', 'checkout', 'none'], true) ? $i['placement'] : 'all',
                'action' => in_array($i['action'] ?? null, ['shop', 'payments', 'account', 'contact', 'cancel', 'club'], true) ? $i['action'] : 'contact',
                'active' => ($i['active'] ?? null) === true,
            ];
        }
        $ids = array_column($help, 'id');
        foreach ($help as $i) if ($i['title'] === '' || $i['answer'] === '') Scc_Cx::fail('יש להזין שאלה ותשובה ולהימנע ממזהים כפולים.');
        if (count(array_unique($ids)) !== count($ids)) Scc_Cx::fail('יש להזין שאלה ותשובה ולהימנע ממזהים כפולים.');
        $cfg = is_array($d['config'] ?? null) ? $d['config'] : [];
        $config = ['analyticsEnabled' => ($cfg['analyticsEnabled'] ?? null) === true, 'siteAlertsEnabled' => ($cfg['siteAlertsEnabled'] ?? null) !== false];
        $wpdb->query('START TRANSACTION');
        try {
            Scc_Docs::set_setting('help', ['items' => $help]);
            Scc_Docs::set_setting('experience', $config);
            Scc_Cx::audit(Scc_Cx::identity($r)['id'], 'עדכון מרכז העזרה ושירות עצמי');
            $wpdb->query('COMMIT');
        } catch (Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
        return Scc_Cx::reply(['ok' => true]);
    }
}

Scc_Router::add('GET', '#^/api/help$#', [Scc_Cx_Experience::class, 'help']);
Scc_Router::add('GET', '#^/api/experience$#', [Scc_Cx_Experience::class, 'config']);
Scc_Router::add('POST', '#^/api/experience/events$#', [Scc_Cx_Experience::class, 'event']);
Scc_Router::add('GET', '#^/api/admin/experience$#', [Scc_Cx_Experience::class, 'admin_get'], 'admin');
Scc_Router::add('PUT', '#^/api/admin/experience$#', [Scc_Cx_Experience::class, 'admin_put'], 'admin');
