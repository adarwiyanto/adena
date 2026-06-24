<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
$u = require_menu_access('inventori');
ensure_inventory_module_schema();

$branchId = (int)($_GET['branch_id'] ?? active_branch_id());
$search = trim((string)($_GET['search'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));
$productType = trim((string)($_GET['product_type'] ?? ''));
$stockStatus = trim((string)($_GET['stock_status'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$rows = stock_products_for_stock_view($branchId, $search, $category, $productType);
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
  <style><?php echo $customCss; ?>
    .stock-page{max-width:1560px;margin:0 auto;padding:18px 22px 34px;}
    .stock-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px;}
    .stock-head h3{margin:0 0 4px;font-size:22px;}
    .stock-muted{color:#64748b;font-size:13px;}
    .stock-filter-grid{display:grid;grid-template-columns:1.1fr 1.6fr 1.1fr 1fr 1fr;gap:14px;align-items:end;}
    .stock-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;}
    .stock-actions .btn{min-width:130px;text-align:center;}
    .stock-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px;}
    .stock-stat{border:1px solid #e5e7eb;border-radius:16px;padding:12px 14px;background:#f8fafc;}
    .stock-stat strong{display:block;font-size:20px;color:#0f172a;margin-top:3px;}
    .stock-table-wrap{overflow:auto;border-radius:14px;border:1px solid #e5e7eb;}
    .stock-table-wrap .table{min-width:1120px;margin:0;}
    .stock-name{font-weight:700;color:#0f172a;min-width:240px;}
    .stock-code{color:#64748b;font-size:12px;}
    .stock-number{text-align:right;font-weight:700;white-space:nowrap;}
    .stock-track-no{color:#b45309;font-size:12px;display:block;margin-top:3px;}
    @media (max-width:1200px){.stock-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.stock-summary{grid-template-columns:repeat(2,minmax(0,1fr));}}
    @media (max-width:700px){.stock-page{padding:12px}.stock-filter-grid,.stock-summary{grid-template-columns:1fr}.stock-head{display:block}.stock-actions .btn{width:100%;}}
  </style>
</head>
<body class="desktop-compact">
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content stock-page">
      <div class="card">
        <div class="stock-head">
          <div>
            <h3>Daftar Stok</h3>
            <div class="stock-muted">Menampilkan produk stok per cabang. Produk POS tetap diatur terpisah oleh show_on_pos.</div>
          </div>
        </div>
        <form method="get">
          <div class="stock-filter-grid">
          <div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$branchId?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div>
          <div class="row"><label>Search</label><input type="text" name="search" value="<?php echo e($search); ?>" placeholder="Nama/kategori/kode"></div>
          <div class="row"><label>Kategori</label><select name="category"><option value="">Semua</option><?php foreach($categories as $c): ?><option value="<?php echo e((string)$c['category']); ?>" <?php echo $category===(string)$c['category']?'selected':''; ?>><?php echo e((string)$c['category']); ?></option><?php endforeach; ?></select></div>
          <div class="row"><label>Jenis Produk</label><select name="product_type"><option value="">Semua</option><option value="raw_material" <?php echo $productType==='raw_material'?'selected':''; ?>>Raw Material</option><option value="finished_good" <?php echo $productType==='finished_good'?'selected':''; ?>>Finished Good</option></select></div>
          <div class="row"><label>Status Stok</label><select name="stock_status"><option value="">Semua</option><option value="menipis" <?php echo $stockStatus==='menipis'?'selected':''; ?>>Menipis</option><option value="habis" <?php echo $stockStatus==='habis'?'selected':''; ?>>Habis</option></select></div>
          </div>
          <div class="stock-actions">
            <button class="btn" type="submit">Filter</button>
            <a class="btn btn-light" href="<?php echo e(base_url('admin/stocks.php')); ?>">Reset</a>
            <?php if (has_menu_access($u, 'stok_opname', 'create')): ?><a class="btn" href="<?php echo e(base_url('admin/stock_opname_form.php?branch_id=' . $branchId)); ?>">Buat Opname Baru</a><?php endif; ?>
          </div>
        </form>
        <div class="stock-summary">
          <div class="stock-stat"><span>Total item</span><strong><?php echo e((string)$totalItems); ?></strong></div>
          <div class="stock-stat"><span>Cabang</span><strong><?php foreach($branches as $b){ if((int)$b['id']===$branchId){ echo e((string)$b['branch_name']); break; } } ?></strong></div>
          <div class="stock-stat"><span>Filter kategori</span><strong><?php echo e($category !== '' ? $category : 'Semua'); ?></strong></div>
          <div class="stock-stat"><span>Status</span><strong><?php echo e($stockStatus !== '' ? ucfirst($stockStatus) : 'Semua'); ?></strong></div>
        </div>
      </div>

      <div class="card">
        <div class="stock-table-wrap">
        <table class="table">
          <thead><tr><th>No</th><th>Nama Barang</th><th>Kode/ID</th><th>Kategori</th><th>Jenis</th><th>Track</th><th>Stok Saat Ini</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php if (empty($pagedRows)): ?>
            <tr><td colspan="9" style="text-align:center;color:#94a3b8">Tidak ada data.</td></tr>
          <?php else: foreach($pagedRows as $idx => $r): ?>
            <?php $unitMeta = product_unit_fallback($r); ?>
            <tr>
              <td><?php echo e((string)($offset + $idx + 1)); ?></td>
              <td><div class="stock-name"><?php echo e((string)$r['name']); ?></div><?php if ((int)$r['track_stock']!==1): ?><span class="stock-track-no">Belum aktif track stock</span><?php endif; ?></td>
              <td><span class="stock-code">#<?php echo e((string)$r['id']); ?></span></td>
              <td><?php echo e((string)($r['category'] ?? '-')); ?></td>
              <td><?php echo e((string)$r['product_type']); ?></td>
              <td><?php echo (int)$r['track_stock']===1 ? 'Ya' : 'Tidak'; ?></td>
              <td class="stock-number"><?php echo e(format_qty((float)$r['current_stock'], $unitMeta['base_unit'])); ?></td>
              <td><span class="badge" style="<?php echo stock_status_badge_class((string)$r['stock_status']); ?>"><?php echo e((string)$r['stock_status']); ?></span></td>
              <td><a class="btn btn-light" href="<?php echo e(base_url('admin/stock_card.php?branch_id=' . $branchId . '&product_id=' . (int)$r['id'])); ?>">Kartu Stok</a></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>
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
