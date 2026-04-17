<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function ensure_inventory_module_schema(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  ensure_branches_table();
  ensure_products_inventory_columns();
  ensure_suppliers_table();
  ensure_purchase_tables();
  ensure_purchase_revision_audit_table();
  ensure_bom_tables();
  ensure_production_tables();
  ensure_stock_ledger_table();
  ensure_products_reorder_level_column();
  ensure_stock_opname_tables();
  ensure_sales_inventory_columns();
  ensure_sales_production_links_table();
  ensure_inventory_settings_defaults();
}

function ensure_branches_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS branches (
      id INT AUTO_INCREMENT PRIMARY KEY,
      branch_code VARCHAR(40) NOT NULL,
      branch_name VARCHAR(120) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_branch_code (branch_code)
    ) ENGINE=InnoDB");
    db()->exec("INSERT INTO branches (branch_code, branch_name, is_active)
      SELECT 'MAIN','Cabang Utama',1 FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM branches LIMIT 1)");
  } catch (Throwable $e) {
    // no-op
  }
}

function ensure_products_inventory_columns(): void {
  $defs = [
    "ALTER TABLE products ADD COLUMN product_type ENUM('finished_good','raw_material','service') NOT NULL DEFAULT 'finished_good' AFTER price",
    "ALTER TABLE products ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER product_type",
    "ALTER TABLE products ADD COLUMN allow_direct_purchase TINYINT(1) NOT NULL DEFAULT 0 AFTER track_stock",
    "ALTER TABLE products ADD COLUMN allow_bom TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_direct_purchase",
    "ALTER TABLE products ADD COLUMN show_on_pos TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_bom",
    "ALTER TABLE products ADD COLUMN show_on_landing TINYINT(1) NOT NULL DEFAULT 1 AFTER show_on_pos",
    "ALTER TABLE products ADD KEY idx_product_type (product_type)",
  ];
  foreach ($defs as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) { /* no-op */ }
  }
  $unitDefs = [
    "ALTER TABLE products ADD COLUMN base_unit VARCHAR(50) NULL AFTER allow_bom",
    "ALTER TABLE products ADD COLUMN purchase_unit VARCHAR(50) NULL AFTER base_unit",
    "ALTER TABLE products ADD COLUMN purchase_to_base_factor DECIMAL(18,6) NOT NULL DEFAULT 1.000000 AFTER purchase_unit",
    "ALTER TABLE products ADD COLUMN sale_unit VARCHAR(50) NULL AFTER purchase_to_base_factor",
    "ALTER TABLE products ADD COLUMN sale_to_base_factor DECIMAL(18,6) NOT NULL DEFAULT 1.000000 AFTER sale_unit",
  ];
  foreach ($unitDefs as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) { /* no-op */ }
  }
  try {
    db()->exec("UPDATE products SET base_unit='pcs' WHERE (base_unit IS NULL OR TRIM(base_unit)='') AND product_type='raw_material'");
    db()->exec("UPDATE products SET purchase_unit=base_unit WHERE purchase_unit IS NULL OR TRIM(purchase_unit)=''");
    db()->exec("UPDATE products SET sale_unit=base_unit WHERE sale_unit IS NULL OR TRIM(sale_unit)=''");
    db()->exec("UPDATE products SET purchase_to_base_factor=1.000000 WHERE purchase_to_base_factor<=0 OR purchase_to_base_factor IS NULL");
    db()->exec("UPDATE products SET sale_to_base_factor=1.000000 WHERE sale_to_base_factor<=0 OR sale_to_base_factor IS NULL");
    db()->exec("UPDATE products SET show_on_pos=0, show_on_landing=0 WHERE product_type='raw_material' AND show_on_pos=1 AND show_on_landing=1");
  } catch (Throwable $e) {
  }
}


