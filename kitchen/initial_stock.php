<?php
require_once __DIR__ . '/../core/ops14.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = adena14_area_guard('kitchen');
$customCss = setting('custom_css','');
$err=''; $msg=''; $products=[]; $rows=[]; $locationId=1;
try { $locationId = portal_inventory_kitchen_location_id(); $products = portal_inventory_products('', 'all'); } catch(Throwable $e) { $err = $e->getMessage(); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    $pid=(int)($_POST['product_id'] ?? 0);
    $qty=parse_number_input($_POST['qty'] ?? 0);
    $cost=trim((string)($_POST['unit_cost'] ?? ''))!=='' ? parse_number_input($_POST['unit_cost']) : null;
    portal_inventory_create_initial_stock($locationId,$pid,$qty,$cost,trim((string)($_POST['note'] ?? '')),(int)$u['id']);
    $msg='Stok awal dapur berhasil disimpan.';
  } catch(Throwable $e){ $err=$e->getMessage(); }
}
try {
  if (portal_table_exists('initial_stock_entries')) {
    $stmt=db()->prepare("SELECT ise.*,p.name product_name,p.base_unit,p.product_type FROM initial_stock_entries ise JOIN products p ON p.id=ise.product_id WHERE ise.location_id=? ORDER BY ise.id DESC LIMIT 80");
    $stmt->execute([$locationId]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch(Throwable $e){ if(!$err) $err=$e->getMessage(); }
kitchen_header('Stok Awal Dapur', $customCss);
?>
<div class="card"><h3>Input Stok Awal Dapur</h3><p class="portal-note">Stok awal hanya untuk saldo awal lokasi dapur. Untuk koreksi berikutnya gunakan Stok Opname.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="post" class="grid cols-4"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row wide"><label>Produk</label><select name="product_id" required><option value="0">-- pilih --</option><?php foreach($products as $p): ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e((string)$p['name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Qty</label><input name="qty" inputmode="decimal" required></div><div class="row"><label>Unit Cost</label><input name="unit_cost" inputmode="decimal"></div><div class="row wide"><label>Catatan</label><input name="note"></div><div class="row" style="align-self:end"><button class="btn" type="submit">Simpan</button></div></form></div>
<div class="card"><h3>Riwayat Stok Awal</h3><table class="table"><thead><tr><th>Produk</th><th>Qty</th><th>Cost</th><th>Tanggal</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="4" style="text-align:center;color:#94a3b8">Belum ada data.</td></tr><?php endif; foreach($rows as $r): $unit=product_unit_fallback($r); ?><tr><td><?php echo e((string)$r['product_name']); ?></td><td><?php echo e(format_qty((float)$r['qty'],$unit['base_unit'])); ?></td><td><?php echo e(format_money((float)($r['unit_cost'] ?? 0))); ?></td><td><?php echo e((string)$r['created_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php kitchen_footer(); ?>
