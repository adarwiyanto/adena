-- Adena API Settings v2 - additive safe update
CREATE TABLE IF NOT EXISTS api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  device_code VARCHAR(40) NULL,
  branch_id INT NULL,
  token_plain TEXT NULL,
  api_type VARCHAR(50) NULL,
  unit_code VARCHAR(40) NULL,
  remote_base_url VARCHAR(255) NULL,
  remote_token TEXT NULL,
  allowed_ips TEXT NULL,
  notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  INDEX idx_api_tokens_active (is_active),
  INDEX idx_api_tokens_device (device_code),
  INDEX idx_api_tokens_unit (unit_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER device_code;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS token_plain TEXT NULL AFTER branch_id;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS api_type VARCHAR(50) NULL AFTER token_plain;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS unit_code VARCHAR(40) NULL AFTER api_type;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS remote_base_url VARCHAR(255) NULL AFTER unit_code;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS remote_token TEXT NULL AFTER remote_base_url;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS allowed_ips TEXT NULL AFTER remote_token;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER allowed_ips;
ALTER TABLE api_tokens MODIFY device_code VARCHAR(40) NULL;

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
