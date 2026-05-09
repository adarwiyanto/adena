-- Adena API Settings + Permission per Token
-- Aman untuk database lama: tidak menghapus data.

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `device_code` varchar(20) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `token_plain` text DEFAULT NULL,
  `client_type` varchar(30) NOT NULL DEFAULT 'pos_desktop',
  `permissions` text DEFAULT NULL,
  `allowed_ips` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_api_tokens_active` (`is_active`),
  KEY `idx_api_tokens_client_type` (`client_type`),
  KEY `idx_api_tokens_branch_id` (`branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `device_code` varchar(20) DEFAULT NULL AFTER `token_hash`;
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `branch_id` int(11) DEFAULT NULL AFTER `device_code`;
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `token_plain` text DEFAULT NULL AFTER `branch_id`;
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `client_type` varchar(30) NOT NULL DEFAULT 'pos_desktop' AFTER `token_plain`;
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `permissions` text DEFAULT NULL AFTER `client_type`;
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `allowed_ips` text DEFAULT NULL AFTER `permissions`;
ALTER TABLE `api_tokens` ADD COLUMN IF NOT EXISTS `notes` text DEFAULT NULL AFTER `allowed_ips`;

UPDATE `api_tokens`
SET `client_type` = 'pos_desktop'
WHERE `client_type` IS NULL OR `client_type` = '';

UPDATE `api_tokens`
SET `permissions` = '["master.view","categories.view","products.view","sales.view","sales.push","stocks.view","users.view"]'
WHERE `permissions` IS NULL OR `permissions` = '';

CREATE TABLE IF NOT EXISTS `api_request_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `token_id` int(11) DEFAULT NULL,
  `client_type` varchar(30) DEFAULT NULL,
  `endpoint` varchar(190) NOT NULL,
  `method` varchar(10) NOT NULL,
  `permission` varchar(80) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `status_code` int(11) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_api_logs_token` (`token_id`),
  KEY `idx_api_logs_created` (`created_at`),
  KEY `idx_api_logs_endpoint` (`endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
