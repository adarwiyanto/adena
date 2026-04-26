-- Additive migration untuk API token desktop + idempotency transaksi desktop

CREATE TABLE IF NOT EXISTS api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  INDEX idx_api_tokens_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sales ADD COLUMN local_device_id VARCHAR(120) NULL;
ALTER TABLE sales ADD COLUMN local_transaction_id VARCHAR(120) NULL;
ALTER TABLE sales ADD COLUMN payment_channel_id BIGINT NULL;
ALTER TABLE sales ADD COLUMN payment_channel_name VARCHAR(120) NULL;
ALTER TABLE sales ADD COLUMN guide_id BIGINT NULL;

ALTER TABLE sales ADD KEY idx_sales_device_local (local_device_id, local_transaction_id);
ALTER TABLE sales ADD KEY idx_sales_payment_channel_id (payment_channel_id);
ALTER TABLE sales ADD KEY idx_sales_guide_id (guide_id);
