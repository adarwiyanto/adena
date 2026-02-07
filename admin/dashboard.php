<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';

date_default_timezone_set('Asia/Jakarta');

start_secure_session();
require_login();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeLogo = setting('store_logo', '');
$customCss = setting('custom_css', '');
$u = current_user();
$role = $u['role'] ?? '';

if (is_employee_role($role) || $role === '' || $role === null) {
  redirect(base_url('pos/index.php'));
  exit;
}

if ($role !== 'admin' && $role !== 'owner') {
  http_response_code(403);
  exit('Forbidden');
}

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

$stats = [
  'products' => (int)db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'],
  'sales' => 0,
  'revenue' => 0.0,
  'returns' => 0,
  'avg_transaction' => 0.0,
];

$stmt = db()->prepare("
  SELECT COUNT(*) c, COALESCE(SUM(total),0) s
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
$statsRange = $stmt->fetch();
$stats['sales'] = (int)($statsRange['c'] ?? 0);
$stats['revenue'] = (float)($statsRange['s'] ?? 0);

$stmt = db()->prepare("
  SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c,
         COALESCE(SUM(total),0) s
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
$avgRow = $stmt->fetch();
$txCount = (int)($avgRow['c'] ?? 0);
$stats['avg_transaction'] = $txCount > 0 ? ((float)$avgRow['s'] / $txCount) : 0.0;

$stmt = db()->prepare("
  SELECT COUNT(*) c
  FROM sales
  WHERE COALESCE(returned_at, sold_at) >= ?
    AND COALESCE(returned_at, sold_at) < ?
    AND return_reason IS NOT NULL
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
$stats['returns'] = (int)($stmt->fetch()['c'] ?? 0);

$stmt = db()->prepare("
  SELECT payment_method, COUNT(*) c, COALESCE(SUM(total),0) s
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
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

$peakRange = $_GET['peak_range'] ?? 'all_time';
$peakStartInput = $_GET['peak_start'] ?? '';
$peakEndInput = $_GET['peak_end'] ?? '';
$peakStart = null;
$peakEnd = null;
$peakLabel = '';

switch ($peakRange) {
  case 'this_week':
    $peakStart = $today->modify('monday this week');
    $peakEnd = $peakStart->modify('+1 week');
    $peakLabel = 'Minggu Ini';
    break;
  case 'this_month':
    $peakStart = $today->modify('first day of this month');
    $peakEnd = $peakStart->modify('+1 month');
    $peakLabel = 'Bulan Ini';
    break;
  case 'custom':
    $parsedStart = DateTimeImmutable::createFromFormat('Y-m-d', $peakStartInput);
    $parsedEnd = DateTimeImmutable::createFromFormat('Y-m-d', $peakEndInput);
    if ($parsedStart && $parsedEnd) {
      if ($parsedStart > $parsedEnd) {
        $tmp = $parsedStart;
        $parsedStart = $parsedEnd;
        $parsedEnd = $tmp;
      }
      $peakStart = $parsedStart;
      $peakEnd = $parsedEnd->modify('+1 day');
      $peakLabel = 'Custom';
    } else {
      $peakRange = 'all_time';
      $peakLabel = 'Semua Waktu';
    }
    break;
  case 'all_time':
  default:
    $peakRange = 'all_time';
    $peakLabel = 'Semua Waktu';
    break;
}

$peakDays = 1;
$peakParams = [];
$peakWhere = '';
if ($peakRange === 'all_time') {
  $row = db()->query("SELECT MIN(sold_at) AS min_date, MAX(sold_at) AS max_date FROM sales WHERE return_reason IS NULL")->fetch();
  if (!empty($row['min_date']) && !empty($row['max_date'])) {
    $peakStart = new DateTimeImmutable($row['min_date']);
    $peakEnd = (new DateTimeImmutable($row['max_date']))->modify('+1 day');
  }
}
if ($peakStart && $peakEnd) {
  $peakWhere = "AND sold_at >= ? AND sold_at < ?";
  $peakParams[] = $peakStart->format('Y-m-d H:i:s');
  $peakParams[] = $peakEnd->format('Y-m-d H:i:s');
  $peakDays = max(1, (int)$peakEnd->diff($peakStart)->days);
}

$hourlyCounts = array_fill(0, 24, 0);
$stmt = db()->prepare("
  SELECT HOUR(tx_time) h, COUNT(*) c
  FROM (
    SELECT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id)) AS tx_code,
           MIN(sold_at) AS tx_time
    FROM sales
    WHERE return_reason IS NULL
    {$peakWhere}
    GROUP BY COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))
  ) t
  GROUP BY HOUR(tx_time)
  ORDER BY h ASC
");
$stmt->execute($peakParams);
foreach ($stmt->fetchAll() as $row) {
  $hour = (int)($row['h'] ?? 0);
  if ($hour >= 0 && $hour <= 23) {
    $hourlyCounts[$hour] = (int)$row['c'];
  }
}

$hourlyAverages = [];
$maxHourly = 0.0;
foreach ($hourlyCounts as $hour => $count) {
  $avg = $peakDays > 0 ? $count / $peakDays : 0;
  $hourlyAverages[$hour] = $avg;
  if ($avg > $maxHourly) {
    $maxHourly = $avg;
  }
}

if ($role === 'admin') {
  $stmt = db()->prepare("
    SELECT COUNT(*) c, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
  ");
  $stmt->execute([$todayStartStr, $todayEndStr]);
  $row = $stmt->fetch();

  $stmt = db()->prepare("
    SELECT COUNT(*) c
    FROM sales
    WHERE COALESCE(returned_at, sold_at) >= ?
      AND COALESCE(returned_at, sold_at) < ?
      AND return_reason IS NOT NULL
  ");
  $stmt->execute([$todayStartStr, $todayEndStr]);
  $returnsToday = (int)($stmt->fetch()['c'] ?? 0);

  $stmt = db()->prepare("
    SELECT COUNT(*) c
    FROM sales
    WHERE sold_at >= ?
      AND sold_at < ?
      AND return_reason IS NULL
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
    SELECT COUNT(*) c, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
  ");
  $stmt->execute([$todayStartStr, $todayEndStr]);
  $todayRow = $stmt->fetch();

  $stmt->execute([$monthStartStr, $monthEndStr]);
  $monthRow = $stmt->fetch();

  $stmt->execute([$lastMonthStartStr, $lastMonthEndStr]);
  $lastMonthRow = $stmt->fetch();

  $stmt = db()->prepare("
    SELECT COUNT(*) c
    FROM sales
    WHERE COALESCE(returned_at, sold_at) >= ?
      AND COALESCE(returned_at, sold_at) < ?
      AND return_reason IS NOT NULL
  ");
  $stmt->execute([$monthStartStr, $monthEndStr]);
  $returnsMonth = (int)($stmt->fetch()['c'] ?? 0);

  $stmt = db()->prepare("
    SELECT payment_method, COUNT(*) c, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
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
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
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
    WHERE s.sold_at >= ? AND s.sold_at < ? AND s.return_reason IS NULL
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
      AND s.return_reason IS NULL
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
        AND s.return_reason IS NULL
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
  return 'Rp ' . number_format((float)$amount, 0, '.', ',');
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
    .hourly-chart {
      display: grid;
      gap: 10px;
      grid-template-columns: repeat(auto-fit, minmax(70px, 1fr));
      align-items: end;
      margin-top: 12px;
    }
    .hourly-bar {
      display: grid;
      gap: 6px;
      justify-items: center;
    }
    .hourly-bar-value {
      font-size: 12px;
      color: #334155;
    }
    .hourly-bar-fill {
      width: 100%;
      border-radius: 10px 10px 4px 4px;
      background: linear-gradient(180deg, rgba(59,130,246,.9), rgba(59,130,246,.35));
      min-height: 12px;
    }
    .hourly-bar-label {
      font-size: 11px;
      color: #64748b;
    }
    .hourly-filter {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: flex-end;
    }
    .hourly-filter .row {
      margin: 0;
    }
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

      <div class="content">
        <div class="card" style="margin-bottom:16px">
          <h3 style="margin-top:0">Filter Periode</h3>
          <form method="get" style="margin-bottom:12px">
            <div class="row">
              <label>Periode</label>
              <select name="range" id="sales-range">
                <option value="today" <?php echo $range === 'today' ? 'selected' : ''; ?>>Hari ini</option>
                <option value="yesterday" <?php echo $range === 'yesterday' ? 'selected' : ''; ?>>Kemarin</option>
                <option value="last7" <?php echo $range === 'last7' ? 'selected' : ''; ?>>7 hari terakhir</option>
                <option value="this_month" <?php echo $range === 'this_month' ? 'selected' : ''; ?>>Bulan ini</option>
                <option value="last_month" <?php echo $range === 'last_month' ? 'selected' : ''; ?>>Bulan lalu</option>
                <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom</option>
              </select>
            </div>
            <div class="row" id="custom-range" style="display:<?php echo $range === 'custom' ? 'grid' : 'none'; ?>;gap:8px">
              <label for="start">Mulai</label>
              <input type="date" name="start" id="start" value="<?php echo e($_GET['start'] ?? $today->format('Y-m-d')); ?>">
              <label for="end">Sampai</label>
              <input type="date" name="end" id="end" value="<?php echo e($_GET['end'] ?? $today->format('Y-m-d')); ?>">
            </div>
            <button class="btn" type="submit">Terapkan</button>
          </form>
          <p><small>Periode: <?php echo e($rangeLabel); ?></small></p>
        </div>

        <div class="grid cols-4">
          <div class="card">
            <h4 style="margin-top:0">Total Produk</h4>
            <div style="font-size:24px;font-weight:600"><?php echo e((string)$stats['products']); ?></div>
          </div>
          <div class="card">
            <h4 style="margin-top:0">Transaksi</h4>
            <div style="font-size:24px;font-weight:600"><?php echo e((string)$stats['sales']); ?></div>
          </div>
          <div class="card">
            <h4 style="margin-top:0">Omzet</h4>
            <div style="font-size:24px;font-weight:600"><?php echo e(format_rupiah($stats['revenue'])); ?></div>
          </div>
          <div class="card">
            <h4 style="margin-top:0">Retur</h4>
            <div style="font-size:24px;font-weight:600"><?php echo e((string)$stats['returns']); ?></div>
          </div>
          <div class="card">
            <h4 style="margin-top:0">Rata-rata Belanja</h4>
            <div style="font-size:24px;font-weight:600"><?php echo e(format_rupiah($stats['avg_transaction'])); ?></div>
          </div>
        </div>

        <div class="card" style="margin-top:16px">
          <h3 style="margin-top:0">Grafik Rata-rata Jam Kunjungan</h3>
          <p style="margin:4px 0 12px;color:var(--muted)">Rata-rata jumlah transaksi per jam berdasarkan periode yang dipilih.</p>
          <form method="get" class="hourly-filter">
            <input type="hidden" name="range" value="<?php echo e($range); ?>">
            <?php if (!empty($_GET['start'])): ?>
              <input type="hidden" name="start" value="<?php echo e($_GET['start']); ?>">
            <?php endif; ?>
            <?php if (!empty($_GET['end'])): ?>
              <input type="hidden" name="end" value="<?php echo e($_GET['end']); ?>">
            <?php endif; ?>
            <div class="row" style="min-width:160px">
              <label>Periode</label>
              <select name="peak_range" id="peak-range">
                <option value="all_time" <?php echo $peakRange === 'all_time' ? 'selected' : ''; ?>>All time</option>
                <option value="this_week" <?php echo $peakRange === 'this_week' ? 'selected' : ''; ?>>Minggu ini</option>
                <option value="this_month" <?php echo $peakRange === 'this_month' ? 'selected' : ''; ?>>Bulan ini</option>
                <option value="custom" <?php echo $peakRange === 'custom' ? 'selected' : ''; ?>>Custom</option>
              </select>
            </div>
            <div class="row" id="peak-custom-start" style="min-width:160px;display:<?php echo $peakRange === 'custom' ? 'grid' : 'none'; ?>">
              <label>Mulai</label>
              <input type="date" name="peak_start" value="<?php echo e($peakStartInput ?: $today->format('Y-m-d')); ?>">
            </div>
            <div class="row" id="peak-custom-end" style="min-width:160px;display:<?php echo $peakRange === 'custom' ? 'grid' : 'none'; ?>">
              <label>Sampai</label>
              <input type="date" name="peak_end" value="<?php echo e($peakEndInput ?: $today->format('Y-m-d')); ?>">
            </div>
            <button class="btn" type="submit">Terapkan</button>
          </form>
          <p style="margin:10px 0 0"><small>Periode grafik: <?php echo e($peakLabel); ?> · <?php echo e((string)$peakDays); ?> hari</small></p>
          <div class="hourly-chart">
            <?php foreach ($hourlyAverages as $hour => $avg): ?>
              <?php
                $height = $maxHourly > 0 ? ($avg / $maxHourly) * 120 : 0;
                $label = str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00';
              ?>
              <div class="hourly-bar">
                <div class="hourly-bar-value"><?php echo e(number_format($avg, 1)); ?></div>
                <div class="hourly-bar-fill" style="height:<?php echo e(number_format($height, 2)); ?>px"></div>
                <div class="hourly-bar-label"><?php echo e($label); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

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
                    echo e(number_format($growth, 1)) . '%';
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
              <strong><?php echo e(number_format($returnRate, 1)); ?>%</strong>
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
    const rangeSelect = document.querySelector('#sales-range');
    const customRange = document.querySelector('#custom-range');
    if (rangeSelect && customRange) {
      rangeSelect.addEventListener('change', () => {
        customRange.style.display = rangeSelect.value === 'custom' ? 'grid' : 'none';
      });
    }

    const peakSelect = document.querySelector('#peak-range');
    const peakStart = document.querySelector('#peak-custom-start');
    const peakEnd = document.querySelector('#peak-custom-end');
    if (peakSelect && peakStart && peakEnd) {
      const togglePeakCustom = () => {
        const show = peakSelect.value === 'custom';
        peakStart.style.display = show ? 'grid' : 'none';
        peakEnd.style.display = show ? 'grid' : 'none';
      };
      peakSelect.addEventListener('change', togglePeakCustom);
      togglePeakCustom();
    }
  </script>
</body>
</html>
