<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/store_accounting.php';
require_once __DIR__ . '/../core/sales_revision.php';
require_once __DIR__ . '/../lib/upload_secure.php';

start_secure_session();
require_login();
ensure_rbac_schema();
$me = require_menu_access('pos_history', 'view');
ensure_sales_payment_bank_column();
ensure_sales_revision_schema();

$appName  = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$range = $_GET['range'] ?? 'today';
if (!in_array($range, ['today', 'yesterday', '7days', 'custom'], true)) $range = 'today';
$customStart = $_GET['start'] ?? '';
$customEnd   = $_GET['end']   ?? '';

$today = new DateTimeImmutable('today');
if ($range === 'today') {
  $startDate = $today->setTime(0, 0, 0);
  $endDate   = $today->setTime(23, 59, 59);
} elseif ($range === 'yesterday') {
  $yesterday = $today->modify('-1 day');
  $startDate = $yesterday->setTime(0, 0, 0);
  $endDate   = $yesterday->setTime(23, 59, 59);
} elseif ($range === '7days') {
  $startDate = $today->modify('-6 days')->setTime(0, 0, 0);
  $endDate   = $today->setTime(23, 59, 59);
} else {
  $parsedStart = DateTimeImmutable::createFromFormat('Y-m-d', $customStart);
  $parsedEnd   = DateTimeImmutable::createFromFormat('Y-m-d', $customEnd);
  if ($parsedStart && $parsedEnd) {
    $startDate = $parsedStart->setTime(0, 0, 0);
    $endDate   = $parsedEnd->setTime(23, 59, 59);
    if ($startDate > $endDate) { $tmp = $startDate; $startDate = $endDate; $endDate = $tmp; }
  } else {
    $range = 'today';
    $startDate = $today->setTime(0, 0, 0);
    $endDate   = $today->setTime(23, 59, 59);
  }
}

// Detect optional columns
$hasBankCol = false;
try { $hasBankCol = !empty(db()->query("SHOW COLUMNS FROM sales LIKE 'payment_bank'")->fetchAll()); } catch (Throwable $e) {}
$hasProofCol = false;
try { $hasProofCol = !empty(db()->query("SHOW COLUMNS FROM sales LIKE 'payment_proof_path'")->fetchAll()); } catch (Throwable $e) {}
$hasReturnCol = false;
try { $hasReturnCol = !empty(db()->query("SHOW COLUMNS FROM sales LIKE 'return_reason'")->fetchAll()); } catch (Throwable $e) {}

$bankSel   = $hasBankCol   ? "MAX(s.payment_bank) AS payment_bank,"         : "NULL AS payment_bank,";
$proofSel  = $hasProofCol  ? "MAX(s.payment_proof_path) AS payment_proof_path," : "NULL AS payment_proof_path,";
$returnSel = $hasReturnCol ? "MAX(s.return_reason) AS return_reason,"        : "NULL AS return_reason,";

