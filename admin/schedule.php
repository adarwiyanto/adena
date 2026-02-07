<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/attendance.php';

start_secure_session();
require_schedule_or_attendance_admin();
ensure_employee_roles();
ensure_employee_attendance_tables();

$err = '';
$ok = '';
$employeeId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  try {
    if ($employeeId <= 0) {
      throw new Exception('Pilih pegawai.');
    }

    if ($action === 'save_weekly') {
      $db = db();
      for ($i = 1; $i <= 7; $i++) {
        $start = $_POST['start_time'][$i] ?? null;
        $end = $_POST['end_time'][$i] ?? null;
        $grace = max(0, (int) ($_POST['grace_minutes'][$i] ?? 0));
        $allowCheckinBefore = max(0, (int) ($_POST['allow_checkin_before_minutes'][$i] ?? 0));
        $overtimeBefore = max(0, (int) ($_POST['overtime_before_minutes'][$i] ?? 0));
        $overtimeAfter = max(0, (int) ($_POST['overtime_after_minutes'][$i] ?? 0));
        $off = !empty($_POST['is_off'][$i]) ? 1 : 0;

        $stmt = $db->prepare("INSERT INTO employee_schedule_weekly (user_id, weekday, start_time, end_time, grace_minutes, allow_checkin_before_minutes, overtime_before_minutes, overtime_after_minutes, is_off) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time), grace_minutes=VALUES(grace_minutes), allow_checkin_before_minutes=VALUES(allow_checkin_before_minutes), overtime_before_minutes=VALUES(overtime_before_minutes), overtime_after_minutes=VALUES(overtime_after_minutes), is_off=VALUES(is_off), updated_at=NOW()");
        $stmt->execute([$employeeId, $i, $start ?: null, $end ?: null, $grace, $allowCheckinBefore, $overtimeBefore, $overtimeAfter, $off]);
      }
      $ok = 'Jadwal mingguan disimpan.';
    } elseif ($action === 'save_override') {
      $date = trim((string) ($_POST['schedule_date'] ?? ''));
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Tanggal override tidak valid.');
      }

      $start = $_POST['start_time'] ?? null;
      $end = $_POST['end_time'] ?? null;
      $grace = max(0, (int) ($_POST['grace_minutes'] ?? 0));
      $allowCheckinBefore = max(0, (int) ($_POST['allow_checkin_before_minutes'] ?? 0));
      $overtimeBefore = max(0, (int) ($_POST['overtime_before_minutes'] ?? 0));
      $overtimeAfter = max(0, (int) ($_POST['overtime_after_minutes'] ?? 0));
      $off = !empty($_POST['is_off']) ? 1 : 0;
      $note = substr(trim((string) ($_POST['note'] ?? '')), 0, 255);

      $stmt = db()->prepare("INSERT INTO employee_schedule_overrides (user_id, schedule_date, start_time, end_time, grace_minutes, allow_checkin_before_minutes, overtime_before_minutes, overtime_after_minutes, is_off, note) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), end_time=VALUES(end_time), grace_minutes=VALUES(grace_minutes), allow_checkin_before_minutes=VALUES(allow_checkin_before_minutes), overtime_before_minutes=VALUES(overtime_before_minutes), overtime_after_minutes=VALUES(overtime_after_minutes), is_off=VALUES(is_off), note=VALUES(note), updated_at=NOW()");
      $stmt->execute([$employeeId, $date, $start ?: null, $end ?: null, $grace, $allowCheckinBefore, $overtimeBefore, $overtimeAfter, $off, $note]);
      $ok = 'Override disimpan.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$employees = db()->query("SELECT id,name,role FROM users WHERE role IN ('pegawai','pegawai_pos','pegawai_non_pos','manager_toko') ORDER BY name")->fetchAll();
$weekly = [];
$overrides = [];
if ($employeeId > 0) {
  $stmt = db()->prepare('SELECT * FROM employee_schedule_weekly WHERE user_id=?');
  $stmt->execute([$employeeId]);
  foreach ($stmt->fetchAll() as $r) {
    $weekly[(int) $r['weekday']] = $r;
  }

  $monthStart = date('Y-m-01');
  $monthEnd = date('Y-m-t');
  $stmt = db()->prepare('SELECT * FROM employee_schedule_overrides WHERE user_id=? AND schedule_date BETWEEN ? AND ? ORDER BY schedule_date');
  $stmt->execute([$employeeId, $monthStart, $monthEnd]);
  $overrides = $stmt->fetchAll();
}

$days = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];
$customCss = setting('custom_css', '');

