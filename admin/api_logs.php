<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/rbac.php';
require_once __DIR__ . '/../api/helpers.php';

start_secure_session();
$me = require_menu_access('settings', 'view');
function api_page_is_owner_safe(array $user): bool {
  if (function_exists('current_user_is_owner')) { try { return (bool)current_user_is_owner(); } catch (Throwable $e) {} }
  try { $resolved = function_exists('resolve_user_role') ? resolve_user_role($user) : []; return strtolower((string)($resolved['role_key'] ?? $user['role'] ?? '')) === 'owner'; } catch (Throwable $e) { return false; }
}
if (!api_page_is_owner_safe(is_array($me) ? $me : [])) { redirect(base_url('admin/dashboard.php')); }
ensure_api_tokens_table();

$tokenId = (int)($_GET['token_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = [];
if ($tokenId > 0) { $where[] = 'l.token_id=?'; $params[] = $tokenId; }
if ($status !== '') { $where[] = 'l.status_code=?'; $params[] = (int)$status; }
if ($q !== '') { $where[] = '(l.endpoint LIKE ? OR l.token_name LIKE ? OR l.permission_key LIKE ? OR l.ip_address LIKE ?)'; $like='%'.$q.'%'; array_push($params,$like,$like,$like,$like); }
$sqlWhere = $where ? ('WHERE '.implode(' AND ', $where)) : '';
$stmt = db()->prepare("SELECT l.* FROM api_logs l $sqlWhere ORDER BY l.id DESC LIMIT 300");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$tokens = db()->query('SELECT id, name, device_code FROM api_tokens ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$customCss = setting('custom_css', '');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Log API</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head>
<body class="desktop-compact"><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content">
<div class="card"><h3 style="margin-top:0">Log API</h3><p><small>Riwayat request API dipisahkan dari halaman Pengaturan API agar halaman token tetap ringan.</small></p>
<form method="get" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end">
<div class="row"><label>Token</label><select name="token_id"><option value="0">Semua token</option><?php foreach($tokens as $t): ?><option value="<?php echo e((string)$t['id']); ?>" <?php echo $tokenId===(int)$t['id']?'selected':''; ?>><?php echo e($t['name'].' '.(($t['device_code']??'') ? '('.$t['device_code'].')' : '')); ?></option><?php endforeach; ?></select></div>
<div class="row"><label>Status</label><input name="status" value="<?php echo e($status); ?>" placeholder="200 / 401 / 403 / 500"></div>
<div class="row"><label>Cari</label><input name="q" value="<?php echo e($q); ?>" placeholder="endpoint / permission / IP"></div>
<button class="btn" type="submit">Filter</button></form></div>
<div class="card" style="margin-top:16px"><div class="table-wrap"><table><thead><tr><th>Waktu</th><th>Token</th><th>Endpoint</th><th>Method</th><th>Permission</th><th>Status</th><th>IP</th><th>Pesan</th></tr></thead><tbody>
<?php if (!$logs): ?><tr><td colspan="8">Belum ada log API.</td></tr><?php endif; ?>
<?php foreach($logs as $l): ?><tr><td><?php echo e($l['created_at']); ?></td><td><?php echo e($l['token_name'] ?: '-'); ?></td><td><?php echo e($l['endpoint']); ?></td><td><?php echo e($l['method']); ?></td><td><?php echo e($l['permission_key'] ?: '-'); ?></td><td><?php echo e((string)($l['status_code'] ?? '-')); ?></td><td><?php echo e($l['ip_address'] ?: '-'); ?></td><td><?php echo e($l['message'] ?: '-'); ?></td></tr><?php endforeach; ?>
</tbody></table></div></div></div></div></div><script src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
