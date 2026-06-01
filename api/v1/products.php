<?php
require_once __DIR__ . '/_common.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
  api_verify_token('products.view');
  api_ok(['products'=>api_v1_rows('SELECT * FROM products ORDER BY name')]);
}
if ($method === 'POST') {
  api_verify_token('products.import');
  $in = api_v1_input(); $items = $in['items'] ?? [$in]; $count=0;
  foreach ($items as $item) {
    if (!is_array($item)) continue;
    api_v1_upsert_by_name('products',$item,['name','price','category','category_id','image_path','is_favorite','is_best_seller','show_on_pos','track_stock','base_unit','updated_at']);
    $count++;
  }
  api_ok(['imported'=>$count]);
}
api_err('Method tidak diizinkan.',405);
