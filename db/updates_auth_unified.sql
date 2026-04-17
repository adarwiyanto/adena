-- Unified auth updates (aman & idempotent)

SET @db_name := DATABASE();

SET @has_username := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'customers'
    AND COLUMN_NAME = 'username'
);
SET @sql := IF(@has_username = 0,
  'ALTER TABLE customers ADD COLUMN username VARCHAR(60) NULL AFTER name',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_username_idx := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'customers'
    AND INDEX_NAME = 'uniq_customers_username'
);
SET @sql := IF(@has_username_idx = 0,
  'ALTER TABLE customers ADD UNIQUE KEY uniq_customers_username (username)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_email_idx := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db_name
    AND TABLE_NAME = 'customers'
    AND INDEX_NAME = 'uniq_customers_email'
);
SET @sql := IF(@has_email_idx = 0,
  'ALTER TABLE customers ADD UNIQUE KEY uniq_customers_email (email)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill username customer lama dilakukan otomatis oleh aplikasi
-- melalui helper customer_backfill_usernames() saat boot.
