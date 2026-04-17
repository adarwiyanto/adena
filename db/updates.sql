-- Tambahan kolom untuk pembayaran & retur transaksi
ALTER TABLE sales
  ADD COLUMN transaction_code VARCHAR(40) NULL AFTER id,
  ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER total,
  ADD COLUMN payment_proof_path VARCHAR(255) NULL AFTER payment_method,
  ADD COLUMN return_reason VARCHAR(255) NULL AFTER payment_proof_path,
  ADD COLUMN returned_at TIMESTAMP NULL DEFAULT NULL AFTER return_reason;

-- Perubahan role superadmin menjadi owner + undangan user
UPDATE users SET role='owner' WHERE role='superadmin';
ALTER TABLE users
  MODIFY role ENUM('owner','admin','user','pegawai') NOT NULL DEFAULT 'admin';

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

ALTER TABLE users
  ADD COLUMN email VARCHAR(190) NULL AFTER username,
  ADD COLUMN avatar_path VARCHAR(255) NULL AFTER role;

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

ALTER TABLE customers
  ADD COLUMN phone VARCHAR(30) NULL AFTER name,
  ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0 AFTER phone,
  ADD COLUMN loyalty_remainder INT NOT NULL DEFAULT 0 AFTER loyalty_points;
ALTER TABLE customers
  ADD UNIQUE KEY uniq_phone (phone);

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
