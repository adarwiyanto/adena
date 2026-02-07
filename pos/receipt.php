<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';

start_secure_session();
require_login();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', '');
$storeLogo = setting('store_logo', '');
$storeAddress = setting('store_address', '');
$storePhone = setting('store_phone', '');
$receiptFooter = setting('receipt_footer', '');

$receipt = $_SESSION['pos_receipt'] ?? null;
$receiptId = trim($_GET['id'] ?? '');
$receiptValid = $receipt && $receiptId !== '' && $receiptId === ($receipt['id'] ?? '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Receipt <?php echo e($receiptId ?: '-'); ?></title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(base_url('pos/receipt-print.css')); ?>">
</head>
<body>
  <div class="receipt-page">
    <div class="receipt-toolbar no-print">
      <div>
        <strong>Petunjuk Print Struk 58mm</strong>
        <ul>
          <li>Destination: printer thermal.</li>
          <li>Layout: Portrait, Scale 100%.</li>
          <li>Margins: None/Custom 0.</li>
          <li>Paper size: 58mm / 57mm / 2.25" (sesuai driver).</li>
        </ul>
      </div>
      <div class="receipt-toolbar-actions">
        <button class="btn" type="button" data-print-window>Print</button>
        <a class="btn" href="<?php echo e(base_url('pos/index.php')); ?>">Kembali</a>
      </div>
    </div>

    <?php if (!$receiptValid): ?>
      <div class="receipt-error">
        <strong>Struk tidak ditemukan.</strong>
        <p>Silakan kembali ke POS dan ulangi proses cetak.</p>
      </div>
    <?php else: ?>
      <div class="receipt" role="document">
        <div class="receipt-header">
          <?php if (!empty($storeLogo)): ?>
            <div class="receipt-logo">
              <img src="<?php echo e(upload_url($storeLogo, 'image')); ?>" alt="<?php echo e($storeName); ?>">
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
          <div>No: <?php echo e($receipt['id']); ?></div>
          <div>Tanggal: <?php echo e($receipt['time']); ?></div>
          <div>Kasir: <?php echo e($receipt['cashier']); ?></div>
        </div>

        <div class="receipt-items">
          <?php foreach ($receipt['items'] as $item): ?>
            <div class="receipt-item">
              <div class="receipt-item-name"><?php echo e($item['name']); ?></div>
              <div class="receipt-item-row">
                <div class="receipt-item-qty"><?php echo e((string)$item['qty']); ?> x Rp <?php echo e(number_format((float)$item['price'], 0, '.', ',')); ?></div>
                <div class="receipt-item-subtotal">Rp <?php echo e(number_format((float)$item['subtotal'], 0, '.', ',')); ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="receipt-summary">
          <div class="receipt-line">
            <span>Total</span>
            <span>Rp <?php echo e(number_format((float)$receipt['total'], 0, '.', ',')); ?></span>
          </div>
          <div class="receipt-line">
            <span>Bayar</span>
            <span>Rp <?php echo e(number_format((float)$receipt['total'], 0, '.', ',')); ?></span>
          </div>
          <div class="receipt-line">
            <span>Kembalian</span>
            <span>Rp <?php echo e(number_format(0, 0, '.', ',')); ?></span>
          </div>
          <div class="receipt-line">
            <span>Pembayaran</span>
            <span><?php echo e(strtoupper($receipt['payment'] ?? '-')); ?></span>
          </div>
        </div>

        <?php if (!empty($receiptFooter)): ?>
          <div class="receipt-footer"><?php echo e($receiptFooter); ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
