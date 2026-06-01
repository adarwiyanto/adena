<?php
require_once __DIR__ . '/../../core/api_web_products.php';
require_web_product_api_token('products.read');
web_product_api_ok([
  'service' => 'Adena API Antar Website',
  'module' => 'products',
  'time' => date('Y-m-d H:i:s'),
]);
