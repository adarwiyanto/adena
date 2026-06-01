<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (!function_exists('adena_stu_table_columns')) {
  function adena_stu_table_columns(string $table): array {
    try {
      $stmt = db()->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
      $stmt->execute([$table]);
      return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) { return []; }
  }
}
if (!function_exists('adena_stu_has_column')) {
  function adena_stu_has_column(string $table, string $column): bool {
    return in_array($column, adena_stu_table_columns($table), true);
  }
}
if (!function_exists('adena_stu_exec_safe')) {
  function adena_stu_exec_safe(string $sql): void {
    try { db()->exec($sql); } catch (Throwable $e) {}
  }
}
if (!function_exists('adena_stu_ensure_schema')) {
  function adena_stu_ensure_schema(): void {
    static $done = false; if ($done) return; $done = true;
    adena_stu_exec_safe("CREATE TABLE IF NOT EXISTS stock_locations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      location_code VARCHAR(40) NOT NULL,
      location_name VARCHAR(160) NOT NULL,
      location_type ENUM('kitchen','store','branch') NOT NULL DEFAULT 'branch',
      branch_id INT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_stock_locations_code (location_code),
      KEY idx_stock_locations_type (location_type,is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach([
      "ALTER TABLE stock_locations ADD COLUMN location_code VARCHAR(40) NOT NULL AFTER id",
      "ALTER TABLE stock_locations ADD COLUMN location_name VARCHAR(160) NOT NULL AFTER location_code",
      "ALTER TABLE stock_locations ADD COLUMN location_type ENUM('kitchen','store','branch') NOT NULL DEFAULT 'branch' AFTER location_name",
      "ALTER TABLE stock_locations ADD COLUMN branch_id INT NULL AFTER location_type",
      "ALTER TABLE stock_locations ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER branch_id",
      "ALTER TABLE stock_locations ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER is_active",
      "ALTER TABLE stock_locations ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
      "ALTER TABLE stock_locations ADD UNIQUE KEY uniq_stock_locations_code (location_code)",
      "ALTER TABLE stock_locations ADD KEY idx_stock_locations_type (location_type,is_active)"
    ] as $sql) adena_stu_exec_safe($sql);

    adena_stu_exec_safe("CREATE TABLE IF NOT EXISTS stock_transfers (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      transfer_no VARCHAR(60) NOT NULL,
      from_location_id INT NOT NULL,
      to_location_id INT NOT NULL,
      status ENUM('draft','sent','accepted','rejected','cancelled') NOT NULL DEFAULT 'draft',
      transfer_type VARCHAR(30) NOT NULL DEFAULT 'stock_transfer',
      sent_at TIMESTAMP NULL DEFAULT NULL,
      accepted_at TIMESTAMP NULL DEFAULT NULL,
      rejected_at TIMESTAMP NULL DEFAULT NULL,
      cancelled_at TIMESTAMP NULL DEFAULT NULL,
      created_by INT NULL,
      sent_by INT NULL,
      received_by INT NULL,
      notes TEXT NULL,
      receiver_notes TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_stock_transfer_no (transfer_no),
      KEY idx_transfer_from_to_status (from_location_id,to_location_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach([
      "ALTER TABLE stock_transfers ADD COLUMN transfer_no VARCHAR(60) NOT NULL AFTER id",
      "ALTER TABLE stock_transfers ADD COLUMN from_location_id INT NOT NULL AFTER transfer_no",
      "ALTER TABLE stock_transfers ADD COLUMN to_location_id INT NOT NULL AFTER from_location_id",
      "ALTER TABLE stock_transfers ADD COLUMN status ENUM('draft','sent','accepted','rejected','cancelled') NOT NULL DEFAULT 'draft' AFTER to_location_id",
      "ALTER TABLE stock_transfers ADD COLUMN transfer_type VARCHAR(30) NOT NULL DEFAULT 'stock_transfer' AFTER status",
      "ALTER TABLE stock_transfers ADD COLUMN sent_at TIMESTAMP NULL DEFAULT NULL AFTER transfer_type",
      "ALTER TABLE stock_transfers ADD COLUMN accepted_at TIMESTAMP NULL DEFAULT NULL AFTER sent_at",
      "ALTER TABLE stock_transfers ADD COLUMN rejected_at TIMESTAMP NULL DEFAULT NULL AFTER accepted_at",
      "ALTER TABLE stock_transfers ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER rejected_at",
      "ALTER TABLE stock_transfers ADD COLUMN created_by INT NULL AFTER cancelled_at",
      "ALTER TABLE stock_transfers ADD COLUMN sent_by INT NULL AFTER created_by",
      "ALTER TABLE stock_transfers ADD COLUMN received_by INT NULL AFTER sent_by",
      "ALTER TABLE stock_transfers ADD COLUMN notes TEXT NULL AFTER received_by",
      "ALTER TABLE stock_transfers ADD COLUMN receiver_notes TEXT NULL AFTER notes",
      "ALTER TABLE stock_transfers ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER receiver_notes",
      "ALTER TABLE stock_transfers ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
      "ALTER TABLE stock_transfers ADD UNIQUE KEY uniq_stock_transfer_no (transfer_no)",
      "ALTER TABLE stock_transfers ADD KEY idx_transfer_from_to_status (from_location_id,to_location_id,status)"
    ] as $sql) adena_stu_exec_safe($sql);

    adena_stu_exec_safe("CREATE TABLE IF NOT EXISTS stock_transfer_items (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      transfer_id BIGINT NOT NULL,
      product_id INT NOT NULL,
      qty DECIMAL(18,4) NOT NULL DEFAULT 0,
      unit_cost DECIMAL(18,2) NULL,
      note VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_transfer_items_transfer (transfer_id),
      KEY idx_transfer_items_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach([
      "ALTER TABLE stock_transfer_items ADD COLUMN transfer_id BIGINT NOT NULL AFTER id",
      "ALTER TABLE stock_transfer_items ADD COLUMN product_id INT NOT NULL AFTER transfer_id",
      "ALTER TABLE stock_transfer_items ADD COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER product_id",
      "ALTER TABLE stock_transfer_items ADD COLUMN unit_cost DECIMAL(18,2) NULL AFTER qty",
      "ALTER TABLE stock_transfer_items ADD COLUMN note VARCHAR(255) NULL AFTER unit_cost",
      "ALTER TABLE stock_transfer_items ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER note",
      "ALTER TABLE stock_transfer_items ADD KEY idx_transfer_items_transfer (transfer_id)",
      "ALTER TABLE stock_transfer_items ADD KEY idx_transfer_items_product (product_id)"
    ] as $sql) adena_stu_exec_safe($sql);

    adena_stu_exec_safe("ALTER TABLE stock_ledger ADD COLUMN location_id INT NULL AFTER branch_id");
    adena_stu_exec_safe("ALTER TABLE stock_ledger ADD KEY idx_stock_ledger_location (location_id,product_id,created_at)");

    try {
      $db = db();
      $db->exec("INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
        SELECT 'KITCHEN','Dapur Produksi','kitchen',NULL,1 FROM DUAL
        WHERE NOT EXISTS (SELECT 1 FROM stock_locations WHERE location_code='KITCHEN')");
      $db->exec("INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
        SELECT CONCAT('TOKO-', b.branch_code), b.branch_name, IF(COALESCE(b.is_kitchen,0)=1 OR b.unit_type='dapur','kitchen','branch'), b.id, 1
        FROM branches b
        WHERE NOT EXISTS (SELECT 1 FROM stock_locations sl WHERE sl.branch_id=b.id)");
    } catch (Throwable $e) {}
  }
}
if (!function_exists('adena_stu_schema_ready')) {
  function adena_stu_schema_ready(?string &$reason = null): bool {
    adena_stu_ensure_schema();
    $must = [
      'stock_locations' => ['id','location_code','location_name','location_type','branch_id','is_active'],
      'stock_transfers' => ['id','transfer_no','from_location_id','to_location_id','status','sent_at','accepted_at','rejected_at','created_by','sent_by','received_by','notes','receiver_notes'],
      'stock_transfer_items' => ['id','transfer_id','product_id','qty','note'],
      'stock_ledger' => ['branch_id','location_id','product_id','trans_type','ref_table','ref_id','qty_in','qty_out','note']
    ];
    foreach ($must as $table => $cols) {
      $have = array_flip(adena_stu_table_columns($table));
      if (!$have) { $reason = "Tabel {$table} belum ada."; return false; }
      foreach ($cols as $c) if (!isset($have[$c])) { $reason = "Kolom {$table}.{$c} belum ada."; return false; }
    }
    return true;
  }
}
if (!function_exists('adena_stu_locations')) {
  function adena_stu_locations(): array {
    adena_stu_ensure_schema();
    try { return db()->query("SELECT * FROM stock_locations WHERE is_active=1 ORDER BY FIELD(location_type,'kitchen','store','branch'), location_name")->fetchAll(PDO::FETCH_ASSOC) ?: []; }
    catch (Throwable $e) { return []; }
  }
}
if (!function_exists('adena_stu_products')) {
  function adena_stu_products(): array {
    try {
      $where = [];
      if (adena_stu_has_column('products','track_stock')) $where[] = "track_stock=1";
      if (adena_stu_has_column('products','is_active')) $where[] = "is_active=1";
      $sql = "SELECT id,name FROM products" . ($where ? " WHERE ".implode(' AND ', $where) : "") . " ORDER BY name ASC";
      return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
  }
}
if (!function_exists('adena_stu_location_branch_id')) {
  function adena_stu_location_branch_id(array $loc): int {
    $bid = (int)($loc['branch_id'] ?? 0);
    return $bid > 0 ? $bid : (int)(function_exists('active_branch_id') ? active_branch_id() : 1);
  }
}
if (!function_exists('adena_stu_collect_items')) {
  function adena_stu_collect_items(array $src): array {
    $pids = $src['item_product_id'] ?? [];
    $qtys = $src['item_qty'] ?? [];
    $notes = $src['item_notes'] ?? [];
    if (!is_array($pids)) $pids = [];
    if (!is_array($qtys)) $qtys = [];
    if (!is_array($notes)) $notes = [];
    $items = []; $max = max(count($pids), count($qtys), count($notes));
    for ($i=0; $i<$max; $i++) {
      $pid = (int)($pids[$i] ?? 0);
      $qty = function_exists('parse_number_input') ? parse_number_input($qtys[$i] ?? 0) : (float)str_replace(',', '.', (string)($qtys[$i] ?? 0));
      $note = trim((string)($notes[$i] ?? ''));
      if ($pid<=0 && $qty<=0 && $note==='') continue;
      if ($pid<=0 || $qty<=0) throw new Exception('Produk dan qty transfer wajib valid.');
      $items[] = ['product_id'=>$pid, 'qty'=>$qty, 'note'=>$note];
    }
    if (!$items) throw new Exception('Minimal 1 item transfer wajib diisi.');
    return $items;
  }
}
if (!function_exists('adena_stu_insert_ledger')) {
  function adena_stu_insert_ledger(array $row): void {
    $hasUnitCost = adena_stu_has_column('stock_ledger','unit_cost');
    if ($hasUnitCost) {
      $stmt = db()->prepare("INSERT INTO stock_ledger (branch_id,location_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,unit_cost,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([(int)$row['branch_id'], $row['location_id'] !== null ? (int)$row['location_id'] : null, (int)$row['product_id'], (string)$row['trans_type'], (string)$row['ref_table'], (int)$row['ref_id'], (float)($row['qty_in'] ?? 0), (float)($row['qty_out'] ?? 0), $row['unit_cost'] ?? null, $row['note'] ?? null, $row['created_by'] ?? null]);
    } else {
      $stmt = db()->prepare("INSERT INTO stock_ledger (branch_id,location_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,note,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([(int)$row['branch_id'], $row['location_id'] !== null ? (int)$row['location_id'] : null, (int)$row['product_id'], (string)$row['trans_type'], (string)$row['ref_table'], (int)$row['ref_id'], (float)($row['qty_in'] ?? 0), (float)($row['qty_out'] ?? 0), $row['note'] ?? null, $row['created_by'] ?? null]);
    }
  }
}
