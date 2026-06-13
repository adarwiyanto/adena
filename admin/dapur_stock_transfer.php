<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/dapur_stock_transfer.php';

start_secure_session();
$me = require_menu_access('sales', 'view');
$roleKey = (string)(resolve_user_role($me)['role_key'] ?? '');
if (!in_array($roleKey, ['owner','admin'], true)) redirect(base_url('admin/dashboard.php'));

ensure_dapur_stock_transfer_schema();
$customCss = setting('custom_css', '');
$ok = '';
$err = '';
$branchId = dapur_active_branch_id();
$userId = (int)($me['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_check();
  try {
    $result = dapur_create_stock_transfer([
      'product_id' => (int)($_POST['product_id'] ?? 0),
      'qty' => (float)($_POST['qty'] ?? 0),
      'direction' => (string)($_POST['direction'] ?? 'in'),
      'notes' => (string)($_POST['notes'] ?? ''),
      'unit_cost' => ($_POST['unit_cost'] ?? ''),
      'source' => 'admin',
      'created_by' => $userId,
      'branch_id' => $branchId,
    ]);
    $ok = (!empty($result['duplicate']) ? 'Transfer sudah pernah tercatat: ' : 'Transfer stok berhasil disimpan: ') . (string)$result['transfer_no'];
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$search = trim((string)($_GET['q'] ?? ''));
$params = [$branchId];
$where = "WHERE p.track_stock=1";
if ($search !== '') {
  $where .= " AND (p.name LIKE ? OR p.category LIKE ?)";
  $params[] = '%' . $search . '%';
  $params[] = '%' . $search . '%';
}
$sql = "SELECT p.id, p.name, p.base_unit, p.category, COALESCE(SUM(sl.qty_in - sl.qty_out),0) AS stock_qty
        FROM products p
        LEFT JOIN stock_ledger sl ON sl.product_id=p.id AND sl.branch_id=?
        {$where}
        GROUP BY p.id, p.name, p.base_unit, p.category
        ORDER BY p.name ASC
        LIMIT 250";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = db()->prepare("SELECT d.*, p.name AS product_name, p.base_unit
  FROM dapur_stock_transfers d
  JOIN products p ON p.id=d.product_id
  WHERE d.branch_id=?
  ORDER BY d.created_at DESC, d.id DESC
  LIMIT 30");
$stmt->execute([$branchId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Transfer Stok dari Dapur</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    <?php echo $customCss; ?>
    .dapur-transfer-wrap{max-width:1100px}.dapur-card{padding:14px;border-radius:12px}.dapur-form-grid{display:grid;grid-template-columns:2fr 130px 170px 130px;gap:10px;align-items:end}.dapur-form-grid .wide{grid-column:1 / -2}.dapur-muted{color:#64748b;font-size:12px}.dapur-actions{display:flex;gap:8px;align-items:end}.dapur-table small{color:#64748b}.badge-in{background:#dcfce7;color:#166534;padding:3px 8px;border-radius:999px;font-size:12px}.badge-return{background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:999px;font-size:12px}@media(max-width:980px){.dapur-form-grid{grid-template-columns:1fr}.dapur-form-grid .wide{grid-column:auto}}
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="title">Transfer Stok dari Dapur</div></div>
    <div class="content dapur-transfer-wrap">
      <div class="card dapur-card">
        <h3 style="margin-top:0">Transfer Stok dari Dapur</h3>
        <p class="dapur-muted">Input ringkas untuk stok masuk dari dapur atau pengembalian stok ke dapur. Efek stok dicatat ke kartu stok/ledger.</p>
        <?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>
        <form method="get" class="dapur-actions" style="margin-bottom:10px">
          <div class="row" style="min-width:280px"><label>Cari produk</label><input type="search" name="q" value="<?php echo e($search); ?>" placeholder="Nama/kategori produk"></div>
          <button class="btn" type="submit">Cari</button>
          <?php if ($search !== ''): ?><a class="btn" href="<?php echo e(base_url('admin/dapur_stock_transfer.php')); ?>">Reset</a><?php endif; ?>
        </form>
        <form method="post" class="dapur-form-grid">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <div class="row">
            <label>Produk</label>
            <select name="product_id" required>
              <option value="">Pilih produk</option>
              <?php foreach ($products as $p): ?>
                <option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name']); ?><?php echo $p['base_unit'] ? ' — stok ' . e(format_number_id((float)$p['stock_qty'])) . ' ' . e($p['base_unit']) : ' — stok ' . e(format_number_id((float)$p['stock_qty'])); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row"><label>Qty</label><input type="number" name="qty" step="0.0001" min="0.0001" required></div>
          <div class="row"><label>Jenis</label><select name="direction"><option value="in">Masuk dari dapur</option><option value="return">Pengembalian ke dapur</option></select></div>
          <div class="row"><label>Harga/Cost</label><input type="number" name="unit_cost" step="0.01" min="0" placeholder="Opsional"></div>
          <div class="row wide"><label>Catatan</label><input type="text" name="notes" maxlength="255" placeholder="Opsional"></div>
          <button class="btn" type="submit">Simpan</button>
        </form>
      </div>

      <div class="card dapur-card" style="margin-top:12px">
        <h3 style="margin-top:0">Riwayat Terakhir</h3>
        <div class="table-wrap"><table class="dapur-table">
          <thead><tr><th>Waktu</th><th>No</th><th>Jenis</th><th>Produk</th><th>Qty</th><th>Catatan</th><th>Sumber</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo e((string)$r['created_at']); ?></td>
              <td><small><?php echo e((string)$r['transfer_no']); ?></small></td>
              <td><?php echo $r['direction'] === 'return' ? '<span class="badge-return">Ke dapur</span>' : '<span class="badge-in">Dari dapur</span>'; ?></td>
              <td><?php echo e((string)$r['product_name']); ?></td>
              <td><?php echo e(format_number_id((float)$r['qty'])); ?> <?php echo e((string)($r['base_unit'] ?? '')); ?></td>
              <td><?php echo e((string)($r['notes'] ?? '')); ?></td>
              <td><?php echo e((string)$r['source']); ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="7">Belum ada riwayat transfer dapur.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
