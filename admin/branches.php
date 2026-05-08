<?php
require_once __DIR__ . '/../core/branch_portal.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
$u = require_menu_access('settings', 'view');
ensure_branch_portal_schema();
$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  require_action_access('settings', 'edit');
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'save_branch') {
      $id = (int)($_POST['id'] ?? 0);
      $code = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
      $name = trim((string)($_POST['branch_name'] ?? ''));
      $active = isset($_POST['is_active']) ? 1 : 0;
      if ($code === '' || $name === '') throw new Exception('Kode dan nama cabang wajib diisi.');
      if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $code)) throw new Exception('Kode cabang hanya boleh huruf, angka, underscore, atau minus.');
      if ($id > 0) {
        $stmt = db()->prepare("UPDATE branches SET branch_code=?, branch_name=?, is_active=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$code, $name, $active, $id]);
        $msg = 'Cabang berhasil diperbarui.';
      } else {
        $stmt = db()->prepare("INSERT INTO branches (branch_code, branch_name, is_active) VALUES (?,?,?)");
        $stmt->execute([$code, $name, $active]);
        $msg = 'Cabang berhasil dibuat. Halaman cabang otomatis tersedia.';
      }
    } elseif ($action === 'assign_user') {
      $userId = (int)($_POST['user_id'] ?? 0);
      $branchIds = array_map('intval', $_POST['branch_ids'] ?? []);
      if ($userId <= 0) throw new Exception('User wajib dipilih.');
      db()->prepare("DELETE FROM user_branches WHERE user_id=?")->execute([$userId]);
      $stmt = db()->prepare("INSERT IGNORE INTO user_branches (user_id, branch_id) VALUES (?,?)");
      foreach ($branchIds as $bid) {
        if ($bid > 0) $stmt->execute([$userId, $bid]);
      }
      $msg = 'Akses cabang user berhasil diperbarui.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$editId = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
  $stmt = db()->prepare("SELECT * FROM branches WHERE id=? LIMIT 1");
  $stmt->execute([$editId]);
  $edit = $stmt->fetch() ?: null;
}
$branches = branch_portal_all_branches(false);
$users = db()->query("SELECT id, username, name, role FROM users ORDER BY name ASC")->fetchAll();
$userMap = [];
try {
  $rows = db()->query("SELECT user_id, branch_id FROM user_branches")->fetchAll();
  foreach ($rows as $r) $userMap[(int)$r['user_id']][] = (int)$r['branch_id'];
} catch (Throwable $e) {}
$customCss = setting('custom_css', '');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Master Cabang</title><link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head>
<body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content">
<div class="card"><h3>Master Cabang</h3><p style="color:#64748b">Tambah cabang di sini. Setelah disimpan, dashboard cabang otomatis tersedia di halaman cabang.</p><?php if($err): ?><div class="card" style="border-color:#fecdd3;background:#fff1f2;color:#9f1239"><?php echo e($err); ?></div><?php endif; ?><?php if($msg): ?><div class="card" style="border-color:#bbf7d0;background:#f0fdf4;color:#166534"><?php echo e($msg); ?></div><?php endif; ?>
<form method="post" class="grid cols-4"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save_branch"><input type="hidden" name="id" value="<?php echo e((string)($edit['id'] ?? 0)); ?>">
<div class="row"><label>Kode Cabang</label><input name="branch_code" value="<?php echo e((string)($edit['branch_code'] ?? '')); ?>" placeholder="BELITUNG"></div>
<div class="row"><label>Nama Cabang</label><input name="branch_name" value="<?php echo e((string)($edit['branch_name'] ?? '')); ?>" placeholder="Adena Belitung"></div>
<div class="row" style="align-self:end"><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="is_active" value="1" <?php echo !isset($edit['is_active']) || (int)$edit['is_active']===1 ? 'checked' : ''; ?>> Aktif</label></div>
<div class="row" style="align-self:end"><button class="btn" type="submit"><?php echo $edit ? 'Update Cabang' : 'Tambah Cabang'; ?></button> <?php if($edit): ?><a class="btn btn-light" href="<?php echo e(base_url('admin/branches.php')); ?>">Batal</a><?php endif; ?></div>
</form></div>
<div class="card"><h3>Daftar Cabang</h3><table class="table"><thead><tr><th>Kode</th><th>Nama</th><th>Status</th><th>Halaman Cabang</th><th>Aksi</th></tr></thead><tbody><?php foreach($branches as $b): ?><tr><td><?php echo e((string)$b['branch_code']); ?></td><td><?php echo e((string)$b['branch_name']); ?></td><td><?php echo (int)$b['is_active']===1?'Aktif':'Nonaktif'; ?></td><td><a class="btn btn-light" href="<?php echo e(base_url('cabang/dashboard.php?branch_id='.(int)$b['id'])); ?>" target="_blank">Buka</a></td><td><a class="btn btn-light" href="<?php echo e(base_url('admin/branches.php?edit='.(int)$b['id'])); ?>">Edit</a></td></tr><?php endforeach; ?></tbody></table></div>
<div class="card"><h3>Assign User ke Cabang</h3><p style="color:#64748b">Owner otomatis bisa semua cabang. Untuk user lain, pilih cabang yang boleh diakses.</p><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="assign_user"><div class="grid cols-2"><div class="row"><label>User</label><select name="user_id" required><?php foreach($users as $usr): ?><option value="<?php echo e((string)$usr['id']); ?>"><?php echo e($usr['name'].' (@'.$usr['username'].') - '.$usr['role']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Cabang</label><div style="display:flex;gap:12px;flex-wrap:wrap"><?php foreach($branches as $b): ?><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="branch_ids[]" value="<?php echo e((string)$b['id']); ?>"> <?php echo e((string)$b['branch_name']); ?></label><?php endforeach; ?></div></div></div><div style="margin-top:12px"><button class="btn" type="submit">Simpan Akses Cabang</button></div></form></div>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
