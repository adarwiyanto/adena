-- Patch: role_id as single source of truth untuk sistem role
-- Aman dijalankan berulang (idempotent secara praktis di MySQL modern)

START TRANSACTION;

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

INSERT INTO roles (role_key, role_name, is_system, is_active)
VALUES
  ('owner', 'Owner', 1, 1),
  ('admin', 'Admin', 1, 1),
  ('manager', 'Manager', 1, 1),
  ('kasir', 'Kasir', 1, 1),
  ('gudang', 'Gudang', 1, 1)
ON DUPLICATE KEY UPDATE
  role_name = VALUES(role_name),
  is_system = VALUES(is_system),
  is_active = 1;

ALTER TABLE users ADD COLUMN IF NOT EXISTS role_id INT NULL AFTER role;
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_role_id (role_id);

-- Prioritaskan role_id valid yang sudah ada
UPDATE users u
JOIN roles r ON r.id = u.role_id
SET u.role = r.role_key;

-- Backfill role_id hanya untuk data yang belum punya role_id valid
UPDATE users u
JOIN roles r ON r.role_key = (
  CASE LOWER(TRIM(COALESCE(u.role, '')))
    WHEN 'superadmin' THEN 'owner'
    WHEN 'owner' THEN 'owner'
    WHEN 'admin' THEN 'admin'
    WHEN 'manager' THEN 'manager'
    WHEN 'gudang' THEN 'gudang'
    WHEN 'kasir' THEN 'kasir'
    WHEN 'pegawai' THEN 'kasir'
    WHEN 'user' THEN 'kasir'
    ELSE 'kasir'
  END
)
LEFT JOIN roles rv ON rv.id = u.role_id
SET u.role_id = r.id,
    u.role = r.role_key
WHERE rv.id IS NULL;

-- Cegah role pegawai/user tetap aktif di kolom legacy
UPDATE users SET role = 'kasir' WHERE role IN ('pegawai', 'user', 'superadmin') OR role IS NULL OR role = '';

-- Tambahkan FK (akan diabaikan jika sudah ada / nama konflik)
ALTER TABLE users
  ADD CONSTRAINT fk_users_role_id
  FOREIGN KEY (role_id) REFERENCES roles(id)
  ON UPDATE CASCADE
  ON DELETE RESTRICT;

COMMIT;
