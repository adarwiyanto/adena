-- Adena patch: toko menerima transfer stok dari dapur, pembelian umum, dan impor produk
-- Aman dijalankan berulang; abaikan error duplicate column/key bila database sudah pernah dipatch.

ALTER TABLE purchase_headers
  ADD COLUMN purchase_type ENUM('raw_material','general') NOT NULL DEFAULT 'raw_material' AFTER purchase_date;

ALTER TABLE purchase_items
  MODIFY product_id INT NULL;

ALTER TABLE purchase_items
  ADD COLUMN item_name VARCHAR(190) NULL AFTER product_id;

CREATE TABLE IF NOT EXISTS stock_transfer_headers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transfer_no VARCHAR(50) NOT NULL,
  transfer_date DATE NOT NULL,
  source_branch_id INT NOT NULL,
  dest_branch_id INT NOT NULL,
  status ENUM('draft','sent','received','cancelled') NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  created_by INT NULL,
  sent_by INT NULL,
  sent_at TIMESTAMP NULL DEFAULT NULL,
  received_by INT NULL,
  received_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_transfer_no (transfer_no),
  KEY idx_transfer_status (source_branch_id,dest_branch_id,status,transfer_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(18,4) NOT NULL,
  unit_cost DECIMAL(18,2) NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_transfer_items_header (transfer_id),
  KEY idx_transfer_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
