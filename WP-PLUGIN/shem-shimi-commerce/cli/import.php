<?php
/**
 * wp scc import --source=<dir> [--assets=<public dir>] [--report-dir=<dir>] [--dry-run | --commit] [--overwrite-settings]
 *
 * Imports the read-only export of a live store (catalogue, collections, store settings, help centre) into the CURRENT site
 * (store = SCC_ROLE: shem -> shem-menswear-*.json, shimi -> shimi-basics-*.json). Never contacts a live site.
 *
 *  - DRY-RUN IS THE DEFAULT. Nothing is written (no DB row, no file, no attachment) unless --commit (or --no-dry-run) is given.
 *  - Idempotent by legacy id: a second run creates and updates nothing (products by Scc_Core::upsert_product_from_legacy,
 *    collections/settings/images by content comparison).
 *  - Migration context: Scc_Core::$importing, Scc_Channel_Catalog::$quiet (no cross-store event), wp_mail short-circuited and counted,
 *    constant SCC_MIGRATION_IMPORT. After a commit run every table that must not change (mail, stock movements, loyalty, payments,
 *    outbox, orders, coupons, users, scheduled actions) is compared before/after and reported as side_effects.
 *  - Never imports customers/orders/promotions: they are only detected. A file of those kinds in the export is reported as an error.
 *  - Images from the repo public dir are copied byte-for-byte to uploads/scc-import and attached; sha256 is verified after the copy.
 *  - Report: <report-dir>/import-<store>-<date>[-dry-run][-N].json with one record per source object.
 *
 * Load: add `if (defined('WP_CLI') && WP_CLI) require SCC_DIR . 'cli/import.php';` to the plugin bootstrap, or run
 * `wp --require=<plugin>/cli/import.php scc import ...` (this file does not need WordPress loaded to be required).
 */
if (!defined('ABSPATH') && !defined('WP_CLI')) exit;
if (!defined('WP_CLI') || !WP_CLI) return;
if (defined('SCC_IMPORT_CLI_LOADED')) return; // top-level classes are hoisted, so a class_exists() guard would always fire
define('SCC_IMPORT_CLI_LOADED', true);

final class Scc_Import_Command {
    const VERSION = '1.0.0';
    const STORES = ['shem' => 'shem-menswear', 'shimi' => 'shimi-basics'];
    /** kinds the importer understands, per file suffix */
    const KNOWN = ['catalogue', 'collections', 'help', 'store'];
    /** export file kinds that would carry personal / operational data: never imported, always flagged */
    const FORBIDDEN_RX = '/(order|customer|member|promotion|coupon|loyalty|ledger|request|invoice|payment|user)/i';
    /** tables expected to change in a commit run; every other scc table, wc order/coupon table, users, mail must stay equal */
    const EXPECTED_TABLES = ['scc_products', 'scc_collections', 'scc_settings'];

    private array $records = [];
    private array $files = [];
    private array $notes = [];
    private int $mails = 0;
    private bool $dry = true;
    private string $store = '';
    private string $assets = '';
    private array $imgCache = [];
    private array $seenSku = [];

    /**
     * ## OPTIONS
     * --source=<dir>          Read-only export directory (with SHA256SUMS).
     * [--assets=<dir>]        public/ dir of the store source tree (contains assets/). Default: auto-detect next to the export.
     * [--report-dir=<dir>]    Where the JSON report goes. Default: <source>/../shem-shop/docs/reports when it exists, else uploads/scc-import-reports.
     * [--dry-run]             Default. Report only.
     * [--commit]              Write for real (same as --no-dry-run).
     * [--overwrite-settings]  Replace staging setting values that differ from the export (default: keep them and report a conflict).
     * [--skip-images]        Do not ingest images (report them as skipped).
     */
    public function __invoke($args, $assoc) {
        $t0 = microtime(true);
        $src = rtrim((string) ($assoc['source'] ?? ''), '/');
        if ($src === '' || !is_dir($src)) WP_CLI::error('--source=<dir> is required and must be a directory.');
        $src = realpath($src);
        $this->srcdir = $src;
        $commit = !empty($assoc['commit']) || (array_key_exists('dry-run', $assoc) && ($assoc['dry-run'] === false || $assoc['dry-run'] === 'false'));
        if ($commit && !empty($assoc['commit']) && !empty($assoc['dry-run']) && $assoc['dry-run'] !== 'false') WP_CLI::error('--dry-run and --commit are mutually exclusive.');
        $this->dry = !$commit;
        $overwrite = !empty($assoc['overwrite-settings']);
        $skipImages = !empty($assoc['skip-images']);

        // ---- preconditions
        $role = defined('SCC_ROLE') ? SCC_ROLE : '';
        if (!isset(self::STORES[$role])) WP_CLI::error('SCC_ROLE must be shem or shimi (wp-config).');
        if (!class_exists('Scc_Core') || !class_exists('Scc_Docs') || !function_exists('wc_get_product')) WP_CLI::error('The commerce plugin and WooCommerce must be active.');
        $this->store = self::STORES[$role];
        WP_CLI::log(sprintf('scc import %s | store=%s role=%s | mode=%s | source=%s', self::VERSION, $this->store, $role, $this->dry ? 'DRY-RUN (nothing is written)' : 'COMMIT', $src));
        $this->assets = $this->find_assets($assoc['assets'] ?? '', $src, $role);

        // ---- export integrity
        $this->verify_files($src);
        $fatal = array_filter($this->files, fn($f) => !$f['ok'] && ($f['store'] === $this->store || $f['store'] === ''));
        $this->scan_foreign($src);

        $data = [];
        foreach (self::KNOWN as $k) {
            $f = $src . '/' . $this->store . '-' . $k . '.json';
            $data[$k] = is_file($f) ? file_get_contents($f) : null;
            if ($data[$k] === null) $this->note('error', "missing export file: {$this->store}-$k.json");
        }
        if ($fatal) {
            foreach ($fatal as $f) $this->note('error', 'checksum/availability failure: ' . $f['name'] . ' (' . $f['problem'] . ')');
            WP_CLI::warning('Export integrity failed: nothing will be imported.');
            $this->finish($assoc, $src, $role, $t0, null, null);
            return;
        }

        // ---- migration context + before snapshot
        $before = $this->snapshot();
        if (!$this->dry) $this->enter_context();

        // ---- run
        $this->do_products($data['catalogue'], $skipImages);
        $this->do_collections($data['collections']);
        $this->do_settings($data['store'], $data['catalogue'], $overwrite);
        $this->do_help($data['help']);
        $this->detect_absent();

        $after = $this->snapshot();
        if (!$this->dry) $this->leave_context();
        $this->finish($assoc, $src, $role, $t0, $before, $after);
    }

