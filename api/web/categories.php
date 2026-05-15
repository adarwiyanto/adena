<?php
require_once __DIR__ . '/../../core/api_web_products.php';
require_web_product_api_token('categories.read');
ensure_product_categories_table();
$rows = db()->query('SELECT id, name, created_at FROM product_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
web_product_api_ok(['data' => $rows, 'count' => count($rows)]);
