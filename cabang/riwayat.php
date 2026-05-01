<?php
require_once __DIR__ . '/../core/branch_portal.php';
require_once __DIR__ . '/_layout.php';
$u = branch_portal_current_user();
$branchId = branch_portal_active_branch_id($u);
$branch = branch_portal_branch($branchId) ?: ['branch_name'=>'Halaman Cabang'];
$customCss=setting('custom_css','');
$stmt=db()->prepare("SELECT bsi.*, p.name product_name, p.base_unit, p.product_type, ua.name approver_name FROM branch_stock_inputs bsi JOIN products p ON p.id=bsi.product_id LEFT JOIN users ua ON ua.id=bsi.approved_by WHERE bsi.branch_id=? ORDER BY bsi.id DESC LIMIT 100"); $stmt->execute([$branchId]); $inputs=$stmt->fetchAll();
$stmt=db()->prepare("SELECT h.*, u.name creator_name, ua.name approver_name, (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id=h.id) total_items FROM stock_opname_headers h LEFT JOIN users u ON u.id=h.created_by LEFT JOIN users ua ON ua.id=h.approved_by WHERE h.branch_id=? ORDER BY h.id DESC LIMIT 100"); $stmt->execute([$branchId]); $opnames=$stmt->fetchAll();
cabang_header('Riwayat Cabang', $branch, $customCss);
?>
<div class="card"><h3>Riwayat Stok Masuk</h3><table class="table"><thead><tr><th>No</th><th>Produk</th><th>Qty</th><th>Status</th><th>Catatan</th><th>Approval</th></tr></thead><tbody><?php if(empty($inputs)): ?><tr><td colspan="6" style="text-align:center;color:#94a3b8">Belum ada data.</td></tr><?php else: foreach($inputs as $r): $unit=product_unit_fallback($r); ?><tr><td><?php echo e((string)$r['input_no']); ?><br><small><?php echo e((string)$r['created_at']); ?></small></td><td><?php echo e((string)$r['product_name']); ?></td><td><?php echo e(format_qty((float)$r['qty'],$unit['base_unit'])); ?></td><td><?php echo e((string)$r['status']); ?></td><td><?php echo e((string)($r['notes'] ?? '')); ?></td><td><?php echo e((string)($r['approval_note'] ?? '-')); ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
<div class="card"><h3>Riwayat Stock Opname</h3><table class="table"><thead><tr><th>No Opname</th><th>Tanggal</th><th>Item</th><th>Status</th><th>Approval</th></tr></thead><tbody><?php if(empty($opnames)): ?><tr><td colspan="5" style="text-align:center;color:#94a3b8">Belum ada data.</td></tr><?php else: foreach($opnames as $r): ?><tr><td><?php echo e((string)$r['opname_no']); ?><br><small><?php echo e((string)$r['created_at']); ?></small></td><td><?php echo e((string)$r['opname_date']); ?></td><td><?php echo e((string)($r['total_items'] ?? 0)); ?></td><td><?php echo e((string)$r['status']); ?></td><td><?php echo e((string)($r['approval_note'] ?? '-')); ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
<?php cabang_footer(); ?>
