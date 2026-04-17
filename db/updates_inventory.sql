-- Inventory / BOM / Purchase / Production (additive)
ALTER TABLE products
  ADD COLUMN product_type ENUM('finished_good','raw_material','service') NOT NULL DEFAULT 'finished_good' AFTER price,
  ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 1 AFTER product_type,
  ADD COLUMN allow_direct_purchase TINYINT(1) NOT NULL DEFAULT 0 AFTER track_stock,
  ADD COLUMN allow_bom TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_direct_purchase,
  ADD KEY idx_product_type (product_type);

CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_code VARCHAR(40) NOT NULL,
  branch_name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_branch_code (branch_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  supplier_code VARCHAR(40) NOT NULL,
  supplier_name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  address TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_supplier_code (supplier_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_headers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  supplier_id INT NOT NULL,
  purchase_no VARCHAR(50) NOT NULL,
  purchase_date DATE NOT NULL,
  status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  subtotal DECIMAL(18,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  tax_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(18,2) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_by INT NULL,
  posted_by INT NULL,
  posted_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_purchase_no (purchase_no),
  KEY idx_purchase_branch_status_date (branch_id,status,purchase_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(18,4) NOT NULL,
  unit_cost DECIMAL(18,2) NOT NULL,
  line_total DECIMAL(18,2) NOT NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_purchase_items_purchase (purchase_id),
  KEY idx_purchase_items_product (product_id),
  CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchase_headers(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_revision_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT NOT NULL,
  purchase_no VARCHAR(50) NOT NULL,
  edited_by INT NULL,
  edited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  edit_reason TEXT NULL,
  snapshot_before LONGTEXT NULL,
  snapshot_after LONGTEXT NULL,
  change_summary LONGTEXT NULL,
  KEY idx_purchase_revision_purchase (purchase_id, edited_at),
  KEY idx_purchase_revision_no (purchase_no)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bom_headers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NULL,
  finished_product_id INT NOT NULL,
  bom_code VARCHAR(50) NOT NULL,
  bom_name VARCHAR(160) NOT NULL,
  yield_qty DECIMAL(18,4) NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_bom_code (bom_code),
  KEY idx_bom_finished_active (finished_product_id,is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bom_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bom_id INT NOT NULL,
  material_product_id INT NOT NULL,
  qty_per_yield DECIMAL(18,4) NOT NULL,
  unit_note VARCHAR(120) NULL,
  wastage_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_bom_material (bom_id,material_product_id),
  KEY idx_bom_items_lookup (bom_id,material_product_id),
  CONSTRAINT fk_bom_items_header FOREIGN KEY (bom_id) REFERENCES bom_headers(id) ON DELETE CASCADE,
  CONSTRAINT fk_bom_items_material FOREIGN KEY (material_product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_headers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  production_no VARCHAR(50) NOT NULL,
  branch_id INT NOT NULL,
  bom_id INT NOT NULL,
  finished_product_id INT NOT NULL,
  production_date DATE NOT NULL,
  qty_to_produce DECIMAL(18,4) NOT NULL,
  status ENUM('draft','posted','cancelled') NOT NULL DEFAULT 'draft',
  mode_source ENUM('manual_menu','pos_auto') NOT NULL DEFAULT 'manual_menu',
  notes TEXT NULL,
  created_by INT NULL,
  posted_by INT NULL,
  posted_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_production_no (production_no),
  KEY idx_production_branch_status_date (branch_id,status,production_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  production_id INT NOT NULL,
  material_product_id INT NOT NULL,
  required_qty DECIMAL(18,4) NOT NULL,
  actual_qty DECIMAL(18,4) NOT NULL,
  unit_cost DECIMAL(18,2) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_production_items_prod (production_id),
  KEY idx_production_items_material (material_product_id),
  CONSTRAINT fk_production_items_header FOREIGN KEY (production_id) REFERENCES production_headers(id) ON DELETE CASCADE,
  CONSTRAINT fk_production_items_material FOREIGN KEY (material_product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_ledger (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  product_id INT NOT NULL,
  trans_type VARCHAR(60) NOT NULL,
  ref_table VARCHAR(60) NOT NULL,
  ref_id BIGINT NOT NULL,
  qty_in DECIMAL(18,4) NOT NULL DEFAULT 0,
  qty_out DECIMAL(18,4) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(18,2) NULL,
  note VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_stock_ledger_main (branch_id,product_id,created_at)
) ENGINE=InnoDB;

ALTER TABLE sales ADD COLUMN branch_id INT NULL AFTER product_id;

CREATE TABLE IF NOT EXISTS sales_production_links (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  transaction_code VARCHAR(40) NOT NULL,
  production_id INT NOT NULL,
  branch_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sales_prod_tx (transaction_code),
  KEY idx_sales_prod_prod (production_id)
) ENGINE=InnoDB;

INSERT INTO settings (`key`,`value`) VALUES
('production_mode','auto'),
('pos_autoproduction_enabled','1'),
('pos_autoproduction_allow_negative','0'),
('bom_require_exact_material_stock','1'),
('purchase_raw_material_only','1'),
('active_branch_id','1')
ON DUPLICATE KEY UPDATE `value`=`value`;
