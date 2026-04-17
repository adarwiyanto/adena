<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/inventory.php';

function ensure_sales_revision_schema(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  $columns = [
    "ALTER TABLE sales ADD COLUMN base_sale_code VARCHAR(60) NULL AFTER transaction_code",
    "ALTER TABLE sales ADD COLUMN revision_suffix VARCHAR(10) NULL AFTER base_sale_code",
    "ALTER TABLE sales ADD COLUMN revision_no INT NOT NULL DEFAULT 0 AFTER revision_suffix",
    "ALTER TABLE sales ADD COLUMN is_active_revision TINYINT(1) NOT NULL DEFAULT 1 AFTER revision_no",
    "ALTER TABLE sales ADD COLUMN revised_from_sale_id INT NULL AFTER is_active_revision",
    "ALTER TABLE sales ADD COLUMN revision_reason_category VARCHAR(120) NULL AFTER revised_from_sale_id",
    "ALTER TABLE sales ADD COLUMN revision_reason_text TEXT NULL AFTER revision_reason_category",
    "ALTER TABLE sales ADD COLUMN revised_by_user_id INT NULL AFTER revision_reason_text",
    "ALTER TABLE sales ADD COLUMN revised_at DATETIME NULL AFTER revised_by_user_id",
    "ALTER TABLE sales ADD COLUMN revision_status VARCHAR(30) NOT NULL DEFAULT 'active' AFTER revised_at",
    "ALTER TABLE sales ADD COLUMN original_sale_id INT NULL AFTER revision_status",
    "ALTER TABLE sales ADD KEY idx_sales_revision_active (is_active_revision, sold_at)",
    "ALTER TABLE sales ADD KEY idx_sales_revision_base (base_sale_code, revision_no)",
  ];
  foreach ($columns as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) {}
  }

  try {
    db()->exec("UPDATE sales SET base_sale_code = COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-', id)) WHERE base_sale_code IS NULL OR base_sale_code=''");
    db()->exec("UPDATE sales SET revision_suffix = NULL WHERE revision_suffix=''");
    db()->exec("UPDATE sales SET revision_no = 0 WHERE revision_no IS NULL");
    db()->exec("UPDATE sales SET revision_status='active' WHERE revision_status IS NULL OR revision_status=''");
    db()->exec("UPDATE sales SET is_active_revision=1 WHERE is_active_revision IS NULL");
    db()->exec("UPDATE sales SET original_sale_id=id WHERE original_sale_id IS NULL");
  } catch (Throwable $e) {
  }
}

function revision_suffix_from_number(int $revisionNo): ?string {
  if ($revisionNo <= 0) return null;
  $alpha = '';
  while ($revisionNo > 0) {
    $revisionNo--;
    $alpha = chr(65 + ($revisionNo % 26)) . $alpha;
    $revisionNo = intdiv($revisionNo, 26);
  }
  return $alpha;
}

function sale_code_with_revision(string $baseCode, ?string $suffix): string {
  $suffix = trim((string)$suffix);
  return $suffix === '' ? $baseCode : $baseCode . $suffix;
}

function rollback_sale_stock(PDO $db, string $transactionCode, int $actorId): void {
  $stmt = $db->prepare("SELECT id, product_id, qty, branch_id, base_sale_code, revision_suffix FROM sales WHERE transaction_code=? AND is_active_revision=1");
  $stmt->execute([$transactionCode]);
  foreach ($stmt->fetchAll() as $row) {
    $branchId = (int)($row['branch_id'] ?? active_branch_id());
    add_stock_ledger([
      'branch_id' => $branchId,
      'product_id' => (int)$row['product_id'],
      'trans_type' => 'sale_revision_rollback',
      'ref_table' => 'sales',
      'ref_id' => (int)$row['id'],
      'qty_in' => (float)$row['qty'],
      'qty_out' => 0,
      'unit_cost' => null,
      'note' => 'Rollback revisi penjualan ' . sale_code_with_revision((string)$row['base_sale_code'], $row['revision_suffix']),
      'created_by' => $actorId,
    ]);
  }
}

function apply_sale_stock(PDO $db, string $transactionCode, int $actorId): void {
  $stmt = $db->prepare("SELECT id, product_id, qty, branch_id, base_sale_code, revision_suffix FROM sales WHERE transaction_code=? AND is_active_revision=1");
  $stmt->execute([$transactionCode]);
  foreach ($stmt->fetchAll() as $row) {
    $branchId = (int)($row['branch_id'] ?? active_branch_id());
    $stock = branch_stock($branchId, (int)$row['product_id']);
    if ($stock < (float)$row['qty']) {
      throw new Exception('Stok tidak cukup untuk menyimpan revisi transaksi.');
    }
    add_stock_ledger([
      'branch_id' => $branchId,
      'product_id' => (int)$row['product_id'],
      'trans_type' => 'sale_revision_apply',
      'ref_table' => 'sales',
      'ref_id' => (int)$row['id'],
      'qty_in' => 0,
      'qty_out' => (float)$row['qty'],
      'unit_cost' => null,
      'note' => 'Apply revisi penjualan ' . sale_code_with_revision((string)$row['base_sale_code'], $row['revision_suffix']),
      'created_by' => $actorId,
    ]);
  }
}

