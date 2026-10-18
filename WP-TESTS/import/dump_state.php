<?php
/** Read-only dump of imported catalogue state (Woo + scc_products) as JSON, for verify_import.py. Writes nothing. */
global $wpdb;
$out = ['site' => defined('SCC_ROLE') ? SCC_ROLE : '?', 'products' => [], 'woo_total' => 0, 'skus' => [], 'legacy_ids' => [], 'scc_ids' => []];
$ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status<>'auto-draft'");
$out['woo_total'] = count($ids);
foreach ($ids as $pid) {
  $legacy = get_post_meta($pid, '_scc_legacy_id', true);
  $sku = get_post_meta($pid, '_sku', true);
  if ($sku !== '') $out['skus'][] = $sku;
  if ($legacy === '') continue;
  $out['legacy_ids'][] = $legacy;
  $p = wc_get_product($pid);
  $vars = [];
  foreach ($wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='product_variation' AND post_parent=%d AND post_status<>'trash'", $pid)) as $vid) {
    $v = wc_get_product($vid);
    $vars[] = ['id' => get_post_meta($vid, '_scc_legacy_variant_id', true), 'sku' => $v->get_sku(), 'size' => get_post_meta($vid, '_scc_size', true), 'color' => get_post_meta($vid, '_scc_color', true),
      'stock' => $v->get_stock_quantity(), 'price' => $v->get_price(), 'regular' => $v->get_regular_price(), 'sale' => $v->get_sale_price(), 'status' => get_post_status($vid)];
  }
  $thumb = (int) get_post_thumbnail_id($pid); $file = $thumb ? get_attached_file($thumb) : '';
  $out['products'][$legacy] = ['woo_id' => $pid, 'status' => get_post_status($pid), 'title' => get_the_title($pid), 'sku' => $sku, 'price' => $p->get_price(), 'content' => get_post_field('post_content', $pid),
    'cats' => wp_get_post_terms($pid, 'product_cat', ['fields' => 'names']), 'brand' => get_post_meta($pid, '_scc_brand', true), 'color' => get_post_meta($pid, '_scc_color', true),
    'sizes' => json_decode(get_post_meta($pid, '_scc_sizes', true) ?: '[]', true), 'prev' => get_post_meta($pid, '_scc_previous_price', true), 'variations' => $vars,
    'thumb_id' => $thumb, 'thumb_sha' => ($file && is_readable($file)) ? hash_file('sha256', $file) : null, 'doc' => json_decode(get_post_meta($pid, '_scc_doc', true), true)];
}
foreach ($wpdb->get_results("SELECT id, data FROM {$wpdb->prefix}scc_products") as $r) { $out['scc_ids'][] = $r->id; $out['scc_docs'][$r->id] = json_decode($r->data, true); }
echo "@@" . wp_json_encode($out) . "@@\n";
