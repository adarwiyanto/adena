-- POS print bridge jobs (58mm receipt tokenized payload)
CREATE TABLE IF NOT EXISTS pos_print_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_token VARCHAR(100) NOT NULL UNIQUE,
  sale_id BIGINT NULL,
  receipt_payload LONGTEXT NOT NULL,
  payload_hash VARCHAR(64) NOT NULL,
  status ENUM('pending','printed','expired','cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  printed_at DATETIME NULL,
  created_by INT NULL,
  device_hint VARCHAR(100) NULL,
  notes VARCHAR(255) NULL,
  KEY idx_status_expires (status, expires_at),
  KEY idx_sale_id (sale_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
