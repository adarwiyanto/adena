<?php
require_once __DIR__ . '/../core/branch_portal.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = branch_portal_current_user();
$branchId = branch_portal_active_branch_id($u);
$branch = branch_portal_branch($branchId) ?: ['branch_name'=>'Halaman Cabang'];
$locationId = portal_inventory_branch_location_id($branchId);
$search = trim((string)($_GET['search'] ?? ''));
$rows = portal_inventory_stock_rows($locationId, $search, 'all');
$customCss=setting('custom_css','');
cabang_header('Stok Cabang', $branch, $customCss);
?>
<div class="card"><h3>Daftar Stok Cabang</h3><p style="color:#64748b">Stok dibaca dari lokasi cabang aktif. Halaman ini tidak masuk ke Admin.</p><form method="get" class="grid cols-3"><div class="row"><label>Search</label><input name="search" value="<?php echo e($search); ?>" placeholder="Nama/kategori/kode"></div><div class="row" style="align-self:end"><button class="btn" type="submit">Filter</button></div></form></div>
<div class="card"><table class="table"><thead><tr><th>Produk</th><th>Kategori</th><th>Jenis</th><th style="text-align:right">Stok Cabang</th></tr></thead><tbody><?php if(empty($rows)): ?><tr><td colspan="4" style="text-align:center;color:#94a3b8">Data tidak ditemukan.</td></tr><?php else: foreach($rows as $r): $unit=product_unit_fallback($r); ?><tr><td><?php echo e((string)$r['name']); ?></td><td><?php echo e((string)($r['category'] ?? '-')); ?></td><td><?php echo e((string)$r['product_type']); ?></td><td style="text-align:right;font-weight:800"><?php echo e(format_qty((float)$r['stock_qty'],$unit['base_unit'])); ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
<?php cabang_footer(); ?>
