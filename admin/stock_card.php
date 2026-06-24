<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
inventory_require_stock_role();
ensure_inventory_module_schema();

$branchId = (int)($_GET['branch_id'] ?? active_branch_id());
$productId = (int)($_GET['product_id'] ?? 0);
$productSearch = trim((string)($_GET['product_search'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$branches = inventory_branches();
$products = stock_products_for_stock_view($branchId, $productSearch, '', '');
$rows = [];
$opening = 0.0;
$product = null;
$currentStock = 0.0;

if ($productId > 0) {
  $stmt = db()->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
  $stmt->execute([$productId]);
  $product = $stmt->fetch() ?: null;
  if ($product) {
    $existsInDropdown = false;
    foreach ($products as $p) {
      if ((int)$p['id'] === $productId) { $existsInDropdown = true; break; }
    }
    if (!$existsInDropdown) {
      $extra = stock_products_for_stock_view($branchId, (string)$productId, '', '');
      foreach ($extra as $p) {
        if ((int)$p['id'] === $productId) { array_unshift($products, $p); break; }
      }
    }
    $currentStock = branch_stock($branchId, $productId);
    if ($dateFrom !== '') {
      $stmt = db()->prepare("SELECT COALESCE(SUM(qty_in-qty_out),0) AS opening_qty FROM stock_ledger WHERE branch_id=? AND product_id=? AND DATE(created_at) < ?");
      $stmt->execute([$branchId, $productId, $dateFrom]);
      $opening = (float)($stmt->fetch()['opening_qty'] ?? 0);
    }
    $rows = stock_card_rows($branchId, $productId, $dateFrom, $dateTo);
  }
}

$customCss = setting('custom_css', '');
$unitMeta = $product ? product_unit_fallback($product) : ['base_unit' => null];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kartu Stok</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?>
    .stock-card-page{max-width:1560px;margin:0 auto;padding:18px 22px 34px;}
    .stock-title{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:16px;}
    .stock-title h3{margin:0 0 4px;font-size:22px;}
    .stock-muted{color:#64748b;font-size:13px;}
    .stock-form-grid{display:grid;grid-template-columns:1fr 1.3fr 1.9fr 1fr 1fr;gap:14px;align-items:end;}
    .stock-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;}
    .stock-actions .btn{min-width:140px;text-align:center;}
    .stock-product-summary{display:grid;grid-template-columns:2fr repeat(3,minmax(0,1fr));gap:12px;margin-top:16px;}
    .stock-summary-box{border:1px solid #e5e7eb;border-radius:16px;padding:12px 14px;background:#f8fafc;}
    .stock-summary-box span{display:block;color:#64748b;font-size:12px;}
    .stock-summary-box strong{display:block;font-size:20px;color:#0f172a;margin-top:3px;}
    .stock-product-name strong{font-size:18px;}
    .stock-table-wrap{overflow:auto;border-radius:14px;border:1px solid #e5e7eb;}
    .stock-table-wrap .table{min-width:1120px;margin:0;}
    .stock-number{text-align:right;font-weight:700;white-space:nowrap;}
    .stock-ref{font-size:12px;color:#475569;white-space:nowrap;}
    .stock-note{min-width:220px;color:#475569;}
    @media (max-width:1300px){.stock-form-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.stock-product-summary{grid-template-columns:repeat(2,minmax(0,1fr));}}
    @media (max-width:700px){.stock-card-page{padding:12px}.stock-form-grid,.stock-product-summary{grid-template-columns:1fr}.stock-title{display:block}.stock-actions .btn{width:100%;}}
  </style>
</head>
<body class="desktop-compact">
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content stock-card-page">
      <div class="card">
        <div class="stock-title">
          <div>
            <h3>Kartu Stok / Riwayat Stok</h3>
            <div class="stock-muted">Pilih cabang dan barang untuk melihat mutasi masuk-keluar beserta saldo berjalan.</div>
          </div>
        </div>
        <form method="get">
          <div class="stock-form-grid">
            <div class="row"><label>Cabang</label><select name="branch_id" onchange="this.form.submit()">
              <?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$branchId?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?>
            </select></div>
            <div class="row"><label>Cari Barang</label><input type="text" name="product_search" value="<?php echo e($productSearch); ?>" placeholder="Nama/kategori/kode"></div>
            <div class="row"><label>Barang</label><select name="product_id" required><option value="">Pilih Barang</option><?php foreach($products as $p): ?><?php $pm = product_unit_fallback($p); ?><option value="<?php echo e((string)$p['id']); ?>" <?php echo (int)$p['id']===$productId?'selected':''; ?>><?php echo e((string)$p['name']); ?> — stok <?php echo e(format_qty((float)$p['current_stock'], $pm['base_unit'])); ?></option><?php endforeach; ?></select></div>
            <div class="row"><label>Dari</label><input type="date" name="date_from" value="<?php echo e($dateFrom); ?>"></div>
            <div class="row"><label>Sampai</label><input type="date" name="date_to" value="<?php echo e($dateTo); ?>"></div>
          </div>
          <div class="stock-actions">
            <button class="btn" type="submit">Tampilkan</button>
            <a class="btn btn-light" href="<?php echo e(base_url('admin/stock_card.php?branch_id=' . $branchId)); ?>">Reset</a>
            <a class="btn btn-light" href="<?php echo e(base_url('admin/stocks.php?branch_id=' . $branchId)); ?>">Kembali ke Daftar Stok</a>
          </div>
        </form>
        <?php if($product): ?>
          <div class="stock-product-summary">
            <div class="stock-summary-box stock-product-name"><span>Barang</span><strong><?php echo e((string)$product['name']); ?></strong></div>
            <div class="stock-summary-box"><span>Kategori</span><strong><?php echo e((string)($product['category'] ?? '-')); ?></strong></div>
            <div class="stock-summary-box"><span>Jenis</span><strong><?php echo e((string)$product['product_type']); ?></strong></div>
            <div class="stock-summary-box"><span>Stok saat ini</span><strong><?php echo e(format_qty($currentStock, $unitMeta['base_unit'])); ?></strong></div>
          </div>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="stock-table-wrap">
          <table class="table">
            <thead><tr><th>Tanggal</th><th>Trans Type</th><th>Referensi</th><th>Qty In</th><th>Qty Out</th><th>Saldo</th><th>Note</th><th>User</th></tr></thead>
            <tbody>
            <?php if($productId<=0): ?>
              <tr><td colspan="8" style="text-align:center;color:#94a3b8">Pilih barang terlebih dulu.</td></tr>
            <?php elseif(!$product): ?>
              <tr><td colspan="8" style="text-align:center;color:#94a3b8">Barang tidak ditemukan.</td></tr>
            <?php else:
              $running = $opening;
            ?>
              <tr><td colspan="5"><strong>Saldo Awal</strong></td><td class="stock-number"><strong><?php echo e(format_qty($opening, $unitMeta['base_unit'])); ?></strong></td><td colspan="2"></td></tr>
              <?php if(empty($rows)): ?>
                <tr><td colspan="8" style="text-align:center;color:#94a3b8">Tidak ada mutasi pada periode ini.</td></tr>
              <?php else: foreach($rows as $r):
                $running += (float)$r['qty_in'] - (float)$r['qty_out'];
                $ref = (string)$r['ref_table'] . '#' . (string)$r['ref_id'];
                if (($r['ref_table'] ?? '') === 'stock_opname_headers' && !empty($r['opname_no'])) $ref = (string)$r['opname_no'];
                if (($r['ref_table'] ?? '') === 'purchase_headers' && !empty($r['purchase_no'])) $ref = (string)$r['purchase_no'];
                if (($r['ref_table'] ?? '') === 'production_headers' && !empty($r['production_no'])) $ref = (string)$r['production_no'];
              ?>
              <tr>
                <td><?php echo e((string)$r['created_at']); ?></td>
                <td><?php echo e((string)$r['trans_type']); ?></td>
                <td><span class="stock-ref"><?php echo e($ref); ?></span></td>
                <td class="stock-number"><?php echo e(format_qty((float)$r['qty_in'], $unitMeta['base_unit'])); ?></td>
                <td class="stock-number"><?php echo e(format_qty((float)$r['qty_out'], $unitMeta['base_unit'])); ?></td>
                <td class="stock-number"><?php echo e(format_qty((float)$running, $unitMeta['base_unit'])); ?></td>
                <td class="stock-note"><?php echo e((string)($r['note'] ?? '')); ?></td>
                <td><?php echo e((string)($r['user_name'] ?? '-')); ?></td>
              </tr>
              <?php endforeach; endif; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
