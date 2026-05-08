-- Adena POS/Web 1.4: discount, pending order, editable price items, locations, initial stock, transfer approval.
-- Safe to run repeatedly; individual ALTER may warn if column already exists.

ALTER TABLE products ADD COLUMN is_price_editable TINYINT(1) NOT NULL DEFAULT 0 AFTER track_stock;
ALTER TABLE products ADD COLUMN include_in_sales_report TINYINT(1) NOT NULL DEFAULT 1 AFTER is_price_editable;
ALTER TABLE products ADD COLUMN kitchen_price DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER price;
ALTER TABLE products ADD COLUMN min_stock_level DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER kitchen_price;

ALTER TABLE sales ADD COLUMN discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER total;
ALTER TABLE sales ADD COLUMN discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed' AFTER discount_amount;
ALTER TABLE sales ADD COLUMN tx_discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER discount_type;
ALTER TABLE sales ADD COLUMN tx_discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed' AFTER tx_discount_amount;
ALTER TABLE sales ADD COLUMN include_in_sales_report TINYINT(1) NOT NULL DEFAULT 1 AFTER tx_discount_type;
ALTER TABLE sales ADD COLUMN line_subtotal DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER include_in_sales_report;
ALTER TABLE sales ADD COLUMN line_net_total DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER line_subtotal;
ALTER TABLE sales ADD COLUMN pending_order_id BIGINT NULL AFTER line_net_total;

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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS initial_stock_entries (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  location_id INT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(18,4) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(18,2) NULL,
  status ENUM('posted','owner_override_requested','owner_override_approved','void') NOT NULL DEFAULT 'posted',
  note TEXT NULL,
  created_by INT NULL,
  approved_by INT NULL,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_initial_stock_once (location_id,product_id),
  KEY idx_initial_stock_location (location_id,status)
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pos_pending_orders (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  local_pending_id VARCHAR(120) NOT NULL,
  pending_code VARCHAR(80) NULL,
  cashier_id INT NULL,
  branch_id INT NULL,
  customer_name VARCHAR(160) NULL,
  note TEXT NULL,
  subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
  total DECIMAL(18,2) NOT NULL DEFAULT 0,
  status ENUM('pending','paid','deleted') NOT NULL DEFAULT 'pending',
  payload_json LONGTEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_pos_pending_local (local_pending_id),
  KEY idx_pos_pending_status (status,branch_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pos_pending_order_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  pending_order_id BIGINT NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(190) NULL,
  qty DECIMAL(18,4) NOT NULL DEFAULT 0,
  price_each DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_type VARCHAR(20) NOT NULL DEFAULT 'fixed',
  total DECIMAL(18,2) NOT NULL DEFAULT 0,
  include_in_sales_report TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_pending_items_order (pending_order_id)
) ENGINE=InnoDB;

INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
SELECT 'KITCHEN','Dapur Produksi','kitchen',NULL,1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM stock_locations WHERE location_code='KITCHEN');

INSERT INTO stock_locations (location_code,location_name,location_type,branch_id,is_active)
SELECT 'MAIN','Toko Utama','store',1,1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM stock_locations WHERE location_code='MAIN');

UPDATE sales SET line_subtotal = qty * price_each WHERE line_subtotal = 0 OR line_subtotal IS NULL;
UPDATE sales SET line_net_total = total WHERE line_net_total = 0 OR line_net_total IS NULL;
