<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
require_admin();
ensure_inventory_module_schema();
$branchId = (int)($_GET['branch_id'] ?? active_branch_id());
$branches = inventory_branches();

$purchase = db()->prepare("SELECT ph.purchase_date, ph.purchase_no, s.supplier_name, ph.grand_total, ph.status, COUNT(pi.id) AS total_items
  FROM purchase_headers ph
  LEFT JOIN suppliers s ON s.id=ph.supplier_id
  LEFT JOIN purchase_items pi ON pi.purchase_id=ph.id
  WHERE ph.branch_id=? AND ph.purchase_type='general'
  GROUP BY ph.id
  ORDER BY ph.purchase_date DESC, ph.id DESC LIMIT 100");
$purchase->execute([$branchId]);
$purchaseRows = $purchase->fetchAll();

$opname = db()->prepare("SELECT h.opname_no, h.opname_date, h.status,
  COUNT(i.id) total_items,
  SUM(CASE WHEN ABS(i.variance_qty)>0.00001 THEN 1 ELSE 0 END) variance_items
  FROM stock_opname_headers h
  LEFT JOIN stock_opname_items i ON i.opname_id=h.id
  WHERE h.branch_id=?
  GROUP BY h.id
  ORDER BY h.id DESC LIMIT 100");
$opname->execute([$branchId]);
$opnameRows = $opname->fetchAll();

$card = db()->prepare("SELECT sl.created_at, p.name, p.base_unit, p.purchase_unit, p.purchase_to_base_factor, p.sale_unit, p.sale_to_base_factor, sl.trans_type, sl.qty_in, sl.qty_out, sl.note
  FROM stock_ledger sl
  JOIN products p ON p.id=sl.product_id
  WHERE sl.branch_id=?
  ORDER BY sl.id DESC LIMIT 200");
$card->execute([$branchId]);
$cardRows = $card->fetchAll();
$customCss = setting('custom_css','');
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Laporan Inventory Toko</title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>">
<link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
<style><?php echo $customCss; ?>.report-page{max-width:1380px;margin:0 auto}.table-shell{overflow:auto;border:1px solid #e2e8f0;border-radius:16px}.report-table{min-width:900px}.muted{color:#64748b;font-size:12px}.filter-line{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}@media(max-width:900px){.filter-line{display:block}.filter-line .btn{width:100%;margin-top:8px}}</style>
</head>
<body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Laporan Inventory Toko</strong></div><div class="content report-page">
<div class="card"><form class="filter-line"><div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$branchId?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div><button class="btn" type="submit">Filter</button></form><p class="muted">Laporan ini disederhanakan untuk mode toko: pembelian barang, stok opname, dan kartu stok. Laporan BOM/produksi tidak ditampilkan.</p></div>

<div class="card"><h3>Pembelian Barang</h3><div class="table-shell"><table class="table report-table"><thead><tr><th>Tanggal</th><th>No</th><th>Supplier</th><th>Item</th><th>Total</th><th>Status</th></tr></thead><tbody><?php foreach($purchaseRows as $r): ?><tr><td><?php echo e($r['purchase_date']); ?></td><td><?php echo e($r['purchase_no']); ?></td><td><?php echo e($r['supplier_name'] ?? '-'); ?></td><td><?php echo e((string)($r['total_items'] ?? 0)); ?></td><td><?php echo e(format_money((float)$r['grand_total'])); ?></td><td><?php echo e($r['status']); ?></td></tr><?php endforeach; if(!$purchaseRows): ?><tr><td colspan="6" style="text-align:center;color:#94a3b8">Belum ada pembelian barang.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="card"><h3>Stok Opname</h3><div class="table-shell"><table class="table report-table"><thead><tr><th>Tanggal</th><th>No Opname</th><th>Total Item</th><th>Item Selisih</th><th>Status</th></tr></thead><tbody><?php foreach($opnameRows as $r): ?><tr><td><?php echo e($r['opname_date']); ?></td><td><?php echo e($r['opname_no']); ?></td><td><?php echo e((string)($r['total_items'] ?? 0)); ?></td><td><?php echo e((string)($r['variance_items'] ?? 0)); ?></td><td><?php echo e($r['status']); ?></td></tr><?php endforeach; if(!$opnameRows): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Belum ada stok opname.</td></tr><?php endif; ?></tbody></table></div></div>

<div class="card"><h3>Kartu Stok / Ledger</h3><div class="table-shell"><table class="table report-table"><thead><tr><th>Waktu</th><th>Produk</th><th>Tipe</th><th>In</th><th>Out</th><th>Catatan</th></tr></thead><tbody><?php foreach($cardRows as $r): $m=product_unit_fallback($r); ?><tr><td><?php echo e($r['created_at']); ?></td><td><?php echo e($r['name']); ?></td><td><?php echo e($r['trans_type']); ?></td><td><?php echo e(format_qty((float)$r['qty_in'], $m['base_unit'])); ?></td><td><?php echo e(format_qty((float)$r['qty_out'], $m['base_unit'])); ?></td><td><?php echo e($r['note']); ?></td></tr><?php endforeach; if(!$cardRows): ?><tr><td colspan="6" style="text-align:center;color:#94a3b8">Belum ada mutasi stok.</td></tr><?php endif; ?></tbody></table></div></div>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
