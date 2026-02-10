<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_admin();

$me = current_user();
if (!in_array((string)($me['role'] ?? ''), ['admin', 'owner', 'superadmin'], true)) {
  http_response_code(403);
  exit('Forbidden');
}

ensure_work_locations_table();
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'save') {
      $name = substr(trim((string)($_POST['name'] ?? '')), 0, 120);
      $lat = trim((string)($_POST['latitude'] ?? ''));
      $lng = trim((string)($_POST['longitude'] ?? ''));
      $radius = max(20, min(1000, (int)($_POST['radius_meters'] ?? 150)));
      if ($name === '') throw new Exception('Nama lokasi kerja wajib diisi.');
      if (!is_numeric($lat) || !is_numeric($lng)) throw new Exception('Koordinat geotagging tidak valid.');
      $latFloat = (float)$lat;
      $lngFloat = (float)$lng;
      if ($latFloat < -90 || $latFloat > 90 || $lngFloat < -180 || $lngFloat > 180) {
        throw new Exception('Koordinat di luar rentang yang valid.');
      }
      $stmt = db()->prepare('INSERT INTO work_locations (name, latitude, longitude, radius_meters) VALUES (?,?,?,?)');
      $stmt->execute([$name, $latFloat, $lngFloat, $radius]);
      $ok = 'Lokasi kerja berhasil ditambahkan.';
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $stmt = db()->prepare('DELETE FROM work_locations WHERE id=?');
        $stmt->execute([$id]);
        $ok = 'Lokasi kerja berhasil dihapus.';
      }
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$rows = db()->query('SELECT id, name, latitude, longitude, radius_meters, created_at FROM work_locations ORDER BY id DESC')->fetchAll();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Lokasi Kerja</title>
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
      <div class="badge">Lokasi Kerja</div>
    </div>

    <div class="content">
      <div class="grid cols-2">
        <div class="card">
          <h3 style="margin-top:0">Tambah Lokasi Kerja</h3>
          <?php if ($err): ?><div class="card" style="background:rgba(251,113,133,.12)"><?php echo e($err); ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="card" style="background:rgba(52,211,153,.12)"><?php echo e($ok); ?></div><?php endif; ?>

          <form method="post" id="work-location-form">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save">
            <div class="row"><label>Nama Lokasi Kerja</label><input name="name" required></div>
            <div class="row"><label>Latitude</label><input name="latitude" id="latitude" inputmode="decimal" placeholder="Contoh: -6.2000000" required></div>
            <div class="row"><label>Longitude</label><input name="longitude" id="longitude" inputmode="decimal" placeholder="Contoh: 106.8166667" required></div>
            <div class="row"><label>Radius Valid (meter)</label><input type="number" name="radius_meters" value="150" min="20" max="1000"></div>
            <button class="btn" type="button" id="btn-geotag">Ambil Lokasi dari Browser</button>
            <p><small id="geo_status">Belum ada lokasi GPS. Anda juga bisa isi koordinat manual.</small></p>
            <button class="btn" type="submit">Simpan Lokasi</button>
            <p><small>Absensi di luar radius lokasi kerja akan dianggap tidak sah.</small></p>
          </form>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Daftar Lokasi Kerja</h3>
          <table class="table">
            <thead><tr><th>Nama</th><th>Lokasi</th><th>Radius</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo e($r['name']); ?></td>
                <td><?php echo e($r['latitude'] . ', ' . $r['longitude']); ?></td>
                <td><?php echo e((string)$r['radius_meters']); ?>m</td>
                <td>
                  <form method="post" data-confirm="Hapus lokasi kerja ini?" style="display:inline">
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
<script nonce="<?php echo e(csp_nonce()); ?>">
  const geoBtn = document.getElementById('btn-geotag');
  const geoStatus = document.getElementById('geo_status');
  const latInput = document.getElementById('latitude');
  const lngInput = document.getElementById('longitude');

  function validateCoordinateInput() {
    const lat = Number(latInput.value);
    const lng = Number(lngInput.value);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
      geoStatus.textContent = 'Latitude/Longitude harus berupa angka.';
      return false;
    }
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
      geoStatus.textContent = 'Rentang koordinat tidak valid (lat -90..90, long -180..180).';
      return false;
    }
    return true;
  }

  function getLocation(options) {
    return new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, options);
    });
  }

  function geolocationErrorMessage(error) {
    if (!error || typeof error.code === 'undefined') {
      return 'Terjadi kesalahan tidak dikenal saat mengambil lokasi.';
    }
    if (error.code === error.PERMISSION_DENIED) {
      return 'Izin lokasi ditolak. Aktifkan izin lokasi pada browser/perangkat Anda.';
    }
    if (error.code === error.POSITION_UNAVAILABLE) {
      return 'Lokasi tidak tersedia. Pastikan GPS/lokasi perangkat aktif.';
    }
    if (error.code === error.TIMEOUT) {
      return 'Waktu pengambilan lokasi habis. Coba lagi di area dengan sinyal GPS lebih baik.';
    }
    return 'Gagal mengambil lokasi: ' + (error.message || 'unknown error');
  }

  geoBtn.addEventListener('click', async () => {
    console.debug('[admin geo] tombol ambil lokasi diklik');
    geoBtn.disabled = true;
    try {
      if (!navigator.geolocation) {
        geoStatus.textContent = 'Browser tidak mendukung geolocation.';
        return;
      }

      if (!window.isSecureContext) {
        geoStatus.textContent = 'Lokasi butuh HTTPS. Buka halaman lewat https:// agar browser dapat meminta izin lokasi.';
      }

      if (navigator.permissions && navigator.permissions.query) {
        try {
          const permission = await navigator.permissions.query({ name: 'geolocation' });
          if (permission.state === 'denied') {
            geoStatus.textContent = 'Izin lokasi diblokir browser. Aktifkan kembali izin lokasi di pengaturan browser.';
            return;
          }
          if (permission.state === 'prompt') {
            geoStatus.textContent = 'Mengambil lokasi... izinkan akses lokasi saat diminta browser.';
          }
        } catch (permError) {
          console.debug('[admin geo] permissions API tidak tersedia penuh:', permError);
        }
      } else {
        geoStatus.textContent = 'Mengambil lokasi...';
      }

      const primaryOptions = { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 };
      try {
        const position = await getLocation(primaryOptions);
        latInput.value = position.coords.latitude.toFixed(7);
        lngInput.value = position.coords.longitude.toFixed(7);
        geoStatus.textContent = `Lokasi didapat: ${latInput.value}, ${lngInput.value} (akurasi ±${Math.round(position.coords.accuracy || 0)}m)`;
      } catch (primaryError) {
        if (primaryError && primaryError.code === primaryError.TIMEOUT) {
          geoStatus.textContent = 'Lokasi timeout. Mencoba ulang dengan mode hemat GPS...';
          const fallbackOptions = { enableHighAccuracy: false, timeout: 15000, maximumAge: 30000 };
          const fallbackPosition = await getLocation(fallbackOptions);
          latInput.value = fallbackPosition.coords.latitude.toFixed(7);
          lngInput.value = fallbackPosition.coords.longitude.toFixed(7);
          geoStatus.textContent = `Lokasi didapat: ${latInput.value}, ${lngInput.value} (akurasi ±${Math.round(fallbackPosition.coords.accuracy || 0)}m)`;
        } else {
          throw primaryError;
        }
      }
    } catch (error) {
      console.error('[admin geo] gagal mengambil lokasi', error);
      geoStatus.textContent = geolocationErrorMessage(error);
    } finally {
      geoBtn.disabled = false;
    }
  });

  document.getElementById('work-location-form').addEventListener('submit', (event) => {
    if (!validateCoordinateInput()) {
      event.preventDefault();
      return;
    }
    geoStatus.textContent = 'Koordinat siap disimpan.';
  });
</script>
</body>
</html>
