<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function dapur_table_exists(string $table): bool {
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

function dapur_column_exists(string $table, string $column): bool {
  try {
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
  } catch (Throwable $e) { return false; }
}

function dapur_try_exec(string $sql): void { try { db()->exec($sql); } catch (Throwable $e) {} }

function ensure_dapur_stock_transfer_schema(): void {
  $pdo = db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS dapur_stock_transfers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    transfer_no VARCHAR(80) NOT NULL,
    direction VARCHAR(20) NOT NULL DEFAULT 'in',
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
    unit_cost DECIMAL(18,2) NULL,
    notes TEXT NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'admin',
    api_token_id INT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dapur_stock_transfer_no (transfer_no),
    KEY idx_dapur_stock_transfer_created (created_at),
    KEY idx_dapur_stock_transfer_product (product_id),
    KEY idx_dapur_stock_transfer_branch (branch_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  if (dapur_table_exists('stock_ledger')) {
    if (!dapur_column_exists('stock_ledger', 'location_id')) dapur_try_exec("ALTER TABLE stock_ledger ADD COLUMN location_id INT NULL AFTER branch_id");
  }
}

function dapur_active_branch_id(): int {
  $id = function_exists('active_branch_id') ? (int)active_branch_id() : 0;
  if ($id <= 0 && function_exists('adena_single_branch_id')) $id = (int)adena_single_branch_id();
  if ($id <= 0) $id = (int)setting('active_branch_id', '1');
  return $id > 0 ? $id : 1;
}

function dapur_current_stock(int $branchId, int $productId): float {
  try {
    $st = db()->prepare('SELECT COALESCE(SUM(qty_in - qty_out),0) FROM stock_ledger WHERE branch_id=? AND product_id=?');
    $st->execute([$branchId, $productId]);
    return (float)$st->fetchColumn();
  } catch (Throwable $e) { return 0.0; }
}

function dapur_generate_transfer_no(string $direction): string {
  $prefix = $direction === 'return' ? 'DRT' : 'DTF';
  return $prefix . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function dapur_create_stock_transfer(array $data): array {
  ensure_dapur_stock_transfer_schema();
  $productId = (int)($data['product_id'] ?? 0);
  $qty = (float)($data['qty'] ?? 0);
  $direction = strtolower(trim((string)($data['direction'] ?? 'in')));
  if (!in_array($direction, ['in','return'], true)) $direction = 'in';
  $branchId = (int)($data['branch_id'] ?? 0);
  if ($branchId <= 0) $branchId = dapur_active_branch_id();
  $notes = trim((string)($data['notes'] ?? ''));
  $source = trim((string)($data['source'] ?? 'admin')) ?: 'admin';
  $apiTokenId = isset($data['api_token_id']) ? (int)$data['api_token_id'] : null;
  $createdBy = isset($data['created_by']) ? (int)$data['created_by'] : null;
  $unitCost = isset($data['unit_cost']) && $data['unit_cost'] !== '' ? (float)$data['unit_cost'] : null;
  $transferNo = strtoupper(trim((string)($data['transfer_no'] ?? '')));
  if ($transferNo === '') $transferNo = dapur_generate_transfer_no($direction);

  if ($productId <= 0) throw new RuntimeException('Produk wajib dipilih.');
  if ($qty <= 0) throw new RuntimeException('Qty harus lebih dari 0.');

  $st = db()->prepare('SELECT id, name, base_unit, track_stock FROM products WHERE id=? LIMIT 1');
  $st->execute([$productId]);
  $product = $st->fetch(PDO::FETCH_ASSOC);
  if (!$product) throw new RuntimeException('Produk tidak ditemukan.');
  if ((int)($product['track_stock'] ?? 1) !== 1) throw new RuntimeException('Produk ini tidak memakai tracking stok.');

  if ($direction === 'return') {
    $stock = dapur_current_stock($branchId, $productId);
    if ($stock + 0.0001 < $qty) throw new RuntimeException('Stok tidak cukup untuk pengembalian ke dapur. Stok saat ini: ' . format_number_id($stock));
  }

  $pdo = db();
  $pdo->beginTransaction();
  try {
    $check = $pdo->prepare('SELECT id FROM dapur_stock_transfers WHERE transfer_no=? LIMIT 1');
    $check->execute([$transferNo]);
    $existingId = (int)($check->fetchColumn() ?: 0);
    if ($existingId > 0) {
      $pdo->commit();
      return ['id'=>$existingId,'transfer_no'=>$transferNo,'duplicate'=>true];
    }

    $ins = $pdo->prepare('INSERT INTO dapur_stock_transfers (transfer_no,direction,branch_id,product_id,qty,unit_cost,notes,source,api_token_id,created_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
    $ins->execute([$transferNo,$direction,$branchId,$productId,$qty,$unitCost,$notes,$source,$apiTokenId,$createdBy]);
    $id = (int)$pdo->lastInsertId();

    $qtyIn = $direction === 'in' ? $qty : 0;
    $qtyOut = $direction === 'return' ? $qty : 0;
    $transType = $direction === 'return' ? 'dapur_return_out' : 'dapur_transfer_in';
    $ledgerNote = ($direction === 'return' ? 'Pengembalian stok ke dapur' : 'Transfer stok dari dapur') . ($notes !== '' ? ' - ' . $notes : '');
    $ledger = $pdo->prepare('INSERT INTO stock_ledger (branch_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,unit_cost,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $ledger->execute([$branchId,$productId,$transType,'dapur_stock_transfers',$id,$qtyIn,$qtyOut,$unitCost,$ledgerNote,$createdBy]);

    $pdo->commit();
    return ['id'=>$id,'transfer_no'=>$transferNo,'duplicate'=>false];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
