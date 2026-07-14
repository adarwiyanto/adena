-- POS customer membership patch. Run once via phpMyAdmin.
ALTER TABLE sales ADD COLUMN IF NOT EXISTS customer_id BIGINT NULL;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS customer_name VARCHAR(150) NULL;
ALTER TABLE sales ADD COLUMN IF NOT EXISTS customer_phone VARCHAR(50) NULL;
ALTER TABLE sales ADD INDEX IF NOT EXISTS idx_sales_customer_phone (customer_phone);
-- Normalize existing Indonesian mobile numbers where safe.
UPDATE customers SET phone=CONCAT('62',SUBSTRING(phone,2)) WHERE phone LIKE '0%';
