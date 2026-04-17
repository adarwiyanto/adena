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
$isOwner = inventory_is_owner($u);
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  $approvalNote = trim((string)($_POST['approval_note'] ?? ''));
  try {
    if (!$isOwner) throw new Exception('Hanya owner yang boleh approve/reject opname.');
    $db = db();
    $db->beginTransaction();
    if ($action === 'approve') {
      approve_stock_opname($db, $id, (int)($u['id'] ?? 0), $approvalNote);
    } elseif ($action === 'reject') {
      reject_stock_opname($db, $id, (int)($u['id'] ?? 0), $approvalNote);
    }
    $db->commit();
    redirect(base_url('admin/stock_opname_approval.php'));
  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    $err = $e->getMessage();
  }
}

$status = trim((string)($_GET['status'] ?? 'waiting_approval'));
$params = [];
$sql = "SELECT h.*, b.branch_name, u.name creator_name, ua.name approver_name
  FROM stock_opname_headers h
  JOIN branches b ON b.id=h.branch_id
  LEFT JOIN users u ON u.id=h.created_by
  LEFT JOIN users ua ON ua.id=h.approved_by
  WHERE 1=1";
if ($status !== '' && in_array($status, ['waiting_approval','approved','rejected'], true)) {
  $sql .= " AND h.status=?";
  $params[] = $status;
}
$sql .= " ORDER BY CASE WHEN h.status='waiting_approval' THEN 0 ELSE 1 END, h.id DESC LIMIT 200";
$stmt = db()->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
$customCss = setting('custom_css', '');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Approval Stok Opname</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head>
<body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
<div class="content"><div class="card"><h3>Approval Stok Opname</h3>
<?php if($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
<?php if(!$isOwner): ?><div class="card" style="background:#fff7ed;border-color:#fdba74;color:#9a3412">Mode lihat saja: hanya owner yang dapat approve/reject.</div><?php endif; ?>
<form method="get" class="grid cols-4"><div class="row"><label>Status</label><select name="status"><option value="waiting_approval" <?php echo $status==='waiting_approval'?'selected':''; ?>>Menunggu Approval</option><option value="approved" <?php echo $status==='approved'?'selected':''; ?>>Approved</option><option value="rejected" <?php echo $status==='rejected'?'selected':''; ?>>Rejected</option><option value="" <?php echo $status===''?'selected':''; ?>>Semua</option></select></div><div class="row" style="align-self:end"><button class="btn" type="submit">Filter</button></div></form>
</div>
<div class="card"><table class="table"><thead><tr><th>No Opname</th><th>Tanggal</th><th>Cabang</th><th>Petugas</th><th>Status</th><th>Approval</th><th>Aksi</th></tr></thead><tbody>
<?php if(empty($rows)): ?><tr><td colspan="7" style="text-align:center;color:#94a3b8">Tidak ada data.</td></tr><?php else: foreach($rows as $r): ?>
<tr>
<td><?php echo e((string)$r['opname_no']); ?></td>
<td><?php echo e((string)$r['opname_date']); ?></td>
<td><?php echo e((string)$r['branch_name']); ?></td>
<td><?php echo e((string)($r['creator_name'] ?? '-')); ?></td>
<td><span class="badge"><?php echo e((string)$r['status']); ?></span></td>
<td><?php echo e((string)($r['approver_name'] ?? '-')); ?><br><small><?php echo e((string)($r['approved_at'] ?? '')); ?></small></td>
<td style="display:flex;gap:6px;flex-wrap:wrap">
  <a class="btn btn-light" href="<?php echo e(base_url('admin/stock_opname_form.php?id=' . (int)$r['id'])); ?>">Detail</a>
  <?php if($isOwner && ($r['status'] ?? '') === 'waiting_approval'): ?>
    <form method="post" style="display:flex;gap:6px"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$r['id']); ?>"><input type="text" name="approval_note" placeholder="Catatan approval/reject" required><button class="btn" type="submit" name="action" value="approve">Approve</button><button class="btn danger" type="submit" name="action" value="reject">Reject</button></form>
  <?php endif; ?>
</td>
</tr>
<?php endforeach; endif; ?>
</tbody></table></div>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
