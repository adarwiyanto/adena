<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/rbac.php';

start_secure_session();

if ((file_exists(__DIR__ . '/install/install.lock') === false && file_exists(__DIR__ . '/install/LOCK') === false)
  && !file_exists(__DIR__ . '/config.php')) {
  redirect('install/index.php');
}

$isAndroidApp = is_android_app_request();
$me = current_user();
ensure_rbac_schema();
if ($me && $isAndroidApp) {
  redirect(base_url('pos/index.php'));
}
if ($me) {
  redirect(resolve_default_landing_page_for_user($me));
}

$err = '';
if (login_should_recover()) {
  redirect(base_url('recovery.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $u = trim($_POST['username'] ?? '');
  $p = (string)($_POST['password'] ?? '');
  $remember = isset($_POST['remember_me']) && $_POST['remember_me'] === '1';
  $rateId = ($u !== '' ? $u : 'guest') . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  if (!rate_limit_check('admin_login', $rateId)) {
    $err = 'Terlalu banyak percobaan login. Silakan coba lagi nanti.';
  } elseif (login_attempt($u, $p, $remember)) {
    $me = current_user();
    rate_limit_clear('admin_login', $rateId);
    if ($isAndroidApp) {
      redirect(base_url('pos/index.php'));
    }
    redirect(resolve_default_landing_page_for_user($me));
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
    .remember-row{display:flex;align-items:flex-start;gap:9px;margin:12px 0 6px}
    .remember-row input{width:auto;margin-top:3px}
    .remember-warning{display:none;margin:8px 0 14px;padding:10px 12px;border:1px solid #f59e0b;border-radius:8px;background:rgba(245,158,11,.12);color:#92400e;font-size:.85rem;line-height:1.4}
    .remember-warning.show{display:block}
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
        <label class="remember-row">
          <input id="remember-me" type="checkbox" name="remember_me" value="1">
          <span>Remember me</span>
        </label>
        <div id="remember-warning" class="remember-warning" role="alert">
          Gunakan fitur ini hanya pada komputer pribadi. Jangan aktifkan pada komputer bersama atau perangkat umum karena akun dapat dibuka tanpa memasukkan username dan password.
        </div>
        <button class="btn" type="submit" style="width:100%">Masuk</button>
      </form>
      <div class="center" style="margin-top:12px">
        <a href="<?php echo e(base_url('recovery.php')); ?>">Recovery Password</a>
      </div>
    </div>
  </div>
  <script nonce="<?php echo e(csp_nonce()); ?>">
    (function () {
      var checkbox = document.getElementById('remember-me');
      var warning = document.getElementById('remember-warning');
      if (!checkbox || !warning) return;
      checkbox.addEventListener('change', function () {
        warning.classList.toggle('show', checkbox.checked);
      });
    })();
  </script>
</body>
</html>
