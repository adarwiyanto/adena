<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
$u = inventory_require_stock_role();
ensure_inventory_module_schema();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  try {
    $db = db();
    $db->beginTransaction();
    if ($action === 'submit') {
      submit_stock_opname($db, $id);
    } elseif ($action === 'cancel') {
      cancel_stock_opname($db, $id);
    }
    $db->commit();
    redirect(base_url('admin/stock_opname.php'));
  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $err = $e->getMessage();
  }
}

$branchId = (int)($_GET['branch_id'] ?? active_branch_id());
$status = trim((string)($_GET['status'] ?? ''));
$branches = inventory_branches();
$params = [$branchId];
$sql = "SELECT h.*, b.branch_name, u.name creator_name,
  (SELECT COUNT(*) FROM stock_opname_items soi WHERE soi.opname_id=h.id) AS total_items,
  (SELECT COUNT(*) FROM stock_opname_items soi WHERE soi.opname_id=h.id AND ABS(soi.variance_qty) > 0.00001) AS total_variance_items
  FROM stock_opname_headers h
  JOIN branches b ON b.id=h.branch_id
  LEFT JOIN users u ON u.id=h.created_by
  WHERE h.branch_id=?";
if ($status !== '' && in_array($status, ['draft','waiting_approval','approved','rejected','cancelled'], true)) {
  $sql .= " AND h.status=?";
  $params[] = $status;
}
$sql .= " ORDER BY h.id DESC LIMIT 200";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
$customCss = setting('custom_css', '');

function opname_status_badge(string $status): string {
  $map = [
    'draft' => 'background:#f8fafc;border-color:#cbd5e1;color:#475569;',
    'waiting_approval' => 'background:#fff7ed;border-color:#fed7aa;color:#9a3412;',
    'approved' => 'background:#f0fdf4;border-color:#bbf7d0;color:#166534;',
    'rejected' => 'background:#fff1f2;border-color:#fecdd3;color:#9f1239;',
    'cancelled' => 'background:#f1f5f9;border-color:#cbd5e1;color:#475569;',
  ];
  return $map[$status] ?? '';
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Stok Opname</title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?>
<div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
<div class="content">
<div class="card"><h3>Stok Opname</h3>
<?php if($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
<form method="get" class="grid cols-4">
  <div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===$branchId?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div>
  <div class="row"><label>Status</label><select name="status"><option value="">Semua</option><option value="draft" <?php echo $status==='draft'?'selected':''; ?>>Draft</option><option value="waiting_approval" <?php echo $status==='waiting_approval'?'selected':''; ?>>Menunggu Approval</option><option value="approved" <?php echo $status==='approved'?'selected':''; ?>>Approved</option><option value="rejected" <?php echo $status==='rejected'?'selected':''; ?>>Rejected</option><option value="cancelled" <?php echo $status==='cancelled'?'selected':''; ?>>Cancelled</option></select></div>
  <div class="row" style="align-self:end"><button class="btn" type="submit">Filter</button></div>
  <div class="row" style="align-self:end"><a class="btn" href="<?php echo e(base_url('admin/stock_opname_form.php?branch_id=' . $branchId)); ?>">Buat Draft Opname</a></div>
</form>
</div>
<div class="card"><table class="table"><thead><tr><th>No Opname</th><th>Tanggal</th><th>Cabang</th><th>Petugas</th><th>Ringkasan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php if(empty($rows)): ?><tr><td colspan="7" style="text-align:center;color:#94a3b8">Belum ada data.</td></tr><?php else: foreach($rows as $r): ?>
<tr>
  <td><?php echo e((string)$r['opname_no']); ?></td>
  <td><?php echo e((string)$r['opname_date']); ?></td>
  <td><?php echo e((string)$r['branch_name']); ?></td>
  <td><?php echo e((string)($r['creator_name'] ?? '-')); ?></td>
  <td><?php echo e((string)((int)($r['total_items'] ?? 0))); ?> item / selisih <?php echo e((string)((int)($r['total_variance_items'] ?? 0))); ?></td>
  <td><span class="badge" style="<?php echo opname_status_badge((string)$r['status']); ?>"><?php echo e((string)$r['status']); ?></span></td>
  <td style="display:flex;gap:6px;flex-wrap:wrap">
    <a class="btn btn-light" href="<?php echo e(base_url('admin/stock_opname_form.php?id=' . (int)$r['id'])); ?>">Detail</a>
    <?php if(($r['status'] ?? '') === 'draft'): ?>
      <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="id" value="<?php echo e((string)$r['id']); ?>"><button class="btn" type="submit">Submit</button></form>
    <?php endif; ?>
    <?php if(in_array(($r['status'] ?? ''), ['draft','waiting_approval'], true)): ?>
      <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e((string)$r['id']); ?>"><button class="btn danger" type="submit">Cancel</button></form>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</div></div></div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body></html>
