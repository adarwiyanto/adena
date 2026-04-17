<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';

start_secure_session();
require_login();
ensure_user_profile_columns();

$me = current_user();
$userId = (int)($me['id'] ?? 0);
$stmt = db()->prepare("SELECT id, name, role, password_hash FROM users WHERE id=? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
  logout();
  redirect(base_url('adm.php'));
}

$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    $old = (string)($_POST['old_password'] ?? '');
    $new1 = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['confirm_password'] ?? '');
    if ($old === '' || $new1 === '' || $new2 === '') {
      throw new Exception('Semua field wajib diisi.');
    }
    if (!password_verify($old, $user['password_hash'])) {
      throw new Exception('Password lama tidak sesuai.');
    }
    if ($new1 !== $new2) {
      throw new Exception('Konfirmasi password baru tidak sama.');
    }
    $hash = password_hash($new1, PASSWORD_DEFAULT);
    $stmt = db()->prepare("UPDATE users SET password_hash=? WHERE id=?");
    $stmt->execute([$hash, $userId]);
    $ok = 'Password berhasil diperbarui.';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$customCss = setting('custom_css', '');
$isPegawai = ($user['role'] ?? '') === 'pegawai';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ubah Password</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
  <div class="container">
    <?php if (!$isPegawai): ?>
      <?php include __DIR__ . '/admin/partials_sidebar.php'; ?>
    <?php endif; ?>
    <div class="main">
      <div class="topbar">
        <?php if (!$isPegawai): ?>
          <button class="btn" data-toggle-sidebar type="button">Menu</button>
        <?php endif; ?>
        <div class="title">Ubah Password</div>
        <div class="spacer"></div>
        <?php if ($isPegawai): ?>
          <a class="btn" href="<?php echo e(base_url('pos/index.php')); ?>">Kembali ke POS</a>
        <?php endif; ?>
      </div>
      <div class="content">
        <div class="card" style="max-width:640px">
          <h3 style="margin-top:0">Ganti Password</h3>
          <?php if ($err): ?>
            <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
          <?php endif; ?>
          <?php if ($ok): ?>
            <div class="card" style="border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)"><?php echo e($ok); ?></div>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <div class="row">
              <label>Password Lama</label>
              <input type="password" name="old_password" autocomplete="current-password" required>
            </div>
            <div class="row">
              <label>Password Baru</label>
              <input type="password" name="new_password" autocomplete="new-password" required>
            </div>
            <div class="row">
              <label>Ulangi Password Baru</label>
              <input type="password" name="confirm_password" autocomplete="new-password" required>
            </div>
            <button class="btn" type="submit">Simpan Password</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
