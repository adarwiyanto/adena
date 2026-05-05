<?php
require_once __DIR__ . '/../core/ops14.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = adena14_area_guard('kitchen');
$customCss = setting('custom_css','');
$err=''; $msg=''; $rows=[]; $locationId=1;
try { $locationId=portal_inventory_kitchen_location_id(); $rows=portal_inventory_stock_rows($locationId,'','all'); } catch(Throwable $e){ $err=$e->getMessage(); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    $count=0;
    foreach(($_POST['physical_qty'] ?? []) as $pid=>$raw){
      $raw=trim((string)$raw);
      if($raw==='') continue;
      portal_inventory_adjust_stock($locationId,(int)$pid,parse_number_input($raw),trim((string)($_POST['note'] ?? '')),(int)$u['id']);
      $count++;
    }
    $msg='Opname dapur diproses untuk '.$count.' produk.';
    $rows=portal_inventory_stock_rows($locationId,'','all');
  } catch(Throwable $e){ $err=$e->getMessage(); }
}
kitchen_header('Stok Opname Dapur', $customCss);
?>
<div class="card"><h3>Stok Opname Dapur</h3><p class="portal-note">Input hanya produk yang ingin dikoreksi. Sistem membuat adjustment di stock_ledger berdasarkan location_id dapur.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>Catatan opname</label><input name="note" placeholder="opsional"></div><table class="table"><thead><tr><th>Produk</th><th>Stok Sistem</th><th>Stok Fisik Baru</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="3" style="text-align:center;color:#94a3b8">Data tidak ditemukan.</td></tr><?php endif; foreach($rows as $r): $unit=product_unit_fallback($r); ?><tr><td><?php echo e((string)$r['name']); ?></td><td><?php echo e(format_qty((float)$r['stock_qty'],$unit['base_unit'])); ?></td><td><input name="physical_qty[<?php echo e((string)$r['id']); ?>]" inputmode="decimal" placeholder="kosongkan bila tidak diubah"></td></tr><?php endforeach; ?></tbody></table><button class="btn" type="submit">Proses Opname</button></form></div>
<?php kitchen_footer(); ?>
