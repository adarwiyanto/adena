<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';

start_secure_session();
require_login();
ensure_rbac_schema();
$me = require_menu_access('pos', 'view');
ensure_sales_payment_bank_column();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);

$period = $_GET['period'] ?? 'today';
$dateFrom = date('Y-m-d');
$dateTo   = date('Y-m-d');

if ($period === 'yesterday') {
  $dateFrom = $dateTo = date('Y-m-d', strtotime('-1 day'));
} elseif ($period === 'last7') {
  $dateFrom = date('Y-m-d', strtotime('-6 days'));
} elseif ($period === 'this_month') {
  $dateFrom = date('Y-m-01');
} elseif ($period === 'custom') {
  $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
  $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo   = date('Y-m-d');
  if ($dateTo < $dateFrom) $dateTo = $dateFrom;
}

$userId = (int)($me['id'] ?? 0);

// Check if payment_bank column exists (may not exist on older deployments)
$hasBankCol = false;
try {
  $hasBankCol = !empty(db()->query("SHOW COLUMNS FROM sales LIKE 'payment_bank'")->fetchAll());
} catch (Throwable $e) {}
$bankSelect = $hasBankCol ? "MAX(s.payment_bank)" : "NULL";

$transactions = [];
try {
  $stmt = db()->prepare("
    SELECT
      s.transaction_code,
      MIN(s.created_at)     AS created_at,
      SUM(s.total)          AS total,
      MAX(s.payment_method) AS payment_method,
      $bankSelect           AS payment_bank
    FROM sales s
    WHERE s.created_by = ?
      AND s.is_active_revision = 1
      AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY s.transaction_code
    ORDER BY MIN(s.created_at) DESC
    LIMIT 500
  ");
  $stmt->execute([$userId, $dateFrom, $dateTo]);
  $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $transactions = [];
}

$itemsByTx = [];
if (!empty($transactions)) {
  $txCodes      = array_column($transactions, 'transaction_code');
  $placeholders = implode(',', array_fill(0, count($txCodes), '?'));
  try {
    $stmtItems = db()->prepare("
      SELECT s.transaction_code, p.name AS product_name, s.qty, s.price_each, s.total,
             CASE WHEN s.price_each = 0 THEN 1 ELSE 0 END AS is_reward
      FROM sales s
      LEFT JOIN products p ON p.id = s.product_id
      WHERE s.transaction_code IN ($placeholders)
        AND s.is_active_revision = 1
      ORDER BY s.id ASC
    ");
    $stmtItems->execute($txCodes);
    foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
      $itemsByTx[$item['transaction_code']][] = $item;
    }
  } catch (Throwable $e) {}
}

$grandTotal = array_sum(array_column($transactions, 'total'));
$customCss  = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Riwayat Transaksi — <?php echo e($storeName); ?></title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('pos/pos.css')); ?>">
  <style>
    .hist-wrap{max-width:960px;margin:0 auto;padding:16px}
    .hist-filter{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
    .hist-filter a.btn.active,.hist-filter button.btn.active{background:var(--primary,#6366f1);color:#fff}
    .hist-table{width:100%;border-collapse:collapse}
    .hist-table th,.hist-table td{padding:8px 10px;text-align:left;border-bottom:1px solid var(--border);font-size:.9rem;vertical-align:middle}
    .hist-table th{font-weight:600;background:var(--surface-alt,#f8fafc)}
    .hist-items-row td{padding:0;background:var(--surface-alt,#f8fafc)}
    .hist-items-inner{padding:8px 16px}
    .hist-items-table{width:100%;border-collapse:collapse;font-size:.85rem}
    .hist-items-table th,.hist-items-table td{padding:4px 8px;border-bottom:1px solid var(--border)}
    .hist-items-table th{font-weight:600}
    .hist-toggle{cursor:pointer;background:none;border:none;color:var(--primary,#6366f1);font-weight:500;text-align:left;padding:0}
    .hist-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.75rem;font-weight:600;background:#e0e7ff;color:#3730a3}
    .hist-badge.cash{background:#dcfce7;color:#15803d}
    .hist-badge.qris{background:#fef9c3;color:#a16207}
    .hist-summary{display:flex;gap:24px;margin-bottom:12px;font-size:.9rem}
    .hist-summary strong{font-size:1rem}
  </style>
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="pos-page">
  <div class="topbar pos-topbar">
    <div class="title"><?php echo e($appName); ?> — Riwayat Transaksi</div>
    <div class="spacer"></div>
    <a class="btn" href="<?php echo e(base_url('pos/index.php')); ?>">← Kembali ke POS</a>
  </div>

  <div class="hist-wrap">
    <!-- Filter -->
    <form method="get" class="hist-filter">
      <?php
        $periods = [
          'today'      => 'Hari Ini',
          'yesterday'  => 'Kemarin',
          'last7'      => '7 Hari Lalu',
          'this_month' => 'Bulan Ini',
        ];
      ?>
      <?php foreach ($periods as $pKey => $pLabel): ?>
        <a class="btn<?php echo $period === $pKey ? ' active' : ''; ?>"
           href="?period=<?php echo $pKey; ?>">
          <?php echo $pLabel; ?>
        </a>
      <?php endforeach; ?>
      <span style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <input type="hidden" name="period" value="custom">
        <input type="date" name="date_from"
               value="<?php echo e($period === 'custom' ? $dateFrom : date('Y-m-d')); ?>"
               style="width:135px">
        <span>s/d</span>
        <input type="date" name="date_to"
               value="<?php echo e($period === 'custom' ? $dateTo : date('Y-m-d')); ?>"
               style="width:135px">
        <button type="submit" class="btn<?php echo $period === 'custom' ? ' active' : ''; ?>">
          Tampilkan
        </button>
      </span>
    </form>

    <!-- Summary -->
    <div class="pos-panel" style="margin-bottom:16px">
      <div class="hist-summary">
        <div>Periode: <strong><?php echo e(date('d/m/Y', strtotime($dateFrom))); ?><?php echo $dateFrom !== $dateTo ? ' — ' . e(date('d/m/Y', strtotime($dateTo))) : ''; ?></strong></div>
        <div>Transaksi: <strong><?php echo count($transactions); ?></strong></div>
        <div>Total: <strong>Rp <?php echo e(format_number_id($grandTotal)); ?></strong></div>
      </div>

      <?php if (empty($transactions)): ?>
        <div class="pos-empty">Tidak ada transaksi pada periode ini.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="hist-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Kode Transaksi</th>
                <th>Waktu</th>
                <th>Pembayaran</th>
                <th style="text-align:right">Total</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($transactions as $i => $tx): ?>
                <?php
                  $txCode   = $tx['transaction_code'];
                  $items    = $itemsByTx[$txCode] ?? [];
                  $pm       = strtolower($tx['payment_method'] ?? '');
                  $bank     = $tx['payment_bank'] ?? '';
                  $pmLabel  = strtoupper($pm);
                  if ($bank !== '') $pmLabel .= ' — ' . $bank;
                  $rowId    = 'row-' . md5($txCode);
                  $badgeCls = $pm === 'cash' ? 'cash' : ($pm === 'qris' ? 'qris' : '');
                ?>
                <tr>
                  <td><?php echo e((string)($i + 1)); ?></td>
                  <td>
                    <button class="hist-toggle" onclick="toggleRow('<?php echo $rowId; ?>')">
                      ▶ <?php echo e($txCode); ?>
                    </button>
                  </td>
                  <td><?php echo e(date('d/m/Y H:i', strtotime($tx['created_at']))); ?></td>
                  <td>
                    <span class="hist-badge <?php echo $badgeCls; ?>"><?php echo e($pmLabel); ?></span>
                  </td>
                  <td style="text-align:right">Rp <?php echo e(format_number_id((float)$tx['total'])); ?></td>
                  <td>
                    <a class="btn"
                       style="font-size:.8rem;padding:3px 10px"
                       href="<?php echo e(base_url('pos/history_print.php?code=' . urlencode($txCode))); ?>"
                       target="_blank">Cetak Struk</a>
                  </td>
                </tr>
                <tr id="<?php echo $rowId; ?>" style="display:none" class="hist-items-row">
                  <td colspan="6">
                    <div class="hist-items-inner">
                      <table class="hist-items-table">
                        <thead>
                          <tr>
                            <th>Produk</th>
                            <th style="text-align:right">Qty</th>
                            <th style="text-align:right">Harga</th>
                            <th style="text-align:right">Subtotal</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($items as $item): ?>
                            <tr>
                              <td><?php echo e($item['product_name'] ?? '-'); ?><?php echo $item['is_reward'] ? ' <small style="color:#a16207">(reward)</small>' : ''; ?></td>
                              <td style="text-align:right"><?php echo e((string)$item['qty']); ?></td>
                              <td style="text-align:right">
                                <?php echo $item['is_reward'] ? 'Gratis' : 'Rp ' . e(format_number_id((float)$item['price_each'])); ?>
                              </td>
                              <td style="text-align:right">
                                <?php echo $item['is_reward'] ? 'Rp 0' : 'Rp ' . e(format_number_id((float)$item['total'])); ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
<script nonce="<?php echo e(csp_nonce()); ?>">
function toggleRow(id) {
  var el = document.getElementById(id);
  if (!el) return;
  var hidden = el.style.display === 'none' || el.style.display === '';
  el.style.display = hidden ? 'table-row' : 'none';
  var btn = el.previousElementSibling && el.previousElementSibling.querySelector('.hist-toggle');
  if (btn) btn.textContent = btn.textContent.replace(hidden ? '▶' : '▼', hidden ? '▼' : '▶');
}
</script>
</body>
</html>
