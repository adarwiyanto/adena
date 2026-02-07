<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/email.php';

start_secure_session();
require_admin();
ensure_owner_role();
ensure_user_invites_table();
$me = current_user();

$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $stmt = db()->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if ($target && (int)$target['id'] !== (int)($me['id'] ?? 0)) {
          if (($me['role'] ?? '') === 'admin' && in_array(($target['role'] ?? ''), ['owner', 'superadmin'], true)) {
            throw new Exception('Admin tidak bisa menghapus owner.');
          }
          $del = db()->prepare("DELETE FROM users WHERE id=?");
          $del->execute([$id]);
          redirect(base_url('admin/users.php'));
        }
      }
    }

    if ($action === 'update_role') {
      if (($me['role'] ?? '') !== 'owner') {
        throw new Exception('Hanya owner yang bisa mengubah role user.');
      }
      $id = (int)($_POST['id'] ?? 0);
      $role = $_POST['role'] ?? 'pegawai';
      if (!in_array($role, ['admin', 'owner', 'pegawai', 'pegawai_pos', 'pegawai_non_pos', 'manager_toko'], true)) $role = 'pegawai';
      if ($id > 0 && $id !== (int)($me['id'] ?? 0)) {
        $stmt = db()->prepare("UPDATE users SET role=? WHERE id=?");
        $stmt->execute([$role, $id]);
        redirect(base_url('admin/users.php'));
      }
    }

    if ($action === 'invite') {
      if (($me['role'] ?? '') !== 'owner') {
        throw new Exception('Hanya owner yang bisa mengundang user.');
      }
      $email = trim($_POST['email'] ?? '');
      $role = $_POST['role'] ?? 'pegawai';
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email tidak valid.');
      }
      if (!in_array($role, ['admin', 'owner', 'pegawai', 'pegawai_pos', 'pegawai_non_pos', 'manager_toko'], true)) $role = 'pegawai';

      $token = bin2hex(random_bytes(16));
      $tokenHash = hash('sha256', $token);
      $expiresAt = date('Y-m-d H:i:s', strtotime('+2 days'));
      $stmt = db()->prepare("INSERT INTO user_invites (email, role, token_hash, expires_at) VALUES (?,?,?,?)");
      $stmt->execute([$email, $role, $tokenHash, $expiresAt]);

      if (!send_invite_email($email, $token, $role)) {
        throw new Exception('Gagal mengirim email undangan.');
      }

      $ok = 'Undangan berhasil dikirim.';
    }

    if ($action === 'save_email_settings') {
      if (($me['role'] ?? '') !== 'owner') {
        throw new Exception('Hanya owner yang bisa mengubah pengaturan email.');
      }
      $smtpHost = trim($_POST['smtp_host'] ?? '');
      $smtpPort = trim($_POST['smtp_port'] ?? '');
      $smtpSecure = strtolower(trim($_POST['smtp_secure'] ?? 'ssl'));
      $smtpUser = trim($_POST['smtp_user'] ?? '');
      $smtpPass = (string)($_POST['smtp_pass'] ?? '');
      $fromEmail = trim($_POST['smtp_from_email'] ?? '');
      $fromName = trim($_POST['smtp_from_name'] ?? '');
      if (!in_array($smtpSecure, ['ssl', 'tls', 'none'], true)) {
        $smtpSecure = 'ssl';
      }

      if ($smtpHost === '' || $smtpPort === '' || $smtpUser === '' || $smtpPass === '') {
        throw new Exception('Host, port, user, dan password SMTP wajib diisi.');
      }
      if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email pengirim tidak valid.');
      }

      set_setting('smtp_host', $smtpHost);
      set_setting('smtp_port', $smtpPort);
      set_setting('smtp_secure', $smtpSecure);
      set_setting('smtp_user', $smtpUser);
      set_setting('smtp_pass', $smtpPass);
      set_setting('smtp_from_email', $fromEmail);
      set_setting('smtp_from_name', $fromName);

      $ok = 'Pengaturan email berhasil disimpan.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$users = db()->query("SELECT id, username, name, role, created_at FROM users ORDER BY id DESC")->fetchAll();
