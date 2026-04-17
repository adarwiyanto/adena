<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/email.php';

start_secure_session();
ensure_user_profile_columns();
ensure_password_resets_table();

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    if ($username === '' || $email === '') {
      throw new Exception('Username dan email wajib diisi.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      throw new Exception('Format email tidak valid.');
    }

    $stmt = db()->prepare("SELECT id, name FROM users WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
      throw new Exception('Username tidak ditemukan.');
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    $stmt = db()->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?,?,?)");
    $stmt->execute([(int)$user['id'], $tokenHash, $expiresAt]);

    $link = base_url('reset_password.php?token=' . urlencode($token));
    $subject = 'Reset Password Akun Hope Noodles';
    $body = "<p>Halo " . e($user['name'] ?? 'User') . ",</p>"
      . "<p>Kami menerima permintaan reset password untuk akun Anda.</p>"
      . "<p>Silakan klik link berikut untuk mengganti password:</p>"
      . "<p><a href=\"{$link}\">{$link}</a></p>"
      . "<p>Link berlaku selama 1 jam. Jika Anda tidak meminta reset password, abaikan email ini.</p>";

    if (!send_email_smtp($email, $subject, $body)) {
      throw new Exception('Gagal mengirim email recovery.');
    }

    $ok = 'Link recovery berhasil dikirim. Silakan cek email Anda.';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$appName = app_config()['app']['name'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Recovery Password</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    .login-wrap{max-width:440px;margin:8vh auto}
    .center{text-align:center}
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="card">
      <div class="center">
        <h2><?php echo e($appName); ?></h2>
        <p><small>Recovery password via email</small></p>
      </div>
      <?php if ($err): ?>
        <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
      <?php endif; ?>
      <?php if ($ok): ?>
        <div class="card" style="border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)"><?php echo e($ok); ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <div class="row">
          <label>Username</label>
          <input name="username" required>
        </div>
        <div class="row">
          <label>Email</label>
          <input type="email" name="email" required>
        </div>
        <button class="btn" type="submit" style="width:100%">Kirim Link Recovery</button>
      </form>
      <div class="center" style="margin-top:12px">
        <a href="<?php echo e(base_url('adm.php')); ?>">Kembali ke Login</a>
      </div>
    </div>
  </div>
</body>
</html>
