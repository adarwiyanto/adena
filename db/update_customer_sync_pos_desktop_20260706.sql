-- Update sinkron pelanggan POS Desktop ke menu Data Pelanggan web.
-- Aman dijalankan berulang. Tidak mengubah tabel lain di luar customers dan sales.

SET @db_name := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='sales' AND COLUMN_NAME='customer_id') = 0,
  'ALTER TABLE sales ADD COLUMN customer_id BIGINT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='sales' AND INDEX_NAME='idx_sales_customer_id') = 0,
  'ALTER TABLE sales ADD KEY idx_sales_customer_id (customer_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Buat data customer dari riwayat sales POS Desktop yang sudah punya nomor HP tetapi belum ada di tabel customers.
INSERT INTO customers (name, phone)
SELECT x.customer_name, x.customer_phone
FROM (
  SELECT
    TRIM(s.customer_phone) AS customer_phone,
    COALESCE(NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(TRIM(s.customer_name),'') ORDER BY s.sold_at DESC, s.id DESC SEPARATOR '\n'), '\n', 1), ''), TRIM(s.customer_phone)) AS customer_name
  FROM sales s
  WHERE COALESCE(s.customer_phone,'') <> ''
  GROUP BY TRIM(s.customer_phone)
) x
LEFT JOIN customers c ON c.phone = x.customer_phone
WHERE c.id IS NULL;

-- Update nama customer sesuai input POS Desktop terbaru berdasarkan nomor HP yang sama.
UPDATE customers c
JOIN (
  SELECT
    TRIM(s.customer_phone) AS customer_phone,
    NULLIF(SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(TRIM(s.customer_name),'') ORDER BY s.sold_at DESC, s.id DESC SEPARATOR '\n'), '\n', 1), '') AS customer_name
  FROM sales s
  WHERE COALESCE(s.customer_phone,'') <> ''
  GROUP BY TRIM(s.customer_phone)
) x ON x.customer_phone = c.phone
SET c.name = x.customer_name
WHERE x.customer_name IS NOT NULL
  AND x.customer_name <> ''
  AND c.name <> x.customer_name;

-- Link sales lama ke customers agar statistik Data Pelanggan web langsung terbaca.
UPDATE sales s
JOIN customers c ON c.phone = TRIM(s.customer_phone)
SET s.customer_id = c.id
WHERE s.customer_id IS NULL
  AND COALESCE(s.customer_phone,'') <> '';
