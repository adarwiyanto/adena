-- Patch multi-cabang terbatas: halaman cabang + stok masuk pending + blind stock opname.
-- Aman dijalankan berulang; tidak mengubah POS desktop.

CREATE TABLE IF NOT EXISTS user_branches (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  branch_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_branch (user_id, branch_id),
  KEY idx_user_branches_user (user_id),
  KEY idx_user_branches_branch (branch_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS branch_stock_inputs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  input_no VARCHAR(80) NOT NULL,
  branch_id INT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(18,4) NOT NULL,
  unit_cost DECIMAL(18,2) NULL,
  notes TEXT NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  created_by INT NOT NULL,
  approved_by INT NULL,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  approval_note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_branch_stock_input_no (input_no),
  KEY idx_branch_stock_inputs_branch_status (branch_id,status,created_at),
  KEY idx_branch_stock_inputs_product (product_id),
  KEY idx_branch_stock_inputs_created_by (created_by),
  KEY idx_branch_stock_inputs_approved_by (approved_by)
) ENGINE=InnoDB;

INSERT IGNORE INTO user_branches (user_id, branch_id)
SELECT u.id, 1 FROM users u WHERE u.role IN ('admin','pegawai');
