<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_admin();
ensure_kitchen_kpi_tables();

$me = current_user();
$role = (string)($me['role'] ?? '');
if (!in_array($role, ['owner','admin','manager_dapur'], true)) { http_response_code(403); exit('Forbidden'); }

$err='';$ok='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'set_target') {
      $userId=(int)($_POST['user_id'] ?? 0); $activityId=(int)($_POST['activity_id'] ?? 0); $date=trim((string)($_POST['target_date'] ?? app_today_jakarta())); $qty=max(0,(int)($_POST['target_qty'] ?? 0));
      if ($userId<=0 || $activityId<=0) throw new Exception('Data target tidak valid.');
      $stmt=db()->prepare('INSERT INTO kitchen_kpi_targets (user_id,activity_id,target_date,target_qty,created_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE target_qty=VALUES(target_qty), created_by=VALUES(created_by), updated_at=NOW()');
      $stmt->execute([$userId,$activityId,$date,$qty,(int)($me['id']??0)]);
      $ok='Target KPI dapur disimpan.';
    }
    if ($action === 'approve_realization') {
      $approvalId = (int)($_POST['approval_id'] ?? 0);
      if ($approvalId <= 0) throw new Exception('Data persetujuan tidak valid.');
      $stmt = db()->prepare("UPDATE kitchen_kpi_realization_approvals SET approved_at=NOW() WHERE id=? AND approver_user_id=?");
      $stmt->execute([$approvalId, (int)($me['id'] ?? 0)]);
      if ($stmt->rowCount() > 0) {
        $ok = 'Realisasi berhasil disetujui.';
      } else {
        throw new Exception('Persetujuan tidak ditemukan atau bukan jatah Anda.');
      }
    }
  } catch (Throwable $e) { $err=$e->getMessage(); }
}

$filter = (string)($_GET['filter'] ?? 'hari_ini');
$today = new DateTimeImmutable(app_today_jakarta(), new DateTimeZone('Asia/Jakarta'));
$start = $today->format('Y-m-d'); $end = $today->format('Y-m-d');
if ($filter==='bulan_ini') { $start=$today->modify('first day of this month')->format('Y-m-d'); $end=$today->modify('last day of this month')->format('Y-m-d'); }
if ($filter==='bulan_lalu') { $start=$today->modify('first day of last month')->format('Y-m-d'); $end=$today->modify('last day of last month')->format('Y-m-d'); }
if ($filter==='custom') { $start=(string)($_GET['start_date'] ?? $start); $end=(string)($_GET['end_date'] ?? $end); }
if ($filter==='perbulan') { $start=$today->modify('first day of this month')->format('Y-m-01'); $end=$today->modify('last day of this month')->format('Y-m-d'); }

$employees = db()->query("SELECT id,name FROM users WHERE role='pegawai_dapur' ORDER BY name ASC")->fetchAll();
$activities = db()->query("SELECT id,activity_name FROM kitchen_kpi_activities ORDER BY activity_name ASC")->fetchAll();

$baseQuery = "SELECT r.id AS realization_id,
    u.name,
    a.activity_name,
    t.target_date AS period_date,
    t.target_qty,
    COALESCE(r.qty,0) AS realized_qty,
    COUNT(ap.id) AS approver_total,
    SUM(CASE WHEN ap.approved_at IS NOT NULL THEN 1 ELSE 0 END) AS approver_approved
  FROM kitchen_kpi_targets t
  JOIN users u ON u.id=t.user_id
  JOIN kitchen_kpi_activities a ON a.id=t.activity_id
  LEFT JOIN kitchen_kpi_realizations r ON r.user_id=t.user_id AND r.activity_id=t.activity_id AND r.realization_date=t.target_date
  LEFT JOIN kitchen_kpi_realization_approvals ap ON ap.realization_id=r.id
  WHERE t.target_date BETWEEN ? AND ?";
if (in_array($role, ['owner','admin'], true)) {
  $baseQuery .= " AND r.id IS NOT NULL";
}
$baseQuery .= " GROUP BY r.id, u.name, a.activity_name, t.target_date, t.target_qty, r.qty
  ORDER BY t.target_date DESC, u.name ASC, a.activity_name ASC";

$stmt = db()->prepare($baseQuery);
$stmt->execute([$start,$end]);
$rows=$stmt->fetchAll();

