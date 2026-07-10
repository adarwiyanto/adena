-- Patch Adena: API Dapur + transfer stok dapur/toko.
-- Aman untuk database existing. Halaman PHP patch juga menjalankan migrasi defensif otomatis.

CREATE TABLE IF NOT EXISTS dapur_stock_transfers (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_token_permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  token_id INT NOT NULL,
  permission_key VARCHAR(80) NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_api_token_permission (token_id, permission_key),
  KEY idx_api_token_permissions_token (token_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  token_id INT NULL,
  token_name VARCHAR(120) NULL,
  endpoint VARCHAR(255) NOT NULL,
  method VARCHAR(12) NOT NULL,
  permission_key VARCHAR(80) NULL,
  status_code INT NULL,
  ip_address VARCHAR(64) NULL,
  message VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_api_logs_created (created_at),
  KEY idx_api_logs_token (token_id),
  KEY idx_api_logs_endpoint (endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS adena_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE adena_add_column_if_missing(IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_definition);
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END//
DELIMITER ;

CALL adena_add_column_if_missing('api_tokens', 'api_mode', "api_mode VARCHAR(20) NOT NULL DEFAULT 'sender' AFTER device_code");
CALL adena_add_column_if_missing('api_tokens', 'branch_id', 'branch_id INT NULL AFTER api_mode');
CALL adena_add_column_if_missing('api_tokens', 'unit_code', 'unit_code VARCHAR(40) NULL AFTER branch_id');
CALL adena_add_column_if_missing('api_tokens', 'remote_base_url', 'remote_base_url VARCHAR(255) NULL AFTER unit_code');
CALL adena_add_column_if_missing('api_tokens', 'remote_token', 'remote_token TEXT NULL AFTER remote_base_url');
CALL adena_add_column_if_missing('api_tokens', 'token_plain', 'token_plain TEXT NULL AFTER remote_token');
CALL adena_add_column_if_missing('api_tokens', 'api_type', 'api_type VARCHAR(50) NULL AFTER token_plain');
CALL adena_add_column_if_missing('api_tokens', 'client_type', "client_type VARCHAR(30) NOT NULL DEFAULT 'pos_desktop' AFTER api_type");
CALL adena_add_column_if_missing('api_tokens', 'permissions', 'permissions TEXT NULL AFTER client_type');
CALL adena_add_column_if_missing('api_tokens', 'allowed_ips', 'allowed_ips TEXT NULL AFTER permissions');
CALL adena_add_column_if_missing('api_tokens', 'notes', 'notes TEXT NULL AFTER allowed_ips');
CALL adena_add_column_if_missing('api_tokens', 'last_used_at', 'last_used_at DATETIME NULL AFTER is_active');
CALL adena_add_column_if_missing('api_tokens', 'revoked_at', 'revoked_at DATETIME NULL AFTER created_at');

DROP PROCEDURE IF EXISTS adena_add_column_if_missing;
