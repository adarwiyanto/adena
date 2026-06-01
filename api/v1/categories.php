<?php
require_once __DIR__ . '/_common.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
  api_verify_token('categories.view');
  api_ok(['categories'=>api_v1_rows('SELECT * FROM product_categories ORDER BY name')]);
}
if ($method === 'POST') {
  api_verify_token('categories.import');
  $in = api_v1_input(); $items = $in['items'] ?? [$in]; $count=0;
  foreach ($items as $item) { if (!is_array($item)) continue; api_v1_upsert_by_name('product_categories',$item,['name','image_path','sort_order','is_active']); $count++; }
  api_ok(['imported'=>$count]);
}
api_err('Method tidak diizinkan.',405);
