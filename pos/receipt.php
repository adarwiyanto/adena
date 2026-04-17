<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';

start_secure_session();
require_login();
ensure_pos_print_jobs_table();
expire_old_pos_print_jobs();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', '');
$storeLogo = setting('store_logo', '');
$storeAddress = setting('store_address', '');
$storePhone = setting('store_phone', '');
$receiptFooter = setting('receipt_footer', '');

$receipt = $_SESSION['pos_receipt'] ?? null;
$receiptId = trim((string)($_GET['id'] ?? ''));
$jobToken = trim((string)($_GET['token'] ?? ''));
$sessionJobToken = trim((string)($receipt['print_job_token'] ?? ''));
if ($jobToken === '' && $sessionJobToken !== '') {
  $jobToken = $sessionJobToken;
}

$receiptValid = $receipt && $receiptId !== '' && $receiptId === (string)($receipt['id'] ?? '');
$printJobStatus = null;

if (!$receiptValid && $jobToken !== '') {
  $job = get_pos_print_job_by_token($jobToken, ['allow_printed' => true]);
  if ($job) {
    $payload = $job['payload'] ?? [];
    $receipt = [
      'id' => (string)($payload['receipt_id'] ?? '-'),
      'time' => (string)($payload['tanggal_jam'] ?? '-'),
      'cashier' => (string)($payload['cashier'] ?? '-'),
      'payment' => (string)($payload['payment_method'] ?? '-'),
      'items' => is_array($payload['items'] ?? null) ? $payload['items'] : [],
      'total' => (float)($payload['total'] ?? 0),
      'paid_amount' => (float)($payload['bayar'] ?? 0),
      'change_amount' => (float)($payload['kembalian'] ?? 0),
    ];
    $receiptId = $receipt['id'];
    $storeName = (string)($payload['store_name'] ?? $storeName);
    $storeSubtitle = (string)($payload['store_subtitle'] ?? $storeSubtitle);
    $storeAddress = (string)($payload['store_address'] ?? $storeAddress);
    $storePhone = (string)($payload['store_phone'] ?? $storePhone);
    $receiptFooter = (string)($payload['footer'] ?? $receiptFooter);
    if (!empty($payload['logo_url'])) {
      $storeLogo = (string)$payload['logo_url'];
    }
    $receiptValid = true;
    $printJobStatus = (string)$job['status'];
  }
}

if ($receiptValid && $printJobStatus === null && $jobToken !== '') {
  $job = get_pos_print_job_by_token($jobToken, ['allow_printed' => true]);
  if ($job) {
    $printJobStatus = (string)$job['status'];
  }
}

if ($receiptValid && $jobToken === '') {
  $payload = build_pos_receipt_payload($receipt, [
    'store_name' => $storeName,
    'store_subtitle' => $storeSubtitle,
    'store_address' => $storeAddress,
    'store_phone' => $storePhone,
    'footer' => $receiptFooter,
    'store_logo' => $storeLogo,
    'paid_amount' => (float)($receipt['paid_amount'] ?? $receipt['total'] ?? 0),
  ]);

  $printJob = create_pos_print_job($payload, [
    'created_by' => (int)((current_user()['id'] ?? 0)),
    'device_hint' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 100),
    'notes' => 'Receipt page generated token',
  ]);
  if ($printJob && !empty($printJob['job_token'])) {
    $jobToken = (string)$printJob['job_token'];
    if (is_array($_SESSION['pos_receipt'] ?? null)) {
      $_SESSION['pos_receipt']['print_job_token'] = $jobToken;
    }
    $printJobStatus = 'pending';
  }
}

