<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$me = require_menu_access('customers', 'view');
ensure_landing_order_tables();

function customer_age(?string $birthDate): ?int {
  if (!$birthDate) return null;
  try {
    $birth = new DateTimeImmutable($birthDate);
    return (int)$birth->diff(new DateTimeImmutable('today'))->y;
  } catch (Throwable $e) { return null; }
}
function customer_age_group(?int $age): string {
  if ($age === null) return 'Tidak diisi';
  if ($age < 18) return '<18';
  if ($age <= 25) return '18-25';
  if ($age <= 35) return '26-35';
  if ($age <= 50) return '36-50';
  return '>50';
}
function customer_category(int $orders, float $total, ?string $lastOrder): string {
  if ($lastOrder) {
    try {
      $days = (new DateTimeImmutable($lastOrder))->diff(new DateTimeImmutable('today'))->days;
      if ($days > 30) return 'inactive';
    } catch (Throwable $e) {}
  }
  if ($orders >= 8 || $total >= 1000000) return 'high';
  if ($orders >= 3 || $total >= 300000) return 'medium';
  return 'low';
}

$err = $_SESSION['customers_err'] ?? '';
unset($_SESSION['customers_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  if ($action === 'delete_customer') {
    if (($me['role'] ?? '') !== 'owner') {
      $_SESSION['customers_err'] = 'Hanya owner yang dapat menghapus pelanggan.';
    } else {
      $customerId = (int)($_POST['customer_id'] ?? 0);
      if ($customerId > 0) {
        $stmt = db()->prepare('DELETE FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
      }
    }
  }
  redirect(base_url('admin/customers.php'));
}

$customers = db()->query(" 
  SELECT c.id, c.name, c.email, c.phone, c.gender, c.birth_date, c.domicile, c.instagram, c.loyalty_points, c.created_at,
         NULLIF(GREATEST(
           COALESCE(os.last_order_at, '1000-01-01 00:00:00'),
           COALESCE(ss.last_order_at, '1000-01-01 00:00:00'),
           COALESCE(sp.last_order_at, '1000-01-01 00:00:00')
         ), '1000-01-01 00:00:00') AS last_order_at,
         (COALESCE(os.order_count,0) + COALESCE(ss.order_count,0) + COALESCE(sp.order_count,0)) AS order_count,
         (COALESCE(os.total_spend,0) + COALESCE(ss.total_spend,0) + COALESCE(sp.total_spend,0)) AS total_spend,
         COALESCE(
           (
             SELECT p2.name
             FROM orders o2
             JOIN order_items oi2 ON oi2.order_id=o2.id
             JOIN products p2 ON p2.id=oi2.product_id
             WHERE o2.customer_id=c.id
             GROUP BY p2.id, p2.name
             ORDER BY SUM(oi2.qty) DESC, SUM(oi2.subtotal) DESC
             LIMIT 1
           ),
           (
             SELECT p3.name
             FROM sales s3
             JOIN products p3 ON p3.id=s3.product_id
             WHERE (s3.customer_id=c.id OR (s3.customer_id IS NULL AND COALESCE(c.phone,'') <> '' AND TRIM(s3.customer_phone)=TRIM(c.phone)))
               AND COALESCE(s3.return_status,'none') <> 'returned'
               AND COALESCE(s3.is_active_revision,1) = 1
             GROUP BY p3.id, p3.name
             ORDER BY SUM(s3.qty) DESC, SUM(s3.total) DESC
             LIMIT 1
           )
         ) AS top_product
  FROM customers c
  LEFT JOIN (
    SELECT o.customer_id, MAX(o.created_at) AS last_order_at, COUNT(DISTINCT o.id) AS order_count, COALESCE(SUM(oi.subtotal),0) AS total_spend
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    GROUP BY o.customer_id
  ) os ON os.customer_id = c.id
  LEFT JOIN (
    SELECT s.customer_id,
           MAX(s.sold_at) AS last_order_at,
           COUNT(DISTINCT COALESCE(s.transaction_group_uuid, s.local_transaction_id, s.transaction_code, CAST(s.id AS CHAR))) AS order_count,
           COALESCE(SUM(s.total),0) AS total_spend
    FROM sales s
    WHERE s.customer_id IS NOT NULL
      AND COALESCE(s.return_status,'none') <> 'returned'
      AND COALESCE(s.is_active_revision,1) = 1
    GROUP BY s.customer_id
  ) ss ON ss.customer_id = c.id
  LEFT JOIN (
    SELECT TRIM(s.customer_phone) AS customer_phone,
           MAX(s.sold_at) AS last_order_at,
           COUNT(DISTINCT COALESCE(s.transaction_group_uuid, s.local_transaction_id, s.transaction_code, CAST(s.id AS CHAR))) AS order_count,
           COALESCE(SUM(s.total),0) AS total_spend
    FROM sales s
    WHERE s.customer_id IS NULL
      AND COALESCE(s.customer_phone,'') <> ''
      AND COALESCE(s.return_status,'none') <> 'returned'
      AND COALESCE(s.is_active_revision,1) = 1
    GROUP BY TRIM(s.customer_phone)
  ) sp ON sp.customer_phone = TRIM(c.phone)
  ORDER BY last_order_at DESC, c.created_at DESC
  LIMIT 500
")->fetchAll();

$totalCustomers = count($customers);
$newThisMonth = 0; $activeCustomers = 0; $repeatCustomers = 0;
$ageGroups = []; $genderGroups = []; $domicileGroups = [];
$monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');
foreach ($customers as $c) {
  $age = customer_age($c['birth_date'] ?? null);
  $ag = customer_age_group($age);
  $ageGroups[$ag] = ($ageGroups[$ag] ?? 0) + 1;
  $gender = (string)($c['gender'] ?: 'Tidak diisi');
  $genderGroups[$gender] = ($genderGroups[$gender] ?? 0) + 1;
  $dom = trim((string)($c['domicile'] ?? '')) ?: 'Tidak diisi';
  $domicileGroups[$dom] = ($domicileGroups[$dom] ?? 0) + 1;
  if (!empty($c['created_at']) && $c['created_at'] >= $monthStart) $newThisMonth++;
  if (!empty($c['last_order_at'])) {
    try { if ((new DateTimeImmutable($c['last_order_at'])) >= (new DateTimeImmutable('today'))->modify('-30 days')) $activeCustomers++; } catch (Throwable $e) {}
  }
  if ((int)($c['order_count'] ?? 0) >= 2) $repeatCustomers++;
}
arsort($domicileGroups);
$customCss = setting('custom_css', '');
$genderLabels = ['male'=>'Laki-laki','female'=>'Perempuan','other'=>'Lainnya'];
$categoryLabels = ['high'=>'High','medium'=>'Medium','low'=>'Low','inactive'=>'Inactive'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Data Pelanggan</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?> .mini-list{margin:0;padding-left:18px;color:#475569}.demobar{height:8px;border-radius:999px;background:#e2e8f0;overflow:hidden}.demobar span{display:block;height:100%;background:#2563eb}</style>
</head>
<body class="desktop-compact">
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">Data Pelanggan & Demografi</div></div>
    <div class="content">
      <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
      <div class="grid cols-4">
        <div class="card"><h4 style="margin-top:0">Total Customer</h4><div style="font-size:24px;font-weight:700"><?php echo e((string)$totalCustomers); ?></div></div>
        <div class="card"><h4 style="margin-top:0">Customer Baru Bulan Ini</h4><div style="font-size:24px;font-weight:700"><?php echo e((string)$newThisMonth); ?></div></div>
        <div class="card"><h4 style="margin-top:0">Aktif 30 Hari</h4><div style="font-size:24px;font-weight:700"><?php echo e((string)$activeCustomers); ?></div></div>
        <div class="card"><h4 style="margin-top:0">Repeat Rate</h4><div style="font-size:24px;font-weight:700"><?php echo e($totalCustomers > 0 ? format_number_id(($repeatCustomers/$totalCustomers)*100) : '0'); ?>%</div></div>
      </div>

      <div class="grid cols-3" style="margin-top:16px">
        <div class="card"><h3 style="margin-top:0">Umur</h3><?php foreach($ageGroups as $label=>$count): $pct=$totalCustomers?($count/$totalCustomers*100):0; ?><div style="margin:8px 0"><strong><?php echo e($label); ?></strong> <small><?php echo e((string)$count); ?> customer</small><div class="demobar"><span style="width:<?php echo e(number_format($pct,2,'.','')); ?>%"></span></div></div><?php endforeach; ?></div>
        <div class="card"><h3 style="margin-top:0">Jenis Kelamin</h3><?php foreach($genderGroups as $label=>$count): $pct=$totalCustomers?($count/$totalCustomers*100):0; ?><div style="margin:8px 0"><strong><?php echo e($genderLabels[$label] ?? $label); ?></strong> <small><?php echo e((string)$count); ?> customer</small><div class="demobar"><span style="width:<?php echo e(number_format($pct,2,'.','')); ?>%"></span></div></div><?php endforeach; ?></div>
        <div class="card"><h3 style="margin-top:0">Domisili Teratas</h3><ul class="mini-list"><?php foreach(array_slice($domicileGroups,0,8,true) as $label=>$count): ?><li><?php echo e($label); ?> — <?php echo e((string)$count); ?></li><?php endforeach; ?></ul></div>
      </div>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Daftar Customer</h3>
        <table class="table">
          <thead><tr><th>Nama</th><th>Demografi</th><th>Kontak</th><th>Transaksi</th><th>Top Produk</th><th>Kategori</th><th>Terdaftar</th><?php if (($me['role'] ?? '') === 'owner'): ?><th>Aksi</th><?php endif; ?></tr></thead>
          <tbody>
            <?php if (empty($customers)): ?><tr><td colspan="<?php echo ($me['role'] ?? '') === 'owner' ? '8' : '7'; ?>">Belum ada data pelanggan.</td></tr><?php else: ?>
              <?php foreach ($customers as $c): $age=customer_age($c['birth_date'] ?? null); $cat=customer_category((int)$c['order_count'], (float)$c['total_spend'], $c['last_order_at'] ?? null); ?>
                <tr>
                  <td><?php echo e($c['name']); ?><br><small><?php echo e($c['instagram'] ? '@'.$c['instagram'] : 'IG belum diisi'); ?></small></td>
                  <td><?php echo e($age !== null ? $age.' th' : '-'); ?> · <?php echo e($genderLabels[$c['gender'] ?? ''] ?? '-'); ?><br><small><?php echo e($c['domicile'] ?: '-'); ?></small></td>
                  <td><?php echo e($c['phone'] ?: '-'); ?><br><small><?php echo e($c['email'] ?: '-'); ?></small></td>
                  <td><?php echo e((string)$c['order_count']); ?> order<br><small>Rp <?php echo e(format_number_id((float)$c['total_spend'],0)); ?></small></td>
                  <td><?php echo e($c['top_product'] ?: '-'); ?></td>
                  <td><span class="badge"><?php echo e($categoryLabels[$cat] ?? $cat); ?></span><br><small>Last: <?php echo e($c['last_order_at'] ?: '-'); ?></small></td>
                  <td><?php echo e($c['created_at']); ?></td>
                  <?php if (($me['role'] ?? '') === 'owner'): ?><td><form method="post" data-confirm="Hapus pelanggan ini?"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete_customer"><input type="hidden" name="customer_id" value="<?php echo e((string)$c['id']); ?>"><button class="btn btn-ghost" type="submit">Hapus</button></form></td><?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
