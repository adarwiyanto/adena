<?php
require_once __DIR__ . '/../core/portal_area_guard.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = portal_light_area_guard('kitchen');
$customCss = setting('custom_css', '');
$err = '';
$locationId = 1;
$recentTransfers = [];
$stockSku = 0; $pendingTransfer = 0; $productions = 0;
try {
  $locationId = portal_inventory_kitchen_location_id();
  
  $stockSku = 0;
  foreach (portal_inventory_stock_rows($locationId, '', 'all') as $stockRow) {
    if ((float)($stockRow['stock_qty'] ?? 0) != 0.0) { $stockSku++; }
  }
  $stmt = db()->prepare("SELECT COUNT(*) FROM stock_transfers WHERE from_location_id=? AND status='sent'");
  $stmt->execute([$locationId]);
  $pendingTransfer = (int)$stmt->fetchColumn();
  if (portal_table_exists('production_headers')) {
    $productions = (int)(db()->query("SELECT COUNT(*) FROM production_headers WHERE production_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn() ?: 0);
  }
  $stmt = db()->prepare("SELECT st.id,st.transfer_no,st.status,st.created_at,st.notes,tl.location_name to_name,
      (SELECT COUNT(*) FROM stock_transfer_items si WHERE si.transfer_id=st.id) item_count,
      (SELECT COALESCE(SUM(si.qty),0) FROM stock_transfer_items si WHERE si.transfer_id=st.id) total_qty
    FROM stock_transfers st
    LEFT JOIN stock_locations tl ON tl.id=st.to_location_id
    WHERE st.from_location_id=?
    ORDER BY st.id DESC LIMIT 10");
  $stmt->execute([$locationId]);
  $recentTransfers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $err = $e->getMessage();
}
kitchen_header('Dapur Produksi', $customCss);
?>
<?php if ($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><strong>Error dapur:</strong> <?php echo e($err); ?></div><?php endif; ?>
<div class="card"><h2>Dashboard Dapur</h2><p class="portal-note">Area khusus dapur untuk stok bahan/produk, BOM, produksi, stok opname, dan transfer keluar. Halaman ini tidak lagi mengarah ke file Admin.</p></div>
<div class="grid cols-3">
  <div class="card"><div class="muted">Produksi 30 hari</div><h2><?php echo e((string)$productions); ?></h2></div>
  <div class="card"><div class="muted">Transfer menunggu penerima</div><h2><?php echo e((string)$pendingTransfer); ?></h2></div>
  <div class="card"><div class="muted">SKU stok aktif</div><h2><?php echo e((string)$stockSku); ?></h2></div>
</div>
<div class="card"><h3>Menu Dapur</h3><div class="grid cols-3">
  <a class="btn" href="<?php echo e(base_url('kitchen/stocks.php')); ?>">Stok Dapur</a>
  <a class="btn" href="<?php echo e(base_url('kitchen/initial_stock.php')); ?>">Stok Awal</a>
  <a class="btn" href="<?php echo e(base_url('kitchen/opname.php')); ?>">Stok Opname</a>
  <a class="btn" href="<?php echo e(base_url('kitchen/bom.php')); ?>">BOM Produk</a>
  <a class="btn" href="<?php echo e(base_url('kitchen/production.php')); ?>">Produksi Finished Good</a>
  <a class="btn" href="<?php echo e(base_url('kitchen/transfers.php')); ?>">Transfer ke Cabang</a>
</div></div>
<div class="card"><h3>Transfer keluar terakhir</h3><table class="table"><thead><tr><th>No</th><th>Tujuan</th><th>Status</th><th>Item</th><th>Tanggal</th></tr></thead><tbody>
<?php if (!$recentTransfers): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Belum ada transfer.</td></tr><?php endif; ?>
<?php foreach($recentTransfers as $r): ?><tr><td><?php echo e((string)$r['transfer_no']); ?></td><td><?php echo e((string)($r['to_name'] ?? '-')); ?></td><td><?php echo e((string)$r['status']); ?></td><td><?php echo e((string)$r['item_count']); ?> item<br><small><?php echo e((string)$r['total_qty']); ?></small></td><td><?php echo e((string)$r['created_at']); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php kitchen_footer(); ?>
