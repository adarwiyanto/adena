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

function api_page_is_owner_safe(array $user): bool {
  if (function_exists('current_user_is_owner')) {
    try { return (bool)current_user_is_owner(); } catch (Throwable $e) {}
  }
  try {
    $resolved = function_exists('resolve_user_role') ? resolve_user_role($user) : [];
    return strtolower((string)($resolved['role_key'] ?? $user['role'] ?? '')) === 'owner';
  } catch (Throwable $e) { return false; }
}
if (!api_page_is_owner_safe(is_array($me) ? $me : [])) { redirect(base_url('admin/dashboard.php')); }
ensure_api_tokens_table();

$err = ''; $ok = ''; $generatedToken = '';

function api_permissions_catalog(): array {
  return [
    'Master Data' => ['master.view'=>'Lihat master data cabang/dapur/backoffice, payment, guide'],
    'Jenis Produk' => ['category.view'=>'Lihat jenis produk','category.import'=>'Impor/tambah jenis produk','category.edit'=>'Edit jenis produk'],
    'Produk' => ['product.view'=>'Lihat produk','product.import'=>'Impor/tambah produk','product.edit'=>'Edit produk'],
    'Penjualan' => ['sales.view'=>'Lihat transaksi penjualan','sales.push'=>'Push/impor transaksi penjualan','sales.revision'=>'Revisi transaksi penjualan'],
    'Pembelian' => ['purchase.view'=>'Lihat transaksi pembelian','purchase.push'=>'Push/impor transaksi pembelian','purchase.revision'=>'Revisi transaksi pembelian'],
    'Stok' => ['stock.view'=>'Lihat stok','stock.adjust'=>'Edit/adjustment stok','stock.opname'=>'Stok opname'],
    'Transfer Stok' => ['transfer.view'=>'Lihat transfer stok','transfer.create'=>'Buat transfer stok','transfer.receive'=>'Terima transfer stok','transfer.cancel'=>'Batalkan transfer stok'],
    'User' => ['user.view'=>'Lihat user','user.sync'=>'Sinkron user'],
    'Log API' => ['api_log.view'=>'Lihat log API'],
  ];
}
function api_default_permissions(string $type): array {
  $map = [
    'pos_desktop' => ['master.view','product.view','sales.view','sales.push','stock.view','user.view','user.sync'],
    'branch_client' => ['master.view','product.view','sales.view','sales.push','purchase.view','stock.view','transfer.view','transfer.receive','user.view'],
    'kitchen_client' => ['master.view','category.view','product.view','purchase.view','purchase.push','stock.view','stock.adjust','transfer.view','transfer.create','user.view'],
    'backoffice_client' => ['master.view','category.view','category.import','category.edit','product.view','product.import','product.edit','sales.view','purchase.view','stock.view','transfer.view','user.view','user.sync','api_log.view'],
  ];
  return $map[$type] ?? $map['pos_desktop'];
}
function normalize_code(string $code): string { return preg_replace('/[^A-Z0-9_\-]/', '', strtoupper(trim($code))) ?? ''; }
function save_token_permissions(int $tokenId, array $permissions): void {
  try { ensure_api_tokens_table(); } catch (Throwable $e) {}
  if (!api_table_exists('api_token_permissions')) return;
  db()->prepare('DELETE FROM api_token_permissions WHERE token_id=?')->execute([$tokenId]);
  $stmt = db()->prepare('INSERT INTO api_token_permissions (token_id, permission_key, is_allowed, created_at) VALUES (?,?,1,NOW())');
  foreach (array_unique($permissions) as $p) if ($p !== '') $stmt->execute([$tokenId, $p]);
}
function api_col_exists(string $table, string $col): bool {
  $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
  $stmt->execute([$table, $col]);
  return (int)$stmt->fetchColumn() > 0;
}
function api_table_exists(string $table): bool {
  $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
  $stmt->execute([$table]);
  return (int)$stmt->fetchColumn() > 0;
}
function api_fetch_all_safe(string $sql): array {
  try { return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) { return []; }
}

