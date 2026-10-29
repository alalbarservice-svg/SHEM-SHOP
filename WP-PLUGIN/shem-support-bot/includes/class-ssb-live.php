<?php
if (!defined('ABSPATH')) exit;
/**
 * Live store data (read-only): products, stock, variations, brands, orders.
 * Nothing here writes. Order data is returned only after verification (order number + email or phone of THAT order).
 */
final class Ssb_Live {

    /** @return string[] distinct brand names of active products */
    public static function brands(): array {
        $b = [];
        foreach (self::products() as $p) { $n = trim((string) ($p['brand'] ?? '')); if ($n !== '') $b[$n] = 1; }
        $k = array_keys($b); sort($k); return $k;
    }
    private static ?array $cache = null;
    public static function flush(): void { self::$cache = null; }
    /**
     * Products the customer can actually see in the store: active, non-demo, AND linked to a published WooCommerce product
     * whose catalogue visibility is not "hidden". (Fixtures that only exist as documents are never offered.)
     */
    public static function products(): array {
        if (self::$cache !== null) return self::$cache;
        if (!class_exists('Scc_Docs')) return [];
        $out = [];
        foreach (Scc_Docs::all('products') as $id => $p) {
            if (isset($p['active']) && !$p['active']) continue;
            if (!empty($p['demo'])) continue;
            if (!self::displayed((string) ($p['id'] ?? $id))) continue;
            $out[$id] = $p;
        }
        return self::$cache = $out;
    }
    private static function displayed(string $docId): bool {
        if (!class_exists('Scc_Core') || !function_exists('wc_get_product')) return true; // no commerce layer to ask: fall back to the documents
        $pid = (int) Scc_Core::find_product_id($docId);
        if (!$pid) return false;
        $wc = wc_get_product($pid);
        return $wc && $wc->get_status() === 'publish' && $wc->get_catalog_visibility() !== 'hidden';
    }
    /** English colour words stored on some products -> the Hebrew name customers use. */
    const COLOR_EN = ['black' => 'שחור', 'white' => 'לבן', 'ivory' => 'שנהב', 'navy' => 'נייבי', 'blue' => 'כחול', 'grey' => 'אפור', 'gray' => 'אפור', 'beige' => 'בז', 'green' => 'ירוק', 'brown' => 'חום', 'red' => 'אדום', 'olive' => 'זית', 'cream' => 'קרם', 'sand' => 'חול', 'taupe' => 'טאופ'];
    public static function color_he(string $c): string {
        $k = strtolower(trim($c));
        return self::COLOR_EN[$k] ?? trim($c);
    }
    /** Hebrew / English spellings customers use for the brands. Extra aliases can be added in the brain (section product, meta {"brand":"X"}, keywords = aliases). */
    const BRAND_ALIASES = [
        'Loro Piana' => ['לורו פיאנה', 'לורו פיאנו', 'לורו', 'loro piana', 'loro'],
        'Ralph Lauren' => ['ראלף לורן', 'ראלף לורין', 'ראלף', 'ralph lauren', 'ralph'],
        'Amiri' => ['אמירי', 'amiri'],
        'Givenchy' => ['גיבנשי', 'ג׳יבנשי', "ג'יבנשי", 'givenchy'],
        'Dior' => ['דיור', 'dior'],
        'SHEM' => ['shem'],
    ];
    const COLOR_ROOTS = ['שחור', 'לבן', 'כחול', 'נייבי', 'תכלת', 'אפור', 'ירוק', 'חום', 'בז', 'אדום', 'בורדו', 'ורוד', 'צהוב', 'כתום', 'סגול', 'חול', 'קרם', 'זית', 'קאמל', 'שמנת', 'טורקיז', 'כסף', 'זהב', 'שנהב', 'טאופ', 'אבן', 'אינדיגו'];