    /* ------------------------------------------------------------------ plumbing */
    private function note(string $level, string $msg, array $ctx = []): void { $this->notes[] = ['level' => $level] + ['message' => $msg] + $ctx; }
    private function rec(string $type, string $sid, string $result, array $extra = []): void {
        $this->records[] = array_merge(['type' => $type, 'source_id' => $sid, 'target_id' => null, 'result' => $result, 'converted' => [], 'error' => null, 'warnings' => [], 'sha256' => null], $extra);
    }
    private static function sha(string $s): string { return hash('sha256', $s); }
    private static function canon(mixed $v): string {
        $f = function ($x) use (&$f) {
            if (is_array($x)) { if (!array_is_list($x)) ksort($x); return array_map($f, $x); }
            return $x;
        };
        return (string) json_encode($f($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function find_assets(string $given, string $src, string $role): string {
        $pub = strtoupper($role);
        $cand = array_filter([$given, $src . '/public', dirname($src) . '/shem-shop/' . $pub . '/public', dirname($src) . '/' . $pub . '/public']);
        foreach ($cand as $c) if (is_dir($c . '/assets')) return rtrim(realpath($c), '/');
        $this->note('error', 'asset source dir not found (pass --assets=<' . $pub . '/public>)');
        return '';
    }

    private function verify_files(string $src): void {
        $sums = $src . '/SHA256SUMS';
        if (!is_file($sums)) { $this->note('error', 'SHA256SUMS is missing in the export'); return; }
        $listed = [];
        foreach (preg_split('/\R/', (string) file_get_contents($sums)) as $line) {
            if (!preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/i', trim($line), $m)) continue;
            $name = basename($m[2]); $listed[$name] = true; $p = $src . '/' . $name;
            $actual = is_file($p) ? hash_file('sha256', $p) : null;
            $store = '';
            foreach (self::STORES as $s) if (str_starts_with($name, $s . '-')) $store = $s;
            $this->files[] = ['name' => $name, 'store' => $store, 'expected' => strtolower($m[1]), 'actual' => $actual, 'ok' => $actual !== null && hash_equals(strtolower($m[1]), $actual),
                'problem' => $actual === null ? 'file missing' : ($actual === strtolower($m[1]) ? '' : 'sha256 mismatch')];
        }
        foreach (glob($src . '/*') ?: [] as $p) if (is_file($p) && basename($p) !== 'SHA256SUMS' && empty($listed[basename($p)])) {
            $this->files[] = ['name' => basename($p), 'store' => '', 'expected' => null, 'actual' => hash_file('sha256', $p), 'ok' => false, 'problem' => 'not listed in SHA256SUMS'];
        }
    }

    /** Files in the export that this importer must not silently skip. */
    private function scan_foreign(string $src): void {
        foreach (glob($src . '/*') ?: [] as $p) {
            $b = basename($p);
            if ($b === 'SHA256SUMS') continue;
            $mine = false;
            foreach (self::KNOWN as $k) if ($b === $this->store . '-' . $k . '.json') $mine = true;
            if ($mine) continue;
            $other = false;
            foreach (self::STORES as $s) if ($s !== $this->store && str_starts_with($b, $s . '-')) $other = true;
            if ($other) { $this->note('info', "ignored (other store): $b"); continue; }
            if (preg_match(self::FORBIDDEN_RX, $b)) $this->note('error', "export contains personal/operational data this tool never imports: $b");
            else $this->note('warning', "unrecognised export file ignored: $b");
        }
        // top-level keys of the files we do read
    }

    /** Customers/orders/promotions/members/loyalty are never imported; record whether the export carries them (scan_foreign already errors on a hit). */
    private function detect_absent(): void {
        foreach (['customers', 'orders', 'promotions', 'members', 'loyalty'] as $k) {
            $hit = glob($this->srcdir . '/' . $this->store . '-' . $k . '*.json') ?: [];
            $this->rec($k, '*', $hit ? 'present_in_export_NOT_IMPORTED' : 'absent_in_export');
        }
    }
    private ?string $srcdir = null;

    /* ------------------------------------------------------------------ migration context */
    private function enter_context(): void {
        if (!defined('SCC_MIGRATION_IMPORT')) define('SCC_MIGRATION_IMPORT', true);
        Scc_Core::$importing = true;
        if (class_exists('Scc_Channel_Catalog')) Scc_Channel_Catalog::$quiet = true;
        add_filter('pre_wp_mail', function () { $this->mails++; return false; }, 1, 0);
    }
    private function leave_context(): void {
        Scc_Core::$importing = false;
        if (class_exists('Scc_Channel_Catalog')) Scc_Channel_Catalog::$quiet = false;
    }
    private function snapshot(): array {
        global $wpdb; $o = [];
        foreach ($wpdb->get_col("SHOW TABLES LIKE '" . $wpdb->esc_like($wpdb->prefix) . "scc%'") as $t) $o[substr($t, strlen($wpdb->prefix))] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$t`");
        foreach (['wc_orders', 'wc_order_addresses', 'wc_orders_meta', 'wc_order_operational_data', 'actionscheduler_actions', 'woocommerce_order_items'] as $n) {
            $t = $wpdb->prefix . $n; if ($wpdb->get_var("SHOW TABLES LIKE '$t'")) $o[$n] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$t`");
        }
        foreach (['shop_order', 'shop_order_refund', 'shop_coupon'] as $pt) $o['posts:' . $pt] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s", $pt));
        $o['users'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");
        $o['posts:product'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'");
        $o['posts:product_variation'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product_variation'");
        $o['posts:attachment'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'");
        return $o;
    }

    /* ------------------------------------------------------------------ raw json slicing (byte-exact per record) */
    private static function skip_value(string $s, int $i): int {
        $n = strlen($s); $c = $s[$i];
        if ($c === '"') { $i++; while ($i < $n) { if ($s[$i] === '\\') $i += 2; elseif ($s[$i] === '"') return $i + 1; else $i++; } return $n; }
        if ($c === '{' || $c === '[') {
            $d = 0;
            while ($i < $n) {
                $ch = $s[$i];
                if ($ch === '"') { $i = self::skip_value($s, $i); continue; }
                if ($ch === '{' || $ch === '[') $d++; elseif ($ch === '}' || $ch === ']') { $d--; if ($d === 0) return $i + 1; }
                $i++;
            }
            return $n;
        }
        while ($i < $n && strpos(",]} \t\r\n", $s[$i]) === false) $i++;
        return $i;
    }
    /** @return string[] exact source bytes of each element of the top-level array (or of the array under $key). */
    private static function raw_slices(string $json, ?string $key): array {
        $n = strlen($json);
        if ($key !== null) {
            if (!preg_match('/"' . preg_quote($key, '/') . '"\s*:\s*\[/', $json, $m, PREG_OFFSET_CAPTURE)) return [];
            $i = $m[0][1] + strlen($m[0][0]);
        } else { $i = strpos($json, '['); if ($i === false) return []; $i++; }
        $out = [];
        while ($i < $n) {
            while ($i < $n && strpos(" \t\r\n,", $json[$i]) !== false) $i++;
            if ($i >= $n || $json[$i] === ']') break;
            $s = $i; $i = self::skip_value($json, $i); $out[] = substr($json, $s, $i - $s);
        }
        return $out;
    }

    /* ------------------------------------------------------------------ products */
    private function do_products(?string $json, bool $skipImages): void {
        if ($json === null) return;
        $doc = json_decode($json, true);
        if (!is_array($doc) || !is_array($doc['products'] ?? null)) { $this->note('error', 'catalogue file is not {products:[...]}'); return; }
        $extraKeys = array_diff(array_keys($doc), ['products', 'configured']);
        if ($extraKeys) $this->note('error', 'catalogue has keys the importer does not handle: ' . implode(',', $extraKeys));
        $raw = self::raw_slices($json, 'products');
        $exact = count($raw) === count($doc['products']);
        $ids = []; $this->seenSku = [];
        foreach ($doc['products'] as $idx => $p) {
            $sid = is_array($p) && is_scalar($p['id'] ?? null) ? (string) $p['id'] : '#' . $idx;
            try {
                if (!is_array($p) || $sid === '' || $sid[0] === '#') throw new InvalidArgumentException('product without id');
                if (isset($ids[$sid])) throw new InvalidArgumentException('duplicate product id in export');
                $ids[$sid] = true;
                $bytes = $exact && json_decode($raw[$idx], true) === $p ? $raw[$idx] : null;
                $warn = [];
                if ($bytes === null) { $bytes = Scc_Core::doc_json($p); $warn[] = 'source bytes not preserved (canonical JSON used)'; }
                $this->validate_product($p, $sid, $warn);
                if ($this->dry) { // own evaluation: upsert's dry path still rewrites the mirror row of unchanged products
                    $pid0 = Scc_Core::find_product_id($sid);
                    $same = $pid0 && (string) get_post_meta($pid0, Scc_Core::M_HASH, true) === sha1($bytes);
                    $res = ['action' => $same ? 'unchanged' : ($pid0 ? 'would_update' : 'would_create'), 'wc_id' => $pid0, 'warnings' => []];
                } else $res = Scc_Core::upsert_product_from_legacy($p, ['raw_json' => $bytes]);
                $warn = array_merge($warn, $res['warnings'] ?? []);
                $extra = ['target_id' => $res['wc_id'] ?: null, 'sha256' => self::sha($bytes), 'converted' => $this->converted($p), 'warnings' => $warn];
                if (!$this->dry && !empty($res['variations'])) $extra['variations'] = $res['variations'];
                // image
                if (!$skipImages) {
                    $img = $this->do_images($p, $sid, (int) $res['wc_id']);
                    $extra['images'] = $img['rows']; if ($img['error']) $extra['error'] = $img['error'];
                    $extra['warnings'] = array_merge($extra['warnings'], $img['warnings']);
                } else $extra['images'] = 'skipped (--skip-images)';
                $this->rec('product', $sid, empty($extra['error']) ? $res['action'] : 'ERROR', $extra + ['action_before_error' => $res['action']]);
            } catch (Throwable $e) {
                $this->rec('product', $sid, 'ERROR', ['error' => $e->getMessage(), 'sha256' => isset($raw[$idx]) ? self::sha($raw[$idx]) : null]);
            }
        }
    }

    private function validate_product(array $p, string $sid, array &$warn): void {
        if (!is_numeric($p['price'] ?? null)) throw new InvalidArgumentException('price missing or not numeric');
        if (isset($p['stock']) && (!is_numeric($p['stock']) || $p['stock'] < 0)) throw new InvalidArgumentException('stock is negative or not numeric');
        $own = Scc_Core::find_product_id($sid);
        $ownKids = $own ? array_map('intval', (wc_get_product($own) ? (wc_get_product($own)->get_children() ?: []) : [])) : [];
        $check = function (string $sku, string $what) use ($own, $ownKids, $sid) {
            if ($sku === '') return;
            $k = strtolower($sku);
            if (isset($this->seenSku[$k])) throw new InvalidArgumentException("sku '$sku' ($what) already used by {$this->seenSku[$k]} in this export");
            $this->seenSku[$k] = "$sid/$what";
            $other = (int) wc_get_product_id_by_sku($sku);
            if ($other && $other !== $own && !in_array($other, $ownKids, true)) throw new InvalidArgumentException("sku '$sku' ($what) already belongs to WooCommerce product #$other");
        };
        $check((string) ($p['sku'] ?? ''), 'product');
        $vids = [];
        foreach ((is_array($p['variants'] ?? null) ? $p['variants'] : []) as $v) {
            $vid = is_array($v) && is_scalar($v['id'] ?? null) ? (string) $v['id'] : '';
            if ($vid === '') throw new InvalidArgumentException('variant without id');
            if (isset($vids[$vid])) throw new InvalidArgumentException("duplicate variant id $vid");
            $vids[$vid] = true;
            $check((string) ($v['sku'] ?? ''), 'variant ' . $vid);
        }
        if (!empty($p['sizeStock']) && !empty($p['variants'])) $warn[] = 'sizeStock ignored for variable product (stock lives on variations)';
    }

    private function converted(array $p): array {
        $c = [];
        $price = (float) ($p['price'] ?? 0); $prev = is_numeric($p['previousPrice'] ?? null) ? (float) $p['previousPrice'] : null;
        $c[] = !empty($p['variants']) ? 'type=variable (' . count($p['variants']) . ' variations)' : 'type=simple';
        $c[] = $prev !== null && $prev > $price ? "price->sale_price $price, regular_price $prev" : "price->regular_price $price";
        $c[] = (($p['active'] ?? true) !== false && empty($p['archived'])) ? 'status=publish' : 'status=draft';
        if (!empty($p['cat'])) $c[] = 'cat->product_cat "' . $p['cat'] . '"';
        if (!empty($p['sizeStock']) && empty($p['variants'])) $c[] = 'sizeStock->_scc_size_stock';
        if (!empty($p['qaBatch']) || !empty($p['demo'])) $c[] = 'flag:qa/demo item in live export (kept as is)';
        $c[] = 'document kept byte-exact in _scc_doc + wp_scc_products mirror';
        return $c;
    }

    /* ------------------------------------------------------------------ images */
    /** Asset path of a product per the storefront rule: imageUrl || /assets/${image||id||"knit"}.png */
    private static function main_asset(array $p): string {
        $u = $p['imageUrl'] ?? null;
        if (is_string($u) && $u !== '') return $u;
        $n = $p['image'] ?? null; if (!is_string($n) || $n === '') $n = isset($p['id']) && is_scalar($p['id']) && (string) $p['id'] !== '' ? (string) $p['id'] : 'knit';
        return '/assets/' . $n . '.png';
    }
    private function do_images(array $p, string $sid, int $pid): array {
        $out = ['rows' => [], 'error' => null, 'warnings' => []];
        $paths = [['main', self::main_asset($p)]];
        foreach ((is_array($p['images'] ?? null) ? $p['images'] : []) as $i => $im) if (is_array($im) && !empty($im['url'])) $paths[] = ['gallery', (string) $im['url']];
        foreach ((is_array($p['colors'] ?? null) ? $p['colors'] : []) as $c) if (is_array($c) && !empty($c['imageUrl'])) $paths[] = ['gallery', (string) $c['imageUrl']];
        $gallery = [];
        foreach ($paths as [$role, $url]) {
            try {
                $r = $this->image($url);
                if ($role === 'main') $r += ['attach' => $this->attach_main($pid, $r['attachment_id'] ?? 0)];
                else { $gallery[] = $r['attachment_id'] ?? 0; }
                $out['rows'][] = ['role' => $role] + $r;
            } catch (Throwable $e) {
                $out['rows'][] = ['role' => $role, 'asset' => $url, 'result' => 'ERROR', 'error' => $e->getMessage()];
                $out['error'] = ($out['error'] ? $out['error'] . '; ' : '') . 'image ' . $url . ': ' . $e->getMessage();
            }
        }
        $gallery = array_values(array_unique(array_filter($gallery)));
        if (!$this->dry && $pid && $gallery) {
            $mine = (string) get_post_meta($pid, '_scc_import_gallery', true); $cur = (string) get_post_meta($pid, '_product_image_gallery', true);
            $new = implode(',', $gallery);
            if ($cur === '' || $cur === $mine) { if ($cur !== $new) { update_post_meta($pid, '_product_image_gallery', $new); } update_post_meta($pid, '_scc_import_gallery', $new); }
            else $out['warnings'][] = 'gallery kept (edited in WordPress after a previous import)';
        }
        return $out;
    }
    /** @return array{asset:string,file:string,sha256:string,bytes:int,result:string,attachment_id?:int} */
    private function image(string $url): array {
        if (isset($this->imgCache[$url]) && ($this->imgCache[$url]['result'] ?? '') !== 'ERROR') return ['result' => 'reused_in_run'] + $this->imgCache[$url];
        if (!preg_match('#^/(assets|media)/[a-zA-Z0-9_./-]+$#', $url) || str_contains($url, '..')) throw new InvalidArgumentException('asset path not allowed');
        if ($this->assets === '') throw new RuntimeException('asset source dir not available');
        $file = $this->assets . $url;
        if (!is_file($file)) throw new RuntimeException('asset file not found in source tree: ' . $url);
        $sha = hash_file('sha256', $file); $bytes = filesize($file);
        $ft = wp_check_filetype(basename($file));
        if (!$ft['type'] || !str_starts_with($ft['type'], 'image/')) throw new RuntimeException('not an image type');
        $row = ['asset' => $url, 'file' => $file, 'sha256' => $sha, 'bytes' => $bytes];
        $existing = $this->find_attachment($sha);
        if ($existing) {
            $path = get_attached_file($existing);
            $ok = $path && is_file($path) && hash_file('sha256', $path) === $sha;
            if (!$ok) throw new RuntimeException("attachment #$existing exists but its file is missing or differs from the source");
            return $this->imgCache[$url] = $row + ['result' => 'exists', 'attachment_id' => $existing, 'target_sha256' => $sha];
        }
        if ($this->dry) return $this->imgCache[$url] = $row + ['result' => 'would_create'];
        $up = wp_upload_dir(); if (!empty($up['error'])) throw new RuntimeException('uploads not writable: ' . $up['error']);
        $dir = $up['basedir'] . '/scc-import'; wp_mkdir_p($dir);
        $dest = $dir . '/' . basename($file);
        if (is_file($dest) && hash_file('sha256', $dest) !== $sha) throw new RuntimeException('different file already at ' . $dest);
        if (!is_file($dest) && !copy($file, $dest)) throw new RuntimeException('copy failed');
        if (hash_file('sha256', $dest) !== $sha) { @unlink($dest); throw new RuntimeException('sha256 mismatch after copy'); }
        add_filter('big_image_size_threshold', '__return_false', 99);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $aid = wp_insert_attachment(['post_mime_type' => $ft['type'], 'post_title' => pathinfo($file, PATHINFO_FILENAME), 'post_status' => 'inherit', 'guid' => $up['baseurl'] . '/scc-import/' . basename($file)], $dest, 0, true);
        if (is_wp_error($aid)) throw new RuntimeException($aid->get_error_message());
        update_post_meta($aid, '_scc_src_sha256', $sha); update_post_meta($aid, '_scc_src_asset', $url);
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $dest));
        $final = hash_file('sha256', $dest);
        if ($final !== $sha) throw new RuntimeException('original file changed during attachment processing');
        return $this->imgCache[$url] = $row + ['result' => 'created', 'attachment_id' => (int) $aid, 'target_sha256' => $final];
    }
    private function find_attachment(string $sha): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key='_scc_src_sha256' AND m.meta_value=%s AND p.post_type='attachment' ORDER BY p.ID LIMIT 1", $sha));
    }
    private function attach_main(int $pid, int $aid): string {
        if ($this->dry) return $pid ? ((int) get_post_thumbnail_id($pid) === $aid && $aid ? 'unchanged' : 'would_set') : 'would_set';
        if (!$pid || !$aid) return 'skipped';
        $cur = (int) get_post_meta($pid, '_thumbnail_id', true); $mine = (int) get_post_meta($pid, '_scc_import_image', true);
        if ($cur === $aid) { update_post_meta($pid, '_scc_import_image', $aid); return 'unchanged'; }
        if ($cur && $cur !== $mine) return 'kept_existing (image edited in WordPress)';
        update_post_meta($pid, '_thumbnail_id', $aid); update_post_meta($pid, '_scc_import_image', $aid);
        return 'set';
    }

    /* ------------------------------------------------------------------ collections */
    private function do_collections(?string $json): void {
        if ($json === null) return;
        global $wpdb;
        $arr = json_decode($json, true);
        if (!is_array($arr) || ($arr && !array_is_list($arr))) { $this->note('error', 'collections file is not a JSON array'); return; }
        $raw = self::raw_slices($json, null);
        $t = Scc_Docs::t('collections');
        $known = array_flip(array_column($this->records_of('product'), 'source_id'));
        foreach ($arr as $i => $c) {
            $sid = is_array($c) && is_scalar($c['id'] ?? null) ? (string) $c['id'] : '#' . $i;
            try {
                if (!is_array($c) || $sid === '' || $sid[0] === '#') throw new InvalidArgumentException('collection without id');
                foreach ((array) ($c['productIds'] ?? []) as $pid) if (!isset($known[(string) $pid]) && !Scc_Core::find_product_id((string) $pid)) throw new InvalidArgumentException("productIds refers to unknown product $pid");
                $rev = isset($c['revision']) && is_string($c['revision']) && $c['revision'] !== '' ? $c['revision'] : null;
                $data = $c; unset($data['revision']);
                $row = $wpdb->get_row($wpdb->prepare("SELECT data,updated_at FROM $t WHERE id=%s", $sid), ARRAY_A);
                $same = $row && self::canon(json_decode($row['data'], true)) === self::canon($data) && ($rev === null || $row['updated_at'] === $rev);
                $bytes = $raw[$i] ?? Scc_Core_Util::enc($c);
                if ($same) { $this->rec('collection', $sid, 'unchanged', ['target_id' => $sid, 'sha256' => self::sha($bytes)]); continue; }
                $result = $row ? 'updated' : 'created';
                if ($this->dry) $result = $row ? 'would_update' : 'would_create';
                else $wpdb->query($wpdb->prepare("INSERT INTO $t (id,data,updated_at) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE data=VALUES(data),updated_at=VALUES(updated_at)",
                    $sid, wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rev ?? Scc_Core_Util::now_iso()));
                $this->rec('collection', $sid, $result, ['target_id' => $sid, 'sha256' => self::sha($bytes), 'converted' => ['stored in wp_scc_collections (data without revision, updated_at = source revision)']]);
            } catch (Throwable $e) {
                $this->rec('collection', $sid, 'ERROR', ['error' => $e->getMessage(), 'sha256' => isset($raw[$i]) ? self::sha($raw[$i]) : null]);
            }
        }
        if (!$arr) $this->rec('collection', '*', 'none_in_export');
    }
    private function records_of(string $type): array { return array_values(array_filter($this->records, fn($r) => $r['type'] === $type)); }

    /* ------------------------------------------------------------------ settings */
    private function put_setting(string $key, mixed $incoming, string $sid, bool $overwrite, ?string $srcBytes = null): void {
        try {
            $cur = Scc_Docs::get('settings', $key);
            $curV = $cur === null ? null : ($cur['value'] ?? null);
            $sha = self::sha($srcBytes ?? Scc_Core_Util::enc($incoming));
            $isObj = is_array($incoming) && ($incoming === [] || !array_is_list($incoming));
            $write = function ($v) use ($key, $isObj) { Scc_Docs::put('settings', $key, ['value' => ($isObj && $v === []) ? new stdClass() : $v]); };
            if ($cur === null) {
                if (!$this->dry) $write($incoming);
                $this->rec('setting', $sid, $this->dry ? 'would_create' : 'created', ['target_id' => $key, 'sha256' => $sha, 'converted' => ['stored as {value: ...} in wp_scc_settings']]);
                return;
            }
            if (self::canon($curV) === self::canon($incoming)) { $this->rec('setting', $sid, 'unchanged', ['target_id' => $key, 'sha256' => $sha]); return; }
            // object settings: fill missing keys, never replace a different non-empty value unless --overwrite-settings
            if ($isObj && is_array($curV) && $incoming !== []) {
                $merged = $curV; $conf = [];
                foreach ($incoming as $k => $v) {
                    if (!array_key_exists($k, $curV) || $curV[$k] === '' || $curV[$k] === null) $merged[$k] = $v;
                    elseif ($curV[$k] !== $v) { if ($overwrite) $merged[$k] = $v; else $conf[] = $k; }
                }
                if (self::canon($merged) === self::canon($curV)) {
                    $this->rec('setting', $sid, $conf ? 'kept_existing_conflict' : 'unchanged', ['target_id' => $key, 'sha256' => $sha, 'warnings' => $conf ? ['staging value differs from export for: ' . implode(',', $conf) . ' (use --overwrite-settings to replace)'] : []]);
                    return;
                }
                if (!$this->dry) $write($merged);
                $this->rec('setting', $sid, $this->dry ? 'would_update' : 'updated', ['target_id' => $key, 'sha256' => $sha, 'warnings' => $conf ? ['kept staging value for: ' . implode(',', $conf)] : []]);
                return;
            }
            if ($incoming === [] || $incoming === null) { $this->rec('setting', $sid, 'kept_existing', ['target_id' => $key, 'sha256' => $sha, 'warnings' => ['export value is empty, staging value kept']]); return; }
            if (!$overwrite) { $this->rec('setting', $sid, 'kept_existing_conflict', ['target_id' => $key, 'sha256' => $sha, 'warnings' => ['staging value differs from export (use --overwrite-settings to replace)']]); return; }
            if (!$this->dry) $write($incoming);
            $this->rec('setting', $sid, $this->dry ? 'would_update' : 'updated', ['target_id' => $key, 'sha256' => $sha]);
        } catch (Throwable $e) { $this->rec('setting', $sid, 'ERROR', ['error' => $e->getMessage()]); }
    }
    private function do_settings(?string $storeJson, ?string $catJson, bool $overwrite): void {
        if ($storeJson !== null) {
            $s = json_decode($storeJson, true);
            if (!is_array($s)) $this->note('error', 'store file is not a JSON object');
            else {
                $extra = array_diff(array_keys($s), ['operations', 'business']);
                if ($extra) $this->note('error', 'store file has keys the importer does not handle: ' . implode(',', $extra));
                foreach (['operations', 'business'] as $k) if (array_key_exists($k, $s)) $this->put_setting($k, $s[$k], $k, $overwrite);
            }
        }
        if ($catJson !== null) {
            $c = json_decode($catJson, true);
            if (is_array($c) && array_key_exists('configured', $c)) $this->put_setting('catalogueInitialized', $c['configured'] === true, 'catalogue.configured', $overwrite);
        }
    }

    /* ------------------------------------------------------------------ help centre (SHEM only) */
    private function do_help(?string $json): void {
        if ($json === null) return;
        if (SCC_ROLE !== 'shem') { $this->rec('help', 'help', 'skipped', ['warnings' => ['help centre is a SHEM-only route; export content: ' . substr(trim($json), 0, 60)]]); return; }
        $items = json_decode($json, true);
        if (!is_array($items) || !array_is_list($items)) { $this->rec('help', 'help', 'ERROR', ['error' => 'help file is not a JSON array']); return; }
        $sha = self::sha($json);
        $def = class_exists('Scc_Cx') ? Scc_Cx::default_help() : null;
        if ($def !== null && self::canon($items) === self::canon($def)) {
            $cur = Scc_Docs::setting('help');
            $this->rec('help', 'help', $cur === null ? 'skipped_equals_built_in_default' : 'kept_existing', ['sha256' => $sha,
                'warnings' => ['export is the PUBLIC /api/help output (active items only); equals the built-in default, so no setting is stored']]);
            return;
        }
        $this->put_setting('help', ['items' => $items], 'help', false, $json);
        $this->records[count($this->records) - 1]['warnings'][] = 'export is the PUBLIC /api/help output: inactive items of the live admin are not in it';
    }

    /* ------------------------------------------------------------------ report */
    private function finish(array $assoc, string $src, string $role, float $t0, ?array $before, ?array $after): void {
        $count = [];
        foreach ($this->records as $r) { $count[$r['type']][$r['result']] = ($count[$r['type']][$r['result']] ?? 0) + 1; }
        $errors = count(array_filter($this->records, fn($r) => str_starts_with($r['result'], 'ERROR') || !empty($r['error']))) + count(array_filter($this->notes, fn($n) => $n['level'] === 'error'));
        $newRecords = 0;
        foreach ($this->records as $r) if (in_array($r['result'], ['created', 'updated'], true)) $newRecords++;
        foreach ($this->records as $r) foreach ((array) ($r['images'] ?? []) as $im) if (is_array($im) && ($im['result'] ?? '') === 'created') $newRecords++;
        $side = null;
        if ($before !== null && $after !== null) {
            $delta = []; foreach ($after as $k => $v) $delta[$k] = $v - ($before[$k] ?? 0);
            $allowed = array_merge(self::EXPECTED_TABLES, ['posts:product', 'posts:product_variation', 'posts:attachment']);
            $bad = array_filter($delta, fn($d, $k) => $d !== 0 && !in_array($k, $allowed, true), ARRAY_FILTER_USE_BOTH);
            $side = ['mode' => $this->dry ? 'dry-run (nothing written)' : 'commit', 'wp_mail_attempts_suppressed' => $this->mails, 'row_delta' => array_filter($delta, fn($d) => $d !== 0), 'unexpected_changes' => $bad, 'ok' => !$bad && $this->mails === 0];
            if ($bad || $this->mails) { $errors++; $this->note('error', 'migration side effects detected: ' . json_encode($bad) . ' mails=' . $this->mails); }
        }
        $report = [
            'tool' => 'wp scc import ' . self::VERSION, 'store' => $this->store, 'role' => $role, 'site' => home_url(),
            'mode' => $this->dry ? 'dry-run' : 'commit', 'overwrite_settings' => !empty($assoc['overwrite-settings']),
            'started_at' => gmdate('c', (int) $t0), 'finished_at' => gmdate('c'), 'seconds' => round(microtime(true) - $t0, 2),
            'source_dir' => $src, 'assets_dir' => $this->assets, 'files' => $this->files,
            'counts' => $count, 'errors' => $errors, 'new_records' => $this->dry ? null : $newRecords,
            'side_effects' => $side, 'notes' => $this->notes, 'records' => $this->records,
        ];
        $dir = (string) ($assoc['report-dir'] ?? '');
        if ($dir === '') { $cand = dirname($src) . '/shem-shop/docs/reports'; $dir = is_dir($cand) && is_writable($cand) ? $cand : wp_upload_dir()['basedir'] . '/scc-import-reports'; }
        if (!is_dir($dir)) wp_mkdir_p($dir);
        $base = $dir . '/import-' . $this->store . '-' . gmdate('Y-m-d') . ($this->dry ? '-dry-run' : '');
        $path = $base . '.json'; for ($n = 2; is_file($path); $n++) $path = $base . '-' . $n . '.json';
        $ok = file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        if ($ok === false) WP_CLI::warning('could not write the report to ' . $path);

        // ---- console summary
        WP_CLI::log('');
        foreach ($count as $type => $res) WP_CLI::log(sprintf('%-12s %s', $type, implode(', ', array_map(fn($k, $v) => "$k=$v", array_keys($res), $res))));
        foreach ($this->notes as $n) WP_CLI::log(strtoupper($n['level']) . ': ' . $n['message']);
        foreach ($this->records as $r) if (!empty($r['error'])) WP_CLI::log('ERROR ' . $r['type'] . ' ' . $r['source_id'] . ': ' . $r['error']);
        if ($side) WP_CLI::log('side effects: ' . ($side['ok'] ? 'none (mail attempts 0, protected tables unchanged)' : 'DETECTED ' . json_encode($side['unexpected_changes'])));
        WP_CLI::log("errors=$errors" . ($this->dry ? '' : " new_records=$newRecords") . " report=$path");
        if ($errors > 0) WP_CLI::halt(1);
        WP_CLI::success($this->dry ? 'Dry-run finished with zero errors. Nothing was written.' : 'Import finished with zero errors.');
    }
}

/** Namespace for `wp scc ...` (other agents may add more subcommands under it). */
final class Scc_Cli_Root {}
{
    $scc_path = ['scc'];
    if (!WP_CLI::get_root_command()->find_subcommand($scc_path)) WP_CLI::add_command('scc', 'Scc_Cli_Root', ['shortdesc' => 'SHEM-SHIMI commerce tools.']);
}
WP_CLI::add_command('scc import', 'Scc_Import_Command');
