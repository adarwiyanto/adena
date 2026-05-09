-- Adena POS / Web
-- Migration: Single Cabang Belitung - PRESERVE DATA
-- Dibuat untuk UPDATE database existing, bukan import ulang penuh.
-- Aman untuk data lama: tidak ada DROP TABLE, tidak ada TRUNCATE, tidak ada DELETE cabang/transaksi lama.
-- Efek utama:
-- 1) Menambahkan kolom/tabel pendukung single-branch bila belum ada.
-- 2) Menetapkan Belitung sebagai satu-satunya cabang aktif.
-- 3) Menonaktifkan cabang/unit lain tanpa menghapus datanya.
-- 4) Menjaga kompatibilitas POS Desktop ver 1.5 melalui branch_id/api token.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;

-- =========================================================
-- Helper: tambah kolom hanya jika belum ada
-- =========================================================
DROP PROCEDURE IF EXISTS adena_add_column_if_missing;
DELIMITER $$
CREATE PROCEDURE adena_add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @adena_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @adena_sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- =========================================================
-- Tabel settings bila belum ada
-- =========================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Tabel branches bila belum ada
-- Struktur dibuat kompatibel dengan dump terbaru, tapi tanpa drop data lama.
-- =========================================================
CREATE TABLE IF NOT EXISTS `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_code` varchar(40) NOT NULL,
  `branch_name` varchar(120) NOT NULL,
  `unit_type` enum('branch','kitchen') NOT NULL DEFAULT 'branch',
  `is_kitchen` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_branches_active` (`is_active`),
  KEY `idx_branches_unit_type` (`unit_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tambah kolom branches yang sering jadi sumber error sync bila belum ada
CALL adena_add_column_if_missing('branches', 'branch_code', "varchar(40) NOT NULL DEFAULT 'BLT'");
CALL adena_add_column_if_missing('branches', 'branch_name', "varchar(120) NOT NULL DEFAULT 'Belitung'");
CALL adena_add_column_if_missing('branches', 'unit_type', "enum('branch','kitchen') NOT NULL DEFAULT 'branch'");
CALL adena_add_column_if_missing('branches', 'is_kitchen', "tinyint(1) NOT NULL DEFAULT 0");
CALL adena_add_column_if_missing('branches', 'is_active', "tinyint(1) NOT NULL DEFAULT 1");
CALL adena_add_column_if_missing('branches', 'sort_order', "int(11) NOT NULL DEFAULT 0");
CALL adena_add_column_if_missing('branches', 'created_at', "timestamp NULL DEFAULT CURRENT_TIMESTAMP");
CALL adena_add_column_if_missing('branches', 'updated_at', "timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");

-- =========================================================
-- Tabel harga cabang bila belum ada, untuk kompatibilitas kode terbaru.
-- Data lama tidak disentuh.
-- =========================================================
CREATE TABLE IF NOT EXISTS `branch_product_prices` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_branch_product` (`branch_id`,`product_id`),
  KEY `idx_branch_product_prices_branch` (`branch_id`),
  KEY `idx_branch_product_prices_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- Kolom branch_id pada tabel yang dibutuhkan POS/API.
-- Tambah hanya bila belum ada.
-- =========================================================
CALL adena_add_column_if_missing('api_tokens', 'branch_id', 'int(11) DEFAULT NULL');
CALL adena_add_column_if_missing('pos_shifts', 'branch_id', 'int(11) DEFAULT NULL');
CALL adena_add_column_if_missing('sales', 'branch_id', 'int(11) DEFAULT NULL');
CALL adena_add_column_if_missing('sale_payments', 'local_transaction_id', 'varchar(100) DEFAULT NULL');
CALL adena_add_column_if_missing('stock_locations', 'branch_id', 'int(11) DEFAULT NULL');
CALL adena_add_column_if_missing('stock_ledger', 'branch_id', 'int(11) DEFAULT NULL');
CALL adena_add_column_if_missing('purchase_headers', 'branch_id', 'int(11) DEFAULT NULL');
CALL adena_add_column_if_missing('production_headers', 'branch_id', 'int(11) DEFAULT NULL');
CALL adena_add_column_if_missing('pos_pending_orders', 'branch_id', 'int(11) DEFAULT NULL');
CALL adena_add_column_if_missing('stock_opname_headers', 'branch_id', 'int(11) DEFAULT NULL');

-- =========================================================
-- Pastikan ada Cabang Belitung.
-- Tidak menghapus cabang lama.
-- =========================================================
INSERT INTO `branches` (`branch_code`, `branch_name`, `unit_type`, `is_kitchen`, `is_active`, `sort_order`, `created_at`, `updated_at`)
SELECT 'BLT', 'Belitung', 'branch', 0, 1, 0, NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `branches`
    WHERE `branch_name` = 'Belitung' OR `branch_code` IN ('BLT','MAIN')
);

