<?php
if (!defined('ABSPATH')) exit;
/**
 * Document store mirroring the D1 tables (id + JSON data + updated_at) so worker logic ports 1:1.
 * Table names mirror SHEM/db/schema.ts: wp_scc_<table>. Generic doc tables are created from DOC_TABLES.
 * Modules needing relational tables (reservations, ledgers...) create their own via scc_install_tables action.
 */
final class Scc_Docs {
    const DOC_TABLES = ['settings','products','orders','promotions','collections','customers','requests','member_profiles','activities','loyalty_ledger','invoice_documents','mail_jobs','document_sequences','operation_guards','payment_transactions','product_links','payment_sessions','audit','stock_movements','members','cx_watches','cx_events','cx_preferences','cx_shipment_events'];
    public static function t(string $name): string { global $wpdb; return $wpdb->prefix . 'scc_' . $name; }
    public static function install(): void {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        foreach (self::DOC_TABLES as $n) {
            dbDelta('CREATE TABLE ' . self::t($n) . " (id varchar(190) NOT NULL, data longtext NOT NULL, updated_at varchar(40) NOT NULL, PRIMARY KEY (id), KEY updated_at (updated_at)) $c;");
        }
        do_action('scc_install_tables', $c);
        update_option('scc_db_version', SCC_VERSION);
    }
    public static function maybe_install(): void { if (get_option('scc_db_version') !== SCC_VERSION) self::install(); }
    public static function get(string $table, string $id): ?array {
        global $wpdb; $r = $wpdb->get_var($wpdb->prepare('SELECT data FROM ' . self::t($table) . ' WHERE id=%s', $id));
        return $r === null ? null : (json_decode($r, true) ?: []);
    }
    public static function put(string $table, string $id, array $data): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare('REPLACE INTO ' . self::t($table) . ' (id,data,updated_at) VALUES (%s,%s,%s)', $id, wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), gmdate('c')));
    }
    public static function delete(string $table, string $id): void { global $wpdb; $wpdb->delete(self::t($table), ['id' => $id]); }
    /** @return array<string,array> id => data */
    public static function all(string $table): array {
        global $wpdb; $out = [];
        foreach ($wpdb->get_results('SELECT id,data FROM ' . self::t($table) . ' ORDER BY updated_at DESC', ARRAY_A) as $r) $out[$r['id']] = json_decode($r['data'], true) ?: [];
        return $out;
    }
    public static function setting(string $key, mixed $default = null): mixed { $d = self::get('settings', $key); return $d === null ? $default : ($d['value'] ?? $default); }
    public static function set_setting(string $key, mixed $value): void { self::put('settings', $key, ['value' => $value]); }
}
