<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/email.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/branch_portal.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
ensure_branch_portal_schema();
ensure_owner_role();
ensure_user_invites_table();
ensure_user_profile_columns();
ensure_rbac_schema();
$me = require_menu_access('users', 'view');

$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  $actionPermMap = ['delete'=>'delete','update_role'=>'edit','update_branch'=>'edit','invite'=>'create','save_email_settings'=>'edit'];
  if (isset($actionPermMap[$action])) {
    require_action_access('users', $actionPermMap[$action]);
  }

  try {
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $stmt = db()->prepare("
          SELECT u.id, u.role_id, COALESCE(r.role_key,'') AS role_key
          FROM users u
          LEFT JOIN roles r ON r.id=u.role_id
          WHERE u.id=? LIMIT 1
        ");
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if ($target && (int)$target['id'] !== (int)($me['id'] ?? 0)) {
          if (($me['role'] ?? '') === 'admin' && (($target['role_key'] ?? '') === 'owner')) {
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
      $roleId = (int)($_POST['role_id'] ?? 0);
      $role = role_by_id($roleId);
      if (!$role || (int)($role['is_active'] ?? 0) !== 1) {
        throw new Exception('Role tidak valid.');
      }
      $roleKey = (string)($role['role_key'] ?? '');
      if ($roleKey === 'owner' && ($me['role'] ?? '') !== 'owner') throw new Exception('Role owner hanya bisa diset owner.');
      if ($id > 0 && $id !== (int)($me['id'] ?? 0)) {
        $stmt = db()->prepare("UPDATE users SET role=?, role_id=? WHERE id=?");
        $stmt->execute([$roleKey, $roleId, $id]);
        redirect(base_url('admin/users.php'));
      }
    }

    if ($action === 'update_branch') {
      if (($me['role'] ?? '') !== 'owner') {
        throw new Exception('Hanya owner yang bisa mengatur cabang user.');
      }
      $id = (int)($_POST['id'] ?? 0);
      $branchId = (int)($_POST['branch_id'] ?? 0);
      if ($id <= 0 || $id === (int)($me['id'] ?? 0)) throw new Exception('User tidak valid.');
      $stmt = db()->prepare("SELECT id FROM branches WHERE id=? AND is_active=1 LIMIT 1");
      $stmt->execute([$branchId]);
      if (!$stmt->fetch()) throw new Exception('Cabang tidak valid.');
      $del = db()->prepare("DELETE FROM user_branches WHERE user_id=?");
      $del->execute([$id]);
      $ins = db()->prepare("INSERT INTO user_branches (user_id, branch_id) VALUES (?, ?)");
      $ins->execute([$id, $branchId]);
      redirect(base_url('admin/users.php'));
    }

    if ($action === 'invite') {
      if (($me['role'] ?? '') !== 'owner') {
        throw new Exception('Hanya owner yang bisa mengundang user.');
      }
      $email = trim($_POST['email'] ?? '');
      $role = strtolower(trim((string)($_POST['role'] ?? 'kasir')));
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email tidak valid.');
      }
      $allowedRoles = array_column(db()->query("SELECT role_key FROM roles WHERE is_active=1")->fetchAll(), 'role_key');
      if (!in_array($role, $allowedRoles, true)) $role = 'kasir';
      if ($role === 'owner') throw new Exception('Undangan owner tidak diizinkan dari halaman ini.');

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

$rolesActive = db()->query("SELECT id, role_key, role_name FROM roles WHERE is_active=1 ORDER BY role_name ASC")->fetchAll();
$users = db()->query("
  SELECT u.id, u.username, u.name, u.avatar_path, u.role_id, u.created_at, r.role_key, r.role_name,
         (SELECT ub.branch_id FROM user_branches ub WHERE ub.user_id=u.id ORDER BY ub.id ASC LIMIT 1) AS assigned_branch_id
  FROM users u
  LEFT JOIN roles r ON r.id = u.role_id
  ORDER BY u.id DESC
")->fetchAll();
$branches = branch_portal_all_branches(true);
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
  <style>
    .user-name-cell{display:flex;align-items:center;gap:10px;min-width:170px}
    .user-photo-thumb{width:42px;height:42px;border-radius:12px;object-fit:cover;border:1px solid var(--border);background:#f8fafc;flex:0 0 auto}
    .user-photo-placeholder{width:42px;height:42px;border-radius:12px;border:1px dashed #cbd5e1;background:linear-gradient(135deg,#f8fafc,#e2e8f0);color:#64748b;display:flex;align-items:center;justify-content:center;text-align:center;font-size:9px;font-weight:800;line-height:1.05;letter-spacing:.03em;box-sizing:border-box;flex:0 0 auto;text-transform:uppercase}
    .user-photo-thumb.is-hidden{display:none}
    .user-photo-name{font-weight:600}
  </style>
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
                  <?php foreach ($rolesActive as $r): if (($r['role_key'] ?? '') === 'owner') continue; ?>
                    <option value="<?php echo e($r['role_key']); ?>"><?php echo e($r['role_name']); ?></option>
                  <?php endforeach; ?>
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
            <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Cabang</th><th>Dibuat</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <?php
                  $roleValue = (string)($u['role_key'] ?? '');
                  $roleLabel = (string)($u['role_name'] ?? '');
                  $roleMissing = ((int)($u['role_id'] ?? 0) <= 0 || $roleValue === '' || $roleLabel === '');
                ?>
                <tr>
                  <td><?php echo e($u['username']); ?></td>
                  <td>
                    <?php $userAvatarUrl = !empty($u['avatar_path']) ? upload_url($u['avatar_path'], 'image') : ''; ?>
                    <div class="user-name-cell">
                      <?php if ($userAvatarUrl): ?>
                        <img class="user-photo-thumb" src="<?php echo e($userAvatarUrl); ?>" alt="<?php echo e($u['name'] ?: $u['username']); ?>" onerror="this.classList.add('is-hidden');this.nextElementSibling.style.display='flex';">
                        <div class="user-photo-placeholder" style="display:none">No<br>Photo</div>
                      <?php else: ?>
                        <div class="user-photo-placeholder">No<br>Photo</div>
                      <?php endif; ?>
                      <span class="user-photo-name"><?php echo e($u['name']); ?></span>
                    </div>
                  </td>
                  <td>
                    <?php if ($roleMissing): ?>
                      <span class="badge" style="background:#fee2e2;color:#991b1b">role_id invalid</span>
                    <?php else: ?>
                      <span class="badge"><?php echo e(strtolower($roleLabel)); ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (($me['role'] ?? '') === 'owner' && (int)$u['id'] !== (int)($me['id'] ?? 0)): ?>
                      <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_branch">
                        <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                        <select name="branch_id">
                          <?php foreach ($branches as $b): ?>
                            <option value="<?php echo e((string)$b['id']); ?>" <?php echo ((int)($u['assigned_branch_id'] ?? 0) === (int)$b['id']) ? 'selected' : ''; ?>><?php echo e((string)$b['branch_name']); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button class="btn" type="submit">Simpan</button>
                      </form>
                    <?php else: ?>
                      <?php
                        $assignedName = '-';
                        foreach ($branches as $b) { if ((int)($u['assigned_branch_id'] ?? 0) === (int)$b['id']) { $assignedName = (string)$b['branch_name']; break; } }
                        echo e($assignedName);
                      ?>
                    <?php endif; ?>
                  </td>
                  <td><?php echo e($u['created_at']); ?></td>
                  <td>
                    <?php if (($me['role'] ?? '') === 'owner' && (int)$u['id'] !== (int)($me['id'] ?? 0)): ?>
                      <form method="post" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="update_role">
                        <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                        <select name="role_id">
                          <?php foreach ($rolesActive as $roleOption): ?>
                            <option value="<?php echo e((string)$roleOption['id']); ?>" <?php echo ((int)$u['role_id'] === (int)$roleOption['id']) ? 'selected' : ''; ?>>
                              <?php echo e($roleOption['role_name']); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                        <button class="btn" type="submit">Simpan</button>
                      </form>
                      <form method="post" data-confirm="Hapus user ini?" style="display:inline">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                        <button class="btn" type="submit">Hapus</button>
                      </form>
                    <?php elseif (($me['role'] ?? '') === 'admin' && (int)$u['id'] !== (int)($me['id'] ?? 0) && ($u['role_key'] ?? '') !== 'owner'): ?>
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
