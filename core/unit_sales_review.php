<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/store_accounting.php';

function ensure_unit_sales_review_schema(): void {
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS sales_returns (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      offline_uuid VARCHAR(120) NULL UNIQUE,
      transaction_group_uuid VARCHAR(120) NULL,
      local_transaction_id VARCHAR(120) NULL,
      transaction_code VARCHAR(120) NULL,
      branch_id INT NULL,
      reason TEXT NULL,
      total_return DECIMAL(14,2) NOT NULL DEFAULT 0,
      created_by BIGINT NULL,
      created_at DATETIME NULL,
      sync_status VARCHAR(30) NOT NULL DEFAULT 'synced',
      KEY idx_sales_returns_branch_created (branch_id, created_at),
      KEY idx_sales_returns_tx (transaction_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {}
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS sales_return_items (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      return_id BIGINT NULL,
      sale_id BIGINT NULL,
      product_id BIGINT NULL,
      qty DECIMAL(14,3) NOT NULL DEFAULT 0,
      price_each DECIMAL(14,2) NOT NULL DEFAULT 0,
      subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
      KEY idx_sales_return_items_return (return_id),
      KEY idx_sales_return_items_sale (sale_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {}
  $sqls = [
    "ALTER TABLE sales ADD COLUMN include_in_sales_report TINYINT(1) NOT NULL DEFAULT 1",
    "ALTER TABLE sales ADD COLUMN returned_by BIGINT NULL",
    "ALTER TABLE sales ADD COLUMN returned_at DATETIME NULL",
    "ALTER TABLE sales ADD COLUMN return_reason TEXT NULL",
    "ALTER TABLE sales ADD KEY idx_sales_branch_tx (branch_id, transaction_code)",
    "ALTER TABLE sales ADD KEY idx_sales_branch_sold (branch_id, sold_at)",
  ];
  foreach ($sqls as $sql) { try { db()->exec($sql); } catch (Throwable $e) {} }
}

function unit_review_role_caps(array $user): array {
  $roleKey = current_user_role_key();
  $legacyRole = strtolower((string)($user['role'] ?? ''));
  if ($roleKey === '' && $legacyRole !== '') $roleKey = $legacyRole;
  if ($roleKey === 'superadmin') $roleKey = 'owner';
  return [
    'role' => $roleKey,
    'is_owner' => in_array($roleKey, ['owner', 'superadmin'], true),
    'is_admin' => in_array($roleKey, ['admin', 'owner', 'superadmin'], true),
    'can_return' => in_array($roleKey, ['kasir','manager_cabang','pegawai_cabang','admin','owner','superadmin'], true),
  ];
}

function unit_review_tx_where_sql(): string {
  return "COALESCE(NULLIF(s.transaction_code,''), CONCAT('LEGACY-', s.id))";
}

function unit_review_load_items(int $unitId, string $txCode): array {
  $txExpr = unit_review_tx_where_sql();
  $sql = "SELECT s.*, {$txExpr} AS tx_code, p.name AS product_name, COALESCE(p.sale_unit,'pcs') AS sale_unit,
      u.name AS cashier_name, ru.name AS returned_by_name
    FROM sales s
    LEFT JOIN products p ON p.id=s.product_id
    LEFT JOIN users u ON u.id=s.created_by
    LEFT JOIN users ru ON ru.id=s.returned_by
    WHERE s.branch_id=? AND {$txExpr}=?
    ORDER BY s.id ASC";
  $st = db()->prepare($sql);
  $st->execute([$unitId, $txCode]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function unit_review_render(string $section): void {
  global $unitId, $unit, $units, $customCss, $u;
  ensure_unit_sales_review_schema();
  $caps = unit_review_role_caps(is_array($u ?? null) ? $u : []);
  $isOwner = (bool)$caps['is_owner'];
  $isAdmin = (bool)$caps['is_admin'];
  $canReturn = (bool)$caps['can_return'];
  $from = $_GET['from'] ?? date('Y-m-01');
  $to = $_GET['to'] ?? date('Y-m-d');
  $detailTx = trim((string)($_GET['detail'] ?? ''));
  $msg = '';

  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $code = trim((string)($_POST['transaction_code'] ?? ''));
    try {
      if ($code === '') throw new Exception('Nomor transaksi tidak ditemukan.');
      $items = unit_review_load_items((int)$unitId, $code);
      if (!$items) throw new Exception('Transaksi tidak ditemukan pada unit ini.');

      if ($action === 'return') {
        if (!$canReturn) throw new Exception('Anda tidak diizinkan melakukan retur.');
        $alreadyReturned = false;
        foreach ($items as $it) {
          if (trim((string)($it['return_reason'] ?? '')) !== '') $alreadyReturned = true;
        }
        if ($alreadyReturned) throw new Exception('Transaksi sudah memiliki status retur.');
        $reason = trim((string)($_POST['reason'] ?? $_POST['return_reason'] ?? ''));
        if ($reason === '') $reason = 'Retur penjualan';
        $uuid = 'WEBRET-' . date('YmdHis') . '-' . strtoupper(substr(md5($code . microtime(true)), 0, 6));
        $preparedReturn = store_acc_prepare_transaction($items);
        $total = (float)$preparedReturn['net_before_return'];
        $preparedLinesById = [];
        foreach ($preparedReturn['rows'] as $preparedLine) $preparedLinesById[(int)$preparedLine['id']] = $preparedLine;
        db()->beginTransaction();
        db()->prepare("INSERT INTO sales_returns (offline_uuid, transaction_group_uuid, transaction_code, branch_id, reason, total_return, created_by, created_at, sync_status) VALUES (?,?,?,?,?,?,?,NOW(),'synced')")
          ->execute([$uuid, $items[0]['transaction_group_uuid'] ?? $code, $code, (int)$unitId, $reason, $total, (int)($u['id'] ?? 0)]);
        $rid = (int)db()->lastInsertId();
        foreach ($items as $it) {
          $lineTotal = (float)($preparedLinesById[(int)$it['id']]['_line_net'] ?? $it['total']);
          db()->prepare("INSERT INTO sales_return_items (return_id,sale_id,product_id,qty,price_each,subtotal) VALUES (?,?,?,?,?,?)")
            ->execute([$rid, (int)$it['id'], (int)$it['product_id'], (float)$it['qty'], (float)$it['price_each'], $lineTotal]);
          db()->prepare("UPDATE sales SET return_reason=?, returned_at=NOW(), returned_by=? WHERE id=? AND branch_id=?")
            ->execute([$reason, (int)($u['id'] ?? 0), (int)$it['id'], (int)$unitId]);
          try {
            add_stock_ledger([
              'branch_id' => (int)$unitId,
              'product_id' => (int)$it['product_id'],
              'trans_type' => 'sale_return',
              'ref_table' => 'sales_returns',
              'ref_id' => $rid,
              'qty_in' => (float)$it['qty'],
              'qty_out' => 0,
              'unit_cost' => null,
              'note' => $reason,
              'created_by' => (int)($u['id'] ?? 0),
            ]);
          } catch (Throwable $e) {}
        }
        db()->commit();
        $msg = 'Retur tersimpan.';
      } elseif ($action === 'edit') {
        if (!$isAdmin) throw new Exception('Hanya admin/owner yang bisa edit transaksi.');
        $newMethod = trim((string)($_POST['payment_method'] ?? ''));
        $newBank = trim((string)($_POST['payment_bank'] ?? ''));
        db()->prepare("UPDATE sales SET payment_method=COALESCE(NULLIF(?,''),payment_method), payment_bank=? WHERE branch_id=? AND COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-', id))=?")
          ->execute([$newMethod, $newBank, (int)$unitId, $code]);
        $msg = 'Transaksi diperbarui.';
      } elseif ($action === 'delete') {
        if (!$isOwner) throw new Exception('Hanya owner yang bisa menghapus riwayat penjualan.');
        db()->prepare("UPDATE sales SET include_in_sales_report=0, return_reason=COALESCE(NULLIF(return_reason,''),'Dihapus owner'), returned_at=COALESCE(returned_at,NOW()), returned_by=? WHERE branch_id=? AND COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-', id))=?")
          ->execute([(int)($u['id'] ?? 0), (int)$unitId, $code]);
        $msg = 'Riwayat penjualan dihapus/disembunyikan dari laporan unit.';
      }
    } catch (Throwable $e) {
      if (db()->inTransaction()) db()->rollBack();
      $msg = $e->getMessage();
    }
  }

  $rows = [];
  try {
    $txExpr = unit_review_tx_where_sql();
    $sql = "SELECT {$txExpr} AS transaction_code,
        MIN(s.sold_at) sold_at,
        COALESCE(MAX(u.name),'-') cashier,
        MAX(s.payment_method) payment_method,
        COALESCE(MAX(s.payment_bank),MAX(s.payment_channel_name),'') bank,
        SUM(s.qty) qty,
        SUM(COALESCE(NULLIF(s.line_net_total,0),s.total)) total,
        MAX(CASE WHEN s.return_reason IS NULL OR s.return_reason='' THEN 0 ELSE 1 END) returned,
        MAX(s.return_reason) return_reason
      FROM sales s
      LEFT JOIN users u ON u.id=s.created_by
      WHERE s.branch_id=? AND DATE(s.sold_at) BETWEEN ? AND ? AND COALESCE(s.include_in_sales_report,1)=1
      GROUP BY {$txExpr}
      ORDER BY sold_at DESC
      LIMIT 300";
    $st = db()->prepare($sql);
    $st->execute([(int)$unitId, $from, $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
      $txItems = unit_review_load_items((int)$unitId, (string)$row['transaction_code']);
      if ($txItems) {
        $prepared = store_acc_prepare_transaction($txItems);
        $row['total'] = (float)$prepared['net_before_return'];
      }
    }
    unset($row);
  } catch (Throwable $e) { $msg = $e->getMessage(); }

  $detailItems = [];
  $detailSale = null;
  if ($detailTx !== '' && $isAdmin) {
    try {
      $detailItems = unit_review_load_items((int)$unitId, $detailTx);
      if ($detailItems) {
        $preparedDetail = store_acc_prepare_transaction($detailItems);
        $detailItems = $preparedDetail['rows'];
      }
      $detailSale = $detailItems[0] ?? null;
      if (!$detailSale && $msg === '') $msg = 'Detail transaksi tidak ditemukan pada unit ini.';
    } catch (Throwable $e) { $msg = $e->getMessage(); }
  } elseif ($detailTx !== '' && !$isAdmin) {
    $msg = 'Detail isi transaksi hanya dapat dibuka admin atau owner.';
  }

  $title = $section === 'dapur' ? 'Review Penjualan Dapur' : 'Review Penjualan Cabang';
  $sidebarPath = $section === 'dapur' ? __DIR__ . '/../dapur/_sidebar.php' : __DIR__ . '/../cabang/_sidebar.php';
  $unitName = $unit['branch_name'] ?? ($section === 'dapur' ? 'Dapur' : 'Cabang');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo e($title); ?></title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .review-actions{display:flex;flex-wrap:wrap;gap:6px;align-items:center}.review-actions form{display:inline-flex;gap:6px;align-items:center;margin:0}.inline-mini{max-width:110px}.detail-card{margin-top:14px}.muted{opacity:.72}.danger{background:#dc2626;color:#fff;border-color:#dc2626}.table td,.table th{vertical-align:top}
  </style>
</head>
<body>
<div class="container">
  <?php include $sidebarPath; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><strong><?php echo e($title . ' - ' . $unitName); ?></strong></div>
    <div class="content">
      <?php if ($msg): ?><div class="alert"><?php echo e($msg); ?></div><?php endif; ?>
      <div class="card">
        <form class="grid cols-4" method="get">
          <input type="hidden" name="unit_id" value="<?php echo e((string)$unitId); ?>">
          <div class="row"><label>Dari</label><input type="date" name="from" value="<?php echo e($from); ?>"></div>
          <div class="row"><label>Sampai</label><input type="date" name="to" value="<?php echo e($to); ?>"></div>
          <div class="row" style="align-self:end"><button class="btn" type="submit">Filter</button></div>
        </form>
      </div>
      <div class="card">
        <table class="table">
          <thead><tr><th>Waktu</th><th>No</th><th>Kasir</th><th>Metode</th><th>Bank</th><th>Qty</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php foreach($rows as $r): $code=(string)$r['transaction_code']; ?>
              <tr>
                <td><?php echo e($r['sold_at']); ?></td>
                <td><strong><?php echo e($code); ?></strong></td>
                <td><?php echo e($r['cashier']); ?></td>
                <td><?php echo e($r['payment_method']); ?></td>
                <td><?php echo e($r['bank']); ?></td>
                <td><?php echo e((string)$r['qty']); ?></td>
                <td><?php echo e(format_money($r['total'])); ?></td>
                <td><?php echo ((int)$r['returned']===1) ? 'Retur: '.e($r['return_reason'] ?? '') : 'Aktif'; ?></td>
                <td>
                  <div class="review-actions">
                    <?php if($isAdmin): ?><a class="btn" href="<?php echo e(unit_url($section,(int)$unitId,'review_sales.php') . '&from=' . urlencode($from) . '&to=' . urlencode($to) . '&detail=' . urlencode($code)); ?>">Detail Isi</a><?php endif; ?>
                    <?php if((int)$r['returned']!==1 && $canReturn): ?>
                      <form method="post" onsubmit="return confirm('Retur transaksi ini?')">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="transaction_code" value="<?php echo e($code); ?>">
                        <input type="hidden" name="action" value="return">
                        <input class="inline-mini" name="reason" placeholder="alasan retur" value="Retur penjualan">
                        <button class="btn">Retur</button>
                      </form>
                    <?php endif; ?>
                    <?php if($isAdmin): ?>
                      <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="transaction_code" value="<?php echo e($code); ?>">
                        <input type="hidden" name="action" value="edit">
                        <input class="inline-mini" name="payment_method" placeholder="metode">
                        <input class="inline-mini" name="payment_bank" placeholder="bank">
                        <button class="btn">Edit</button>
                      </form>
                    <?php endif; ?>
                    <?php if($isOwner): ?>
                      <form method="post" onsubmit="return confirm('Hapus riwayat penjualan ini dari laporan unit?')">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="transaction_code" value="<?php echo e($code); ?>">
                        <input type="hidden" name="action" value="delete">
                        <button class="btn danger">Hapus</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; if(empty($rows)): ?><tr><td colspan="9">Belum ada data.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if($detailSale): ?>
        <div class="card detail-card">
          <h3 style="margin-top:0">Detail Isi Transaksi <?php echo e($detailTx); ?></h3>
          <p class="muted"><strong>Tanggal:</strong> <?php echo e($detailSale['sold_at']); ?> · <strong>Kasir:</strong> <?php echo e($detailSale['cashier_name'] ?? '-'); ?> · <strong>Pembayaran:</strong> <?php echo e(($detailSale['payment_method'] ?? '-') . (!empty($detailSale['payment_bank']) ? ' - ' . $detailSale['payment_bank'] : '')); ?></p>
          <?php if(!empty($detailSale['return_reason'])): ?><p><strong>Status retur:</strong> <?php echo e($detailSale['return_reason']); ?> <?php if(!empty($detailSale['returned_by_name'])) echo 'oleh ' . e($detailSale['returned_by_name']); ?></p><?php endif; ?>
          <table class="table"><thead><tr><th>Produk</th><th>Qty</th><th>Satuan</th><th>Harga</th><th>Diskon</th><th>Total</th></tr></thead><tbody>
            <?php $sum=0.0; foreach($detailItems as $it): $line=(float)($it['_line_net'] ?? ($it['line_net_total'] ?: $it['total'])); $sum += $line; ?>
              <tr><td><?php echo e($it['product_name'] ?? '-'); ?></td><td><?php echo e((string)$it['qty']); ?></td><td><?php echo e($it['sale_unit'] ?? 'pcs'); ?></td><td><?php echo e(format_money($it['price_each'])); ?></td><td><?php echo e(($it['discount_type'] ?? 'fixed') . ' ' . format_number_id((float)($it['discount_amount'] ?? 0))); ?></td><td><?php echo e(format_money($line)); ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
          <p><strong>Grand Total:</strong> <?php echo e(format_money($sum)); ?></p>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
<?php
}