function ensure_suppliers_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS suppliers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      supplier_code VARCHAR(40) NOT NULL,
      supplier_name VARCHAR(160) NOT NULL,
      phone VARCHAR(40) NULL,
      email VARCHAR(190) NULL,
      address TEXT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_supplier_code (supplier_code)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_purchase_tables(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS purchase_headers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      branch_id INT NOT NULL,
      supplier_id INT NOT NULL,
      purchase_no VARCHAR(50) NOT NULL,
      purchase_date DATE NOT NULL,
      status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
      subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
      discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      grand_total DECIMAL(18,2) NOT NULL DEFAULT 0,
      notes TEXT NULL,
      created_by INT NULL,
      posted_by INT NULL,
      posted_at TIMESTAMP NULL DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_purchase_no (purchase_no),
      KEY idx_purchase_branch_status_date (branch_id,status,purchase_date),
      KEY idx_purchase_supplier (supplier_id)
    ) ENGINE=InnoDB");

    db()->exec("CREATE TABLE IF NOT EXISTS purchase_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      purchase_id INT NOT NULL,
      product_id INT NOT NULL,
      qty DECIMAL(18,4) NOT NULL,
      unit_cost DECIMAL(18,2) NOT NULL,
      line_total DECIMAL(18,2) NOT NULL,
      notes VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_purchase_items_purchase (purchase_id),
      KEY idx_purchase_items_product (product_id),
      CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchase_headers(id) ON DELETE CASCADE,
      CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_purchase_revision_audit_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS purchase_revision_audit (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      purchase_id INT NOT NULL,
      purchase_no VARCHAR(50) NOT NULL,
      edited_by INT NULL,
      edited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      edit_reason TEXT NULL,
      snapshot_before LONGTEXT NULL,
      snapshot_after LONGTEXT NULL,
      change_summary LONGTEXT NULL,
      KEY idx_purchase_revision_purchase (purchase_id, edited_at),
      KEY idx_purchase_revision_no (purchase_no)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_bom_tables(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS bom_headers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      branch_id INT NULL,
      finished_product_id INT NOT NULL,
      bom_code VARCHAR(50) NOT NULL,
      bom_name VARCHAR(160) NOT NULL,
      yield_qty DECIMAL(18,4) NOT NULL DEFAULT 1,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_bom_code (bom_code),
      KEY idx_bom_finished_active (finished_product_id,is_active)
    ) ENGINE=InnoDB");

    db()->exec("CREATE TABLE IF NOT EXISTS bom_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      bom_id INT NOT NULL,
      material_product_id INT NOT NULL,
      qty_per_yield DECIMAL(18,4) NOT NULL,
      unit_note VARCHAR(120) NULL,
      wastage_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_bom_material (bom_id, material_product_id),
      KEY idx_bom_items_lookup (bom_id,material_product_id),
      CONSTRAINT fk_bom_items_header FOREIGN KEY (bom_id) REFERENCES bom_headers(id) ON DELETE CASCADE,
      CONSTRAINT fk_bom_items_material FOREIGN KEY (material_product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_production_tables(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS production_headers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      production_no VARCHAR(50) NOT NULL,
      branch_id INT NOT NULL,
      bom_id INT NOT NULL,
      finished_product_id INT NOT NULL,
      production_date DATE NOT NULL,
      qty_to_produce DECIMAL(18,4) NOT NULL,
      status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
      mode_source ENUM('manual_menu','pos_auto') NOT NULL DEFAULT 'manual_menu',
      notes TEXT NULL,
      created_by INT NULL,
      posted_by INT NULL,
      posted_at TIMESTAMP NULL DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_production_no (production_no),
      KEY idx_production_branch_status_date (branch_id,status,production_date)
    ) ENGINE=InnoDB");

    db()->exec("CREATE TABLE IF NOT EXISTS production_items (
      id INT AUTO_INCREMENT PRIMARY KEY,
      production_id INT NOT NULL,
      material_product_id INT NOT NULL,
      required_qty DECIMAL(18,4) NOT NULL,
      actual_qty DECIMAL(18,4) NOT NULL,
      unit_cost DECIMAL(18,2) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_production_items_prod (production_id),
      KEY idx_production_items_material (material_product_id),
      CONSTRAINT fk_production_items_header FOREIGN KEY (production_id) REFERENCES production_headers(id) ON DELETE CASCADE,
      CONSTRAINT fk_production_items_material FOREIGN KEY (material_product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_stock_ledger_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS stock_ledger (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      branch_id INT NOT NULL,
      product_id INT NOT NULL,
      trans_type VARCHAR(60) NOT NULL,
      ref_table VARCHAR(60) NOT NULL,
      ref_id BIGINT NOT NULL,
      qty_in DECIMAL(18,4) NOT NULL DEFAULT 0,
      qty_out DECIMAL(18,4) NOT NULL DEFAULT 0,
      unit_cost DECIMAL(18,2) NULL,
      note VARCHAR(255) NULL,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_stock_ledger_main (branch_id,product_id,created_at),
      KEY idx_stock_ledger_ref (ref_table,ref_id)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_sales_inventory_columns(): void {
  $defs = [
    "ALTER TABLE sales ADD COLUMN branch_id INT NULL AFTER product_id",
  ];
  foreach ($defs as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) { }
  }
}

function ensure_products_reorder_level_column(): void {
  try {
    db()->exec("ALTER TABLE products ADD COLUMN reorder_level DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER track_stock");
  } catch (Throwable $e) {
    // no-op
  }
}

function ensure_stock_opname_tables(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS stock_opname_headers (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      opname_no VARCHAR(80) NOT NULL,
      branch_id INT NOT NULL,
      opname_date DATE NOT NULL,
      status ENUM('draft','waiting_approval','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
      notes TEXT NULL,
      approval_note TEXT NULL,
      created_by INT NOT NULL,
      approved_by INT NULL,
      approved_at TIMESTAMP NULL DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_stock_opname_no (opname_no),
      KEY idx_stock_opname_branch_status_date (branch_id,status,opname_date),
      KEY idx_stock_opname_created_by (created_by),
      KEY idx_stock_opname_approved_by (approved_by)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS stock_opname_items (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      opname_id BIGINT NOT NULL,
      product_id INT NOT NULL,
      system_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
      physical_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
      variance_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
      variance_type ENUM('plus','minus','zero') NOT NULL DEFAULT 'zero',
      reason_note VARCHAR(255) NULL,
      line_note VARCHAR(255) NULL,
      warning_flag TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_stock_opname_item (opname_id,product_id),
      KEY idx_stock_opname_item_product (product_id),
      CONSTRAINT fk_stock_opname_items_header FOREIGN KEY (opname_id) REFERENCES stock_opname_headers(id) ON DELETE CASCADE,
      CONSTRAINT fk_stock_opname_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_sales_production_links_table(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS sales_production_links (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      transaction_code VARCHAR(40) NOT NULL,
      production_id INT NOT NULL,
      branch_id INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_sales_prod_tx (transaction_code),
      KEY idx_sales_prod_prod (production_id)
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }
}

function ensure_inventory_settings_defaults(): void {
  $defaults = [
    'production_mode' => 'auto',
    'pos_autoproduction_enabled' => '1',
    'pos_autoproduction_allow_negative' => '0',
    'bom_require_exact_material_stock' => '1',
    'purchase_raw_material_only' => '1',
    'active_branch_id' => '1',
    'number_decimal_places_qty' => '2',
    'number_decimal_places_money' => '2',
    'number_decimal_separator' => '.',
    'number_thousand_separator' => ',',
    'number_trim_trailing_zero' => '0',
    'number_show_unit_after_qty' => '1',
  ];
  foreach ($defaults as $k => $v) {
    set_setting($k, setting($k, $v));
  }
}

function inventory_branches(): array {
  $stmt = db()->query("SELECT id, branch_code, branch_name, is_active FROM branches WHERE is_active=1 ORDER BY branch_name ASC");
  return $stmt->fetchAll();
}

function active_branch_id(): int {
  $id = (int)setting('active_branch_id', '1');
  if ($id > 0) return $id;
  return 1;
}

function branch_stock(int $branchId, int $productId): float {
  $stmt = db()->prepare("SELECT COALESCE(SUM(qty_in - qty_out),0) AS qty FROM stock_ledger WHERE branch_id=? AND product_id=?");
  $stmt->execute([$branchId, $productId]);
  $row = $stmt->fetch();
  return (float)($row['qty'] ?? 0);
}

function add_stock_ledger(array $row): void {
  $stmt = db()->prepare("INSERT INTO stock_ledger (branch_id,product_id,trans_type,ref_table,ref_id,qty_in,qty_out,unit_cost,note,created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([
    (int)$row['branch_id'],
    (int)$row['product_id'],
    (string)$row['trans_type'],
    (string)$row['ref_table'],
    (int)$row['ref_id'],
    (float)($row['qty_in'] ?? 0),
    (float)($row['qty_out'] ?? 0),
    isset($row['unit_cost']) ? (float)$row['unit_cost'] : null,
    $row['note'] ?? null,
    isset($row['created_by']) ? (int)$row['created_by'] : null,
  ]);
}

function get_active_bom_for_product(int $productId, int $branchId): ?array {
  $stmt = db()->prepare("SELECT * FROM bom_headers
    WHERE finished_product_id=? AND is_active=1 AND (branch_id IS NULL OR branch_id=?)
    ORDER BY CASE WHEN branch_id=? THEN 0 ELSE 1 END, id DESC LIMIT 1");
  $stmt->execute([$productId, $branchId, $branchId]);
  $bom = $stmt->fetch();
  return $bom ?: null;
}

function explode_bom_requirements(int $bomId, float $qtyToProduce): array {
  $stmt = db()->prepare("SELECT bi.*, p.name AS material_name, p.product_type, p.base_unit, p.purchase_unit, p.purchase_to_base_factor, p.sale_unit, p.sale_to_base_factor
    FROM bom_items bi
    JOIN products p ON p.id = bi.material_product_id
    WHERE bi.bom_id=?
    ORDER BY bi.sort_order ASC, bi.id ASC");
  $stmt->execute([$bomId]);
  $items = $stmt->fetchAll();

  $stmt = db()->prepare("SELECT yield_qty FROM bom_headers WHERE id=? LIMIT 1");
  $stmt->execute([$bomId]);
  $h = $stmt->fetch();
  $yield = max(0.0001, (float)($h['yield_qty'] ?? 1));
  $factor = $qtyToProduce / $yield;

  $out = [];
  foreach ($items as $it) {
    if (($it['product_type'] ?? '') !== 'raw_material') {
      throw new Exception('BOM item wajib produk raw material.');
    }
    $req = $factor * (float)$it['qty_per_yield'];
    $wastage = (float)$it['wastage_pct'];
    if ($wastage > 0) {
      $req *= (1 + ($wastage / 100));
    }
    $out[] = [
      'material_product_id' => (int)$it['material_product_id'],
      'required_qty' => round($req, 4),
      'actual_qty' => round($req, 4),
      'unit_cost' => null,
    ];
  }
  return $out;
}

function create_production_with_items(PDO $db, array $header, array $items): int {
  $stmt = $db->prepare("INSERT INTO production_headers
    (production_no, branch_id, bom_id, finished_product_id, production_date, qty_to_produce, status, mode_source, notes, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?)");
  $stmt->execute([
    $header['production_no'],
    (int)$header['branch_id'],
    (int)$header['bom_id'],
    (int)$header['finished_product_id'],
    $header['production_date'],
    (float)$header['qty_to_produce'],
    $header['status'] ?? 'draft',
    $header['mode_source'] ?? 'manual_menu',
    $header['notes'] ?? null,
    isset($header['created_by']) ? (int)$header['created_by'] : null,
  ]);
  $id = (int)$db->lastInsertId();

  $stmtItem = $db->prepare("INSERT INTO production_items (production_id, material_product_id, required_qty, actual_qty, unit_cost)
    VALUES (?,?,?,?,?)");
  foreach ($items as $it) {
    $stmtItem->execute([$id, (int)$it['material_product_id'], (float)$it['required_qty'], (float)$it['actual_qty'], $it['unit_cost']]);
  }
  return $id;
}

function post_production(int $productionId, int $userId, string $consumeTransType = 'production_consume', string $outputTransType = 'production_output'): void {
  $db = db();
  $stmt = $db->prepare("SELECT * FROM production_headers WHERE id=? LIMIT 1");
  $stmt->execute([$productionId]);
  $h = $stmt->fetch();
  if (!$h) throw new Exception('Dokumen produksi tidak ditemukan.');
  if ($h['status'] !== 'draft') throw new Exception('Hanya dokumen draft yang dapat diposting.');

  $stmt = $db->prepare("SELECT * FROM production_items WHERE production_id=?");
  $stmt->execute([$productionId]);
  $items = $stmt->fetchAll();
  if (empty($items)) throw new Exception('Item produksi kosong.');

  foreach ($items as $it) {
    $stock = branch_stock((int)$h['branch_id'], (int)$it['material_product_id']);
    if ($stock < (float)$it['actual_qty']) {
      throw new Exception('Stok bahan baku tidak cukup untuk posting produksi.');
    }
  }

  foreach ($items as $it) {
    add_stock_ledger([
      'branch_id' => (int)$h['branch_id'],
      'product_id' => (int)$it['material_product_id'],
      'trans_type' => $consumeTransType,
      'ref_table' => 'production_headers',
      'ref_id' => $productionId,
      'qty_in' => 0,
      'qty_out' => (float)$it['actual_qty'],
      'unit_cost' => $it['unit_cost'],
      'note' => 'Konsumsi produksi',
      'created_by' => $userId,
    ]);
  }

  add_stock_ledger([
    'branch_id' => (int)$h['branch_id'],
    'product_id' => (int)$h['finished_product_id'],
    'trans_type' => $outputTransType,
    'ref_table' => 'production_headers',
    'ref_id' => $productionId,
    'qty_in' => (float)$h['qty_to_produce'],
    'qty_out' => 0,
    'unit_cost' => null,
    'note' => 'Hasil produksi',
    'created_by' => $userId,
  ]);

  $stmt = $db->prepare("UPDATE production_headers SET status='posted', posted_by=?, posted_at=NOW() WHERE id=?");
  $stmt->execute([$userId, $productionId]);
}

function inventory_is_owner(array $user): bool {
  return ($user['role'] ?? '') === 'owner';
}

function inventory_require_stock_role(): array {
  require_admin();
  $u = current_user() ?? [];
  if (!in_array(($u['role'] ?? ''), ['owner', 'admin'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }
  return $u;
}

function stock_opname_warning_threshold(): float {
  return 10.0;
}

function stock_variance_needs_warning(float $varianceQty): bool {
  return abs($varianceQty) > stock_opname_warning_threshold();
}

function stock_variance_reason_required(float $varianceQty): bool {
  return abs($varianceQty) > 0.00001;
}

function stock_status_label(float $stockQty, float $reorderLevel): string {
  if ($stockQty <= 0) return 'Habis';
  if ($reorderLevel > 0 && $stockQty <= $reorderLevel) return 'Menipis';
  return 'Aman';
}

function generate_stock_opname_no(PDO $db): string {
  $prefix = 'SO-' . date('Ymd-His');
  for ($i = 0; $i < 10; $i++) {
    $suffix = strtoupper(bin2hex(random_bytes(2)));
    $no = $prefix . '-' . $suffix;
    $stmt = $db->prepare("SELECT id FROM stock_opname_headers WHERE opname_no=? LIMIT 1");
    $stmt->execute([$no]);
    if (!$stmt->fetch()) return $no;
  }
  return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
}

function stock_products_for_opname(int $branchId, string $search = '', string $category = '', string $productType = ''): array {
  $params = [$branchId];
  $sql = "SELECT p.id, p.name, p.category, p.product_type, p.track_stock, p.reorder_level, p.base_unit, p.purchase_unit, p.purchase_to_base_factor, p.sale_unit, p.sale_to_base_factor,
      COALESCE(SUM(sl.qty_in - sl.qty_out),0) AS current_stock
    FROM products p
    LEFT JOIN stock_ledger sl ON sl.product_id=p.id AND sl.branch_id=?
    WHERE p.track_stock=1 AND p.product_type IN ('raw_material','finished_good')";

  if ($search !== '') {
    $sql .= " AND (p.name LIKE ? OR COALESCE(p.category,'') LIKE ? OR CAST(p.id AS CHAR) LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
  }
  if ($category !== '') {
    $sql .= " AND COALESCE(p.category,'') = ?";
    $params[] = $category;
  }
  if ($productType !== '' && in_array($productType, ['raw_material', 'finished_good', 'service'], true)) {
    $sql .= " AND p.product_type = ?";
    $params[] = $productType;
  }
  $sql .= " GROUP BY p.id ORDER BY p.name ASC";

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll();
}

function stock_categories(): array {
  $stmt = db()->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category<>'' ORDER BY category ASC");
  return $stmt->fetchAll();
}

function create_stock_opname_draft(PDO $db, array $header, array $products): int {
  if (empty($products)) throw new Exception('Tidak ada produk untuk opname.');
  $opnameNo = generate_stock_opname_no($db);
  $stmt = $db->prepare("INSERT INTO stock_opname_headers (opname_no,branch_id,opname_date,status,notes,created_by) VALUES (?,?,?,?,?,?)");
  $stmt->execute([
    $opnameNo,
    (int)$header['branch_id'],
    (string)$header['opname_date'],
    'draft',
    $header['notes'] ?? null,
    (int)$header['created_by'],
  ]);
  $opnameId = (int)$db->lastInsertId();

  $itemStmt = $db->prepare("INSERT INTO stock_opname_items
    (opname_id,product_id,system_qty,physical_qty,variance_qty,variance_type,reason_note,line_note,warning_flag)
    VALUES (?,?,?,?,?,?,?,?,?)");
  foreach ($products as $p) {
    $systemQty = (float)($p['current_stock'] ?? 0);
    $itemStmt->execute([$opnameId, (int)$p['id'], $systemQty, $systemQty, 0, 'zero', null, null, 0]);
  }
  return $opnameId;
}

function get_stock_opname_header(int $id): ?array {
  $stmt = db()->prepare("SELECT h.*, b.branch_name, u.name AS creator_name, ua.name AS approver_name
    FROM stock_opname_headers h
    LEFT JOIN branches b ON b.id=h.branch_id
    LEFT JOIN users u ON u.id=h.created_by
    LEFT JOIN users ua ON ua.id=h.approved_by
    WHERE h.id=? LIMIT 1");
  $stmt->execute([$id]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function get_stock_opname_items(int $opnameId): array {
  $stmt = db()->prepare("SELECT i.*, p.name product_name, p.category, p.product_type, p.reorder_level, p.base_unit, p.purchase_unit, p.purchase_to_base_factor, p.sale_unit, p.sale_to_base_factor
    FROM stock_opname_items i
    JOIN products p ON p.id=i.product_id
    WHERE i.opname_id=?
    ORDER BY p.name ASC");
  $stmt->execute([$opnameId]);
  return $stmt->fetchAll();
}

function save_stock_opname_items(PDO $db, int $opnameId, array $rows): void {
  $header = get_stock_opname_header($opnameId);
  if (!$header) throw new Exception('Dokumen opname tidak ditemukan.');
  if (($header['status'] ?? '') !== 'draft') throw new Exception('Hanya draft yang dapat diedit.');
  if (empty($rows)) throw new Exception('Item opname wajib ada.');

  $stmt = $db->prepare("UPDATE stock_opname_items
    SET physical_qty=?, variance_qty=?, variance_type=?, reason_note=?, line_note=?, warning_flag=?, updated_at=NOW()
    WHERE id=? AND opname_id=?");

  foreach ($rows as $row) {
    $itemId = (int)($row['id'] ?? 0);
    $physicalQty = (float)($row['physical_qty'] ?? 0);
    if ($physicalQty < 0) throw new Exception('Physical qty tidak boleh negatif.');
    $reason = trim((string)($row['reason_note'] ?? ''));
    $lineNote = trim((string)($row['line_note'] ?? ''));
    $systemQty = (float)($row['system_qty'] ?? 0);
    $variance = round($physicalQty - $systemQty, 4);
    $type = 'zero';
    if ($variance > 0) $type = 'plus';
    if ($variance < 0) $type = 'minus';
    if (stock_variance_reason_required($variance) && $reason === '') {
      throw new Exception('Alasan selisih wajib diisi untuk variance tidak nol.');
    }
    $stmt->execute([
      $physicalQty,
      $variance,
      $type,
      $reason !== '' ? $reason : null,
      $lineNote !== '' ? $lineNote : null,
      stock_variance_needs_warning($variance) ? 1 : 0,
      $itemId,
      $opnameId,
    ]);
  }
}

function submit_stock_opname(PDO $db, int $opnameId): void {
  $header = get_stock_opname_header($opnameId);
  if (!$header) throw new Exception('Dokumen opname tidak ditemukan.');
  if (($header['status'] ?? '') !== 'draft') throw new Exception('Hanya draft yang bisa disubmit.');
  $items = get_stock_opname_items($opnameId);
  if (empty($items)) throw new Exception('Item opname kosong.');
  foreach ($items as $it) {
    $physical = (float)$it['physical_qty'];
    if ($physical < 0) throw new Exception('Physical qty tidak boleh negatif.');
    $variance = (float)$it['variance_qty'];
    if (stock_variance_reason_required($variance) && trim((string)($it['reason_note'] ?? '')) === '') {
      throw new Exception('Masih ada item selisih tanpa alasan.');
    }
  }
  $stmt = $db->prepare("UPDATE stock_opname_headers SET status='waiting_approval', updated_at=NOW() WHERE id=?");
  $stmt->execute([$opnameId]);
}

function approve_stock_opname(PDO $db, int $opnameId, int $userId, string $note = ''): void {
  $stmt = $db->prepare("SELECT * FROM stock_opname_headers WHERE id=? LIMIT 1 FOR UPDATE");
  $stmt->execute([$opnameId]);
  $header = $stmt->fetch();
  if (!$header) throw new Exception('Dokumen opname tidak ditemukan.');
  if (($header['status'] ?? '') !== 'waiting_approval') throw new Exception('Hanya status menunggu approval yang bisa diapprove.');

  $items = get_stock_opname_items($opnameId);
  if (empty($items)) throw new Exception('Item opname kosong.');
  foreach ($items as $it) {
    $variance = (float)$it['variance_qty'];
    if (abs($variance) < 0.00001) continue;
    $transType = $variance > 0 ? 'stock_opname_adjustment_plus' : 'stock_opname_adjustment_minus';
    add_stock_ledger([
      'branch_id' => (int)$header['branch_id'],
      'product_id' => (int)$it['product_id'],
      'trans_type' => $transType,
      'ref_table' => 'stock_opname_headers',
      'ref_id' => $opnameId,
      'qty_in' => $variance > 0 ? abs($variance) : 0,
      'qty_out' => $variance < 0 ? abs($variance) : 0,
      'unit_cost' => null,
      'note' => 'Adjustment stok opname ' . (string)$header['opname_no'],
      'created_by' => $userId,
    ]);
  }

  $stmt = $db->prepare("UPDATE stock_opname_headers
    SET status='approved', approved_by=?, approved_at=NOW(), approval_note=?, updated_at=NOW()
    WHERE id=?");
  $stmt->execute([$userId, $note !== '' ? $note : null, $opnameId]);
}

function reject_stock_opname(PDO $db, int $opnameId, int $userId, string $note = ''): void {
  if ($note === '') throw new Exception('Catatan reject wajib diisi.');
  $stmt = $db->prepare("UPDATE stock_opname_headers
    SET status='rejected', approved_by=?, approved_at=NOW(), approval_note=?, updated_at=NOW()
    WHERE id=? AND status='waiting_approval'");
  $stmt->execute([$userId, $note, $opnameId]);
  if ($stmt->rowCount() <= 0) throw new Exception('Dokumen tidak bisa direject.');
}

function cancel_stock_opname(PDO $db, int $opnameId): void {
  $stmt = $db->prepare("UPDATE stock_opname_headers SET status='cancelled', updated_at=NOW()
    WHERE id=? AND status IN ('draft','waiting_approval')");
  $stmt->execute([$opnameId]);
  if ($stmt->rowCount() <= 0) throw new Exception('Hanya draft/menunggu approval yang bisa dibatalkan.');
}

function stock_card_rows(int $branchId, int $productId, string $dateFrom = '', string $dateTo = ''): array {
  $params = [$branchId, $productId];
  $sql = "SELECT sl.*, u.name AS user_name, soh.opname_no, ph.purchase_no, pr.production_no
    FROM stock_ledger sl
    LEFT JOIN users u ON u.id=sl.created_by
    LEFT JOIN stock_opname_headers soh ON soh.id=sl.ref_id AND sl.ref_table='stock_opname_headers'
    LEFT JOIN purchase_headers ph ON ph.id=sl.ref_id AND sl.ref_table='purchase_headers'
    LEFT JOIN production_headers pr ON pr.id=sl.ref_id AND sl.ref_table='production_headers'
    WHERE sl.branch_id=? AND sl.product_id=?";
  if ($dateFrom !== '') {
    $sql .= " AND DATE(sl.created_at) >= ?";
    $params[] = $dateFrom;
  }
  if ($dateTo !== '') {
    $sql .= " AND DATE(sl.created_at) <= ?";
    $params[] = $dateTo;
  }
  $sql .= " ORDER BY sl.created_at ASC, sl.id ASC";
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll();
}
