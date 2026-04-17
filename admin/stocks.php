<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
$u = inventory_require_stock_role();
ensure_inventory_module_schema();

$branchId = (int)($_GET['branch_id'] ?? active_branch_id());
$search = trim((string)($_GET['search'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$productType = trim((string)($_GET['product_type'] ?? ''));
$stockStatus = trim((string)($_GET['stock_status'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$rows = stock_products_for_opname($branchId, $search, $category, $productType);
$filtered = [];
foreach ($rows as $r) {
  $stockQty = (float)($r['current_stock'] ?? 0);
  $status = stock_status_label($stockQty, (float)($r['reorder_level'] ?? 0));
  if ($stockStatus === 'menipis' && $status !== 'Menipis') continue;
  if ($stockStatus === 'habis' && $status !== 'Habis') continue;
  $r['stock_status'] = $status;
  $filtered[] = $r;
}
$totalItems = count($filtered);
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$pagedRows = array_slice($filtered, $offset, $perPage);

$branches = inventory_branches();
$categories = stock_categories();
$customCss = setting('custom_css', '');

function stock_status_badge_class(string $status): string {
  if ($status === 'Habis') return 'background:#fff1f2;border-color:#fecdd3;color:#9f1239;';
  if ($status === 'Menipis') return 'background:#fff7ed;border-color:#fed7aa;color:#9a3412;';
  return 'background:#ecfeff;border-color:#bae6fd;color:#155e75;';
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Daftar Stok</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content">
      <div class="card">
        <h3>Daftar Stok</h3>
        <form method="get" class="grid cols-4">
          <div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$branchId?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div>
          <div class="row"><label>Search</label><input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Nama/kategori/kode"></div>
          <div class="row"><label>Kategori</label><select name="category"><option value="">Semua</option><?php foreach($categories as $c): ?><option value="<?php echo e((string)$c['category']); ?>" <?php echo $category===(string)$c['category']?'selected':''; ?>><?php echo e((string)$c['category']); ?></option><?php endforeach; ?></select></div>
          <div class="row"><label>Jenis Produk</label><select name="product_type"><option value="">Semua</option><option value="raw_material" <?php echo $productType==='raw_material'?'selected':''; ?>>Raw Material</option><option value="finished_good" <?php echo $productType==='finished_good'?'selected':''; ?>>Finished Good</option></select></div>
          <div class="row"><label>Status Stok</label><select name="stock_status"><option value="">Semua</option><option value="menipis" <?php echo $stockStatus==='menipis'?'selected':''; ?>>Menipis</option><option value="habis" <?php echo $stockStatus==='habis'?'selected':''; ?>>Habis</option></select></div>
          <div class="row" style="align-self:end"><button class="btn" type="submit">Filter</button></div>
          <div class="row" style="align-self:end"><a class="btn btn-light" href="<?php echo e(base_url('admin/stocks.php')); ?>">Reset</a></div>
          <div class="row" style="align-self:end"><a class="btn" href="<?php echo e(base_url('admin/stock_opname_form.php?branch_id=' . $branchId)); ?>">Buat Opname Baru</a></div>
        </form>
        <p style="margin-top:12px;color:#64748b">Total item: <strong><?php echo e((string)$totalItems); ?></strong></p>
      </div>

      <div class="card">
        <table class="table">
          <thead><tr><th>No</th><th>Nama Barang</th><th>Kode/ID</th><th>Kategori</th><th>Jenis</th><th>Track</th><th>Stok Saat Ini</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php if (empty($pagedRows)): ?>
            <tr><td colspan="9" style="text-align:center;color:#94a3b8">Tidak ada data.</td></tr>
          <?php else: foreach($pagedRows as $idx => $r): ?>
            <?php $unitMeta = product_unit_fallback($r); ?>
            <tr>
              <td><?php echo e((string)($offset + $idx + 1)); ?></td>
              <td><?php echo e((string)$r['name']); ?></td>
              <td>#<?php echo e((string)$r['id']); ?></td>
              <td><?php echo e((string)($r['category'] ?? '-')); ?></td>
              <td><?php echo e((string)$r['product_type']); ?></td>
              <td><?php echo (int)$r['track_stock']===1 ? 'Ya' : 'Tidak'; ?></td>
              <td><?php echo e(format_qty((float)$r['current_stock'], $unitMeta['base_unit'])); ?></td>
              <td><span class="badge" style="<?php echo stock_status_badge_class((string)$r['stock_status']); ?>"><?php echo e((string)$r['stock_status']); ?></span></td>
              <td><a class="btn btn-light" href="<?php echo e(base_url('admin/stock_card.php?branch_id=' . $branchId . '&product_id=' . (int)$r['id'])); ?>">Kartu Stok</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        <?php if ($totalPages > 1): ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
            <?php for($i=1; $i<=$totalPages; $i++):
              $params = $_GET; $params['page'] = $i;
              $url = base_url('admin/stocks.php?' . http_build_query($params));
            ?>
              <a class="btn btn-light" href="<?php echo e($url); ?>" style="<?php echo $i===$page?'background:#dbeafe;border-color:#93c5fd;':''; ?>"><?php echo e((string)$i); ?></a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
