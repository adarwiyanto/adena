<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/store_accounting.php';

start_secure_session();
require_login();
ensure_rbac_schema();
$me = require_menu_access('pos_history', 'print');
ensure_sales_payment_bank_column();

$appName      = app_config()['app']['name'];
$storeName    = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', '');
$storeAddress = setting('store_address', '');
$storePhone   = setting('store_phone', '');
$receiptFooter = setting('receipt_footer', '');
$storeLogo    = setting('store_logo', '');

$txCode = trim((string)($_GET['code'] ?? ''));
if ($txCode === '') {
  echo '<p>Kode transaksi tidak valid.</p>';
  exit;
}

$userId = (int)($me['id'] ?? 0);

$hasBankCol = false;
try {
  $hasBankCol = !empty(db()->query("SHOW COLUMNS FROM sales LIKE 'payment_bank'")->fetchAll());
} catch (Throwable $e) {}
$bankSelect = $hasBankCol ? "MAX(s.payment_bank)" : "NULL";

$tx = null;
try {
  $stmt = db()->prepare("
    SELECT
      s.transaction_code,
      MIN(s.sold_at)        AS created_at,
      SUM(s.total)          AS total,
      MAX(s.payment_method) AS payment_method,
      $bankSelect           AS payment_bank,
      MAX(s.created_by)     AS created_by,
      u.name                AS cashier_name
    FROM sales s
    LEFT JOIN users u ON u.id = s.created_by
    WHERE s.transaction_code = ?
      AND s.is_active_revision = 1
    GROUP BY s.transaction_code
    LIMIT 1
  ");
  $stmt->execute([$txCode]);
  $tx = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $tx = null;
}

if (!$tx) {
  echo '<p>Transaksi tidak ditemukan.</p>';
  exit;
}

// Kasir hanya bisa cetak transaksi milik sendiri; role dengan akses sales bisa semua
$hasSalesAccess = has_menu_access($me, 'sales', 'view');
if ((int)$tx['created_by'] !== $userId && !$hasSalesAccess) {
  echo '<p>Akses ditolak.</p>';
  exit;
}

$stmtItems = db()->prepare("
  SELECT s.*, p.name AS product_name, s.price_each = 0 AS is_reward
  FROM sales s
  LEFT JOIN products p ON p.id = s.product_id
  WHERE s.transaction_code = ?
    AND s.is_active_revision = 1
  ORDER BY s.id ASC
");
$stmtItems->execute([$txCode]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
$preparedTransaction = store_acc_prepare_transaction($items);
$items = $preparedTransaction['rows'];
$tx['gross_total'] = (float)$preparedTransaction['gross'];
$tx['discount_total'] = (float)$preparedTransaction['discount_total'];
$tx['total'] = (float)$preparedTransaction['net_before_return'];

$pmLabel = strtoupper($tx['payment_method'] ?? '-');
if (!empty($tx['payment_bank'])) $pmLabel .= ' — ' . $tx['payment_bank'];

$logoSrc = '';
if (!empty($storeLogo)) {
  $logoSrc = preg_match('/^https?:\/\//i', (string)$storeLogo)
    ? (string)$storeLogo
    : upload_url((string)$storeLogo, 'image');
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Struk <?php echo e($txCode); ?></title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(base_url('pos/receipt-print.css')); ?>">
  <style>
    .hist-toolbar{display:flex;gap:8px;padding:12px 16px;background:#fff;border-bottom:1px solid #e5e7eb;position:sticky;top:0;z-index:10}
    @media print{.hist-toolbar{display:none}}
  </style>
</head>
<body>
  <div class="hist-toolbar no-print">
    <strong style="flex:1">Struk Transaksi</strong>
    <button class="btn" onclick="window.print()">Cetak</button>
    <a class="btn btn-muted" href="javascript:history.back()">Kembali</a>
  </div>

  <div class="receipt-page">
    <div class="receipt" id="receipt-print-root">
      <div class="receipt-header">
        <?php if (!empty($logoSrc)): ?>
          <div class="receipt-logo">
            <img src="<?php echo e($logoSrc); ?>" alt="<?php echo e($storeName); ?>">
          </div>
        <?php endif; ?>
        <div class="receipt-store">
          <div class="receipt-store-name"><?php echo e($storeName); ?></div>
          <?php if (!empty($storeSubtitle)): ?>
            <div class="receipt-store-line"><?php echo e($storeSubtitle); ?></div>
          <?php endif; ?>
          <?php if (!empty($storeAddress)): ?>
            <div class="receipt-store-line"><?php echo e($storeAddress); ?></div>
          <?php endif; ?>
          <?php if (!empty($storePhone)): ?>
            <div class="receipt-store-line">Telp: <?php echo e($storePhone); ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="receipt-meta">
        <div>No: <?php echo e($txCode); ?></div>
        <div>Tanggal: <?php echo e(date('d/m/Y H:i', strtotime($tx['created_at']))); ?></div>
        <div>Kasir: <?php echo e($tx['cashier_name'] ?? '-'); ?></div>
      </div>

      <div class="receipt-items">
        <?php foreach ($items as $item): ?>
          <div class="receipt-item">
            <div class="receipt-item-name"><?php echo e($item['product_name'] ?? '-'); ?></div>
            <div class="receipt-item-row">
              <div class="receipt-item-qty">
                <?php echo e(format_number_id((float)$item['qty'], 0)); ?> x
                <?php echo $item['is_reward'] ? 'Gratis' : 'Rp ' . e(format_number_id((float)$item['price_each'])); ?>
              </div>
              <div class="receipt-item-subtotal">
                <?php echo $item['is_reward'] ? 'Rp 0' : 'Rp ' . e(format_number_id((float)$item['_line_net'])); ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="receipt-summary">
        <div class="receipt-line">
          <span>Penjualan Kotor</span>
          <span>Rp <?php echo e(format_number_id((float)$tx['gross_total'])); ?></span>
        </div>
        <?php if ((float)$tx['discount_total'] > 0): ?>
        <div class="receipt-line">
          <span>Diskon</span>
          <span>- Rp <?php echo e(format_number_id((float)$tx['discount_total'])); ?></span>
        </div>
        <?php endif; ?>
        <div class="receipt-line">
          <strong>Total Setelah Diskon</strong>
          <strong>Rp <?php echo e(format_number_id((float)$tx['total'])); ?></strong>
        </div>
        <div class="receipt-line">
          <span>Pembayaran</span>
          <span><?php echo e($pmLabel); ?></span>
        </div>
      </div>

      <?php if (!empty($receiptFooter)): ?>
        <div class="receipt-footer"><?php echo e($receiptFooter); ?></div>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
