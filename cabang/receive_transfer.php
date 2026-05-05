<?php
require_once __DIR__ . '/../core/branch_portal.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/portal_inventory.php';
require_once __DIR__ . '/_layout.php';
$u = branch_portal_current_user();
$branchId = branch_portal_active_branch_id($u);
$branch = branch_portal_branch($branchId) ?: ['branch_name'=>'Halaman Cabang'];
$locationId = portal_inventory_branch_location_id($branchId);
$err=''; $msg='';
if ($_SERVER['REQUEST_METHOD']==='POST') { csrf_check(); try { portal_inventory_accept_transfer((int)($_POST['transfer_id'] ?? 0),(int)$u['id'],trim((string)($_POST['receiver_notes'] ?? ''))); $msg='Transfer berhasil diterima dan stok cabang bertambah.'; } catch(Throwable $e){ $err=$e->getMessage(); } }
$stmt=db()->prepare("SELECT st.*, fl.location_name from_name, tl.location_name to_name, COUNT(si.id) item_count, COALESCE(SUM(si.qty),0) total_qty FROM stock_transfers st LEFT JOIN stock_locations fl ON fl.id=st.from_location_id LEFT JOIN stock_locations tl ON tl.id=st.to_location_id LEFT JOIN stock_transfer_items si ON si.transfer_id=st.id WHERE st.to_location_id=? GROUP BY st.id ORDER BY st.id DESC LIMIT 80"); $stmt->execute([$locationId]); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$customCss=setting('custom_css','');
cabang_header('Terima Transfer', $branch, $customCss);
?>
<div class="card"><h3>Terima Transfer Cabang</h3><p style="color:#64748b">Hanya transfer tujuan cabang aktif yang muncul di halaman ini.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><table class="table"><thead><tr><th>No</th><th>Asal</th><th>Status</th><th>Item</th><th>Aksi</th></tr></thead><tbody><?php if(empty($rows)): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Belum ada transfer.</td></tr><?php else: foreach($rows as $r): ?><tr><td><?php echo e((string)$r['transfer_no']); ?><br><small><?php echo e((string)$r['created_at']); ?></small></td><td><?php echo e((string)($r['from_name'] ?? '-')); ?></td><td><strong><?php echo e((string)$r['status']); ?></strong></td><td><?php echo e((string)$r['item_count']); ?> item<br><small><?php echo e((string)$r['total_qty']); ?></small></td><td><?php if(($r['status'] ?? '')==='sent'): ?><form method="post" style="display:flex;gap:6px"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="transfer_id" value="<?php echo e((string)$r['id']); ?>"><input name="receiver_notes" placeholder="catatan"><button class="btn" type="submit">Terima</button></form><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
<?php cabang_footer(); ?>
