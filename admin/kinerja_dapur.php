<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/attendance.php';

start_secure_session();
require_admin();

$me = current_user();
if (!in_array((string)($me['role'] ?? ''), ['admin', 'owner', 'superadmin', 'manager_dapur'], true)) {
  http_response_code(403);
  exit('Forbidden');
}

$attendanceToday = attendance_today_for_user((int)($me['id'] ?? 0));
$hasCheckinToday = !empty($attendanceToday['checkin_time']);
$hasCheckoutToday = !empty($attendanceToday['checkout_time']);

ensure_kitchen_kpi_tables();
ensure_employee_attendance_tables();
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'create') {
      $activityName = substr(trim((string)($_POST['activity_name'] ?? '')), 0, 160);
      $pointValue = (int)($_POST['point_value'] ?? 0);
      if ($activityName === '') {
        throw new Exception('Nama kegiatan wajib diisi.');
      }
      if ($pointValue < 0) {
        throw new Exception('Nilai poin tidak boleh negatif.');
      }
      $stmt = db()->prepare('INSERT INTO kitchen_kpi_activities (activity_name, point_value, created_by) VALUES (?,?,?)');
      $stmt->execute([$activityName, $pointValue, (int)($me['id'] ?? 0)]);
      $ok = 'Kegiatan KPI dapur berhasil ditambahkan.';
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $stmt = db()->prepare('DELETE FROM kitchen_kpi_activities WHERE id=?');
        $stmt->execute([$id]);
        $ok = 'Kegiatan KPI dapur berhasil dihapus.';
      }
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$rows = db()->query('SELECT id, activity_name, point_value, created_at FROM kitchen_kpi_activities ORDER BY id DESC')->fetchAll();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kinerja Dapur</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <div class="badge">Kinerja Dapur</div>
      <?php if (!$hasCheckinToday): ?>
        <a class="btn" href="<?php echo e(base_url('pos/absen.php?type=in')); ?>">Absen Masuk</a>
      <?php elseif (!$hasCheckoutToday): ?>
        <a class="btn" href="<?php echo e(base_url('pos/absen.php?type=out')); ?>">Absen Pulang</a>
      <?php else: ?>
        <button class="btn" type="button" disabled>Absen Lengkap</button>
      <?php endif; ?>
    </div>
    <div class="content">
      <div class="grid cols-2">
        <div class="card">
          <h3 style="margin-top:0">Tambah Kegiatan KPI Dapur</h3>
          <?php if ($err): ?><div class="card" style="background:rgba(251,113,133,.12)"><?php echo e($err); ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="card" style="background:rgba(52,211,153,.12)"><?php echo e($ok); ?></div><?php endif; ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="create">
            <div class="row"><label>Nama Kegiatan</label><input name="activity_name" maxlength="160" required></div>
            <div class="row"><label>Nilai Poin</label><input type="number" min="0" name="point_value" required></div>
            <button class="btn" type="submit">Simpan</button>
            <p><small>Poin ini akan menjadi dasar KPI bulanan pegawai dapur.</small></p>
          </form>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Daftar KPI Kinerja Dapur</h3>
          <table class="table">
            <thead><tr><th>Nama Kegiatan</th><th>Poin</th><th>Dibuat</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo e($r['activity_name']); ?></td>
                <td><?php echo e((string)$r['point_value']); ?></td>
                <td><?php echo e($r['created_at']); ?></td>
                <td>
                  <form method="post" data-confirm="Hapus kegiatan ini?" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo e($r['id']); ?>">
                    <button class="btn" type="submit">Hapus</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
