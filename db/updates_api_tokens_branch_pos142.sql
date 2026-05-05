-- Adena POS Desktop 1.4.2: token dapat dikaitkan ke cabang POS.
ALTER TABLE api_tokens ADD COLUMN branch_id INT NULL AFTER device_code;
ALTER TABLE api_tokens ADD KEY idx_api_tokens_branch (branch_id);
