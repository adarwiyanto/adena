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
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$branches = inventory_branches();
$products = stock_products_for_opname($branchId);
$rows = [];
$opening = 0.0;
$product = null;
if ($productId > 0) {
  $stmt = db()->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
  $stmt->execute([$productId]);
  $product = $stmt->fetch() ?: null;
  if ($dateFrom !== '') {
    $stmt = db()->prepare("SELECT COALESCE(SUM(qty_in-qty_out),0) AS opening_qty FROM stock_ledger WHERE branch_id=? AND product_id=? AND DATE(created_at) < ?");
    $stmt->execute([$branchId, $productId, $dateFrom]);
    $opening = (float)($stmt->fetch()['opening_qty'] ?? 0);
  }
  $rows = stock_card_rows($branchId, $productId, $dateFrom, $dateTo);
}

$customCss = setting('custom_css', '');
$unitMeta = $product ? product_unit_fallback($product) : ['base_unit' => null];
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kartu Stok</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head>
<body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
<div class="content"><div class="card"><h3>Kartu Stok / Riwayat Stok</h3>
<form method="get" class="grid cols-4"><div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$branchId?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div>
<div class="row"><label>Barang</label><select name="product_id" required><option value="">Pilih Barang</option><?php foreach($products as $p): ?><option value="<?php echo e((string)$p['id']); ?>" <?php echo (int)$p['id']===$productId?'selected':''; ?>><?php echo e((string)$p['name']); ?></option><?php endforeach; ?></select></div>
<div class="row"><label>Dari</label><input type="date" name="date_from" value="<?php echo e($dateFrom); ?>"></div><div class="row"><label>Sampai</label><input type="date" name="date_to" value="<?php echo e($dateTo); ?>"></div>
<div class="row" style="align-self:end"><button class="btn" type="submit">Tampilkan</button></div>
</form>
</div>
<div class="card"><table class="table"><thead><tr><th>Tanggal</th><th>Trans Type</th><th>Referensi</th><th>Qty In</th><th>Qty Out</th><th>Saldo</th><th>Note</th><th>User</th></tr></thead><tbody>
<?php if($productId<=0): ?><tr><td colspan="8" style="text-align:center;color:#94a3b8">Pilih barang terlebih dulu.</td></tr><?php else:
  $running = $opening;
?>
<tr><td colspan="5"><strong>Saldo Awal</strong></td><td><strong><?php echo e(format_qty($opening, $unitMeta['base_unit'])); ?></strong></td><td colspan="2"></td></tr>
<?php if(empty($rows)): ?><tr><td colspan="8" style="text-align:center;color:#94a3b8">Tidak ada mutasi.</td></tr><?php else: foreach($rows as $r):
  $running += (float)$r['qty_in'] - (float)$r['qty_out'];
  $ref = (string)$r['ref_table'] . '#' . (string)$r['ref_id'];
  if (($r['ref_table'] ?? '') === 'stock_opname_headers' && !empty($r['opname_no'])) $ref = (string)$r['opname_no'];
  if (($r['ref_table'] ?? '') === 'purchase_headers' && !empty($r['purchase_no'])) $ref = (string)$r['purchase_no'];
  if (($r['ref_table'] ?? '') === 'production_headers' && !empty($r['production_no'])) $ref = (string)$r['production_no'];
?>
<tr>
<td><?php echo e((string)$r['created_at']); ?></td>
<td><?php echo e((string)$r['trans_type']); ?></td>
<td><?php echo e($ref); ?></td>
<td><?php echo e(format_qty((float)$r['qty_in'], $unitMeta['base_unit'])); ?></td>
<td><?php echo e(format_qty((float)$r['qty_out'], $unitMeta['base_unit'])); ?></td>
<td><?php echo e(format_qty((float)$running, $unitMeta['base_unit'])); ?></td>
<td><?php echo e((string)($r['note'] ?? '')); ?></td>
<td><?php echo e((string)($r['user_name'] ?? '-')); ?></td>
</tr>
<?php endforeach; endif; endif; ?>
</tbody></table></div>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
