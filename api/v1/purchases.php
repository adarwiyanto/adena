<?php
require_once __DIR__ . '/_common.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
  $token = api_verify_token('purchases.view');
  [$branchSql,$params] = api_v1_filter_branch_sql($token);
  $headers = api_v1_table_exists('purchase_headers') ? api_v1_rows('SELECT * FROM purchase_headers WHERE 1=1'.$branchSql.' ORDER BY purchase_date DESC, id DESC LIMIT 1000',$params) : [];
  $items = api_v1_table_exists('purchase_items') ? api_v1_rows('SELECT * FROM purchase_items ORDER BY id DESC LIMIT 5000') : [];
  api_ok(['purchase_headers'=>$headers,'purchase_items'=>$items]);
}
if ($method === 'POST') {
  api_verify_token('purchases.push');
  api_ok(['message'=>'Endpoint pembelian siap. Gunakan skema purchase_headers/purchase_items sesuai database aktif.']);
}
api_err('Method tidak diizinkan.',405);
