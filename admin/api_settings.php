<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/api_permissions.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session();
$me = require_admin();
if (!current_user_is_owner()) {
  redirect(base_url('admin/dashboard.php'));
}
ensure_api_v1_schema();
try { ensure_inventory_module_schema(); } catch (Throwable $e) {}

$err = '';
$ok = '';
$generatedToken = '';
$clientTypes = [
  'pos_desktop' => 'POS Desktop',
  'backoffice' => 'Backoffice',
  'branch' => 'Cabang / Toko',
  'kitchen' => 'Dapur',
  'integration' => 'Integrasi Lain',
];

function api_norm_device_code(string $code): string {
  $normalized = strtoupper(trim($code));
  return preg_replace('/\s+/', '', $normalized) ?? '';
}

function api_post_permissions(): array {
  $perms = $_POST['permissions'] ?? [];
  return is_array($perms) ? api_clean_permissions($perms) : [];
}

function api_default_or_post_permissions(string $clientType): array {
  $posted = api_post_permissions();
  return $posted ?: api_default_permissions($clientType);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  try {
    if ($action === 'generate') {
      $name = trim((string)($_POST['name'] ?? ''));
      $clientType = strtolower(trim((string)($_POST['client_type'] ?? 'pos_desktop')));
      if (!isset($clientTypes[$clientType])) $clientType = 'integration';
      $deviceCode = api_norm_device_code((string)($_POST['device_code'] ?? ''));
      $branchId = (int)($_POST['branch_id'] ?? 0);
      $allowedIps = trim((string)($_POST['allowed_ips'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $permissions = api_default_or_post_permissions($clientType);
      if ($name === '') throw new Exception('Nama API client wajib diisi.');
      if ($deviceCode !== '' && !preg_match('/^[A-Z0-9_-]+$/', $deviceCode)) throw new Exception('Kode device/unit hanya boleh huruf, angka, strip, dan underscore.');
      $generatedToken = bin2hex(random_bytes(32));
      db()->prepare("INSERT INTO api_tokens (name, token_hash, device_code, branch_id, token_plain, client_type, permissions, allowed_ips, notes, is_active, created_at) VALUES (?,?,?,?,?,?,?,?,?,1,NOW())")
        ->execute([$name, password_hash($generatedToken, PASSWORD_DEFAULT), $deviceCode !== '' ? $deviceCode : null, $branchId > 0 ? $branchId : null, null, $clientType, api_permissions_encode($permissions), $allowedIps !== '' ? $allowedIps : null, $notes !== '' ? $notes : null]);
      $ok = 'Token API berhasil dibuat. Salin token sekarang karena hanya tampil sekali.';
    } elseif ($action === 'save' && $id > 0) {
      $name = trim((string)($_POST['name'] ?? ''));
      $clientType = strtolower(trim((string)($_POST['client_type'] ?? 'pos_desktop')));
      if (!isset($clientTypes[$clientType])) $clientType = 'integration';
      $deviceCode = api_norm_device_code((string)($_POST['device_code'] ?? ''));
      $branchId = (int)($_POST['branch_id'] ?? 0);
      $allowedIps = trim((string)($_POST['allowed_ips'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $permissions = api_post_permissions();
      if ($name === '') throw new Exception('Nama API client wajib diisi.');
      db()->prepare("UPDATE api_tokens SET name=?, client_type=?, device_code=?, branch_id=?, permissions=?, allowed_ips=?, notes=? WHERE id=?")
        ->execute([$name, $clientType, $deviceCode !== '' ? $deviceCode : null, $branchId > 0 ? $branchId : null, api_permissions_encode($permissions), $allowedIps !== '' ? $allowedIps : null, $notes !== '' ? $notes : null, $id]);
      $ok = 'Pengaturan API berhasil disimpan.';
    } elseif ($action === 'regenerate' && $id > 0) {
      $stmt = db()->prepare('SELECT * FROM api_tokens WHERE id=? LIMIT 1');
      $stmt->execute([$id]);
      $old = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$old) throw new Exception('Token tidak ditemukan.');
      $generatedToken = bin2hex(random_bytes(32));
      db()->prepare('UPDATE api_tokens SET token_hash=?, is_active=1, revoked_at=NULL, created_at=NOW() WHERE id=?')
        ->execute([password_hash($generatedToken, PASSWORD_DEFAULT), $id]);
      $ok = 'Token digenerate ulang. Salin token baru sekarang.';
    } elseif ($action === 'revoke' && $id > 0) {
      db()->prepare('UPDATE api_tokens SET is_active=0, revoked_at=NOW() WHERE id=?')->execute([$id]);
      $ok = 'Token berhasil dinonaktifkan.';
    } elseif ($action === 'activate' && $id > 0) {
      db()->prepare('UPDATE api_tokens SET is_active=1, revoked_at=NULL WHERE id=?')->execute([$id]);
      $ok = 'Token berhasil diaktifkan kembali.';
    } elseif ($action === 'delete' && $id > 0) {
      db()->prepare('DELETE FROM api_tokens WHERE id=?')->execute([$id]);
      $ok = 'Token berhasil dihapus.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$catalog = api_permission_catalog();
$grouped = [];
foreach ($catalog as $key => $meta) {
  $grouped[$meta['group']][$key] = $meta['label'];
}
$branches = [];
try { $branches = inventory_branches(); } catch (Throwable $e) {}
$tokens = db()->query("SELECT t.*, b.branch_name FROM api_tokens t LEFT JOIN branches b ON b.id=t.branch_id ORDER BY t.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$logs = db()->query("SELECT l.*, t.name AS token_name FROM api_request_logs l LEFT JOIN api_tokens t ON t.id=l.token_id ORDER BY l.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$customCss = function_exists('setting') ? setting('custom_css', '') : '';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pengaturan API</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?> .perm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px}.perm-box{border:1px solid #e5e7eb;border-radius:12px;padding:10px;background:#fff}.perm-box h4{margin:0 0 8px}.perm-box label{display:block;font-weight:500;margin:6px 0}.muted{opacity:.7}.token-card{border:1px solid #e5e7eb;border-radius:14px;padding:12px;margin-bottom:12px}</style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div>
    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Pengaturan API</h3>
        <p><small>Menu ini khusus owner. Token POS lama tetap memakai Authorization Bearer dan tetap kompatibel.</small></p>
        <?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?>
        <?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>
        <?php if ($generatedToken !== ''): ?><div class="card" style="border-color:#93c5fd;background:#eff6ff"><strong>Token baru (tampil sekali):</strong><div style="margin-top:6px"><code style="word-break:break-all"><?php echo e($generatedToken); ?></code></div></div><?php endif; ?>
        <form method="post" style="margin-top:12px">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="generate">
          <div class="row"><label>Nama API Client</label><input type="text" name="name" required maxlength="100" placeholder="Contoh: Backoffice Pusat / Dapur Utama / POS Toko A"></div>
          <div class="row"><label>Jenis API</label><select name="client_type"><?php foreach ($clientTypes as $k=>$v): ?><option value="<?php echo e($k); ?>"><?php echo e($v); ?></option><?php endforeach; ?></select></div>
          <div class="row"><label>Kode Device/Unit</label><input type="text" name="device_code" maxlength="20" placeholder="Contoh: BLT, DAPUR, OWNER"></div>
          <div class="row"><label>Cabang/Dapur terkait</label><select name="branch_id"><option value="0">Tidak terikat unit</option><?php foreach ($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>"><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div>
          <div class="row"><label>Allowed IP (opsional, pisahkan baris/koma)</label><textarea name="allowed_ips" rows="2" placeholder="Kosongkan bila tidak dibatasi"></textarea></div>
          <div class="row"><label>Catatan</label><textarea name="notes" rows="2"></textarea></div>
          <h4>Permission API</h4>
          <p class="muted"><small>Bila tidak dicentang saat membuat token, sistem memakai default sesuai jenis API. Setelah dibuat, permission bisa diedit per token.</small></p>
          <div class="perm-grid">
            <?php foreach ($grouped as $group=>$items): ?><div class="perm-box"><h4><?php echo e($group); ?></h4><?php foreach ($items as $key=>$label): ?><label><input type="checkbox" name="permissions[]" value="<?php echo e($key); ?>"> <?php echo e($label); ?></label><?php endforeach; ?></div><?php endforeach; ?>
          </div>
          <p style="margin-top:12px"><button class="btn" type="submit">Generate Token API</button></p>
        </form>
      </div>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Daftar Token API</h3>
        <?php foreach ($tokens as $t): $perms=api_permissions_decode($t['permissions'] ?? ''); ?>
          <div class="token-card">
            <form method="post">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>">
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px">
                <div><label>Nama</label><input name="name" value="<?php echo e((string)$t['name']); ?>"></div>
                <div><label>Jenis</label><select name="client_type"><?php foreach ($clientTypes as $k=>$v): ?><option value="<?php echo e($k); ?>" <?php echo (($t['client_type'] ?? '')===$k?'selected':''); ?>><?php echo e($v); ?></option><?php endforeach; ?></select></div>
                <div><label>Kode</label><input name="device_code" value="<?php echo e((string)($t['device_code'] ?? '')); ?>"></div>
                <div><label>Unit</label><select name="branch_id"><option value="0">Tidak terikat</option><?php foreach ($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo ((int)($t['branch_id'] ?? 0)===(int)$b['id']?'selected':''); ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div>
              </div>
              <div class="row"><label>Allowed IP</label><textarea name="allowed_ips" rows="2"><?php echo e((string)($t['allowed_ips'] ?? '')); ?></textarea></div>
              <div class="row"><label>Catatan</label><textarea name="notes" rows="2"><?php echo e((string)($t['notes'] ?? '')); ?></textarea></div>
              <div class="perm-grid"><?php foreach ($grouped as $group=>$items): ?><div class="perm-box"><h4><?php echo e($group); ?></h4><?php foreach ($items as $key=>$label): ?><label><input type="checkbox" name="permissions[]" value="<?php echo e($key); ?>" <?php echo in_array($key,$perms,true)?'checked':''; ?>> <?php echo e($label); ?></label><?php endforeach; ?></div><?php endforeach; ?></div>
              <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;align-items:center">
                <span>Status: <?php echo ((int)$t['is_active']===1)?'<b style="color:#16a34a">Aktif</b>':'<b class="muted">Nonaktif</b>'; ?></span>
                <span class="muted">Last used: <?php echo e((string)($t['last_used_at'] ?: '-')); ?></span>
                <button class="btn" type="submit">Simpan</button>
            </form>
                <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><input type="hidden" name="action" value="regenerate"><button class="btn" type="submit">Generate Ulang</button></form>
                <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><input type="hidden" name="action" value="<?php echo ((int)$t['is_active']===1)?'revoke':'activate'; ?>"><button class="btn" type="submit"><?php echo ((int)$t['is_active']===1)?'Nonaktifkan':'Aktifkan'; ?></button></form>
                <form method="post" onsubmit="return confirm('Hapus token ini?')"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><input type="hidden" name="action" value="delete"><button class="btn" type="submit">Hapus</button></form>
              </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Log API Terakhir</h3>
        <div class="table-wrap"><table><thead><tr><th>Waktu</th><th>Token</th><th>Endpoint</th><th>Method</th><th>Permission</th><th>Status</th><th>IP</th></tr></thead><tbody><?php foreach ($logs as $l): ?><tr><td><?php echo e($l['created_at']); ?></td><td><?php echo e((string)($l['token_name'] ?? '-')); ?></td><td><?php echo e($l['endpoint']); ?></td><td><?php echo e($l['method']); ?></td><td><?php echo e((string)($l['permission'] ?? '-')); ?></td><td><?php echo e((string)$l['status_code']); ?></td><td><?php echo e((string)($l['ip_address'] ?? '-')); ?></td></tr><?php endforeach; ?></tbody></table></div>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
