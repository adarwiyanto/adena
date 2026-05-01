<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/inventory.php';

function ensure_branch_portal_schema(): void {
  ensure_inventory_module_schema();
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS user_branches (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      branch_id INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_user_branch (user_id, branch_id),
      KEY idx_user_branches_user (user_id),
      KEY idx_user_branches_branch (branch_id)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {}

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS branch_stock_inputs (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      input_no VARCHAR(80) NOT NULL,
      branch_id INT NOT NULL,
      product_id INT NOT NULL,
      qty DECIMAL(18,4) NOT NULL,
      unit_cost DECIMAL(18,2) NULL,
      notes TEXT NULL,
      status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
      created_by INT NOT NULL,
      approved_by INT NULL,
      approved_at TIMESTAMP NULL DEFAULT NULL,
      approval_note TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_branch_stock_input_no (input_no),
      KEY idx_branch_stock_inputs_branch_status (branch_id,status,created_at),
      KEY idx_branch_stock_inputs_product (product_id),
      KEY idx_branch_stock_inputs_created_by (created_by),
      KEY idx_branch_stock_inputs_approved_by (approved_by)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {}
}

function branch_portal_current_user(): array {
  start_secure_session();
  require_admin();
  ensure_branch_portal_schema();
  return current_user() ?? [];
}

function branch_portal_is_owner(array $u): bool {
  $resolved = resolve_user_role($u);
  return (string)($resolved['role_key'] ?? '') === 'owner';
}

function branch_portal_all_branches(bool $activeOnly = true): array {
  ensure_branch_portal_schema();
  $sql = "SELECT id, branch_code, branch_name, is_active FROM branches";
  if ($activeOnly) $sql .= " WHERE is_active=1";
  $sql .= " ORDER BY branch_name ASC";
  return db()->query($sql)->fetchAll();
}

function branch_portal_user_branch_ids(array $u): array {
  ensure_branch_portal_schema();
  if (branch_portal_is_owner($u)) {
    $rows = branch_portal_all_branches(true);
    return array_map(static fn($r) => (int)$r['id'], $rows);
  }
  $uid = (int)($u['id'] ?? 0);
  if ($uid <= 0) return [];
  try {
    $stmt = db()->prepare("SELECT branch_id FROM user_branches WHERE user_id=?");
    $stmt->execute([$uid]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'branch_id'));
    if (!empty($ids)) return $ids;
  } catch (Throwable $e) {}
  return [max(1, (int)setting('active_branch_id', '1'))];
}

function branch_portal_allowed_branches(array $u): array {
  $ids = branch_portal_user_branch_ids($u);
  if (empty($ids)) return [];
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $stmt = db()->prepare("SELECT id, branch_code, branch_name, is_active FROM branches WHERE is_active=1 AND id IN ($ph) ORDER BY branch_name ASC");
  $stmt->execute($ids);
  return $stmt->fetchAll();
}

function branch_portal_active_branch_id(array $u): int {
  start_secure_session();
  $allowed = branch_portal_user_branch_ids($u);
  $active = (int)($_SESSION['active_branch_id'] ?? 0);
  if ($active > 0 && in_array($active, $allowed, true)) return $active;
  $fallback = (int)($allowed[0] ?? 0);
  if ($fallback > 0) {
    $_SESSION['active_branch_id'] = $fallback;
    return $fallback;
  }
  return 1;
}

function branch_portal_set_active_branch(array $u, int $branchId): void {
  $allowed = branch_portal_user_branch_ids($u);
  if (!in_array($branchId, $allowed, true)) {
    throw new Exception('User tidak memiliki akses ke cabang tersebut.');
  }
  $stmt = db()->prepare("SELECT id FROM branches WHERE id=? AND is_active=1 LIMIT 1");
  $stmt->execute([$branchId]);
  if (!$stmt->fetch()) throw new Exception('Cabang tidak aktif atau tidak ditemukan.');
  $_SESSION['active_branch_id'] = $branchId;
}

function branch_portal_branch(int $branchId): ?array {
  $stmt = db()->prepare("SELECT * FROM branches WHERE id=? LIMIT 1");
  $stmt->execute([$branchId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function branch_portal_products(string $search = ''): array {
  $params = [];
  $sql = "SELECT id, name, category, product_type, track_stock, base_unit, purchase_unit, purchase_to_base_factor, sale_unit, sale_to_base_factor
          FROM products
          WHERE track_stock=1 AND product_type IN ('raw_material','finished_good')";
  if ($search !== '') {
    $sql .= " AND (name LIKE ? OR COALESCE(category,'') LIKE ? OR CAST(id AS CHAR) LIKE ?)";
    $term = '%' . $search . '%';
    $params = [$term, $term, $term];
  }
  $sql .= " ORDER BY name ASC LIMIT 300";
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll();
}

function branch_portal_generate_input_no(PDO $db): string {
  $prefix = 'BSI-' . date('Ymd-His');
  for ($i=0; $i<10; $i++) {
    $no = $prefix . '-' . strtoupper(bin2hex(random_bytes(2)));
    $stmt = $db->prepare("SELECT id FROM branch_stock_inputs WHERE input_no=? LIMIT 1");
    $stmt->execute([$no]);
    if (!$stmt->fetch()) return $no;
  }
  return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function branch_portal_create_stock_input(int $branchId, int $productId, float $qty, ?float $unitCost, string $notes, int $userId): int {
  if ($qty <= 0) throw new Exception('Jumlah stok masuk wajib lebih dari 0.');
  $db = db();
  $no = branch_portal_generate_input_no($db);
  $stmt = $db->prepare("INSERT INTO branch_stock_inputs (input_no,branch_id,product_id,qty,unit_cost,notes,status,created_by)
    VALUES (?,?,?,?,?,?, 'pending', ?)");
  $stmt->execute([$no, $branchId, $productId, $qty, $unitCost, $notes !== '' ? $notes : null, $userId]);
  return (int)$db->lastInsertId();
}

function branch_portal_approve_stock_input(int $id, int $userId, string $note = ''): void {
  $db = db();
  $db->beginTransaction();
  try {
    $stmt = $db->prepare("SELECT * FROM branch_stock_inputs WHERE id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('Dokumen stok masuk tidak ditemukan.');
    if (($row['status'] ?? '') !== 'pending') throw new Exception('Hanya stok masuk pending yang bisa disetujui.');
    add_stock_ledger([
      'branch_id' => (int)$row['branch_id'],
      'product_id' => (int)$row['product_id'],
      'trans_type' => 'branch_stock_input',
      'ref_table' => 'branch_stock_inputs',
      'ref_id' => (int)$row['id'],
      'qty_in' => (float)$row['qty'],
      'qty_out' => 0,
      'unit_cost' => $row['unit_cost'] !== null ? (float)$row['unit_cost'] : null,
      'note' => 'Stok masuk cabang ' . (string)$row['input_no'],
      'created_by' => $userId,
    ]);
    $stmt = $db->prepare("UPDATE branch_stock_inputs SET status='approved', approved_by=?, approved_at=NOW(), approval_note=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([$userId, $note !== '' ? $note : null, $id]);
    $db->commit();
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }
}

function branch_portal_reject_stock_input(int $id, int $userId, string $note): void {
  if (trim($note) === '') throw new Exception('Catatan penolakan wajib diisi.');
  $stmt = db()->prepare("UPDATE branch_stock_inputs SET status='rejected', approved_by=?, approved_at=NOW(), approval_note=?, updated_at=NOW() WHERE id=? AND status='pending'");
  $stmt->execute([$userId, $note, $id]);
  if ($stmt->rowCount() <= 0) throw new Exception('Dokumen tidak bisa ditolak.');
}

function branch_portal_create_blind_opname(int $branchId, array $items, string $notes, int $userId): int {
  if (empty($items)) throw new Exception('Minimal satu produk wajib diisi.');
  $db = db();
  $db->beginTransaction();
  try {
    $opnameNo = generate_stock_opname_no($db);
    $stmt = $db->prepare("INSERT INTO stock_opname_headers (opname_no,branch_id,opname_date,status,notes,created_by) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$opnameNo, $branchId, date('Y-m-d'), 'waiting_approval', $notes !== '' ? $notes : 'Input blind opname dari halaman cabang', $userId]);
    $opnameId = (int)$db->lastInsertId();
    $itemStmt = $db->prepare("INSERT INTO stock_opname_items
      (opname_id,product_id,system_qty,physical_qty,variance_qty,variance_type,reason_note,line_note,warning_flag)
      VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($items as $it) {
      $productId = (int)($it['product_id'] ?? 0);
      $physical = (float)($it['physical_qty'] ?? 0);
      $lineNote = trim((string)($it['line_note'] ?? ''));
      if ($productId <= 0) continue;
      if ($physical < 0) throw new Exception('Stok fisik tidak boleh negatif.');
      $systemQty = branch_stock($branchId, $productId);
      $variance = round($physical - $systemQty, 4);
      $type = 'zero';
      if ($variance > 0) $type = 'plus';
      if ($variance < 0) $type = 'minus';
      $reason = abs($variance) > 0.00001 ? ($lineNote !== '' ? $lineNote : 'Input fisik dari cabang') : null;
      $itemStmt->execute([$opnameId, $productId, $systemQty, $physical, $variance, $type, $reason, $lineNote !== '' ? $lineNote : null, stock_variance_needs_warning($variance) ? 1 : 0]);
    }
    $db->commit();
    return $opnameId;
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }
}
