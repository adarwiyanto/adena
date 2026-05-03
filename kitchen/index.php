<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/ops14.php';
$u = adena14_area_guard('kitchen');
ensure_inventory_module_schema();
$customCss = setting('custom_css', '');
$metrics = adena14_dashboard_metrics('kitchen');
$locs = adena14_locations();
$kitchenLoc = null; foreach ($locs as $l) { if (($l['location_type'] ?? '') === 'kitchen') { $kitchenLoc = $l; break; } }
$kitchenLocationId = (int)($kitchenLoc['id'] ?? 0);
$recentTransfers = [];
try {
  $stmt = db()->prepare("SELECT st.*, fl.location_name from_name, tl.location_name to_name FROM stock_transfers st LEFT JOIN stock_locations fl ON fl.id=st.from_location_id LEFT JOIN stock_locations tl ON tl.id=st.to_location_id WHERE st.from_location_id=? ORDER BY st.id DESC LIMIT 10");
  $stmt->execute([$kitchenLocationId]);
  $recentTransfers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dapur Produksi</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head>
<body><div class="container"><?php include __DIR__ . '/../admin/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong>Dapur Produksi</strong></div><div class="content">
<div class="card"><h2>Dashboard Dapur</h2><p style="color:#64748b">Area khusus dapur untuk produksi BOM, finished good, stok dapur, dan transfer keluar. Owner/admin tetap bisa akses penuh.</p></div>
<div class="grid cols-4">
  <div class="card"><div class="muted">Produksi 30 hari</div><h2><?php echo e((string)$metrics['production_30d']); ?></h2></div>
  <div class="card"><div class="muted">Transfer menunggu penerima</div><h2><?php echo e((string)$metrics['transfers_pending']); ?></h2></div>
  <div class="card"><div class="muted">SKU Stok</div><h2><?php echo e((string)$metrics['stock_skus']); ?></h2></div>
  <div class="card"><div class="muted">Dead Stock</div><h2><?php echo e((string)$metrics['dead_stock']); ?></h2></div>
</div>
<div class="card"><h3>Menu Dapur</h3><div class="grid cols-4">
  <a class="btn" href="<?php echo e(base_url('admin/bom.php')); ?>">BOM Produk</a>
  <a class="btn" href="<?php echo e(base_url('admin/production.php')); ?>">Produksi Finished Good</a>
  <a class="btn" href="<?php echo e(base_url('admin/stocks.php')); ?>">Stok Dapur</a>
  <a class="btn" href="<?php echo e(base_url('kitchen/transfers.php')); ?>">Transfer ke Toko/Cabang</a>
  <a class="btn btn-light" href="<?php echo e(base_url('stock/initial.php?area=kitchen')); ?>">Stok Awal</a>
  <a class="btn btn-light" href="<?php echo e(base_url('admin/stock_opname.php')); ?>">Stok Opname</a>
</div></div>
<div class="card"><h3>Transfer keluar terakhir</h3><table class="table"><thead><tr><th>No</th><th>Tujuan</th><th>Status</th><th>Tanggal</th><th>Catatan</th></tr></thead><tbody><?php if(!$recentTransfers): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Belum ada transfer.</td></tr><?php endif; foreach($recentTransfers as $r): ?><tr><td><?php echo e($r['transfer_no']); ?></td><td><?php echo e($r['to_name'] ?? '-'); ?></td><td><?php echo e($r['status']); ?></td><td><?php echo e($r['created_at']); ?></td><td><?php echo e($r['notes'] ?? ''); ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
