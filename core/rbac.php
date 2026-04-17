<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

function ensure_rbac_schema(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("CREATE TABLE IF NOT EXISTS roles (
      id INT AUTO_INCREMENT PRIMARY KEY,
      role_key VARCHAR(50) NOT NULL,
      role_name VARCHAR(100) NOT NULL,
      is_system TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_role_key (role_key)
    ) ENGINE=InnoDB");

    db()->exec("CREATE TABLE IF NOT EXISTS role_permissions (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      role_id INT NOT NULL,
      menu_key VARCHAR(80) NOT NULL,
      can_view TINYINT(1) NOT NULL DEFAULT 0,
      can_create TINYINT(1) NOT NULL DEFAULT 0,
      can_edit TINYINT(1) NOT NULL DEFAULT 0,
      can_delete TINYINT(1) NOT NULL DEFAULT 0,
      can_print TINYINT(1) NOT NULL DEFAULT 0,
      can_export TINYINT(1) NOT NULL DEFAULT 0,
      can_approve TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_role_menu (role_id, menu_key),
      KEY idx_menu (menu_key),
      CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
  } catch (Throwable $e) {
  }

  try {
    db()->exec("DELETE rp1 FROM role_permissions rp1
      INNER JOIN role_permissions rp2
      ON rp1.role_id = rp2.role_id AND rp1.menu_key = rp2.menu_key AND rp1.id > rp2.id");
  } catch (Throwable $e) {
  }

  try {
    $hasRoleId = (bool)db()->query("SHOW COLUMNS FROM users LIKE 'role_id'")->fetch();
    if (!$hasRoleId) {
      db()->exec("ALTER TABLE users ADD COLUMN role_id INT NULL AFTER role");
      try { db()->exec("ALTER TABLE users ADD KEY idx_role_id (role_id)"); } catch (Throwable $e) {}
    }
    try {
      db()->exec("ALTER TABLE users ADD KEY idx_role_id (role_id)");
    } catch (Throwable $e) {}
  } catch (Throwable $e) {
  }

  $defaults = [
    ['owner', 'Owner', 1],
    ['admin', 'Admin', 1],
    ['manager', 'Manager', 1],
    ['kasir', 'Kasir', 1],
    ['gudang', 'Gudang', 1],
  ];
  foreach ($defaults as $row) {
    try {
      $stmt = db()->prepare("INSERT INTO roles (role_key, role_name, is_system, is_active)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), is_system=VALUES(is_system), is_active=1");
      $stmt->execute([$row[0], $row[1], $row[2]]);
    } catch (Throwable $e) {}
  }

  try {
    db()->exec("UPDATE users SET role='owner' WHERE role='superadmin'");
  } catch (Throwable $e) {}

  $roleMap = [
    'owner' => 'owner',
    'admin' => 'admin',
    'manager' => 'manager',
    'kasir' => 'kasir',
    'gudang' => 'gudang',
    'pegawai' => 'kasir',
    'user' => 'kasir',
    '' => 'kasir',
  ];
  try {
    $users = db()->query("SELECT id, role, role_id FROM users")->fetchAll();
    foreach ($users as $u) {
      $roleId = (int)($u['role_id'] ?? 0);
      $role = role_by_id($roleId);
      if ($role) {
        $targetRoleKey = strtolower(trim((string)($role['role_key'] ?? '')));
      } else {
        $existingRole = strtolower(trim((string)($u['role'] ?? '')));
        $targetRoleKey = $roleMap[$existingRole] ?? $existingRole;
        if ($targetRoleKey === '' || $targetRoleKey === 'pegawai' || $targetRoleKey === 'user') {
          $targetRoleKey = 'kasir';
        }
        $roleId = role_id_by_key($targetRoleKey);
      }
      if ($roleId <= 0) continue;
      $stmt = db()->prepare("UPDATE users SET role_id=?, role=? WHERE id=?");
      $stmt->execute([$roleId, $targetRoleKey, (int)$u['id']]);
    }
  } catch (Throwable $e) {
  }

  try {
    db()->exec("ALTER TABLE users ADD CONSTRAINT fk_users_role_id FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT ON UPDATE CASCADE");
  } catch (Throwable $e) {
  }

  seed_default_role_permissions();
}

function role_id_by_key(string $roleKey): int {
  try {
    $stmt = db()->prepare("SELECT id FROM roles WHERE role_key=? LIMIT 1");
    $stmt->execute([$roleKey]);
    $row = $stmt->fetch();
    return (int)($row['id'] ?? 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function role_by_id(int $roleId): ?array {
  try {
    $stmt = db()->prepare("SELECT * FROM roles WHERE id=? LIMIT 1");
    $stmt->execute([$roleId]);
    $row = $stmt->fetch();
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function role_by_key(string $roleKey): ?array {
  try {
    $stmt = db()->prepare("SELECT * FROM roles WHERE role_key=? LIMIT 1");
    $stmt->execute([$roleKey]);
    $row = $stmt->fetch();
    return $row ?: null;
  } catch (Throwable $e) {
    return null;
  }
}

function resolve_user_role(array $user): array {
  ensure_rbac_schema();

  $roleId = (int)($user['role_id'] ?? 0);
  $role = $roleId > 0 ? role_by_id($roleId) : null;

  if (!$role) {
    $legacyRole = strtolower(trim((string)($user['role'] ?? '')));
    if ($legacyRole === 'superadmin') $legacyRole = 'owner';
    if ($legacyRole === 'pegawai' || $legacyRole === 'user') $legacyRole = 'kasir';
    if ($legacyRole !== '') {
      $role = role_by_key($legacyRole);
      if ($role) {
        $roleId = (int)($role['id'] ?? 0);
      }
    }
  }

  return [
    'role_id' => $roleId,
    'role_key' => strtolower(trim((string)($role['role_key'] ?? ''))),
    'role_name' => (string)($role['role_name'] ?? ''),
  ];
}

function role_menu_tree(): array {
  return [
    'dashboard' => ['label' => 'Dashboard', 'actions' => ['view', 'export']],
    'pos' => ['label' => 'POS', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve']],
    'sales' => ['label' => 'Penjualan', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve']],
    'produk' => ['label' => 'Produk', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export']],
    'inventori' => ['label' => 'Inventori', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve']],
    'stok_opname' => ['label' => 'Stok Opname', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve']],
    'customers' => ['label' => 'Pelanggan', 'actions' => ['view', 'create', 'edit', 'delete', 'export']],
    'suppliers' => ['label' => 'Supplier', 'actions' => ['view', 'create', 'edit', 'delete', 'export']],
    'purchase' => ['label' => 'Pembelian Bahan Baku', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve']],
    'users' => ['label' => 'Manajemen User', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
    'roles' => ['label' => 'Role & Permission', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
    'settings' => ['label' => 'Pengaturan', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve']],
  ];
}

function seed_default_role_permissions(): void {
  $menuDefaults = [
    'owner' => array_keys(role_menu_tree()),
    'admin' => ['dashboard', 'pos', 'sales', 'produk', 'inventori', 'stok_opname', 'customers', 'suppliers', 'purchase', 'users', 'settings'],
    'manager' => ['dashboard', 'sales', 'inventori', 'stok_opname', 'customers', 'purchase'],
    'kasir' => ['pos'],
    'gudang' => ['inventori', 'stok_opname', 'purchase'],
  ];
  foreach ($menuDefaults as $roleKey => $menus) {
    $roleId = role_id_by_key($roleKey);
    if ($roleId <= 0) continue;
    foreach (role_menu_tree() as $menuKey => $meta) {
      try {
        $stmt = db()->prepare("SELECT id FROM role_permissions WHERE role_id=? AND menu_key=? LIMIT 1");
        $stmt->execute([$roleId, $menuKey]);
        $exists = $stmt->fetch();
        if ($exists) {
          continue;
        }

        $allow = in_array($menuKey, $menus, true);
        $insert = db()->prepare("INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([
          $roleId,
          $menuKey,
          $allow ? 1 : 0,
          $allow ? 1 : 0,
          $allow ? 1 : 0,
          $allow ? 1 : 0,
          $allow ? 1 : 0,
          $allow ? 1 : 0,
          $allow ? 1 : 0,
        ]);
      } catch (Throwable $e) {}
    }
  }
}

function current_user_role_key(): string {
  start_secure_session();
  $u = $_SESSION['user'] ?? [];
  $resolved = resolve_user_role(is_array($u) ? $u : []);
  return (string)($resolved['role_key'] ?? '');
}

function current_user_is_owner(): bool {
  return current_user_role_key() === 'owner';
}

function has_role_permission(int $roleId, string $menuKey, string $action = 'view'): bool {
  $allowedActions = ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve'];
  if (!in_array($action, $allowedActions, true)) $action = 'view';
  $col = 'can_' . $action;
  try {
    $stmt = db()->prepare("SELECT MAX({$col}) AS allowed FROM role_permissions WHERE role_id=? AND menu_key=?");
    $stmt->execute([$roleId, $menuKey]);
    $row = $stmt->fetch();
    return (int)($row['allowed'] ?? 0) === 1;
  } catch (Throwable $e) {
    return false;
  }
}

function has_menu_access(array $user, string $menuKey, string $action = 'view'): bool {
  ensure_rbac_schema();
  $resolved = resolve_user_role($user);
  $roleKey = (string)($resolved['role_key'] ?? '');
  if ($roleKey === 'owner') return true;

  $aliasMap = [
    'admin' => 'dashboard',
  ];
  $menuKey = $aliasMap[$menuKey] ?? $menuKey;

  $roleId = (int)($resolved['role_id'] ?? 0);
  if ($roleId <= 0) return false;

  if (has_role_permission($roleId, $menuKey, $action)) {
    return true;
  }

  $fallbackMenu = [
    'dashboard' => ['sales', 'produk', 'inventori', 'stok_opname', 'users', 'settings'],
    'settings' => ['users', 'roles'],
    'sales' => ['dashboard'],
  ];
  foreach ($fallbackMenu[$menuKey] ?? [] as $candidate) {
    if (has_role_permission($roleId, $candidate, $action)) return true;
  }
  return false;
}

function get_menu_landing_order(): array {
  return [
    ['menu' => 'dashboard', 'url' => base_url('admin/dashboard.php')],
    ['menu' => 'pos', 'url' => base_url('pos/index.php')],
    ['menu' => 'sales', 'url' => base_url('admin/sales.php')],
    ['menu' => 'produk', 'url' => base_url('admin/products.php')],
    ['menu' => 'inventori', 'url' => base_url('admin/stocks.php')],
    ['menu' => 'stok_opname', 'url' => base_url('admin/stock_opname.php')],
    ['menu' => 'users', 'url' => base_url('admin/users.php')],
    ['menu' => 'roles', 'url' => base_url('admin/roles.php')],
    ['menu' => 'settings', 'url' => base_url('admin/store.php')],
  ];
}

function resolve_default_landing_page_for_user(array $user): string {
  if (has_menu_access($user, 'dashboard')) {
    return base_url('admin/dashboard.php');
  }

  $resolved = resolve_user_role($user);
  $roleKey = (string)($resolved['role_key'] ?? '');
  if ($roleKey === 'kasir' && has_menu_access($user, 'pos')) {
    return base_url('pos/index.php');
  }

  foreach (get_menu_landing_order() as $item) {
    if (has_menu_access($user, $item['menu'])) {
      return $item['url'];
    }
  }

  if ($roleKey === 'kasir') {
    return base_url('pos/index.php');
  }
  return base_url('admin/access_unconfigured.php');
}

function redirect_to_best_allowed_page(array $user, string $reason = 'forbidden'): void {
  $target = resolve_default_landing_page_for_user($user);
  if (strpos($target, 'access_unconfigured.php') !== false) {
    redirect(base_url('admin/access_unconfigured.php?reason=' . urlencode($reason)));
  }
  redirect($target);
}

function require_menu_access(string $menuKey, string $action = 'view'): array {
  require_once __DIR__ . '/auth.php';
  require_admin();
  $u = current_user() ?? [];
  if (!has_menu_access($u, $menuKey, $action)) {
    redirect_to_best_allowed_page($u, 'menu:' . $menuKey . ':' . $action);
  }
  return $u;
}

function require_action_access(string $menuKey, string $action): array {
  return require_menu_access($menuKey, $action);
}
