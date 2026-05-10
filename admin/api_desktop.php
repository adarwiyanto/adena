<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../api/helpers.php';
require_once __DIR__ . '/../core/api_permissions.php';

start_secure_session();
$me = require_menu_access('settings', 'view');
$roleKey = (string)(resolve_user_role($me)['role_key'] ?? '');
if (!in_array($roleKey, ['owner', 'admin'], true)) {
  redirect(base_url('admin/dashboard.php'));
}

ensure_api_settings_schema();
$err = '';
$ok = '';
$generatedToken = '';

function normalize_device_code(string $code): string {
  $normalized = strtoupper(trim($code));
  return preg_replace('/\s+/', '', $normalized) ?? '';
}
function normalize_remote_domain(string $domain): string {
  $domain = trim($domain);
  if ($domain === '') return '';
  if (!preg_match('~^https?://~i', $domain)) $domain = 'https://' . $domain;
  return rtrim($domain, '/');
}
function api_selected_permissions_from_post(string $fallbackMode): array {
  $selected = $_POST['permissions'] ?? [];
  if (!is_array($selected)) $selected = [];
  $clean = api_clean_permissions($selected);
  return $clean ?: api_default_permissions($fallbackMode);
}
function deactivate_active_tokens_for_device(string $deviceCode, int $exceptId = 0): void {
  $deviceCode = normalize_device_code($deviceCode);
  if ($deviceCode === '') return;
  if ($exceptId > 0) {
    db()->prepare('UPDATE api_tokens SET is_active = 0, revoked_at = COALESCE(revoked_at, NOW()) WHERE device_code = ? AND is_active = 1 AND id <> ?')
      ->execute([$deviceCode, $exceptId]);
    return;
  }
  db()->prepare('UPDATE api_tokens SET is_active = 0, revoked_at = COALESCE(revoked_at, NOW()) WHERE device_code = ? AND is_active = 1')
    ->execute([$deviceCode]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);

  if ($action === 'generate_sender') {
    $name = trim((string)($_POST['name'] ?? ''));
    $deviceCode = normalize_device_code((string)($_POST['device_code'] ?? ''));
    $permissions = api_selected_permissions_from_post('sender');
    if ($name === '') {
      $err = 'Nama API wajib diisi.';
    } elseif ($deviceCode !== '' && !preg_match('/^[A-Z0-9]+$/', $deviceCode)) {
      $err = 'Kode API hanya boleh huruf dan angka, tanpa spasi.';
    } else {
      deactivate_active_tokens_for_device($deviceCode);
      $generatedToken = bin2hex(random_bytes(24));
      db()->prepare('INSERT INTO api_tokens (name, token_hash, device_code, api_mode, permissions, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())')
        ->execute([$name, password_hash($generatedToken, PASSWORD_DEFAULT), $deviceCode !== '' ? $deviceCode : null, 'sender', api_permissions_encode($permissions)]);
      $ok = 'API pembuat/pengirim berhasil dibuat. Salin token sekarang karena hanya tampil sekali.';
    }
  } elseif ($action === 'save_receiver') {
    $name = trim((string)($_POST['name'] ?? ''));
    $remoteBaseUrl = normalize_remote_domain((string)($_POST['remote_base_url'] ?? ''));
    $remoteToken = trim((string)($_POST['remote_token'] ?? ''));
    $permissions = api_selected_permissions_from_post('receiver');
    if ($name === '') {
      $err = 'Nama koneksi wajib diisi.';
    } elseif ($remoteBaseUrl === '') {
      $err = 'Domain pembuat wajib diisi.';
    } elseif ($remoteToken === '') {
      $err = 'API token dari website pembuat wajib diisi.';
    } else {
      if ($id > 0) {
        db()->prepare('UPDATE api_tokens SET name=?, api_mode=?, remote_base_url=?, remote_token=?, permissions=?, is_active=1 WHERE id=?')
          ->execute([$name, 'receiver', $remoteBaseUrl, $remoteToken, api_permissions_encode($permissions), $id]);
        $ok = 'Koneksi penerima berhasil diperbarui.';
      } else {
        db()->prepare('INSERT INTO api_tokens (name, token_hash, api_mode, remote_base_url, remote_token, permissions, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())')
          ->execute([$name, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'receiver', $remoteBaseUrl, $remoteToken, api_permissions_encode($permissions)]);
        $ok = 'Koneksi penerima berhasil disimpan.';
      }
    }
  } elseif ($action === 'revoke' && $id > 0) {
    db()->prepare('UPDATE api_tokens SET is_active = 0, revoked_at = NOW() WHERE id = ?')->execute([$id]);
    $ok = 'API berhasil dinonaktifkan.';
  } elseif ($action === 'delete' && $id > 0) {
    db()->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$id]);
    $ok = 'API berhasil dihapus.';
  } elseif ($action === 'regenerate_sender' && $id > 0) {
    $stmt = db()->prepare('SELECT name, device_code, permissions FROM api_tokens WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) {
      $err = 'API tidak ditemukan.';
    } else {
      $deviceCode = normalize_device_code((string)($old['device_code'] ?? ''));
      deactivate_active_tokens_for_device($deviceCode, $id);
      db()->prepare('UPDATE api_tokens SET is_active = 0, revoked_at = NOW() WHERE id = ?')->execute([$id]);
      $generatedToken = bin2hex(random_bytes(24));
      db()->prepare('INSERT INTO api_tokens (name, token_hash, device_code, api_mode, permissions, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())')
        ->execute([(string)$old['name'], password_hash($generatedToken, PASSWORD_DEFAULT), $deviceCode !== '' ? $deviceCode : null, 'sender', (string)($old['permissions'] ?? api_permissions_encode(api_default_permissions('sender')))]);
      $ok = 'Token API pembuat/pengirim digenerate ulang. Salin token baru sekarang.';
    }
  }
}

