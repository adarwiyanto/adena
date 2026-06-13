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

function dapur_normalize_device_code(string $code): string {
  $normalized = strtoupper(trim($code));
  $normalized = preg_replace('/\s+/', '', $normalized) ?? '';
  return $normalized !== '' ? $normalized : 'DAPUR';
}

function dapur_revoke_active_tokens_for_device(string $deviceCode, int $exceptId = 0): void {
  $deviceCode = dapur_normalize_device_code($deviceCode);
  if ($exceptId > 0) {
    db()->prepare('UPDATE api_tokens SET is_active = 0, revoked_at = COALESCE(revoked_at, NOW()) WHERE device_code = ? AND is_active = 1 AND id <> ?')
      ->execute([$deviceCode, $exceptId]);
  } else {
    db()->prepare('UPDATE api_tokens SET is_active = 0, revoked_at = COALESCE(revoked_at, NOW()) WHERE device_code = ? AND is_active = 1')
      ->execute([$deviceCode]);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);

  if ($action === 'generate') {
    $name = trim((string)($_POST['name'] ?? 'API Dapur'));
    $deviceCode = dapur_normalize_device_code((string)($_POST['device_code'] ?? 'DAPUR'));
    if ($name === '') {
      $err = 'Nama token wajib diisi.';
    } elseif (!preg_match('/^[A-Z0-9_-]{3,20}$/', $deviceCode)) {
      $err = 'Kode dapur hanya boleh huruf, angka, underscore, atau strip; 3-20 karakter.';
    } else {
      dapur_revoke_active_tokens_for_device($deviceCode);
      $generatedToken = bin2hex(random_bytes(24));
      db()->prepare('INSERT INTO api_tokens (name, token_hash, device_code, is_active, created_at) VALUES (?, ?, ?, 1, NOW())')
        ->execute([$name, password_hash($generatedToken, PASSWORD_DEFAULT), $deviceCode]);
      $ok = 'Token API Dapur berhasil dibuat. Salin sekarang karena hanya tampil sekali.';
    }
  } elseif ($action === 'revoke' && $id > 0) {
    db()->prepare('UPDATE api_tokens SET is_active = 0, revoked_at = NOW() WHERE id = ?')->execute([$id]);
    $ok = 'Token berhasil direvoke.';
  } elseif ($action === 'delete' && $id > 0) {
    db()->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$id]);
    $ok = 'Token berhasil dihapus.';
  } elseif ($action === 'regenerate' && $id > 0) {
    $stmt = db()->prepare('SELECT name, device_code FROM api_tokens WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) {
      $err = 'Token tidak ditemukan.';
    } else {
      $name = trim((string)($old['name'] ?? 'API Dapur')) ?: 'API Dapur';
      $deviceCode = dapur_normalize_device_code((string)($old['device_code'] ?? 'DAPUR'));
      dapur_revoke_active_tokens_for_device($deviceCode, $id);
      db()->prepare('UPDATE api_tokens SET is_active = 0, revoked_at = NOW() WHERE id = ?')->execute([$id]);
      $generatedToken = bin2hex(random_bytes(24));
      db()->prepare('INSERT INTO api_tokens (name, token_hash, device_code, is_active, created_at) VALUES (?, ?, ?, 1, NOW())')
        ->execute([$name, password_hash($generatedToken, PASSWORD_DEFAULT), $deviceCode]);
      $ok = 'Token digenerate ulang. Salin token baru sekarang.';
    }
  }
}

$tokens = db()->query("SELECT id, name, device_code, is_active, last_used_at, created_at, revoked_at FROM api_tokens WHERE device_code LIKE 'DAPUR%' OR name LIKE '%Dapur%' OR name LIKE '%dapur%' ORDER BY id DESC")
  ->fetchAll(PDO::FETCH_ASSOC);
