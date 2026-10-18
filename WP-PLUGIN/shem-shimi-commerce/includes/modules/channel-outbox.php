<?php
if (!defined('ABSPATH')) exit;
/**
 * Durable outbox to the other store. State machine per event:
 *   pending -> sending -> delivered
 *                      -> retry (attempt failed, retryable, next_at = now + exponential backoff) -> sending ...
 *                      -> dead  (attempts >= max_attempts, or a permanent 4xx) - visible in admin, manual retry puts it back to pending
 * Delivery runs on Action Scheduler (hook scc_channel_deliver, group scc-channel) with a 1-minute sweeper (scc_channel_sweep) as safety net,
 * plus one inline attempt at the end of the request that created the event (disabled with SCC_CHANNEL_INLINE=false or in WP-CLI).
 * Claiming is a single conditional UPDATE, so two workers can never send the same event concurrently; the receiver additionally
 * de-duplicates on the event id (inbox), so a delivery that succeeded but was not acknowledged is safe to repeat.
 */
final class Scc_Channel_Outbox {
    const GROUP = 'scc-channel';
    const LOCK_SECONDS = 90;
    private static array $inline = [];
    public static function t(): string { return Scc_Channel_Tx::t('outbox'); }
    public static function backoff(int $attempts): int {
        $s = (int) min(3600, 30 * (2 ** max(0, $attempts - 1)));
        $s = (int) round($s * (0.9 + (random_int(0, 200) / 1000)));
        return (int) apply_filters('scc_channel_backoff', $s, $attempts);
    }
    public static function enqueue(string $kind, string $path, array $payload, string $domain_key = '', string $revision = '', ?string $event_id = null, int $max = 8): string {
        global $wpdb;
        $event_id ??= $kind . ':' . wp_generate_uuid4();
        $now = Scc_Channel::ts();
        $n = $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::t() . " (event_id,kind,path,payload,state,attempts,max_attempts,next_at,domain_key,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,'pending',0,%d,%d,%s,%s,%d,%d)", $event_id, $kind, $path, Scc_Channel::json($payload), $max, $now, $domain_key, $revision, $now, $now));
        if ($n) { self::schedule($event_id, $now); self::$inline[$event_id] = true; self::arm_inline(); }
        return $event_id;
    }
    public static function schedule(string $event_id, int $at): void {
        if (function_exists('as_schedule_single_action')) {
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('scc_channel_deliver', [$event_id], self::GROUP)) return;
            as_schedule_single_action(max($at, time()), 'scc_channel_deliver', [$event_id], self::GROUP);
        } elseif (!wp_next_scheduled('scc_channel_deliver', [$event_id])) {
            wp_schedule_single_event(max($at, time()), 'scc_channel_deliver', [$event_id]);
        }
    }
    private static function arm_inline(): void {
        static $armed = false;
        if ($armed || (defined('SCC_CHANNEL_INLINE') && SCC_CHANNEL_INLINE === false) || (defined('WP_CLI') && WP_CLI)) return;
        $armed = true;
        add_action('shutdown', function () {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            foreach (array_keys(self::$inline) as $id) { try { self::deliver($id); } catch (Throwable $e) { error_log('[scc-channel] inline ' . $e->getMessage()); } }
            self::$inline = [];
        }, 99);
    }
    /** Claim and send one event. Returns the resulting state, or null when it was not claimable (not due / taken / delivered). */
    public static function deliver(string $event_id, bool $force = false): ?string {
        global $wpdb; $t = self::t(); $now = Scc_Channel::ts();
        $due = $force ? '' : $wpdb->prepare(' AND next_at<=%d', $now);
        $n = $wpdb->query($wpdb->prepare("UPDATE $t SET state='sending',attempts=attempts+1,locked_until=%d,updated_at=%d WHERE event_id=%s AND state IN ('pending','retry')", $now + self::LOCK_SECONDS, $now, $event_id) . $due);
        if (!$n) return null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE event_id=%s", $event_id), ARRAY_A);
        $payload = json_decode($row['payload'], true) ?: [];
        $payload['eventId'] = $event_id;
        try {
            if (apply_filters('scc_channel_outbox_send', null, $row, $payload) === null) Scc_Channel::peer($row['path'], $payload);
            $wpdb->update($t, ['state' => 'delivered', 'delivered_at' => Scc_Channel::ts(), 'updated_at' => Scc_Channel::ts(), 'last_error' => '', 'last_status' => 200, 'locked_until' => 0], ['event_id' => $event_id]);
            self::result($row, true, null);
            return 'delivered';
        } catch (Throwable $e) {
            $status = $e instanceof Scc_Http_Exception ? $e->status : 0;
            $retryable = $status === 0 || $status >= 500 || in_array($status, [408, 425, 429], true);
            $dead = !$retryable || (int) $row['attempts'] >= (int) $row['max_attempts'];
            $st = $dead ? 'dead' : 'retry';
            $next = Scc_Channel::ts() + ($dead ? 0 : self::backoff((int) $row['attempts']));
            $wpdb->update($t, ['state' => $st, 'next_at' => $next, 'updated_at' => Scc_Channel::ts(), 'last_error' => mb_substr($e->getMessage(), 0, 500), 'last_status' => $status, 'locked_until' => 0], ['event_id' => $event_id]);
            if (!$dead) self::schedule($event_id, $next);
            self::result($row, false, $e, $st);
            return $st;
        }
    }
    /** Link status follows the outbox (the admin sees sync-pending while retrying, error once dead-lettered). */
    private static function result(array $row, bool $ok, ?Throwable $e, string $state = 'delivered'): void {
        $p = json_decode($row['payload'], true) ?: [];
        if ($row['kind'] === 'refresh' && !empty($p['id'])) {
            $link = Scc_Channel_Links::get((string) $p['id']);
            if ($link) {
                $upd = $ok ? ['status' => 'connected', 'lastSyncAt' => Scc_Channel::now(), 'error' => ''] : ['status' => $state === 'dead' ? 'error' : 'sync-pending', 'error' => $e->getMessage()];
                Scc_Docs::put(Scc_Channel_Links::TABLE, (string) $p['id'], array_merge($link, $upd));
            }
        }
        do_action('scc_channel_outbox_result', $row, $ok, $e, $state);
    }
    /** Cron: reclaim crashed workers, send what is due, expire holds, trim old rows. */
    public static function sweep(): array {
        global $wpdb; $t = self::t(); $now = Scc_Channel::ts();
        $reclaimed = (int) $wpdb->query($wpdb->prepare("UPDATE $t SET state='retry',next_at=%d,last_error='worker lost' WHERE state='sending' AND locked_until<%d", $now, $now));
        $due = $wpdb->get_col($wpdb->prepare("SELECT event_id FROM $t WHERE state IN ('pending','retry') AND next_at<=%d ORDER BY next_at LIMIT 50", $now));
        $sent = 0; foreach ($due as $id) { if (self::deliver($id) !== null) $sent++; }
        $expired = Scc_Channel_Stock::expire_due();
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE state='delivered' AND delivered_at<%d", $now - 30 * 86400));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Scc_Channel_Tx::t('inbox') . " WHERE status='done' AND updated_at<%d", $now - 30 * 86400));
        return ['reclaimed' => $reclaimed, 'sent' => $sent, 'expired' => $expired];
    }
    public static function retry(string $event_id): bool {
        global $wpdb; $now = Scc_Channel::ts();
        $ok = (bool) $wpdb->query($wpdb->prepare('UPDATE ' . self::t() . " SET state='pending',attempts=0,next_at=%d,last_error=NULL,updated_at=%d WHERE event_id=%s AND state IN ('dead','retry')", $now, $now, $event_id));
        if ($ok) self::schedule($event_id, $now);
        return $ok;
    }
    public static function listing(int $limit = 100, string $state = ''): array {
        global $wpdb; $t = self::t();
        $sql = "SELECT event_id,kind,path,state,attempts,max_attempts,next_at,last_error,last_status,domain_key,revision,created_at,updated_at,delivered_at FROM $t" . ($state !== '' ? $wpdb->prepare(' WHERE state=%s', $state) : '') . ' ORDER BY created_at DESC, event_id LIMIT ' . (int) $limit;
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }
    public static function counts(): array {
        global $wpdb; $out = ['pending' => 0, 'sending' => 0, 'retry' => 0, 'dead' => 0, 'delivered' => 0];
        foreach ($wpdb->get_results('SELECT state,COUNT(*) n FROM ' . self::t() . ' GROUP BY state', ARRAY_A) as $r) $out[$r['state']] = (int) $r['n'];
        return $out;
    }
}
add_action('scc_channel_deliver', ['Scc_Channel_Outbox', 'deliver'], 10, 1);
add_action('scc_channel_sweep', ['Scc_Channel_Outbox', 'sweep']);
add_action('action_scheduler_init', function () {
    if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action') && !as_has_scheduled_action('scc_channel_sweep', [], Scc_Channel_Outbox::GROUP)) as_schedule_recurring_action(time() + 60, 60, 'scc_channel_sweep', [], Scc_Channel_Outbox::GROUP);
});
if (!function_exists('as_schedule_single_action')) {
    add_action('init', function () { if (!wp_next_scheduled('scc_channel_sweep')) wp_schedule_event(time() + 60, 'hourly', 'scc_channel_sweep'); });
}
