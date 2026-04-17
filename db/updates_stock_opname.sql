-- Stock module additive migration (safe / backward-compatible)

ALTER TABLE products
  ADD COLUMN reorder_level DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER track_stock;

CREATE TABLE IF NOT EXISTS stock_opname_headers (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  opname_no VARCHAR(80) NOT NULL,
  branch_id INT NOT NULL,
  opname_date DATE NOT NULL,
  status ENUM('draft','waiting_approval','approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  approval_note TEXT NULL,
  created_by INT NOT NULL,
  approved_by INT NULL,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_stock_opname_no (opname_no),
  KEY idx_stock_opname_branch_status_date (branch_id,status,opname_date),
  KEY idx_stock_opname_created_by (created_by),
  KEY idx_stock_opname_approved_by (approved_by),
  CONSTRAINT fk_stock_opname_headers_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_opname_headers_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_stock_opname_headers_approver FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_opname_items (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  opname_id BIGINT NOT NULL,
  product_id INT NOT NULL,
  system_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
  physical_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
  variance_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
  variance_type ENUM('plus','minus','zero') NOT NULL DEFAULT 'zero',
  reason_note VARCHAR(255) NULL,
  line_note VARCHAR(255) NULL,
  warning_flag TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_stock_opname_item (opname_id,product_id),
  KEY idx_stock_opname_item_product (product_id),
  CONSTRAINT fk_stock_opname_items_header FOREIGN KEY (opname_id) REFERENCES stock_opname_headers(id) ON DELETE CASCADE,
  CONSTRAINT fk_stock_opname_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
