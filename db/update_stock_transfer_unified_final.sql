-- Patch FINAL: unify alur Transfer Stok / Penerimaan Stok.
-- Aman dijalankan ulang. Fokus: gunakan stock_transfers + stock_transfer_items sebagai tabel aktif.

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_transfers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  transfer_no VARCHAR(60) NOT NULL,
  from_location_id INT NOT NULL,
  to_location_id INT NOT NULL,
  status ENUM('draft','sent','accepted','rejected','cancelled') NOT NULL DEFAULT 'draft',
  transfer_type VARCHAR(30) NOT NULL DEFAULT 'stock_transfer',
  sent_at TIMESTAMP NULL DEFAULT NULL,
  accepted_at TIMESTAMP NULL DEFAULT NULL,
  rejected_at TIMESTAMP NULL DEFAULT NULL,
  cancelled_at TIMESTAMP NULL DEFAULT NULL,
  created_by INT NULL,
  sent_by INT NULL,
  received_by INT NULL,
  notes TEXT NULL,
  receiver_notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_stock_transfer_no (transfer_no),
  KEY idx_transfer_from_to_status (from_location_id,to_location_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE PROCEDURE adena_patch_stock_transfer_unified_final()
BEGIN
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

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='transfer_type') THEN
    ALTER TABLE stock_transfers ADD COLUMN transfer_type VARCHAR(30) NOT NULL DEFAULT 'stock_transfer' AFTER status;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='cancelled_at') THEN
    ALTER TABLE stock_transfers ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER rejected_at;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfers' AND COLUMN_NAME='receiver_notes') THEN
    ALTER TABLE stock_transfers ADD COLUMN receiver_notes TEXT NULL AFTER notes;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_items' AND COLUMN_NAME='note') THEN
    ALTER TABLE stock_transfer_items ADD COLUMN note VARCHAR(255) NULL AFTER unit_cost;
  END IF;
  IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_items' AND COLUMN_NAME='notes') THEN
    UPDATE stock_transfer_items SET note = COALESCE(note, notes) WHERE note IS NULL;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_ledger' AND COLUMN_NAME='location_id') THEN
    ALTER TABLE stock_ledger ADD COLUMN location_id INT NULL AFTER branch_id;
  END IF;

  INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
  SELECT 'KITCHEN','Dapur Produksi','kitchen',NULL,1 FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM stock_locations WHERE location_code='KITCHEN');

  INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
  SELECT CONCAT('TOKO-', b.branch_code), b.branch_name,
         CASE WHEN COALESCE(b.is_kitchen,0)=1 OR b.unit_type='dapur' THEN 'kitchen' ELSE 'branch' END,
         b.id, 1
  FROM branches b
  WHERE NOT EXISTS (SELECT 1 FROM stock_locations sl WHERE sl.branch_id=b.id);

  IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_headers') THEN
    INSERT INTO stock_transfers (transfer_no,from_location_id,to_location_id,status,transfer_type,sent_at,accepted_at,cancelled_at,created_by,sent_by,received_by,notes,created_at,updated_at)
    SELECT h.transfer_no,
           COALESCE(sl_from.id, (SELECT id FROM stock_locations ORDER BY id LIMIT 1)),
           COALESCE(sl_to.id, (SELECT id FROM stock_locations ORDER BY id LIMIT 1)),
           CASE h.status WHEN 'received' THEN 'accepted' WHEN 'cancelled' THEN 'cancelled' WHEN 'sent' THEN 'sent' ELSE 'sent' END,
           'legacy_transfer',
           h.sent_at,
           h.received_at,
           CASE WHEN h.status='cancelled' THEN COALESCE(h.updated_at,h.created_at) ELSE NULL END,
           h.created_by,
           h.sent_by,
           h.received_by,
           h.notes,
           h.created_at,
           h.updated_at
    FROM stock_transfer_headers h
    LEFT JOIN stock_locations sl_from ON sl_from.branch_id=h.source_branch_id
    LEFT JOIN stock_locations sl_to ON sl_to.branch_id=h.dest_branch_id
    WHERE NOT EXISTS (SELECT 1 FROM stock_transfers st WHERE st.transfer_no=h.transfer_no);
  END IF;

  IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_headers') THEN
    INSERT INTO stock_transfer_items (transfer_id,product_id,qty,unit_cost,note,created_at)
    SELECT st.id, i.product_id, i.qty, i.unit_cost,
           i.note,
           COALESCE(i.created_at, NOW())
    FROM stock_transfer_items i
    JOIN stock_transfer_headers h ON h.id=i.transfer_id
    JOIN stock_transfers st ON st.transfer_no=h.transfer_no
    WHERE st.transfer_type='legacy_transfer'
      AND NOT EXISTS (
        SELECT 1 FROM stock_transfer_items ni
        WHERE ni.transfer_id=st.id AND ni.product_id=i.product_id AND ni.qty=i.qty
      );
  END IF;
END$$
DELIMITER ;

CALL adena_patch_stock_transfer_unified_final();
DROP PROCEDURE adena_patch_stock_transfer_unified_final;