    private static function aliases(): array {
        $out = self::BRAND_ALIASES;
        foreach (self::brands() as $b) $out[$b] = array_merge($out[$b] ?? [], [$b]);
        if (class_exists('Ssb_Brain')) foreach (Ssb_Brain::all('product', true) as $r) {
            $m = Ssb_Brain::meta($r); $br = trim((string) ($m['brand'] ?? ''));
            if ($br === '') continue;
            foreach (preg_split('/[\r\n,]+/u', (string) $r['keywords']) as $k) if (trim($k) !== '') $out[$br][] = trim($k);
        }
        return $out;
    }
    /** Whole-word alias hit, tolerant of one Hebrew prefix letter (בראלף, למאנגו, מאמירי). $tf is ' ' . folded text . ' '. */
    private static function alias_hit(string $tf, string $af): bool {
        if (mb_strpos($tf, ' ' . $af . ' ') !== false) return true;
        foreach (['ב', 'ל', 'מ', 'ש', 'ו', 'ה', 'כ'] as $p) if (mb_strpos($tf, ' ' . $p . $af . ' ') !== false) return true;
        return false;
    }
    /** Brand mentioned in the text (only brands that exist in the live catalogue). */
    public static function brand_in_text(string $text): ?string {
        $tf = ' ' . Ssb_Text::fold($text) . ' ';
        $live = self::brands();
        $best = null; $bl = 0;
        foreach (self::aliases() as $brand => $list) {
            if (!in_array($brand, $live, true)) continue;
            foreach ($list as $a) {
                $af = Ssb_Text::fold($a);
                if ($af !== '' && self::alias_hit($tf, $af) && mb_strlen($af) > $bl) { $best = $brand; $bl = mb_strlen($af); }
            }
        }
        return $best;
    }
    private static function strip_aliases(string $folded): string {
        $t = ' ' . $folded . ' ';
        $all = [];
        foreach (self::aliases() as $list) foreach ($list as $a) { $af = Ssb_Text::fold($a); if ($af !== '') $all[] = $af; }
        usort($all, fn($x, $y) => mb_strlen($y) <=> mb_strlen($x));
        foreach ($all as $af) { $t = str_replace(' ' . $af . ' ', ' ', $t); foreach (['ב', 'ל', 'מ', 'ש', 'ו', 'ה', 'כ'] as $p) $t = str_replace(' ' . $p . $af . ' ', ' ', $t); }
        return trim($t);
    }
    /** Normalised colour root of one folded word, or null. Handles gender / plural endings and one-letter prefixes (בשחור). */
    private static function color_root(string $w): ?string {
        static $roots = null;
        if ($roots === null) { $roots = []; foreach (self::COLOR_ROOTS as $r) $roots[Ssb_Text::fold($r)] = 1; }
        $try = function (string $x) use ($roots): ?string {
            if (isset($roots[$x])) return $x;
            foreach (['ימ', 'ות', 'ה'] as $suf) if (mb_strlen($x) > 3 && mb_substr($x, -mb_strlen($suf)) === $suf) { $y = mb_substr($x, 0, -mb_strlen($suf)); if (isset($roots[$y])) return $y; }
            return null;
        };
        if ($r = $try($w)) return $r;
        if (mb_strlen($w) > 3 && mb_strpos('בלהוכמש', mb_substr($w, 0, 1)) !== false && ($r = $try(mb_substr($w, 1)))) return $r;
        return null;
    }
    /** @return array<string,string> root => the word as the customer typed it */
    public static function color_roots(string $text): array {
        $out = [];
        foreach (explode(' ', Ssb_Text::fold($text)) as $w) if ($w !== '' && ($r = self::color_root($w))) $out[$r] = $w;
        return $out;
    }
    private static function product_color_roots(array $p): array {
        $out = [];
        foreach (self::colors($p) as $c) foreach (explode(' ', Ssb_Text::fold(self::color_he($c))) as $w) if ($w !== '' && ($r = self::color_root($w))) $out[$r] = 1;
        return array_keys($out);
    }
    /**
     * Products matching the customer's words. Brand is a filter, colour is checked explicitly (never through stemming).
     * @return array{brand:?string, colors:array<string,string>, by_name:array[], by_color:array[]}
     */
    public static function match_products(string $text, bool $loose = false): array {
        $brand = self::brand_in_text($text);
        $cr = self::color_roots($text);
        $rest = [];
        $filler = ['צבע', 'בצבע', 'צבעים', 'מוצר', 'מוצרים', 'פריט', 'פריטים', 'דגם', 'דגמים'];
        foreach (explode(' ', self::strip_aliases(Ssb_Text::fold($text))) as $w) if ($w !== '' && !self::color_root($w) && !in_array($w, $filler, true)) $rest[] = $w;
        $q = Ssb_Text::stems(implode(' ', $rest));
        $min = ($brand || $cr || $loose) ? 1 : 2;
        $scored = [];
        if ($q) foreach (self::products() as $p) {
            if ($brand && ($p['brand'] ?? '') !== $brand) continue;
            $hay = Ssb_Text::stems(($p['he'] ?? '') . ' ' . ($p['en'] ?? ''));
            $hits = count(array_intersect($q, $hay));
            if ($hits >= $min) $scored[] = [$hits, $p];
        }
        if (!$q && $cr) foreach (self::products() as $p) { if ($brand && ($p['brand'] ?? '') !== $brand) continue; $scored[] = [0, $p]; } // colour (+ brand) only: every product of that brand
        usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
        $byName = array_map(fn($x) => $x[1], $scored);
        $byColor = $cr ? array_values(array_filter($byName, fn($p) => array_intersect(array_keys($cr), self::product_color_roots($p)))) : $byName;
        return ['brand' => $brand, 'colors' => $cr, 'by_name' => $byName, 'by_color' => $byColor];
    }
    public static function find_products(string $text, int $limit = 4): array { return array_slice(self::match_products($text)['by_color'], 0, $limit); }
    /** Individual colour names for display: compound values ("שנהב וטאופ") split, English folded to Hebrew, duplicates removed. */
    public static function color_names(array $p): array {
        $out = [];
        foreach (self::colors($p) as $c) foreach (preg_split('/\s*[,\/]\s*|\s+ו(?=\S)/u', $c) ?: [] as $x) { $x = self::color_he(trim($x)); if ($x !== '') $out[$x] = 1; }
        return array_keys($out);
    }
    /** Colours (Hebrew free text as stored). */
    public static function colors(array $p): array {
        $c = [];
        if (!empty($p['color'])) $c[] = trim((string) $p['color']);
        foreach ((array) ($p['colors'] ?? []) as $x) { $x = is_array($x) ? ($x['name'] ?? $x['he'] ?? '') : $x; if (trim((string) $x) !== '') $c[] = trim((string) $x); }
        foreach ((array) ($p['variants'] ?? []) as $v) if (!empty($v['color'])) $c[] = trim((string) $v['color']);
        return array_values(array_unique(array_filter($c)));
    }
    /**
     * Stock per size. Live WooCommerce variation stock when the product is linked, else the store document.
     * @return array{sizes: array<string,int>, source: string}
     */
    public static function stock(array $p): array {
        $sizes = []; $src = 'doc';
        $id = (string) ($p['id'] ?? '');
        if ($id !== '' && class_exists('Scc_Core') && function_exists('wc_get_product')) {
            $pid = (int) Scc_Core::find_product_id($id);
            if ($pid) {
                $vm = method_exists('Scc_Core', 'variation_map') ? (array) Scc_Core::variation_map($pid) : [];
                $by = [];
                foreach ((array) ($p['variants'] ?? []) as $v) {
                    $vid = $vm[$v['id'] ?? ''] ?? 0; if (!$vid) continue;
                    $vp = wc_get_product($vid); if (!$vp) continue;
                    $q = $vp->managing_stock() ? (int) $vp->get_stock_quantity() : ($vp->is_in_stock() ? 1 : 0);
                    $key = (string) ($v['size'] ?? ''); if ($key === '') continue;
                    $by[$key] = ($by[$key] ?? 0) + max(0, $q);
                }
                if ($by) { $sizes = $by; $src = 'woocommerce'; }
            }
        }
        if (!$sizes && !empty($p['sizeStock']) && is_array($p['sizeStock'])) foreach ($p['sizeStock'] as $s => $q) $sizes[(string) $s] = max(0, (int) $q);
        if (!$sizes && !empty($p['sizes'])) foreach ((array) $p['sizes'] as $s) $sizes[(string) $s] = max(0, (int) ($p['stock'] ?? 0));
        return ['sizes' => $sizes, 'source' => $src];
    }
    /** Plain-text size guide of a product document, or '' when none was entered. */
    public static function size_guide_text(array $p): string {
        $g = $p['sizeGuide'] ?? null; if (!is_array($g)) return '';
        $lines = [];
        $cols = (array) ($g['columns'] ?? []); $rows = (array) ($g['rows'] ?? []);
        if ($cols && $rows) {
            $lines[] = implode(' | ', array_map('strval', $cols));
            foreach ($rows as $r) $lines[] = implode(' | ', array_map(fn($x) => is_scalar($x) ? (string) $x : '', array_values((array) $r)));
        }
        foreach (['instructions' => 'הוראות מדידה', 'fit' => 'גזרה', 'model' => 'הדגמנית/הדגמן'] as $k => $lab) {
            $v = trim((string) ($g[$k] ?? '')); if ($v !== '') $lines[] = $lab . ': ' . $v;
        }
        return implode("\n", $lines);
    }

