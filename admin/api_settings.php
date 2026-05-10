<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../core/api_permissions.php';

start_secure_session();
$me = require_admin();
if (!current_user_is_owner()) redirect(base_url('admin/dashboard.php'));
ensure_api_v1_schema();

$err = '';
$ok = '';
$generatedToken = '';
$testResult = null;
$modes = ['sender' => 'Pembuat / Pengirim API', 'receiver' => 'Penerima API'];

function adena_api_norm_url(string $url): string {
  $url = trim($url);
  if ($url === '') return '';
  if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
  return rtrim($url, '/');
}
function adena_api_post_permissions(): array {
  $perms = $_POST['permissions'] ?? [];
  return is_array($perms) ? api_clean_permissions($perms) : [];
}
function adena_api_permission_summary(array $perms, array $catalog, int $limit = 5): string {
  if (!$perms) return 'Belum ada permission';
  $labels = [];
  foreach ($perms as $p) $labels[] = $catalog[$p]['label'] ?? $p;
  $more = max(0, count($labels) - $limit);
  $labels = array_slice($labels, 0, $limit);
  return implode(', ', $labels) . ($more ? ' +' . $more . ' lainnya' : '');
}
function adena_api_test_remote(string $baseUrl, string $token): array {
  $baseUrl = adena_api_norm_url($baseUrl);
  $token = trim($token);
  if ($baseUrl === '' || $token === '') return ['ok'=>false,'message'=>'Domain pembuat dan API token wajib diisi.'];
  $url = $baseUrl . '/api/auth.php';
  $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json', 'X-Debug-Sync: 1'];
  $body = false; $status = 0; $err = '';
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n", $headers),'timeout'=>15,'ignore_errors'=>true]]);
    $body = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header) && is_array($http_response_header)) {
      foreach ($http_response_header as $h) if (preg_match('~HTTP/\S+\s+(\d+)~', $h, $m)) { $status=(int)$m[1]; break; }
    }
    $err = $body === false ? 'Tidak dapat menghubungi domain pembuat.' : '';
  }
  if ($body === false || $body === '') return ['ok'=>false,'status'=>$status,'message'=>$err ?: 'Tidak ada response dari domain pembuat.','url'=>$url];
  $json = json_decode((string)$body, true);
  if (!is_array($json)) return ['ok'=>false,'status'=>$status,'message'=>'Response bukan JSON valid.','url'=>$url,'raw'=>substr((string)$body,0,500)];
  $remoteToken = $json['token'] ?? [];
  $permissions = [];
  if (isset($remoteToken['permissions'])) $permissions = api_permissions_decode($remoteToken['permissions']);
  return ['ok'=>!empty($json['ok']),'status'=>$status,'message'=>(string)($json['message'] ?? (!empty($json['ok']) ? 'Koneksi berhasil.' : 'Koneksi gagal.')),'url'=>$url,'remote'=>$remoteToken,'permissions'=>$permissions];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  try {
    if ($action === 'test_receiver') {
      $testResult = adena_api_test_remote((string)($_POST['remote_base_url'] ?? ''), (string)($_POST['remote_token'] ?? ''));
    } elseif ($action === 'create_sender') {
      $name = trim((string)($_POST['name'] ?? ''));
      $permissions = adena_api_post_permissions();
      if ($name === '') throw new Exception('Nama API wajib diisi.');
      if (!$permissions) throw new Exception('Pilih minimal satu permission.');
      $generatedToken = bin2hex(random_bytes(32));
      db()->prepare("INSERT INTO api_tokens (name, token_hash, token_plain, client_type, api_type, permissions, is_active, created_at) VALUES (?,?,?,?,?,?,1,NOW())")
        ->execute([$name, password_hash($generatedToken, PASSWORD_DEFAULT), null, 'sender', 'sender', api_permissions_encode($permissions)]);
      $ok = 'API pembuat berhasil dibuat. Salin token sekarang karena hanya tampil sekali.';
    } elseif ($action === 'create_receiver') {
      $name = trim((string)($_POST['name'] ?? ''));
      $remoteBaseUrl = adena_api_norm_url((string)($_POST['remote_base_url'] ?? ''));
      $remoteToken = trim((string)($_POST['remote_token'] ?? ''));
      $permissions = adena_api_post_permissions();
      if ($name === '') throw new Exception('Nama koneksi wajib diisi.');
      if ($remoteBaseUrl === '') throw new Exception('Domain pembuat wajib diisi.');
      if ($remoteToken === '') throw new Exception('API token dari pembuat wajib diisi.');
      if (!$permissions) throw new Exception('Pilih minimal satu permission lokal untuk koneksi ini.');
      $localSecret = bin2hex(random_bytes(32));
      db()->prepare("INSERT INTO api_tokens (name, token_hash, token_plain, client_type, api_type, remote_base_url, remote_token, permissions, is_active, created_at) VALUES (?,?,?,?,?,?,?,?,1,NOW())")
        ->execute([$name, password_hash($localSecret, PASSWORD_DEFAULT), null, 'receiver', 'receiver', $remoteBaseUrl, $remoteToken, api_permissions_encode($permissions)]);
      $ok = 'Koneksi penerima berhasil disimpan.';
    } elseif ($action === 'save' && $id > 0) {
      $name = trim((string)($_POST['name'] ?? ''));
      $mode = (string)($_POST['api_mode'] ?? 'sender');
      if (!isset($modes[$mode])) $mode = 'sender';
      $remoteBaseUrl = $mode === 'receiver' ? adena_api_norm_url((string)($_POST['remote_base_url'] ?? '')) : null;
      $remoteToken = $mode === 'receiver' ? trim((string)($_POST['remote_token'] ?? '')) : null;
      $permissions = adena_api_post_permissions();
      if ($name === '') throw new Exception('Nama wajib diisi.');
      if ($mode === 'receiver' && ($remoteBaseUrl === '' || $remoteToken === '')) throw new Exception('Domain pembuat dan API token wajib diisi untuk penerima.');
      if (!$permissions) throw new Exception('Pilih minimal satu permission.');
      db()->prepare("UPDATE api_tokens SET name=?, client_type=?, api_type=?, remote_base_url=?, remote_token=?, permissions=? WHERE id=?")
        ->execute([$name, $mode, $mode, $remoteBaseUrl, $remoteToken, api_permissions_encode($permissions), $id]);
      $ok = 'Pengaturan API berhasil disimpan.';
    } elseif ($action === 'regenerate' && $id > 0) {
      $generatedToken = bin2hex(random_bytes(32));
      db()->prepare('UPDATE api_tokens SET token_hash=?, is_active=1, revoked_at=NULL, created_at=NOW() WHERE id=?')
        ->execute([password_hash($generatedToken, PASSWORD_DEFAULT), $id]);
      $ok = 'Token digenerate ulang. Salin token baru sekarang.';
    } elseif ($action === 'revoke' && $id > 0) {
      db()->prepare('UPDATE api_tokens SET is_active=0, revoked_at=NOW() WHERE id=?')->execute([$id]);
      $ok = 'API berhasil dinonaktifkan.';
    } elseif ($action === 'activate' && $id > 0) {
      db()->prepare('UPDATE api_tokens SET is_active=1, revoked_at=NULL WHERE id=?')->execute([$id]);
      $ok = 'API berhasil diaktifkan kembali.';
    } elseif ($action === 'delete' && $id > 0) {
      db()->prepare('DELETE FROM api_tokens WHERE id=?')->execute([$id]);
      $ok = 'API berhasil dihapus.';
    }
  } catch (Throwable $e) { $err = $e->getMessage(); }
}

