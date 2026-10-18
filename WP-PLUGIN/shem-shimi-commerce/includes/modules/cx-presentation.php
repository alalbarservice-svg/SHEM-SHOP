<?php
/**
 * Product presentation validator (images, size guide, colour swatches). Port of productPresentation() in SHEM/worker/customer-experience.js.
 * Called by the admin product-save handler (catalogue/admin module): $clean = Scc_Cx_Presentation::validate($body); product doc = [...$doc, ...$clean].
 * Throws Scc_Http_Exception (400, Hebrew) exactly as the original fail().
 */
if (!defined('ABSPATH')) exit;
if (SCC_ROLE !== 'shem') return;
require_once __DIR__ . '/cx-base.php';

final class Scc_Cx_Presentation {
    private static function asset(mixed $v): bool {
        if (!Scc_Cx::truthy($v)) return true;
        return is_string($v) && preg_match('#^/(assets|media)/[a-zA-Z0-9_./-]+$#', $v) === 1 && strpos($v, '..') === false;
    }
    private static function str(mixed $v): string {
        if (is_bool($v)) return $v ? 'true' : 'false';
        if ($v === null) return 'null';
        if (is_float($v) && floor($v) == $v && abs($v) < 1e15) return (string) (int) $v;
        return is_scalar($v) ? (string) $v : json_encode($v);
    }
    public static function validate(array $data): array {
        $fail = fn(string $m) => Scc_Cx::fail($m);
        $images = is_array($data['images'] ?? null) ? array_values($data['images']) : [];
        foreach ($images as $i) if (!is_array($i) || !self::asset($i['url'] ?? null) || !Scc_Cx::truthy($i['url'] ?? null)) $fail('ניתן להוסיף עד שלוש תמונות מוצר שהועלו לאתר.');
        if (count($images) > 3) $fail('ניתן להוסיף עד שלוש תמונות מוצר שהועלו לאתר.');

        $guide = is_array($data['sizeGuide'] ?? null) ? $data['sizeGuide'] : [];
        $columns = is_array($guide['columns'] ?? null) ? array_map(fn($c) => Scc_Cx::text($c, 60), array_values($guide['columns'])) : [];
        $rows = is_array($guide['rows'] ?? null) ? array_values($guide['rows']) : [];
        if (count($columns) > 6 || count($rows) > 30 || in_array('', $columns, true) || count(array_unique($columns)) !== count($columns)) $fail('טבלת המידות אינה תקינה.');
        if ($rows && !$columns) $fail('יש להוסיף לפחות עמודת מדידה אחת.');
        foreach ($rows as $r) {
            if (!is_array($r) || Scc_Cx::text($r['size'] ?? null, 20) === '' || !is_array($r['values'] ?? null) || !array_is_list($r['values']) || count($r['values']) !== count($columns))
                $fail('יש להזין מידה וערך לכל עמודה בטבלת המידות.');
        }
        $sizes = array_map(fn($r) => Scc_Cx::text($r['size'], 20), $rows);
        if (count(array_unique($sizes)) !== count($rows)) $fail('יש להסיר מידות כפולות בטבלה.');

        $colors = is_array($data['colors'] ?? null) ? array_values($data['colors']) : [];
        if (count($colors) > 1 && empty($data['variants'])) $fail('למוצר עם מספר צבעים יש להגדיר גרסאות מידה וצבע.');
        $badColors = count($colors) > 30;
        foreach ($colors as $c) {
            if (!is_array($c) || Scc_Cx::text($c['name'] ?? null, 50) === '' || !is_string($c['hex'] ?? null) || !preg_match('/^#[0-9a-f]{6}$/i', $c['hex']) || !self::asset($c['imageUrl'] ?? null)) { $badColors = true; break; }
        }
        if ($badColors) $fail('יש להזין שם צבע וקוד צבע תקין.');
        $names = array_map(fn($c) => Scc_Cx::text($c['name'], 50), $colors);
        if (count(array_unique($names)) !== count($colors)) $fail('יש להסיר צבעים כפולים.');

        return [
            'images' => array_map(fn($i) => ['url' => $i['url'], 'alt' => Scc_Cx::text($i['alt'] ?? null, 160)], $images),
            'sizeGuide' => [
                'columns' => $columns,
                'rows' => array_map(fn($r) => ['size' => Scc_Cx::text($r['size'], 20), 'values' => array_map(fn($v) => Scc_Cx::text(self::str($v), 60), $r['values'])], $rows),
                'instructions' => Scc_Cx::text($guide['instructions'] ?? null, 1500),
                'fit' => Scc_Cx::text($guide['fit'] ?? null, 500),
                'model' => Scc_Cx::text($guide['model'] ?? null, 500),
            ],
            'colors' => array_map(fn($c) => ['name' => Scc_Cx::text($c['name'], 50), 'hex' => $c['hex'], 'imageUrl' => Scc_Cx::truthy($c['imageUrl'] ?? null) ? $c['imageUrl'] : ''], $colors),
        ];
    }
}
