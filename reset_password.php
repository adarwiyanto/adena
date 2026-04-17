<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/csrf.php';

start_secure_session();
ensure_password_resets_table();

$err = '';
$ok = '';
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

function find_reset(string $token): ?array {
  if ($token === '') return null;
  $tokenHash = hash('sha256', $token);
  $stmt = db()->prepare("SELECT * FROM password_resets WHERE token_hash=? LIMIT 1");
  $stmt->execute([$tokenHash]);
  $row = $stmt->fetch();
  if (!$row) return null;
  if (!empty($row['used_at'])) return null;
  if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) return null;
  return $row;
}

$reset = find_reset($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    if (!$reset) {
      throw new Exception('Token recovery tidak valid atau sudah kedaluwarsa.');
    }
    $pass1 = (string)($_POST['new_password'] ?? '');
    $pass2 = (string)($_POST['confirm_password'] ?? '');
    if ($pass1 === '' || $pass2 === '') {
      throw new Exception('Password baru wajib diisi.');
    }
    if ($pass1 !== $pass2) {
      throw new Exception('Konfirmasi password baru tidak sama.');
    }
    $hash = password_hash($pass1, PASSWORD_DEFAULT);
    $stmt = db()->prepare("UPDATE users SET password_hash=? WHERE id=?");
    $stmt->execute([$hash, (int)$reset['user_id']]);
    $stmt = db()->prepare("UPDATE password_resets SET used_at=NOW() WHERE id=?");
    $stmt->execute([(int)$reset['id']]);
    $ok = 'Password berhasil diperbarui. Silakan login kembali.';
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
  <title>Reset Password</title>
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
        <p><small>Atur ulang password</small></p>
      </div>
      <?php if ($err): ?>
        <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
      <?php endif; ?>
      <?php if ($ok): ?>
        <div class="card" style="border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)"><?php echo e($ok); ?></div>
        <div class="center"><a href="<?php echo e(base_url('adm.php')); ?>">Login</a></div>
      <?php endif; ?>

      <?php if ($reset && !$ok): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="token" value="<?php echo e($token); ?>">
          <div class="row">
            <label>Password Baru</label>
            <input type="password" name="new_password" autocomplete="new-password" required>
          </div>
          <div class="row">
            <label>Ulangi Password Baru</label>
            <input type="password" name="confirm_password" autocomplete="new-password" required>
          </div>
          <button class="btn" type="submit" style="width:100%">Simpan Password</button>
        </form>
      <?php elseif (!$ok): ?>
        <p>Token recovery tidak valid atau sudah kedaluwarsa.</p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