$pendingApprovals = [];
if ($role === 'manager_dapur') {
  $stmtPending = db()->prepare("SELECT ap.id, u.name, a.activity_name, r.realization_date, r.qty
    FROM kitchen_kpi_realization_approvals ap
    JOIN kitchen_kpi_realizations r ON r.id=ap.realization_id
    JOIN users u ON u.id=r.user_id
    JOIN kitchen_kpi_activities a ON a.id=r.activity_id
    WHERE ap.approver_user_id=? AND ap.approved_at IS NULL
    ORDER BY r.realization_date DESC, u.name ASC, a.activity_name ASC");
  $stmtPending->execute([(int)($me['id'] ?? 0)]);
  $pendingApprovals = $stmtPending->fetchAll();
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rekapan KPI Pegawai Dapur</title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"></head>
<body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">Rekapan KPI pegawai dapur</div></div><div class="content">
<?php if($err):?><div class="card" style="background:rgba(251,113,133,.12)"><?php echo e($err); ?></div><?php endif; ?><?php if($ok):?><div class="card" style="background:rgba(52,211,153,.12)"><?php echo e($ok); ?></div><?php endif; ?>
<div class="card"><form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end"><div class="row"><label>Filter</label><select name="filter"><option value="hari_ini" <?php echo $filter==='hari_ini'?'selected':''; ?>>Perhari (Hari ini)</option><option value="perbulan" <?php echo $filter==='perbulan'?'selected':''; ?>>Perbulan</option><option value="bulan_ini" <?php echo $filter==='bulan_ini'?'selected':''; ?>>Bulan ini</option><option value="bulan_lalu" <?php echo $filter==='bulan_lalu'?'selected':''; ?>>Bulan lalu</option><option value="custom" <?php echo $filter==='custom'?'selected':''; ?>>Custom</option></select></div><div class="row"><label>Start</label><input type="date" name="start_date" value="<?php echo e($start); ?>"></div><div class="row"><label>End</label><input type="date" name="end_date" value="<?php echo e($end); ?>"></div><button class="btn" type="submit">Terapkan</button></form></div>
<?php if ($role !== 'manager_dapur'): ?>
<div class="grid cols-2"><div class="card"><h3>Atur Target</h3><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="set_target"><div class="row"><label>Pegawai Dapur</label><select name="user_id"><?php foreach($employees as $e):?><option value="<?php echo e($e['id']); ?>"><?php echo e($e['name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Kegiatan</label><select name="activity_id"><?php foreach($activities as $a):?><option value="<?php echo e($a['id']); ?>"><?php echo e($a['activity_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Tanggal</label><input type="date" name="target_date" value="<?php echo e(app_today_jakarta()); ?>"></div><div class="row"><label>Target Qty</label><input type="number" min="0" name="target_qty" required></div><button class="btn" type="submit">Simpan Target</button></form></div></div>
<?php endif; ?>
<?php if ($role === 'manager_dapur'): ?>
<div class="card"><h3>Persetujuan Realisasi (Manager Dapur)</h3><table class="table"><thead><tr><th>Tanggal</th><th>Pegawai</th><th>Kegiatan</th><th>Qty</th><th>Aksi</th></tr></thead><tbody><?php if(!$pendingApprovals): ?><tr><td colspan="5">Tidak ada realisasi yang menunggu persetujuan Anda.</td></tr><?php else: foreach($pendingApprovals as $p): ?><tr><td><?php echo e($p['realization_date']); ?></td><td><?php echo e($p['name']); ?></td><td><?php echo e($p['activity_name']); ?></td><td><?php echo e((string)$p['qty']); ?></td><td><form method="post" style="margin:0"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="approve_realization"><input type="hidden" name="approval_id" value="<?php echo e((string)$p['id']); ?>"><button class="btn" type="submit">Setujui</button></form></td></tr><?php endforeach; endif; ?></tbody></table></div>
<?php endif; ?>
<div class="card"><table class="table"><thead><tr><th>Tanggal</th><th>Pegawai</th><th>Kegiatan</th><th>Target</th><th>Realisasi</th><th>Status Persetujuan</th></tr></thead><tbody><?php foreach($rows as $r): $approved=(int)$r['approver_approved']; $total=(int)$r['approver_total']; $isApproved=$total>0 && $approved>=$total; if (in_array($role, ['owner','admin'], true) && !$isApproved) { continue; } ?><tr><td><?php echo e($r['period_date']); ?></td><td><?php echo e($r['name']); ?></td><td><?php echo e($r['activity_name']); ?></td><td><?php echo e((string)$r['target_qty']); ?></td><td><?php echo e((string)$r['realized_qty']); ?></td><td><?php if ((int)$r['realization_id'] <= 0): ?>Belum diinput<?php else: ?><?php echo e((string)$approved . '/' . (string)$total); ?><?php echo $isApproved ? ' (Disetujui semua)' : ' (Menunggu persetujuan)'; ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
