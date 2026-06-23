-- Patch 20260623b
-- Penyatuan API & Integrasi: Kasir Desktop, Antar Cabang, Dapur, Situs Lain.
-- Jalur Kasir Desktop tetap memakai tabel api_tokens dan endpoint lama.

ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER device_code;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS token_plain TEXT NULL AFTER branch_id;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS api_type VARCHAR(50) NULL AFTER token_plain;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS client_type VARCHAR(30) NULL AFTER api_type;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS api_mode VARCHAR(20) NOT NULL DEFAULT 'sender' AFTER client_type;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS unit_code VARCHAR(40) NULL AFTER api_mode;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS remote_base_url VARCHAR(255) NULL AFTER unit_code;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS remote_token TEXT NULL AFTER remote_base_url;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS permissions TEXT NULL AFTER remote_token;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS allowed_ips TEXT NULL AFTER permissions;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER allowed_ips;

UPDATE api_tokens
SET client_type = 'desktop'
WHERE (client_type IS NULL OR client_type = '')
  AND (api_type IS NULL OR api_type = '' OR api_type = 'desktop');

UPDATE api_tokens
SET permissions = '["master.view","categories.view","products.view","sales.view","sales.push","stocks.view","users.view"]'
WHERE permissions IS NULL OR permissions = '';
