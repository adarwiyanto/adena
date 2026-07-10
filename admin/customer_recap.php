<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$me = require_menu_access('customers', 'view');

function ensure_customer_recap_sales_columns(): void {
  try { db()->exec("ALTER TABLE sales ADD COLUMN customer_name VARCHAR(150) NULL"); } catch (Throwable $e) {}
  try { db()->exec("ALTER TABLE sales ADD COLUMN customer_phone VARCHAR(50) NULL"); } catch (Throwable $e) {}
  try { db()->exec("ALTER TABLE sales ADD KEY idx_sales_customer_name (customer_name)"); } catch (Throwable $e) {}
  try { db()->exec("ALTER TABLE sales ADD KEY idx_sales_customer_phone (customer_phone)"); } catch (Throwable $e) {}
}
ensure_customer_recap_sales_columns();

$q = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'name');
$dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
$sortMap = [
  'name' => 'customer_name_sort',
  'phone' => 'customer_phone_sort',
  'transactions' => 'transaction_count',
  'total' => 'total_spend',
  'last' => 'last_transaction_at',
];
$orderBy = $sortMap[$sort] ?? $sortMap['name'];
$params = [];
$where = "WHERE (COALESCE(customer_name,'') <> '' OR COALESCE(customer_phone,'') <> '')";
if ($q !== '') {
  $where .= " AND (customer_name LIKE ? OR customer_phone LIKE ?)";
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}

$stmt = db()->prepare("SELECT
    COALESCE(NULLIF(TRIM(customer_name),''), '(Tanpa nama)') AS customer_name,
    COALESCE(TRIM(customer_phone),'') AS customer_phone,
    LOWER(COALESCE(NULLIF(TRIM(customer_name),''), '(Tanpa nama)')) AS customer_name_sort,
    COALESCE(TRIM(customer_phone),'') AS customer_phone_sort,
    COUNT(DISTINCT COALESCE(transaction_group_uuid, local_transaction_id, transaction_code)) AS transaction_count,
    COALESCE(SUM(total),0) AS total_spend,
    MAX(sold_at) AS last_transaction_at
  FROM sales
  $where
  GROUP BY LOWER(COALESCE(NULLIF(TRIM(customer_name),''), '(Tanpa nama)')), COALESCE(TRIM(customer_phone),'')
  ORDER BY $orderBy $dir, customer_name_sort ASC
  LIMIT 500");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$totalCustomers = count($rows);
$totalTransactions = 0;
$totalSpend = 0;
foreach ($rows as $r) { $totalTransactions += (int)$r['transaction_count']; $totalSpend += (float)$r['total_spend']; }

function money_id($v): string { return 'Rp ' . number_format((float)$v, 0, ',', '.'); }
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Rekapitulasi Pelanggan</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body class="desktop-compact">
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">Rekapitulasi Pelanggan</div></div>
    <div class="content">
      <div class="grid cols-3" style="margin-bottom:16px">
        <div class="card"><h4 style="margin-top:0">Total Pelanggan</h4><div style="font-size:24px;font-weight:700"><?php echo e((string)$totalCustomers); ?></div></div>
        <div class="card"><h4 style="margin-top:0">Total Transaksi</h4><div style="font-size:24px;font-weight:700"><?php echo e((string)$totalTransactions); ?></div></div>
        <div class="card"><h4 style="margin-top:0">Total Belanja</h4><div style="font-size:24px;font-weight:700"><?php echo e(money_id($totalSpend)); ?></div></div>
      </div>

      <div class="card">
        <h3 style="margin-top:0">Rekapitulasi Pelanggan</h3>
        <p style="color:#64748b;margin-top:-6px">Data diambil dari transaksi POS yang memiliki nama atau nomor telepon pelanggan.</p>
        <form method="get" class="filters" style="margin-bottom:12px">
          <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Cari nama / nomor telepon">
          <select name="sort">
            <option value="name" <?php echo $sort==='name'?'selected':''; ?>>Sort nama</option>
            <option value="phone" <?php echo $sort==='phone'?'selected':''; ?>>Sort nomor telepon</option>
            <option value="transactions" <?php echo $sort==='transactions'?'selected':''; ?>>Sort jumlah transaksi</option>
            <option value="total" <?php echo $sort==='total'?'selected':''; ?>>Sort total belanja</option>
            <option value="last" <?php echo $sort==='last'?'selected':''; ?>>Sort terakhir transaksi</option>
          </select>
          <select name="dir">
            <option value="asc" <?php echo $dir==='ASC'?'selected':''; ?>>A-Z / kecil-besar</option>
            <option value="desc" <?php echo $dir==='DESC'?'selected':''; ?>>Z-A / besar-kecil</option>
          </select>
          <button class="btn" type="submit">Tampilkan</button>
        </form>
        <table class="table">
          <thead><tr><th>Nama pelanggan</th><th>No. telepon</th><th>Jumlah transaksi</th><th>Total belanja</th><th>Terakhir transaksi</th></tr></thead>
          <tbody>
            <?php if (!$rows): ?><tr><td colspan="5">Belum ada data pelanggan.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo e($r['customer_name'] ?: '-'); ?></td>
                <td><?php echo e($r['customer_phone'] ?: '-'); ?></td>
                <td><?php echo e((string)(int)$r['transaction_count']); ?></td>
                <td><?php echo e(money_id($r['total_spend'])); ?></td>
                <td><?php echo e($r['last_transaction_at'] ?: '-'); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
