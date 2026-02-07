<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../lib/upload_secure.php';

start_secure_session();
require_admin();
ensure_landing_order_tables();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', 'Katalog produk sederhana');
$storeIntro = setting('store_intro', 'Kami adalah usaha yang menghadirkan produk pilihan dengan kualitas terbaik untuk kebutuhan Anda.');
$storeLogo = setting('store_logo', '');
$storePromo = setting('store_promo', '');
$storePromoEnabled = setting('store_promo_enabled', '1') === '1';
$recaptchaSiteKey = setting('recaptcha_site_key', '');
$recaptchaSecretKey = setting('recaptcha_secret_key', '');
$landingOrderEnabled = setting('landing_order_enabled', '1') === '1';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? 'store';

  $name = trim($_POST['store_name'] ?? '');
  $subtitle = trim($_POST['store_subtitle'] ?? '');
  $intro = trim($_POST['store_intro'] ?? '');
  $recaptchaSiteKeyInput = trim($_POST['recaptcha_site_key'] ?? '');
  $recaptchaSecretKeyInput = trim($_POST['recaptcha_secret_key'] ?? '');
  $landingOrderEnabledInput = isset($_POST['landing_order_enabled']) ? '1' : '0';
  $storePromoInput = trim($_POST['store_promo'] ?? '');
  $storePromoEnabledInput = isset($_POST['store_promo_enabled']) ? '1' : '0';
  $removeLogo = isset($_POST['remove_logo']);

  try {
    if ($action === 'store' && $name === '') {
      throw new Exception('Nama toko wajib diisi.');
    }

    $logoPath = $storeLogo;

    if ($action === 'store') {
      if ($removeLogo && $storeLogo) {
        if (upload_is_legacy_path($storeLogo)) {
          $old = __DIR__ . '/../' . $storeLogo;
          if (file_exists($old)) @unlink($old);
        } else {
          upload_secure_delete($storeLogo, 'image');
        }
        $logoPath = '';
      }

      if (!empty($_FILES['store_logo']['name'])) {
        $upload = upload_secure($_FILES['store_logo'], 'image');
        if (empty($upload['ok'])) throw new Exception($upload['error'] ?? 'Upload gagal.');

        if ($storeLogo) {
          if (upload_is_legacy_path($storeLogo)) {
            $old = __DIR__ . '/../' . $storeLogo;
            if (file_exists($old)) @unlink($old);
          } else {
            upload_secure_delete($storeLogo, 'image');
          }
        }

        $logoPath = $upload['name'];
      }

      set_setting('store_name', $name);
      set_setting('store_subtitle', $subtitle);
      set_setting('store_intro', $intro);
      set_setting('store_logo', $logoPath);
      set_setting('store_promo', $storePromoInput);
      set_setting('store_promo_enabled', $storePromoEnabledInput);
    }

    if ($action === 'recaptcha') {
      set_setting('recaptcha_site_key', $recaptchaSiteKeyInput);
      set_setting('recaptcha_secret_key', $recaptchaSecretKeyInput);
    }

    if ($action === 'landing_order') {
      set_setting('landing_order_enabled', $landingOrderEnabledInput);
    }

    redirect(base_url('admin/store.php'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Profil Toko</title>
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
    </div>

    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Profil Toko</h3>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="store">
          <div class="row">
            <label>Nama Toko</label>
            <input name="store_name" value="<?php echo e($_POST['store_name'] ?? $storeName); ?>" required>
          </div>
          <div class="row">
            <label>Subjudul</label>
            <input name="store_subtitle" value="<?php echo e($_POST['store_subtitle'] ?? $storeSubtitle); ?>">
          </div>
          <div class="row">
            <label>Perkenalan Usaha</label>
            <textarea name="store_intro" rows="4"><?php echo e($_POST['store_intro'] ?? $storeIntro); ?></textarea>
          </div>
          <div class="row">
            <label>Konten PROMO</label>
            <textarea name="store_promo" rows="4" placeholder="Contoh: Diskon 20% untuk semua menu sampai akhir bulan"><?php echo e($_POST['store_promo'] ?? $storePromo); ?></textarea>
          </div>
          <div class="row">
            <label class="checkbox-row">
              <input type="checkbox" name="store_promo_enabled" value="1" <?php echo $storePromoEnabled ? 'checked' : ''; ?>>
              Tampilkan kolom PROMO di landing page
            </label>
          </div>
          <div class="row">
            <label>Logo Toko (opsional, max 2MB)</label>
            <input type="file" name="store_logo" accept=".jpg,.jpeg,.png">
            <?php if (!empty($storeLogo)): ?>
              <div style="margin-top:10px;display:flex;align-items:center;gap:12px">
                <img class="thumb" src="<?php echo e(upload_url($storeLogo, 'image')); ?>">
                <label style="display:flex;align-items:center;gap:8px">
                  <input type="checkbox" name="remove_logo" value="1">
                  Hapus logo
                </label>
              </div>
            <?php endif; ?>
          </div>
          <button class="btn" type="submit">Simpan</button>
        </form>
      </div>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Google reCAPTCHA</h3>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="recaptcha">
          <div class="row">
            <label>Site Key</label>
            <input name="recaptcha_site_key" value="<?php echo e($_POST['recaptcha_site_key'] ?? $recaptchaSiteKey); ?>">
          </div>
          <div class="row">
            <label>Secret Key</label>
            <input name="recaptcha_secret_key" value="<?php echo e($_POST['recaptcha_secret_key'] ?? $recaptchaSecretKey); ?>">
          </div>
          <button class="btn" type="submit">Simpan reCAPTCHA</button>
        </form>
        <p><small>Gunakan kunci reCAPTCHA v3 (score-based) terbaru dari Google.</small></p>
      </div>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Pesanan Online Landing Page</h3>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="landing_order">
          <div class="row">
            <label class="checkbox-row">
              <input type="checkbox" name="landing_order_enabled" value="1" <?php echo $landingOrderEnabled ? 'checked' : ''; ?>>
              Aktifkan pesanan online di landing page
            </label>
          </div>
          <button class="btn" type="submit">Simpan</button>
        </form>
        <p><small>Jika dimatikan, keranjang dan checkout tidak tampil di landing page.</small></p>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
