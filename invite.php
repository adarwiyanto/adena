<?php
if (!ob_get_level()) ob_start();
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/csrf.php';
start_secure_session();

ensure_owner_role();
ensure_user_invites_table();
ensure_user_profile_columns();

$err = '';
$ok = '';
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$cfg = app_config();
$csrfDebugEnabled = (($_GET['debug_csrf'] ?? '') === '1')
  && (($cfg['security']['csrf_debug'] ?? false) === true);
if ($csrfDebugEnabled) {
  $mask = function (?string $value): string {
    if (!$value) return '';
    return substr($value, 0, 8) . '...';
  };
  header('Content-Type: text/plain; charset=UTF-8');
  echo "session_id: " . session_id() . "\n";
  echo "http_host: " . ($_SERVER['HTTP_HOST'] ?? '') . "\n";
  echo "csrf_session: " . $mask($_SESSION['_csrf'] ?? '') . "\n";
  echo "csrf_post: " . $mask($_POST['_csrf'] ?? '') . "\n";
  exit;
}

function find_invite(string $token): ?array {
  if ($token === '') return null;
  $tokenHash = hash('sha256', $token);
  $stmt = db()->prepare("SELECT * FROM user_invites WHERE token_hash=? LIMIT 1");
  $stmt->execute([$tokenHash]);
  $invite = $stmt->fetch();
  if (!$invite) return null;
  if (!empty($invite['used_at'])) return null;
  if (!empty($invite['expires_at']) && strtotime($invite['expires_at']) < time()) return null;
  return $invite;
}

$invite = find_invite($token);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  try {
    if (!$invite) throw new Exception('Undangan tidak valid atau sudah kedaluwarsa.');
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $p1 = (string)($_POST['pass1'] ?? '');
    $p2 = (string)($_POST['pass2'] ?? '');
    if ($name === '' || $username === '') throw new Exception('Nama dan username wajib diisi.');
    if ($p1 === '' || $p1 !== $p2) throw new Exception('Password tidak cocok.');

    $stmt = db()->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    if ($stmt->fetch()) throw new Exception('Username sudah dipakai.');

    $role = (string)($invite['role'] ?? 'user');
    if (!in_array($role, ['admin', 'user', 'owner', 'pegawai'], true)) $role = 'user';
    $hash = password_hash($p1, PASSWORD_DEFAULT);
    $email = (string)($invite['email'] ?? '');
    $stmt = db()->prepare("INSERT INTO users (username,email,name,role,password_hash) VALUES (?,?,?,?,?)");
    $stmt->execute([$username, $email, $name, $role, $hash]);

    $stmt = db()->prepare("UPDATE user_invites SET used_at=NOW() WHERE id=?");
    $stmt->execute([(int)$invite['id']]);
    $ok = 'Akun berhasil dibuat. Silakan login.';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$appName = app_config()['app']['name'];
$customCss = setting('custom_css','');
$csrf = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  $csrf = csrf_token();
} elseif ($invite && !$ok) {
  $csrf = csrf_token();
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Aktivasi Akun</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .invite-wrap{max-width:520px;margin:8vh auto}
  </style>
</head>
<body>
  <div class="invite-wrap">
    <div class="card">
      <h2 style="margin-top:0"><?php echo e($appName); ?></h2>
      <p><small>Aktivasi akun undangan</small></p>
      <?php if ($err): ?>
        <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
      <?php endif; ?>
      <?php if ($ok): ?>
        <div class="card" style="border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)">
          <?php echo e($ok); ?> <a href="<?php echo e(base_url('adm.php')); ?>">Login</a>
        </div>
      <?php endif; ?>

      <?php if ($invite && !$ok): ?>
        <p>Undangan untuk: <strong><?php echo e($invite['email'] ?? ''); ?></strong></p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="token" value="<?php echo e($token); ?>">
          <div class="row">
            <label>Nama</label>
            <input name="name" required>
          </div>
          <div class="row">
            <label>Username</label>
            <input name="username" required>
          </div>
          <div class="row">
            <label>Password</label>
            <input type="password" name="pass1" required>
          </div>
          <div class="row">
            <label>Ulangi Password</label>
            <input type="password" name="pass2" required>
          </div>
          <button class="btn" type="submit" style="width:100%">Buat Akun</button>
        </form>
      <?php elseif (!$ok): ?>
        <p>Undangan tidak ditemukan atau sudah kedaluwarsa.</p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
