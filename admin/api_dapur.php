<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../api/helpers.php';
require_once __DIR__ . '/../core/api_permissions.php';

start_secure_session();
$me = require_menu_access('settings', 'view');
$roleKey = (string)(resolve_user_role($me)['role_key'] ?? '');
if (!in_array($roleKey, ['owner','admin'], true)) redirect(base_url('admin/dashboard.php'));

ensure_api_settings_schema();
$customCss = setting('custom_css', '');
$ok = '';
$err = '';
$generatedToken = '';

function dapur_token_permissions(): array {
  return ['products.view','stocks.view','stock_transfer','stock_return'];
}
function dapur_save_token_permissions(int $tokenId, array $permissions): void {
  db()->prepare('DELETE FROM api_token_permissions WHERE token_id=?')->execute([$tokenId]);
  $ins = db()->prepare('INSERT INTO api_token_permissions (token_id, permission_key, is_allowed, created_at) VALUES (?,?,1,NOW())');
  foreach ($permissions as $permission) $ins->execute([$tokenId, $permission]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  if ($action === 'generate') {
    $name = trim((string)($_POST['name'] ?? 'API Dapur'));
    $unitCode = strtoupper(trim((string)($_POST['unit_code'] ?? (function_exists('adena_single_branch_code') ? adena_single_branch_code() : setting('active_unit_code', '')))));
    if ($name === '') $name = 'API Dapur';
    $permissions = dapur_token_permissions();
    $generatedToken = 'dpr_' . bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO api_tokens (name, token_hash, device_code, api_mode, api_type, client_type, unit_code, permissions, is_active, created_at, notes) VALUES (?,?,?,?,?,?,?,?,1,NOW(),?)')
      ->execute([$name, password_hash($generatedToken, PASSWORD_DEFAULT), $unitCode ?: null, 'sender', 'dapur', 'kitchen', $unitCode ?: null, api_permissions_encode($permissions), 'Token khusus transfer stok dari dapur dan pengembalian stok.']);
    $tokenId = (int)db()->lastInsertId();
    dapur_save_token_permissions($tokenId, $permissions);
    $ok = 'Token API Dapur berhasil dibuat. Salin token sekarang karena hanya tampil sekali.';
  } elseif ($action === 'revoke' && $id > 0) {
    db()->prepare("UPDATE api_tokens SET is_active=0, revoked_at=NOW() WHERE id=? AND (api_type='dapur' OR client_type='kitchen')")->execute([$id]);
    $ok = 'Token API Dapur dinonaktifkan.';
  } elseif ($action === 'delete' && $id > 0) {
    db()->prepare("DELETE FROM api_token_permissions WHERE token_id=?")->execute([$id]);
    db()->prepare("DELETE FROM api_tokens WHERE id=? AND (api_type='dapur' OR client_type='kitchen')")->execute([$id]);
    $ok = 'Token API Dapur dihapus.';
  }
}

$stmt = db()->query("SELECT id, name, device_code, unit_code, permissions, is_active, last_used_at, created_at, revoked_at FROM api_tokens WHERE api_type='dapur' OR client_type='kitchen' ORDER BY id DESC");
$tokens = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$endpoint = base_url('api/dapur/stock_transfer.php');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Setting API Dapur</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    <?php echo $customCss; ?>
    .api-dapur-wrap{max-width:980px}.api-dapur-card{padding:14px;border-radius:12px}.api-dapur-form{display:grid;grid-template-columns:1fr 160px auto;gap:10px;align-items:end}.api-dapur-token{word-break:break-all;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px}.api-dapur-actions{display:flex;gap:6px;flex-wrap:wrap}.api-dapur-muted{color:#64748b;font-size:12px}@media(max-width:900px){.api-dapur-form{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="title">Setting API Dapur</div></div>
    <div class="content api-dapur-wrap">
      <div class="card api-dapur-card">
        <h3 style="margin-top:0">Generate Token API Dapur</h3>
        <p class="api-dapur-muted">Token ini khusus untuk transfer stok dari dapur ke toko dan pengembalian stok dari toko ke dapur. Tidak mengubah API POS desktop.</p>
        <?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>
        <?php if ($generatedToken !== ''): ?><div class="api-dapur-token"><strong>Token baru:</strong><br><code><?php echo e($generatedToken); ?></code></div><?php endif; ?>
        <form method="post" class="api-dapur-form" style="margin-top:12px">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="generate">
          <div class="row"><label>Nama Token</label><input type="text" name="name" value="API Dapur" maxlength="100"></div>
          <div class="row"><label>Kode Unit</label><input type="text" name="unit_code" value="<?php echo e(function_exists('adena_single_branch_code') ? adena_single_branch_code() : setting('active_unit_code', '')); ?>" maxlength="40"></div>
          <button class="btn" type="submit">Generate Token</button>
        </form>
        <p class="api-dapur-muted" style="margin-bottom:0">Endpoint: <code><?php echo e($endpoint); ?></code></p>
      </div>

      <div class="card api-dapur-card" style="margin-top:12px">
        <h3 style="margin-top:0">Daftar Token API Dapur</h3>
        <div class="table-wrap"><table>
          <thead><tr><th>Nama</th><th>Kode</th><th>Permission</th><th>Status</th><th>Last Used</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php foreach ($tokens as $t): ?>
            <tr>
              <td><?php echo e((string)$t['name']); ?></td>
              <td><?php echo e((string)($t['unit_code'] ?: $t['device_code'] ?: '-')); ?></td>
              <td>Transfer stok, Pengembalian stok</td>
              <td><?php echo ((int)$t['is_active'] === 1) ? '<span style="color:#22c55e">Aktif</span>' : '<span style="opacity:.65">Nonaktif</span>'; ?></td>
              <td><?php echo e((string)($t['last_used_at'] ?: '-')); ?></td>
              <td class="api-dapur-actions">
                <?php if ((int)$t['is_active'] === 1): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn" type="submit">Nonaktifkan</button></form><?php endif; ?>
                <form method="post" onsubmit="return confirm('Hapus token API Dapur ini?')"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn btn-danger" type="submit">Hapus</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$tokens): ?><tr><td colspan="6">Belum ada token API Dapur.</td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
