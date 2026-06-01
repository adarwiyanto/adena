<?php
require_once __DIR__ . '/../../core/api_web_products.php';
require_web_product_api_token('products.read');
require_once __DIR__ . '/../../core/inventory.php';
ensure_inventory_module_schema();
ensure_products_category_column();
ensure_products_best_seller_column();

$updatedAfter = trim((string)($_GET['updated_after'] ?? ''));
$where = '';
$params = [];
if ($updatedAfter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $updatedAfter)) {
  $where = 'WHERE updated_at IS NULL OR updated_at >= ?';
  $params[] = $updatedAfter;
}
$sql = "SELECT id, name, category, price, image_path, is_favorite, is_best_seller,
               product_type, track_stock, allow_direct_purchase, allow_bom,
               show_on_pos, show_on_landing, base_unit, purchase_unit, purchase_to_base_factor,
               sale_unit, sale_to_base_factor, created_at, updated_at
        FROM products {$where}
        ORDER BY id ASC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$data = [];
foreach ($rows as $p) {
  $imageUrl = null;
  if (!empty($p['image_path'])) {
    $imageUrl = base_url('api/web/product-image.php?id=' . (int)$p['id']);
  }
  $item = [
    'remote_id' => (int)$p['id'],
    'name' => (string)$p['name'],
    'category' => (string)($p['category'] ?? ''),
    'price' => (float)$p['price'],
    'product_type' => (string)($p['product_type'] ?? 'finished_good'),
    'track_stock' => (int)($p['track_stock'] ?? 1),
    'allow_direct_purchase' => (int)($p['allow_direct_purchase'] ?? 0),
    'allow_bom' => (int)($p['allow_bom'] ?? 0),
    'show_on_pos' => (int)($p['show_on_pos'] ?? 1),
    'show_on_landing' => (int)($p['show_on_landing'] ?? 1),
    'base_unit' => (string)($p['base_unit'] ?? 'pcs'),
    'purchase_unit' => (string)($p['purchase_unit'] ?? ($p['base_unit'] ?? 'pcs')),
    'purchase_to_base_factor' => (float)($p['purchase_to_base_factor'] ?? 1),
    'sale_unit' => (string)($p['sale_unit'] ?? ($p['base_unit'] ?? 'pcs')),
    'sale_to_base_factor' => (float)($p['sale_to_base_factor'] ?? 1),
    'image_url' => $imageUrl,
    'updated_at' => (string)($p['updated_at'] ?? $p['created_at'] ?? ''),
  ];
  $item['hash'] = web_product_remote_hash($item);
  $data[] = $item;
}
web_product_api_ok(['data' => $data, 'count' => count($data)]);
