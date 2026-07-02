-- Patch: rekapitulasi pelanggan POS desktop/web
-- Aman dijalankan berulang. Jika kolom/index sudah ada, query akan dilewati.

SET @db_name := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='sales' AND COLUMN_NAME='customer_name') = 0,
  'ALTER TABLE sales ADD COLUMN customer_name VARCHAR(150) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='sales' AND COLUMN_NAME='customer_phone') = 0,
  'ALTER TABLE sales ADD COLUMN customer_phone VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='sales' AND INDEX_NAME='idx_sales_customer_name') = 0,
  'ALTER TABLE sales ADD KEY idx_sales_customer_name (customer_name)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='sales' AND INDEX_NAME='idx_sales_customer_phone') = 0,
  'ALTER TABLE sales ADD KEY idx_sales_customer_phone (customer_phone)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
