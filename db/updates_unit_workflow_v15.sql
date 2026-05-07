-- Adena POS/Web Unit Workflow v1.5
-- Jalankan aman berkali-kali; abaikan error duplicate column/index bila DB engine belum mendukung IF NOT EXISTS untuk ALTER.
ALTER TABLE branches ADD COLUMN unit_type ENUM('branch','kitchen') NOT NULL DEFAULT 'branch' AFTER branch_name;
ALTER TABLE branches ADD COLUMN is_kitchen TINYINT(1) NOT NULL DEFAULT 0 AFTER unit_type;
ALTER TABLE branches ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER is_active;
ALTER TABLE branches ADD KEY idx_branches_unit_type (unit_type,is_active);
UPDATE branches SET unit_type='kitchen', is_kitchen=1 WHERE LOWER(branch_name) LIKE '%dapur%' OR LOWER(branch_name) LIKE '%kitchen%' OR LOWER(branch_code) LIKE '%dapur%' OR LOWER(branch_code) LIKE '%kit%' OR LOWER(branch_code) LIKE '%kitchen%';
INSERT INTO branches (branch_code, branch_name, unit_type, is_kitchen, is_active)
SELECT 'DAPUR','Dapur Utama','kitchen',1,1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM branches WHERE unit_type='kitchen' OR is_kitchen=1 LIMIT 1);

ALTER TABLE sales ADD COLUMN sale_source VARCHAR(30) NOT NULL DEFAULT 'branch_pos' AFTER branch_id;
ALTER TABLE sales ADD COLUMN unit_type VARCHAR(30) NULL AFTER sale_source;
ALTER TABLE sales ADD KEY idx_sales_unit_date (branch_id,sale_source,sold_at);

ALTER TABLE stock_transfers ADD COLUMN transfer_type VARCHAR(30) NOT NULL DEFAULT 'stock_transfer' AFTER status;
ALTER TABLE stock_transfers ADD KEY idx_transfer_from_to_status (from_location_id,to_location_id,status);
ALTER TABLE pos_shifts ADD KEY idx_pos_shifts_branch_status (branch_id,status,opened_at);
