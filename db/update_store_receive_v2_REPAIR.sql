-- Patch REPAIR/FIX: melengkapi schema Transfer Stok + Penerimaan Stok.
-- Aman dijalankan ulang. Dipakai bila update_store_receive_v2.sql sempat berhenti di tengah.

DELIMITER $$

DROP PROCEDURE IF EXISTS adena_store_receive_v2_repair $$
CREATE PROCEDURE adena_store_receive_v2_repair()
BEGIN
  -- purchase umum: produk boleh kosong
  IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_headers') THEN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_headers' AND COLUMN_NAME='purchase_type') THEN
      ALTER TABLE purchase_headers ADD COLUMN purchase_type ENUM('raw_material','general') NOT NULL DEFAULT 'raw_material' AFTER purchase_date;
    END IF;
  END IF;

  IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_items') THEN
    IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_items' AND COLUMN_NAME='product_id' AND IS_NULLABLE='NO') THEN
      ALTER TABLE purchase_items MODIFY product_id INT NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_items' AND COLUMN_NAME='item_name') THEN
      ALTER TABLE purchase_items ADD COLUMN item_name VARCHAR(190) NULL AFTER product_id;
    END IF;
  END IF;

  -- stock_locations: buat/repair kolom yang kurang
  CREATE TABLE IF NOT EXISTS stock_locations (
    id INT NOT NULL AUTO_INCREMENT,
    location_code VARCHAR(40) NOT NULL,
    location_name VARCHAR(160) NOT NULL,
    location_type ENUM('kitchen','store','branch') NOT NULL DEFAULT 'branch',
    branch_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_locations' AND COLUMN_NAME='location_code') THEN
    ALTER TABLE stock_locations ADD COLUMN location_code VARCHAR(40) NOT NULL AFTER id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_locations' AND COLUMN_NAME='location_name') THEN
    ALTER TABLE stock_locations ADD COLUMN location_name VARCHAR(160) NOT NULL AFTER location_code;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_locations' AND COLUMN_NAME='location_type') THEN
    ALTER TABLE stock_locations ADD COLUMN location_type ENUM('kitchen','store','branch') NOT NULL DEFAULT 'branch' AFTER location_name;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_locations' AND COLUMN_NAME='branch_id') THEN
    ALTER TABLE stock_locations ADD COLUMN branch_id INT NULL AFTER location_type;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_locations' AND COLUMN_NAME='is_active') THEN
    ALTER TABLE stock_locations ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER branch_id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_locations' AND COLUMN_NAME='created_at') THEN
    ALTER TABLE stock_locations ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_locations' AND COLUMN_NAME='updated_at') THEN
    ALTER TABLE stock_locations ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
  END IF;

  -- stock_transfers
  CREATE TABLE IF NOT EXISTS stock_transfers (
    id BIGINT NOT NULL AUTO_INCREMENT,
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
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='transfer_no') THEN
    ALTER TABLE stock_transfers ADD COLUMN transfer_no VARCHAR(60) NOT NULL AFTER id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='from_location_id') THEN
    ALTER TABLE stock_transfers ADD COLUMN from_location_id INT NOT NULL AFTER transfer_no;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='to_location_id') THEN
    ALTER TABLE stock_transfers ADD COLUMN to_location_id INT NOT NULL AFTER from_location_id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='status') THEN
    ALTER TABLE stock_transfers ADD COLUMN status ENUM('draft','sent','accepted','rejected','cancelled') NOT NULL DEFAULT 'draft' AFTER to_location_id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='sent_at') THEN
    ALTER TABLE stock_transfers ADD COLUMN sent_at TIMESTAMP NULL DEFAULT NULL AFTER status;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='accepted_at') THEN
    ALTER TABLE stock_transfers ADD COLUMN accepted_at TIMESTAMP NULL DEFAULT NULL AFTER sent_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='rejected_at') THEN
    ALTER TABLE stock_transfers ADD COLUMN rejected_at TIMESTAMP NULL DEFAULT NULL AFTER accepted_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='created_by') THEN
    ALTER TABLE stock_transfers ADD COLUMN created_by INT NULL AFTER rejected_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='sent_by') THEN
    ALTER TABLE stock_transfers ADD COLUMN sent_by INT NULL AFTER created_by;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='received_by') THEN
    ALTER TABLE stock_transfers ADD COLUMN received_by INT NULL AFTER sent_by;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='notes') THEN
    ALTER TABLE stock_transfers ADD COLUMN notes TEXT NULL AFTER received_by;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='receiver_notes') THEN
    ALTER TABLE stock_transfers ADD COLUMN receiver_notes TEXT NULL AFTER notes;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='created_at') THEN
    ALTER TABLE stock_transfers ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER receiver_notes;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='updated_at') THEN
    ALTER TABLE stock_transfers ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
  END IF;

  -- stock_transfer_items
  CREATE TABLE IF NOT EXISTS stock_transfer_items (
    id BIGINT NOT NULL AUTO_INCREMENT,
    transfer_id BIGINT NOT NULL,
    product_id INT NOT NULL,
    qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    unit_cost DECIMAL(18,2) NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_items' AND COLUMN_NAME='transfer_id') THEN
    ALTER TABLE stock_transfer_items ADD COLUMN transfer_id BIGINT NOT NULL AFTER id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_items' AND COLUMN_NAME='product_id') THEN
    ALTER TABLE stock_transfer_items ADD COLUMN product_id INT NOT NULL AFTER transfer_id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_items' AND COLUMN_NAME='qty') THEN
    ALTER TABLE stock_transfer_items ADD COLUMN qty DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER product_id;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_items' AND COLUMN_NAME='unit_cost') THEN
    ALTER TABLE stock_transfer_items ADD COLUMN unit_cost DECIMAL(18,2) NULL AFTER qty;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_items' AND COLUMN_NAME='note') THEN
    ALTER TABLE stock_transfer_items ADD COLUMN note VARCHAR(255) NULL AFTER unit_cost;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_items' AND COLUMN_NAME='created_at') THEN
    ALTER TABLE stock_transfer_items ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER note;
  END IF;

  -- stock_ledger harus punya location_id untuk stok per lokasi
  IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_ledger') THEN
    IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_ledger' AND COLUMN_NAME='location_id') THEN
      ALTER TABLE stock_ledger ADD COLUMN location_id INT NULL AFTER branch_id;
    END IF;
  END IF;

  -- isi lokasi dari branches agar dapur/cabang muncul di dropdown
  IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branches') THEN
    INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
    SELECT CONCAT('BR-', b.branch_code), b.branch_name,
           CASE WHEN (b.unit_type='kitchen' OR b.is_kitchen=1) THEN 'kitchen' ELSE 'branch' END,
           b.id, b.is_active
    FROM branches b
    WHERE NOT EXISTS (SELECT 1 FROM stock_locations sl WHERE sl.branch_id=b.id)
      AND NOT EXISTS (SELECT 1 FROM stock_locations sl2 WHERE sl2.location_code=CONCAT('BR-', b.branch_code));
  END IF;

  IF NOT EXISTS (SELECT 1 FROM stock_locations WHERE location_code='KITCHEN') THEN
    INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
    VALUES ('KITCHEN','Dapur Produksi','kitchen',NULL,1);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM stock_locations WHERE location_code='MAIN') THEN
    INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
    VALUES ('MAIN','Toko Utama','store',1,1);
  END IF;
END $$

CALL adena_store_receive_v2_repair() $$
DROP PROCEDURE IF EXISTS adena_store_receive_v2_repair $$

DELIMITER ;

-- Index dibuat di luar procedure dengan toleransi error: abaikan bila sudah ada.
-- Bila phpMyAdmin berhenti karena duplicate key pada bagian ini, struktur tabel utama sudah tetap aman.
SET @dummy := 1;
