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
    $count=0; $note=trim((string)($_POST['notes'] ?? ''));
    foreach (($_POST['physical_qty'] ?? []) as $pid=>$qtyRaw) {
      $txt=trim((string)$qtyRaw); if ($txt==='') continue;
      portal_inventory_adjust_stock($locationId, (int)$pid, parse_number_input($txt), $note, (int)$u['id']);
      $count++;
    }
    $msg = $count > 0 ? 'Stok opname dapur berhasil diproses.' : 'Tidak ada item yang diisi.';
  } catch(Throwable $e) { $err=$e->getMessage(); }
}
$rows = portal_inventory_stock_rows($locationId,$search,'all');
$customCss = setting('custom_css','');
kitchen_header('Stok Opname Dapur', $customCss);
?>
<div class="card"><h3>Stok Opname Dapur</h3><p class="portal-note">Input stok fisik akan membuat penyesuaian masuk/keluar pada lokasi Dapur Produksi.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="get" class="grid cols-3"><div class="row"><label>Cari Produk</label><input name="search" value="<?php echo e($search); ?>" placeholder="nama/kategori/id"></div><div class="row" style="align-self:end"><button class="btn btn-light" type="submit">Cari</button></div></form></div>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="card"><div class="row"><label>Catatan Umum</label><input name="notes" placeholder="contoh: opname akhir bulan"></div><table class="table"><thead><tr><th>Produk</th><th>Stok Sistem</th><th>Stok Fisik</th></tr></thead><tbody><?php foreach($rows as $r): $unit=product_unit_fallback($r); ?><tr><td><?php echo e((string)$r['name']); ?><br><small><?php echo e((string)$unit['base_unit']); ?></small></td><td><?php echo e(format_qty((float)$r['stock_qty'],$unit['base_unit'])); ?></td><td><input name="physical_qty[<?php echo e((string)$r['id']); ?>]" inputmode="decimal" placeholder="kosongkan bila tidak dihitung"></td></tr><?php endforeach; ?></tbody></table><div style="margin-top:12px"><button class="btn" type="submit">Proses Opname</button></div></div></form>
<?php kitchen_footer(); ?>
