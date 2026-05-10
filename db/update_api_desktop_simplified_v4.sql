-- Patch: API Desktop simplified v4
-- Aman untuk database existing. Jalankan via phpMyAdmin bila ingin menyiapkan kolom sebelum upload file PHP.

SET @db_name := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='api_tokens' AND COLUMN_NAME='api_mode') = 0,
  'ALTER TABLE api_tokens ADD COLUMN api_mode VARCHAR(20) NOT NULL DEFAULT ''sender'' AFTER device_code',
  'SELECT "api_mode already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='api_tokens' AND COLUMN_NAME='remote_base_url') = 0,
  'ALTER TABLE api_tokens ADD COLUMN remote_base_url VARCHAR(255) NULL AFTER api_mode',
  'SELECT "remote_base_url already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='api_tokens' AND COLUMN_NAME='remote_token') = 0,
  'ALTER TABLE api_tokens ADD COLUMN remote_token TEXT NULL AFTER remote_base_url',
  'SELECT "remote_token already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='api_tokens' AND COLUMN_NAME='permissions') = 0,
  'ALTER TABLE api_tokens ADD COLUMN permissions TEXT NULL AFTER remote_token',
  'SELECT "permissions already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='api_tokens' AND COLUMN_NAME='notes') = 0,
  'ALTER TABLE api_tokens ADD COLUMN notes TEXT NULL AFTER permissions',
  'SELECT "notes already exists" AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE api_tokens
SET api_mode = 'sender'
WHERE api_mode IS NULL OR api_mode = '';

UPDATE api_tokens
SET permissions = '["master.view","categories.view","products.view","sales.view","sales.push","stocks.view","users.view"]'
WHERE permissions IS NULL OR permissions = '';
