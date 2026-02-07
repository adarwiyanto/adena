<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/attendance.php';

start_secure_session();
require_login();
ensure_employee_roles();
ensure_employee_attendance_tables();

$me = current_user();
$role = (string)($me['role'] ?? '');
if (!is_employee_role($role)) {
  http_response_code(403);
  exit('Forbidden');
}

$type = ($_GET['type'] ?? 'in') === 'out' ? 'out' : 'in';
$today = app_today_jakarta();
$err = '';
$ok = '';

$now = attendance_now();
$todayDate = $now->format('Y-m-d');
$currentTime = $now->format('H:i');

$timeToMinutes = static function (string $time): int {
  [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));
  return ($h * 60) + $m;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $postedType = ($_POST['type'] ?? 'in') === 'out' ? 'out' : 'in';
  $type = $postedType;
  $attendDate = trim((string)($_POST['attend_date'] ?? ''));
  $attendTime = trim((string)($_POST['attend_time'] ?? ''));
  $earlyCheckoutReason = substr(trim((string)($_POST['early_checkout_reason'] ?? '')), 0, 255);
  $deviceInfo = substr(trim((string)($_POST['device_info'] ?? '')), 0, 255);

  try {
    if ($attendDate !== $today) {
      throw new Exception('Tanggal absen harus hari ini.');
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $attendTime)) {
      throw new Exception('Waktu absen tidak valid.');
    }
    if ($attendDate !== $todayDate) {
      throw new Exception('Absensi hanya bisa untuk tanggal hari ini.');
    }
    if (empty($_FILES['attendance_photo']['name'] ?? '')) {
      throw new Exception('Foto wajib dari kamera.');
    }

    $photo = $_FILES['attendance_photo'];
    if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new Exception('Upload foto gagal.');
    }
    if (($photo['size'] ?? 0) <= 0 || ($photo['size'] ?? 0) > 2 * 1024 * 1024) {
      throw new Exception('Ukuran foto maksimal 2MB.');
    }

    $tmpPath = (string)($photo['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
      throw new Exception('File foto tidak valid.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
      throw new Exception('MIME foto tidak valid.');
    }

    $raw = @file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
      throw new Exception('Foto tidak valid.');
    }

    $timeFull = $attendDate . ' ' . $attendTime . ':00';
    $db = db();
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM employee_attendance WHERE user_id=? AND attend_date=? LIMIT 1 FOR UPDATE");
    $stmt->execute([(int)$me['id'], $today]);
    $row = $stmt->fetch();

    if (!$row) {
      $ins = $db->prepare("INSERT INTO employee_attendance (user_id, attend_date) VALUES (?, ?)");
      $ins->execute([(int)$me['id'], $today]);
      $stmt->execute([(int)$me['id'], $today]);
      $row = $stmt->fetch();
    }

    if ($type === 'in' && !empty($row['checkin_time'])) {
      throw new Exception('Absen masuk sudah tercatat.');
    }
    if ($type === 'out' && empty($row['checkin_time'])) {
      throw new Exception('Belum ada absen masuk hari ini.');
    }
    if ($type === 'out' && !empty($row['checkout_time'])) {
      throw new Exception('Absen pulang sudah tercatat.');
    }

    $schedule = getScheduleForDate((int) $me['id'], $today);
    $isOff = !empty($schedule['is_off']);
    $isUnscheduled = $schedule['source'] === 'none';
    $startTime = (string) ($schedule['start_time'] ?? '');
    $endTime = (string) ($schedule['end_time'] ?? '');
    $graceMinutes = max(0, (int) ($schedule['grace_minutes'] ?? 0));
    $allowCheckinBefore = max(0, (int) ($schedule['allow_checkin_before_minutes'] ?? 0));
    $overtimeBeforeLimit = max(0, (int) ($schedule['overtime_before_minutes'] ?? 0));
    $overtimeAfterLimit = max(0, (int) ($schedule['overtime_after_minutes'] ?? 0));

    $checkinStatus = null;
    $checkoutStatus = null;
    $lateMinutes = 0;
    $earlyMinutes = 0;
    $overtimeBefore = 0;
    $overtimeAfter = 0;
    $workMinutes = 0;

    if ($type === 'in') {
      if ($isOff) {
        throw new Exception('Hari ini libur (OFF). Tidak perlu absensi datang.');
      }
      if ($isUnscheduled || $startTime === '' || $endTime === '') {
        throw new Exception('Jadwal belum diatur. Hubungi admin.');
      }

      $checkinMin = $timeToMinutes($attendTime);
      $startMin = $timeToMinutes($startTime);
      $windowStart = $startMin - $allowCheckinBefore;
      $windowEnd = $startMin + $graceMinutes;

      if ($checkinMin < $windowStart) {
        if ($overtimeBeforeLimit > 0) {
          $checkinStatus = 'early';
          $overtimeBefore = min($startMin - $checkinMin, $overtimeBeforeLimit);
        } else {
          $allowedHour = floor($windowStart / 60);
          $allowedMinute = $windowStart % 60;
          throw new Exception(sprintf('Belum masuk window absen. Anda bisa absen mulai %02d:%02d', $allowedHour, $allowedMinute));
        }
      } elseif ($checkinMin > $windowEnd) {
        $checkinStatus = 'late';
        $lateMinutes = $checkinMin - $windowEnd;
      } else {
        $checkinStatus = 'ontime';
      }
    }

    if ($type === 'out') {
      $checkinTs = !empty($row['checkin_time']) ? strtotime((string) $row['checkin_time']) : 0;
      $checkoutTs = strtotime($timeFull);
      if ($checkinTs > 0 && $checkoutTs > $checkinTs) {
        $workMinutes = (int) floor(($checkoutTs - $checkinTs) / 60);
      }

      if ($isOff) {
        $checkinStatus = 'off';
        $checkoutStatus = 'off';
      } elseif ($isUnscheduled || $startTime === '' || $endTime === '') {
        $checkinStatus = 'unscheduled';
        $checkoutStatus = 'unscheduled';
      } else {
        $checkoutMin = $timeToMinutes($attendTime);
        $endMin = $timeToMinutes($endTime);
        if ($checkoutMin < $endMin) {
          $checkoutStatus = 'early_leave';
          $earlyMinutes = $endMin - $checkoutMin;
          if ($earlyCheckoutReason === '') {
            throw new Exception('Alasan pulang lebih awal wajib diisi.');
          }
        } else {
          $checkoutStatus = 'normal';
          $earlyCheckoutReason = '';
          if ($overtimeAfterLimit > 0) {
            $overtimeAfter = min($checkoutMin - $endMin, $overtimeAfterLimit);
          }
        }
      }
    }

    $dir = attendance_upload_dir($today);
    $uniq = bin2hex(random_bytes(5));
    $ext = $mime === 'image/png' ? '.png' : '.jpg';
    $fileName = 'user_' . (int)$me['id'] . '_' . str_replace('-', '', $today) . '_' . $type . '_' . $uniq . $ext;
    $fullPath = $dir . $fileName;
    if (@file_put_contents($fullPath, $raw, LOCK_EX) === false) {
      throw new Exception('Gagal menyimpan foto.');
    }
    @chmod($fullPath, 0640);
    $stored = 'attendance/' . substr($today, 0, 4) . '/' . substr($today, 5, 2) . '/' . $fileName;

    if ($type === 'in') {
      $upd = $db->prepare("UPDATE employee_attendance SET checkin_time=?, checkin_photo_path=?, checkin_device_info=?, checkin_status=?, late_minutes=?, overtime_before_minutes=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$timeFull, $stored, $deviceInfo, $checkinStatus, $lateMinutes, $overtimeBefore, (int)$row['id']]);
    } else {
      $upd = $db->prepare("UPDATE employee_attendance SET checkout_time=?, checkout_photo_path=?, checkout_device_info=?, checkin_status=COALESCE(checkin_status, ?), checkout_status=?, early_minutes=?, overtime_after_minutes=?, work_minutes=?, early_checkout_reason=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$timeFull, $stored, $deviceInfo, $checkinStatus, $checkoutStatus, $earlyMinutes, $overtimeAfter, $workMinutes, $earlyCheckoutReason !== '' ? $earlyCheckoutReason : null, (int)$row['id']]);
    }

    $db->commit();
    if ($type === 'out' && !empty($_GET['logout'])) {
      logout();
      redirect(base_url('index.php'));
    }
    $ok = $type === 'in' ? 'Absen masuk berhasil disimpan.' : 'Absen pulang berhasil disimpan.';
  } catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
      $db->rollBack();
    }
    if (!empty($fullPath) && is_file($fullPath)) {
      @unlink($fullPath);
    }
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Absen <?php echo e(strtoupper($type)); ?></title>
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
</head>
<body>
<div class="container" style="max-width:720px;margin:20px auto">
  <div class="card">
    <h3>Absensi <?php echo $type === 'in' ? 'Masuk' : 'Pulang'; ?></h3>
    <?php if ($err): ?><div class="card" style="background:rgba(251,113,133,.12)"><?php echo e($err); ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="card" style="background:rgba(52,211,153,.12)"><?php echo e($ok); ?></div><?php endif; ?>
    <form method="post" id="absen-form" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
      <input type="hidden" name="type" value="<?php echo e($type); ?>">
      <input type="hidden" name="device_info" id="device_info">
      <div class="row"><label>Tanggal</label><input name="attend_date" value="<?php echo e($today); ?>" readonly></div>
      <div class="row"><label>Waktu</label><input type="time" name="attend_time" value="<?php echo e(app_now_jakarta('H:i')); ?>" required></div>
      <?php if ($type === 'out'): ?>
      <div class="row">
        <label>Alasan pulang lebih awal (wajib jika checkout sebelum jadwal)</label>
        <textarea name="early_checkout_reason" rows="3" maxlength="255"></textarea>
      </div>
      <?php endif; ?>
      <div class="row">
        <label>Foto Absensi</label>
        <input type="file" name="attendance_photo" id="attendance_photo" accept="image/jpeg,image/png" capture="user" required>
        <small>Gunakan kamera HP untuk mengambil foto absensi.</small>
      </div>
      <div id="photo_preview_wrap" style="margin-top:10px;display:none">
        <img id="photo_preview" alt="Preview foto absensi" style="max-width:100%;border-radius:12px">
      </div>
      <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn" type="submit">Simpan</button>
        <a class="btn" href="<?php echo e(base_url('pos/index.php')); ?>">Kembali ke POS</a>
      </div>
    </form>
  </div>
</div>
<script>
  document.getElementById('device_info').value = navigator.userAgent || '';

  const fileInput = document.getElementById('attendance_photo');
  const previewWrap = document.getElementById('photo_preview_wrap');
  const previewImg = document.getElementById('photo_preview');

  fileInput.addEventListener('change', () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) {
      previewImg.src = '';
      previewWrap.style.display = 'none';
      return;
    }
    previewImg.src = URL.createObjectURL(file);
    previewWrap.style.display = 'block';
  });
</script>
</body>
</html>
