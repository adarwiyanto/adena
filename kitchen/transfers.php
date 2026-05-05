<?php
require_once __DIR__ . '/../core/ops14.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = adena14_area_guard('kitchen');
$fromLocationId = portal_inventory_kitchen_location_id();
$err=''; $msg='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  try {
    $items=[];
    foreach (($_POST['product_id'] ?? []) as $idx=>$pidRaw) {
      $pid=(int)$pidRaw;
      $qtyRaw=($_POST['qty'] ?? [])[$idx] ?? '';
      $qty=trim((string)$qtyRaw) !== '' ? parse_number_input($qtyRaw) : 0;
      $items[]=['product_id'=>$pid,'qty'=>$qty,'unit_cost'=>null,'note'=>trim((string)(($_POST['line_note'] ?? [])[$idx] ?? ''))];
    }
    portal_inventory_create_transfer($fromLocationId, (int)($_POST['to_location_id'] ?? 0), $items, trim((string)($_POST['notes'] ?? '')), (int)$u['id']);
    $msg='Transfer keluar berhasil dibuat dan menunggu penerimaan cabang.';
  } catch(Throwable $e) { $err=$e->getMessage(); }
}
$destinations = portal_inventory_destination_locations($fromLocationId);
$products = portal_inventory_stock_rows($fromLocationId, '', 'finished');
$rows=[];
try {
  $stmt=db()->prepare("SELECT st.*, fl.location_name from_name, tl.location_name to_name, COUNT(si.id) item_count, COALESCE(SUM(si.qty),0) total_qty FROM stock_transfers st LEFT JOIN stock_locations fl ON fl.id=st.from_location_id LEFT JOIN stock_locations tl ON tl.id=st.to_location_id LEFT JOIN stock_transfer_items si ON si.transfer_id=st.id WHERE st.from_location_id=? GROUP BY st.id ORDER BY st.id DESC LIMIT 50");
  $stmt->execute([$fromLocationId]); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e) {}
$customCss = setting('custom_css','');
kitchen_header('Transfer Dapur', $customCss);
?>
<div class="card"><h3>Buat Transfer Keluar</h3><p class="portal-note">Stok langsung dikurangi dari lokasi Dapur saat transfer dibuat, lalu stok cabang bertambah setelah cabang menerima transfer.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>Tujuan Transfer</label><select name="to_location_id" required><?php foreach($destinations as $d): ?><option value="<?php echo e((string)$d['id']); ?>"><?php echo e((string)$d['location_name'].' ('.(string)$d['location_type'].')'); ?></option><?php endforeach; ?></select></div><table class="table" style="margin-top:12px"><thead><tr><th>Produk</th><th>Stok Dapur</th><th>Qty Transfer</th><th>Catatan Item</th></tr></thead><tbody><?php for($i=0;$i<5;$i++): ?><tr><td><select name="product_id[]"><option value="0">-- pilih --</option><?php foreach($products as $p): $unit=product_unit_fallback($p); ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name'].' / stok '.format_qty((float)$p['stock_qty'],$unit['base_unit'])); ?></option><?php endforeach; ?></select></td><td class="muted">lihat dropdown</td><td><input name="qty[]" inputmode="decimal" placeholder="0.00"></td><td><input name="line_note[]" placeholder="opsional"></td></tr><?php endfor; ?></tbody></table><div class="row"><label>Catatan Transfer</label><input name="notes" placeholder="opsional"></div><div style="margin-top:12px"><button class="btn" type="submit">Kirim Transfer</button></div></form></div>
<div class="card"><h3>Riwayat Transfer dari Dapur</h3><table class="table"><thead><tr><th>No</th><th>Tujuan</th><th>Status</th><th>Item</th><th>Waktu</th></tr></thead><tbody><?php if(empty($rows)): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Belum ada transfer.</td></tr><?php else: foreach($rows as $r): ?><tr><td><?php echo e((string)$r['transfer_no']); ?></td><td><?php echo e((string)($r['to_name'] ?? '-')); ?></td><td><strong><?php echo e((string)$r['status']); ?></strong></td><td><?php echo e((string)$r['item_count']); ?> item<br><small><?php echo e((string)$r['total_qty']); ?></small></td><td><?php echo e((string)($r['created_at'] ?? '')); ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
<?php kitchen_footer(); ?>