ob_start();
require_once __DIR__ . '/partials_sidebar.php';
$sidebarHtml = ob_get_clean();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Jadwal Pegawai</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php echo $sidebarHtml; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <div class="title">Jadwal Pegawai</div>
    </div>

    <div class="content">
      <div class="card">
        <h3>Jadwal Kerja Pegawai</h3>
        <?php if ($err): ?><div class="card" style="background:rgba(251,113,133,.12)"><?php echo e($err); ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="card" style="background:rgba(52,211,153,.12)"><?php echo e($ok); ?></div><?php endif; ?>
        <form method="get" action="">
          <div class="row">
            <label>Pegawai</label>
            <select name="user_id" required>
              <option value="">- pilih -</option>
              <?php foreach ($employees as $u): ?>
                <option value="<?php echo e((string) $u['id']); ?>" <?php echo $employeeId === (int) $u['id'] ? 'selected' : ''; ?>><?php echo e($u['name'] . ' (' . $u['role'] . ')'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn" type="submit">Tampilkan</button>
        </form>
      </div>

      <?php if ($employeeId > 0): ?>
        <div class="card">
          <h4>Jadwal Mingguan</h4>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_weekly">
            <input type="hidden" name="user_id" value="<?php echo e((string) $employeeId); ?>">
            <table class="table">
              <thead><tr><th>Hari</th><th>Jam Masuk</th><th>Jam Pulang</th><th>Grace</th><th>Window Absen Datang</th><th>Lembur Sebelum Masuk</th><th>Lembur Sesudah Pulang</th><th>OFF/Libur</th></tr></thead>
              <tbody>
              <?php foreach ($days as $i => $nm): $r = $weekly[$i] ?? []; ?>
                <tr>
                  <td><?php echo e($nm); ?></td>
                  <td><input type="time" name="start_time[<?php echo $i; ?>]" value="<?php echo e((string) ($r['start_time'] ?? '')); ?>"></td>
                  <td><input type="time" name="end_time[<?php echo $i; ?>]" value="<?php echo e((string) ($r['end_time'] ?? '')); ?>"></td>
                  <td><input type="number" min="0" name="grace_minutes[<?php echo $i; ?>]" value="<?php echo e((string) ($r['grace_minutes'] ?? 0)); ?>"></td>
                  <td><input type="number" min="0" name="allow_checkin_before_minutes[<?php echo $i; ?>]" value="<?php echo e((string) ($r['allow_checkin_before_minutes'] ?? 0)); ?>"></td>
                  <td><input type="number" min="0" name="overtime_before_minutes[<?php echo $i; ?>]" value="<?php echo e((string) ($r['overtime_before_minutes'] ?? 0)); ?>"></td>
                  <td><input type="number" min="0" name="overtime_after_minutes[<?php echo $i; ?>]" value="<?php echo e((string) ($r['overtime_after_minutes'] ?? 0)); ?>"></td>
                  <td><input type="checkbox" name="is_off[<?php echo $i; ?>]" value="1" <?php echo !empty($r['is_off']) ? 'checked' : ''; ?>></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <button class="btn" type="submit">Simpan Mingguan</button>
          </form>
        </div>

        <div class="card">
          <h4>Override Bulanan</h4>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_override">
            <input type="hidden" name="user_id" value="<?php echo e((string) $employeeId); ?>">
            <div class="grid cols-2">
              <div class="row"><label>Tanggal</label><input type="date" name="schedule_date" required></div>
              <div class="row"><label>Grace</label><input type="number" min="0" name="grace_minutes" value="0"></div>
              <div class="row"><label>Window Absen Datang (mnt)</label><input type="number" min="0" name="allow_checkin_before_minutes" value="0"></div>
              <div class="row"><label>Lembur Sebelum Masuk (mnt)</label><input type="number" min="0" name="overtime_before_minutes" value="0"></div>
              <div class="row"><label>Lembur Sesudah Pulang (mnt)</label><input type="number" min="0" name="overtime_after_minutes" value="0"></div>
              <div class="row"><label>Masuk</label><input type="time" name="start_time"></div>
              <div class="row"><label>Pulang</label><input type="time" name="end_time"></div>
              <div class="row"><label>Catatan</label><input name="note"></div>
              <div class="row"><label><input type="checkbox" name="is_off" value="1"> Libur</label></div>
            </div>
            <button class="btn" type="submit">Simpan Override</button>
          </form>

          <table class="table">
            <thead><tr><th>Tanggal</th><th>Masuk</th><th>Pulang</th><th>Grace</th><th>Window</th><th>Lembur Sebelum</th><th>Lembur Sesudah</th><th>Libur</th><th>Catatan</th></tr></thead>
            <tbody>
            <?php foreach ($overrides as $o): ?>
              <tr>
                <td><?php echo e($o['schedule_date']); ?></td>
                <td><?php echo e((string) $o['start_time']); ?></td>
                <td><?php echo e((string) $o['end_time']); ?></td>
                <td><?php echo e((string) $o['grace_minutes']); ?></td>
                <td><?php echo e((string) ($o['allow_checkin_before_minutes'] ?? 0)); ?></td>
                <td><?php echo e((string) ($o['overtime_before_minutes'] ?? 0)); ?></td>
                <td><?php echo e((string) ($o['overtime_after_minutes'] ?? 0)); ?></td>
                <td><?php echo !empty($o['is_off']) ? 'Ya' : 'Tidak'; ?></td>
                <td><?php echo e((string) $o['note']); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