$isAndroidApp = is_android_app_request();
$baseUrl = rtrim(base_url(), '/');
$apiUrl = base_url('pos/print_job_api.php');
$logoSrc = '';
if (!empty($storeLogo)) {
  $logoSrc = preg_match('/^https?:\/\//i', (string)$storeLogo) ? (string)$storeLogo : upload_url((string)$storeLogo, 'image');
}
$bridgePayload = null;
if ($receiptValid) {
  $bridgePayload = build_pos_receipt_payload($receipt, [
    'store_name' => $storeName,
    'store_subtitle' => $storeSubtitle,
    'store_address' => $storeAddress,
    'store_phone' => $storePhone,
    'footer' => $receiptFooter,
    'store_logo' => $storeLogo,
    'paid_amount' => (float)($receipt['paid_amount'] ?? $receipt['total'] ?? 0),
  ]);
}
$deepLink = $jobToken !== ''
  ? 'hopepos://print?token=' . rawurlencode($jobToken) . '&base=' . rawurlencode($baseUrl)
  : '';
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
  <div class="receipt-page"
    data-receipt-bridge="1"
    data-is-android-app="<?php echo $isAndroidApp ? '1' : '0'; ?>"
    data-android-bridge-name="AndroidBridge"
    data-print-token="<?php echo e($jobToken); ?>"
    data-bridge-link="<?php echo e($deepLink); ?>"
    data-api-url="<?php echo e($apiUrl); ?>">
    <div class="receipt-toolbar no-print">
      <div>
        <strong>Petunjuk Print Struk 58mm</strong>
        <ul>
          <li>Destination: printer thermal.</li>
          <li>Layout: Portrait, Scale 100%.</li>
          <li>Margins: None/Custom 0.</li>
          <li>Paper size: 58mm / 57mm / 2.25" (sesuai driver).</li>
        </ul>
        <?php if (!empty($printJobStatus)): ?>
          <div class="receipt-status-badge receipt-status-<?php echo e($printJobStatus); ?>">Status print job: <?php echo e(strtoupper($printJobStatus)); ?></div>
        <?php endif; ?>
      </div>
      <div class="receipt-toolbar-actions">
        <button class="btn" type="button" data-print-via-app>Cetak</button>
        <button class="btn btn-secondary" type="button" data-print-window>Print Browser</button>
        <button class="btn btn-muted" type="button" data-open-printer-settings hidden>Pengaturan Printer</button>
        <a class="btn btn-muted" href="<?php echo e(base_url('pos/index.php')); ?>">Kembali</a>
      </div>
    </div>

    <div class="receipt-notice no-print" data-receipt-bridge-notice hidden></div>

    <?php if (!$receiptValid): ?>
      <div class="receipt-error">
        <strong>Struk tidak ditemukan.</strong>
        <p>Silakan kembali ke POS dan ulangi proses cetak.</p>
      </div>
    <?php else: ?>
      <div class="receipt" id="receipt-print-root" role="document" data-receipt-id="<?php echo e((string)$receipt['id']); ?>" data-cashier="<?php echo e((string)$receipt['cashier']); ?>" data-time="<?php echo e((string)$receipt['time']); ?>" data-store-name="<?php echo e($storeName); ?>" data-logo-src="<?php echo e($logoSrc); ?>">
        <div class="receipt-header">
          <?php if (!empty($storeLogo)): ?>
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
          <div>No: <?php echo e($receipt['id']); ?></div>
          <div>Tanggal: <?php echo e($receipt['time']); ?></div>
          <div>Kasir: <?php echo e($receipt['cashier']); ?></div>
        </div>

        <div class="receipt-items">
          <?php foreach ($receipt['items'] as $item): ?>
            <div class="receipt-item">
              <div class="receipt-item-name"><?php echo e((string)($item['name'] ?? '')); ?></div>
              <div class="receipt-item-row">
                <div class="receipt-item-qty"><?php echo e(format_number_id((float)($item['qty'] ?? 0), 0)); ?> x Rp <?php echo e(format_number_id((float)($item['price'] ?? 0))); ?></div>
                <div class="receipt-item-subtotal">Rp <?php echo e(format_number_id((float)($item['subtotal'] ?? 0))); ?></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php
          $paid = (float)($receipt['paid_amount'] ?? $receipt['total'] ?? 0);
          $change = (float)($receipt['change_amount'] ?? max($paid - (float)($receipt['total'] ?? 0), 0));
        ?>
        <div class="receipt-summary">
          <div class="receipt-line">
            <span>Total</span>
            <span>Rp <?php echo e(format_number_id((float)$receipt['total'])); ?></span>
          </div>
          <div class="receipt-line">
            <span>Bayar</span>
            <span>Rp <?php echo e(format_number_id($paid)); ?></span>
          </div>
          <div class="receipt-line">
            <span>Kembalian</span>
            <span>Rp <?php echo e(format_number_id($change)); ?></span>
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
  <?php if ($bridgePayload): ?>
    <script id="receipt-bridge-payload" type="application/json"><?php echo json_encode($bridgePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
  <?php endif; ?>
  <script src="<?php echo e(asset_url('pos/receipt-bridge.js')); ?>"></script>
</body>
</html>