$branches = [];
try { $branches = db()->query("SELECT id, branch_code, branch_name FROM branches WHERE is_active=1 ORDER BY branch_name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  try {
    if ($action === 'generate') {
      $name = trim((string)($_POST['name'] ?? ''));
      $apiType = (string)($_POST['api_type'] ?? 'pos_desktop');
      $deviceCode = normalize_code((string)($_POST['device_code'] ?? ''));
      $unitCode = normalize_code((string)($_POST['unit_code'] ?? ''));
      $branchId = (int)($_POST['branch_id'] ?? 0);
      $allowedIps = trim((string)($_POST['allowed_ips'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $mode = (string)($_POST['connection_mode'] ?? 'generate');
      $remoteBaseUrl = trim((string)($_POST['remote_base_url'] ?? ''));
      $remoteToken = trim((string)($_POST['remote_token'] ?? ''));
      if ($name === '') throw new RuntimeException('Nama API client wajib diisi.');
      if ($deviceCode === '') $deviceCode = $unitCode;
      if ($mode === 'connect' && ($remoteBaseUrl === '' || $remoteToken === '')) throw new RuntimeException('Base URL dan token cabang sumber wajib diisi untuk mode connect.');

      $generatedToken = bin2hex(random_bytes(24));
      $stmt = db()->prepare('INSERT INTO api_tokens (name, token_hash, device_code, branch_id, token_plain, api_type, unit_code, remote_base_url, remote_token, allowed_ips, notes, is_active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,NOW())');
      $stmt->execute([$name, password_hash($generatedToken, PASSWORD_DEFAULT), $deviceCode ?: null, $branchId ?: null, $generatedToken, $apiType, $unitCode ?: null, $remoteBaseUrl ?: null, $remoteToken ?: null, $allowedIps ?: null, $notes ?: null]);
      $tokenId = (int)db()->lastInsertId();
      $postedPerms = $_POST['permissions'] ?? [];
      $permissions = is_array($postedPerms) && count($postedPerms) ? array_map('strval', $postedPerms) : api_default_permissions($apiType);
      save_token_permissions($tokenId, $permissions);
      $ok = $mode === 'connect' ? 'API client connect cabang berhasil disimpan. Salin token lokal bila diperlukan.' : 'Token API berhasil dibuat. Salin sekarang karena hanya tampil sekali.';
    } elseif ($action === 'save_permissions' && $id > 0) {
      $postedPerms = $_POST['permissions'] ?? [];
      save_token_permissions($id, is_array($postedPerms) ? array_map('strval', $postedPerms) : []);
      $ok = 'Permission API berhasil disimpan.';
    } elseif ($action === 'save_detail' && $id > 0) {
      $name = trim((string)($_POST['name'] ?? '')) ?: 'API Client';
      $apiType = (string)($_POST['api_type'] ?? 'pos_desktop');
      $deviceCode = normalize_code((string)($_POST['device_code'] ?? ''));
      $unitCode = normalize_code((string)($_POST['unit_code'] ?? ''));
      $branchId = (int)($_POST['branch_id'] ?? 0);
      db()->prepare('UPDATE api_tokens SET name=?, api_type=?, device_code=?, unit_code=?, branch_id=?, remote_base_url=?, remote_token=?, allowed_ips=?, notes=? WHERE id=?')
        ->execute([$name,$apiType,$deviceCode ?: null,$unitCode ?: null,$branchId ?: null,trim((string)($_POST['remote_base_url'] ?? '')) ?: null,trim((string)($_POST['remote_token'] ?? '')) ?: null,trim((string)($_POST['allowed_ips'] ?? '')) ?: null,trim((string)($_POST['notes'] ?? '')) ?: null,$id]);
      $ok = 'Detail token API berhasil disimpan.';
    } elseif ($action === 'revoke' && $id > 0) {
      db()->prepare('UPDATE api_tokens SET is_active=0, revoked_at=NOW() WHERE id=?')->execute([$id]);
      $ok = 'Token berhasil direvoke.';
    } elseif ($action === 'activate' && $id > 0) {
      db()->prepare('UPDATE api_tokens SET is_active=1, revoked_at=NULL WHERE id=?')->execute([$id]);
      $ok = 'Token berhasil diaktifkan.';
    } elseif ($action === 'delete' && $id > 0) {
      db()->prepare('DELETE FROM api_token_permissions WHERE token_id=?')->execute([$id]);
      db()->prepare('DELETE FROM api_tokens WHERE id=?')->execute([$id]);
      $ok = 'Token berhasil dihapus.';
    } elseif ($action === 'regenerate' && $id > 0) {
      $generatedToken = bin2hex(random_bytes(24));
      db()->prepare('UPDATE api_tokens SET token_hash=?, token_plain=?, is_active=1, revoked_at=NULL, created_at=NOW() WHERE id=?')->execute([password_hash($generatedToken, PASSWORD_DEFAULT), $generatedToken, $id]);
      $ok = 'Token baru berhasil dibuat. Salin sekarang karena hanya tampil sekali.';
    }
  } catch (Throwable $e) { $err = $e->getMessage(); }
}

$tokens = api_fetch_all_safe('SELECT * FROM api_tokens ORDER BY id DESC');
$permRows = api_fetch_all_safe('SELECT token_id, permission_key FROM api_token_permissions WHERE is_allowed=1');
$tokenPerms = [];
foreach ($permRows as $r) $tokenPerms[(int)$r['token_id']][] = (string)$r['permission_key'];
$customCss = setting('custom_css', '');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pengaturan API</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?>
.api-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.api-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.api-perm{border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#fff}.api-perm summary{cursor:pointer;padding:10px 12px;font-weight:700;background:#f8fafc}.api-perm label{display:flex;gap:10px;align-items:flex-start;padding:8px 12px;border-top:1px solid #f1f5f9}.api-perm input{margin-top:3px}.token-card{border:1px solid #e5e7eb;border-radius:16px;padding:12px;margin-top:10px}.token-head{display:grid;grid-template-columns:1.3fr .8fr .8fr .8fr auto;gap:10px;align-items:center}.badge{display:inline-flex;padding:3px 8px;border-radius:999px;background:#eef6ff;color:#075985;font-size:12px;font-weight:700}.badge.off{background:#f1f5f9;color:#64748b}.detail-box{margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb}@media(max-width:900px){.api-grid,.api-row,.token-head{grid-template-columns:1fr}}
</style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content">
<div class="card"><h3 style="margin-top:0">Pengaturan API</h3><p><small>Owner dapat membuat token untuk POS/cabang/dapur/backoffice, atau menyimpan koneksi ke API dari cabang lain. Token lama tetap kompatibel memakai Authorization Bearer.</small></p>
<?php if ($err): ?><div class="card" style="border-color:#fca5a5;background:#fef2f2"><?php echo e($err); ?></div><?php endif; ?><?php if ($ok): ?><div class="card" style="border-color:#86efac;background:#ecfdf5"><?php echo e($ok); ?></div><?php endif; ?>
<?php if ($generatedToken): ?><div class="card" style="border-color:#93c5fd;background:#eff6ff"><strong>Token baru (tampil sekali):</strong><div style="margin-top:6px"><code style="word-break:break-all"><?php echo e($generatedToken); ?></code></div></div><?php endif; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="generate">
<div class="api-row"><div class="row"><label>Mode koneksi</label><select name="connection_mode" id="connection_mode"><option value="generate">Generate API dari cabang ini</option><option value="connect">Connect ke API cabang lain</option></select></div><div class="row"><label>Jenis API</label><select name="api_type"><option value="pos_desktop">POS Desktop</option><option value="branch_client">Client Cabang</option><option value="kitchen_client">Dapur</option><option value="backoffice_client">Backoffice</option></select></div></div>
<div class="api-row"><div class="row"><label>Nama API Client</label><input type="text" name="name" required maxlength="100" placeholder="Contoh: Backoffice Pusat / Dapur Utama / POS Toko A"></div><div class="row"><label>Kode Device/Unit</label><input type="text" name="device_code" maxlength="40" placeholder="Contoh: BLT, DAPUR, OWNER"></div></div>
<div class="api-row"><div class="row"><label>Unit terkait</label><select name="branch_id"><option value="0">Tidak terikat unit</option><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>"><?php echo e($b['branch_name'].' ('.$b['branch_code'].')'); ?></option><?php endforeach; ?></select></div><div class="row"><label>Kode Unit Terkait</label><input type="text" name="unit_code" maxlength="40" placeholder="Contoh: PGK / BLT / DAPUR"></div></div>
<div class="api-row connect-fields"><div class="row"><label>Base URL API cabang sumber</label><input type="url" name="remote_base_url" placeholder="https://domain-cabang.com"></div><div class="row"><label>Token API cabang sumber</label><input type="text" name="remote_token" placeholder="Paste token dari cabang sumber"></div></div>
<div class="row"><label>Allowed IP (opsional, pisahkan baris/koma)</label><textarea name="allowed_ips" rows="2" placeholder="Kosongkan bila tidak dibatasi"></textarea></div><div class="row"><label>Catatan</label><textarea name="notes" rows="2"></textarea></div>
<h4>Permission API</h4><p><small>Bila tidak dicentang, sistem memakai default sesuai jenis API. Tampilan dibuat model list explorer agar tidak menumpuk.</small></p><div class="api-grid"><?php foreach(api_permissions_catalog() as $group=>$items): ?><details class="api-perm"><summary><?php echo e($group); ?></summary><?php foreach($items as $key=>$label): ?><label><input type="checkbox" name="permissions[]" value="<?php echo e($key); ?>"><span><?php echo e($label); ?></span></label><?php endforeach; ?></details><?php endforeach; ?></div>
<div style="margin-top:12px"><button class="btn" type="submit">Generate / Simpan API</button></div></form></div>

<div class="card" style="margin-top:16px"><h3 style="margin-top:0">Daftar Token API</h3><p><small>Permission token yang sudah ada disembunyikan. Klik Detail untuk melihat/mengubah permission.</small></p>
<?php foreach($tokens as $t): $tid=(int)$t['id']; $perms=$tokenPerms[$tid] ?? []; ?><div class="token-card"><div class="token-head"><div><strong><?php echo e($t['name']); ?></strong><br><small><?php echo e($t['device_code'] ?: '-'); ?></small></div><div><?php echo e($t['api_type'] ?: 'pos_desktop'); ?></div><div><?php echo e($t['unit_code'] ?: '-'); ?></div><div><?php echo ((int)$t['is_active']===1)?'<span class="badge">Aktif</span>':'<span class="badge off">Nonaktif</span>'; ?></div><details><summary class="btn" style="list-style:none">Detail</summary></details></div>
<details class="detail-box"><summary class="btn" style="display:inline-block;list-style:none">Buka detail token</summary>
<form method="post" style="margin-top:10px"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save_detail"><input type="hidden" name="id" value="<?php echo e((string)$tid); ?>"><div class="api-row"><div class="row"><label>Nama</label><input name="name" value="<?php echo e($t['name']); ?>"></div><div class="row"><label>Jenis</label><select name="api_type"><?php foreach(['pos_desktop'=>'POS Desktop','branch_client'=>'Client Cabang','kitchen_client'=>'Dapur','backoffice_client'=>'Backoffice'] as $k=>$v): ?><option value="<?php echo e($k); ?>" <?php echo (($t['api_type'] ?: 'pos_desktop')===$k)?'selected':''; ?>><?php echo e($v); ?></option><?php endforeach; ?></select></div></div><div class="api-row"><div class="row"><label>Kode</label><input name="device_code" value="<?php echo e($t['device_code'] ?? ''); ?>"></div><div class="row"><label>Kode Unit</label><input name="unit_code" value="<?php echo e($t['unit_code'] ?? ''); ?>"></div></div><div class="api-row"><div class="row"><label>Unit terkait</label><select name="branch_id"><option value="0">Tidak terikat unit</option><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo ((int)($t['branch_id'] ?? 0)===(int)$b['id'])?'selected':''; ?>><?php echo e($b['branch_name'].' ('.$b['branch_code'].')'); ?></option><?php endforeach; ?></select></div><div class="row"><label>Allowed IP</label><textarea name="allowed_ips" rows="1"><?php echo e($t['allowed_ips'] ?? ''); ?></textarea></div></div><div class="api-row"><div class="row"><label>Remote Base URL</label><input name="remote_base_url" value="<?php echo e($t['remote_base_url'] ?? ''); ?>"></div><div class="row"><label>Remote Token</label><input name="remote_token" value="<?php echo e($t['remote_token'] ?? ''); ?>"></div></div><div class="row"><label>Catatan</label><textarea name="notes" rows="2"><?php echo e($t['notes'] ?? ''); ?></textarea></div><button class="btn" type="submit">Simpan Detail</button></form>
<form method="post" style="margin-top:12px"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save_permissions"><input type="hidden" name="id" value="<?php echo e((string)$tid); ?>"><h4>Permission</h4><div class="api-grid"><?php foreach(api_permissions_catalog() as $group=>$items): ?><details class="api-perm"><summary><?php echo e($group); ?></summary><?php foreach($items as $key=>$label): ?><label><input type="checkbox" name="permissions[]" value="<?php echo e($key); ?>" <?php echo in_array($key,$perms,true)?'checked':''; ?>><span><?php echo e($label); ?></span></label><?php endforeach; ?></details><?php endforeach; ?></div><button class="btn" style="margin-top:10px" type="submit">Simpan Permission</button></form>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px"><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$tid); ?>"><input type="hidden" name="action" value="regenerate"><button class="btn" type="submit">Generate Ulang</button></form><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$tid); ?>"><input type="hidden" name="action" value="<?php echo ((int)$t['is_active']===1)?'revoke':'activate'; ?>"><button class="btn" type="submit"><?php echo ((int)$t['is_active']===1)?'Revoke':'Aktifkan'; ?></button></form><form method="post" onsubmit="return confirm('Hapus token API ini?')"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$tid); ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-danger" type="submit">Hapus</button></form></div>
</details></div><?php endforeach; ?>
</div></div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script><script>function toggleConnect(){var m=document.getElementById('connection_mode');document.querySelectorAll('.connect-fields').forEach(function(el){el.style.display=(m&&m.value==='connect')?'grid':'none';});}document.getElementById('connection_mode')?.addEventListener('change',toggleConnect);toggleConnect();</script></body></html>
