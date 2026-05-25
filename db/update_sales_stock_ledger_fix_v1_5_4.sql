-- Patch v1.5.4: perbaikan ledger stok penjualan dan backfill transaksi lama.
-- Jalankan sekali pada database existing setelah upload file patch.
-- Aman untuk data lama: hanya menambah stock_ledger qty_out untuk baris sales aktif yang belum punya ledger keluar.

INSERT INTO stock_ledger
  (branch_id, product_id, trans_type, ref_table, ref_id, qty_in, qty_out, unit_cost, note, created_by, created_at)
SELECT
  COALESCE(s.branch_id, CAST(COALESCE(NULLIF((SELECT `value` FROM settings WHERE `key`='active_branch_id' LIMIT 1), ''), '1') AS UNSIGNED)) AS branch_id,
  s.product_id,
  'pos_sale' AS trans_type,
  'sales' AS ref_table,
  s.id AS ref_id,
  0 AS qty_in,
  s.qty AS qty_out,
  NULL AS unit_cost,
  CONCAT('Backfill stok penjualan ', COALESCE(NULLIF(s.transaction_code,''), CONCAT('LEGACY-', s.id))) AS note,
  s.created_by,
  COALESCE(s.sold_at, NOW()) AS created_at
FROM sales s
JOIN products p ON p.id = s.product_id
LEFT JOIN stock_ledger sl
  ON sl.ref_table = 'sales'
 AND sl.ref_id = s.id
 AND sl.qty_out > 0
WHERE sl.id IS NULL
  AND COALESCE(p.track_stock, 1) = 1
  AND COALESCE(s.qty, 0) > 0
  AND COALESCE(s.is_active_revision, 1) = 1
  AND (s.return_reason IS NULL OR s.return_reason = '');
