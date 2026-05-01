-- Patch multi-cabang dashboard + customer demografi + branch permission
-- Aman dijalankan berulang; beberapa ALTER mungkin menampilkan duplicate column/index warning dan boleh diabaikan bila sudah ada.

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
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'approved',
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

ALTER TABLE branch_stock_inputs MODIFY status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'approved';
ALTER TABLE customers ADD COLUMN domicile VARCHAR(120) NULL AFTER birth_date;
ALTER TABLE customers ADD COLUMN instagram VARCHAR(120) NULL AFTER domicile;
ALTER TABLE users MODIFY role ENUM('owner','admin','manager','manager_cabang','pegawai_cabang','kasir','gudang','user','pegawai') NOT NULL DEFAULT 'admin';

INSERT INTO roles (role_key, role_name, is_system, is_active)
VALUES ('manager_cabang','Manager Cabang',1,1), ('pegawai_cabang','Pegawai Cabang',1,1)
ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), is_system=VALUES(is_system), is_active=1;

UPDATE roles SET role_key='manager_cabang', role_name='Manager Cabang' WHERE role_key='manager';
UPDATE users SET role='manager_cabang' WHERE role='manager';
UPDATE users u JOIN roles r ON r.role_key='manager_cabang' SET u.role_id=r.id WHERE u.role='manager_cabang';

INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
SELECT id, 'branch_page', 1,1,0,0,0,1,0 FROM roles WHERE role_key='manager_cabang'
ON DUPLICATE KEY UPDATE can_view=1, can_create=1, can_edit=0, can_delete=0, can_print=0, can_export=1, can_approve=0;

INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
SELECT id, 'branch_page', 1,1,0,0,0,0,0 FROM roles WHERE role_key='pegawai_cabang'
ON DUPLICATE KEY UPDATE can_view=1, can_create=1, can_edit=0, can_delete=0, can_print=0, can_export=0, can_approve=0;

INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
SELECT id, 'branch_page', 1,1,1,0,0,1,0 FROM roles WHERE role_key='admin'
ON DUPLICATE KEY UPDATE can_view=1, can_create=1, can_edit=1, can_export=1;