$customCss = setting('custom_css', '');
$productEndpoint = base_url('api/dapur/products.php');
$stockEndpoint = base_url('api/dapur/stock_transfer.php');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>API Dapur</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    <?php echo $customCss; ?>
    .api-dapur-grid{display:grid;grid-template-columns:minmax(260px,380px) 1fr;gap:14px;align-items:start}
    .api-dapur-card h3{margin-top:0;margin-bottom:8px}
    .api-dapur-card p{margin:6px 0}
    .api-code{display:block;word-break:break-all;white-space:normal;background:rgba(15,23,42,.06);border:1px solid rgba(148,163,184,.35);border-radius:8px;padding:8px;font-size:12px}
    .api-chip{display:inline-block;border:1px solid rgba(148,163,184,.45);border-radius:999px;padding:3px 8px;margin:2px;font-size:12px}
    @media(max-width:900px){.api-dapur-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content">
      <div class="api-dapur-grid">
        <div class="card api-dapur-card">
          <h3>API Dapur</h3>
          <p><small>Token untuk koneksi dapur. Endpoint produk bisa ekspor dan impor seluruh produk.</small></p>
          <?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?>
          <?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>
          <?php if ($generatedToken !== ''): ?>
            <div class="card" style="border-color:#93c5fd;background:#eff6ff">
              <strong>Token baru:</strong>
              <code class="api-code"><?php echo e($generatedToken); ?></code>
            </div>
          <?php endif; ?>

          <form method="post" style="margin-top:10px">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="generate">
            <div class="row">
              <label>Nama</label>
              <input type="text" name="name" required maxlength="100" value="API Dapur">
            </div>
            <div class="row">
              <label>Kode Dapur</label>
              <input type="text" name="device_code" maxlength="20" value="DAPUR">
            </div>
            <button class="btn" type="submit">Generate Token</button>
          </form>
        </div>

        <div class="card api-dapur-card">
          <h3>Endpoint & Permission</h3>
          <p><strong>Produk ekspor/impor semua:</strong></p>
          <code class="api-code"><?php echo e($productEndpoint); ?></code>
          <p><small>GET = export all, POST JSON = import/upsert all.</small></p>
          <p><strong>Transfer stok:</strong></p>
          <code class="api-code"><?php echo e($stockEndpoint); ?></code>
          <div style="margin-top:10px">
            <span class="api-chip">products.export_all</span>
            <span class="api-chip">products.import_all</span>
            <span class="api-chip">stock_transfer</span>
            <span class="api-chip">stock_return</span>
          </div>
          <p><small>Header wajib: <code>Authorization: Bearer TOKEN</code></small></p>
        </div>
      </div>

      <div class="card" style="margin-top:14px">
        <h3 style="margin-top:0">Daftar Token API Dapur</h3>
        <div class="table-wrap"><table>
          <thead><tr><th>Nama</th><th>Kode</th><th>Status</th><th>Last Used</th><th>Dibuat</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php foreach ($tokens as $t): ?>
            <tr>
              <td><?php echo e((string)$t['name']); ?></td>
              <td><?php echo e((string)($t['device_code'] ?? '-')); ?></td>
              <td><?php echo ((int)$t['is_active'] === 1) ? '<span style="color:#22c55e">Aktif</span>' : '<span style="opacity:.65">Nonaktif</span>'; ?></td>
              <td><?php echo e((string)($t['last_used_at'] ?: '-')); ?></td>
              <td><?php echo e((string)$t['created_at']); ?></td>
              <td style="display:flex;gap:6px;flex-wrap:wrap">
                <?php if ((int)$t['is_active'] === 1): ?>
                  <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn" type="submit">Revoke</button></form>
                <?php endif; ?>
                <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="regenerate"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn" type="submit">Generate Ulang</button></form>
                <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn danger" type="submit" onclick="return confirm('Hapus token ini?')">Hapus</button></form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($tokens)): ?><tr><td colspan="6"><small>Belum ada token API Dapur.</small></td></tr><?php endif; ?>
          </tbody>
        </table></div>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
