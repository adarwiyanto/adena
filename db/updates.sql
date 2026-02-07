-- Tambahan kolom untuk pembayaran & retur transaksi
-- Idempotent add column: sales.transaction_code
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'sales' AND column_name = 'transaction_code'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE sales ADD COLUMN transaction_code VARCHAR(40) NULL AFTER id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Idempotent add column: sales.payment_method
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'sales' AND column_name = 'payment_method'
);
SET @sql := IF(@exists = 0, "ALTER TABLE sales ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER total", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Idempotent add column: sales.payment_proof_path
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'sales' AND column_name = 'payment_proof_path'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE sales ADD COLUMN payment_proof_path VARCHAR(255) NULL AFTER payment_method', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Idempotent add column: sales.return_reason
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'sales' AND column_name = 'return_reason'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE sales ADD COLUMN return_reason VARCHAR(255) NULL AFTER payment_proof_path', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Idempotent add column: sales.returned_at
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'sales' AND column_name = 'returned_at'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE sales ADD COLUMN returned_at TIMESTAMP NULL DEFAULT NULL AFTER return_reason', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Perubahan role superadmin menjadi owner + undangan user
UPDATE users SET role='owner' WHERE role='superadmin';
UPDATE users SET role='pegawai' WHERE role='user';
ALTER TABLE users
  MODIFY role ENUM('owner','admin','pegawai') NOT NULL DEFAULT 'admin';

CREATE TABLE IF NOT EXISTS user_invites (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  role VARCHAR(30) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  used_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_token_hash (token_hash),
  KEY idx_email (email)
) ENGINE=InnoDB;

-- Idempotent add column: users.email
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'users' AND column_name = 'email'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL AFTER username', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Idempotent add column: users.avatar_path
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'users' AND column_name = 'avatar_path'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER role', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  used_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_token_hash (token_hash),
  KEY idx_user_id (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Idempotent add column: customers.phone
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'customers' AND column_name = 'phone'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE customers ADD COLUMN phone VARCHAR(30) NULL AFTER name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Idempotent add column: customers.loyalty_points
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'customers' AND column_name = 'loyalty_points'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE customers ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0 AFTER phone', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Idempotent add column: customers.loyalty_remainder
SET @db := DATABASE();
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = @db AND table_name = 'customers' AND column_name = 'loyalty_remainder'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE customers ADD COLUMN loyalty_remainder INT NOT NULL DEFAULT 0 AFTER loyalty_points', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Idempotent add index: customers.uniq_phone
SET @db := DATABASE();
SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = @db AND table_name = 'customers' AND index_name = 'uniq_phone'
);
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE customers ADD UNIQUE KEY uniq_phone (phone)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_code VARCHAR(40) NOT NULL,
  customer_id INT NOT NULL,
  status ENUM('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_status (status),
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  qty INT NOT NULL DEFAULT 1,
  price_each DECIMAL(15,2) NOT NULL DEFAULT 0,
  subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO settings (`key`,`value`) VALUES ('recaptcha_site_key','')
  ON DUPLICATE KEY UPDATE `value`=`value`;
INSERT INTO settings (`key`,`value`) VALUES ('recaptcha_secret_key','')
  ON DUPLICATE KEY UPDATE `value`=`value`;
INSERT INTO settings (`key`,`value`) VALUES ('loyalty_points_per_order','0')
  ON DUPLICATE KEY UPDATE `value`=`value`;
INSERT INTO settings (`key`,`value`) VALUES ('loyalty_point_value','0')
  ON DUPLICATE KEY UPDATE `value`=`value`;
INSERT INTO settings (`key`,`value`) VALUES ('loyalty_remainder_mode','discard')
  ON DUPLICATE KEY UPDATE `value`=`value`;

CREATE TABLE IF NOT EXISTS loyalty_rewards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  points_required INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_product (product_id),
  KEY idx_points (points_required),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Attendance + employee role POS/non-POS + schedule
ALTER TABLE users
  MODIFY role ENUM('owner','admin','pegawai','pegawai_pos','pegawai_non_pos') NOT NULL DEFAULT 'admin';

CREATE TABLE IF NOT EXISTS employee_attendance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  attend_date DATE NOT NULL,
  checkin_time DATETIME NULL,
  checkout_time DATETIME NULL,
  checkin_photo_path VARCHAR(255) NULL,
  checkout_photo_path VARCHAR(255) NULL,
  checkin_device_info VARCHAR(255) NULL,
  checkout_device_info VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_date (user_id, attend_date),
  KEY idx_attend_date (attend_date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employee_schedule_weekly (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  weekday TINYINT NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  grace_minutes INT NOT NULL DEFAULT 0,
  is_off TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_weekday (user_id, weekday),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employee_schedule_overrides (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  schedule_date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  grace_minutes INT NOT NULL DEFAULT 0,
  is_off TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_date (user_id, schedule_date),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE orders
  MODIFY status ENUM('pending','processing','completed','cancelled','pending_payment','unpaid') NOT NULL DEFAULT 'pending';

-- Payroll-ready attendance/schedule additive columns (idempotent)
SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_schedule_weekly' AND column_name = 'allow_checkin_before_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_schedule_weekly ADD COLUMN allow_checkin_before_minutes INT NOT NULL DEFAULT 0 AFTER grace_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_schedule_weekly' AND column_name = 'overtime_before_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_schedule_weekly ADD COLUMN overtime_before_minutes INT NOT NULL DEFAULT 0 AFTER allow_checkin_before_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_schedule_weekly' AND column_name = 'overtime_after_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_schedule_weekly ADD COLUMN overtime_after_minutes INT NOT NULL DEFAULT 0 AFTER overtime_before_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_schedule_overrides' AND column_name = 'allow_checkin_before_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_schedule_overrides ADD COLUMN allow_checkin_before_minutes INT NOT NULL DEFAULT 0 AFTER grace_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_schedule_overrides' AND column_name = 'overtime_before_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_schedule_overrides ADD COLUMN overtime_before_minutes INT NOT NULL DEFAULT 0 AFTER allow_checkin_before_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_schedule_overrides' AND column_name = 'overtime_after_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_schedule_overrides ADD COLUMN overtime_after_minutes INT NOT NULL DEFAULT 0 AFTER overtime_before_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_attendance' AND column_name = 'checkin_status'
);
SET @sql := IF(@exists = 0, "ALTER TABLE employee_attendance ADD COLUMN checkin_status ENUM('ontime','late','early','invalid_window','off','unscheduled','absent') NULL AFTER checkout_device_info", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_attendance' AND column_name = 'checkout_status'
);
SET @sql := IF(@exists = 0, "ALTER TABLE employee_attendance ADD COLUMN checkout_status ENUM('normal','early_leave','missing','off','unscheduled') NULL AFTER checkin_status", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_attendance' AND column_name = 'late_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_attendance ADD COLUMN late_minutes INT NOT NULL DEFAULT 0 AFTER checkout_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_attendance' AND column_name = 'early_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_attendance ADD COLUMN early_minutes INT NOT NULL DEFAULT 0 AFTER late_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_attendance' AND column_name = 'overtime_before_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_attendance ADD COLUMN overtime_before_minutes INT NOT NULL DEFAULT 0 AFTER early_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_attendance' AND column_name = 'overtime_after_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_attendance ADD COLUMN overtime_after_minutes INT NOT NULL DEFAULT 0 AFTER overtime_before_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_attendance' AND column_name = 'work_minutes'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_attendance ADD COLUMN work_minutes INT NOT NULL DEFAULT 0 AFTER overtime_after_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'employee_attendance' AND column_name = 'early_checkout_reason'
);
SET @sql := IF(@exists = 0, 'ALTER TABLE employee_attendance ADD COLUMN early_checkout_reason VARCHAR(255) NULL AFTER work_minutes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Add manager_toko role + attendance index (idempotent)
SET @has_manager_toko := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'users'
    AND column_name = 'role'
    AND column_type LIKE "%manager_toko%"
);
SET @sql := IF(@has_manager_toko = 0,
  "ALTER TABLE users MODIFY role ENUM('owner','admin','pegawai','pegawai_pos','pegawai_non_pos','manager_toko') NOT NULL DEFAULT 'admin'",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE users SET role='pegawai' WHERE role='user';

SET @idx_exists := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'employee_attendance'
    AND index_name = 'idx_user_attend_date'
);
SET @sql := IF(@idx_exists = 0,
  'ALTER TABLE employee_attendance ADD INDEX idx_user_attend_date (user_id, attend_date)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
