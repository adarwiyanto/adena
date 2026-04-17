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
    $hasRoleId = (bool)db()->query("SHOW COLUMNS FROM users LIKE 'role_id'")->fetch();
    if (!$hasRoleId) {
      db()->exec("ALTER TABLE users ADD COLUMN role_id INT NULL AFTER role");
      try { db()->exec("ALTER TABLE users ADD KEY idx_role_id (role_id)"); } catch (Throwable $e) {}
    }
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
    'pegawai' => 'kasir',
    'user' => 'kasir',
    '' => 'kasir',
  ];
  try {
    $users = db()->query("SELECT id, role, role_id FROM users")->fetchAll();
    foreach ($users as $u) {
      $existingRole = strtolower(trim((string)($u['role'] ?? '')));
      $targetRoleKey = $roleMap[$existingRole] ?? 'kasir';
      $roleId = role_id_by_key($targetRoleKey);
      if ($roleId <= 0) continue;
      $stmt = db()->prepare("UPDATE users SET role_id=?, role=? WHERE id=?");
      $stmt->execute([$roleId, $targetRoleKey, (int)$u['id']]);
    }
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

function role_menu_tree(): array {
  return [
    'pos' => ['label' => 'POS', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve']],
    'admin' => ['label' => 'Admin', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
    'produk' => ['label' => 'Produk', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export']],
    'inventori' => ['label' => 'Inventori', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve']],
    'stok_opname' => ['label' => 'Stok Opname', 'actions' => ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve']],
  ];
}

function seed_default_role_permissions(): void {
  $menuDefaults = [
    'owner' => ['pos', 'admin', 'produk', 'inventori', 'stok_opname'],
    'admin' => ['pos', 'produk', 'inventori', 'stok_opname'],
    'manager' => ['stok_opname'],
    'kasir' => ['pos'],
    'gudang' => ['inventori', 'stok_opname'],
  ];
  foreach ($menuDefaults as $roleKey => $menus) {
    $roleId = role_id_by_key($roleKey);
    if ($roleId <= 0) continue;
    foreach (role_menu_tree() as $menuKey => $meta) {
      $allow = in_array($menuKey, $menus, true);
      try {
        $stmt = db()->prepare("INSERT INTO role_permissions (role_id, menu_key, can_view, can_create, can_edit, can_delete, can_print, can_export, can_approve)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_create=VALUES(can_create), can_edit=VALUES(can_edit),
          can_delete=VALUES(can_delete), can_print=VALUES(can_print), can_export=VALUES(can_export), can_approve=VALUES(can_approve)");
        $stmt->execute([
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
  $role = strtolower(trim((string)($u['role'] ?? '')));
  if ($role === 'superadmin') return 'owner';
  if ($role === 'pegawai') return 'kasir';
  if ($role === 'user') return 'kasir';
  return $role;
}

function current_user_is_owner(): bool {
  return current_user_role_key() === 'owner';
}

function has_role_permission(int $roleId, string $menuKey, string $action = 'view'): bool {
  $allowedActions = ['view', 'create', 'edit', 'delete', 'print', 'export', 'approve'];
  if (!in_array($action, $allowedActions, true)) $action = 'view';
  $col = 'can_' . $action;
  try {
    $stmt = db()->prepare("SELECT {$col} AS allowed FROM role_permissions WHERE role_id=? AND menu_key=? LIMIT 1");
    $stmt->execute([$roleId, $menuKey]);
    $row = $stmt->fetch();
    return (int)($row['allowed'] ?? 0) === 1;
  } catch (Throwable $e) {
    return false;
  }
}

function has_menu_access(array $user, string $menuKey, string $action = 'view'): bool {
  ensure_rbac_schema();
  $role = strtolower(trim((string)($user['role'] ?? '')));
  if ($role === 'superadmin' || $role === 'owner') return true;
  $roleId = (int)($user['role_id'] ?? 0);
  if ($roleId <= 0 && $role !== '') {
    $roleId = role_id_by_key($role);
  }
  if ($roleId <= 0) return false;
  return has_role_permission($roleId, $menuKey, $action);
}

function require_menu_access(string $menuKey, string $action = 'view'): array {
  require_once __DIR__ . '/auth.php';
  require_admin();
  $u = current_user() ?? [];
  if (!has_menu_access($u, $menuKey, $action)) {
    http_response_code(403);
    exit('Forbidden');
  }
  return $u;
}
