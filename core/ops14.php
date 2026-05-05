<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/rbac.php';

function ensure_adena14_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  $pdo = db();
  $safe = static function (string $sql) use ($pdo): void { try { $pdo->exec($sql); } catch (Throwable $e) {} };

  $safe("ALTER TABLE products ADD COLUMN is_price_editable TINYINT(1) NOT NULL DEFAULT 0 AFTER track_stock");
  $safe("ALTER TABLE products ADD COLUMN include_in_sales_report TINYINT(1) NOT NULL DEFAULT 1 AFTER is_price_editable");
  $safe("ALTER TABLE products ADD COLUMN kitchen_price DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER price");
  $safe("ALTER TABLE products ADD COLUMN min_stock_level DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER kitchen_price");

  $safe("ALTER TABLE sales ADD COLUMN discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total");
  $safe("ALTER TABLE sales ADD COLUMN discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed' AFTER discount_amount");
  $safe("ALTER TABLE sales ADD COLUMN tx_discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER discount_type");
  $safe("ALTER TABLE sales ADD COLUMN tx_discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed' AFTER tx_discount_amount");
  $safe("ALTER TABLE sales ADD COLUMN include_in_sales_report TINYINT(1) NOT NULL DEFAULT 1 AFTER tx_discount_type");
  $safe("ALTER TABLE sales ADD COLUMN line_subtotal DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER include_in_sales_report");
  $safe("ALTER TABLE sales ADD COLUMN line_net_total DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER line_subtotal");
  $safe("ALTER TABLE sales ADD COLUMN pending_order_id BIGINT NULL AFTER line_net_total");

  $safe("CREATE TABLE IF NOT EXISTS stock_locations (
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
  ) ENGINE=InnoDB");

  $safe("CREATE TABLE IF NOT EXISTS initial_stock_entries (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    location_id INT NOT NULL,
    product_id INT NOT NULL,
    qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(18,2) NULL,
    status ENUM('posted','owner_override_requested','owner_override_approved','void') NOT NULL DEFAULT 'posted',
    note TEXT NULL,
    created_by INT NULL,
    approved_by INT NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_initial_stock_once (location_id,product_id),
    KEY idx_initial_stock_location (location_id,status)
  ) ENGINE=InnoDB");

  $safe("CREATE TABLE IF NOT EXISTS stock_transfers (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    transfer_no VARCHAR(60) NOT NULL,
    from_location_id INT NOT NULL,
    to_location_id INT NOT NULL,
    status ENUM('draft','sent','accepted','rejected','cancelled') NOT NULL DEFAULT 'draft',
    sent_at TIMESTAMP NULL DEFAULT NULL,
    accepted_at TIMESTAMP NULL DEFAULT NULL,
    rejected_at TIMESTAMP NULL DEFAULT NULL,
    created_by INT NULL,
    sent_by INT NULL,
    received_by INT NULL,
    notes TEXT NULL,
    receiver_notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_stock_transfer_no (transfer_no),
    KEY idx_stock_transfer_status (status,from_location_id,to_location_id)
  ) ENGINE=InnoDB");

  $safe("CREATE TABLE IF NOT EXISTS stock_transfer_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    transfer_id BIGINT NOT NULL,
    product_id INT NOT NULL,
    qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(18,2) NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_transfer_items_transfer (transfer_id),
    KEY idx_transfer_items_product (product_id)
  ) ENGINE=InnoDB");

  $safe("CREATE TABLE IF NOT EXISTS pos_pending_orders (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    local_pending_id VARCHAR(120) NOT NULL,
    pending_code VARCHAR(80) NULL,
    cashier_id INT NULL,
    branch_id INT NULL,
    customer_name VARCHAR(160) NULL,
    note TEXT NULL,
    subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
    total DECIMAL(18,2) NOT NULL DEFAULT 0,
    status ENUM('pending','paid','deleted') NOT NULL DEFAULT 'pending',
    payload_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pos_pending_local (local_pending_id),
    KEY idx_pos_pending_status (status,branch_id)
  ) ENGINE=InnoDB");

  $safe("CREATE TABLE IF NOT EXISTS pos_pending_order_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    pending_order_id BIGINT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(190) NULL,
    qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    price_each DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
    total DECIMAL(18,2) NOT NULL DEFAULT 0,
    include_in_sales_report TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_pending_items_order (pending_order_id)
  ) ENGINE=InnoDB");

  try {
    $pdo->exec("INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
      SELECT 'KITCHEN','Dapur Produksi','kitchen',NULL,1 FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM stock_locations WHERE location_code='KITCHEN')");
    $pdo->exec("INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
      SELECT 'MAIN','Toko Utama','store',1,1 FROM DUAL
      WHERE NOT EXISTS (SELECT 1 FROM stock_locations WHERE location_code='MAIN')");
    $pdo->exec("UPDATE sales SET line_subtotal = qty * price_each WHERE line_subtotal = 0 OR line_subtotal IS NULL");
    $pdo->exec("UPDATE sales SET line_net_total = total WHERE line_net_total = 0 OR line_net_total IS NULL");
  } catch (Throwable $e) {}
}

