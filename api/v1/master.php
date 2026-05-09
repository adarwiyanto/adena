<?php
require_once __DIR__ . '/_common.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') api_err('Method tidak diizinkan.', 405);
$token = api_verify_token('master.view');
$pdo = db();
$out = [];
foreach ([
  'branches' => "SELECT id, branch_code, branch_name, unit_type, is_kitchen, is_active FROM branches ORDER BY sort_order, id",
  'payment_methods' => "SELECT * FROM payment_methods ORDER BY sort_order, id",
  'qris_banks' => "SELECT * FROM qris_banks ORDER BY sort_order, id",
  'guides' => "SELECT id, name, is_active FROM guides ORDER BY name",
  'branch_product_prices' => "SELECT * FROM branch_product_prices ORDER BY branch_id, product_id",
] as $key => $sql) {
  $table = $key === 'qris_banks' ? 'qris_banks' : $key;
  $out[$key] = api_v1_table_exists($table) ? api_v1_rows($sql) : [];
}
api_ok(['token'=>$token, 'data'=>$out]);
