-- Adena API Settings v2.1 hotfix
-- Catatan: patch PHP sudah melakukan migrasi otomatis secara defensif.
-- SQL ini opsional bila ingin menyiapkan tabel/kolom manual lewat phpMyAdmin.

CREATE TABLE IF NOT EXISTS api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  device_code VARCHAR(40) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  INDEX idx_api_tokens_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='api_tokens' AND COLUMN_NAME='branch_id')=0, 'ALTER TABLE api_tokens ADD COLUMN branch_id INT NULL AFTER device_code', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='api_tokens' AND COLUMN_NAME='token_plain')=0, 'ALTER TABLE api_tokens ADD COLUMN token_plain TEXT NULL AFTER branch_id', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='api_tokens' AND COLUMN_NAME='api_type')=0, 'ALTER TABLE api_tokens ADD COLUMN api_type VARCHAR(50) NULL AFTER token_plain', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='api_tokens' AND COLUMN_NAME='client_type')=0, 'ALTER TABLE api_tokens ADD COLUMN client_type VARCHAR(30) NULL AFTER api_type', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='api_tokens' AND COLUMN_NAME='unit_code')=0, 'ALTER TABLE api_tokens ADD COLUMN unit_code VARCHAR(40) NULL AFTER client_type', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='api_tokens' AND COLUMN_NAME='remote_base_url')=0, 'ALTER TABLE api_tokens ADD COLUMN remote_base_url VARCHAR(255) NULL AFTER unit_code', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='api_tokens' AND COLUMN_NAME='remote_token')=0, 'ALTER TABLE api_tokens ADD COLUMN remote_token TEXT NULL AFTER remote_base_url', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='api_tokens' AND COLUMN_NAME='permissions')=0, 'ALTER TABLE api_tokens ADD COLUMN permissions TEXT NULL AFTER remote_token', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='api_tokens' AND COLUMN_NAME='allowed_ips')=0, 'ALTER TABLE api_tokens ADD COLUMN allowed_ips TEXT NULL AFTER permissions', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='api_tokens' AND COLUMN_NAME='notes')=0, 'ALTER TABLE api_tokens ADD COLUMN notes TEXT NULL AFTER allowed_ips', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

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
