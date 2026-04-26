<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../api/helpers.php';

start_secure_session();
$me = require_menu_access('settings', 'view');
$roleKey = (string)(resolve_user_role($me)['role_key'] ?? '');
if (!in_array($roleKey, ['owner', 'admin'], true)) {
  redirect(base_url('admin/dashboard.php'));
}

ensure_api_tokens_table();
$err = '';
$ok = '';
$generatedToken = '';
function normalize_device_code(string $code): string {
  $normalized = strtoupper(trim($code));
  return preg_replace('/\s+/', '', $normalized) ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);

  if ($action === 'generate') {
    $name = trim((string)($_POST['name'] ?? ''));
    $deviceCode = normalize_device_code((string)($_POST['device_code'] ?? ''));
    if ($name === '') {
      $err = 'Nama device wajib diisi.';
    } elseif ($deviceCode !== '' && !preg_match('/^[A-Z0-9]+$/', $deviceCode)) {
      $err = 'Kode POS/Device hanya boleh huruf dan angka (uppercase, tanpa spasi).';
    } else {
      $generatedToken = bin2hex(random_bytes(24));
      db()->prepare('INSERT INTO api_tokens (name, token_hash, device_code, is_active, created_at) VALUES (?, ?, ?, 1, NOW())')
        ->execute([$name, password_hash($generatedToken, PASSWORD_DEFAULT), $deviceCode !== '' ? $deviceCode : null]);
      $ok = 'Token berhasil dibuat. Salin sekarang karena hanya tampil sekali.';
    }
  } elseif ($action === 'revoke' && $id > 0) {
    db()->prepare('UPDATE api_tokens SET is_active = 0, revoked_at = NOW() WHERE id = ?')
      ->execute([$id]);
    $ok = 'Token berhasil direvoke.';
  } elseif ($action === 'regenerate' && $id > 0) {
    $name = trim((string)($_POST['name'] ?? ''));
    $deviceCode = normalize_device_code((string)($_POST['device_code'] ?? ''));
    if ($name === '') {
      $name = 'Kasir Desktop';
    }
    if ($deviceCode !== '' && !preg_match('/^[A-Z0-9]+$/', $deviceCode)) {
      $err = 'Kode POS/Device hanya boleh huruf dan angka (uppercase, tanpa spasi).';
    } else {
      db()->prepare('UPDATE api_tokens SET is_active = 0, revoked_at = NOW() WHERE id = ?')
        ->execute([$id]);
      $generatedToken = bin2hex(random_bytes(24));
      db()->prepare('INSERT INTO api_tokens (name, token_hash, device_code, is_active, created_at) VALUES (?, ?, ?, 1, NOW())')
        ->execute([$name, password_hash($generatedToken, PASSWORD_DEFAULT), $deviceCode !== '' ? $deviceCode : null]);
      $ok = 'Token digenerate ulang. Salin token baru sekarang.';
    }
  }
}

$tokens = db()->query('SELECT id, name, device_code, is_active, last_used_at, created_at, revoked_at FROM api_tokens ORDER BY id DESC')
  ->fetchAll(PDO::FETCH_ASSOC);
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Kasir Desktop</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Kasir Desktop</h3>
        <p><small>Token dipakai POS desktop melalui header Authorization Bearer.</small></p>
        <?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>

        <?php if ($generatedToken !== ''): ?>
          <div class="card" style="border-color:#93c5fd;background:#eff6ff">
            <strong>Token baru (tampil sekali):</strong>
            <div style="margin-top:6px"><code style="word-break:break-all"><?php echo e($generatedToken); ?></code></div>
          </div>
        <?php endif; ?>

        <form method="post" style="margin-top:12px">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="generate">
          <div class="row">
            <label>Nama Device</label>
            <input type="text" name="name" required maxlength="100" placeholder="Contoh: POS Kasir 1">
          </div>
          <div class="row">
            <label>Kode POS/Device</label>
            <input type="text" name="device_code" maxlength="20" placeholder="Contoh: TJQ">
          </div>
          <button class="btn" type="submit">Generate Token</button>
        </form>
      </div>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Daftar Token</h3>
        <div class="table-wrap"><table>
          <thead><tr><th>Nama</th><th>Kode POS</th><th>Status</th><th>Last Used</th><th>Dibuat</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php foreach ($tokens as $t): ?>
            <tr>
              <td><?php echo e($t['name']); ?></td>
              <td><?php echo e((string)($t['device_code'] ?? '-')); ?></td>
              <td><?php echo ((int)$t['is_active'] === 1) ? '<span style="color:#22c55e">Aktif</span>' : '<span style="opacity:.6">Nonaktif</span>'; ?></td>
              <td><?php echo e($t['last_used_at'] ?: '-'); ?></td>
              <td><?php echo e($t['created_at']); ?></td>
              <td style="display:flex;gap:6px;flex-wrap:wrap">
                <?php if ((int)$t['is_active'] === 1): ?>
                  <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>">
                    <button class="btn" type="submit">Revoke</button>
                  </form>
                <?php endif; ?>
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="regenerate">
                  <input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>">
                  <input type="hidden" name="name" value="<?php echo e((string)$t['name']); ?>">
                  <input type="hidden" name="device_code" value="<?php echo e((string)($t['device_code'] ?? '')); ?>">
                  <button class="btn" type="submit">Generate Ulang</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
