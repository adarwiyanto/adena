<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/ops14.php';
$u = adena14_area_guard('branch');
ensure_inventory_module_schema();
$customCss = setting('custom_css', '');
$metrics = adena14_dashboard_metrics('branch');
$recentIncoming = [];
try { $recentIncoming = db()->query("SELECT st.*, fl.location_name from_name, tl.location_name to_name FROM stock_transfers st LEFT JOIN stock_locations fl ON fl.id=st.from_location_id LEFT JOIN stock_locations tl ON tl.id=st.to_location_id WHERE st.status IN ('sent','accepted','rejected') ORDER BY st.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Toko / Cabang</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head>
<body><div class="container"><?php include __DIR__ . '/../admin/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Toko / Cabang</strong></div><div class="content">
<div class="card"><h2>Dashboard Toko / Cabang</h2><p style="color:#64748b">Area cabang untuk stok toko, penerimaan transfer, stok opname, dan perkembangan omset cabang.</p></div>
<div class="grid cols-4">
  <div class="card"><div class="muted">Omset 30 hari</div><h2>Rp <?php echo e(format_money($metrics['revenue'])); ?></h2></div>
  <div class="card"><div class="muted">Transaksi 30 hari</div><h2><?php echo e((string)$metrics['transactions']); ?></h2></div>
  <div class="card"><div class="muted">Transfer perlu approval</div><h2><?php echo e((string)$metrics['transfers_pending']); ?></h2></div>
  <div class="card"><div class="muted">Dead Stock</div><h2><?php echo e((string)$metrics['dead_stock']); ?></h2></div>
</div>
<div class="card"><h3>Menu Toko/Cabang</h3><div class="grid cols-4">
  <a class="btn" href="<?php echo e(base_url('admin/stocks.php')); ?>">Stok Toko</a>
  <a class="btn" href="<?php echo e(base_url('branch/transfer_receive.php')); ?>">Approval Transfer Masuk</a>
  <a class="btn" href="<?php echo e(base_url('admin/stock_opname.php')); ?>">Stok Opname</a>
  <a class="btn" href="<?php echo e(base_url('pos/index.php')); ?>" target="_blank">POS Kasir</a>
  <a class="btn btn-light" href="<?php echo e(base_url('stock/initial.php?area=branch')); ?>">Stok Awal</a>
  <a class="btn btn-light" href="<?php echo e(base_url('admin/sales.php')); ?>">Riwayat Penjualan</a>
</div></div>
<div class="card"><h3>Transfer masuk terakhir</h3><table class="table"><thead><tr><th>No</th><th>Dari</th><th>Tujuan</th><th>Status</th><th>Tanggal</th></tr></thead><tbody><?php if(!$recentIncoming): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Belum ada transfer.</td></tr><?php endif; foreach($recentIncoming as $r): ?><tr><td><?php echo e($r['transfer_no']); ?></td><td><?php echo e($r['from_name'] ?? '-'); ?></td><td><?php echo e($r['to_name'] ?? '-'); ?></td><td><?php echo e($r['status']); ?></td><td><?php echo e($r['created_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
