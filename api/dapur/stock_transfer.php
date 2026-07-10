<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../../core/dapur_stock_transfer.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  api_err('Method tidak diizinkan', 405);
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) $payload = $_POST ?: [];

$direction = strtolower(trim((string)($payload['direction'] ?? 'in')));
$permission = $direction === 'return' ? 'stock_return' : 'stock_transfer';
$token = require_api_token($permission);

try {
  $result = dapur_create_stock_transfer([
    'transfer_no' => (string)($payload['transfer_no'] ?? ''),
    'product_id' => (int)($payload['product_id'] ?? 0),
    'qty' => (float)($payload['qty'] ?? 0),
    'direction' => $direction,
    'notes' => (string)($payload['notes'] ?? ''),
    'unit_cost' => $payload['unit_cost'] ?? null,
    'source' => 'api',
    'api_token_id' => (int)($token['id'] ?? 0),
    'branch_id' => (int)($token['branch_id'] ?? 0),
  ]);
  api_ok([
    'message' => !empty($result['duplicate']) ? 'Transfer sudah pernah tercatat' : 'Transfer stok berhasil disimpan',
    'transfer_id' => (int)$result['id'],
    'transfer_no' => (string)$result['transfer_no'],
    'duplicate' => !empty($result['duplicate']),
  ]);
} catch (Throwable $e) {
  api_err($e->getMessage(), 400);
}
