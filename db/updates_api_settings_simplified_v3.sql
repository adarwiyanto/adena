-- Patch: Simplified API Settings v3
-- Aman untuk database existing. Jalankan sekali via phpMyAdmin.
-- Script ini mengecek kolom dulu agar tidak error Duplicate column.

SET @db_name := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='api_tokens' AND COLUMN_NAME='api_type') = 0,
  'ALTER TABLE api_tokens ADD COLUMN api_type VARCHAR(50) NULL AFTER client_type',
  'SELECT "api_type already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='api_tokens' AND COLUMN_NAME='remote_base_url') = 0,
  'ALTER TABLE api_tokens ADD COLUMN remote_base_url VARCHAR(255) NULL AFTER api_type',
  'SELECT "remote_base_url already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='api_tokens' AND COLUMN_NAME='remote_token') = 0,
  'ALTER TABLE api_tokens ADD COLUMN remote_token TEXT NULL AFTER remote_base_url',
  'SELECT "remote_token already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE api_tokens
SET api_type = client_type
WHERE (api_type IS NULL OR api_type = '')
  AND client_type IS NOT NULL
  AND client_type <> '';
