<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/api_web_products.php';

start_secure_session();
$me = require_menu_access('settings', 'view');
$roleKey = (string)(resolve_user_role($me)['role_key'] ?? '');
if (!in_array($roleKey, ['owner', 'admin'], true)) redirect(base_url('admin/dashboard.php'));
ensure_web_product_api_schema();

$err = '';
$ok = '';
$generatedToken = '';
$allowedPerms = [
  'products.read' => 'Lihat/ekspor produk',
  'categories.read' => 'Lihat/ekspor kategori produk',
  'product_images.read' => 'Lihat/ekspor gambar produk',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  try {
    if ($action === 'generate') {
      $name = trim((string)($_POST['name'] ?? ''));
      $perms = $_POST['permissions'] ?? [];
      if (!is_array($perms)) $perms = [];
      $perms = array_values(array_intersect(array_keys($allowedPerms), array_map('strval', $perms)));
      if ($name === '') throw new RuntimeException('Nama API wajib diisi.');
      if (empty($perms)) throw new RuntimeException('Minimal pilih satu permission.');
      $generatedToken = bin2hex(random_bytes(32));
      db()->prepare('INSERT INTO api_web_tokens (name, token_hash, token_plain, permissions, is_active, created_at) VALUES (?,?,?,?,1,NOW())')
        ->execute([$name, password_hash($generatedToken, PASSWORD_DEFAULT), $generatedToken, json_encode($perms)]);
      $ok = 'Token API antar website berhasil dibuat. Salin token ini untuk website penerima.';
    } elseif ($action === 'revoke' && $id > 0) {
      db()->prepare('UPDATE api_web_tokens SET is_active = 0, revoked_at = NOW() WHERE id = ?')->execute([$id]);
      $ok = 'Token berhasil dinonaktifkan.';
    } elseif ($action === 'delete' && $id > 0) {
      db()->prepare('DELETE FROM api_web_tokens WHERE id = ?')->execute([$id]);
      $ok = 'Token berhasil dihapus.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage() ?: 'Terjadi kesalahan.';
  }
}

$tokens = db()->query('SELECT * FROM api_web_tokens ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>API Antar Website</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    <?php echo $customCss; ?>
    .api-grid{display:grid;grid-template-columns:minmax(280px,420px) 1fr;gap:16px;align-items:start}.api-token{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;word-break:break-all;background:#eef2ff;border:1px solid #c7d2fe;border-radius:12px;padding:10px}.perm-list{display:grid;gap:8px}.perm-item{display:flex;gap:10px;align-items:flex-start;padding:10px;border:1px solid var(--border);border-radius:12px;background:rgba(255,255,255,.65)}.status-pill{display:inline-flex;border-radius:999px;padding:3px 9px;font-size:12px;background:#e5e7eb}.status-on{background:#dcfce7;color:#166534}.status-off{background:#fee2e2;color:#991b1b}.table-scroll{overflow:auto}.table-scroll table{min-width:760px}@media (max-width:900px){.api-grid{grid-template-columns:1fr}.table-scroll table{min-width:0}.table-scroll table,.table-scroll thead,.table-scroll tbody,.table-scroll th,.table-scroll td,.table-scroll tr{display:block}.table-scroll thead{display:none}.table-scroll tr{border:1px solid var(--border);border-radius:14px;margin-bottom:10px;padding:8px;background:#fff}.table-scroll td{border:0!important;display:flex;justify-content:space-between;gap:12px}.table-scroll td::before{content:attr(data-label);font-weight:700;color:var(--muted)}}
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><a class="btn" href="<?php echo e(base_url('admin/product_import.php')); ?>">Impor Produk</a></div>
    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">API Antar Website</h3>
        <p><small>Token ini khusus untuk komunikasi web-to-web. Tidak memakai tabel/file API Desktop, sehingga POS Desktop tetap aman.</small></p>
      </div>
      <?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>
      <?php if ($generatedToken !== ''): ?><div class="card" style="border-color:#93c5fd;background:#eff6ff"><strong>Token baru:</strong><div class="api-token" style="margin-top:8px"><?php echo e($generatedToken); ?></div></div><?php endif; ?>

      <div class="api-grid">
        <div class="card">
          <h3 style="margin-top:0">Buat API Pembuat</h3>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="generate">
            <div class="row"><label>Nama API</label><input name="name" required maxlength="120" placeholder="Contoh: Produk Cabang Belitung"></div>
            <label>Permission</label>
            <div class="perm-list" style="margin-top:8px">
              <?php foreach ($allowedPerms as $key => $label): ?>
                <label class="perm-item"><input type="checkbox" name="permissions[]" value="<?php echo e($key); ?>" checked><span><strong><?php echo e($label); ?></strong><br><small><?php echo e($key); ?></small></span></label>
              <?php endforeach; ?>
            </div>
            <button class="btn" type="submit" style="margin-top:12px">Generate Token Web</button>
          </form>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Daftar Token API Web</h3>
          <div class="table-scroll"><table class="table">
            <thead><tr><th>Nama</th><th>Permission</th><th>Status</th><th>Last Used</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($tokens as $t): $perms = web_product_token_permissions($t); ?>
              <tr>
                <td data-label="Nama"><?php echo e((string)$t['name']); ?></td>
                <td data-label="Permission"><small><?php echo e(implode(', ', $perms)); ?></small></td>
                <td data-label="Status"><?php echo ((int)$t['is_active'] === 1) ? '<span class="status-pill status-on">Aktif</span>' : '<span class="status-pill status-off">Nonaktif</span>'; ?></td>
                <td data-label="Last Used"><?php echo e((string)($t['last_used_at'] ?: '-')); ?></td>
                <td data-label="Aksi" style="display:flex;gap:8px;flex-wrap:wrap">
                  <?php if ((int)$t['is_active'] === 1): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn" type="submit">Nonaktifkan</button></form><?php endif; ?>
                  <form method="post" data-confirm="Hapus token ini?"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><button class="btn danger" type="submit">Hapus</button></form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$tokens): ?><tr><td colspan="5" style="text-align:center;color:var(--muted)">Belum ada token.</td></tr><?php endif; ?>
            </tbody>
          </table></div>
        </div>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
