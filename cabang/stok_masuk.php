<?php
require_once __DIR__ . '/../core/branch_portal.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/_layout.php';
$u = branch_portal_current_user();
$branchId = branch_portal_active_branch_id($u);
$branch = branch_portal_branch($branchId) ?: ['branch_name'=>'Halaman Cabang'];
$err=''; $msg=''; $search=trim((string)($_GET['search'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    $productId=(int)($_POST['product_id'] ?? 0);
    $qty=parse_number_input($_POST['qty'] ?? 0);
    $unitCostRaw=trim((string)($_POST['unit_cost'] ?? ''));
    $unitCost=$unitCostRaw!=='' ? parse_number_input($unitCostRaw) : null;
    $notes=trim((string)($_POST['notes'] ?? ''));
    branch_portal_create_stock_input($branchId,$productId,$qty,$unitCost,$notes,(int)$u['id']);
    $msg='Stok masuk berhasil dikirim dan menunggu approval admin.';
  } catch(Throwable $e) { $err=$e->getMessage(); }
}
$products=branch_portal_products($search); $customCss=setting('custom_css','');
cabang_header('Input Stok Masuk', $branch, $customCss);
?>
<div class="card"><h3>Input Stok Masuk</h3><p style="color:#64748b">Input dari cabang akan masuk status <strong>pending</strong>. Stok sistem baru bertambah setelah admin approve.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="get" class="grid cols-3"><div class="row"><label>Cari Produk</label><input name="search" value="<?php echo e($search); ?>" placeholder="nama/kategori/id"></div><div class="row" style="align-self:end"><button class="btn btn-light" type="submit">Cari</button></div></form></div>
<div class="card"><form method="post" class="grid cols-2"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>Produk</label><select name="product_id" required><?php foreach($products as $p): $unit=product_unit_fallback($p); ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name'].' - '.$unit['base_unit']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Jumlah Masuk</label><input name="qty" inputmode="decimal" required placeholder="0.00"></div><div class="row"><label>Harga Satuan / Unit Cost (opsional)</label><input name="unit_cost" inputmode="decimal" placeholder="0.00"></div><div class="row"><label>Keterangan</label><input name="notes" placeholder="contoh: kiriman pusat / produksi hari ini"></div><div class="row" style="align-self:end"><button class="btn" type="submit">Kirim Stok Masuk</button></div></form></div>
<?php cabang_footer(); ?>