    /* ------------------------------------------------------------------ orders */
    /**
     * Verified order lookup. Order number + (email OR phone) must belong to the SAME order. Anything else = failure,
     * and the failure never says which part was wrong.
     * @return array{ok:bool, order?:array}
     */
    public static function order_lookup(string $number, string $email, string $phone): array {
        if (!function_exists('wc_get_order') || !preg_match('/^\d{1,10}$/', $number) || ($email === '' && $phone === '')) return ['ok' => false];
        $o = wc_get_order((int) $number);
        if (!$o || $o->get_type() !== 'shop_order') return ['ok' => false];
        $emails = array_filter([strtolower((string) $o->get_billing_email()), strtolower((string) $o->get_meta('_scc_legacy_email'))]);
        $phones = array_filter([Ssb_Text::phone_key((string) $o->get_billing_phone())]);
        $raw = $o->get_meta('_scc_legacy_order');
        if (is_string($raw) && $raw !== '' && class_exists('Scc_Core_Orders')) {
            $doc = Scc_Core_Orders::to_doc($o);
            if ($doc) {
                if (!empty($doc['customer']['email'])) $emails[] = strtolower((string) $doc['customer']['email']);
                if (!empty($doc['customer']['phone'])) $phones[] = Ssb_Text::phone_key((string) $doc['customer']['phone']);
            }
        }
        $okE = $email !== '' && in_array(strtolower($email), $emails, true);
        $okP = $phone !== '' && $phone !== '' && in_array($phone, array_filter($phones), true);
        if (!$okE && !$okP) return ['ok' => false];
        $items = [];
        foreach ($o->get_items() as $it) {
            $items[] = trim($it->get_name() . ' ' . ($it->get_meta('מידה') ? '(מידה ' . $it->get_meta('מידה') . ')' : '') . ' x' . (int) $it->get_quantity());
        }
        $dt = $o->get_date_created();
        return ['ok' => true, 'order' => [
            'id' => $o->get_id(),
            'status' => 'wc-' . $o->get_status(),
            'date' => $dt ? $dt->date_i18n('d/m/Y') : '',
            'items' => $items,
            'total' => wp_strip_all_tags(html_entity_decode(wc_price($o->get_total()))),
            'shipping' => $o->get_shipping_method(),
        ]];
    }
}
