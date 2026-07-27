<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/store_accounting.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$me = require_menu_access('rekap_omset', 'view');
ensure_payment_methods_table();

// --- Periode ---
$allowed = ['today', 'yesterday', '7days', 'month', 'custom'];
$period = in_array($_GET['period'] ?? '', $allowed) ? $_GET['period'] : 'today';

$tz = date_default_timezone_get();
$today = date('Y-m-d');

switch ($period) {
  case 'yesterday':
    $dateFrom = date('Y-m-d', strtotime('-1 day'));
    $dateTo   = $dateFrom;
    break;
  case '7days':
    $dateFrom = date('Y-m-d', strtotime('-6 days'));
    $dateTo   = $today;
    break;
  case 'month':
    $dateFrom = date('Y-m-01');
    $dateTo   = $today;
    break;
  case 'custom':
    $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $today;
    $dateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : $today;
    if ($dateTo < $dateFrom) $dateTo = $dateFrom;
    break;
  default: // today
    $dateFrom = $today;
    $dateTo   = $today;
}

$tsFrom = $dateFrom . ' 00:00:00';
$tsTill = $dateTo   . ' 23:59:59';

// --- Export CSV ---
$exportCsv = isset($_GET['export']) && $_GET['export'] === 'csv' && has_menu_access($me, 'rekap_omset', 'export');

// --- Perhitungan terpusat (tanpa perubahan struktur database) ---
$salesMetrics = store_acc_sales_metrics($tsFrom, date('Y-m-d H:i:s', strtotime($tsTill . ' +1 second')));

$paymentNameMap = [];
try {
  foreach (db()->query("SELECT code,name FROM payment_methods")->fetchAll(PDO::FETCH_ASSOC) as $method) {
    $paymentNameMap[(string)$method['code']] = (string)$method['name'];
  }
} catch (Throwable $e) {}

$summaryRows = [];
foreach ($salesMetrics['payment_breakdown'] as $row) {
  $code = (string)($row['payment_method'] ?? 'unknown');
  $summaryRows[] = [
    'payment_method' => $code,
    'payment_method_name' => $paymentNameMap[$code] ?? $code,
    'total_transaksi' => (int)($row['c'] ?? 0),
    'penjualan_setelah_diskon' => (float)($row['sales_after_discount'] ?? 0),
    'total_retur' => (float)($row['returns'] ?? 0),
    'total_omset' => (float)($row['s'] ?? 0),
  ];
}

$grandTotal = (float)$salesMetrics['net_sales'];
$grandTx = (int)$salesMetrics['transactions'];

$userNames = [];
$userIds = array_values(array_unique(array_filter(array_map(static fn($r) => (int)($r['created_by'] ?? 0), $salesMetrics['details']))));
if ($userIds) {
  try {
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmtUsers = db()->prepare("SELECT id,name FROM users WHERE id IN ($placeholders)");
    $stmtUsers->execute($userIds);
    foreach ($stmtUsers->fetchAll(PDO::FETCH_ASSOC) as $row) $userNames[(int)$row['id']] = (string)$row['name'];
  } catch (Throwable $e) {}
}

$detailRows = [];
foreach ($salesMetrics['details'] as $row) {
  $method = (string)($row['payment_method'] ?? 'unknown');
  $detailRows[] = $row + [
    'sold_at' => $row['event_at'] ?? null,
    'payment_method_name' => $paymentNameMap[$method] ?? $method,
    'cashier' => $userNames[(int)($row['created_by'] ?? 0)] ?? '-',
    'total' => (float)($row['net'] ?? 0),
  ];
}
// --- Export CSV ---
if ($exportCsv) {
  $label = match ($period) {
    'yesterday' => 'kemarin',
    '7days'     => '7hari',
    'month'     => 'bulanini',
    'custom'    => $dateFrom . '_sd_' . $dateTo,
    default     => 'hari_ini',
  };
  $filename = 'rekap_omset_' . $label . '.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  echo "\xEF\xBB\xBF"; // BOM for Excel UTF-8
  $out = fopen('php://output', 'w');

  fputcsv($out, ['REKAP OMSET'], ';');
  fputcsv($out, ['Periode', $dateFrom . ' s/d ' . $dateTo], ';');
  fputcsv($out, [], ';');

  // Ringkasan
  fputcsv($out, ['Ringkasan per Metode Pembayaran'], ';');
  fputcsv($out, ['Metode Pembayaran', 'Jumlah Transaksi', 'Penjualan Setelah Diskon', 'Retur', 'Omzet Bersih'], ';');
  foreach ($summaryRows as $r) {
    fputcsv($out, [
      $r['payment_method_name'],
      (int)$r['total_transaksi'],
      number_format((float)$r['penjualan_setelah_diskon'], 2, ',', '.'),
      number_format((float)$r['total_retur'], 2, ',', '.'),
      number_format((float)$r['total_omset'], 2, ',', '.'),
    ], ';');
  }
  fputcsv($out, ['TOTAL', $grandTx, number_format((float)$salesMetrics['sales_after_discount'], 2, ',', '.'), number_format((float)$salesMetrics['returns'], 2, ',', '.'), number_format($grandTotal, 2, ',', '.')], ';');
  fputcsv($out, [], ';');

  // Detail
  fputcsv($out, ['Detail Transaksi'], ';');
  fputcsv($out, ['Waktu', 'Jenis', 'Kode Transaksi', 'Metode Pembayaran', 'Bank/Akun', 'Kasir', 'Penjualan Kotor', 'Diskon Item', 'Diskon Transaksi', 'Retur', 'Omzet Bersih'], ';');
  foreach ($detailRows as $r) {
    fputcsv($out, [
      $r['sold_at'],
      $r['event_label'] ?? 'Penjualan',
      $r['transaction_code'],
      $r['payment_method_name'],
      $r['payment_bank'] ?? '-',
      $r['cashier'] ?? '-',
      number_format((float)$r['gross'], 2, ',', '.'),
      number_format((float)$r['item_discount'], 2, ',', '.'),
      number_format((float)$r['transaction_discount'], 2, ',', '.'),
      number_format((float)$r['return_amount'], 2, ',', '.'),
      number_format((float)$r['total'], 2, ',', '.'),
    ], ';');
  }
  fclose($out);
  exit;
}