function adena14_locations(): array {
  ensure_adena14_schema();
  try { return db()->query("SELECT * FROM stock_locations WHERE is_active=1 ORDER BY FIELD(location_type,'kitchen','store','branch'), location_name")->fetchAll(PDO::FETCH_ASSOC) ?: []; }
  catch (Throwable $e) { return []; }
}

function adena14_dashboard_metrics(?string $area = null, ?int $locationId = null): array {
  ensure_adena14_schema();
  $pdo = db();
  $out = ['revenue'=>0,'transactions'=>0,'stock_skus'=>0,'low_stock'=>0,'dead_stock'=>0,'transfers_pending'=>0,'production_30d'=>0,'purchases_30d'=>0];
  try {
    $out['revenue'] = (float)($pdo->query("SELECT COALESCE(SUM(CASE WHEN include_in_sales_report=0 THEN 0 ELSE COALESCE(line_net_total,total) END),0) c FROM sales WHERE sold_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND (revision_status IS NULL OR revision_status='active')")->fetch()['c'] ?? 0);
    $out['transactions'] = (int)($pdo->query("SELECT COUNT(DISTINCT COALESCE(transaction_group_uuid,transaction_code)) c FROM sales WHERE sold_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch()['c'] ?? 0);
    $out['stock_skus'] = (int)($pdo->query("SELECT COUNT(DISTINCT product_id) c FROM stock_ledger")->fetch()['c'] ?? 0);
    $out['low_stock'] = (int)($pdo->query("SELECT COUNT(*) c FROM products p WHERE p.track_stock=1 AND COALESCE(p.reorder_level,p.min_stock_level,0)>0")->fetch()['c'] ?? 0);
    $out['dead_stock'] = (int)($pdo->query("SELECT COUNT(*) c FROM products p WHERE p.track_stock=1 AND p.id NOT IN (SELECT DISTINCT product_id FROM sales WHERE sold_at >= DATE_SUB(NOW(), INTERVAL 60 DAY))")->fetch()['c'] ?? 0);
    $out['transfers_pending'] = (int)($pdo->query("SELECT COUNT(*) c FROM stock_transfers WHERE status='sent'")->fetch()['c'] ?? 0);
    $out['production_30d'] = (int)($pdo->query("SELECT COUNT(*) c FROM production_headers WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch()['c'] ?? 0);
    $out['purchases_30d'] = (int)($pdo->query("SELECT COUNT(*) c FROM purchase_headers WHERE purchase_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetch()['c'] ?? 0);
  } catch (Throwable $e) {}
  return $out;
}

function adena14_area_guard(string $area): array {
  start_secure_session();
  require_admin();
  ensure_rbac_schema();
  ensure_adena14_schema();
  $u = current_user() ?: [];
  if (current_user_is_owner() || has_menu_access($u, 'dashboard')) return $u;
  if ($area === 'kitchen' && (has_menu_access($u, 'kitchen_page') || has_menu_access($u, 'inventori') || has_menu_access($u, 'stok_opname'))) return $u;
  if ($area === 'branch' && has_menu_access($u, 'branch_page')) return $u;
  redirect_to_best_allowed_page($u, 'area:' . $area);
  return $u;
}
