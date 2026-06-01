<?php
require_once __DIR__ . '/_common.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
  api_verify_token('stocks.view');
  $locations = api_v1_table_exists('stock_locations') ? api_v1_rows('SELECT * FROM stock_locations ORDER BY id') : [];
  $ledger = api_v1_table_exists('stock_ledger') ? api_v1_rows('SELECT * FROM stock_ledger ORDER BY id DESC LIMIT 5000') : [];
  api_ok(['stock_locations'=>$locations,'stock_ledger'=>$ledger]);
}
if ($method === 'POST') {
  api_verify_token('stocks.adjust');
  $in = api_v1_input();
  api_ok(['message'=>'Endpoint adjustment stok aktif. Simpan adjustment mengikuti tabel stock_ledger/stock_opname pada database aktif.', 'received'=>$in]);
}
api_err('Method tidak diizinkan.',405);
