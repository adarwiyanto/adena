<?php
require_once __DIR__ . '/../core/branch_portal.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
$u = require_menu_access('inventori', 'view');
ensure_branch_portal_schema();
$err=''; $msg='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  require_action_access('inventori', 'edit');
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  $note = trim((string)($_POST['approval_note'] ?? ''));
  try {
    throw new Exception('Input stok cabang sekarang langsung masuk stok. Halaman ini hanya untuk audit.');
  } catch (Throwable $e) { $err = $e->getMessage(); }
}
$branchId = (int)($_GET['branch_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? 'pending'));
$params=[];
$sql="SELECT bsi.*, b.branch_name, p.name product_name, p.base_unit, p.product_type, u.name creator_name, ua.name approver_name
FROM branch_stock_inputs bsi
JOIN branches b ON b.id=bsi.branch_id
JOIN products p ON p.id=bsi.product_id
LEFT JOIN users u ON u.id=bsi.created_by
LEFT JOIN users ua ON ua.id=bsi.approved_by
WHERE 1=1";
if ($branchId > 0) { $sql.=" AND bsi.branch_id=?"; $params[]=$branchId; }
if ($status !== '' && in_array($status, ['pending','approved','rejected','cancelled'], true)) { $sql.=" AND bsi.status=?"; $params[]=$status; }
$sql.=" ORDER BY bsi.id DESC LIMIT 300";
$stmt=db()->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
$branches=branch_portal_all_branches(true); $customCss=setting('custom_css','');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Audit Stok Masuk Cabang</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head><body class="desktop-compact"><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content">
<div class="card"><h3>Audit Stok Masuk Cabang</h3><p style="color:#64748b">Input stok dari halaman cabang langsung menambah stok sistem dan dicatat sebagai log audit. Tidak ada approval stok masuk.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?><form method="get" class="grid cols-4"><div class="row"><label>Cabang</label><select name="branch_id"><option value="0">Semua</option><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$branchId?'selected':''; ?>><?php echo e((string)$b['branch_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Status</label><select name="status"><option value="">Semua</option><?php foreach(['pending','approved','rejected','cancelled'] as $s): ?><option value="<?php echo e($s); ?>" <?php echo $status===$s?'selected':''; ?>><?php echo e($s); ?></option><?php endforeach; ?></select></div><div class="row" style="align-self:end"><button class="btn" type="submit">Filter</button></div></form></div>
<div class="card"><table class="table"><thead><tr><th>No</th><th>Cabang</th><th>Produk</th><th>Qty</th><th>Input oleh</th><th>Status</th><th>Catatan</th><th>Aksi</th></tr></thead><tbody><?php if(empty($rows)): ?><tr><td colspan="8" style="text-align:center;color:#94a3b8">Tidak ada data.</td></tr><?php else: foreach($rows as $r): $unit=product_unit_fallback($r); ?><tr><td><?php echo e((string)$r['input_no']); ?><br><small><?php echo e((string)$r['created_at']); ?></small></td><td><?php echo e((string)$r['branch_name']); ?></td><td><?php echo e((string)$r['product_name']); ?></td><td><?php echo e(format_qty((float)$r['qty'],$unit['base_unit'])); ?></td><td><?php echo e((string)($r['creator_name'] ?? '-')); ?></td><td><?php echo e((string)$r['status']); ?></td><td><?php echo e((string)($r['notes'] ?? '')); ?></td><td><?php echo e((string)($r['approval_note'] ?? 'Auto masuk dari halaman cabang')); ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