// --- Label periode ---
$periodLabel = match ($period) {
  'yesterday' => 'Kemarin (' . date('d/m/Y', strtotime($dateFrom)) . ')',
  '7days'     => '7 Hari Terakhir (' . date('d/m/Y', strtotime($dateFrom)) . ' – ' . date('d/m/Y', strtotime($dateTo)) . ')',
  'month'     => 'Bulan Ini (' . date('F Y', strtotime($dateFrom)) . ')',
  'custom'    => 'Custom: ' . date('d/m/Y', strtotime($dateFrom)) . ' – ' . date('d/m/Y', strtotime($dateTo)),
  default     => 'Hari Ini (' . date('d/m/Y') . ')',
};

$canExport = has_menu_access($me, 'rekap_omset', 'export');
$customCss = setting('custom_css', '');

// URL helper untuk filter
function rekapUrl(array $params): string {
  $base = ['period' => 'today'];
  $merged = array_merge($base, $params);
  return 'rekap_omset.php?' . http_build_query($merged);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Rekap Omset</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    <?php echo $customCss; ?>
    @media print { .no-print { display: none !important; } }
    .period-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
    .period-tabs a { padding: 6px 14px; border-radius: 6px; border: 1px solid var(--border, #ccc); text-decoration: none; font-size: .875rem; color: inherit; }
    .period-tabs a.active { background: var(--primary, #0d6efd); color: #fff; border-color: var(--primary, #0d6efd); }
    .summary-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; margin-bottom: 16px; }
    .summary-card { background: var(--card-bg, #fff); border: 1px solid var(--border, #dee2e6); border-radius: 8px; padding: 12px 16px; }
    .summary-card .label { font-size: .75rem; color: var(--muted, #6c757d); margin-bottom: 4px; }
    .summary-card .value { font-size: 1.1rem; font-weight: 600; }
    .summary-card .sub { font-size: .75rem; color: var(--muted, #6c757d); margin-top: 2px; }
    .total-card { border-color: var(--primary, #0d6efd); background: var(--primary-bg, #e7f1ff); }
    tfoot td { font-weight: 600; }
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar no-print"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content">

      <div class="card no-print" style="margin-bottom:12px">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
          <h3 style="margin:0">Rekap Omset</h3>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php if ($canExport): ?>
            <a class="btn" href="rekap_omset.php?<?php echo e(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>">Export CSV</a>
            <?php endif; ?>
            <button class="btn" type="button" onclick="window.print()">Print / PDF</button>
          </div>
        </div>
      </div>

      <div class="card no-print" style="margin-bottom:12px">
        <div class="period-tabs">
          <a href="<?php echo e(rekapUrl(['period' => 'today'])); ?>" class="<?php echo $period === 'today' ? 'active' : ''; ?>">Hari Ini</a>
          <a href="<?php echo e(rekapUrl(['period' => 'yesterday'])); ?>" class="<?php echo $period === 'yesterday' ? 'active' : ''; ?>">Kemarin</a>
          <a href="<?php echo e(rekapUrl(['period' => '7days'])); ?>" class="<?php echo $period === '7days' ? 'active' : ''; ?>">7 Hari</a>
          <a href="<?php echo e(rekapUrl(['period' => 'month'])); ?>" class="<?php echo $period === 'month' ? 'active' : ''; ?>">Bulan Ini</a>
          <a href="<?php echo e(rekapUrl(['period' => 'custom', 'from' => $dateFrom, 'to' => $dateTo])); ?>" class="<?php echo $period === 'custom' ? 'active' : ''; ?>">Custom</a>
        </div>

        <?php if ($period === 'custom'): ?>
        <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <input type="hidden" name="period" value="custom">
          <label style="font-size:.875rem">Dari: <input type="date" name="from" value="<?php echo e($dateFrom); ?>" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border,#ccc)"></label>
          <label style="font-size:.875rem">S/d: <input type="date" name="to" value="<?php echo e($dateTo); ?>" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border,#ccc)"></label>
          <button class="btn" type="submit">Tampilkan</button>
        </form>
        <?php endif; ?>
      </div>

      <!-- Print header -->
      <div style="display:none" class="print-only">
        <h2 style="margin:0 0 4px"><?php echo e(setting('store_name', 'Rekap Omset')); ?></h2>
        <p style="margin:0 0 12px;color:#666">Rekap Omset &mdash; <?php echo e($periodLabel); ?></p>
      </div>
      <style>@media screen{.print-only{display:none!important}}@media print{.print-only{display:block!important}}</style>

      <div class="card" style="margin-bottom:12px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:4px">
          <h4 style="margin:0">Ringkasan &mdash; <?php echo e($periodLabel); ?></h4>
        </div>

        <div class="summary-grid">
          <div class="summary-card"><div class="label">Penjualan Kotor</div><div class="value">Rp <?php echo e(format_number_id((float)$salesMetrics['gross_sales'])); ?></div></div>
          <div class="summary-card"><div class="label">Diskon Item</div><div class="value">Rp <?php echo e(format_number_id((float)$salesMetrics['item_discount'])); ?></div></div>
          <div class="summary-card"><div class="label">Diskon Transaksi</div><div class="value">Rp <?php echo e(format_number_id((float)$salesMetrics['transaction_discount'])); ?></div></div>
          <div class="summary-card"><div class="label">Retur Penjualan</div><div class="value">Rp <?php echo e(format_number_id((float)$salesMetrics['returns'])); ?></div><div class="sub"><?php echo (int)$salesMetrics['return_transactions']; ?> transaksi retur</div></div>
        </div>

        <h4 style="margin:14px 0 8px">Omzet Bersih per Metode Pembayaran</h4>
        <div class="summary-grid">
          <?php foreach ($summaryRows as $r): ?>
          <div class="summary-card">
            <div class="label"><?php echo e($r['payment_method_name']); ?></div>
            <div class="value">Rp <?php echo e(format_number_id((float)$r['total_omset'])); ?></div>
            <div class="sub"><?php echo (int)$r['total_transaksi']; ?> transaksi</div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($summaryRows)): ?>
          <div class="summary-card"><div class="label">Tidak ada data</div></div>
          <?php endif; ?>
        </div>

        <div class="summary-card total-card" style="margin-top:4px">
          <div class="label">Omzet Penjualan Bersih</div>
          <div class="value">Rp <?php echo e(format_number_id($grandTotal)); ?></div>
          <div class="sub"><?php echo $grandTx; ?> transaksi · setelah diskon dan retur</div>
        </div>
      </div>

      <div class="card">
        <h4 style="margin-top:0;margin-bottom:10px">Detail Transaksi</h4>
        <div style="overflow-x:auto">
          <table>
            <thead>
              <tr>
                <th>Waktu</th>
                <th>Jenis</th>
                <th>Kode Transaksi</th>
                <th>Metode Pembayaran</th>
                <th>Bank / Akun</th>
                <th>Kasir</th>
                <th style="text-align:right">Kotor</th>
                <th style="text-align:right">Diskon</th>
                <th style="text-align:right">Retur</th>
                <th style="text-align:right">Omzet Bersih</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($detailRows)): ?>
              <tr><td colspan="10" style="text-align:center;color:var(--muted,#6c757d);padding:24px">Tidak ada transaksi pada periode ini.</td></tr>
              <?php else: foreach ($detailRows as $r): ?>
              <tr>
                <td><?php echo e(date('d/m/Y H:i', strtotime($r['sold_at']))); ?></td>
                <td><?php echo e($r['event_label'] ?? 'Penjualan'); ?></td>
                <td><?php echo e($r['transaction_code']); ?></td>
                <td><?php echo e($r['payment_method_name']); ?></td>
                <td><?php echo e($r['payment_bank'] ?? '-'); ?></td>
                <td><?php echo e($r['cashier'] ?? '-'); ?></td>
                <td style="text-align:right">Rp <?php echo e(format_number_id((float)$r['gross'])); ?></td>
                <td style="text-align:right">Rp <?php echo e(format_number_id((float)$r['discount_total'])); ?></td>
                <td style="text-align:right">Rp <?php echo e(format_number_id((float)$r['return_amount'])); ?></td>
                <td style="text-align:right"><?php echo (float)$r['total'] < 0 ? '- ' : ''; ?>Rp <?php echo e(format_number_id(abs((float)$r['total']))); ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($detailRows)): ?>
            <tfoot>
              <tr>
                <td colspan="9">Omzet Bersih Periode</td>
                <td style="text-align:right">Rp <?php echo e(format_number_id($grandTotal)); ?></td>
              </tr>
            </tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
