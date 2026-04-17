-- Additive migration: role/permission + sales revision

CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(50) NOT NULL,
  role_name VARCHAR(100) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_role_key (role_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  role_id INT NOT NULL,
  menu_key VARCHAR(80) NOT NULL,
  can_view TINYINT(1) NOT NULL DEFAULT 0,
  can_create TINYINT(1) NOT NULL DEFAULT 0,
  can_edit TINYINT(1) NOT NULL DEFAULT 0,
  can_delete TINYINT(1) NOT NULL DEFAULT 0,
  can_print TINYINT(1) NOT NULL DEFAULT 0,
  can_export TINYINT(1) NOT NULL DEFAULT 0,
  can_approve TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_role_menu (role_id, menu_key),
  KEY idx_menu (menu_key)
) ENGINE=InnoDB;

ALTER TABLE users ADD COLUMN role_id INT NULL AFTER role;
ALTER TABLE users ADD KEY idx_role_id (role_id);

ALTER TABLE sales ADD COLUMN base_sale_code VARCHAR(60) NULL AFTER transaction_code;
ALTER TABLE sales ADD COLUMN revision_suffix VARCHAR(10) NULL AFTER base_sale_code;
ALTER TABLE sales ADD COLUMN revision_no INT NOT NULL DEFAULT 0 AFTER revision_suffix;
ALTER TABLE sales ADD COLUMN is_active_revision TINYINT(1) NOT NULL DEFAULT 1 AFTER revision_no;
ALTER TABLE sales ADD COLUMN revised_from_sale_id INT NULL AFTER is_active_revision;
ALTER TABLE sales ADD COLUMN revision_reason_category VARCHAR(120) NULL AFTER revised_from_sale_id;
ALTER TABLE sales ADD COLUMN revision_reason_text TEXT NULL AFTER revision_reason_category;
ALTER TABLE sales ADD COLUMN revised_by_user_id INT NULL AFTER revision_reason_text;
ALTER TABLE sales ADD COLUMN revised_at DATETIME NULL AFTER revised_by_user_id;
ALTER TABLE sales ADD COLUMN revision_status VARCHAR(30) NOT NULL DEFAULT 'active' AFTER revised_at;
ALTER TABLE sales ADD COLUMN original_sale_id INT NULL AFTER revision_status;
ALTER TABLE sales ADD KEY idx_sales_revision_active (is_active_revision, sold_at);
ALTER TABLE sales ADD KEY idx_sales_revision_base (base_sale_code, revision_no);

INSERT INTO roles (role_key, role_name, is_system, is_active) VALUES
('owner','Owner',1,1),('admin','Admin',1,1),('manager','Manager',1,1),('kasir','Kasir',1,1),('gudang','Gudang',1,1)
ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), is_system=VALUES(is_system), is_active=1;

UPDATE users SET role='owner' WHERE role='superadmin';
UPDATE users SET role='kasir' WHERE role IN ('user','pegawai') OR role IS NULL OR role='';

UPDATE users u
JOIN roles r ON r.role_key=u.role
SET u.role_id=r.id
WHERE u.role_id IS NULL;

UPDATE sales SET base_sale_code=COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-',id)) WHERE base_sale_code IS NULL OR base_sale_code='';
UPDATE sales SET is_active_revision=1 WHERE is_active_revision IS NULL;
UPDATE sales SET revision_status='active' WHERE revision_status IS NULL OR revision_status='';
UPDATE sales SET original_sale_id=id WHERE original_sale_id IS NULL;
