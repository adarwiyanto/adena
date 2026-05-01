<?php
require_once __DIR__ . '/../core/inventory.php';
ensure_inventory_module_schema();
ensure_landing_order_tables();

$dashboardBranchRows = [];
$dashboardTopProductsByBranch = [];
$dashboardPaymentsByBranch = [];
$dashboardPeakDayByBranch = [];
$dashboardPeakHourByBranch = [];
$dashboardDeadStockByBranch = [];
$dashboardSlowMovingByBranch = [];
$defaultBranchId = (int)setting('active_branch_id', '1');
if ($defaultBranchId <= 0) $defaultBranchId = 1;
$prevStart = $rangeStart->modify('-' . max(1, (int)$rangeEnd->diff($rangeStart)->days) . ' days');
$prevEnd = $rangeStart;
$prevStartStr = $prevStart->format('Y-m-d H:i:s');
$prevEndStr = $prevEnd->format('Y-m-d H:i:s');

$branchBaseRows = db()->query("SELECT id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name ASC")->fetchAll();
foreach ($branchBaseRows as $b) {
  $bid = (int)$b['id'];
  $dashboardBranchRows[$bid] = [
    'branch_id' => $bid,
    'branch_name' => (string)$b['branch_name'],
    'tx_count' => 0,
    'revenue' => 0.0,
    'avg_ticket' => 0.0,
    'prev_revenue' => 0.0,
    'growth_pct' => null,
  ];
}

