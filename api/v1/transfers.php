<?php
require_once __DIR__ . '/_common.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
  api_verify_token('transfers.view');
  $headers = api_v1_table_exists('stock_transfers') ? api_v1_rows('SELECT * FROM stock_transfers ORDER BY id DESC LIMIT 1000') : [];
  $items = api_v1_table_exists('stock_transfer_items') ? api_v1_rows('SELECT * FROM stock_transfer_items ORDER BY id DESC LIMIT 5000') : [];
  api_ok(['stock_transfers'=>$headers,'stock_transfer_items'=>$items]);
}
if ($method === 'POST') {
  $in = api_v1_input(); $action = strtolower((string)($in['action'] ?? 'create'));
  $permission = $action === 'receive' ? 'transfers.receive' : ($action === 'cancel' ? 'transfers.cancel' : 'transfers.create');
  api_verify_token($permission);
  api_ok(['message'=>'Endpoint transfer stok aktif.', 'action'=>$action, 'received'=>$in]);
}
api_err('Method tidak diizinkan.',405);
