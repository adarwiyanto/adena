<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../lib/upload_secure.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/sales_revision.php';
require_once __DIR__ . '/../core/ops14.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
ensure_sales_transaction_code_column();
ensure_sales_user_column();
ensure_sales_payment_bank_column();
ensure_inventory_module_schema();
ensure_rbac_schema();
ensure_sales_revision_schema();
ensure_adena14_schema();

$err = '';
$me = require_menu_access('sales', 'view');
$canCreateSales = has_menu_access($me, 'sales', 'create');
$canEditSales = has_menu_access($me, 'sales', 'edit');
$canDeleteSales = has_menu_access($me, 'sales', 'delete');
$canApproveSales = has_menu_access($me, 'sales', 'approve');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? 'create';
  $actionPermMap = ['delete'=>'delete','edit_save'=>'edit','return'=>'approve','create'=>'create'];
  if (isset($actionPermMap[$action])) {
    require_action_access('sales', $actionPermMap[$action]);
  }
  $transactionCode = trim($_POST['transaction_code'] ?? '');
  $legacySaleId = (int)($_POST['sale_id'] ?? 0);

  try {
    if ($action === 'delete') {
      if (($me['role'] ?? '') !== 'owner') {
        throw new Exception('Hanya owner yang bisa menghapus transaksi.');
      }
      if ($transactionCode !== '' && strpos($transactionCode, 'LEGACY-') !== 0) {
        $stmt = db()->prepare("SELECT DISTINCT payment_proof_path FROM sales WHERE transaction_code=?");
        $stmt->execute([$transactionCode]);
        $paths = $stmt->fetchAll();
        foreach ($paths as $row) {
          if (!empty($row['payment_proof_path'])) {
            if (upload_is_legacy_path($row['payment_proof_path'])) {
              $fullPath = realpath(__DIR__ . '/../' . $row['payment_proof_path']);
              $uploadsDir = realpath(__DIR__ . '/../uploads/qris');
              if ($fullPath && $uploadsDir && strpos($fullPath, $uploadsDir . DIRECTORY_SEPARATOR) === 0 && is_file($fullPath)) {
                unlink($fullPath);
              }
            } else {
              upload_secure_delete($row['payment_proof_path'], 'image');
            }
          }
        }
        $stmt = db()->prepare("DELETE FROM sales WHERE transaction_code=?");
        $stmt->execute([$transactionCode]);
      } else {
        if ($legacySaleId <= 0) throw new Exception('Transaksi tidak ditemukan.');
        $stmt = db()->prepare("SELECT payment_proof_path FROM sales WHERE id=?");
        $stmt->execute([$legacySaleId]);
        $sale = $stmt->fetch();
        if (!empty($sale['payment_proof_path'])) {
          if (upload_is_legacy_path($sale['payment_proof_path'])) {
            $fullPath = realpath(__DIR__ . '/../' . $sale['payment_proof_path']);
            $uploadsDir = realpath(__DIR__ . '/../uploads/qris');
            if ($fullPath && $uploadsDir && strpos($fullPath, $uploadsDir . DIRECTORY_SEPARATOR) === 0 && is_file($fullPath)) {
              unlink($fullPath);
            }
          } else {
            upload_secure_delete($sale['payment_proof_path'], 'image');
          }
        }
        $stmt = db()->prepare("DELETE FROM sales WHERE id=?");
        $stmt->execute([$legacySaleId]);
      }
      redirect(base_url('admin/sales.php'));
    }

    if ($action === 'edit_save') {
      $role = strtolower((string)($me['role'] ?? ''));
      if (!in_array($role, ['owner', 'admin'], true)) {
        throw new Exception('Hanya owner/admin yang bisa edit transaksi.');
      }
      $itemsRaw = $_POST['items'] ?? [];
      $items = [];
      if (is_array($itemsRaw)) {
        foreach ($itemsRaw as $row) {
          $pid = (int)($row['product_id'] ?? 0);
          $qty = (int)($row['qty'] ?? 0);
          $price = (float)($row['price_each'] ?? 0);
          if ($pid > 0 && $qty > 0) {
            $items[] = ['product_id' => $pid, 'qty' => $qty, 'price_each' => $price];
          }
        }
      }
      if (!$items) throw new Exception('Item revisi wajib diisi.');
      $payload = [
        'sale_code' => $transactionCode,
        'items' => $items,
        'payment_method' => trim((string)($_POST['payment_method'] ?? 'cash')),
        'sold_at' => trim((string)($_POST['sold_at'] ?? '')),
        'reason_category' => trim((string)($_POST['reason_category'] ?? '')),
        'reason_text' => trim((string)($_POST['reason_text'] ?? '')),
      ];
      revise_sale_transaction($payload, $me);
      redirect(base_url('admin/sales.php'));
    }

    if ($action === 'return') {
      if (!in_array($me['role'] ?? '', ['admin', 'owner'], true)) {
        throw new Exception('Anda tidak diizinkan meretur transaksi.');
      }
      $reason = trim($_POST['return_reason'] ?? '');
      if ($reason === '') throw new Exception('Alasan retur wajib diisi.');
      if ($transactionCode !== '' && strpos($transactionCode, 'LEGACY-') !== 0) {
        $stmt = db()->prepare("UPDATE sales SET return_reason=?, returned_at=NOW() WHERE transaction_code=?");
        $stmt->execute([$reason, $transactionCode]);
      } else {
        if ($legacySaleId <= 0) throw new Exception('Transaksi tidak ditemukan.');
        $stmt = db()->prepare("UPDATE sales SET return_reason=?, returned_at=NOW() WHERE id=?");
        $stmt->execute([$reason, $legacySaleId]);
      }
      redirect(base_url('admin/sales.php'));
    }

    $product_id = (int)($_POST['product_id'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 1);

    if ($product_id <= 0) throw new Exception('Produk wajib dipilih.');
    if ($qty <= 0) throw new Exception('Qty minimal 1.');

    $stmt = db()->prepare("SELECT price FROM products WHERE id=?");
    $stmt->execute([$product_id]);
    $p = $stmt->fetch();
    if (!$p) throw new Exception('Produk tidak ditemukan.');

    $price = (float)$p['price'];
    $total = $price * $qty;

    $transactionCode = 'TRX-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $stmt = db()->prepare("INSERT INTO sales (transaction_code, base_sale_code, revision_suffix, revision_no, is_active_revision, revision_status, original_sale_id, product_id, qty, price_each, total, created_by) VALUES (?,?,NULL,0,1,'active',NULL,?,?,?,?,?)");
    $stmt->execute([$transactionCode, $transactionCode, $product_id, $qty, $price, $total, (int)($me['id'] ?? 0)]);
    $saleId = (int)db()->lastInsertId();
    db()->prepare("UPDATE sales SET original_sale_id=? WHERE id=?")->execute([$saleId, $saleId]);

    redirect(base_url('admin/sales.php'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$products = db()->query("SELECT id, name FROM products ORDER BY name ASC")->fetchAll();

$range = $_GET['range'] ?? 'today';
$rangeOptions = ['today', 'yesterday', '7days', 'custom'];
if (!in_array($range, $rangeOptions, true)) {
  $range = 'today';
}
$customStart = $_GET['start'] ?? '';
$customEnd = $_GET['end'] ?? '';
$startDate = null;
$endDate = null;
$today = new DateTimeImmutable('today');

if ($range === 'today') {
  $startDate = $today->setTime(0, 0, 0);
  $endDate = $today->setTime(23, 59, 59);
} elseif ($range === 'yesterday') {
  $yesterday = $today->modify('-1 day');
  $startDate = $yesterday->setTime(0, 0, 0);
  $endDate = $yesterday->setTime(23, 59, 59);
} elseif ($range === '7days') {
  $startDate = $today->modify('-6 days')->setTime(0, 0, 0);
  $endDate = $today->setTime(23, 59, 59);
} elseif ($range === 'custom') {
  $parsedStart = DateTimeImmutable::createFromFormat('Y-m-d', $customStart);
  $parsedEnd = DateTimeImmutable::createFromFormat('Y-m-d', $customEnd);
  if ($parsedStart && $parsedEnd) {
    $startDate = $parsedStart->setTime(0, 0, 0);
    $endDate = $parsedEnd->setTime(23, 59, 59);
    if ($startDate > $endDate) {
      $tmp = $startDate;
      $startDate = $endDate;
      $endDate = $tmp;
    }
  } else {
    $range = 'today';
    $startDate = $today->setTime(0, 0, 0);
    $endDate = $today->setTime(23, 59, 59);
  }
}

$whereClause = 'WHERE s.is_active_revision=1';
$params = [];
if ($startDate && $endDate) {
  $whereClause .= " AND s.sold_at BETWEEN ? AND ?";
  $params[] = $startDate->format('Y-m-d H:i:s');
  $params[] = $endDate->format('Y-m-d H:i:s');
}

$stmt = db()->prepare("
  SELECT
    COALESCE(NULLIF(s.transaction_code, ''), CONCAT('LEGACY-', s.id)) AS tx_code,
    MIN(s.sold_at) AS sold_at,
    SUM(COALESCE(NULLIF(s.line_net_total,0), s.total)) AS total_amount,
    SUM(COALESCE(NULLIF(s.line_subtotal,0), s.qty*s.price_each)) AS subtotal_amount,
    SUM(COALESCE(s.discount_amount,0)) AS item_discount_amount,
    MAX(COALESCE(s.tx_discount_amount,0)) AS tx_discount_amount,
    MAX(COALESCE(s.tx_discount_type,'fixed')) AS tx_discount_type,
    MAX(s.payment_method) AS payment_method,
    MAX(s.payment_bank) AS payment_bank,
    MAX(s.payment_proof_path) AS payment_proof_path,
    MAX(s.return_reason) AS return_reason,
    MAX(u.name) AS cashier_name,
    MAX(s.base_sale_code) AS base_sale_code,
    MAX(s.revision_suffix) AS revision_suffix,
    MAX(s.revision_no) AS revision_no,
    MAX(s.is_active_revision) AS is_active_revision
  FROM sales s
  LEFT JOIN users u ON u.id = s.created_by
  {$whereClause}
  GROUP BY COALESCE(NULLIF(s.transaction_code, ''), CONCAT('LEGACY-', s.id))
  ORDER BY sold_at DESC
  LIMIT 100
");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$transactionCodes = [];
$legacyIds = [];
foreach ($transactions as $tx) {
  $txCode = (string)($tx['tx_code'] ?? '');
  if (strpos($txCode, 'LEGACY-') === 0) {
    $legacyIds[] = (int)substr($txCode, 7);
  } elseif ($txCode !== '') {
    $transactionCodes[] = $txCode;
  }
}

$itemsByTx = [];
if ($transactionCodes) {
  $placeholders = implode(',', array_fill(0, count($transactionCodes), '?'));
  $stmt = db()->prepare("
    SELECT s.*, p.name AS product_name,
      COALESCE(NULLIF(s.transaction_code, ''), CONCAT('LEGACY-', s.id)) AS tx_code
    FROM sales s
    JOIN products p ON p.id = s.product_id
    WHERE s.transaction_code IN ({$placeholders})
    ORDER BY s.id ASC
  ");
  $stmt->execute($transactionCodes);
  foreach ($stmt->fetchAll() as $row) {
    $itemsByTx[$row['tx_code']][] = $row;
  }
}

if ($legacyIds) {
  $placeholders = implode(',', array_fill(0, count($legacyIds), '?'));
  $stmt = db()->prepare("
    SELECT s.*, p.name AS product_name,
      COALESCE(NULLIF(s.transaction_code, ''), CONCAT('LEGACY-', s.id)) AS tx_code
    FROM sales s
    JOIN products p ON p.id = s.product_id
    WHERE s.id IN ({$placeholders})
    ORDER BY s.id ASC
  ");
  $stmt->execute($legacyIds);
  foreach ($stmt->fetchAll() as $row) {
    $itemsByTx[$row['tx_code']][] = $row;
  }
}

$detailTxCode = trim((string)($_GET['detail'] ?? ''));
$revTxCode = trim((string)($_GET['revisions'] ?? ''));
$editTxCode = trim((string)($_GET['edit'] ?? ''));
$detailSale = null;
$detailItems = [];
$revisionRows = [];
if ($detailTxCode !== '') {
  $stmt = db()->prepare("SELECT s.*, p.name AS product_name, p.sale_unit, u.name AS cashier_name, u.role AS cashier_role, ru.name AS revised_by_name
    FROM sales s
    JOIN products p ON p.id=s.product_id
    LEFT JOIN users u ON u.id=s.created_by
    LEFT JOIN users ru ON ru.id=s.revised_by_user_id
    WHERE s.transaction_code=?
    ORDER BY s.id ASC");
  $stmt->execute([$detailTxCode]);
  $detailItems = $stmt->fetchAll();
  $detailSale = $detailItems[0] ?? null;
}
if ($revTxCode !== '' && in_array(($me['role'] ?? ''), ['owner', 'admin'], true)) {
  $stmt = db()->prepare("SELECT s.transaction_code, s.base_sale_code, s.revision_suffix, s.revision_no, s.revision_reason_category, s.revision_reason_text, s.revised_at, s.is_active_revision,
      SUM(s.total) AS grand_total, MAX(u.name) AS revised_by_name
    FROM sales s
    LEFT JOIN users u ON u.id=s.revised_by_user_id
    WHERE s.base_sale_code=(SELECT base_sale_code FROM sales WHERE transaction_code=? LIMIT 1)
    GROUP BY s.transaction_code, s.base_sale_code, s.revision_suffix, s.revision_no, s.revision_reason_category, s.revision_reason_text, s.revised_at, s.is_active_revision
    ORDER BY s.revision_no DESC, s.id DESC");
  $stmt->execute([$revTxCode]);
  $revisionRows = $stmt->fetchAll();
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Penjualan</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .return-reason {
      width: 100%;
      min-width: 0;
      max-width: 420px;
    }
    .return-form {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
    }
    .return-reason-wrapper {
      width: 100%;
      display: none;
    }
    .return-form.is-open .return-reason-wrapper {
      display: block;
    }
    .sales-filters {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: flex-end;
      margin-bottom: 16px;
    }
    .sales-filters .filter-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-width: 160px;
    }
    .sales-filters .filter-actions {
      display: flex;
      gap: 8px;
    }
    .transactions-grid {
      display: grid;
      gap: 12px;
    }
    .transaction-card {
      border: 1px solid rgba(148,163,184,.3);
      border-radius: 12px;
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      background: rgba(15,23,42,.02);
    }
    .transaction-header {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 16px;
      align-items: center;
      justify-content: space-between;
    }
    .transaction-meta {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .transaction-items {
      margin: 0;
      padding-left: 18px;
      display: grid;
      gap: 6px;
      font-size: 14px;
    }
    .transaction-summary {
      display: flex;
      flex-wrap: wrap;
      gap: 10px 16px;
      align-items: center;
      font-size: 14px;
    }
    .transaction-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .transaction-actions form {
      margin: 0;
    }
    @media (min-width: 860px) {
      .transactions-grid {
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      }
    }
    @media (max-width: 560px) {
      .sales-filters .filter-actions {
        width: 100%;
      }
      .sales-filters .filter-actions .btn {
        flex: 1;
      }
    }
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <div class="badge">Input Penjualan</div>
    </div>

    <div class="content">
      <div class="grid cols-2">
        <div class="card">
          <h3 style="margin-top:0">Transaksi Baru</h3>
          <?php if ($err): ?>
            <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <?php if ($canCreateSales): ?><input type="hidden" name="action" value="create">
            <div class="row">
              <label>Produk</label>
              <select name="product_id" required>
                <option value="">-- pilih --</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="row">
              <label>Qty</label>
              <input type="number" name="qty" value="1" min="1" required>
            </div>
            <button class="btn" type="submit">Simpan Penjualan</button><?php endif; ?>
          </form>
          <p><small>Ini versi sederhana: harga mengikuti harga produk saat transaksi dibuat.</small></p>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Riwayat Transaksi</h3>
          <form class="sales-filters" method="get">
            <div class="filter-group">
              <label for="range">Rentang Waktu</label>
              <select name="range" id="range" required>
                <option value="today" <?php echo $range === 'today' ? 'selected' : ''; ?>>Hari ini</option>
                <option value="yesterday" <?php echo $range === 'yesterday' ? 'selected' : ''; ?>>Kemarin</option>
                <option value="7days" <?php echo $range === '7days' ? 'selected' : ''; ?>>7 hari terakhir</option>
                <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom</option>
              </select>
            </div>
            <div class="filter-group" data-custom-range>
              <label for="start">Mulai</label>
              <input type="date" id="start" name="start" value="<?php echo e($customStart); ?>">
            </div>
            <div class="filter-group" data-custom-range>
              <label for="end">Sampai</label>
              <input type="date" id="end" name="end" value="<?php echo e($customEnd); ?>">
            </div>
            <div class="filter-actions">
              <button class="btn" type="submit">Terapkan</button>
            </div>
          </form>
          <div class="transactions-grid">
            <?php foreach ($transactions as $tx): ?>
              <?php
                $txCode = (string)($tx['tx_code'] ?? '');
                $displayCode = $txCode;
                $legacyId = 0;
                if (strpos($txCode, 'LEGACY-') === 0) {
                  $legacyId = (int)substr($txCode, 7);
                  $displayCode = 'TRX-' . $legacyId;
                }
                $items = $itemsByTx[$txCode] ?? [];
                $paymentLabel = (string)($tx['payment_method'] ?? '-');
                if (!empty($tx['payment_bank'])) {
                  $paymentLabel .= ' - ' . (string)$tx['payment_bank'];
                }
              ?>
              <div class="transaction-card">
                <div class="transaction-header">
                  <div class="transaction-meta">
                    <strong><?php echo e($displayCode); ?></strong>
                    <div>
                      <?php if ((int)($tx['is_active_revision'] ?? 1) === 1): ?><span class="badge">Versi Aktif</span><?php else: ?><span class="badge">Versi Lama</span><?php endif; ?>
                      <?php if ((int)($tx['revision_no'] ?? 0) > 0): ?><span class="badge">Sudah Direvisi</span><?php endif; ?>
                    </div>
                    <span><?php echo e($tx['sold_at']); ?></span>
                  </div>
                  <div><strong>Rp <?php echo e(format_number_id((float)$tx['total_amount'])); ?></strong></div><small>Subtotal Rp <?php echo e(format_number_id((float)($tx['subtotal_amount'] ?? $tx['total_amount']))); ?> · Diskon item Rp <?php echo e(format_number_id((float)($tx['item_discount_amount'] ?? 0))); ?> · Diskon trx <?php echo e((string)($tx['tx_discount_type'] ?? 'fixed')); ?> <?php echo e(format_number_id((float)($tx['tx_discount_amount'] ?? 0))); ?></small>
                </div>
                <div class="transaction-summary">
                  <span>Kasir: <?php echo e($tx['cashier_name'] ?? '-'); ?></span>
                  <span>Pembayaran: <?php echo e($paymentLabel); ?></span>
                  <span>Status:
                    <?php if (!empty($tx['return_reason'])): ?>
                      Retur: <?php echo e($tx['return_reason']); ?>
                    <?php else: ?>
                      Sukses
                    <?php endif; ?>
                  </span>
                  <span>
                    Bukti QRIS:
                    <?php if (!empty($tx['payment_proof_path'])): ?>
                      <button type="button" class="qris-thumb-btn" data-qris-full="<?php echo e(upload_url($tx['payment_proof_path'], 'image')); ?>">
                        <img class="qris-thumb" src="<?php echo e(upload_url($tx['payment_proof_path'], 'image')); ?>" alt="Bukti QRIS">
                      </button>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </span>
                </div>
                <?php if (!empty($items)): ?>
                  <ul class="transaction-items">
                    <?php foreach ($items as $item): ?>
                      <li><?php echo e($item['product_name']); ?> × <?php echo e((string)$item['qty']); ?> (Rp <?php echo e(format_number_id((float)$item['total'])); ?>)</li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
                <div class="transaction-actions">
                  <a class="btn" href="<?php echo e(base_url('admin/sales.php?detail=' . urlencode($txCode))); ?>">Detail</a>
                  <?php if (in_array($me['role'] ?? '', ['owner', 'admin'], true)): ?>
                    <a class="btn" href="<?php echo e(base_url('admin/sales.php?revisions=' . urlencode($txCode))); ?>">Revisi</a>
                  <?php endif; ?>
                  <?php if (in_array($me['role'] ?? '', ['owner', 'admin'], true)): ?>
                    <?php if ($canEditSales): ?><a class="btn" href="<?php echo e(base_url('admin/sales.php?edit=' . urlencode($txCode))); ?>">Edit</a><?php endif; ?>
                  <?php endif; ?>
                  <?php if ($canApproveSales && empty($tx['return_reason']) && in_array($me['role'] ?? '', ['admin', 'owner'], true)): ?>
                    <form method="post" class="return-form" data-return-form>
                      <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                      <input type="hidden" name="action" value="return">
                      <?php if ($legacyId > 0): ?>
                        <input type="hidden" name="sale_id" value="<?php echo e((string)$legacyId); ?>">
                      <?php else: ?>
                        <input type="hidden" name="transaction_code" value="<?php echo e($txCode); ?>">
                      <?php endif; ?>
                      <div class="return-reason-wrapper" data-return-reason>
                        <input class="return-reason" type="text" name="return_reason" placeholder="Alasan retur">
                      </div>
                      <button class="btn" type="submit" data-return-submit>Retur</button>
                    </form>
                  <?php endif; ?>
                  <?php if (($me['role'] ?? '') === 'owner'): ?>
                    <?php if ($canDeleteSales): ?><form method="post" data-confirm="Hapus transaksi ini?">
                      <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                      <input type="hidden" name="action" value="delete">
                      <?php if ($legacyId > 0): ?>
                        <input type="hidden" name="sale_id" value="<?php echo e((string)$legacyId); ?>">
                      <?php else: ?>
                        <input type="hidden" name="transaction_code" value="<?php echo e($txCode); ?>">
                      <?php endif; ?>
                      <button class="btn" type="submit">Hapus</button>
                    </form>
                  <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($detailSale): ?>
            <div class="card" style="margin-top:12px">
              <h4 style="margin-top:0">Detail Transaksi: <?php echo e($detailSale['transaction_code']); ?></h4>
              <p>
                <span class="badge"><?php echo ((int)$detailSale['is_active_revision'] === 1) ? 'Versi Aktif' : 'Versi Lama'; ?></span>
                <?php if ((int)($detailSale['revision_no'] ?? 0) > 0): ?><span class="badge">Sudah Direvisi</span><?php endif; ?>
                <?php if (!empty($detailSale['revised_by_name'])): ?><span class="badge">Direvisi oleh <?php echo e($detailSale['revised_by_name']); ?></span><?php endif; ?>
              </p>
              <p><strong>Tanggal:</strong> <?php echo e($detailSale['sold_at']); ?> · <strong>Kasir:</strong> <?php echo e($detailSale['cashier_name'] ?? '-'); ?> (<?php echo e($detailSale['cashier_role'] ?? '-'); ?>) · <strong>Pembayaran:</strong> <?php echo e($detailSale['payment_method'] ?? '-'); ?></p>
              <p><strong>Kategori alasan:</strong> <?php echo e($detailSale['revision_reason_category'] ?? '-'); ?> · <strong>Alasan:</strong> <?php echo e($detailSale['revision_reason_text'] ?? '-'); ?></p>
              <?php if (!empty($detailSale['payment_bank'])): ?>
                <p><strong>Bank QRIS:</strong> <?php echo e($detailSale['payment_bank']); ?></p>
              <?php endif; ?>
              <table class="table"><thead><tr><th>Produk</th><th>Qty</th><th>Satuan</th><th>Harga</th><th>Diskon</th><th>Subtotal/Net</th></tr></thead><tbody>
                <?php $sum=0; foreach ($detailItems as $di): $sum += (float)$di['total']; ?>
                  <tr><td><?php echo e($di['product_name']); ?></td><td><?php echo e((string)$di['qty']); ?></td><td><?php echo e($di['sale_unit'] ?? 'pcs'); ?></td><td>Rp <?php echo e(format_number_id((float)$di['price_each'])); ?></td><td><?php echo e(($di['discount_type'] ?? 'fixed')); ?> <?php echo e(format_number_id((float)($di['discount_amount'] ?? 0))); ?></td><td>Rp <?php echo e(format_number_id((float)($di['line_net_total'] ?: $di['total']))); ?></td></tr>
                <?php endforeach; ?>
              </tbody></table>
              <p><strong>Subtotal:</strong> Rp <?php echo e(format_number_id($sum)); ?> · <strong>Grand Total:</strong> Rp <?php echo e(format_number_id($sum)); ?></p>
            </div>
          <?php endif; ?>

          <?php if (!empty($revisionRows)): ?>
            <div class="card" style="margin-top:12px">
              <h4 style="margin-top:0">Riwayat Revisi</h4>
              <table class="table"><thead><tr><th>Nomor</th><th>Versi</th><th>Revised By</th><th>Waktu</th><th>Kategori</th><th>Alasan</th><th>Total</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($revisionRows as $r): ?>
                  <tr>
                    <td><a href="<?php echo e(base_url('admin/sales.php?detail=' . urlencode((string)$r['transaction_code']))); ?>"><?php echo e($r['transaction_code']); ?></a></td>
                    <td>#<?php echo e((string)$r['revision_no']); ?></td>
                    <td><?php echo e($r['revised_by_name'] ?? '-'); ?></td>
                    <td><?php echo e($r['revised_at'] ?? '-'); ?></td>
                    <td><?php echo e($r['revision_reason_category'] ?? '-'); ?></td>
                    <td><?php echo e($r['revision_reason_text'] ?? '-'); ?></td>
                    <td>Rp <?php echo e(format_number_id((float)$r['grand_total'])); ?></td>
                    <td><?php echo (int)$r['is_active_revision'] === 1 ? 'Aktif' : 'Arsip'; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table>
            </div>
          <?php endif; ?>

          <?php if ($editTxCode !== '' && in_array(($me['role'] ?? ''), ['owner','admin'], true)): ?>
            <?php $editItems = $itemsByTx[$editTxCode] ?? []; ?>
            <div class="card" style="margin-top:12px">
              <h4 style="margin-top:0">Edit Transaksi <?php echo e($editTxCode); ?></h4>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="edit_save">
                <input type="hidden" name="transaction_code" value="<?php echo e($editTxCode); ?>">
                <div class="row"><label>Tanggal transaksi</label><input type="datetime-local" name="sold_at" value="<?php echo e(isset($editItems[0]['sold_at']) ? date('Y-m-d\\TH:i', strtotime((string)$editItems[0]['sold_at'])) : ''); ?>"></div>
                <div class="row"><label>Metode pembayaran</label><select name="payment_method"><option value="cash">cash</option><option value="qris" <?php echo (($editItems[0]['payment_method'] ?? '')==='qris') ? 'selected' : ''; ?>>qris</option></select></div>
                <?php foreach ($editItems as $idx => $item): ?>
                  <div class="row"><label>Item <?php echo e((string)($idx+1)); ?></label>
                    <input type="hidden" name="items[<?php echo e((string)$idx); ?>][product_id]" value="<?php echo e((string)$item['product_id']); ?>">
                    <div style="display:flex;gap:8px;align-items:center"><span><?php echo e($item['product_name'] ?? 'Produk'); ?></span><input style="max-width:90px" type="number" min="1" name="items[<?php echo e((string)$idx); ?>][qty]" value="<?php echo e((string)$item['qty']); ?>"><input style="max-width:140px" type="number" step="0.01" min="0" name="items[<?php echo e((string)$idx); ?>][price_each]" value="<?php echo e((string)$item['price_each']); ?>"></div>
                  </div>
                <?php endforeach; ?>
                <?php if (($me['role'] ?? '') === 'admin'): ?>
                  <div class="row"><label>Kategori Alasan</label><select name="reason_category" required><option value="">-- pilih --</option><option>Salah input item</option><option>Salah qty</option><option>Salah harga</option><option>Salah diskon</option><option>Salah customer</option><option>Koreksi pembayaran</option><option>Lainnya</option></select></div>
                  <div class="row"><label>Alasan Revisi</label><textarea name="reason_text" rows="3" required minlength="5"></textarea></div>
                <?php endif; ?>
                <button class="btn" type="submit">Simpan Revisi</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="qris-preview-modal" data-qris-modal hidden>
  <div class="qris-preview-stage">
    <img alt="Preview bukti QRIS" data-qris-modal-img>
  </div>
  <button class="qris-preview-exit" type="button" data-qris-close>← Kembali</button>
</div>
<script nonce="<?php echo e(csp_nonce()); ?>">
  document.querySelectorAll('[data-return-form]').forEach((form) => {
    const reasonWrap = form.querySelector('[data-return-reason]');
    const reasonInput = reasonWrap ? reasonWrap.querySelector('input[name="return_reason"]') : null;
    form.addEventListener('submit', (event) => {
      if (!form.classList.contains('is-open')) {
        event.preventDefault();
        form.classList.add('is-open');
        if (reasonInput) {
          reasonInput.required = true;
          reasonInput.focus();
        }
      }
    });
  });

  const rangeSelect = document.querySelector('#range');
  const customFields = document.querySelectorAll('[data-custom-range]');
  const updateCustomFields = () => {
    const isCustom = rangeSelect && rangeSelect.value === 'custom';
    customFields.forEach((field) => {
      field.style.display = isCustom ? 'flex' : 'none';
    });
  };
  if (rangeSelect) {
    rangeSelect.addEventListener('change', updateCustomFields);
    updateCustomFields();
  }

  const modal = document.querySelector('[data-qris-modal]');
  const modalImg = modal ? modal.querySelector('[data-qris-modal-img]') : null;
  const closeButtons = modal ? modal.querySelectorAll('[data-qris-close]') : [];
  const openButtons = document.querySelectorAll('[data-qris-full]');
  let scale = 1;
  let translateX = 0;
  let translateY = 0;
  let isPanning = false;
  let startX = 0;
  let startY = 0;

  const applyTransform = () => {
    if (!modalImg) return;
    modalImg.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
  };

  const resetTransform = () => {
    scale = 1;
    translateX = 0;
    translateY = 0;
    applyTransform();
  };

  openButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!modal || !modalImg) return;
      const src = btn.getAttribute('data-qris-full');
      if (!src) return;
      modalImg.src = src;
      resetTransform();
      modal.hidden = false;
      modal.classList.add('is-open');
    });
  });

  closeButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!modal) return;
      modal.classList.remove('is-open');
      modal.hidden = true;
      if (modalImg) modalImg.src = '';
    });
  });

  if (modalImg) {
    modalImg.addEventListener('pointerdown', (event) => {
      isPanning = true;
      startX = event.clientX - translateX;
      startY = event.clientY - translateY;
      modalImg.setPointerCapture(event.pointerId);
      modalImg.style.cursor = 'grabbing';
    });
    modalImg.addEventListener('pointermove', (event) => {
      if (!isPanning) return;
      translateX = event.clientX - startX;
      translateY = event.clientY - startY;
      applyTransform();
    });
    modalImg.addEventListener('pointerup', (event) => {
      isPanning = false;
      modalImg.releasePointerCapture(event.pointerId);
      modalImg.style.cursor = 'grab';
    });
    modalImg.addEventListener('pointercancel', () => {
      isPanning = false;
      modalImg.style.cursor = 'grab';
    });
  }

  if (modal) {
    modal.addEventListener('wheel', (event) => {
      if (!modalImg) return;
      event.preventDefault();
      const delta = event.deltaY < 0 ? 0.1 : -0.1;
      scale = Math.max(1, Math.min(4, scale + delta));
      applyTransform();
    }, { passive: false });

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      if (!modal.classList.contains('is-open')) return;
      modal.classList.remove('is-open');
      modal.hidden = true;
      if (modalImg) modalImg.src = '';
    });
  }
</script>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
