-- Migration: Add shift open/close permissions per role
-- Buka shift = can_create, Tutup shift = can_delete on menu_key 'shift'

-- Owner: buka dan tutup shift
INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
SELECT r.id, 'shift', 1, 1, 0, 1, 0, 0, 0
FROM roles r WHERE r.role_key = 'owner'
ON DUPLICATE KEY UPDATE can_view=1, can_create=1, can_delete=1;

-- Admin: buka dan tutup shift
INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
SELECT r.id, 'shift', 1, 1, 0, 1, 0, 0, 0
FROM roles r WHERE r.role_key = 'admin'
ON DUPLICATE KEY UPDATE can_view=1, can_create=1, can_delete=1;

-- Kasir: buka dan tutup shift
INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
SELECT r.id, 'shift', 1, 1, 0, 1, 0, 0, 0
FROM roles r WHERE r.role_key = 'kasir'
ON DUPLICATE KEY UPDATE can_view=1, can_create=1, can_delete=1;

-- Manager: tidak ada akses shift (default 0)
INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
SELECT r.id, 'shift', 0, 0, 0, 0, 0, 0, 0
FROM roles r WHERE r.role_key = 'manager'
ON DUPLICATE KEY UPDATE can_create=0, can_delete=0;

-- Gudang: tidak ada akses shift (default 0)
INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
SELECT r.id, 'shift', 0, 0, 0, 0, 0, 0, 0
FROM roles r WHERE r.role_key = 'gudang'
ON DUPLICATE KEY UPDATE can_create=0, can_delete=0;
