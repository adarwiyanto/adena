<?php
require_once __DIR__ . '/../core/ops14.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = adena14_area_guard('kitchen');
$locationId = portal_inventory_kitchen_location_id();
$err=''; $msg=''; $search=trim((string)($_GET['search'] ?? ''));
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    portal_inventory_create_initial_stock($locationId, (int)($_POST['product_id'] ?? 0), parse_number_input($_POST['qty'] ?? 0), trim((string)($_POST['unit_cost'] ?? ''))!=='' ? parse_number_input($_POST['unit_cost']) : null, trim((string)($_POST['note'] ?? '')), (int)$u['id']);
    $msg = 'Stok awal dapur berhasil disimpan.';
  } catch(Throwable $e) { $err = $e->getMessage(); }
}
$products = portal_inventory_products($search, 'all');
$customCss = setting('custom_css','');
kitchen_header('Stok Awal Dapur', $customCss);
?>
<div class="card"><h3>Input Stok Awal Dapur</h3><p class="portal-note">Stok awal hanya bisa diinput satu kali per produk per lokasi. Koreksi berikutnya gunakan Stok Opname.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="get" class="grid cols-3"><div class="row"><label>Cari Produk</label><input name="search" value="<?php echo e($search); ?>" placeholder="nama/kategori/id"></div><div class="row" style="align-self:end"><button class="btn btn-light" type="submit">Cari</button></div></form></div>
<div class="card"><form method="post" class="grid cols-2"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>Produk</label><select name="product_id" required><?php foreach($products as $p): $unit=product_unit_fallback($p); ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name'].' - '.$unit['base_unit']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Qty Awal</label><input name="qty" inputmode="decimal" required placeholder="0.00"></div><div class="row"><label>Unit Cost (opsional)</label><input name="unit_cost" inputmode="decimal" placeholder="0.00"></div><div class="row"><label>Catatan</label><input name="note" placeholder="opsional"></div><div class="row" style="align-self:end"><button class="btn" type="submit">Simpan Stok Awal</button></div></form></div>
<?php kitchen_footer(); ?>