$transactions = [];
try {
  $stmt = db()->prepare("
    SELECT
      COALESCE(NULLIF(s.transaction_code, ''), CONCAT('LEGACY-', s.id)) AS tx_code,
      MIN(s.sold_at)          AS sold_at,
      SUM(s.total)            AS total_amount,
      MAX(s.payment_method)   AS payment_method,
      MAX(u.name)             AS cashier_name,
      {$bankSel}
      {$proofSel}
      {$returnSel}
      MAX(s.revision_no)          AS revision_no,
      MAX(s.is_active_revision)   AS is_active_revision
    FROM sales s
    LEFT JOIN users u ON u.id = s.created_by
    WHERE s.is_active_revision = 1
      AND s.sold_at BETWEEN ? AND ?
    GROUP BY COALESCE(NULLIF(s.transaction_code, ''), CONCAT('LEGACY-', s.id))
    ORDER BY MIN(s.sold_at) DESC
    LIMIT 500
  ");
  $stmt->execute([$startDate->format('Y-m-d H:i:s'), $endDate->format('Y-m-d H:i:s')]);
  $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $transactions = [];
}

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
  $ph = implode(',', array_fill(0, count($transactionCodes), '?'));
  try {
    $stmtI = db()->prepare("
      SELECT s.*, p.name AS product_name, p.sale_unit,
        COALESCE(NULLIF(s.transaction_code, ''), CONCAT('LEGACY-', s.id)) AS tx_code,
        CASE WHEN s.price_each = 0 THEN 1 ELSE 0 END AS is_reward
      FROM sales s
      LEFT JOIN products p ON p.id = s.product_id
      WHERE s.transaction_code IN ({$ph})
      ORDER BY s.id ASC
    ");
    $stmtI->execute($transactionCodes);
    foreach ($stmtI->fetchAll(PDO::FETCH_ASSOC) as $item) {
      $itemsByTx[$item['tx_code']][] = $item;
    }
  } catch (Throwable $e) {}
}
if ($legacyIds) {
  $ph = implode(',', array_fill(0, count($legacyIds), '?'));
  try {
    $stmtI = db()->prepare("
      SELECT s.*, p.name AS product_name, p.sale_unit,
        COALESCE(NULLIF(s.transaction_code, ''), CONCAT('LEGACY-', s.id)) AS tx_code,
        CASE WHEN s.price_each = 0 THEN 1 ELSE 0 END AS is_reward
      FROM sales s
      LEFT JOIN products p ON p.id = s.product_id
      WHERE s.id IN ({$ph})
      ORDER BY s.id ASC
    ");
    $stmtI->execute($legacyIds);
    foreach ($stmtI->fetchAll(PDO::FETCH_ASSOC) as $item) {
      $itemsByTx[$item['tx_code']][] = $item;
    }
  } catch (Throwable $e) {}
}

$detailTxCode = trim((string)($_GET['detail'] ?? ''));
$detailSale   = null;
$detailItems  = [];
if ($detailTxCode !== '') {
  try {
    $stmt = db()->prepare("
      SELECT s.*, p.name AS product_name, p.sale_unit,
        u.name AS cashier_name, u.role AS cashier_role
      FROM sales s
      LEFT JOIN products p ON p.id = s.product_id
      LEFT JOIN users u ON u.id = s.created_by
      WHERE s.transaction_code = ? AND s.is_active_revision = 1
      ORDER BY s.id ASC
    ");
    $stmt->execute([$detailTxCode]);
    $detailItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($detailItems) {
      $preparedDetail = store_acc_prepare_transaction($detailItems);
      $detailItems = $preparedDetail['rows'];
    }
    $detailSale  = $detailItems[0] ?? null;
  } catch (Throwable $e) {}
}

foreach ($transactions as &$tx) {
  $code = (string)($tx['tx_code'] ?? '');
  if (!empty($itemsByTx[$code])) {
    $preparedTx = store_acc_prepare_transaction($itemsByTx[$code]);
    $tx['total_amount'] = (float)$preparedTx['net_before_return'];
    $tx['gross_amount'] = (float)$preparedTx['gross'];
    $tx['discount_total'] = (float)$preparedTx['discount_total'];
    $itemsByTx[$code] = $preparedTx['rows'];
  }
}
unset($tx);

$grandTotal = array_sum(array_column($transactions, 'total_amount'));
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
    .hist-wrap{max-width:980px;margin:0 auto;padding:16px}
    .hist-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:16px}
    .hist-filters .filter-group{display:flex;flex-direction:column;gap:6px;min-width:140px}
    .hist-filters .filter-actions{display:flex;gap:8px}
    .hist-summary-bar{display:flex;gap:24px;flex-wrap:wrap;font-size:.9rem;margin-bottom:12px}
    .hist-summary-bar strong{font-size:1rem}
    .transactions-grid{display:grid;gap:12px}
    .transaction-card{border:1px solid rgba(148,163,184,.3);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:10px;background:rgba(15,23,42,.02)}
    .transaction-header{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between}
    .transaction-meta{display:flex;flex-direction:column;gap:4px}
    .transaction-items{margin:0;padding-left:18px;display:grid;gap:4px;font-size:14px}
    .transaction-summary{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;font-size:14px}
    .transaction-actions{display:flex;flex-wrap:wrap;gap:8px}
    .hist-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.75rem;font-weight:600;background:#e0e7ff;color:#3730a3}
    .hist-badge.cash{background:#dcfce7;color:#15803d}
    .hist-badge.qris{background:#fef9c3;color:#a16207}
    .hist-badge.retur{background:#fee2e2;color:#b91c1c}
    @media(min-width:860px){.transactions-grid{grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}}
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
    <form class="hist-filters" method="get">
      <div class="filter-group">
        <label for="range">Rentang Waktu</label>
        <select name="range" id="range">
          <option value="today"     <?php echo $range === 'today'     ? 'selected' : ''; ?>>Hari Ini</option>
          <option value="yesterday" <?php echo $range === 'yesterday' ? 'selected' : ''; ?>>Kemarin</option>
          <option value="7days"     <?php echo $range === '7days'     ? 'selected' : ''; ?>>7 Hari Terakhir</option>
          <option value="custom"    <?php echo $range === 'custom'    ? 'selected' : ''; ?>>Custom</option>
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

    <!-- Summary -->
    <div class="hist-summary-bar">
      <div>Transaksi: <strong><?php echo count($transactions); ?></strong></div>
      <div>Total: <strong>Rp <?php echo e(format_number_id($grandTotal)); ?></strong></div>
    </div>

    <?php if (empty($transactions)): ?>
      <div class="pos-panel">
        <div class="pos-empty">Tidak ada transaksi pada periode ini.</div>
      </div>
    <?php else: ?>
      <div class="transactions-grid">
        <?php foreach ($transactions as $tx): ?>
          <?php
            $txCode      = (string)($tx['tx_code'] ?? '');
            $displayCode = $txCode;
            if (strpos($txCode, 'LEGACY-') === 0) $displayCode = 'TRX-' . substr($txCode, 7);
            $items       = $itemsByTx[$txCode] ?? [];
            $pm          = strtolower((string)($tx['payment_method'] ?? ''));
            $bank        = (string)($tx['payment_bank'] ?? '');
            $pmLabel     = strtoupper($pm);
            if ($bank !== '') $pmLabel .= ' — ' . $bank;
            $badgeCls    = $pm === 'cash' ? 'cash' : ($pm === 'qris' ? 'qris' : '');
            $isRevised   = (int)($tx['revision_no'] ?? 0) > 0;
            $hasReturn   = !empty($tx['return_reason']);
            $isDetail    = $detailTxCode === $txCode;
          ?>
          <div class="transaction-card">
            <div class="transaction-header">
              <div class="transaction-meta">
                <strong><?php echo e($displayCode); ?></strong>
                <div>
                  <span class="hist-badge">Versi Aktif</span>
                  <?php if ($isRevised): ?><span class="hist-badge">Sudah Direvisi</span><?php endif; ?>
                  <?php if ($hasReturn): ?><span class="hist-badge retur">Retur</span><?php endif; ?>
                </div>
                <span style="font-size:.85rem;color:#64748b"><?php echo e((string)($tx['sold_at'] ?? '')); ?></span>
              </div>
              <div><strong>Rp <?php echo e(format_number_id((float)$tx['total_amount'])); ?></strong></div>
            </div>

            <div class="transaction-summary">
              <span>Kasir: <?php echo e((string)($tx['cashier_name'] ?? '-')); ?></span>
              <span>Pembayaran: <span class="hist-badge <?php echo $badgeCls; ?>"><?php echo e($pmLabel ?: '-'); ?></span></span>
              <?php if ($hasReturn): ?>
                <span>Alasan retur: <?php echo e((string)$tx['return_reason']); ?></span>
              <?php else: ?>
                <span>Status: Sukses</span>
              <?php endif; ?>
              <?php if (!empty($tx['payment_proof_path'])): ?>
                <span>
                  Bukti QRIS:
                  <button type="button" class="qris-thumb-btn" data-qris-full="<?php echo e(upload_url($tx['payment_proof_path'], 'image')); ?>">
                    <img class="qris-thumb" src="<?php echo e(upload_url($tx['payment_proof_path'], 'image')); ?>" alt="Bukti QRIS">
                  </button>
                </span>
              <?php endif; ?>
            </div>

            <?php if (!empty($items)): ?>
              <ul class="transaction-items">
                <?php foreach ($items as $item): ?>
                  <li>
                    <?php echo e($item['product_name'] ?? '-'); ?> × <?php echo e((string)$item['qty']); ?>
                    <?php if ($item['is_reward']): ?>
                      <small style="color:#a16207">(reward — Gratis)</small>
                    <?php else: ?>
                      (Rp <?php echo e(format_number_id((float)($item['_line_net'] ?? $item['total']))); ?>)
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <div class="transaction-actions">
              <?php if ($txCode !== '' && strpos($txCode, 'LEGACY-') !== 0): ?>
                <a class="btn" style="font-size:.85rem;padding:4px 12px"
                   href="?range=<?php echo e($range); ?>&start=<?php echo e($customStart); ?>&end=<?php echo e($customEnd); ?>&detail=<?php echo e(urlencode($txCode)); ?>#detail-panel">
                  <?php echo $isDetail ? 'Tutup Detail' : 'Detail'; ?>
                </a>
              <?php endif; ?>
              <?php if ($txCode !== ''): ?>
                <a class="btn" style="font-size:.85rem;padding:4px 12px"
                   href="<?php echo e(base_url('pos/history_print.php?code=' . urlencode($txCode))); ?>"
                   target="_blank">Cetak Struk</a>
              <?php endif; ?>
            </div>

            <?php if ($isDetail && $detailSale): ?>
              <div id="detail-panel" class="card" style="margin-top:4px;background:var(--surface,#fff)">
                <h4 style="margin-top:0">Detail: <?php echo e($detailSale['transaction_code'] ?? $displayCode); ?></h4>
                <p>
                  <strong>Tanggal:</strong> <?php echo e((string)($detailSale['sold_at'] ?? '-')); ?>
                  &nbsp;·&nbsp;
                  <strong>Kasir:</strong> <?php echo e($detailSale['cashier_name'] ?? '-'); ?>
                  &nbsp;·&nbsp;
                  <strong>Pembayaran:</strong> <?php echo e($detailSale['payment_method'] ?? '-'); ?>
                </p>
                <?php if (!empty($detailSale['return_reason'])): ?>
                  <p><strong>Retur:</strong> <?php echo e($detailSale['return_reason']); ?></p>
                <?php endif; ?>
                <table class="table">
                  <thead>
                    <tr>
                      <th>Produk</th>
                      <th>Qty</th>
                      <th>Satuan</th>
                      <th style="text-align:right">Harga</th>
                      <th style="text-align:right">Subtotal</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php $sum = 0; foreach ($detailItems as $di): $lineNet=(float)($di['_line_net'] ?? $di['total']); $sum += $lineNet; ?>
                      <tr>
                        <td><?php echo e($di['product_name'] ?? '-'); ?><?php echo ((int)($di['is_reward'] ?? 0)) ? ' <small style="color:#a16207">(reward)</small>' : ''; ?></td>
                        <td><?php echo e((string)$di['qty']); ?></td>
                        <td><?php echo e($di['sale_unit'] ?? 'pcs'); ?></td>
                        <td style="text-align:right"><?php echo ((int)($di['is_reward'] ?? 0)) ? 'Gratis' : 'Rp ' . e(format_number_id((float)$di['price_each'])); ?></td>
                        <td style="text-align:right">Rp <?php echo e(format_number_id($lineNet)); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                <p style="text-align:right"><strong>Grand Total: Rp <?php echo e(format_number_id($sum)); ?></strong></p>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- QRIS Modal (read-only) -->
<div class="qris-preview-modal" data-qris-modal hidden>
  <div class="qris-preview-stage">
    <img alt="Preview bukti QRIS" data-qris-modal-img>
  </div>
  <button class="qris-preview-exit" type="button" data-qris-close>← Kembali</button>
</div>

<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
<script nonce="<?php echo e(csp_nonce()); ?>">
  // Filter: toggle custom date fields
  const rangeSelect = document.querySelector('#range');
  const customFields = document.querySelectorAll('[data-custom-range]');
  const updateCustomFields = () => {
    const isCustom = rangeSelect && rangeSelect.value === 'custom';
    customFields.forEach(f => { f.style.display = isCustom ? 'flex' : 'none'; });
  };
  if (rangeSelect) { rangeSelect.addEventListener('change', updateCustomFields); updateCustomFields(); }

  // QRIS modal
  const modal = document.querySelector('[data-qris-modal]');
  const modalImg = modal ? modal.querySelector('[data-qris-modal-img]') : null;
  let scale = 1, translateX = 0, translateY = 0, isPanning = false, startX = 0, startY = 0;
  const applyTransform = () => { if (modalImg) modalImg.style.transform = `translate(${translateX}px,${translateY}px) scale(${scale})`; };
  const resetTransform = () => { scale = 1; translateX = 0; translateY = 0; applyTransform(); };

  document.querySelectorAll('[data-qris-full]').forEach(btn => {
    btn.addEventListener('click', () => {
      if (!modal || !modalImg) return;
      modalImg.src = btn.getAttribute('data-qris-full');
      resetTransform();
      modal.hidden = false;
      modal.classList.add('is-open');
    });
  });
  document.querySelectorAll('[data-qris-close]').forEach(btn => {
    btn.addEventListener('click', () => { modal.classList.remove('is-open'); modal.hidden = true; if (modalImg) modalImg.src = ''; });
  });
  if (modalImg) {
    modalImg.addEventListener('pointerdown', e => { isPanning = true; startX = e.clientX - translateX; startY = e.clientY - translateY; modalImg.setPointerCapture(e.pointerId); modalImg.style.cursor = 'grabbing'; });
    modalImg.addEventListener('pointermove', e => { if (!isPanning) return; translateX = e.clientX - startX; translateY = e.clientY - startY; applyTransform(); });
    modalImg.addEventListener('pointerup', e => { isPanning = false; modalImg.releasePointerCapture(e.pointerId); modalImg.style.cursor = 'grab'; });
    modalImg.addEventListener('pointercancel', () => { isPanning = false; modalImg.style.cursor = 'grab'; });
  }
  if (modal) {
    modal.addEventListener('wheel', e => { if (!modalImg) return; e.preventDefault(); const d = e.deltaY < 0 ? 0.1 : -0.1; scale = Math.max(1, Math.min(4, scale + d)); applyTransform(); }, { passive: false });
    document.addEventListener('keydown', e => { if (e.key !== 'Escape' || !modal.classList.contains('is-open')) return; modal.classList.remove('is-open'); modal.hidden = true; if (modalImg) modalImg.src = ''; });
  }
</script>
</body>
</html>