$rows = db()->query("SELECT id, name, device_code, api_mode, remote_base_url, permissions, is_active, last_used_at, created_at, revoked_at FROM api_tokens ORDER BY id DESC")
  ->fetchAll(PDO::FETCH_ASSOC);
$senders = array_values(array_filter($rows, fn($r) => (($r['api_mode'] ?? 'sender') !== 'receiver')));
$receivers = array_values(array_filter($rows, fn($r) => (($r['api_mode'] ?? '') === 'receiver')));
$permissionGroups = api_permission_groups();
$customCss = setting('custom_css', '');

function render_permission_list(array $groups, array $selected, string $name = 'permissions[]'): void {
  $selectedMap = array_flip(api_clean_permissions($selected));
  foreach ($groups as $group => $items) {
    echo '<details class="api-perm-group" open><summary>' . e($group) . '</summary><div class="api-perm-items">';
    foreach ($items as $key => $label) {
      $checked = isset($selectedMap[$key]) ? ' checked' : '';
      echo '<label class="api-perm-item"><input type="checkbox" name="' . e($name) . '" value="' . e($key) . '"' . $checked . '> <span>' . e($label) . '</span></label>';
    }
    echo '</div></details>';
  }
}
function summarize_permissions($raw): string {
  $perms = api_permissions_decode($raw);
  if (!$perms) return '-';
  $catalog = api_permission_catalog();
  $labels = [];
  foreach (array_slice($perms, 0, 4) as $p) $labels[] = $catalog[$p]['label'] ?? $p;
  $more = count($perms) > 4 ? ' +' . (count($perms) - 4) . ' lainnya' : '';
  return implode(', ', $labels) . $more;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Setelan API</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    <?php echo $customCss; ?>
    .api-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start}.api-perm-box{border:1px solid #e5e7eb;border-radius:12px;padding:10px;background:#fff}.api-perm-group{border-bottom:1px solid #eef2f7;padding:6px 0}.api-perm-group:last-child{border-bottom:0}.api-perm-group summary{cursor:pointer;font-weight:700}.api-perm-items{padding:6px 0 2px 14px;display:grid;gap:6px}.api-perm-item{display:flex;gap:8px;align-items:flex-start;font-size:14px}.api-muted{opacity:.7}.api-result{margin-top:8px;padding:10px;border-radius:10px;display:none}.api-result.ok{display:block;border:1px solid #86efac;background:#ecfdf5}.api-result.err{display:block;border:1px solid #fca5a5;background:#fef2f2}.api-actions{display:flex;gap:6px;flex-wrap:wrap}@media(max-width:900px){.api-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Setelan API</h3>
        <p><small>Halaman ini menggantikan setelan API lama. Tidak ada file <code>api_settings.php</code>; semua pengaturan memakai <code>api_desktop.php</code>.</small></p>
        <?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>
        <?php if ($generatedToken !== ''): ?>
          <div class="card" style="border-color:#93c5fd;background:#eff6ff">
            <strong>Token baru, tampil sekali:</strong>
            <div style="margin-top:6px"><code style="word-break:break-all"><?php echo e($generatedToken); ?></code></div>
          </div>
        <?php endif; ?>
      </div>

      <div class="api-grid" style="margin-top:16px">
        <div class="card">
          <h3 style="margin-top:0">Website Pembuat / Pengirim API</h3>
          <p><small class="api-muted">Untuk website ini membuat token yang dipakai website lain/POS.</small></p>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="generate_sender">
            <div class="row"><label>Nama</label><input type="text" name="name" required maxlength="100" placeholder="Contoh: API Adena Pusat"></div>
            <div class="row"><label>Kode API / Device</label><input type="text" name="device_code" maxlength="20" placeholder="Opsional, contoh: PUSAT"></div>
            <div class="row"><label>Permission</label><div class="api-perm-box"><?php render_permission_list($permissionGroups, api_default_permissions('sender')); ?></div></div>
            <button class="btn" type="submit">Generate API</button>
          </form>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Website Penerima</h3>
          <p><small class="api-muted">Untuk website ini connect ke website pembuat menggunakan domain dan token.</small></p>
          <form method="post" id="receiverForm">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="save_receiver">
            <div class="row"><label>Nama</label><input type="text" name="name" required maxlength="100" placeholder="Contoh: Koneksi ke Adena Pusat"></div>
            <div class="row"><label>Domain Pembuat</label><input type="text" name="remote_base_url" required placeholder="https://adena.co.id"></div>
            <div class="row"><label>API Token</label><input type="password" name="remote_token" required placeholder="Token dari website pembuat"></div>
            <div class="row"><label>Permission</label><div class="api-perm-box"><?php render_permission_list($permissionGroups, api_default_permissions('receiver')); ?></div></div>
            <div class="api-actions">
              <button class="btn" type="button" id="btnTestApiConnection" data-endpoint="<?php echo e(base_url('admin/api/remote_test.php')); ?>">Test Koneksi</button>
              <button class="btn" type="submit">Simpan Penerima</button>
            </div>
            <div id="apiTestResult" class="api-result"></div>
          </form>
        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Daftar API Pembuat / Pengirim</h3>
        <div class="table-wrap"><table><thead><tr><th>Nama</th><th>Kode</th><th>Permission</th><th>Status</th><th>Last Used</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach ($senders as $t): ?>
          <tr>
            <td><?php echo e($t['name']); ?></td>
            <td><?php echo e((string)($t['device_code'] ?: '-')); ?></td>
            <td><?php echo e(summarize_permissions($t['permissions'] ?? '')); ?></td>
            <td><?php echo ((int)$t['is_active'] === 1) ? '<span style="color:#22c55e">Aktif</span>' : '<span style="opacity:.6">Nonaktif</span>'; ?></td>
            <td><?php echo e($t['last_used_at'] ?: '-'); ?></td>
            <td class="api-actions">
              <?php if ((int)$t['is_active'] === 1): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn" type="submit">Nonaktifkan</button></form><?php endif; ?>
              <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="regenerate_sender"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn" type="submit">Generate Ulang</button></form>
              <form method="post" class="js-confirm-delete-token"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn btn-danger" type="submit">Hapus</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$senders): ?><tr><td colspan="6">Belum ada API pembuat/pengirim.</td></tr><?php endif; ?>
        </tbody></table></div>
      </div>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Daftar Website Penerima</h3>
        <div class="table-wrap"><table><thead><tr><th>Nama</th><th>Domain Pembuat</th><th>Permission</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach ($receivers as $t): ?>
          <tr>
            <td><?php echo e($t['name']); ?></td>
            <td><?php echo e((string)($t['remote_base_url'] ?: '-')); ?></td>
            <td><?php echo e(summarize_permissions($t['permissions'] ?? '')); ?></td>
            <td><?php echo ((int)$t['is_active'] === 1) ? '<span style="color:#22c55e">Aktif</span>' : '<span style="opacity:.6">Nonaktif</span>'; ?></td>
            <td><?php echo e($t['created_at']); ?></td>
            <td class="api-actions">
              <?php if ((int)$t['is_active'] === 1): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn" type="submit">Nonaktifkan</button></form><?php endif; ?>
              <form method="post" class="js-confirm-delete-token"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn btn-danger" type="submit">Hapus</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$receivers): ?><tr><td colspan="6">Belum ada koneksi penerima.</td></tr><?php endif; ?>
        </tbody></table></div>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
<script src="<?php echo e(asset_url('assets/api_desktop.js')); ?>"></script>
</body>
</html>
