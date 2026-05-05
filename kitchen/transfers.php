<?php
require_once __DIR__ . '/../core/ops14.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = adena14_area_guard('kitchen');
$customCss = setting('custom_css','');
$err=''; $msg=''; $locationId=1; $products=[]; $destinations=[]; $rows=[];
try {
  $locationId=portal_inventory_kitchen_location_id();
  $products=portal_inventory_stock_rows($locationId,'','all');
  $products=array_values(array_filter($products, static fn($p) => (float)($p['stock_qty'] ?? 0) > 0));
  $destinations=portal_inventory_destination_locations($locationId);
} catch(Throwable $e){ $err=$e->getMessage(); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    $items=[];
    foreach(($_POST['product_id'] ?? []) as $i=>$pid){
      $items[]=['product_id'=>(int)$pid,'qty'=>parse_number_input(($_POST['qty'] ?? [])[$i] ?? 0),'unit_cost'=>null,'note'=>trim((string)(($_POST['note'] ?? [])[$i] ?? ''))];
    }
    portal_inventory_create_transfer($locationId,(int)($_POST['to_location_id'] ?? 0),$items,trim((string)($_POST['notes'] ?? '')),(int)$u['id']);
    $msg='Transfer berhasil dibuat dan menunggu diterima cabang/tujuan.';
    $products=portal_inventory_stock_rows($locationId,'','all');
    $products=array_values(array_filter($products, static fn($p) => (float)($p['stock_qty'] ?? 0) > 0));
  } catch(Throwable $e){ $err=$e->getMessage(); }
}
try {
  $stmt=db()->prepare("SELECT st.id,st.transfer_no,st.status,st.created_at,st.notes,tl.location_name to_name,
      (SELECT COUNT(*) FROM stock_transfer_items si WHERE si.transfer_id=st.id) item_count,
      (SELECT COALESCE(SUM(si.qty),0) FROM stock_transfer_items si WHERE si.transfer_id=st.id) total_qty
    FROM stock_transfers st
    LEFT JOIN stock_locations tl ON tl.id=st.to_location_id
    WHERE st.from_location_id=?
    ORDER BY st.id DESC LIMIT 80");
  $stmt->execute([$locationId]);
  $rows=$stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e){ if(!$err) $err=$e->getMessage(); }
kitchen_header('Transfer Dapur', $customCss);
?>
<div class="card"><h3>Transfer Stok dari Dapur</h3><p class="portal-note">Transfer memakai from_location_id dapur dan to_location_id tujuan. Stok keluar dicatat saat transfer dibuat; stok masuk dicatat saat cabang menerima.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="grid cols-3"><div class="row"><label>Tujuan</label><select name="to_location_id" required><option value="0">-- pilih tujuan --</option><?php foreach($destinations as $d): ?><option value="<?php echo e((string)$d['id']); ?>"><?php echo e((string)$d['location_name'].' ['.(string)$d['location_type'].']'); ?></option><?php endforeach; ?></select></div><div class="row wide"><label>Catatan transfer</label><input name="notes"></div></div><table class="table" style="margin-top:12px"><thead><tr><th>Produk</th><th>Stok Saat Ini</th><th>Qty Transfer</th><th>Catatan Item</th></tr></thead><tbody><?php for($i=0;$i<8;$i++): ?><tr><td><select name="product_id[]"><option value="0">-- pilih --</option><?php foreach($products as $p): $unit=product_unit_fallback($p); ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e((string)$p['name'].' | stok '.format_qty((float)$p['stock_qty'],$unit['base_unit'])); ?></option><?php endforeach; ?></select></td><td><small>lihat pilihan produk</small></td><td><input name="qty[]" inputmode="decimal" placeholder="0.00"></td><td><input name="note[]"></td></tr><?php endfor; ?></tbody></table><button class="btn" type="submit">Kirim Transfer</button></form></div>
<div class="card"><h3>Riwayat Transfer Keluar</h3><table class="table"><thead><tr><th>No</th><th>Tujuan</th><th>Status</th><th>Item</th><th>Tanggal</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Belum ada transfer.</td></tr><?php endif; foreach($rows as $r): ?><tr><td><?php echo e((string)$r['transfer_no']); ?></td><td><?php echo e((string)($r['to_name'] ?? '-')); ?></td><td><strong><?php echo e((string)$r['status']); ?></strong></td><td><?php echo e((string)$r['item_count']); ?> item<br><small><?php echo e((string)$r['total_qty']); ?></small></td><td><?php echo e((string)$r['created_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php kitchen_footer(); ?>