function revise_sale_transaction(array $payload, array $actor): string {
  $db = db();
  $db->beginTransaction();
  try {
    $saleCode = trim((string)($payload['sale_code'] ?? ''));
    if ($saleCode === '') throw new Exception('Transaksi tidak ditemukan.');

    $stmt = $db->prepare("SELECT * FROM sales WHERE transaction_code=? AND is_active_revision=1 ORDER BY id ASC FOR UPDATE");
    $stmt->execute([$saleCode]);
    $currentRows = $stmt->fetchAll();
    if (!$currentRows) {
      throw new Exception('Transaksi aktif tidak ditemukan.');
    }

    $baseCode = (string)($currentRows[0]['base_sale_code'] ?? '');
    if ($baseCode === '') $baseCode = $saleCode;
    $currentRevisionNo = (int)($currentRows[0]['revision_no'] ?? 0);
    $nextRevisionNo = $currentRevisionNo + 1;
    $nextSuffix = revision_suffix_from_number($nextRevisionNo);
    $nextCode = sale_code_with_revision($baseCode, $nextSuffix);

    $reasonCategory = trim((string)($payload['reason_category'] ?? ''));
    $reasonText = trim((string)($payload['reason_text'] ?? ''));
    $role = strtolower((string)($actor['role'] ?? ''));
    if ($role === 'admin') {
      if ($reasonCategory === '' || mb_strlen($reasonText) < 5) {
        throw new Exception('Kategori dan alasan revisi minimal 5 karakter wajib diisi untuk admin.');
      }
    }

    $firstOldSaleId = (int)$currentRows[0]['id'];
    $originalSaleId = (int)($currentRows[0]['original_sale_id'] ?? $firstOldSaleId);

    rollback_sale_stock($db, $saleCode, (int)$actor['id']);

    $db->prepare("UPDATE sales SET is_active_revision=0, revision_status='superseded', revised_by_user_id=?, revised_at=NOW() WHERE transaction_code=? AND is_active_revision=1")
      ->execute([(int)$actor['id'], $saleCode]);

    $newItems = $payload['items'] ?? [];
    if (!is_array($newItems) || empty($newItems)) {
      throw new Exception('Item transaksi wajib diisi.');
    }

    $insert = $db->prepare("INSERT INTO sales
      (transaction_code, base_sale_code, revision_suffix, revision_no, is_active_revision, revised_from_sale_id, revision_reason_category, revision_reason_text,
      revised_by_user_id, revised_at, revision_status, original_sale_id, product_id, qty, price_each, total, payment_method, payment_proof_path, created_by, sold_at, return_reason, returned_at, branch_id)
      VALUES (?,?,?,?,1,?,?,?,?,NOW(),'active',?,?,?,?,?,?,?,?,?,?,?,?,?)");

    foreach ($newItems as $item) {
      $pid = (int)($item['product_id'] ?? 0);
      $qty = (int)($item['qty'] ?? 0);
      $price = (float)($item['price_each'] ?? 0);
      if ($pid <= 0 || $qty <= 0) throw new Exception('Produk/qty tidak valid.');
      $insert->execute([
        $nextCode,
        $baseCode,
        $nextSuffix,
        $nextRevisionNo,
        $firstOldSaleId,
        $reasonCategory !== '' ? $reasonCategory : null,
        $reasonText !== '' ? $reasonText : null,
        (int)$actor['id'],
        $originalSaleId,
        $pid,
        $qty,
        $price,
        $price * $qty,
        (string)($payload['payment_method'] ?? ($currentRows[0]['payment_method'] ?? 'cash')),
        (string)($payload['payment_proof_path'] ?? ($currentRows[0]['payment_proof_path'] ?? null)),
        (int)($currentRows[0]['created_by'] ?? ($actor['id'] ?? 0)),
        (string)($payload['sold_at'] ?? ($currentRows[0]['sold_at'] ?? date('Y-m-d H:i:s'))),
        null,
        null,
        (int)($currentRows[0]['branch_id'] ?? active_branch_id()),
      ]);
    }

    apply_sale_stock($db, $nextCode, (int)$actor['id']);

    $db->commit();
    return $nextCode;
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }
}
