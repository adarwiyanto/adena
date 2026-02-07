<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/auth.php';

start_secure_session();

if ((file_exists(__DIR__ . '/install/install.lock') === false && file_exists(__DIR__ . '/install/LOCK') === false)
  && !file_exists(__DIR__ . '/config.php')) {
  redirect('install/index.php');
}

$me = current_user();
if ($me && in_array($me['role'] ?? '', ['admin', 'owner'], true)) {
  redirect(base_url('admin/dashboard.php'));
}
if ($me && !in_array($me['role'] ?? '', ['admin', 'owner'], true)) {
  redirect(base_url('pos/index.php'));
}

$err = '';
if (!empty($_SESSION['flash_error'])) {
  $err = (string) $_SESSION['flash_error'];
  unset($_SESSION['flash_error']);
}
if (login_should_recover()) {
  redirect(base_url('recovery.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $u = trim($_POST['username'] ?? '');
  $p = (string)($_POST['password'] ?? '');
  $rateId = ($u !== '' ? $u : 'guest') . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  if (!rate_limit_check('admin_login', $rateId)) {
    $err = 'Terlalu banyak percobaan login. Silakan coba lagi nanti.';
  } elseif (login_attempt($u, $p)) {
    $me = current_user();
    if (in_array($me['role'] ?? '', ['admin', 'owner'], true)) {
      redirect(base_url('admin/dashboard.php'));
    }
    rate_limit_clear('admin_login', $rateId);
    redirect(base_url('pos/index.php'));
  } else {
    $failedAttempts = login_record_failed_attempt();
    rate_limit_record('admin_login', $rateId);
    if ($failedAttempts >= 3) {
      redirect(base_url('recovery.php'));
    }
    $err = 'Username atau password salah.';
  }
}
$appName = app_config()['app']['name'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login Admin</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    .login-wrap{max-width:420px;margin:8vh auto}
    .center{text-align:center}
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="card">
      <div class="center">
        <h2><?php echo e($appName); ?></h2>
        <p><small>Silakan login admin</small></p>
      </div>
      <?php if ($err): ?>
        <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
      <?php endif; ?>
      <form method="post" class="admin-login">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_generate_token()); ?>">
        <div class="row">
          <label>Username</label>
          <input name="username" autocomplete="username" required>
        </div>
        <div class="row">
          <label>Password</label>
          <input type="password" name="password" autocomplete="current-password" required>
        </div>
        <button class="btn" type="submit" style="width:100%">Masuk</button>
      </form>
      <div class="center" style="margin-top:12px">
        <a href="<?php echo e(base_url('recovery.php')); ?>">Recovery Password</a>
      </div>
    </div>
  </div>
</body>
</html>
