-- Adena multi-cabang/dapur/harga cabang compatibility patch
-- Jalankan sekali di phpMyAdmin sebelum/bersamaan upload file patch.

ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER device_code;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER product_id;
ALTER TABLE pos_shifts ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER shift_code;

CREATE TABLE IF NOT EXISTS branch_product_prices (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  product_id INT NOT NULL,
  price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_branch_product_price (branch_id, product_id),
  KEY idx_bpp_product (product_id),
  KEY idx_bpp_active (branch_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO stock_locations (location_code, location_name, location_type, branch_id, is_active)
SELECT CONCAT('TOKO-', UPPER(REPLACE(REPLACE(b.branch_code, ' ', ''), '-', ''))), CONCAT('Toko ', b.branch_name), 'branch', b.id, 1
FROM branches b
LEFT JOIN stock_locations sl ON sl.branch_id = b.id AND sl.is_active = 1
WHERE b.is_active = 1 AND sl.id IS NULL;
