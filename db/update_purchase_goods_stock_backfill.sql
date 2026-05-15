-- Patch: fix pembelian barang toko agar masuk stok.
-- Aman dijalankan ulang: backfill memakai NOT EXISTS terhadap stock_ledger.
-- Jalankan setelah file patch PHP di-upload.

START TRANSACTION;

-- Pastikan kolom penting tersedia pada database yang lebih lama.
-- Bila kolom sudah ada dan server tidak mendukung IF NOT EXISTS, abaikan error ALTER ini secara manual.
-- Database terbaru Dok sudah memiliki kolom-kolom ini.
-- ALTER TABLE purchase_headers ADD COLUMN purchase_type ENUM('raw_material','general') NOT NULL DEFAULT 'raw_material' AFTER purchase_date;
-- ALTER TABLE purchase_items ADD COLUMN item_name VARCHAR(190) NULL AFTER product_id;

-- Produk yang pernah dibeli sebagai pembelian barang toko harus dianggap sebagai barang stok.
UPDATE products p
JOIN purchase_items pi ON pi.product_id = p.id
JOIN purchase_headers ph ON ph.id = pi.purchase_id
SET p.track_stock = 1,
    p.allow_direct_purchase = 1,
    p.product_type = 'finished_good'
WHERE ph.purchase_type = 'general'
  AND ph.status = 'posted'
  AND pi.product_id IS NOT NULL
  AND pi.product_id > 0;

-- Lengkapi item_name lama yang kosong agar riwayat pembelian lebih enak dibaca.
UPDATE purchase_items pi
JOIN products p ON p.id = pi.product_id
SET pi.item_name = p.name
WHERE (pi.item_name IS NULL OR TRIM(pi.item_name) = '')
  AND pi.product_id IS NOT NULL
  AND pi.product_id > 0;

-- Backfill mutasi stok dari pembelian barang yang sudah posted tetapi belum masuk stock_ledger.
-- Dibuat agregat per purchase_id + product_id supaya aman bila ada produk sama pada nota yang sama.
INSERT INTO stock_ledger
  (branch_id, product_id, trans_type, ref_table, ref_id, qty_in, qty_out, unit_cost, note, created_by, created_at)
SELECT
  ph.branch_id,
  pi.product_id,
  'store_purchase' AS trans_type,
  'purchase_headers' AS ref_table,
  ph.id AS ref_id,
  SUM(pi.qty * COALESCE(NULLIF(p.purchase_to_base_factor, 0), 1)) AS qty_in,
  0 AS qty_out,
  CASE WHEN SUM(pi.qty) > 0 THEN SUM(pi.line_total) / SUM(pi.qty) ELSE MAX(pi.unit_cost) END AS unit_cost,
  CONCAT('Backfill pembelian barang ', ph.purchase_no) AS note,
  ph.created_by,
  COALESCE(ph.created_at, NOW()) AS created_at
FROM purchase_headers ph
JOIN purchase_items pi ON pi.purchase_id = ph.id
JOIN products p ON p.id = pi.product_id
LEFT JOIN stock_ledger sl
  ON sl.ref_table = 'purchase_headers'
 AND sl.ref_id = ph.id
 AND sl.product_id = pi.product_id
 AND sl.trans_type = 'store_purchase'
WHERE ph.purchase_type = 'general'
  AND ph.status = 'posted'
  AND pi.product_id IS NOT NULL
  AND pi.product_id > 0
  AND sl.id IS NULL
GROUP BY ph.id, ph.branch_id, ph.purchase_no, ph.created_by, ph.created_at, pi.product_id;

-- Rapikan metadata posted untuk transaksi lama yang statusnya sudah posted tetapi belum punya posted_at.
UPDATE purchase_headers
SET posted_at = COALESCE(posted_at, created_at),
    posted_by = COALESCE(posted_by, created_by)
WHERE purchase_type = 'general'
  AND status = 'posted';

COMMIT;
