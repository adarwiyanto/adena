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
    if (!has_menu_access($u, 'branch_page', 'create')) { throw new Exception('User tidak memiliki izin input halaman cabang.'); }
    $items=[];
    foreach (($_POST['physical_qty'] ?? []) as $pid => $qtyRaw) {
      $qtyText=trim((string)$qtyRaw);
      if ($qtyText === '') continue;
      $items[]=['product_id'=>(int)$pid,'physical_qty'=>parse_number_input($qtyText),'line_note'=>trim((string)(($_POST['line_note'] ?? [])[$pid] ?? ''))];
    }
    branch_portal_create_blind_opname($branchId,$items,trim((string)($_POST['notes'] ?? '')),(int)$u['id']);
    $msg='Stock opname berhasil dikirim ke admin untuk approval.';
  } catch(Throwable $e) { $err=$e->getMessage(); }
}
$products=branch_portal_products($search); $customCss=setting('custom_css','');
cabang_header('Stock Opname Cabang', $branch, $customCss);
?>
<div class="card"><h3>Stock Opname Cabang</h3><p style="color:#64748b">Cabang hanya mengisi stok fisik. Stok sistem dan selisih tidak ditampilkan.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="get" class="grid cols-3"><div class="row"><label>Cari Produk</label><input name="search" value="<?php echo e($search); ?>" placeholder="nama/kategori/id"></div><div class="row" style="align-self:end"><button class="btn btn-light" type="submit">Cari</button></div></form></div>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="card"><div class="row"><label>Catatan Umum Opname</label><input name="notes" placeholder="contoh: opname akhir shift / akhir bulan"></div><table class="table"><thead><tr><th>Produk</th><th>Kategori</th><th>Stok Fisik</th><th>Catatan Item</th></tr></thead><tbody><?php if(empty($products)): ?><tr><td colspan="4" style="text-align:center;color:#94a3b8">Produk tidak ditemukan.</td></tr><?php else: foreach($products as $p): $unit=product_unit_fallback($p); ?><tr><td><?php echo e((string)$p['name']); ?><br><small><?php echo e((string)$unit['base_unit']); ?></small></td><td><?php echo e((string)($p['category'] ?? '-')); ?></td><td><input name="physical_qty[<?php echo e((string)$p['id']); ?>]" inputmode="decimal" placeholder="kosongkan bila tidak dihitung"></td><td><input name="line_note[<?php echo e((string)$p['id']); ?>]" placeholder="opsional"></td></tr><?php endforeach; endif; ?></tbody></table><div style="margin-top:12px"><button class="btn" type="submit">Kirim Opname ke Admin</button></div></div></form>
<?php cabang_footer(); ?>
