-- POS shift + offline sync additive schema
CREATE TABLE IF NOT EXISTS pos_shifts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  shift_code VARCHAR(60) NOT NULL,
  branch_id INT NULL,
  opened_at DATETIME NOT NULL,
  opened_by INT NOT NULL,
  opening_cash_default DECIMAL(15,2) NOT NULL DEFAULT 0,
  opening_cash_actual DECIMAL(15,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  closed_at DATETIME NULL,
  closed_by INT NULL,
  expected_cash_total DECIMAL(15,2) NULL,
  counted_cash_total DECIMAL(15,2) NULL,
  cash_difference DECIMAL(15,2) NULL,
  notes TEXT NULL,
  offline_open_uuid VARCHAR(80) NULL,
  offline_close_uuid VARCHAR(80) NULL,
  sync_status VARCHAR(20) NOT NULL DEFAULT 'synced',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_shift_code (shift_code),
  UNIQUE KEY uniq_shift_open_uuid (offline_open_uuid),
  UNIQUE KEY uniq_shift_close_uuid (offline_close_uuid),
  KEY idx_shift_status (status, opened_at),
  KEY idx_shift_branch_status (branch_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pos_shift_users (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  shift_id BIGINT NOT NULL,
  user_id INT NOT NULL,
  activity_type VARCHAR(40) NOT NULL DEFAULT 'join',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_shift_user (shift_id, user_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_pos_shift_users_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pos_cash_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  shift_id BIGINT NOT NULL,
  movement_type VARCHAR(10) NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  notes TEXT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  offline_uuid VARCHAR(80) NULL,
  sync_status VARCHAR(20) NOT NULL DEFAULT 'synced',
  UNIQUE KEY uniq_cash_offline_uuid (offline_uuid),
  KEY idx_shift_movement (shift_id, movement_type),
  CONSTRAINT fk_pos_cash_shift FOREIGN KEY (shift_id) REFERENCES pos_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pos_sync_queue_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  offline_uuid VARCHAR(80) NOT NULL,
  payload_json LONGTEXT NULL,
  processed_at DATETIME NULL,
  user_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'success',
  message VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_sync_offline_uuid (offline_uuid),
  KEY idx_sync_status (status, processed_at)
) ENGINE=InnoDB;

ALTER TABLE sales ADD COLUMN shift_id BIGINT NULL AFTER branch_id;
ALTER TABLE sales ADD COLUMN transaction_group_uuid VARCHAR(80) NULL AFTER transaction_code;
ALTER TABLE sales ADD COLUMN offline_uuid VARCHAR(80) NULL AFTER transaction_group_uuid;
ALTER TABLE sales ADD COLUMN sync_status VARCHAR(20) NOT NULL DEFAULT 'synced' AFTER offline_uuid;
ALTER TABLE sales ADD KEY idx_sales_shift_id (shift_id);
ALTER TABLE sales ADD KEY idx_sales_tx_group (transaction_group_uuid);
ALTER TABLE sales ADD UNIQUE KEY uniq_sales_offline_uuid (offline_uuid);

INSERT INTO settings (`key`,`value`) VALUES ('pos_default_opening_cash','100000')
ON DUPLICATE KEY UPDATE `value`=`value`;
