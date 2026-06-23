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
$generatedFor = '';

function api_ui_categories(): array {
  return [
    'desktop' => [
      'label' => 'Kasir Desktop',
      'badge' => 'Legacy-safe',
      'access' => 'POS desktop',
      'desc' => 'Token untuk aplikasi kasir desktop. Jalur ini dipertahankan agar tidak mengganggu POS yang sudah berjalan.',
      'default_mode' => 'desktop',
      'default_label' => 'Default kasir desktop',
      'code_label' => 'Kode device/kasir',
      'code_hint' => 'KASIR01',
      'endpoints' => ['/api/v1/master.php','/api/v1/products.php','/api/v1/sales.php','/api/v1/stocks.php'],
    ],
    'branch' => [
      'label' => 'API Antar Cabang',
      'badge' => 'Read + Write',
      'access' => 'Bisa melihat dan menulis',
      'desc' => 'Untuk sinkronisasi antar toko/cabang. Default bisa baca dan tulis sesuai instruksi.',
      'default_mode' => 'branch',
      'default_label' => 'Read + Write',
      'code_label' => 'Kode cabang/unit',
      'code_hint' => 'CABANG01',
      'endpoints' => ['/api/v1/master.php','/api/v1/products.php','/api/v1/sales.php','/api/v1/purchases.php','/api/v1/transfers.php'],
    ],
    'kitchen' => [
      'label' => 'API ke Dapur',
      'badge' => 'Dapur Adena',
      'access' => 'Produk, stok, transfer dapur',
      'desc' => 'Untuk Dapur Adena membaca produk toko dan mengirim transfer stok ke toko.',
      'default_mode' => 'kitchen',
      'default_label' => 'Dapur default',
      'code_label' => 'Kode dapur/toko',
      'code_hint' => 'DAPUR / TJQ',
      'endpoints' => ['/api/v1/kitchen/ping.php','/api/v1/kitchen/products.php','/api/v1/kitchen/receive-transfer.php'],
    ],
    'external' => [
      'label' => 'API Situs Lain',
      'badge' => 'Read Only',
      'access' => 'Hanya melihat',
      'desc' => 'Untuk website/situs lain membaca data produk dan stok. Tidak boleh menulis transaksi atau stok.',
      'default_mode' => 'external',
      'default_label' => 'Read Only',
      'code_label' => 'Kode situs',
      'code_hint' => 'WEBSITE01',
      'endpoints' => ['/api/web/ping.php','/api/web/categories.php','/api/web/products.php','/api/v1/stocks.php'],
    ],
  ];
}

function api_norm_code(string $code): string {
  $code = strtoupper(trim($code));
  $code = preg_replace('/[^A-Z0-9_-]+/', '', $code) ?? '';
  return substr($code, 0, 40);
}
function api_norm_url(string $url): string {
  $url = trim($url);
  if ($url === '') return '';
  if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
  return rtrim($url, '/');
}
function api_permissions_from_request(string $category, string $mode): array {
  if ($mode === 'custom') {
    $selected = $_POST['permissions'] ?? [];
    if (!is_array($selected)) $selected = [];
    $clean = api_clean_permissions($selected);
    if ($category === 'external') {
      $allowed = array_flip(api_default_permissions('external'));
      $clean = array_values(array_filter($clean, static fn($p) => isset($allowed[$p])));
    }
    return $clean ?: api_default_permissions($category);
  }
  return api_default_permissions($category);
}
function api_category_from_row(array $row): string {
  $client = strtolower(trim((string)($row['client_type'] ?? '')));
  $type = strtolower(trim((string)($row['api_type'] ?? '')));
  $mode = strtolower(trim((string)($row['api_mode'] ?? '')));
  if (in_array($client, ['desktop','branch','kitchen','external'], true)) return $client;
  if (in_array($type, ['desktop','branch','kitchen','external'], true)) return $type;
  if ($type === 'dapur' || $client === 'dapur') return 'kitchen';
  if ($mode === 'receiver') return 'branch';
  return 'desktop';
}
function api_summary_perms($raw): string {
  $perms = api_permissions_decode($raw);
  if (!$perms) return '-';
  $catalog = api_permission_catalog();
  $labels = [];
  foreach (array_slice($perms, 0, 5) as $p) $labels[] = $catalog[$p]['label'] ?? $p;
  return implode(', ', $labels) . (count($perms) > 5 ? ' +' . (count($perms) - 5) . ' lainnya' : '');
}
function api_deactivate_same_desktop(string $deviceCode, int $exceptId = 0): void {
  $deviceCode = api_norm_code($deviceCode);
  if ($deviceCode === '') return;
  $params = [$deviceCode];
  $where = 'device_code = ? AND is_active = 1';
  if ($exceptId > 0) { $where .= ' AND id <> ?'; $params[] = $exceptId; }
  db()->prepare("UPDATE api_tokens SET is_active=0, revoked_at=COALESCE(revoked_at,NOW()) WHERE {$where}")->execute($params);
}
function api_render_permission_checks(array $selected, string $category): void {
  $selectedMap = array_flip(api_clean_permissions($selected));
  $externalAllowed = $category === 'external' ? array_flip(api_default_permissions('external')) : null;
  foreach (api_permission_groups() as $group => $items) {
    echo '<details class="api-perm-group"><summary>' . e($group) . '</summary><div class="api-perm-items">';
    foreach ($items as $key => $label) {
      $disabled = ($externalAllowed !== null && !isset($externalAllowed[$key]));
      $checked = isset($selectedMap[$key]) ? ' checked' : '';
      echo '<label class="api-perm-item' . ($disabled ? ' is-disabled' : '') . '"><input type="checkbox" name="permissions[]" value="' . e($key) . '"' . $checked . ($disabled ? ' disabled' : '') . '> <span>' . e($label) . '</span></label>';
    }
    echo '</div></details>';
  }
}