-- Ambil ID Belitung existing; prioritas nama Belitung, lalu kode BLT/MAIN, lalu ID terkecil.
SET @BELITUNG_ID := (
    SELECT id FROM `branches`
    WHERE `branch_name` = 'Belitung' OR `branch_code` IN ('BLT','MAIN')
    ORDER BY CASE WHEN `branch_name` = 'Belitung' THEN 0 ELSE 1 END, id ASC
    LIMIT 1
);

-- Normalisasi identitas cabang aktif menjadi Belitung/BLT.
UPDATE `branches`
SET `branch_code` = 'BLT',
    `branch_name` = 'Belitung',
    `unit_type` = 'branch',
    `is_kitchen` = 0,
    `is_active` = 1,
    `sort_order` = 0,
    `updated_at` = NOW()
WHERE `id` = @BELITUNG_ID;

-- Nonaktifkan unit/cabang lain tanpa menghapus datanya.
-- Data historis tetap ada, hanya tidak aktif untuk mode single cabang.
UPDATE `branches`
SET `is_active` = 0,
    `updated_at` = NOW()
WHERE `id` <> @BELITUNG_ID;

-- =========================================================
-- Pastikan API token POS Desktop ver 1.5 mengarah ke Belitung.
-- Tidak menghapus token lama.
-- =========================================================
UPDATE `api_tokens`
SET `branch_id` = @BELITUNG_ID
WHERE `branch_id` IS NULL OR `branch_id` = 0 OR `branch_id` NOT IN (SELECT id FROM `branches` WHERE `is_active` = 1);

-- =========================================================
-- Isi branch_id kosong pada data operasional aktif agar POS/API tidak bingung.
-- Tidak mengubah data historis yang sudah punya branch_id valid.
-- =========================================================
UPDATE `pos_shifts` SET `branch_id` = @BELITUNG_ID WHERE `branch_id` IS NULL OR `branch_id` = 0;
UPDATE `sales` SET `branch_id` = @BELITUNG_ID WHERE `branch_id` IS NULL OR `branch_id` = 0;
UPDATE `stock_locations` SET `branch_id` = @BELITUNG_ID WHERE `branch_id` IS NULL OR `branch_id` = 0;
UPDATE `stock_ledger` SET `branch_id` = @BELITUNG_ID WHERE `branch_id` IS NULL OR `branch_id` = 0;
UPDATE `purchase_headers` SET `branch_id` = @BELITUNG_ID WHERE `branch_id` IS NULL OR `branch_id` = 0;
UPDATE `production_headers` SET `branch_id` = @BELITUNG_ID WHERE `branch_id` IS NULL OR `branch_id` = 0;
UPDATE `pos_pending_orders` SET `branch_id` = @BELITUNG_ID WHERE `branch_id` IS NULL OR `branch_id` = 0;
UPDATE `stock_opname_headers` SET `branch_id` = @BELITUNG_ID WHERE `branch_id` IS NULL OR `branch_id` = 0;

-- =========================================================
-- Isi harga Belitung dari harga produk existing bila belum ada.
-- Data harga lama tidak dihapus.
-- =========================================================
INSERT INTO `branch_product_prices` (`branch_id`, `product_id`, `price`, `is_active`, `created_at`, `updated_at`)
SELECT @BELITUNG_ID, p.`id`, COALESCE(p.`price`, 0), 1, NOW(), NOW()
FROM `products` p
WHERE NOT EXISTS (
    SELECT 1
    FROM `branch_product_prices` bpp
    WHERE bpp.`branch_id` = @BELITUNG_ID
      AND bpp.`product_id` = p.`id`
);

-- =========================================================
-- Settings single-cabang dan API-ready.
-- =========================================================
INSERT INTO `settings` (`key`, `value`) VALUES
('single_branch_mode', '1'),
('active_branch_id', CAST(@BELITUNG_ID AS CHAR)),
('active_branch_code', 'BLT'),
('active_branch_name', 'Belitung'),
('app_branch_name', 'Belitung'),
('app_branch_code', 'BLT'),
('installer_branch_name', 'Belitung'),
('installer_branch_code', 'BLT'),
('api_topology', 'single_install_per_branch'),
('api_relation_branch_branch', '1'),
('api_relation_branch_kitchen', '1'),
('api_relation_branch_backoffice', '1'),
('api_relation_kitchen_backoffice', '1'),
('pos_desktop_min_supported_version', '1.5')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- =========================================================
-- Cleanup helper
-- =========================================================
DROP PROCEDURE IF EXISTS adena_add_column_if_missing;
SET FOREIGN_KEY_CHECKS = 1;

-- Validasi cepat setelah import:
-- SELECT id, branch_code, branch_name, unit_type, is_kitchen, is_active FROM branches ORDER BY id;
-- SELECT `key`, `value` FROM settings WHERE `key` IN ('single_branch_mode','active_branch_name','active_branch_code','pos_desktop_min_supported_version');