$stmt = db()->prepare(" 
  SELECT COALESCE(branch_id, ?) AS branch_id,
         COUNT(DISTINCT COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-', id))) AS tx_count,
         COALESCE(SUM(total),0) AS revenue
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
  GROUP BY COALESCE(branch_id, ?)
");
$stmt->execute([$defaultBranchId, $rangeStartStr, $rangeEndStr, $defaultBranchId]);
foreach ($stmt->fetchAll() as $row) {
  $bid = (int)$row['branch_id'];
  if (!isset($dashboardBranchRows[$bid])) continue;
  $dashboardBranchRows[$bid]['tx_count'] = (int)$row['tx_count'];
  $dashboardBranchRows[$bid]['revenue'] = (float)$row['revenue'];
  $dashboardBranchRows[$bid]['avg_ticket'] = (int)$row['tx_count'] > 0 ? (float)$row['revenue'] / (int)$row['tx_count'] : 0;
}
$stmt = db()->prepare(" 
  SELECT COALESCE(branch_id, ?) AS branch_id, COALESCE(SUM(total),0) AS revenue
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
  GROUP BY COALESCE(branch_id, ?)
");
$stmt->execute([$defaultBranchId, $prevStartStr, $prevEndStr, $defaultBranchId]);
foreach ($stmt->fetchAll() as $row) {
  $bid = (int)$row['branch_id'];
  if (!isset($dashboardBranchRows[$bid])) continue;
  $prev = (float)$row['revenue'];
  $dashboardBranchRows[$bid]['prev_revenue'] = $prev;
  $dashboardBranchRows[$bid]['growth_pct'] = $prev > 0 ? (($dashboardBranchRows[$bid]['revenue'] - $prev) / $prev) * 100 : null;
}

$stmt = db()->prepare(" 
  SELECT COALESCE(s.branch_id, ?) AS branch_id, p.name, SUM(s.qty) qty, SUM(s.total) omzet
  FROM sales s JOIN products p ON p.id=s.product_id
  WHERE s.sold_at >= ? AND s.sold_at < ? AND s.return_reason IS NULL AND s.is_active_revision=1
  GROUP BY COALESCE(s.branch_id, ?), p.id, p.name
  ORDER BY branch_id ASC, qty DESC, omzet DESC
");
$stmt->execute([$defaultBranchId, $rangeStartStr, $rangeEndStr, $defaultBranchId]);
foreach ($stmt->fetchAll() as $row) {
  $bid = (int)$row['branch_id'];
  if (!isset($dashboardTopProductsByBranch[$bid])) $dashboardTopProductsByBranch[$bid] = [];
  if (count($dashboardTopProductsByBranch[$bid]) < 3) $dashboardTopProductsByBranch[$bid][] = $row;
}

$stmt = db()->prepare(" 
  SELECT COALESCE(branch_id, ?) AS branch_id, payment_method, COUNT(*) c, SUM(total) s
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
  GROUP BY COALESCE(branch_id, ?), payment_method
  ORDER BY branch_id ASC, s DESC
");
$stmt->execute([$defaultBranchId, $rangeStartStr, $rangeEndStr, $defaultBranchId]);
foreach ($stmt->fetchAll() as $row) {
  $bid = (int)$row['branch_id'];
  if (!isset($dashboardPaymentsByBranch[$bid])) $dashboardPaymentsByBranch[$bid] = [];
  if (count($dashboardPaymentsByBranch[$bid]) < 3) $dashboardPaymentsByBranch[$bid][] = $row;
}

$stmt = db()->prepare(" 
  SELECT COALESCE(branch_id, ?) AS branch_id, DAYNAME(sold_at) dow, COUNT(DISTINCT COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-', id))) c
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
  GROUP BY COALESCE(branch_id, ?), DAYNAME(sold_at)
");
try {
  $stmt->execute([$defaultBranchId, $rangeStartStr, $rangeEndStr, $defaultBranchId]);
  foreach ($stmt->fetchAll() as $row) {
    $bid = (int)$row['branch_id'];
    if (!isset($dashboardPeakDayByBranch[$bid]) || (int)$row['c'] > (int)$dashboardPeakDayByBranch[$bid]['c']) $dashboardPeakDayByBranch[$bid] = $row;
  }
} catch (Throwable $e) {}

$stmt = db()->prepare(" 
  SELECT COALESCE(branch_id, ?) AS branch_id, HOUR(sold_at) h, COUNT(DISTINCT COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-', id))) c
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
  GROUP BY COALESCE(branch_id, ?), HOUR(sold_at)
");
try {
  $stmt->execute([$defaultBranchId, $rangeStartStr, $rangeEndStr, $defaultBranchId]);
  foreach ($stmt->fetchAll() as $row) {
    $bid = (int)$row['branch_id'];
    if (!isset($dashboardPeakHourByBranch[$bid]) || (int)$row['c'] > (int)$dashboardPeakHourByBranch[$bid]['c']) $dashboardPeakHourByBranch[$bid] = $row;
  }
} catch (Throwable $e) {}

$last30StartStr = (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d 00:00:00');
foreach ($branchBaseRows as $b) {
  $bid = (int)$b['id'];
  $stmt = db()->prepare(" 
    SELECT p.name, COALESCE(SUM(sl.qty_in-sl.qty_out),0) stock_qty, MAX(s.sold_at) last_sold
    FROM products p
    JOIN stock_ledger sl ON sl.product_id=p.id AND sl.branch_id=?
    LEFT JOIN sales s ON s.product_id=p.id AND COALESCE(s.branch_id, ?)=? AND s.return_reason IS NULL AND s.is_active_revision=1
    WHERE p.track_stock=1
    GROUP BY p.id, p.name
    HAVING stock_qty > 0 AND (last_sold IS NULL OR last_sold < ?)
    ORDER BY last_sold ASC, stock_qty DESC
    LIMIT 5
  ");
  $stmt->execute([$bid, $defaultBranchId, $bid, $last30StartStr]);
  $dashboardDeadStockByBranch[$bid] = $stmt->fetchAll();

  $stmt = db()->prepare(" 
    SELECT p.name, COALESCE(SUM(s.qty),0) qty
    FROM products p
    LEFT JOIN sales s ON s.product_id=p.id AND COALESCE(s.branch_id, ?)=? AND s.sold_at >= ? AND s.sold_at < ? AND s.return_reason IS NULL AND s.is_active_revision=1
    WHERE p.track_stock=1
    GROUP BY p.id, p.name
    HAVING qty > 0 AND qty <= 3
    ORDER BY qty ASC, p.name ASC
    LIMIT 5
  ");
  $stmt->execute([$defaultBranchId, $bid, $rangeStartStr, $rangeEndStr]);
  $dashboardSlowMovingByBranch[$bid] = $stmt->fetchAll();
}

$dashCustomerStats = ['total'=>0,'new_month'=>0,'active'=>0,'repeat'=>0];
$dashAgeGroups = []; $dashGenderGroups = []; $dashDomicileGroups = [];
try {
  $monthStartDash = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');
  $rows = db()->query("SELECT c.id,c.gender,c.birth_date,c.domicile,c.created_at,MAX(o.created_at) last_order,COUNT(DISTINCT o.id) orders FROM customers c LEFT JOIN orders o ON o.customer_id=c.id GROUP BY c.id")->fetchAll();
  $dashCustomerStats['total'] = count($rows);
  foreach ($rows as $c) {
    $age = null;
    if (!empty($c['birth_date'])) { try { $age = (new DateTimeImmutable($c['birth_date']))->diff(new DateTimeImmutable('today'))->y; } catch (Throwable $e) {} }
    $ag = $age === null ? 'Tidak diisi' : ($age < 18 ? '<18' : ($age <=25 ? '18-25' : ($age <=35 ? '26-35' : ($age <=50 ? '36-50' : '>50'))));
    $dashAgeGroups[$ag] = ($dashAgeGroups[$ag] ?? 0) + 1;
    $g = $c['gender'] ?: 'Tidak diisi'; $dashGenderGroups[$g] = ($dashGenderGroups[$g] ?? 0) + 1;
    $d = trim((string)($c['domicile'] ?? '')) ?: 'Tidak diisi'; $dashDomicileGroups[$d] = ($dashDomicileGroups[$d] ?? 0) + 1;
    if (!empty($c['created_at']) && $c['created_at'] >= $monthStartDash) $dashCustomerStats['new_month']++;
    if ((int)$c['orders'] >= 2) $dashCustomerStats['repeat']++;
    if (!empty($c['last_order'])) { try { if ((new DateTimeImmutable($c['last_order'])) >= (new DateTimeImmutable('today'))->modify('-30 days')) $dashCustomerStats['active']++; } catch (Throwable $e) {} }
  }
  arsort($dashDomicileGroups);
} catch (Throwable $e) {}
$genderMapDash = ['male'=>'Laki-laki','female'=>'Perempuan','other'=>'Lainnya'];
?>
<div class="card" style="margin-top:16px">
  <h3 style="margin-top:0">Dashboard Perkembangan per Cabang</h3>
  <p class="kpi-subtitle">Mengikuti filter periode utama di atas. Data lama tanpa branch_id dibaca sebagai cabang default.</p>
  <div class="grid cols-2">
    <?php foreach ($dashboardBranchRows as $bid => $br): ?>
      <div class="card">
        <h4 style="margin-top:0"><?php echo e($br['branch_name']); ?></h4>
        <div class="grid cols-3">
          <div><small>Omzet</small><br><strong><?php echo e(format_rupiah($br['revenue'])); ?></strong></div>
          <div><small>Transaksi</small><br><strong><?php echo e((string)$br['tx_count']); ?></strong></div>
          <div><small>Avg</small><br><strong><?php echo e(format_rupiah($br['avg_ticket'])); ?></strong></div>
        </div>
        <p style="margin:10px 0 0"><small>Growth vs periode sebelumnya: <strong><?php echo $br['growth_pct'] === null ? 'N/A' : e(format_number_id((float)$br['growth_pct'])) . '%'; ?></strong></small></p>
        <p style="margin:6px 0 0"><small>Hari teramai: <strong><?php echo e((string)($dashboardPeakDayByBranch[$bid]['dow'] ?? '-')); ?></strong> · Jam teramai: <strong><?php echo isset($dashboardPeakHourByBranch[$bid]['h']) ? e(str_pad((string)$dashboardPeakHourByBranch[$bid]['h'],2,'0',STR_PAD_LEFT).':00') : '-'; ?></strong></small></p>
        <div class="grid cols-2" style="margin-top:10px">
          <div><strong>Top produk</strong><ul class="mini-list"><?php foreach(($dashboardTopProductsByBranch[$bid] ?? []) as $p): ?><li><?php echo e($p['name']); ?> (<?php echo e((string)$p['qty']); ?>)</li><?php endforeach; if(empty($dashboardTopProductsByBranch[$bid])): ?><li>Belum ada</li><?php endif; ?></ul></div>
          <div><strong>Metode bayar</strong><ul class="mini-list"><?php foreach(($dashboardPaymentsByBranch[$bid] ?? []) as $p): ?><li><?php echo e($p['payment_method'] ?: '-'); ?>: <?php echo e((string)$p['c']); ?></li><?php endforeach; if(empty($dashboardPaymentsByBranch[$bid])): ?><li>Belum ada</li><?php endif; ?></ul></div>
        </div>
        <div class="grid cols-2" style="margin-top:10px">
          <div><strong>Dead stock &gt;30 hari</strong><ul class="mini-list"><?php foreach(($dashboardDeadStockByBranch[$bid] ?? []) as $p): ?><li><?php echo e($p['name']); ?></li><?php endforeach; if(empty($dashboardDeadStockByBranch[$bid])): ?><li>Aman</li><?php endif; ?></ul></div>
          <div><strong>Slow moving</strong><ul class="mini-list"><?php foreach(($dashboardSlowMovingByBranch[$bid] ?? []) as $p): ?><li><?php echo e($p['name']); ?> (<?php echo e((string)$p['qty']); ?>)</li><?php endforeach; if(empty($dashboardSlowMovingByBranch[$bid])): ?><li>Aman</li><?php endif; ?></ul></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <h3 style="margin-top:0">Dashboard Customer & Demografi</h3>
  <div class="grid cols-4">
    <div class="card"><h4 style="margin-top:0">Total Customer</h4><strong><?php echo e((string)$dashCustomerStats['total']); ?></strong></div>
    <div class="card"><h4 style="margin-top:0">Baru Bulan Ini</h4><strong><?php echo e((string)$dashCustomerStats['new_month']); ?></strong></div>
    <div class="card"><h4 style="margin-top:0">Aktif 30 Hari</h4><strong><?php echo e((string)$dashCustomerStats['active']); ?></strong></div>
    <div class="card"><h4 style="margin-top:0">Repeat Rate</h4><strong><?php echo e($dashCustomerStats['total'] ? format_number_id(($dashCustomerStats['repeat']/$dashCustomerStats['total'])*100) : '0'); ?>%</strong></div>
  </div>
  <div class="grid cols-3" style="margin-top:12px">
    <div><strong>Umur</strong><ul class="mini-list"><?php foreach($dashAgeGroups as $k=>$v): ?><li><?php echo e($k); ?>: <?php echo e((string)$v); ?></li><?php endforeach; ?></ul></div>
    <div><strong>Jenis kelamin</strong><ul class="mini-list"><?php foreach($dashGenderGroups as $k=>$v): ?><li><?php echo e($genderMapDash[$k] ?? $k); ?>: <?php echo e((string)$v); ?></li><?php endforeach; ?></ul></div>
    <div><strong>Domisili teratas</strong><ul class="mini-list"><?php foreach(array_slice($dashDomicileGroups,0,6,true) as $k=>$v): ?><li><?php echo e($k); ?>: <?php echo e((string)$v); ?></li><?php endforeach; ?></ul></div>
  </div>
  <p style="margin-top:12px"><a class="btn" href="<?php echo e(base_url('admin/customers.php')); ?>">Lihat Detail Customer</a></p>
</div>
