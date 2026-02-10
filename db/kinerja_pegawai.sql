-- Update DB aman untuk fitur kinerja/absensi pegawai.
-- Prinsip: additive only (tanpa drop/ubah data stabil).

-- 1) Index untuk filter role user (dipakai halaman user/jadwal/rekap).
SET @idx_users_role_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND index_name = 'idx_users_role'
);
SET @sql_users_role := IF(
  @idx_users_role_exists = 0,
  'ALTER TABLE users ADD INDEX idx_users_role (role)',
  'SELECT 1'
);
PREPARE stmt_users_role FROM @sql_users_role;
EXECUTE stmt_users_role;
DEALLOCATE PREPARE stmt_users_role;

-- 2) Index gabungan untuk rekap absensi per user + tanggal.
SET @idx_attendance_user_date_exists := (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_attendance'
    AND index_name = 'idx_attendance_user_date'
);
SET @sql_attendance_user_date := IF(
  @idx_attendance_user_date_exists = 0,
  'ALTER TABLE employee_attendance ADD INDEX idx_attendance_user_date (user_id, attend_date)',
  'SELECT 1'
);
PREPARE stmt_attendance_user_date FROM @sql_attendance_user_date;
EXECUTE stmt_attendance_user_date;
DEALLOCATE PREPARE stmt_attendance_user_date;
