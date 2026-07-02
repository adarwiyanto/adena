-- Patch 20260702 final: label role toko tanpa mengubah alur POS Desktop. Aman dijalankan berulang.
UPDATE users SET role='owner' WHERE role='superadmin';
INSERT INTO roles(role_key, role_name, is_system, is_active) VALUES ('owner','Owner',1,1),('admin','Admin Toko',1,1),('manager_cabang','Manajer Toko',1,1),('kasir','Pegawai Toko',1,1),('gudang','Pegawai Toko',1,1) ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), is_active=1;
UPDATE roles SET role_name='Owner' WHERE role_key='owner';
UPDATE roles SET role_name='Admin Toko' WHERE role_key='admin';
UPDATE roles SET role_name='Manajer Toko' WHERE role_key IN ('manager_cabang','manager');
UPDATE roles SET role_name='Pegawai Toko' WHERE role_key IN ('kasir','gudang','pegawai_cabang','pegawai','user');
UPDATE roles SET is_active=0, role_name='Manajer Toko (lama)' WHERE role_key='manager';
