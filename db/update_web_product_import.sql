-- Patch: API antar website + impor produk.
-- Aman untuk database existing dan DIPISAH dari api_tokens/API Desktop.

CREATE TABLE IF NOT EXISTS api_web_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  token_plain TEXT NULL,
  permissions TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  INDEX idx_api_web_tokens_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_remote_connections (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  base_url VARCHAR(255) NOT NULL,
  token TEXT NOT NULL,
  permissions TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_test_at DATETIME NULL,
  last_test_status VARCHAR(30) NULL,
  last_test_message TEXT NULL,
  last_sync_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_api_remote_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_import_mappings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  connection_id INT NOT NULL,
  remote_base_url VARCHAR(255) NOT NULL,
  remote_product_id INT NOT NULL,
  remote_hash VARCHAR(64) NULL,
  local_product_id INT NOT NULL,
  last_imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_remote_product (connection_id, remote_product_id),
  INDEX idx_local_product (local_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_import_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  connection_id INT NULL,
  import_status VARCHAR(30) NOT NULL DEFAULT 'success',
  total_new INT NOT NULL DEFAULT 0,
  total_updated INT NOT NULL DEFAULT 0,
  total_skipped INT NOT NULL DEFAULT 0,
  total_conflict INT NOT NULL DEFAULT 0,
  message TEXT NULL,
  created_by INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_import_logs_connection (connection_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
