<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/rbac.php';

start_secure_session();
require_admin();
ensure_rbac_schema();
$me = require_menu_access('roles', 'view');
if (!current_user_is_owner()) {
  http_response_code(403);
  exit('Forbidden');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create') {
      $roleKey = strtolower(trim((string)($_POST['role_key'] ?? '')));
      $roleName = trim((string)($_POST['role_name'] ?? ''));
      if ($roleKey === '' || $roleName === '') throw new Exception('Role key dan nama wajib diisi.');
      if ($roleKey === 'owner') throw new Exception('Role owner tidak boleh ditimpa.');
      $stmt = db()->prepare("INSERT INTO roles (role_key, role_name, is_system, is_active) VALUES (?, ?, 0, 1)");
      $stmt->execute([$roleKey, $roleName]);
      redirect(base_url('admin/roles.php'));
    }

    if ($action === 'delete') {
      $roleId = (int)($_POST['role_id'] ?? 0);
      $role = role_by_id($roleId);
      if (!$role) throw new Exception('Role tidak ditemukan.');
      if (($role['role_key'] ?? '') === 'owner') throw new Exception('Role owner tidak boleh dihapus.');
      $stmt = db()->prepare("SELECT COUNT(*) c FROM users WHERE role_id=?");
      $stmt->execute([$roleId]);
      $count = (int)($stmt->fetch()['c'] ?? 0);
      if ($count > 0) throw new Exception('Role masih dipakai user. Pindahkan user dulu.');
      $stmt = db()->prepare("DELETE FROM roles WHERE id=?");
      $stmt->execute([$roleId]);
      redirect(base_url('admin/roles.php'));
    }

    if ($action === 'save_permissions') {
      require_action_access('roles', 'edit');
      $roleId = (int)($_POST['role_id'] ?? 0);
      $role = role_by_id($roleId);
      if (!$role) throw new Exception('Role tidak ditemukan.');
      if (($role['role_key'] ?? '') === 'owner') throw new Exception('Permission owner terkunci penuh.');

      $submittedPerms = $_POST['permissions'] ?? ($_POST['perm'] ?? []);
      if (!is_array($submittedPerms)) $submittedPerms = [];

      $tree = role_menu_tree();
      $db = db();
      $db->beginTransaction();
      foreach ($tree as $menuKey => $meta) {
        $flags = [];
        foreach (['view','create','edit','delete','print','export','approve'] as $actionKey) {
          $flags[$actionKey] = isset($submittedPerms[$menuKey][$actionKey]) ? 1 : 0;
        }
        $stmt = $db->prepare("INSERT INTO role_permissions
          (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_create=VALUES(can_create), can_edit=VALUES(can_edit),
          can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export), can_approve=VALUES(can_approve)");
        $stmt->execute([$roleId, $menuKey, $flags['view'], $flags['create'], $flags['edit'], $flags['delete'], $flags['print'], $flags['export'], $flags['approve']]);
      }
      $cleanup = $db->prepare("DELETE FROM role_permissions WHERE role_id=? AND menu_key NOT IN (" . implode(',', array_fill(0, count($tree), '?')) . ")");
      $cleanup->execute(array_merge([$roleId], array_keys($tree)));
      $db->commit();
      redirect(base_url('admin/roles.php?role_id=' . $roleId));
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$roles = db()->query("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id=r.id) AS users_count FROM roles r ORDER BY CASE WHEN role_key='owner' THEN 0 ELSE 1 END, role_name ASC")->fetchAll();
$selectedRoleId = (int)($_GET['role_id'] ?? ($roles[0]['id'] ?? 0));
$permissions = [];
if ($selectedRoleId > 0) {
  $stmt = db()->prepare("SELECT * FROM role_permissions WHERE role_id=?");
  $stmt->execute([$selectedRoleId]);
  foreach ($stmt->fetchAll() as $row) {
    $permissions[$row['menu_key']] = $row;
  }
}
$tree = role_menu_tree();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Role Management</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">Role & Permission</div></div>
    <div class="content">
      <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
      <div class="grid cols-2">
        <div class="card">
          <h3 style="margin-top:0">Daftar Role</h3>
          <table class="table"><thead><tr><th>Role</th><th>User</th><th></th></tr></thead><tbody>
          <?php foreach ($roles as $role): ?>
            <tr>
              <td><a href="<?php echo e(base_url('admin/roles.php?role_id=' . (int)$role['id'])); ?>"><?php echo e($role['role_name']); ?> <small>(<?php echo e($role['role_key']); ?>)</small></a></td>
              <td><?php echo e((string)$role['users_count']); ?></td>
              <td>
                <?php if (($role['role_key'] ?? '') !== 'owner'): ?>
                  <form method="post" style="display:inline" data-confirm="Hapus role ini?">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="role_id" value="<?php echo e((string)$role['id']); ?>">
                    <button class="btn" type="submit">Hapus</button>
                  </form>
                <?php else: ?><span class="badge">Locked</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody></table>
          <h4>Tambah Role</h4>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="create">
            <div class="row"><label>Role Key</label><input name="role_key" placeholder="contoh: auditor" required></div>
            <div class="row"><label>Role Name</label><input name="role_name" placeholder="contoh: Auditor" required></div>
            <button class="btn" type="submit">Simpan Role</button>
          </form>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Permission Role</h3>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save_permissions"><input type="hidden" name="role_id" value="<?php echo e((string)$selectedRoleId); ?>">
            <table class="table"><thead><tr><th>Menu</th><th>view</th><th>create</th><th>edit</th><th>delete</th><th>print</th><th>export</th><th>approve</th></tr></thead><tbody>
            <?php $selectedRole = role_by_id($selectedRoleId); $isOwnerRole = (($selectedRole['role_key'] ?? '') === 'owner'); ?>
            <?php foreach ($tree as $menuKey => $meta): $perm = $permissions[$menuKey] ?? []; ?>
              <tr>
                <td>└ <?php echo e($meta['label']); ?></td>
                <?php foreach (['view','create','edit','delete','print','export','approve'] as $action): ?>
                  <td><input type="checkbox" name="permissions[<?php echo e($menuKey); ?>][<?php echo e($action); ?>]" <?php echo ((int)($perm['can_' . $action] ?? 0) === 1 || $isOwnerRole) ? 'checked' : ''; ?> <?php echo $isOwnerRole ? 'disabled' : ''; ?>></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if ($isOwnerRole): ?><p><small>Role owner selalu full access.</small></p><?php else: ?><button class="btn" type="submit">Simpan Permission</button><?php endif; ?>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
