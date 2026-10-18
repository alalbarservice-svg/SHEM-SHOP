<?php
if (!defined('ABSPATH')) exit;
/**
 * Channel tables. Created through the shared `scc_install_tables` action (fresh installs) and, because the shared
 * Scc_Docs::maybe_install() only runs when SCC_VERSION changes, also by our own versioned check (option scc_channel_db_version).
 *   reservations : 20 minute stock holds (PK reservation+product+variant+size), state held|committed|released|expired
 *   ledger       : one-time stock movements; UNIQUE movement_key is the exactly-once guard for every decrement
 *   locks        : one row per product, SELECT .. FOR UPDATE serialises reserve/commit per product
 *   outbox       : durable events to the other store (state machine + retry/backoff + dead-letter)
 *   inbox        : processed inbound event ids (replay protection)
 *   link_keys    : UNIQUE(side, legacy_id) for product_links (source product linked to one card, home card to one source)
 */
final class Scc_Channel_Schema {
    const VERSION = '1.0.0';
    public static function install($charset = null): void {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $charset ?: $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'scc_channel_';
        dbDelta("CREATE TABLE {$p}reservations (
 reservation_id varchar(128) NOT NULL,
 product_id varchar(128) NOT NULL,
 variant_id varchar(128) NOT NULL DEFAULT '',
 size varchar(64) NOT NULL DEFAULT '',
 qty int(10) unsigned NOT NULL,
 state varchar(12) NOT NULL,
 expires_at bigint(20) unsigned NOT NULL,
 created_at bigint(20) unsigned NOT NULL,
 updated_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (reservation_id,product_id,variant_id,size),
 KEY stock (product_id,state,expires_at),
 KEY res_state (reservation_id,state)
) $c;");
        dbDelta("CREATE TABLE {$p}ledger (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 movement_key varchar(190) NOT NULL,
 product_id varchar(128) NOT NULL,
 variant_id varchar(128) NOT NULL DEFAULT '',
 size varchar(64) NOT NULL DEFAULT '',
 delta int(11) NOT NULL,
 reason varchar(255) NOT NULL DEFAULT '',
 ref varchar(190) NOT NULL DEFAULT '',
 applied_by varchar(20) NOT NULL DEFAULT 'channel',
 created_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (id),
 UNIQUE KEY movement_key (movement_key),
 KEY product (product_id,created_at)
) $c;");
        dbDelta("CREATE TABLE {$p}locks (
 product_id varchar(128) NOT NULL,
 PRIMARY KEY  (product_id)
) $c;");
        dbDelta("CREATE TABLE {$p}outbox (
 event_id varchar(190) NOT NULL,
 kind varchar(40) NOT NULL,
 path varchar(190) NOT NULL DEFAULT '',
 payload longtext NOT NULL,
 state varchar(12) NOT NULL,
 attempts int(10) unsigned NOT NULL DEFAULT 0,
 max_attempts int(10) unsigned NOT NULL DEFAULT 8,
 next_at bigint(20) unsigned NOT NULL DEFAULT 0,
 locked_until bigint(20) unsigned NOT NULL DEFAULT 0,
 last_error text NULL,
 last_status int(11) NOT NULL DEFAULT 0,
 domain_key varchar(190) NOT NULL DEFAULT '',
 revision varchar(64) NOT NULL DEFAULT '',
 created_at bigint(20) unsigned NOT NULL,
 updated_at bigint(20) unsigned NOT NULL,
 delivered_at bigint(20) unsigned NULL,
 PRIMARY KEY  (event_id),
 KEY state_next (state,next_at),
 KEY domain (domain_key)
) $c;");
        dbDelta("CREATE TABLE {$p}inbox (
 event_id varchar(190) NOT NULL,
 kind varchar(40) NOT NULL DEFAULT '',
 status varchar(12) NOT NULL,
 locked_until bigint(20) unsigned NOT NULL DEFAULT 0,
 response longtext NULL,
 created_at bigint(20) unsigned NOT NULL,
 updated_at bigint(20) unsigned NOT NULL,
 PRIMARY KEY  (event_id)
) $c;");
        dbDelta("CREATE TABLE {$p}link_keys (
 side varchar(8) NOT NULL,
 legacy_id varchar(128) NOT NULL,
 link_id varchar(128) NOT NULL,
 PRIMARY KEY  (side,legacy_id),
 KEY link (link_id)
) $c;");
        update_option('scc_channel_db_version', self::VERSION, false);
    }
    public static function maybe_install(): void { if (get_option('scc_channel_db_version') !== self::VERSION) self::install(); }
}
add_action('scc_install_tables', ['Scc_Channel_Schema', 'install']);
add_action('plugins_loaded', ['Scc_Channel_Schema', 'maybe_install'], 20);
