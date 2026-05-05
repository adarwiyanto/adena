-- Adena POS Web v1.4 - Portal Cabang/Dapur mandiri berbasis location_id
ALTER TABLE stock_ledger ADD COLUMN location_id INT NULL AFTER branch_id;
ALTER TABLE stock_ledger ADD KEY idx_stock_ledger_location (location_id, product_id, created_at);
ALTER TABLE stock_transfers ADD COLUMN cancelled_at TIMESTAMP NULL DEFAULT NULL AFTER rejected_at;
ALTER TABLE stock_transfer_items ADD COLUMN note VARCHAR(255) NULL AFTER unit_cost;