$catalog = api_permission_catalog();
$grouped = [];
foreach ($catalog as $key => $meta) $grouped[$meta['group']][$key] = $meta['label'];
$tokens = db()->query("SELECT * FROM api_tokens ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$customCss = function_exists('setting') ? setting('custom_css', '') : '';
function render_permission_list(array $grouped, array $selected = []): void { ?>
  <div class="api-perm-list">
    <?php foreach ($grouped as $group=>$items): ?>
      <details class="api-perm-group" open>
        <summary><?php echo e($group); ?></summary>
        <div class="api-perm-items">
          <?php foreach ($items as $key=>$label): ?>
            <label><input type="checkbox" name="permissions[]" value="<?php echo e($key); ?>" <?php echo in_array($key,$selected,true)?'checked':''; ?>> <span><?php echo e($label); ?></span></label>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
<?php }
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pengaturan API</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?>
.api-tabs{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.api-panel{border:1px solid #e5e7eb;border-radius:16px;padding:14px;background:#fff}.api-panel h4{margin:0 0 8px}.api-help{color:#64748b;font-size:13px;line-height:1.45}.api-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.api-perm-list{border:1px solid #e5e7eb;border-radius:14px;overflow:hidden;background:#fff}.api-perm-group{border-bottom:1px solid #eef2f7}.api-perm-group:last-child{border-bottom:0}.api-perm-group summary{cursor:pointer;font-weight:700;padding:10px 12px;background:#f8fafc}.api-perm-items{padding:8px 12px}.api-perm-items label{display:flex;gap:9px;align-items:flex-start;padding:6px 0;font-weight:500}.api-token-card{border:1px solid #e5e7eb;border-radius:14px;padding:12px;margin-bottom:12px;background:#fff}.api-token-head{display:grid;grid-template-columns:1.5fr 160px 1fr auto;gap:10px;align-items:center}.api-badge{display:inline-block;border-radius:999px;padding:3px 9px;background:#dcfce7;color:#166534;font-size:12px;font-weight:700}.api-badge.off{background:#f1f5f9;color:#475569}.api-summary{color:#64748b;font-size:13px}.api-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}.api-test-ok{border-color:#86efac!important;background:#ecfdf5}.api-test-bad{border-color:#fca5a5!important;background:#fef2f2}@media(max-width:760px){.api-token-head{grid-template-columns:1fr}}
</style></head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content">
<div class="card"><h3 style="margin-top:0">Pengaturan API</h3><p class="api-help">Dibuat sederhana: <b>Pembuat/Pengirim</b> untuk membuat token, <b>Penerima</b> untuk connect ke website pembuat. Log API tetap berada di menu Log API.</p><?php if($err): ?><div class="card api-test-bad"><?php echo e($err); ?></div><?php endif; ?><?php if($ok): ?><div class="card api-test-ok"><?php echo e($ok); ?></div><?php endif; ?><?php if($generatedToken): ?><div class="card" style="border-color:#93c5fd;background:#eff6ff"><b>Token baru, tampil sekali:</b><div style="margin-top:6px"><code style="word-break:break-all"><?php echo e($generatedToken); ?></code></div></div><?php endif; ?><?php if(is_array($testResult)): ?><div class="card <?php echo !empty($testResult['ok'])?'api-test-ok':'api-test-bad'; ?>"><b>Hasil Test Koneksi:</b> <?php echo e($testResult['message'] ?? ''); ?><br><small>Status HTTP: <?php echo e((string)($testResult['status'] ?? '-')); ?> · URL: <?php echo e((string)($testResult['url'] ?? '-')); ?></small><?php if(!empty($testResult['remote'])): ?><br><small>Remote: <?php echo e((string)($testResult['remote']['name'] ?? '-')); ?> · Unit: <?php echo e((string)($testResult['remote']['unit_code'] ?? $testResult['remote']['device_code'] ?? '-')); ?></small><?php endif; ?><?php if(!empty($testResult['permissions'])): ?><br><small>Permission remote: <?php echo e(adena_api_permission_summary($testResult['permissions'], $catalog, 12)); ?></small><?php endif; ?></div><?php endif; ?>
<div class="api-tabs">
  <div class="api-panel"><h4>Pembuat / Pengirim API</h4><p class="api-help">Untuk website yang membuat token agar website lain/POS bisa mengambil atau mengirim data.</p><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="create_sender"><div class="row"><label>Nama</label><input name="name" required placeholder="Contoh: API Adena Pusat"></div><h4>Permission</h4><?php render_permission_list($grouped, api_default_permissions('integration')); ?><p><button class="btn" type="submit">Generate API</button></p></form></div>
  <div class="api-panel"><h4>Penerima API</h4><p class="api-help">Untuk website yang connect ke domain pembuat memakai token dari website pembuat.</p><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="create_receiver"><div class="row"><label>Nama</label><input name="name" required placeholder="Contoh: Connect ke Dapur Pusat"></div><div class="row"><label>Domain pembuat</label><input name="remote_base_url" placeholder="https://domain-pembuat.com"></div><div class="row"><label>API token dari pembuat</label><input name="remote_token" placeholder="Paste token dari website pembuat"></div><h4>Permission lokal</h4><?php render_permission_list($grouped, api_default_permissions('integration')); ?><div class="api-actions"><button class="btn" type="submit">Simpan Penerima</button><button class="btn" type="submit" name="action" value="test_receiver">Test Koneksi</button></div></form></div>
</div></div>
<div class="card" style="margin-top:16px"><h3 style="margin-top:0">Daftar API</h3><?php foreach($tokens as $t): $mode=(string)($t['api_type'] ?: $t['client_type'] ?: 'sender'); if(!isset($modes[$mode])) $mode = (($t['remote_base_url'] ?? '') !== '' ? 'receiver' : 'sender'); $perms=api_permissions_decode($t['permissions'] ?? ''); ?><div class="api-token-card"><div class="api-token-head"><div><b><?php echo e((string)$t['name']); ?></b><div class="api-summary"><?php echo e(adena_api_permission_summary($perms,$catalog)); ?></div></div><div><?php echo e($modes[$mode]); ?></div><div class="api-summary"><?php echo $mode==='receiver' ? e((string)($t['remote_base_url'] ?? '-')) : 'Token lokal'; ?></div><div><?php echo ((int)$t['is_active']===1)?'<span class="api-badge">Aktif</span>':'<span class="api-badge off">Nonaktif</span>'; ?></div></div><details style="margin-top:10px"><summary class="btn" style="display:inline-block;list-style:none;cursor:pointer">Detail</summary><form method="post" style="margin-top:12px"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><input type="hidden" name="api_mode" value="<?php echo e($mode); ?>"><div class="api-grid"><div><label>Nama</label><input name="name" value="<?php echo e((string)$t['name']); ?>"></div><div><label>Mode</label><input value="<?php echo e($modes[$mode]); ?>" disabled></div><?php if($mode==='receiver'): ?><div><label>Domain pembuat</label><input name="remote_base_url" value="<?php echo e((string)($t['remote_base_url'] ?? '')); ?>"></div><div><label>API token pembuat</label><input name="remote_token" value="<?php echo e((string)($t['remote_token'] ?? '')); ?>"></div><?php endif; ?></div><h4>Permission</h4><?php render_permission_list($grouped,$perms); ?><div class="api-actions"><button class="btn" type="submit">Simpan</button></form><?php if($mode==='sender'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><input type="hidden" name="action" value="regenerate"><button class="btn" type="submit">Generate Ulang Token</button></form><?php endif; ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><input type="hidden" name="action" value="<?php echo ((int)$t['is_active']===1)?'revoke':'activate'; ?>"><button class="btn" type="submit"><?php echo ((int)$t['is_active']===1)?'Nonaktifkan':'Aktifkan'; ?></button></form><form method="post" onsubmit="return confirm('Hapus API ini?')"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="id" value="<?php echo e((string)$t['id']); ?>"><input type="hidden" name="action" value="delete"><button class="btn" type="submit">Hapus</button></form><?php if($mode==='receiver'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="test_receiver"><input type="hidden" name="remote_base_url" value="<?php echo e((string)($t['remote_base_url'] ?? '')); ?>"><input type="hidden" name="remote_token" value="<?php echo e((string)($t['remote_token'] ?? '')); ?>"><button class="btn" type="submit">Test Koneksi</button></form><?php endif; ?></div></details></div><?php endforeach; ?><?php if(!$tokens): ?><p class="api-help">Belum ada API.</p><?php endif; ?></div>
</div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
