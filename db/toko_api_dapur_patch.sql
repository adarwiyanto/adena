CREATE TABLE IF NOT EXISTS kitchen_api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  token_name VARCHAR(120) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  store_code VARCHAR(80) NULL,
  permissions_json TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS kitchen_api_receive_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  token_id INT NULL,
  branch_id INT NULL,
  supplier_id INT NULL,
  transfer_no VARCHAR(80) NULL,
  endpoint VARCHAR(160) NULL,
  status VARCHAR(40) NOT NULL,
  purchase_id INT NULL,
  purchase_no VARCHAR(80) NULL,
  message TEXT NULL,
  payload_json LONGTEXT NULL,
  remote_ip VARCHAR(80) NULL,
  confirmed_by INT NULL,
  confirmed_at DATETIME NULL,
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
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
