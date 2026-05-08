-- Patch: harga jual finished good per cabang/dapur.
-- Aman dijalankan berulang. Jika kolom/index sudah ada, abaikan error duplikat dari phpMyAdmin/MySQL client.

CREATE TABLE IF NOT EXISTS branch_product_prices (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  product_id INT NOT NULL,
  price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_branch_product_price (branch_id, product_id),
  KEY idx_bpp_branch (branch_id),
  KEY idx_bpp_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE api_tokens ADD COLUMN branch_id INT NULL AFTER device_code;
ALTER TABLE api_tokens ADD KEY idx_api_tokens_branch (branch_id);
