-- Penambahan fitur: role tambahan, KPI kinerja dapur, lokasi kerja geotagging absensi.
-- Sifat: additive/minim perubahan agar DB existing tetap stabil.

-- 1) Tambah role pegawai_dapur jika belum ada di enum users.role
SET @has_role_pegawai_dapur := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND column_name = 'role'
    AND column_type LIKE "%pegawai_dapur%"
);
SET @sql := IF(@has_role_pegawai_dapur = 0,
  "ALTER TABLE users MODIFY role ENUM('owner','admin','pegawai','pegawai_pos','pegawai_non_pos','manager_toko','pegawai_dapur') NOT NULL DEFAULT 'admin'",
  "SELECT 1"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Tabel KPI dapur
CREATE TABLE IF NOT EXISTS kitchen_kpi_activities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  activity_name VARCHAR(160) NOT NULL,
  point_value INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_activity_name (activity_name),
  KEY idx_created_by (created_by),
  CONSTRAINT fk_kitchen_kpi_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 3) Tabel lokasi kerja
CREATE TABLE IF NOT EXISTS work_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  latitude DECIMAL(10,7) NOT NULL,
  longitude DECIMAL(10,7) NOT NULL,
  radius_meters INT NOT NULL DEFAULT 150,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_name (name)
) ENGINE=InnoDB;

-- 4) Kolom geotag absensi (additive)
SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_attendance' AND column_name='checkin_latitude');
SET @sql := IF(@c=0, "ALTER TABLE employee_attendance ADD COLUMN checkin_latitude DECIMAL(10,7) NULL AFTER checkin_device_info", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_attendance' AND column_name='checkin_longitude');
SET @sql := IF(@c=0, "ALTER TABLE employee_attendance ADD COLUMN checkin_longitude DECIMAL(10,7) NULL AFTER checkin_latitude", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_attendance' AND column_name='checkout_latitude');
SET @sql := IF(@c=0, "ALTER TABLE employee_attendance ADD COLUMN checkout_latitude DECIMAL(10,7) NULL AFTER checkout_device_info", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_attendance' AND column_name='checkout_longitude');
SET @sql := IF(@c=0, "ALTER TABLE employee_attendance ADD COLUMN checkout_longitude DECIMAL(10,7) NULL AFTER checkout_latitude", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_attendance' AND column_name='checkin_location_name');
SET @sql := IF(@c=0, "ALTER TABLE employee_attendance ADD COLUMN checkin_location_name VARCHAR(120) NULL AFTER checkin_longitude", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='employee_attendance' AND column_name='checkout_location_name');
SET @sql := IF(@c=0, "ALTER TABLE employee_attendance ADD COLUMN checkout_location_name VARCHAR(120) NULL AFTER checkout_longitude", "SELECT 1");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
