<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/dashboard_charts.php';

date_default_timezone_set('Asia/Jakarta');

start_secure_session();
require_login();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeLogo = setting('store_logo', '');
$customCss = setting('custom_css', '');
ensure_rbac_schema();
$u = require_menu_access('dashboard', 'view');
$role = $u['role'] ?? '';

$range = $_GET['range'] ?? 'today';
$rangeStart = null;
$rangeEnd = null;
$rangeLabel = '';

$today = new DateTimeImmutable('today');
switch ($range) {
  case 'yesterday':
    $rangeStart = $today->modify('-1 day');
    $rangeEnd = $today;
    $rangeLabel = 'Kemarin';
    break;
  case 'last7':
    $rangeStart = $today->modify('-6 days');
    $rangeEnd = $today->modify('+1 day');
    $rangeLabel = '7 Hari Terakhir';
    break;
  case 'this_month':
    $rangeStart = $today->modify('first day of this month');
    $rangeEnd = $rangeStart->modify('+1 month');
    $rangeLabel = 'Bulan Ini';
    break;
  case 'last_month':
    $rangeStart = $today->modify('first day of last month');
    $rangeEnd = $rangeStart->modify('+1 month');
    $rangeLabel = 'Bulan Lalu';
    break;
  case 'custom':
    $startInput = $_GET['start'] ?? '';
    $endInput = $_GET['end'] ?? '';
    if ($startInput && $endInput) {
      $rangeStart = new DateTimeImmutable($startInput);
      $rangeEnd = (new DateTimeImmutable($endInput))->modify('+1 day');
      $rangeLabel = 'Custom';
    } else {
      $rangeStart = $today;
      $rangeEnd = $today->modify('+1 day');
      $rangeLabel = 'Hari Ini';
      $range = 'today';
    }
    break;
  case 'today':
  default:
    $rangeStart = $today;
    $rangeEnd = $today->modify('+1 day');
    $rangeLabel = 'Hari Ini';
    $range = 'today';
    break;
}

$rangeStartStr = $rangeStart->format('Y-m-d H:i:s');
$rangeEndStr = $rangeEnd->format('Y-m-d H:i:s');
$hourlyPayload = dashboard_hourly_payload([
  'range' => 'today',
]);
$weekdayPayload = dashboard_weekday_payload([
  'range' => 'weekly',
]);

$stats = [
  'products' => (int)db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'],
  'sales' => 0,
  'revenue' => 0.0,
  'returns' => 0,
  'avg_transaction' => 0.0,
];

