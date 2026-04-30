<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function ensure_pos_shift_schema(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS pos_shifts (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      shift_code VARCHAR(60) NOT NULL,
      branch_id INT NULL,
      opened_at DATETIME NOT NULL,
      opened_by INT NOT NULL,
      opening_cash_default DECIMAL(15,2) NOT NULL DEFAULT 0,
      opening_cash_actual DECIMAL(15,2) NOT NULL DEFAULT 0,
      status VARCHAR(20) NOT NULL DEFAULT 'open',
      closed_at DATETIME NULL,
      closed_by INT NULL,
      expected_cash_total DECIMAL(15,2) NULL,
      counted_cash_total DECIMAL(15,2) NULL,
      cash_difference DECIMAL(15,2) NULL,
      notes TEXT NULL,
      offline_open_uuid VARCHAR(80) NULL,
      offline_close_uuid VARCHAR(80) NULL,
      sync_status VARCHAR(20) NOT NULL DEFAULT 'synced',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_shift_code (shift_code),
      UNIQUE KEY uniq_shift_open_uuid (offline_open_uuid),
      UNIQUE KEY uniq_shift_close_uuid (offline_close_uuid),
      KEY idx_shift_status (status, opened_at),
      KEY idx_shift_branch_status (branch_id, status)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {}

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS pos_shift_users (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      shift_id BIGINT NOT NULL,
      user_id INT NOT NULL,
      activity_type VARCHAR(40) NOT NULL DEFAULT 'join',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_shift_user (shift_id, user_id),
      KEY idx_user (user_id),
      CONSTRAINT fk_pos_shift_users_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {}

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS pos_cash_movements (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      shift_id BIGINT NOT NULL,
      movement_type VARCHAR(10) NOT NULL,
      amount DECIMAL(15,2) NOT NULL,
      reason VARCHAR(255) NOT NULL,
      notes TEXT NULL,
      created_by INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      offline_uuid VARCHAR(80) NULL,
      sync_status VARCHAR(20) NOT NULL DEFAULT 'synced',
      KEY idx_shift_movement (shift_id, movement_type),
      UNIQUE KEY uniq_cash_offline_uuid (offline_uuid),
      CONSTRAINT fk_pos_cash_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {}

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS pos_sync_queue_log (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      entity_type VARCHAR(40) NOT NULL,
      offline_uuid VARCHAR(80) NOT NULL,
      payload_json LONGTEXT NULL,
      processed_at DATETIME NULL,
      user_id INT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'success',
      message VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_sync_offline_uuid (offline_uuid),
      KEY idx_sync_status (status, processed_at)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {}


  $addColumnIfMissing = function (string $table, string $column, string $definition): void {
    try {
      $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
      $stmt->execute([$column]);
      if (!$stmt->fetch()) db()->exec("ALTER TABLE `$table` ADD COLUMN $definition");
    } catch (Throwable $e) {}
  };

  $addColumnIfMissing('pos_shifts', 'branch_id', 'branch_id INT NULL AFTER shift_code');
  $addColumnIfMissing('pos_shifts', 'opening_cash_default', 'opening_cash_default DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER opened_by');
  $addColumnIfMissing('pos_shifts', 'opening_cash_actual', 'opening_cash_actual DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER opening_cash_default');
  $addColumnIfMissing('pos_shifts', 'expected_cash_total', 'expected_cash_total DECIMAL(15,2) NULL AFTER closed_by');
  $addColumnIfMissing('pos_shifts', 'counted_cash_total', 'counted_cash_total DECIMAL(15,2) NULL AFTER expected_cash_total');
  $addColumnIfMissing('pos_shifts', 'cash_difference', 'cash_difference DECIMAL(15,2) NULL AFTER counted_cash_total');
  $addColumnIfMissing('pos_shifts', 'notes', 'notes TEXT NULL AFTER cash_difference');
  $addColumnIfMissing('pos_shifts', 'offline_open_uuid', 'offline_open_uuid VARCHAR(80) NULL AFTER notes');
  $addColumnIfMissing('pos_shifts', 'offline_close_uuid', 'offline_close_uuid VARCHAR(80) NULL AFTER offline_open_uuid');
  $addColumnIfMissing('pos_shifts', 'sync_status', "sync_status VARCHAR(20) NOT NULL DEFAULT 'synced' AFTER offline_close_uuid");
  $addColumnIfMissing('pos_cash_movements', 'offline_uuid', 'offline_uuid VARCHAR(80) NULL AFTER created_at');
  $addColumnIfMissing('pos_cash_movements', 'sync_status', "sync_status VARCHAR(20) NOT NULL DEFAULT 'synced' AFTER offline_uuid");

  try {
    if (!(bool)db()->query("SHOW COLUMNS FROM sales LIKE 'shift_id'")->fetch()) {
      db()->exec("ALTER TABLE sales ADD COLUMN shift_id BIGINT NULL AFTER branch_id");
    }
  } catch (Throwable $e) {}

  try {
    if (!(bool)db()->query("SHOW COLUMNS FROM sales LIKE 'transaction_group_uuid'")->fetch()) {
      db()->exec("ALTER TABLE sales ADD COLUMN transaction_group_uuid VARCHAR(80) NULL AFTER transaction_code");
    }
  } catch (Throwable $e) {}

  try {
    if (!(bool)db()->query("SHOW COLUMNS FROM sales LIKE 'offline_uuid'")->fetch()) {
      db()->exec("ALTER TABLE sales ADD COLUMN offline_uuid VARCHAR(80) NULL AFTER transaction_group_uuid");
    }
  } catch (Throwable $e) {}

  try {
    if (!(bool)db()->query("SHOW COLUMNS FROM sales LIKE 'sync_status'")->fetch()) {
      db()->exec("ALTER TABLE sales ADD COLUMN sync_status VARCHAR(20) NOT NULL DEFAULT 'synced' AFTER offline_uuid");
    }
  } catch (Throwable $e) {}

  try { db()->exec("ALTER TABLE sales ADD KEY idx_sales_shift_id (shift_id)"); } catch (Throwable $e) {}
  try { db()->exec("ALTER TABLE sales ADD UNIQUE KEY uniq_sales_offline_uuid (offline_uuid)"); } catch (Throwable $e) {}
  try { db()->exec("ALTER TABLE sales ADD KEY idx_sales_tx_group (transaction_group_uuid)"); } catch (Throwable $e) {}

  try {
    if (setting('pos_default_opening_cash', null) === null) {
      set_setting('pos_default_opening_cash', '100000');
    }
  } catch (Throwable $e) {}
}

function pos_shift_generate_code(): string {
  return 'SHIFT-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
}

function pos_shift_get_active(?int $branchId = null): ?array {
  ensure_pos_shift_schema();
  $sql = "SELECT s.*, u.name AS opened_by_name, cu.name AS closed_by_name
          FROM pos_shifts s
          LEFT JOIN users u ON u.id=s.opened_by
          LEFT JOIN users cu ON cu.id=s.closed_by
          WHERE s.status='open'";
  $params = [];
  if ($branchId !== null) {
    $sql .= " AND (s.branch_id IS NULL OR s.branch_id=?)";
    $params[] = $branchId;
  }
  $sql .= " ORDER BY s.opened_at DESC LIMIT 1";
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $row = $stmt->fetch();
  return $row ?: null;
}

function pos_shift_log(int $shiftId, int $userId, string $activityType = 'use'): void {
  ensure_pos_shift_schema();
  $stmt = db()->prepare("INSERT INTO pos_shift_users (shift_id, user_id, activity_type) VALUES (?,?,?)");
  $stmt->execute([$shiftId, $userId, $activityType]);
}

function pos_shift_mark_user_activity(?array $shift, int $userId, string $activityType = 'use'): void {
  if (!$shift || $userId <= 0) return;
  try {
    pos_shift_log((int)$shift['id'], $userId, $activityType);
  } catch (Throwable $e) {}
}

function pos_shift_open(int $openedBy, float $openingCashActual, ?string $offlineUuid = null, ?int $branchId = null): array {
  ensure_pos_shift_schema();
  $db = db();
  $db->beginTransaction();
  try {
    $active = pos_shift_get_active($branchId);
    if ($active) throw new Exception('Masih ada shift yang belum ditutup: ' . ($active['shift_code'] ?? '')); 

    $defaultCash = (float)setting('pos_default_opening_cash', '0');
    $shiftCode = pos_shift_generate_code();
    $syncStatus = $offlineUuid ? 'pending' : 'synced';
    $stmt = $db->prepare("INSERT INTO pos_shifts (shift_code, branch_id, opened_at, opened_by, opening_cash_default, opening_cash_actual, status, offline_open_uuid, sync_status)
      VALUES (?,?,NOW(),?,?,?,?,?,?)");
    $stmt->execute([$shiftCode, $branchId, $openedBy, $defaultCash, $openingCashActual, 'open', $offlineUuid, $syncStatus]);
    $shiftId = (int)$db->lastInsertId();
    pos_shift_log($shiftId, $openedBy, 'open');
    $db->commit();
    return pos_shift_get_active($branchId) ?? [];
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }
}

function pos_shift_calculate_summary(int $shiftId): array {
  $db = db();
  $shiftStmt = $db->prepare("SELECT * FROM pos_shifts WHERE id=? LIMIT 1");
  $shiftStmt->execute([$shiftId]);
  $shift = $shiftStmt->fetch();
  if (!$shift) throw new Exception('Shift tidak ditemukan.');

  $cashSalesStmt = $db->prepare("SELECT COALESCE(SUM(total),0) AS amount FROM sales WHERE shift_id=? AND return_reason IS NULL AND payment_method='cash'");
  $cashSalesStmt->execute([$shiftId]);
  $cashSales = (float)($cashSalesStmt->fetch()['amount'] ?? 0);

  $nonCashStmt = $db->prepare("SELECT COALESCE(SUM(total),0) AS amount FROM sales WHERE shift_id=? AND return_reason IS NULL AND payment_method<>'cash'");
  $nonCashStmt->execute([$shiftId]);
  $nonCashSales = (float)($nonCashStmt->fetch()['amount'] ?? 0);

  $refundStmt = $db->prepare("SELECT COALESCE(SUM(total),0) AS amount FROM sales WHERE shift_id=? AND return_reason IS NOT NULL AND payment_method='cash'");
  $refundStmt->execute([$shiftId]);
  $cashRefund = (float)($refundStmt->fetch()['amount'] ?? 0);

  $cashMoveStmt = $db->prepare("SELECT movement_type, COALESCE(SUM(amount),0) AS amount FROM pos_cash_movements WHERE shift_id=? GROUP BY movement_type");
  $cashMoveStmt->execute([$shiftId]);
  $cashIn = 0.0;
  $cashOut = 0.0;
  foreach ($cashMoveStmt->fetchAll() as $row) {
    if (($row['movement_type'] ?? '') === 'in') $cashIn = (float)$row['amount'];
    if (($row['movement_type'] ?? '') === 'out') $cashOut = (float)$row['amount'];
  }

  $openingCash = (float)($shift['opening_cash_actual'] ?? 0);
  $expectedCash = $openingCash + $cashSales - $cashRefund + $cashIn - $cashOut;

  return [
    'shift' => $shift,
    'opening_cash' => $openingCash,
    'cash_sales' => $cashSales,
    'cash_refund' => $cashRefund,
    'cash_cancel' => 0.0,
    'cash_in' => $cashIn,
    'cash_out' => $cashOut,
    'non_cash_sales' => $nonCashSales,
    'expected_cash' => $expectedCash,
  ];
}

function pos_shift_close(int $shiftId, int $closedBy, float $countedCash, string $notes = '', ?string $offlineUuid = null, string $syncStatus = 'synced'): array {
  ensure_pos_shift_schema();
  $db = db();
  $db->beginTransaction();
  try {
    $summary = pos_shift_calculate_summary($shiftId);
    $shift = $summary['shift'];
    if (($shift['status'] ?? '') !== 'open') throw new Exception('Shift sudah ditutup.');

    $expected = (float)$summary['expected_cash'];
    $difference = $countedCash - $expected;
    $stmt = $db->prepare("UPDATE pos_shifts SET status='closed', closed_at=NOW(), closed_by=?, expected_cash_total=?, counted_cash_total=?, cash_difference=?, notes=?, offline_close_uuid=?, sync_status=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([$closedBy, $expected, $countedCash, $difference, $notes, $offlineUuid, $syncStatus, $shiftId]);
    pos_shift_log($shiftId, $closedBy, 'close');
    $db->commit();
    return pos_shift_calculate_summary($shiftId);
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }
}

function pos_cash_movement_record(int $shiftId, int $createdBy, string $movementType, float $amount, string $reason, string $notes = '', ?string $offlineUuid = null): array {
  ensure_pos_shift_schema();
  if (!in_array($movementType, ['in', 'out'], true)) throw new Exception('Jenis kas tidak valid.');
  if ($amount <= 0) throw new Exception('Nominal harus lebih dari 0.');
  if (trim($reason) === '') throw new Exception('Alasan wajib diisi.');

  $syncStatus = $offlineUuid ? 'pending' : 'synced';
  $stmt = db()->prepare("INSERT INTO pos_cash_movements (shift_id, movement_type, amount, reason, notes, created_by, offline_uuid, sync_status) VALUES (?,?,?,?,?,?,?,?)");
  $stmt->execute([$shiftId, $movementType, $amount, $reason, $notes, $createdBy, $offlineUuid, $syncStatus]);
  pos_shift_log($shiftId, $createdBy, $movementType === 'in' ? 'cash_in' : 'cash_out');

  return [
    'id' => (int)db()->lastInsertId(),
    'shift_id' => $shiftId,
    'movement_type' => $movementType,
    'amount' => $amount,
    'reason' => $reason,
    'notes' => $notes,
  ];
}

function pos_attach_sale_shift(string $transactionCode, int $shiftId, ?string $transactionGroupUuid = null, ?string $offlineUuid = null, string $syncStatus = 'synced'): void {
  ensure_pos_shift_schema();
  $stmt = db()->prepare("UPDATE sales SET shift_id=?, transaction_group_uuid=COALESCE(?, transaction_group_uuid), offline_uuid=COALESCE(?, offline_uuid), sync_status=? WHERE transaction_code=?");
  $stmt->execute([$shiftId, $transactionGroupUuid, $offlineUuid, $syncStatus, $transactionCode]);
}

function pos_sync_log(string $entityType, string $offlineUuid, array $payload, string $status, string $message = '', ?int $userId = null): void {
  ensure_pos_shift_schema();
  $stmt = db()->prepare("INSERT INTO pos_sync_queue_log (entity_type, offline_uuid, payload_json, processed_at, user_id, status, message)
      VALUES (?,?,?,NOW(),?,?,?)
      ON DUPLICATE KEY UPDATE processed_at=VALUES(processed_at), user_id=VALUES(user_id), status=VALUES(status), message=VALUES(message)");
  $stmt->execute([$entityType, $offlineUuid, json_encode($payload), $userId, $status, $message]);
}

function pos_sync_is_duplicate(string $offlineUuid): bool {
  if ($offlineUuid === '') return false;
  $stmt = db()->prepare("SELECT id FROM pos_sync_queue_log WHERE offline_uuid=? LIMIT 1");
  $stmt->execute([$offlineUuid]);
  return (bool)$stmt->fetch();
}

function pos_shift_audit_feed(int $shiftId): array {
  $stmt = db()->prepare("SELECT su.*, u.name AS user_name FROM pos_shift_users su LEFT JOIN users u ON u.id=su.user_id WHERE su.shift_id=? ORDER BY su.created_at ASC, su.id ASC");
  $stmt->execute([$shiftId]);
  return $stmt->fetchAll();
}
