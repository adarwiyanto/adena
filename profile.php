<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/lib/upload_secure.php';

start_secure_session();
require_login();
ensure_user_profile_columns();

$me = current_user();
$userId = (int)($me['id'] ?? 0);
$stmt = db()->prepare("SELECT id, username, name, role, avatar_path FROM users WHERE id=? LIMIT 1");
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
    $username = trim($_POST['username'] ?? '');
    $name = trim($_POST['name'] ?? '');
    if ($username === '' || $name === '') {
      throw new Exception('Username dan nama wajib diisi.');
    }

    $stmt = db()->prepare("SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
    $stmt->execute([$username, $userId]);
    if ($stmt->fetch()) {
      throw new Exception('Username sudah digunakan.');
    }

    $avatarPath = $user['avatar_path'] ?? null;
    if (!empty($_FILES['avatar']['name'])) {
      $upload = upload_secure($_FILES['avatar'], 'image');
      if (empty($upload['ok'])) throw new Exception($upload['error'] ?? 'Gagal mengunggah foto profil.');

      if (!empty($avatarPath)) {
        if (upload_is_legacy_path($avatarPath)) {
          $old = __DIR__ . '/' . $avatarPath;
          if (file_exists($old)) {
            @unlink($old);
          }
        } else {
          upload_secure_delete($avatarPath, 'image');
        }
      }
      $avatarPath = $upload['name'];
    }

    $stmt = db()->prepare("UPDATE users SET username=?, name=?, avatar_path=? WHERE id=?");
    $stmt->execute([$username, $name, $avatarPath, $userId]);

    $user['username'] = $username;
    $user['name'] = $name;
    $user['avatar_path'] = $avatarPath;
    start_session();
    $_SESSION['user']['username'] = $username;
    $_SESSION['user']['name'] = $name;
    $_SESSION['user']['avatar_path'] = $avatarPath;

    $ok = 'Profil berhasil diperbarui.';
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$customCss = setting('custom_css', '');
$isPegawai = ($user['role'] ?? '') === 'pegawai';
$avatarUrl = !empty($user['avatar_path']) ? upload_url($user['avatar_path'], 'image') : '';
$initial = strtoupper(substr((string)($user['name'] ?? 'U'), 0, 1));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Edit Profil</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .profile-avatar-preview{
      width:80px;
      height:80px;
      border-radius:999px;
      border:1px solid var(--border);
      object-fit:cover;
      display:flex;
      align-items:center;
      justify-content:center;
      background:#f8fafc;
      font-weight:700;
      color:#1f2937;
    }
    .profile-avatar-wrap{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
  </style>
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
        <div class="title">Edit Profil</div>
        <div class="spacer"></div>
        <?php if ($isPegawai): ?>
          <a class="btn" href="<?php echo e(base_url('pos/index.php')); ?>">Kembali ke POS</a>
        <?php endif; ?>
      </div>
      <div class="content">
        <div class="card" style="max-width:640px">
          <h3 style="margin-top:0">Data Profil</h3>
          <?php if ($err): ?>
            <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
          <?php endif; ?>
          <?php if ($ok): ?>
            <div class="card" style="border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)"><?php echo e($ok); ?></div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <div class="row">
              <label>Username</label>
              <input name="username" value="<?php echo e($user['username']); ?>" required>
            </div>
            <div class="row">
              <label>Nama</label>
              <input name="name" value="<?php echo e($user['name']); ?>" required>
            </div>
            <div class="row">
              <label>Foto Profil (kamera)</label>
              <div class="profile-avatar-wrap">
                <?php if ($avatarUrl): ?>
                  <img class="profile-avatar-preview" src="<?php echo e($avatarUrl); ?>" alt="Foto Profil">
                <?php else: ?>
                  <div class="profile-avatar-preview"><?php echo e($initial); ?></div>
                <?php endif; ?>
                <input class="file-hidden" type="file" id="avatar" name="avatar" accept=".jpg,.jpeg,.png" capture="user">
                <button class="btn" type="button" data-trigger-avatar>Ambil Foto</button>
                <small>Foto wajib diambil dari kamera.</small>
              </div>
            </div>
            <button class="btn" type="submit">Simpan Profil</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
  <script nonce="<?php echo e(csp_nonce()); ?>">
    (function(){
      const trigger = document.querySelector('[data-trigger-avatar]');
      const input = document.getElementById('avatar');
      const preview = document.querySelector('.profile-avatar-preview');
      if (trigger && input) {
        trigger.addEventListener('click', () => input.click());
      }
      if (input) {
        input.addEventListener('change', () => {
          if (!input.files || !input.files[0]) return;
          const fileUrl = URL.createObjectURL(input.files[0]);
          const currentPreview = document.querySelector('.profile-avatar-preview');
          if (!currentPreview) return;
          if (currentPreview.tagName.toLowerCase() === 'img') {
            currentPreview.src = fileUrl;
          } else {
            const img = document.createElement('img');
            img.className = 'profile-avatar-preview';
            img.src = fileUrl;
            img.alt = 'Foto Profil';
            currentPreview.replaceWith(img);
          }
        });
      }
    })();
  </script>
</body>
</html>
