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
ensure_user_profile_columns();
ensure_work_locations_table();
clean_old_attendance_photos(90);

$me = current_user();
$role = (string)($me['role'] ?? '');
if (!is_employee_role($role)) {
  http_response_code(403);
  exit('Forbidden');
}

$geoSettingStmt = db()->prepare("SELECT attendance_geotagging_enabled FROM users WHERE id=? LIMIT 1");
$geoSettingStmt->execute([(int)($me['id'] ?? 0)]);
$geoSettingRow = $geoSettingStmt->fetch();
$geotaggingEnabled = !isset($geoSettingRow['attendance_geotagging_enabled']) || (int)$geoSettingRow['attendance_geotagging_enabled'] === 1;

$type = ($_GET['type'] ?? 'in') === 'out' ? 'out' : 'in';
$today = app_today_jakarta();
$err = '';
$ok = '';

$now = attendance_now();
$todayDate = $now->format('Y-m-d');
$currentTime = $now->format('H:i');


$backUrl = base_url('pos/index.php');
$backLabel = 'Kembali ke POS';
if (in_array($role, ['pegawai_dapur', 'manager_dapur'], true)) {
  $backUrl = base_url('pos/dapur_hari_ini.php');
  $backLabel = 'Kembali ke JOB Hari Ini';
}

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
  $geoLat = $geotaggingEnabled ? trim((string)($_POST['geo_latitude'] ?? '')) : '';
  $geoLng = $geotaggingEnabled ? trim((string)($_POST['geo_longitude'] ?? '')) : '';
  $geoAccuracy = $geotaggingEnabled ? trim((string)($_POST['geo_accuracy'] ?? '')) : '';

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
    $matchedLocation = null;
    if ($geotaggingEnabled) {
      if (!is_numeric($geoLat) || !is_numeric($geoLng)) {
        throw new Exception('Lokasi GPS wajib diambil dari browser sebelum absen.');
      }
      if ($geoAccuracy !== '' && !is_numeric($geoAccuracy)) {
        $geoAccuracy = '';
      }
      if ($geoAccuracy !== '') {
        $deviceInfo = substr(trim($deviceInfo . ' | acc:' . number_format((float)$geoAccuracy, 2, '.', '') . 'm'), 0, 255);
      }

      $matchedLocation = find_matching_work_location((float)$geoLat, (float)$geoLng);
      if (!$matchedLocation) {
        throw new Exception('Lokasi absensi tidak sah. Anda harus berada di toko atau dapur yang terdaftar.');
      }
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
      $upd = $db->prepare("UPDATE employee_attendance SET checkin_time=?, checkin_photo_path=?, checkin_device_info=?, checkin_latitude=?, checkin_longitude=?, checkin_location_name=?, checkin_status=?, late_minutes=?, overtime_before_minutes=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$timeFull, $stored, $deviceInfo, $geotaggingEnabled ? (float)$geoLat : null, $geotaggingEnabled ? (float)$geoLng : null, $geotaggingEnabled && $matchedLocation ? (string)$matchedLocation['name'] : null, $checkinStatus, $lateMinutes, $overtimeBefore, (int)$row['id']]);
    } else {
      $upd = $db->prepare("UPDATE employee_attendance SET checkout_time=?, checkout_photo_path=?, checkout_device_info=?, checkout_latitude=?, checkout_longitude=?, checkout_location_name=?, checkin_status=COALESCE(checkin_status, ?), checkout_status=?, early_minutes=?, overtime_after_minutes=?, work_minutes=?, early_checkout_reason=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$timeFull, $stored, $deviceInfo, $geotaggingEnabled ? (float)$geoLat : null, $geotaggingEnabled ? (float)$geoLng : null, $geotaggingEnabled && $matchedLocation ? (string)$matchedLocation['name'] : null, $checkinStatus, $checkoutStatus, $earlyMinutes, $overtimeAfter, $workMinutes, $earlyCheckoutReason !== '' ? $earlyCheckoutReason : null, (int)$row['id']]);
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
      <input type="hidden" name="geo_latitude" id="geo_latitude">
      <input type="hidden" name="geo_longitude" id="geo_longitude">
      <input type="hidden" name="geo_accuracy" id="geo_accuracy">
      <div class="row"><label>Tanggal</label><input name="attend_date" value="<?php echo e($today); ?>" readonly></div>
      <div class="row"><label>Waktu</label><input type="time" name="attend_time" value="<?php echo e(app_now_jakarta('H:i')); ?>" required></div>
      <?php if ($type === 'out'): ?>
      <div class="row">
        <label>Alasan pulang lebih awal (wajib jika checkout sebelum jadwal)</label>
        <textarea name="early_checkout_reason" rows="3" maxlength="255"></textarea>
      </div>
      <?php endif; ?>
      <?php if ($geotaggingEnabled): ?>
      <div class="row">
        <label>Geotagging Lokasi</label>
        <button class="btn" type="button" id="btn-geo">Ambil Lokasi Saya</button>
        <small id="geo_status">Belum ada lokasi GPS.</small>
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
        <a class="btn" href="<?php echo e($backUrl); ?>"><?php echo e($backLabel); ?></a>
      </div>
    </form>
  </div>
</div>
<script nonce="<?php echo e(csp_nonce()); ?>">
  document.getElementById('device_info').value = navigator.userAgent || '';

  const fileInput = document.getElementById('attendance_photo');
  const previewWrap = document.getElementById('photo_preview_wrap');
  const previewImg = document.getElementById('photo_preview');


  const geoEnabled = <?php echo $geotaggingEnabled ? 'true' : 'false'; ?>;
  const geoLat = document.getElementById('geo_latitude');
  const geoLng = document.getElementById('geo_longitude');
  const geoAccuracy = document.getElementById('geo_accuracy');
  const geoStatus = document.getElementById('geo_status');
  const geoBtn = document.getElementById('btn-geo');
  const form = document.getElementById('absen-form');
  const submitBtn = form.querySelector('button[type="submit"]');

  let isRequestingLocation = false;

  function geolocationErrorMessage(error, insecureContext = false) {
    if (insecureContext) {
      return 'Geotag butuh HTTPS. Buka halaman lewat https:// agar browser dapat meminta izin lokasi.';
    }
    if (!error || typeof error.code === 'undefined') {
      return 'Gagal mengambil lokasi.';
    }
    if (error.code === error.PERMISSION_DENIED) {
      return 'Izin lokasi ditolak. Aktifkan izin lokasi untuk browser dan coba lagi.';
    }
    if (error.code === error.POSITION_UNAVAILABLE) {
      return 'Lokasi tidak tersedia. Nyalakan GPS dan coba lagi.';
    }
    if (error.code === error.TIMEOUT) {
      return 'Timeout mengambil lokasi. Coba ulang (pastikan sinyal GPS bagus).';
    }
    return 'Gagal mengambil lokasi.';
  }

  function getCurrentPosition(options) {
    return new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, options);
    });
  }

  function getWatchPosition(timeoutMs = 10000) {
    return new Promise((resolve, reject) => {
      const watchId = navigator.geolocation.watchPosition(
        (position) => {
          navigator.geolocation.clearWatch(watchId);
          clearTimeout(timer);
          resolve(position);
        },
        (error) => {
          navigator.geolocation.clearWatch(watchId);
          clearTimeout(timer);
          reject(error);
        },
        { enableHighAccuracy: true, maximumAge: 0, timeout: timeoutMs }
      );

      const timer = window.setTimeout(() => {
        navigator.geolocation.clearWatch(watchId);
        reject({
          code: 3,
          message: 'watchPosition timeout',
          TIMEOUT: 3,
          PERMISSION_DENIED: 1,
          POSITION_UNAVAILABLE: 2,
        });
      }, timeoutMs);
    });
  }

  function clearLocationFields() {
    geoLat.value = '';
    geoLng.value = '';
    geoAccuracy.value = '';
  }

  function fillLocationFields(position) {
    geoLat.value = position.coords.latitude.toFixed(7);
    geoLng.value = position.coords.longitude.toFixed(7);
    geoAccuracy.value = Number(position.coords.accuracy || 0).toFixed(2);
    geoStatus.textContent = `Lokasi didapat: ${geoLat.value}, ${geoLng.value} (akurasi ±${Math.round(position.coords.accuracy || 0)}m)`;
  }

  async function requestLocation() {
    clearLocationFields();

    if (!navigator.geolocation) {
      throw new Error('Geolocation API not supported');
    }

    if (!window.isSecureContext) {
      const insecureError = new Error('Insecure context');
      insecureError.code = -1;
      insecureError.insecureContext = true;
      throw insecureError;
    }

    if (navigator.permissions && navigator.permissions.query) {
      let permission = null;
      try {
        permission = await navigator.permissions.query({ name: 'geolocation' });
      } catch (permError) {
        console.warn('[geo] permissions API check failed:', permError && permError.message ? permError.message : permError);
      }
      if (permission) {
        console.warn('[geo] permissions state:', permission.state);
        if (permission.state === 'denied') {
          const deniedError = new Error('Permission denied by Permissions API');
          deniedError.code = 1;
          deniedError.PERMISSION_DENIED = 1;
          throw deniedError;
        }
      }
    }

    try {
      return await getCurrentPosition({ enableHighAccuracy: false, timeout: 12000, maximumAge: 0 });
    } catch (firstError) {
      console.warn('[geo] first getCurrentPosition failed:', firstError && firstError.code, firstError && firstError.message);
      if (!firstError || (firstError.code !== firstError.TIMEOUT && firstError.code !== firstError.POSITION_UNAVAILABLE)) {
        throw firstError;
      }
    }

    try {
      return await getCurrentPosition({ enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    } catch (secondError) {
      console.warn('[geo] second getCurrentPosition failed:', secondError && secondError.code, secondError && secondError.message);
      if (!secondError || (secondError.code !== secondError.TIMEOUT && secondError.code !== secondError.POSITION_UNAVAILABLE)) {
        throw secondError;
      }
    }

    console.warn('[geo] fallback to watchPosition (max 10s)');
    return getWatchPosition(10000);
  }

  async function runRequestLocationFlow() {
    if (isRequestingLocation) {
      return false;
    }

    isRequestingLocation = true;
    geoBtn.disabled = true;
    if (submitBtn) {
      submitBtn.disabled = true;
    }
    geoStatus.textContent = 'Mengambil lokasi...';

    try {
      const position = await requestLocation();
      fillLocationFields(position);
      geoBtn.textContent = 'Ambil Ulang / Coba Lagi';
      return true;
    } catch (error) {
      clearLocationFields();
      const message = geolocationErrorMessage(error, !!(error && error.insecureContext));
      geoStatus.textContent = message + ' Klik "Coba Lagi".';
      geoBtn.textContent = 'Coba Lagi';
      console.warn('[geo] gagal mengambil lokasi:', {
        code: error && typeof error.code !== 'undefined' ? error.code : null,
        message: error && error.message ? error.message : 'unknown',
        userAgent: navigator.userAgent || '',
      });
      return false;
    } finally {
      isRequestingLocation = false;
      geoBtn.disabled = false;
      if (submitBtn) {
        submitBtn.disabled = false;
      }
    }
  }

  if (geoEnabled && geoBtn) {
    // Geolocation di Android (termasuk Vivo) harus dipicu oleh user gesture (klik tombol).
    geoBtn.addEventListener('click', () => {
      runRequestLocationFlow();
    });

    form.addEventListener('submit', async (e) => {
      if (geoLat.value && geoLng.value) {
        return;
      }

      e.preventDefault();
      const success = await runRequestLocationFlow();
      if (success) {
        form.requestSubmit(submitBtn || undefined);
        return;
      }

      alert('Lokasi GPS belum didapat. Klik tombol "Coba Lagi" untuk mengambil lokasi sebelum submit absen.');
    });
  }

  async function compressImageToMax2MB(file) {
    if (!file || file.size <= 2 * 1024 * 1024) {
      return file;
    }
    const bitmap = await createImageBitmap(file);
    const canvas = document.createElement('canvas');
    const maxWidth = 1600;
    const scale = Math.min(1, maxWidth / bitmap.width);
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const ctx = canvas.getContext('2d');
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

    let quality = 0.9;
    let blob = null;
    while (quality >= 0.45) {
      blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
      if (blob && blob.size <= 2 * 1024 * 1024) {
        break;
      }
      quality -= 0.1;
    }
    if (!blob || blob.size > 2 * 1024 * 1024) {
      throw new Error('Foto tidak bisa dikompres <= 2MB. Ambil ulang foto dengan resolusi lebih rendah.');
    }

    const compressed = new File([blob], (file.name || 'attendance') + '.jpg', { type: 'image/jpeg' });
    const dt = new DataTransfer();
    dt.items.add(compressed);
    fileInput.files = dt.files;
    return compressed;
  }

  fileInput.addEventListener('change', async () => {
    try {
      let file = fileInput.files && fileInput.files[0];
      if (!file) {
        previewImg.src = '';
        previewWrap.style.display = 'none';
        return;
      }

      file = await compressImageToMax2MB(file);
      previewImg.src = URL.createObjectURL(file);
      previewWrap.style.display = 'block';
    } catch (error) {
      alert(error.message || 'Gagal kompres foto.');
      fileInput.value = '';
      previewImg.src = '';
      previewWrap.style.display = 'none';
    }
  });
</script>
</body>
</html>