$stmt = db()->prepare("
  SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c,
         COALESCE(SUM(total),0) s
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
$statsRange = $stmt->fetch();
$stats['sales'] = (int)($statsRange['c'] ?? 0);
$stats['revenue'] = (float)($statsRange['s'] ?? 0);
$stats['avg_transaction'] = $stats['sales'] > 0 ? ($stats['revenue'] / $stats['sales']) : 0.0;

$stmt = db()->prepare("
  SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c
  FROM sales
  WHERE COALESCE(returned_at, sold_at) >= ?
    AND COALESCE(returned_at, sold_at) < ?
    AND return_reason IS NOT NULL
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
$stats['returns'] = (int)($stmt->fetch()['c'] ?? 0);

$stmt = db()->prepare("
  SELECT payment_method, COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c, COALESCE(SUM(total),0) s
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
  GROUP BY payment_method
  ORDER BY s DESC
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
$paymentBreakdown = $stmt->fetchAll();

$stmt = db()->prepare("
  SELECT s.*, p.name product_name
  FROM sales s
  JOIN products p ON p.id = s.product_id
  ORDER BY s.sold_at DESC
  LIMIT 10
");
$stmt->execute();
$recentActivity = $stmt->fetchAll();

$adminStats = [];
$superStats = [];
$trendRows = [];
$topProducts = [];
$deadStock = [];
$sharePaymentsMonth = [];
$recentReturns = [];

$todayStart = $today;
$todayEnd = $today->modify('+1 day');
$todayStartStr = $todayStart->format('Y-m-d H:i:s');
$todayEndStr = $todayEnd->format('Y-m-d H:i:s');

if ($role === 'admin') {
  $stmt = db()->prepare("
    SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
  ");
  $stmt->execute([$todayStartStr, $todayEndStr]);
  $row = $stmt->fetch();

  $stmt = db()->prepare("
    SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c
    FROM sales
    WHERE COALESCE(returned_at, sold_at) >= ?
      AND COALESCE(returned_at, sold_at) < ?
      AND return_reason IS NOT NULL
  ");
  $stmt->execute([$todayStartStr, $todayEndStr]);
  $returnsToday = (int)($stmt->fetch()['c'] ?? 0);

  $stmt = db()->prepare("
    SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c
    FROM sales
    WHERE sold_at >= ?
      AND sold_at < ?
      AND return_reason IS NULL AND is_active_revision=1
      AND payment_method != 'cash'
      AND payment_proof_path IS NULL
  ");
  $stmt->execute([$rangeStartStr, $rangeEndStr]);
  $attention = (int)($stmt->fetch()['c'] ?? 0);

  $adminStats = [
    'sales_today' => (int)($row['c'] ?? 0),
    'revenue_today' => (float)($row['s'] ?? 0),
    'returns_today' => $returnsToday,
    'attention' => $attention,
  ];

  $stmt = db()->prepare("
    SELECT s.*, p.name product_name
    FROM sales s
    JOIN products p ON p.id = s.product_id
    WHERE s.return_reason IS NOT NULL
    ORDER BY COALESCE(s.returned_at, s.sold_at) DESC
    LIMIT 5
  ");
  $stmt->execute();
  $recentReturns = $stmt->fetchAll();
}

if ($role === 'owner') {
  $monthStart = $today->modify('first day of this month');
  $monthEnd = $monthStart->modify('+1 month');
  $lastMonthStart = $today->modify('first day of last month');
  $lastMonthEnd = $lastMonthStart->modify('+1 month');

  $monthStartStr = $monthStart->format('Y-m-d H:i:s');
  $monthEndStr = $monthEnd->format('Y-m-d H:i:s');
  $lastMonthStartStr = $lastMonthStart->format('Y-m-d H:i:s');
  $lastMonthEndStr = $lastMonthEnd->format('Y-m-d H:i:s');

  $stmt = db()->prepare("
    SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
  ");
  $stmt->execute([$todayStartStr, $todayEndStr]);
  $todayRow = $stmt->fetch();

  $stmt->execute([$monthStartStr, $monthEndStr]);
  $monthRow = $stmt->fetch();

  $stmt->execute([$lastMonthStartStr, $lastMonthEndStr]);
  $lastMonthRow = $stmt->fetch();

  $stmt = db()->prepare("
    SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c
    FROM sales
    WHERE COALESCE(returned_at, sold_at) >= ?
      AND COALESCE(returned_at, sold_at) < ?
      AND return_reason IS NOT NULL
  ");
  $stmt->execute([$monthStartStr, $monthEndStr]);
  $returnsMonth = (int)($stmt->fetch()['c'] ?? 0);

  $stmt = db()->prepare("
    SELECT payment_method, COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
    GROUP BY payment_method
    ORDER BY s DESC
  ");
  $stmt->execute([$monthStartStr, $monthEndStr]);
  $sharePaymentsMonth = $stmt->fetchAll();

  $superStats = [
    'sales_today' => (float)($todayRow['s'] ?? 0),
    'sales_month' => (float)($monthRow['s'] ?? 0),
    'tx_today' => (int)($todayRow['c'] ?? 0),
    'tx_month' => (int)($monthRow['c'] ?? 0),
    'sales_last_month' => (float)($lastMonthRow['s'] ?? 0),
    'returns_month' => $returnsMonth,
  ];

  $trendStart = $today->modify('-6 days');
  $trendStartStr = $trendStart->format('Y-m-d H:i:s');
  $trendEndStr = $todayEndStr;

  $stmt = db()->prepare("
    SELECT DATE(sold_at) d, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL AND is_active_revision=1
    GROUP BY DATE(sold_at)
    ORDER BY d ASC
  ");
  $stmt->execute([$trendStartStr, $trendEndStr]);
  $trendRowsRaw = $stmt->fetchAll();
  $trendMap = [];
  foreach ($trendRowsRaw as $row) {
    $trendMap[$row['d']] = (float)$row['s'];
  }
  $trendRows = [];
  for ($i = 0; $i < 7; $i++) {
    $day = $trendStart->modify('+' . $i . ' days');
    $key = $day->format('Y-m-d');
    $trendRows[] = [
      'date' => $key,
      'amount' => $trendMap[$key] ?? 0,
    ];
  }

  $stmt = db()->prepare("
    SELECT p.name, SUM(s.qty) qty, COALESCE(SUM(s.total),0) omzet
    FROM sales s
    JOIN products p ON p.id = s.product_id
    WHERE s.sold_at >= ? AND s.sold_at < ? AND s.return_reason IS NULL AND is_active_revision=1
    GROUP BY s.product_id
    ORDER BY qty DESC
    LIMIT 5
  ");
  $stmt->execute([$monthStartStr, $monthEndStr]);
  $topProducts = $stmt->fetchAll();

  $last30Start = $today->modify('-30 days');
  $last30StartStr = $last30Start->format('Y-m-d H:i:s');
  $last30EndStr = $todayEndStr;

  $stmt = db()->prepare("
    SELECT p.name
    FROM products p
    LEFT JOIN sales s
      ON s.product_id = p.id
      AND s.return_reason IS NULL AND is_active_revision=1
      AND s.sold_at >= ?
      AND s.sold_at < ?
    WHERE s.id IS NULL
    ORDER BY p.name ASC
    LIMIT 5
  ");
  $stmt->execute([$last30StartStr, $last30EndStr]);
  $deadStock = $stmt->fetchAll();

  if (count($deadStock) === 0) {
    $stmt = db()->prepare("
      SELECT p.name, COALESCE(SUM(s.qty),0) qty, COALESCE(SUM(s.total),0) omzet
      FROM products p
      LEFT JOIN sales s
        ON s.product_id = p.id
        AND s.return_reason IS NULL AND is_active_revision=1
        AND s.sold_at >= ?
        AND s.sold_at < ?
      GROUP BY p.id
      ORDER BY qty ASC, p.name ASC
      LIMIT 5
    ");
    $stmt->execute([$last30StartStr, $last30EndStr]);
    $deadStock = $stmt->fetchAll();
  }
}

function format_rupiah($amount)
{
  return 'Rp ' . format_number_id((float)$amount);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .content.dashboard-compact {
      max-width: none;
      padding: 18px 20px 28px;
      margin-left: auto;
      margin-right: auto;
    }
    .dashboard-compact .card { padding: 12px 14px; border-radius: 12px; margin-bottom: 10px; }
    .dashboard-compact .dash-filter-card,
    .dashboard-compact .dash-chart-card { max-width: 100%; }
    .dashboard-compact .dash-filter-card h3,
    .dashboard-compact .dash-chart-card h3 { font-size: 15px; margin-bottom: 6px; }
    .dashboard-compact .grid { gap: 10px; }
    .dashboard-compact .dash-kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
      gap: 10px;
      justify-content: start;
      align-items: stretch;
      margin-bottom: 12px;
    }
    .dashboard-compact .dash-kpi-card {
      min-height: 66px;
      display: flex;
      align-items: stretch;
      justify-content: center;
      width: 100%;
    }
    .dashboard-compact .dash-kpi-line {
      display:flex;
      flex-direction:column;
      justify-content:space-between;
      gap:6px;
      width:100%;
    }
    .dashboard-compact .dash-kpi-label { margin: 0; font-size: 12px; line-height: 1.2; font-weight:700; color:#0f172a; }
    .dashboard-compact .dash-kpi-value { font-size: 16px; font-weight: 800; }
    .dashboard-compact .dash-filter-card form { margin-bottom: 6px !important; }
    .dashboard-compact .dash-filter-card .row { margin-bottom: 6px; }
    .dashboard-compact .dash-chart-card { padding-bottom: 8px; }
    .dashboard-compact .dash-chart-desc { font-size: 12px; margin: 2px 0 6px !important; }
    @media (min-width: 981px) {
      .content.dashboard-compact { width: 100%; max-width: none; padding: 18px 24px 32px; }
      .dashboard-compact .dash-filter-card { padding: 10px 12px; }
      .dashboard-filter-form { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
      .dashboard-filter-form .row { min-width: 150px; margin:0 !important; }
      .dashboard-filter-form .row > label { display:none; }
      .dashboard-filter-form .dashboard-filter-title { min-width:auto; align-self:center; margin-right:8px; display:flex; flex-direction:column; justify-content:center; }
      .dashboard-filter-form .dashboard-filter-title h3 { margin:0; white-space:nowrap; line-height:1.1; }
      .dashboard-filter-form .dashboard-filter-title small { line-height:1.2; margin-top:4px; }
      .dashboard-filter-form .dashboard-custom-range { display:flex !important; gap:8px; align-items:center; }
      .dashboard-filter-form .dashboard-custom-range[hidden] { display:none !important; }
      .dashboard-filter-form input, .dashboard-filter-form select { min-height:34px; padding:6px 10px; border-radius:9px; }
      .dashboard-filter-form .btn { min-height:34px; padding:6px 12px; border-radius:9px; }
      .dashboard-compact .dash-kpi-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
      .dashboard-compact .dash-kpi-card { min-height:44px; align-items:center; }
      .dashboard-compact .dash-kpi-line { flex-direction:row; align-items:baseline; justify-content:center; gap:6px; white-space:nowrap; }
      .dashboard-compact .dash-kpi-label::after { content: ':'; }
      .dashboard-compact .dash-kpi-value { font-size:15px; }
      .dashboard-compact .grid.cols-3 { grid-template-columns: repeat(3, minmax(0,1fr)); }
      .dashboard-compact .grid.cols-4 { grid-template-columns: repeat(4, minmax(0,1fr)); }
      .dashboard-compact .grid.cols-2 { grid-template-columns: repeat(2, minmax(0,1fr)); }
      .dashboard-chart-grid { display:grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap:12px; }
    }
    @media (max-width: 980px) {
      .content.dashboard-compact { max-width: none; padding: 14px; }
      .dashboard-compact .dash-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .dashboard-chart-grid { display:block; }
    }
    .kpi-subtitle {
      margin: 4px 0 0;
      font-size: 12px;
      color: #6b7280;
    }
    .grid.cols-3 {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .grid.cols-4 {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    @media (max-width: 980px) {
      .grid.cols-3,
      .grid.cols-4 {
        grid-template-columns: 1fr;
      }
    }
    .visit-chart {
      display: grid;
      gap: 4px;
      align-items: end;
      margin-top: 6px;
      overflow-x: auto;
      padding-bottom: 2px;
      min-height: 92px;
    }
    .hourly-chart { grid-template-columns: repeat(24, minmax(20px, 1fr)); }
    .daily-chart { grid-template-columns: repeat(auto-fit, minmax(28px, 1fr)); }
    .visit-bar {
      display: grid;
      gap: 2px;
      justify-items: center;
      min-width: 20px;
      align-content:end;
    }
    .visit-bar-value {
      font-size: 9px;
      color: #334155;
    }
    .visit-bar-fill {
      width: 100%;
      max-width: 28px;
      border-radius: 7px 7px 3px 3px;
      background: linear-gradient(180deg, rgba(59,130,246,.9), rgba(59,130,246,.35));
      min-height: 6px;
    }
    .visit-bar-label {
      font-size: 8px;
      color: #64748b;
      white-space:nowrap;
    }
    .hourly-filter {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: flex-end;
    }
    .hourly-filter .row {
      margin: 0;
    }
    .chart-card-head {
      display:flex;
      gap:10px;
      align-items:flex-start;
      justify-content:space-between;
      flex-wrap:wrap;
    }
    .chart-filter-form {
      display:flex;
      gap:8px;
      align-items:center;
      flex-wrap:wrap;
      justify-content:flex-end;
    }
    .chart-filter-form .row { margin:0; }
    .chart-filter-form label { display:none; }
    .chart-filter-form select,
    .chart-filter-form input { min-height:32px; padding:5px 9px; border-radius:9px; font-size:12px; }
    .chart-filter-form .chart-custom-range { display:flex; gap:6px; align-items:center; }
    .chart-filter-form .chart-custom-range[hidden] { display:none !important; }
    .chart-period-info { margin:6px 0 0; color:#64748b; }
  </style>
</head>
<body>
  <div class="container">
    <?php include __DIR__ . '/partials_sidebar.php'; ?>
    <div class="main">
      <div class="topbar">
        <a class="brand-logo" href="<?php echo e(base_url('admin/dashboard.php')); ?>">
          <?php if (!empty($storeLogo)): ?>
            <img src="<?php echo e(upload_url($storeLogo, 'image')); ?>" alt="<?php echo e($storeName); ?>">
          <?php else: ?>
            <span><?php echo e($storeName); ?></span>
          <?php endif; ?>
        </a>
        <button class="burger" data-toggle-sidebar type="button">☰</button>
        <div class="title">Dasbor</div>
        <div class="spacer"></div>
        </div>

      <div class="content dashboard-compact">
        <div class="card dash-filter-card" style="margin-bottom:12px">
          <form method="get" class="dashboard-filter-form" data-dashboard-filter-form style="margin-bottom:0">
            <div class="dashboard-filter-title">
              <h3>Filter Periode</h3>
              <small>Periode: <span data-dashboard-range-label><?php echo e($rangeLabel); ?></span></small>
            </div>
            <div class="row">
              <label>Periode</label>
              <select name="range" id="sales-range" data-dashboard-range>
                <option value="today" <?php echo $range === 'today' ? 'selected' : ''; ?>>Hari ini</option>
                <option value="yesterday" <?php echo $range === 'yesterday' ? 'selected' : ''; ?>>Kemarin</option>
                <option value="last7" <?php echo $range === 'last7' ? 'selected' : ''; ?>>7 hari terakhir</option>
                <option value="this_month" <?php echo $range === 'this_month' ? 'selected' : ''; ?>>Bulan ini</option>
                <option value="last_month" <?php echo $range === 'last_month' ? 'selected' : ''; ?>>Bulan lalu</option>
                <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom</option>
              </select>
            </div>
            <div class="dashboard-custom-range" id="custom-range" <?php echo $range === 'custom' ? '' : 'hidden'; ?>>
              <div class="row">
                <label for="start">Mulai</label>
                <input type="date" name="start" id="start" data-dashboard-start value="<?php echo e($_GET['start'] ?? $today->format('Y-m-d')); ?>">
              </div>
              <div class="row">
                <label for="end">Sampai</label>
                <input type="date" name="end" id="end" data-dashboard-end value="<?php echo e($_GET['end'] ?? $today->format('Y-m-d')); ?>">
              </div>
            </div>
            <button class="btn" type="submit">Terapkan</button>
          </form>
        </div>

        <div class="dash-kpi-grid">
          <div class="card dash-kpi-card">
            <div class="dash-kpi-line"><span class="dash-kpi-label">Total Produk</span><span class="dash-kpi-value"><?php echo e((string)$stats['products']); ?></span></div>
          </div>
          <div class="card dash-kpi-card">
            <div class="dash-kpi-line"><span class="dash-kpi-label">Transaksi</span><span class="dash-kpi-value"><?php echo e((string)$stats['sales']); ?></span></div>
          </div>
          <div class="card dash-kpi-card">
            <div class="dash-kpi-line"><span class="dash-kpi-label">Omzet</span><span class="dash-kpi-value"><?php echo e(format_rupiah($stats['revenue'])); ?></span></div>
          </div>
          <div class="card dash-kpi-card">
            <div class="dash-kpi-line"><span class="dash-kpi-label">Retur</span><span class="dash-kpi-value"><?php echo e((string)$stats['returns']); ?></span></div>
          </div>
          <div class="card dash-kpi-card">
            <div class="dash-kpi-line"><span class="dash-kpi-label">Rata-rata Belanja</span><span class="dash-kpi-value"><?php echo e(format_rupiah($stats['avg_transaction'])); ?></span></div>
          </div>
        </div>

        <div class="dashboard-chart-grid" style="margin-top:12px">
          <div class="card dash-chart-card">
            <div class="chart-card-head">
              <div>
                <h3 style="margin-top:0">Grafik Rata-rata Jam Kunjungan</h3>
                <p class="dash-chart-desc" style="color:var(--muted)">Rata-rata transaksi unik per jam berdasarkan periode grafik.</p>
              </div>
              <form class="chart-filter-form" data-chart-filter="hourly" data-chart-type="hourly">
                <div class="row">
                  <select name="range" data-chart-range>
                    <option value="today" selected>Hari ini</option>
                    <option value="yesterday">Kemarin</option>
                    <option value="last7">7 hari terakhir</option>
                    <option value="this_month">Bulan ini</option>
                    <option value="last_month">Bulan lalu</option>
                    <option value="custom">Custom</option>
                  </select>
                </div>
                <div class="chart-custom-range" data-chart-custom-range hidden>
                  <input type="date" name="start" data-chart-start value="<?php echo e($today->format('Y-m-d')); ?>">
                  <input type="date" name="end" data-chart-end value="<?php echo e($today->format('Y-m-d')); ?>">
                </div>
              </form>
            </div>
            <p class="chart-period-info"><small>Periode grafik: <span data-hourly-chart-label><?php echo e($hourlyPayload['label']); ?></span> · <span data-hourly-chart-days><?php echo e((string)$hourlyPayload['days']); ?></span> hari</small></p>
            <div class="visit-chart hourly-chart" data-hourly-chart>
              <?php foreach ($hourlyPayload['hourly'] as $row): ?>
                <?php $height = $hourlyPayload['max_hourly'] > 0 ? (((float)$row['avg'] / (float)$hourlyPayload['max_hourly']) * 54) : 0; ?>
                <div class="visit-bar">
                  <div class="visit-bar-value"><?php echo e($row['formatted']); ?></div>
                  <div class="visit-bar-fill" style="height:<?php echo e(number_format($height, 2, '.', '')); ?>px"></div>
                  <div class="visit-bar-label"><?php echo e($row['label']); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="card dash-chart-card">
            <div class="chart-card-head">
              <div>
                <h3 style="margin-top:0">Grafik Rata-rata Kunjungan Harian</h3>
                <p class="dash-chart-desc" style="color:var(--muted)">Rata-rata transaksi unik per hari Minggu–Sabtu dalam periode terpilih.</p>
              </div>
              <form class="chart-filter-form" data-chart-filter="weekday" data-chart-type="weekday">
                <div class="row">
                  <select name="range" data-chart-range>
                    <option value="weekly" selected>Weekly</option>
                    <option value="monthly">Monthly</option>
                    <option value="yoy">YoY</option>
                    <option value="all_time">All time</option>
                    <option value="custom">Custom</option>
                  </select>
                </div>
                <div class="chart-custom-range" data-chart-custom-range hidden>
                  <input type="date" name="start" data-chart-start value="<?php echo e($today->format('Y-m-d')); ?>">
                  <input type="date" name="end" data-chart-end value="<?php echo e($today->format('Y-m-d')); ?>">
                </div>
              </form>
            </div>
            <p class="chart-period-info"><small>Periode grafik: <span data-weekday-chart-label><?php echo e($weekdayPayload['label']); ?></span> · <span data-weekday-chart-days><?php echo e((string)$weekdayPayload['days']); ?></span> hari</small></p>
            <div class="visit-chart daily-chart" data-weekday-chart>
              <?php foreach ($weekdayPayload['weekday'] as $row): ?>
                <?php $height = $weekdayPayload['max_weekday'] > 0 ? (((float)$row['avg'] / (float)$weekdayPayload['max_weekday']) * 54) : 0; ?>
                <div class="visit-bar">
                  <div class="visit-bar-value"><?php echo e($row['formatted']); ?></div>
                  <div class="visit-bar-fill" style="height:<?php echo e(number_format($height, 2, '.', '')); ?>px"></div>
                  <div class="visit-bar-label"><?php echo e($row['label']); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <?php if (in_array($role, ['owner','admin'], true)): ?>
          <?php include __DIR__ . '/dashboard_branch_customer.php'; ?>
        <?php endif; ?>

        <?php if ($role === 'owner'): ?>
          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">KPI Owner</h3>
            <p class="kpi-subtitle">Ringkasan performa penjualan toko.</p>
            <div class="grid cols-3">
              <div class="card">
                <h4 style="margin-top:0">Sales Hari Ini</h4>
                <div class="kpi-subtitle">Total omzet penjualan hari ini.</div>
                <div style="font-size:20px;font-weight:600"><?php echo e(format_rupiah($superStats['sales_today'])); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Sales Bulan Ini</h4>
                <div class="kpi-subtitle">Total omzet penjualan bulan berjalan.</div>
                <div style="font-size:20px;font-weight:600"><?php echo e(format_rupiah($superStats['sales_month'])); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Transaksi Hari Ini</h4>
                <div class="kpi-subtitle">Jumlah transaksi selesai hari ini.</div>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$superStats['tx_today']); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Transaksi Bulan Ini</h4>
                <div class="kpi-subtitle">Jumlah transaksi selesai bulan ini.</div>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$superStats['tx_month']); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">AOV Bulan Ini</h4>
                <div class="kpi-subtitle">Rata-rata nilai transaksi bulan ini.</div>
                <div style="font-size:20px;font-weight:600">
                  <?php
                  $aov = $superStats['tx_month'] > 0 ? $superStats['sales_month'] / $superStats['tx_month'] : 0;
                  echo e(format_rupiah($aov));
                  ?>
                </div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Growth vs Bulan Lalu</h4>
                <div class="kpi-subtitle">Perbandingan omzet bulan ini vs bulan lalu.</div>
                <div style="font-size:20px;font-weight:600">
                  <?php
                  if ($superStats['sales_last_month'] > 0) {
                    $growth = (($superStats['sales_month'] - $superStats['sales_last_month']) / $superStats['sales_last_month']) * 100;
                    echo e(format_number_id($growth)) . '%';
                  } else {
                    echo 'N/A';
                  }
                  ?>
                </div>
              </div>
            </div>
          </div>

          <div class="grid cols-2" style="margin-top:16px">
            <div class="card">
              <h3 style="margin-top:0">Omzet per Hari (7 hari terakhir)</h3>
              <table>
                <thead>
                  <tr>
                    <th>Tanggal</th>
                    <th>Omzet</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($trendRows as $row): ?>
                    <tr>
                      <td><?php echo e($row['date']); ?></td>
                      <td><?php echo e(format_rupiah($row['amount'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="card">
              <h3 style="margin-top:0">Share Metode Pembayaran (Bulan Ini)</h3>
              <table>
                <thead>
                  <tr>
                    <th>Metode</th>
                    <th>Transaksi</th>
                    <th>Omzet</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($sharePaymentsMonth) === 0): ?>
                    <tr>
                      <td colspan="3">Belum ada data.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($sharePaymentsMonth as $row): ?>
                      <tr>
                        <td><?php echo e($row['payment_method'] ?? '-'); ?></td>
                        <td><?php echo e((string)$row['c']); ?></td>
                        <td><?php echo e(format_rupiah($row['s'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="grid cols-2" style="margin-top:16px">
            <div class="card">
              <h3 style="margin-top:0">Top 5 Produk Terlaris (Bulan Ini)</h3>
              <table>
                <thead>
                  <tr>
                    <th>Produk</th>
                    <th>Qty</th>
                    <th>Omzet</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($topProducts) === 0): ?>
                    <tr>
                      <td colspan="3">Belum ada penjualan.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($topProducts as $row): ?>
                      <tr>
                        <td><?php echo e($row['name']); ?></td>
                        <td><?php echo e((string)$row['qty']); ?></td>
                        <td><?php echo e(format_rupiah($row['omzet'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            <div class="card">
              <h3 style="margin-top:0">Dead Stock (30 Hari)</h3>
              <table>
                <thead>
                  <tr>
                    <th>Produk</th>
                    <th>Keterangan</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($deadStock) === 0): ?>
                    <tr>
                      <td colspan="2">Semua produk punya penjualan.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($deadStock as $row): ?>
                      <tr>
                        <td><?php echo e($row['name']); ?></td>
                        <td>
                          <?php if (isset($row['qty'])): ?>
                            Qty <?php echo e((string)$row['qty']); ?>
                          <?php else: ?>
                            Tidak ada penjualan
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Return Rate Bulan Ini</h3>
            <p>
              <?php
              $returnRateDenom = $superStats['returns_month'] + $superStats['tx_month'];
              $returnRate = $returnRateDenom > 0 ? ($superStats['returns_month'] / $returnRateDenom) * 100 : 0;
              ?>
              <strong><?php echo e(format_number_id($returnRate)); ?>%</strong>
              (<?php echo e((string)$superStats['returns_month']); ?> retur dari <?php echo e((string)$returnRateDenom); ?> transaksi)
            </p>
          </div>

          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Quick Links</h3>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <a class="btn" href="<?php echo e(base_url('admin/products.php')); ?>">Produk</a>
              <a class="btn" href="<?php echo e(base_url('admin/sales.php')); ?>">Penjualan</a>
              <a class="btn" href="<?php echo e(base_url('admin/theme.php')); ?>">Tema</a>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Tugas Hari Ini</h3>
            <div class="grid cols-4">
              <div class="card">
                <h4 style="margin-top:0">Transaksi Hari Ini</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$adminStats['sales_today']); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Omzet Hari Ini</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e(format_rupiah($adminStats['revenue_today'])); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Retur Hari Ini</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$adminStats['returns_today']); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Perlu Perhatian</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$adminStats['attention']); ?></div>
              </div>
            </div>
          </div>

          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Aksi Cepat</h3>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
              <a class="btn" href="<?php echo e(base_url('pos/index.php')); ?>">Ke POS</a>
              <a class="btn" href="<?php echo e(base_url('admin/sales.php')); ?>">Penjualan</a>
              <a class="btn" href="<?php echo e(base_url('admin/products.php')); ?>">Produk</a>
              <a class="btn" href="<?php echo e(base_url('admin/theme.php')); ?>">Tema</a>
            </div>
          </div>

          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Retur Terbaru</h3>
            <table>
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <th>Produk</th>
                  <th>Qty</th>
                  <th>Alasan</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($recentReturns) === 0): ?>
                  <tr>
                    <td colspan="4">Belum ada retur.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($recentReturns as $row): ?>
                    <tr>
                      <td><?php echo e($row['returned_at'] ?? $row['sold_at']); ?></td>
                      <td><?php echo e($row['product_name']); ?></td>
                      <td><?php echo e((string)$row['qty']); ?></td>
                      <td><?php echo e($row['return_reason']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="grid cols-2" style="margin-top:16px">
          <div class="card">
            <h3 style="margin-top:0">Breakdown Metode Pembayaran</h3>
            <table>
              <thead>
                <tr>
                  <th>Metode</th>
                  <th>Transaksi</th>
                  <th>Omzet</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($paymentBreakdown) === 0): ?>
                  <tr>
                    <td colspan="3">Belum ada transaksi.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($paymentBreakdown as $row): ?>
                    <tr>
                      <td><?php echo e($row['payment_method'] ?? '-'); ?></td>
                      <td><?php echo e((string)$row['c']); ?></td>
                      <td><?php echo e(format_rupiah($row['s'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="card">
            <h3 style="margin-top:0">Aktivitas Terbaru</h3>
            <table>
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <th>Produk</th>
                  <th>Qty</th>
                  <th>Total</th>
                  <th>Metode</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($recentActivity) === 0): ?>
                  <tr>
                    <td colspan="5">Belum ada transaksi.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($recentActivity as $row): ?>
                    <tr>
                      <td>
                        <?php echo e($row['sold_at']); ?>
                        <?php if (!empty($row['return_reason'])): ?>
                          <span class="badge" style="margin-left:6px">RETUR</span>
                        <?php endif; ?>
                      </td>
                      <td><?php echo e($row['product_name']); ?></td>
                      <td><?php echo e((string)$row['qty']); ?></td>
                      <td><?php echo e(format_rupiah($row['total'])); ?></td>
                      <td><?php echo e($row['payment_method'] ?? '-'); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
  <script nonce="<?php echo e(csp_nonce()); ?>">
    const rangeSelect = document.querySelector('[data-dashboard-range]');
    const customRange = document.querySelector('#custom-range');
    const startInput = document.querySelector('[data-dashboard-start]');
    const endInput = document.querySelector('[data-dashboard-end]');

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>'"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
    }
    function barHeight(value, max) {
      if (!max || max <= 0) return 0;
      return Math.max(6, (Number(value || 0) / Number(max)) * 54);
    }
    function renderVisitChart(el, rows, max, valueKey) {
      if (!el) return;
      el.innerHTML = (rows || []).map((row) => {
        const value = Number(row[valueKey] || 0);
        const h = barHeight(value, max).toFixed(2);
        return `<div class="visit-bar"><div class="visit-bar-value">${escapeHtml(row.formatted || '0')}</div><div class="visit-bar-fill" style="height:${h}px"></div><div class="visit-bar-label">${escapeHtml(row.label || '')}</div></div>`;
      }).join('');
    }
    function syncMainKpiCustomRange() {
      if (!rangeSelect || !customRange) return;
      customRange.hidden = rangeSelect.value !== 'custom';
    }
    if (rangeSelect && customRange) {
      rangeSelect.addEventListener('change', syncMainKpiCustomRange);
      syncMainKpiCustomRange();
    }

    async function updateSingleChart(form) {
      if (!form) return;
      const chart = form.getAttribute('data-chart-type') || 'hourly';
      const rangeSelect = form.querySelector('[data-chart-range]');
      const customWrap = form.querySelector('[data-chart-custom-range]');
      const start = form.querySelector('[data-chart-start]');
      const end = form.querySelector('[data-chart-end]');
      const range = rangeSelect ? rangeSelect.value : 'today';
      if (customWrap) customWrap.hidden = range !== 'custom';
      if (range === 'custom' && (!start?.value || !end?.value)) return;

      const params = new URLSearchParams({ chart, range });
      if (range === 'custom') {
        params.set('start', start.value);
        params.set('end', end.value);
      }

      const target = chart === 'weekday' ? document.querySelector('[data-weekday-chart]') : document.querySelector('[data-hourly-chart]');
      if (target) target.setAttribute('aria-busy', 'true');
      try {
        const response = await fetch(`<?php echo e(base_url('admin/dashboard_chart_data.php')); ?>?${params.toString()}`, { headers: { 'Accept': 'application/json' } });
        if (!response.ok) throw new Error('Gagal mengambil data grafik');
        const data = await response.json();
        if (chart === 'weekday') {
          renderVisitChart(target, data.weekday, data.max_weekday, 'avg');
          const label = document.querySelector('[data-weekday-chart-label]');
          const days = document.querySelector('[data-weekday-chart-days]');
          if (label) label.textContent = data.label || '';
          if (days) days.textContent = data.days || '1';
        } else {
          renderVisitChart(target, data.hourly, data.max_hourly, 'avg');
          const label = document.querySelector('[data-hourly-chart-label]');
          const days = document.querySelector('[data-hourly-chart-days]');
          if (label) label.textContent = data.label || '';
          if (days) days.textContent = data.days || '1';
        }
      } catch (err) {
        console.error(err);
      } finally {
        if (target) target.removeAttribute('aria-busy');
      }
    }

    document.querySelectorAll('[data-chart-filter]').forEach((form) => {
      form.addEventListener('submit', (event) => event.preventDefault());
      const range = form.querySelector('[data-chart-range]');
      const customWrap = form.querySelector('[data-chart-custom-range]');
      if (range && customWrap) customWrap.hidden = range.value !== 'custom';
      if (range) range.addEventListener('change', () => updateSingleChart(form));
      form.querySelectorAll('[data-chart-start], [data-chart-end]').forEach((input) => {
        input.addEventListener('change', () => updateSingleChart(form));
      });
    });
  </script>
</body>
</html>
