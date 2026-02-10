-- Migrasi non-destruktif: role non-pegawai umum, pengumuman perusahaan, KPI dapur target/realisasi.

-- 1) Role users: hapus role 'pegawai', tambah manager_dapur.
UPDATE users SET role='pegawai_pos' WHERE role='pegawai';
UPDATE users SET role='pegawai_pos' WHERE role='user';
ALTER TABLE users
  MODIFY role ENUM('owner','admin','pegawai_pos','pegawai_non_pos','manager_toko','pegawai_dapur','manager_dapur') NOT NULL DEFAULT 'admin';

-- 2) Tabel pengumuman perusahaan (aktif 24 jam per record).
CREATE TABLE IF NOT EXISTS company_announcements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(190) NOT NULL,
  message TEXT NOT NULL,
  audience ENUM('toko','dapur') NOT NULL DEFAULT 'toko',
  posted_by INT NULL,
  starts_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_audience_expires (audience, expires_at),
  KEY idx_posted_by (posted_by),
  CONSTRAINT fk_company_announcement_user FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 3) Target KPI dapur per pegawai per hari.
CREATE TABLE IF NOT EXISTS kitchen_kpi_targets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  activity_id INT NOT NULL,
  target_date DATE NOT NULL,
  target_qty INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_target (user_id, activity_id, target_date),
  KEY idx_target_date (target_date),
  CONSTRAINT fk_kpi_target_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi_target_activity FOREIGN KEY (activity_id) REFERENCES kitchen_kpi_activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi_target_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4) Realisasi KPI dapur per pegawai per hari.
CREATE TABLE IF NOT EXISTS kitchen_kpi_realizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  activity_id INT NOT NULL,
  realization_date DATE NOT NULL,
  qty INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_realization (user_id, activity_id, realization_date),
  KEY idx_realization_date (realization_date),
  CONSTRAINT fk_kpi_real_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi_real_activity FOREIGN KEY (activity_id) REFERENCES kitchen_kpi_activities(id) ON DELETE CASCADE,
  CONSTRAINT fk_kpi_real_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
