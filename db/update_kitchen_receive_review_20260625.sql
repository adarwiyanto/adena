-- Patch tambahan 2026-06-25
-- Review/edit penerimaan stok dapur oleh Manager Cabang.
-- Jalankan pada database Adena/Toko.

CREATE TABLE IF NOT EXISTS kitchen_api_receive_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  token_id INT NULL,
  branch_id INT NULL,
  supplier_id INT NULL,
  transfer_no VARCHAR(80) NULL,
  endpoint VARCHAR(160) NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'pending_confirmation',
  purchase_id INT NULL,
  purchase_no VARCHAR(80) NULL,
  message TEXT NULL,
  payload_json LONGTEXT NULL,
  remote_ip VARCHAR(80) NULL,
  confirmed_by INT NULL,
  confirmed_at DATETIME NULL,
  returned_by INT NULL,
  returned_at DATETIME NULL,
  return_note TEXT NULL,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  review_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transfer_no(transfer_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS kitchen_api_received_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  log_id BIGINT NOT NULL,
  product_id INT NOT NULL,
  sku VARCHAR(100) NULL,
  product_name VARCHAR(180) NULL,
  qty DECIMAL(18,4) NOT NULL DEFAULT 0,
  qty_base DECIMAL(18,4) NOT NULL DEFAULT 0,
  unit VARCHAR(50) NULL,
  transfer_price DECIMAL(18,2) DEFAULT 0,
  unit_cost DECIMAL(18,2) DEFAULT 0,
  line_total DECIMAL(18,2) DEFAULT 0,
  review_status VARCHAR(30) NOT NULL DEFAULT 'unchecked',
  reviewed_product_id INT NULL,
  reviewed_qty DECIMAL(18,4) NULL,
  reviewed_qty_base DECIMAL(18,4) NULL,
  reviewed_unit VARCHAR(50) NULL,
  reviewed_unit_cost DECIMAL(18,2) NULL,
  reviewed_line_total DECIMAL(18,2) NULL,
  review_note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_log_id(log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE PROCEDURE adena_add_col_if_missing(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_sql TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @adena_sql = p_sql;
    PREPARE stmt FROM @adena_sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$
DELIMITER ;

CALL adena_add_col_if_missing('kitchen_api_receive_logs','branch_id','ALTER TABLE kitchen_api_receive_logs ADD COLUMN branch_id INT NULL AFTER token_id');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','supplier_id','ALTER TABLE kitchen_api_receive_logs ADD COLUMN supplier_id INT NULL AFTER branch_id');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','purchase_id','ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_id INT NULL AFTER status');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','purchase_no','ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_no VARCHAR(80) NULL AFTER purchase_id');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','confirmed_by','ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_by INT NULL AFTER remote_ip');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','confirmed_at','ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_at DATETIME NULL AFTER confirmed_by');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','returned_by','ALTER TABLE kitchen_api_receive_logs ADD COLUMN returned_by INT NULL AFTER confirmed_at');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','returned_at','ALTER TABLE kitchen_api_receive_logs ADD COLUMN returned_at DATETIME NULL AFTER returned_by');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','return_note','ALTER TABLE kitchen_api_receive_logs ADD COLUMN return_note TEXT NULL AFTER returned_at');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','reviewed_by','ALTER TABLE kitchen_api_receive_logs ADD COLUMN reviewed_by INT NULL AFTER return_note');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','reviewed_at','ALTER TABLE kitchen_api_receive_logs ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by');
CALL adena_add_col_if_missing('kitchen_api_receive_logs','review_note','ALTER TABLE kitchen_api_receive_logs ADD COLUMN review_note TEXT NULL AFTER reviewed_at');

CALL adena_add_col_if_missing('kitchen_api_received_items','qty_base','ALTER TABLE kitchen_api_received_items ADD COLUMN qty_base DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER qty');
CALL adena_add_col_if_missing('kitchen_api_received_items','unit_cost','ALTER TABLE kitchen_api_received_items ADD COLUMN unit_cost DECIMAL(18,2) DEFAULT 0 AFTER transfer_price');
CALL adena_add_col_if_missing('kitchen_api_received_items','line_total','ALTER TABLE kitchen_api_received_items ADD COLUMN line_total DECIMAL(18,2) DEFAULT 0 AFTER unit_cost');
CALL adena_add_col_if_missing('kitchen_api_received_items','review_status','ALTER TABLE kitchen_api_received_items ADD COLUMN review_status VARCHAR(30) NOT NULL DEFAULT ''unchecked'' AFTER line_total');
CALL adena_add_col_if_missing('kitchen_api_received_items','reviewed_product_id','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_product_id INT NULL AFTER review_status');
CALL adena_add_col_if_missing('kitchen_api_received_items','reviewed_qty','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_qty DECIMAL(18,4) NULL AFTER reviewed_product_id');
CALL adena_add_col_if_missing('kitchen_api_received_items','reviewed_qty_base','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_qty_base DECIMAL(18,4) NULL AFTER reviewed_qty');
CALL adena_add_col_if_missing('kitchen_api_received_items','reviewed_unit','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_unit VARCHAR(50) NULL AFTER reviewed_qty_base');
CALL adena_add_col_if_missing('kitchen_api_received_items','reviewed_unit_cost','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_unit_cost DECIMAL(18,2) NULL AFTER reviewed_unit');
CALL adena_add_col_if_missing('kitchen_api_received_items','reviewed_line_total','ALTER TABLE kitchen_api_received_items ADD COLUMN reviewed_line_total DECIMAL(18,2) NULL AFTER reviewed_unit_cost');
CALL adena_add_col_if_missing('kitchen_api_received_items','review_note','ALTER TABLE kitchen_api_received_items ADD COLUMN review_note VARCHAR(255) NULL AFTER reviewed_line_total');

DROP PROCEDURE IF EXISTS adena_add_col_if_missing;

-- Role Manager Cabang tetap diberi akses inventori agar tidak mental ke dashboard.
INSERT INTO roles (role_key, role_name, is_system, is_active)
VALUES ('manager_cabang','Manager Cabang',1,1)
ON DUPLICATE KEY UPDATE role_name='Manager Cabang', is_system=1, is_active=1;

UPDATE users SET role='manager_cabang' WHERE role='manager';

INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
SELECT r.id, x.menu_key, 1,1,1,1,1,1,1
FROM roles r
JOIN (
  SELECT 'inventori' AS menu_key UNION ALL SELECT 'stok_opname' UNION ALL SELECT 'dashboard'
) x
WHERE r.role_key='manager_cabang'
ON DUPLICATE KEY UPDATE can_view=1, can_create=1, can_edit=1, can_delete=1, can_print=1, can_export=1, can_approve=1;
