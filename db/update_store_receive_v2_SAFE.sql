-- Patch SAFE: alur toko menerima stok dari dapur + pembelian umum.
-- Aman dijalankan ulang pada database yang sudah pernah terkena patch sebagian.

DELIMITER $$

DROP PROCEDURE IF EXISTS adena_safe_patch_store_receive_v2 $$
CREATE PROCEDURE adena_safe_patch_store_receive_v2()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_headers'
      AND COLUMN_NAME = 'purchase_type'
  ) THEN
    ALTER TABLE purchase_headers
      ADD COLUMN purchase_type ENUM('raw_material','general') NOT NULL DEFAULT 'raw_material' AFTER purchase_date;
  END IF;

  IF EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_items'
      AND COLUMN_NAME = 'product_id'
      AND IS_NULLABLE = 'NO'
  ) THEN
    ALTER TABLE purchase_items
      MODIFY product_id INT NULL;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'purchase_items'
      AND COLUMN_NAME = 'item_name'
  ) THEN
    ALTER TABLE purchase_items
      ADD COLUMN item_name VARCHAR(190) NULL AFTER product_id;
  END IF;
END $$

CALL adena_safe_patch_store_receive_v2() $$
DROP PROCEDURE IF EXISTS adena_safe_patch_store_receive_v2 $$

DELIMITER ;

CREATE TABLE IF NOT EXISTS stock_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  location_code VARCHAR(40) NOT NULL,
  location_name VARCHAR(160) NOT NULL,
  location_type ENUM('kitchen','store','branch') NOT NULL DEFAULT 'branch',
  branch_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_stock_locations_code (location_code),
  KEY idx_stock_locations_type (location_type,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_transfers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  transfer_no VARCHAR(60) NOT NULL,
  from_location_id INT NOT NULL,
  to_location_id INT NOT NULL,
  status ENUM('draft','sent','accepted','rejected','cancelled') NOT NULL DEFAULT 'draft',
  sent_at TIMESTAMP NULL DEFAULT NULL,
  accepted_at TIMESTAMP NULL DEFAULT NULL,
  rejected_at TIMESTAMP NULL DEFAULT NULL,
  created_by INT NULL,
  sent_by INT NULL,
  received_by INT NULL,
  notes TEXT NULL,
  receiver_notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_stock_transfer_no (transfer_no),
  KEY idx_stock_transfer_status (status,from_location_id,to_location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  transfer_id BIGINT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(18,4) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(18,2) NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_transfer_items_transfer (transfer_id),
  KEY idx_transfer_items_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
