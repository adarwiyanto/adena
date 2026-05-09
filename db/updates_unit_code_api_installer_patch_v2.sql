-- Adena API Unit Code + Installer Multi Domain Patch v2
-- Aman untuk cabang utama yang sudah terinstall. Tidak menghapus data lama.

ALTER TABLE api_tokens ADD COLUMN unit_code VARCHAR(40) NULL AFTER branch_id;
ALTER TABLE api_tokens ADD INDEX idx_api_tokens_unit_code (unit_code);

UPDATE api_tokens
SET unit_code = UPPER(device_code)
WHERE (unit_code IS NULL OR unit_code = '')
  AND device_code IS NOT NULL
  AND device_code <> '';

UPDATE api_tokens t
LEFT JOIN branches b ON b.id = t.branch_id
SET t.unit_code = b.branch_code
WHERE (t.unit_code IS NULL OR t.unit_code = '')
  AND b.branch_code IS NOT NULL;

INSERT INTO settings (`key`, `value`)
SELECT 'active_unit_code', COALESCE((SELECT branch_code FROM branches WHERE is_active=1 ORDER BY id LIMIT 1), 'BLT')
FROM DUAL
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

INSERT INTO settings (`key`, `value`)
SELECT 'active_unit_type', COALESCE((SELECT unit_type FROM branches WHERE is_active=1 ORDER BY id LIMIT 1), 'branch')
FROM DUAL
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

INSERT INTO settings (`key`, `value`)
SELECT 'unit_code', COALESCE((SELECT branch_code FROM branches WHERE is_active=1 ORDER BY id LIMIT 1), 'BLT')
FROM DUAL
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
