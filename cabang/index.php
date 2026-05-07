<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/branch_portal.php';
$branchId = require_portal_branch_id();
$branch = portal_branch_row($branchId);
$customCss = setting('custom_css','');
$today = new DateTimeImmutable('today');
$start = $today->format('Y-m-d 00:00:00');
$end = $today->modify('+1 day')->format('Y-m-d 00:00:00');
$stmt = db()->prepare("SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-',id))) tx_count, COALESCE(SUM(total),0) revenue FROM sales WHERE branch_id=? AND sold_at>=? AND sold_at<? AND return_reason IS NULL AND is_active_revision=1");
$stmt->execute([$branchId,$start,$end]); $todaySales=$stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$stmt = db()->prepare("SELECT payment_method, COALESCE(payment_channel_name,payment_bank,'') channel, COALESCE(SUM(total),0) amount FROM sales WHERE branch_id=? AND sold_at>=? AND sold_at<? AND return_reason IS NULL AND is_active_revision=1 GROUP BY payment_method, channel ORDER BY amount DESC");
$stmt->execute([$branchId,$start,$end]); $payments=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = db()->prepare("SELECT ps.* FROM pos_shifts ps WHERE ps.branch_id=? ORDER BY ps.opened_at DESC LIMIT 5");
$stmt->execute([$branchId]); $shifts=$stmt->fetchAll(PDO::FETCH_ASSOC);
$locId = portal_inventory_branch_location_id($branchId);
$lowStock=[]; $deadStock=[];
if ($locId>0 && table_exists('stock_ledger')) {
  $stmt=db()->prepare("SELECT p.name, p.reorder_level, COALESCE(SUM(sl.qty_in-sl.qty_out),0) qty FROM products p LEFT JOIN stock_ledger sl ON sl.product_id=p.id AND sl.location_id=? WHERE COALESCE(p.track_stock,1)=1 GROUP BY p.id HAVING qty <= COALESCE(p.reorder_level,0) ORDER BY qty ASC LIMIT 10");
  try { $stmt->execute([$locId]); $lowStock=$stmt->fetchAll(PDO::FETCH_ASSOC); } catch(Throwable $e) {}
  $stmt=db()->prepare("SELECT p.name, MAX(s.sold_at) last_sold, COALESCE(SUM(s.qty),0) qty90 FROM products p LEFT JOIN sales s ON s.product_id=p.id AND s.branch_id=? AND s.sold_at>=DATE_SUB(NOW(), INTERVAL 90 DAY) AND s.return_reason IS NULL WHERE COALESCE(p.show_on_pos,1)=1 GROUP BY p.id HAVING qty90=0 ORDER BY p.name LIMIT 10");
  $stmt->execute([$branchId]); $deadStock=$stmt->fetchAll(PDO::FETCH_ASSOC);
}
function cabang_nav($active){ $items=['index.php'=>'Dashboard','sales.php'=>'Penjualan','shifts.php'=>'Shift','stock.php'=>'Stok']; echo '<div class="card" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">'; foreach($items as $url=>$label){$cls=$active===$url?'btn':'btn secondary'; echo '<a class="'.e($cls).'" href="'.e(base_url('cabang/'.$url)).'">'.e($label).'</a>'; } echo '<a class="btn secondary" href="'.e(base_url('admin/logout.php')).'">Logout</a></div>'; }
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cabang - <?php echo e($branch['branch_name'] ?? ''); ?></title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body><div class="main" style="margin:0;max-width:1200px"><div class="content">
<h2>Portal Cabang: <?php echo e($branch['branch_name'] ?? 'Cabang'); ?></h2><?php cabang_nav('index.php'); ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px"><div class="card"><small>Omset hari ini</small><h2>Rp <?php echo e(format_money($todaySales['revenue'] ?? 0)); ?></h2></div><div class="card"><small>Transaksi hari ini</small><h2><?php echo e((string)($todaySales['tx_count'] ?? 0)); ?></h2></div><div class="card"><small>Lokasi stok</small><h2><?php echo e($locId>0?'Aktif':'Belum ada'); ?></h2></div></div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-top:12px"><div class="card"><h3>Payment Breakdown</h3><table><tbody><?php foreach($payments as $p): ?><tr><td><?php echo e($p['payment_method'].' '.$p['channel']); ?></td><td>Rp <?php echo e(format_money($p['amount'])); ?></td></tr><?php endforeach; if(!$payments): ?><tr><td>Belum ada transaksi hari ini.</td></tr><?php endif; ?></tbody></table></div><div class="card"><h3>Shift Terakhir</h3><table><tbody><?php foreach($shifts as $s): ?><tr><td><?php echo e($s['shift_code']); ?></td><td><?php echo e($s['status']); ?></td><td><?php echo e($s['opened_at']); ?></td></tr><?php endforeach; if(!$shifts): ?><tr><td>Belum ada shift.</td></tr><?php endif; ?></tbody></table></div></div>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-top:12px"><div class="card"><h3>Stok Rendah</h3><table><tbody><?php foreach($lowStock as $p): ?><tr><td><?php echo e($p['name']); ?></td><td><?php echo e(format_qty($p['qty'])); ?></td></tr><?php endforeach; if(!$lowStock): ?><tr><td>Tidak ada stok rendah / data lokasi belum lengkap.</td></tr><?php endif; ?></tbody></table></div><div class="card"><h3>Dead Stock 90 hari</h3><table><tbody><?php foreach($deadStock as $p): ?><tr><td><?php echo e($p['name']); ?></td></tr><?php endforeach; if(!$deadStock): ?><tr><td>Tidak ada data dead stock.</td></tr><?php endif; ?></tbody></table></div></div>
</div></div></body></html>