$categories = api_ui_categories();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);

  if ($action === 'generate') {
    $category = (string)($_POST['category'] ?? 'desktop');
    if (!isset($categories[$category])) $category = 'desktop';
    $name = trim((string)($_POST['name'] ?? ''));
    $unitCode = api_norm_code((string)($_POST['unit_code'] ?? ''));
    $mode = (string)($_POST['access_mode'] ?? 'default');
    $remoteBaseUrl = api_norm_url((string)($_POST['remote_base_url'] ?? ''));
    $allowedIps = trim((string)($_POST['allowed_ips'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));
    $permissions = api_permissions_from_request($category, $mode);
    if ($name === '') {
      $err = 'Nama API wajib diisi.';
    } else {
      if ($category === 'desktop' && $unitCode !== '') api_deactivate_same_desktop($unitCode);
      $plain = 'adn_' . bin2hex(random_bytes(24));
      $apiMode = $category === 'branch' ? 'read_write' : ($category === 'external' ? 'read_only' : 'sender');
      db()->prepare('INSERT INTO api_tokens (name, token_hash, device_code, api_type, client_type, api_mode, unit_code, remote_base_url, permissions, allowed_ips, notes, is_active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,NOW())')
        ->execute([$name, password_hash($plain, PASSWORD_DEFAULT), $unitCode !== '' ? $unitCode : null, $category, $category, $apiMode, $unitCode !== '' ? $unitCode : null, $remoteBaseUrl !== '' ? $remoteBaseUrl : null, api_permissions_encode($permissions), $allowedIps !== '' ? $allowedIps : null, $notes !== '' ? $notes : null]);
      $generatedToken = $plain;
      $generatedFor = $categories[$category]['label'] . ' - ' . $name;
      $ok = 'Token API berhasil dibuat. Salin token sekarang karena hanya tampil sekali.';
    }
  } elseif ($action === 'revoke' && $id > 0) {
    db()->prepare('UPDATE api_tokens SET is_active=0, revoked_at=NOW() WHERE id=?')->execute([$id]);
    $ok = 'API dinonaktifkan.';
  } elseif ($action === 'delete' && $id > 0) {
    db()->prepare('DELETE FROM api_tokens WHERE id=?')->execute([$id]);
    $ok = 'API dihapus.';
  } elseif ($action === 'regenerate' && $id > 0) {
    $stmt = db()->prepare('SELECT * FROM api_tokens WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) {
      $err = 'API tidak ditemukan.';
    } else {
      $category = api_category_from_row($old);
      $unitCode = api_norm_code((string)($old['unit_code'] ?: $old['device_code']));
      if ($category === 'desktop' && $unitCode !== '') api_deactivate_same_desktop($unitCode, $id);
      db()->prepare('UPDATE api_tokens SET is_active=0, revoked_at=NOW() WHERE id=?')->execute([$id]);
      $plain = 'adn_' . bin2hex(random_bytes(24));
      $apiMode = (string)($old['api_mode'] ?: ($category === 'branch' ? 'read_write' : ($category === 'external' ? 'read_only' : 'sender')));
      db()->prepare('INSERT INTO api_tokens (name, token_hash, device_code, branch_id, api_type, client_type, api_mode, unit_code, remote_base_url, remote_token, permissions, allowed_ips, notes, is_active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())')
        ->execute([(string)$old['name'], password_hash($plain, PASSWORD_DEFAULT), $unitCode !== '' ? $unitCode : null, $old['branch_id'] ?? null, $category, $category, $apiMode, $unitCode !== '' ? $unitCode : null, $old['remote_base_url'] ?? null, $old['remote_token'] ?? null, $old['permissions'] ?? api_permissions_encode(api_default_permissions($category)), $old['allowed_ips'] ?? null, $old['notes'] ?? null]);
      $generatedToken = $plain;
      $generatedFor = ($categories[$category]['label'] ?? 'API') . ' - ' . (string)$old['name'];
      $ok = 'Token API digenerate ulang. Token lama sudah dinonaktifkan.';
    }
  }
}

