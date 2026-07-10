-- Patch 2026-06-22: konfirmasi penerimaan stok Dapur Adena di toko.
-- Endpoint dan halaman admin akan menjalankan ALTER berikut secara aman otomatis.
-- Bila ingin manual, jalankan hanya kolom yang belum ada di database.

ALTER TABLE kitchen_api_receive_logs ADD COLUMN branch_id INT NULL AFTER token_id;
ALTER TABLE kitchen_api_receive_logs ADD COLUMN supplier_id INT NULL AFTER branch_id;
ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_id INT NULL AFTER status;
ALTER TABLE kitchen_api_receive_logs ADD COLUMN purchase_no VARCHAR(80) NULL AFTER purchase_id;
ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_by INT NULL AFTER remote_ip;
ALTER TABLE kitchen_api_receive_logs ADD COLUMN confirmed_at DATETIME NULL AFTER confirmed_by;
ALTER TABLE kitchen_api_received_items ADD COLUMN qty_base DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER qty;
ALTER TABLE kitchen_api_received_items ADD COLUMN unit_cost DECIMAL(18,2) DEFAULT 0 AFTER transfer_price;
ALTER TABLE kitchen_api_received_items ADD COLUMN line_total DECIMAL(18,2) DEFAULT 0 AFTER unit_cost;