$customCss = setting('custom_css','');
$mailCfg = mail_settings();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>User</title>
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
      <div class="badge">User</div>
    </div>

    <div class="content">
      <div class="grid cols-2">
        <div class="card">
          <h3 style="margin-top:0">Undang User</h3>
          <?php if ($err): ?>
            <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
          <?php endif; ?>
          <?php if ($ok): ?>
            <div class="card" style="border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)"><?php echo e($ok); ?></div>
          <?php endif; ?>
          <?php if (($me['role'] ?? '') === 'owner'): ?>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="invite">
              <div class="row"><label>Email</label><input name="email" type="email" required></div>
              <div class="row">
                <label>Role</label>
                <select name="role">
                  <option value="admin">admin</option>
                  <option value="owner">owner</option>
                  <option value="pegawai" selected>pegawai</option>
                  <option value="pegawai_pos">pegawai_pos</option>
                  <option value="pegawai_non_pos">pegawai_non_pos</option>
                  <option value="manager_toko">manager_toko</option>
                </select>
              </div>
              <button class="btn" type="submit">Kirim Undangan</button>
              <p><small>Link undangan berlaku 2 hari.</small></p>
            </form>
          <?php else: ?>
            <p><small>Hanya owner yang bisa mengundang user.</small></p>
          <?php endif; ?>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Daftar User</h3>
          <table class="table">
            <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Dibuat</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <?php
                  $roleLabels = [
                    'owner' => 'owner',
                    'superadmin' => 'owner',
                    'admin' => 'admin',
                    'pegawai' => 'pegawai',
                    'pegawai_pos' => 'pegawai_pos',
                    'pegawai_non_pos' => 'pegawai_non_pos',
                    'manager_toko' => 'manager_toko',
                  ];
                  $roleValue = (string)($u['role'] ?? '');
                  $roleValueNormalized = $roleValue === 'superadmin' ? 'owner' : $roleValue;
                  $roleLabel = $roleLabels[$roleValue] ?? ($roleValue !== '' ? $roleValue : 'pegawai');
                ?>
                <tr>
                  <td><?php echo e($u['username']); ?></td>
                  <td><?php echo e($u['name']); ?></td>
                  <td><span class="badge"><?php echo e($roleLabel); ?></span></td>
                  <td><?php echo e($u['created_at']); ?></td>
                  <td>
                    <?php if (($me['role'] ?? '') === 'owner' && (int)$u['id'] !== (int)($me['id'] ?? 0)): ?>
                      <form method="post" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_role">
                        <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                        <select name="role">
                          <option value="owner" <?php echo ($roleValueNormalized === 'owner') ? 'selected' : ''; ?>>owner</option>
                          <option value="admin" <?php echo ($roleValueNormalized === 'admin') ? 'selected' : ''; ?>>admin</option>
                          <option value="pegawai" <?php echo ($roleValueNormalized === 'pegawai') ? 'selected' : ''; ?>>pegawai</option>
                          <option value="pegawai_pos" <?php echo ($roleValueNormalized === 'pegawai_pos') ? 'selected' : ''; ?>>pegawai_pos</option>
                          <option value="pegawai_non_pos" <?php echo ($roleValueNormalized === 'pegawai_non_pos') ? 'selected' : ''; ?>>pegawai_non_pos</option>
                          <option value="manager_toko" <?php echo ($roleValueNormalized === 'manager_toko') ? 'selected' : ''; ?>>manager_toko</option>
                        </select>
                        <button class="btn" type="submit">Simpan</button>
                      </form>
                      <form method="post" data-confirm="Hapus user ini?" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                        <button class="btn" type="submit">Hapus</button>
                      </form>
                    <?php elseif (($me['role'] ?? '') === 'admin' && (int)$u['id'] !== (int)($me['id'] ?? 0) && !in_array(($u['role'] ?? ''), ['owner', 'superadmin'], true)): ?>
                      <form method="post" data-confirm="Hapus user ini?" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                        <button class="btn" type="submit">Hapus</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Pengaturan Email</h3>
          <?php if (($me['role'] ?? '') === 'owner'): ?>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="save_email_settings">
              <div class="row"><label>SMTP Host</label><input name="smtp_host" value="<?php echo e($mailCfg['host']); ?>" required></div>
              <div class="row"><label>SMTP Port</label><input name="smtp_port" value="<?php echo e($mailCfg['port']); ?>" required></div>
              <div class="row">
                <label>SMTP Security</label>
                <select name="smtp_secure">
                  <option value="ssl" <?php echo ($mailCfg['secure'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL (465)</option>
                  <option value="tls" <?php echo ($mailCfg['secure'] ?? '') === 'tls' ? 'selected' : ''; ?>>TLS (STARTTLS)</option>
                  <option value="none" <?php echo ($mailCfg['secure'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                </select>
              </div>
              <div class="row"><label>SMTP User</label><input name="smtp_user" value="<?php echo e($mailCfg['user']); ?>" required></div>
              <div class="row"><label>SMTP Password</label><input type="password" name="smtp_pass" value="<?php echo e($mailCfg['pass']); ?>" required></div>
              <div class="row"><label>Email Pengirim</label><input name="smtp_from_email" value="<?php echo e($mailCfg['from_email']); ?>" required></div>
              <div class="row"><label>Nama Pengirim</label><input name="smtp_from_name" value="<?php echo e($mailCfg['from_name']); ?>" required></div>
              <button class="btn" type="submit">Simpan</button>
              <p><small>Default: admin@hopenoodles.my.id (SMTP 465).</small></p>
            </form>
          <?php else: ?>
            <p><small>Pengaturan email hanya tersedia untuk owner.</small></p>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
