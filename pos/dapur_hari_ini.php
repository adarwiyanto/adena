<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/attendance.php';

start_secure_session();
require_login();
ensure_kitchen_kpi_tables();
ensure_company_announcements_table();
ensure_employee_attendance_tables();

$me = current_user();
$role = (string)($me['role'] ?? '');
if ($role !== 'pegawai_dapur') {
  redirect(base_url('pos/index.php'));
}

$attendanceToday = attendance_today_for_user((int)($me['id'] ?? 0));
$hasCheckinToday = !empty($attendanceToday['checkin_time']);
$hasCheckoutToday = !empty($attendanceToday['checkout_time']);

$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'set_realization') {
      $activityId = (int)($_POST['activity_id'] ?? 0);
      $date = trim((string)($_POST['realization_date'] ?? app_today_jakarta()));
      $qty = max(0, (int)($_POST['qty'] ?? 0));
      if ($activityId <= 0) {
        throw new Exception('Kegiatan tidak valid.');
      }
      $stmt = db()->prepare('INSERT INTO kitchen_kpi_realizations (user_id,activity_id,realization_date,qty,created_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE qty=VALUES(qty), created_by=VALUES(created_by), updated_at=NOW()');
      $stmt->execute([(int)$me['id'], $activityId, $date, $qty, (int)$me['id']]);

      $realizationId = (int)db()->lastInsertId();
      if ($realizationId <= 0) {
        $find = db()->prepare('SELECT id FROM kitchen_kpi_realizations WHERE user_id=? AND activity_id=? AND realization_date=? LIMIT 1');
        $find->execute([(int)$me['id'], $activityId, $date]);
        $realizationId = (int)($find->fetchColumn() ?: 0);
      }
      if ($realizationId > 0) {
        kitchen_kpi_sync_realization_approvals($realizationId, (int)$me['id']);
      }
      $ok = 'Realisasi berhasil disimpan. Menunggu persetujuan pegawai dapur lain dan manager dapur.';
    }

    if ($action === 'approve_realization') {
      $approvalId = (int)($_POST['approval_id'] ?? 0);
      if ($approvalId <= 0) throw new Exception('Data persetujuan tidak valid.');
      $stmt = db()->prepare('UPDATE kitchen_kpi_realization_approvals SET approved_at=NOW() WHERE id=? AND approver_user_id=?');
      $stmt->execute([$approvalId, (int)$me['id']]);
      if ($stmt->rowCount() > 0) {
        $ok = 'Realisasi rekan Anda berhasil disetujui.';
      } else {
        throw new Exception('Persetujuan tidak ditemukan atau bukan jatah Anda.');
      }
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$today = app_today_jakarta();
$stmt = db()->prepare("SELECT a.id AS activity_id, a.activity_name, t.target_qty, COALESCE(r.qty,0) AS realized_qty,
    r.id AS realization_id,
    COUNT(ap.id) AS approver_total,
    SUM(CASE WHEN ap.approved_at IS NOT NULL THEN 1 ELSE 0 END) AS approver_approved
  FROM kitchen_kpi_targets t
  JOIN kitchen_kpi_activities a ON a.id=t.activity_id
  LEFT JOIN kitchen_kpi_realizations r ON r.user_id=t.user_id AND r.activity_id=t.activity_id AND r.realization_date=t.target_date
  LEFT JOIN kitchen_kpi_realization_approvals ap ON ap.realization_id=r.id
  WHERE t.user_id=? AND t.target_date=?
  GROUP BY a.id, a.activity_name, t.target_qty, r.id, r.qty
  ORDER BY a.activity_name ASC");
$stmt->execute([(int)$me['id'], $today]);
$rows = $stmt->fetchAll();

$pendingApprovalsStmt = db()->prepare("SELECT ap.id, u.name, a.activity_name, r.realization_date, r.qty
  FROM kitchen_kpi_realization_approvals ap
  JOIN kitchen_kpi_realizations r ON r.id=ap.realization_id
  JOIN users u ON u.id=r.user_id
  JOIN kitchen_kpi_activities a ON a.id=r.activity_id
  WHERE ap.approver_user_id=? AND ap.approved_at IS NULL
  ORDER BY r.realization_date DESC, u.name ASC, a.activity_name ASC");
$pendingApprovalsStmt->execute([(int)$me['id']]);
$pendingApprovals = $pendingApprovalsStmt->fetchAll();

$activitiesStmt = db()->query("SELECT id,activity_name FROM kitchen_kpi_activities ORDER BY activity_name ASC");
$activities = $activitiesStmt->fetchAll();

$announcement = latest_active_announcement('dapur');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pekerjaan Dapur Hari Ini</title>
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
</head>
<body>
<div class="container" style="max-width:980px;margin:20px auto">
  <div class="card">
    <h3>Pekerjaan Dapur Hari Ini</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
      <?php if (!$hasCheckinToday): ?>
        <a class="btn" href="<?php echo e(base_url('pos/absen.php?type=in')); ?>">Absen Masuk</a>
      <?php elseif (!$hasCheckoutToday): ?>
        <a class="btn" href="<?php echo e(base_url('pos/absen.php?type=out')); ?>">Absen Pulang</a>
      <?php else: ?>
        <button class="btn" type="button" disabled>Absen Lengkap</button>
      <?php endif; ?>
    </div>
    <?php if ($err): ?><div class="card" style="background:rgba(251,113,133,.12)"><?php echo e($err); ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="card" style="background:rgba(52,211,153,.12)"><?php echo e($ok); ?></div><?php endif; ?>
    <?php if ($announcement): ?>
      <div class="card" style="margin-bottom:12px;background:rgba(96,165,250,.10);border-color:rgba(96,165,250,.35)">
        <strong><?php echo e((string)$announcement['title']); ?></strong>
        <p style="white-space:pre-line;margin:6px 0"><?php echo e((string)$announcement['message']); ?></p>
        <small>Dari: <?php echo e((string)($announcement['posted_by_name'] ?? 'Admin')); ?></small>
      </div>
    <?php endif; ?>

    <div class="grid cols-2">
      <div class="card">
        <h3>Input Realisasi Sendiri</h3>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="set_realization">
          <div class="row"><label>Kegiatan</label><select name="activity_id"><?php foreach($activities as $a): ?><option value="<?php echo e($a['id']); ?>"><?php echo e($a['activity_name']); ?></option><?php endforeach; ?></select></div>
          <div class="row"><label>Tanggal</label><input type="date" name="realization_date" value="<?php echo e($today); ?>"></div>
          <div class="row"><label>Qty Realisasi</label><input type="number" min="0" name="qty" required></div>
          <button class="btn" type="submit">Simpan Realisasi</button>
        </form>
      </div>
      <div class="card">
        <h3>Persetujuan Rekan Dapur</h3>
        <table class="table">
          <thead><tr><th>Tanggal</th><th>Pegawai</th><th>Kegiatan</th><th>Qty</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php if (!$pendingApprovals): ?>
            <tr><td colspan="5">Tidak ada realisasi yang menunggu persetujuan Anda.</td></tr>
          <?php else: foreach($pendingApprovals as $p): ?>
            <tr>
              <td><?php echo e($p['realization_date']); ?></td>
              <td><?php echo e($p['name']); ?></td>
              <td><?php echo e($p['activity_name']); ?></td>
              <td><?php echo e((string)$p['qty']); ?></td>
              <td>
                <form method="post" style="margin:0">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="approve_realization">
                  <input type="hidden" name="approval_id" value="<?php echo e((string)$p['id']); ?>">
                  <button class="btn" type="submit">Setujui</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <table class="table">
      <thead><tr><th>Kegiatan</th><th>Target</th><th>Realisasi</th><th>Status Persetujuan</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4">Belum ada target KPI dapur hari ini.</td></tr>
      <?php else: foreach ($rows as $r): $approved=(int)$r['approver_approved']; $total=(int)$r['approver_total']; ?>
        <tr>
          <td><?php echo e($r['activity_name']); ?></td>
          <td><?php echo e((string)$r['target_qty']); ?></td>
          <td><?php echo e((string)$r['realized_qty']); ?></td>
          <td>
            <?php if ((int)$r['realization_id'] <= 0): ?>Belum diinput<?php else: ?>
              <?php echo e((string)$approved . '/' . (string)$total); ?>
              <?php echo ($total > 0 && $approved >= $total) ? ' (Disetujui semua)' : ' (Menunggu persetujuan)'; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <div style="margin-top:10px"><a class="btn" href="<?php echo e(base_url('admin/logout.php')); ?>">Logout</a></div>
  </div>
</div>
</body>
</html>
