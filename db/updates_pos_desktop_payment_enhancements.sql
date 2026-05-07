-- POS Desktop payment enhancements: credit-card gross-up, multi-payment, and manual customer capture
INSERT INTO settings (`key`, `value`) VALUES ('credit_card_fee_percent', '2.5')
ON DUPLICATE KEY UPDATE `value` = `value`;

ALTER TABLE sales ADD COLUMN customer_name VARCHAR(150) NULL;
ALTER TABLE sales ADD COLUMN customer_phone VARCHAR(50) NULL;
ALTER TABLE sales ADD COLUMN payment_summary TEXT NULL;

CREATE TABLE IF NOT EXISTS sale_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT NULL,
  transaction_group_uuid VARCHAR(120) NULL,
  local_transaction_id VARCHAR(120) NULL,
  payment_method VARCHAR(50) NOT NULL,
  payment_bank VARCHAR(120) NULL,
  payment_bank_id BIGINT NULL,
  amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  fee_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
  fee_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  charged_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
  cash_received DECIMAL(15,2) NULL,
  cash_change DECIMAL(15,2) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sale_payments_local_tx (local_transaction_id),
  KEY idx_sale_payments_group (transaction_group_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