$rows = db()->query("SELECT id, name, device_code, branch_id, api_type, client_type, api_mode, unit_code, remote_base_url, permissions, allowed_ips, notes, is_active, last_used_at, created_at, revoked_at FROM api_tokens ORDER BY id DESC")
  ->fetchAll(PDO::FETCH_ASSOC);
$rowsByCat = ['desktop'=>[], 'branch'=>[], 'kitchen'=>[], 'external'=>[]];
foreach ($rows as $row) $rowsByCat[api_category_from_row($row)][] = $row;
$customCss = setting('custom_css', '');
$activeCategory = (string)($_GET['category'] ?? 'desktop');
if (!isset($categories[$activeCategory])) $activeCategory = 'desktop';
$baseUrl = rtrim(base_url(''), '/');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>API & Integrasi</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    <?php echo $customCss; ?>
    :root{--api-brown:#6f4e37;--api-brown-2:#8b6a4f;--api-text:#17202a;--api-muted:#5b6573;--api-border:#d9e1ea;--api-bg:#f7f4ef;--api-soft:#fffaf4;--api-danger:#b42318;--api-ok:#087443;--api-blue:#075985;}
    .api-page{display:grid;gap:12px;color:var(--api-text)}
    .api-titlebar{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .api-titlebar h3{margin:0;font-size:20px}.api-titlebar p{margin:4px 0 0;color:var(--api-muted)}
    .api-tabs{display:flex;gap:8px;flex-wrap:wrap}.api-tab{border:1px solid var(--api-border);background:#fff;color:var(--api-text);padding:8px 10px;border-radius:10px;font-weight:700;font-size:13px}.api-tab.active{background:var(--api-brown);border-color:var(--api-brown);color:#fff}
    .api-card{border:1px solid var(--api-border);background:#fff;border-radius:12px;padding:12px;box-shadow:0 4px 14px rgba(15,23,42,.04)}
    .api-card h4{margin:0 0 6px;font-size:15px}.api-card p{margin:4px 0;color:var(--api-muted);font-size:13px}.api-badge{display:inline-flex;align-items:center;background:var(--api-brown);color:#fff;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700}.api-badge.ok{background:var(--api-ok)}.api-badge.ro{background:var(--api-blue)}.api-badge.off{background:#6b7280}.api-badge.err{background:var(--api-danger)}
    .api-grid{display:grid;grid-template-columns:minmax(360px,.75fr) minmax(520px,1.25fr);gap:12px;align-items:start}.api-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 10px}.api-form-grid label{font-size:12px;font-weight:700;color:var(--api-text)}.api-form-grid input,.api-form-grid select,.api-form-grid textarea{width:100%;margin-top:4px;border:1px solid var(--api-border);border-radius:9px;padding:8px 9px;font-size:13px;background:#fff;color:var(--api-text)}.api-form-grid textarea{min-height:62px}.api-wide{grid-column:1/-1}.api-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.api-actions .btn{padding:7px 10px;font-size:12px;min-height:auto}.api-actions .danger{background:var(--api-danger)!important;color:#fff!important;border-color:var(--api-danger)!important}
    .api-copy{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;display:block;word-break:break-all;background:#111827;color:#f9fafb;border-radius:8px;padding:8px;margin:6px 0;font-size:12px}.api-endpoints{display:grid;gap:5px;margin-top:6px}.api-endpoints code{font-size:12px;background:#f3f4f6;color:#111827;border:1px solid #e5e7eb;border-radius:7px;padding:6px 8px;display:block;word-break:break-all}
    .api-table-wrap{overflow:auto;border:1px solid var(--api-border);border-radius:10px}.api-table{width:100%;border-collapse:separate;border-spacing:0;min-width:760px}.api-table th,.api-table td{padding:8px 9px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:top;font-size:13px}.api-table th{background:#f9fafb;color:#374151;font-size:12px}.api-table tr:last-child td{border-bottom:0}.api-small{font-size:12px;color:var(--api-muted)}
    .api-perm-box{border:1px solid var(--api-border);border-radius:10px;padding:8px;background:var(--api-soft);max-height:260px;overflow:auto}.api-perm-group{border-bottom:1px solid #eadfce;padding:5px 0}.api-perm-group:last-child{border-bottom:0}.api-perm-group summary{cursor:pointer;font-weight:700;font-size:12px;color:var(--api-text)}.api-perm-items{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 8px;padding:6px 0 2px}.api-perm-item{display:flex;gap:6px;align-items:flex-start;font-size:12px}.api-perm-item input{width:auto;margin-top:2px}.api-perm-item.is-disabled{opacity:.45}.api-alert{border-radius:10px;padding:10px 12px;font-size:13px}.api-alert.ok{background:#ecfdf3;border:1px solid #86efac;color:#14532d}.api-alert.err{background:#fef2f2;border:1px solid #fca5a5;color:#7f1d1d}.api-alert.info{background:#eff6ff;border:1px solid #93c5fd;color:#0f172a}
    @media(max-width:1050px){.api-grid{grid-template-columns:1fr}.api-form-grid{grid-template-columns:1fr}.api-perm-items{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="title">API & Integrasi</div></div>
    <div class="content api-page">
      <div class="card api-titlebar">
        <div><h3>API & Integrasi</h3><p>Kontrol API disatukan: Kasir Desktop, Antar Cabang, Dapur, dan Situs Lain. Jalur Kasir Desktop tetap legacy-safe.</p></div>
        <div class="api-tabs">
          <?php foreach($categories as $key=>$cat): ?>
            <a class="api-tab <?php echo $activeCategory===$key?'active':''; ?>" href="?category=<?php echo e($key); ?>"><?php echo e($cat['label']); ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if ($err): ?><div class="api-alert err"><?php echo e($err); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="api-alert ok"><?php echo e($ok); ?></div><?php endif; ?>
      <?php if ($generatedToken !== ''): ?>
        <div class="api-alert info"><strong>Token baru untuk <?php echo e($generatedFor); ?>, tampil sekali:</strong><code class="api-copy"><?php echo e($generatedToken); ?></code></div>
      <?php endif; ?>

      <div class="api-grid">
        <div class="api-card">
          <?php $cat = $categories[$activeCategory]; ?>
          <div class="api-titlebar"><div><h4><?php echo e($cat['label']); ?></h4><p><?php echo e($cat['desc']); ?></p></div><span class="api-badge <?php echo $activeCategory==='external'?'ro':''; ?>"><?php echo e($cat['badge']); ?></span></div>
          <form method="post" style="margin-top:10px">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="category" value="<?php echo e($activeCategory); ?>">
            <div class="api-form-grid">
              <p><label>Nama API<input name="name" required placeholder="<?php echo e($cat['label']); ?>"></label></p>
              <p><label><?php echo e($cat['code_label']); ?><input name="unit_code" placeholder="<?php echo e($cat['code_hint']); ?>"></label></p>
              <p><label>Akses<select name="access_mode" data-api-access-mode><option value="default"><?php echo e($cat['default_label']); ?></option><option value="custom">Custom permission</option></select></label></p>
              <p><label>Allowed IP, opsional<input name="allowed_ips" placeholder="Kosong = semua IP"></label></p>
              <p class="api-wide"><label>Remote/Base URL, opsional<input name="remote_base_url" placeholder="https://domain-lain.com"></label></p>
              <p class="api-wide"><label>Catatan<textarea name="notes" placeholder="Catatan singkat"></textarea></label></p>
              <div class="api-wide" data-api-custom-perms style="display:none">
                <div class="api-perm-box"><?php api_render_permission_checks(api_default_permissions($activeCategory), $activeCategory); ?></div>
              </div>
              <div class="api-wide api-actions"><button class="btn" type="submit">Generate Token</button></div>
            </div>
          </form>
          <hr>
          <h4>Endpoint utama</h4>
          <div class="api-endpoints">
            <?php foreach($cat['endpoints'] as $ep): ?><code><?php echo e($baseUrl . $ep); ?></code><?php endforeach; ?>
          </div>
        </div>

        <div class="api-card">
          <h4>Daftar <?php echo e($cat['label']); ?></h4>
          <div class="api-table-wrap"><table class="api-table">
            <thead><tr><th>Nama</th><th>Kode</th><th>Akses</th><th>Status</th><th>Dipakai</th><th>Aksi</th></tr></thead><tbody>
            <?php foreach($rowsByCat[$activeCategory] as $r): ?>
              <tr>
                <td><strong><?php echo e($r['name']); ?></strong><div class="api-small"><?php echo e($r['notes'] ?? ''); ?></div></td>
                <td><?php echo e((string)($r['unit_code'] ?: $r['device_code'] ?: '-')); ?></td>
                <td><div class="api-small"><?php echo e(api_summary_perms($r['permissions'] ?? '')); ?></div></td>
                <td><?php echo (int)$r['is_active']===1 ? '<span class="api-badge ok">Aktif</span>' : '<span class="api-badge off">Nonaktif</span>'; ?></td>
                <td><div class="api-small">Last: <?php echo e($r['last_used_at'] ?: '-'); ?><br>Dibuat: <?php echo e($r['created_at'] ?: '-'); ?></div></td>
                <td><div class="api-actions">
                  <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="regenerate"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="btn" type="submit">Generate Ulang</button></form>
                  <?php if ((int)$r['is_active'] === 1): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="btn" type="submit">Nonaktifkan</button></form><?php endif; ?>
                  <form method="post" onsubmit="return confirm('Hapus token API ini?')"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="btn danger" type="submit">Hapus</button></form>
                </div></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($rowsByCat[$activeCategory])): ?><tr><td colspan="6"><span class="api-small">Belum ada token untuk kategori ini.</span></td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
      </div>

      <div class="api-card">
        <h4>Log API terakhir</h4>
        <div class="api-table-wrap"><table class="api-table" style="min-width:720px"><thead><tr><th>Waktu</th><th>API</th><th>Endpoint</th><th>Method</th><th>Status</th><th>Pesan</th></tr></thead><tbody>
          <?php
          try {
            $logs = db()->query('SELECT * FROM api_logs ORDER BY id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) { $logs = []; }
          foreach($logs as $l): ?>
            <tr><td><?php echo e($l['created_at'] ?? ''); ?></td><td><?php echo e($l['token_name'] ?? '-'); ?></td><td><?php echo e($l['endpoint'] ?? '-'); ?></td><td><?php echo e($l['method'] ?? '-'); ?></td><td><?php echo e((string)($l['status_code'] ?? '-')); ?></td><td><?php echo e($l['message'] ?? ''); ?></td></tr>
          <?php endforeach; ?>
          <?php if (empty($logs)): ?><tr><td colspan="6"><span class="api-small">Belum ada log API.</span></td></tr><?php endif; ?>
        </tbody></table></div>
        <p><a class="btn" href="<?php echo e(base_url('admin/api_logs.php')); ?>">Lihat Log Lengkap</a></p>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('change', function(e){
  const select = e.target.closest('[data-api-access-mode]');
  if (!select) return;
  const box = select.closest('form').querySelector('[data-api-custom-perms]');
  if (box) box.style.display = select.value === 'custom' ? '' : 'none';
});
</script>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
