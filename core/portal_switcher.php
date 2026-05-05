<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/branch_portal.php';

function adena_portal_flash(?string $message = null, string $type = 'error'): ?array {
  start_secure_session();
  if ($message !== null) {
    $_SESSION['portal_flash'] = ['message' => $message, 'type' => $type];
    return null;
  }
  $flash = $_SESSION['portal_flash'] ?? null;
  unset($_SESSION['portal_flash']);
  return is_array($flash) ? $flash : null;
}

function adena_portal_is_admin_like(array $u): bool {
  $resolved = resolve_user_role($u);
  return in_array((string)($resolved['role_key'] ?? ''), ['owner', 'admin'], true);
}

function adena_portal_can_access_kitchen(array $u): bool {
  if (adena_portal_is_admin_like($u)) return true;
  return has_menu_access($u, 'kitchen_page') || has_menu_access($u, 'inventori') || has_menu_access($u, 'stok_opname');
}

function adena_portal_can_access_branch(array $u, int $branchId): bool {
  if ($branchId <= 0) return false;
  if (adena_portal_is_admin_like($u)) return true;
  if (!has_menu_access($u, 'branch_page')) return false;
  return in_array($branchId, branch_portal_user_branch_ids($u), true);
}

function adena_portal_options(array $u): array {
  ensure_branch_portal_schema();
  $options = [];
  $canAdmin = has_menu_access($u, 'dashboard') || adena_portal_is_admin_like($u);
  $options[] = [
    'value' => 'admin',
    'label' => 'Admin Pusat',
    'type' => 'admin',
    'allowed' => $canAdmin,
    'url' => base_url('admin/dashboard.php'),
  ];

  foreach (branch_portal_all_branches(true) as $b) {
    $bid = (int)($b['id'] ?? 0);
    $allowed = adena_portal_can_access_branch($u, $bid);
    $options[] = [
      'value' => 'branch:' . $bid,
      'label' => 'Cabang - ' . (string)($b['branch_name'] ?? ('ID ' . $bid)),
      'type' => 'branch',
      'branch_id' => $bid,
      'allowed' => $allowed,
      'url' => base_url('cabang/dashboard.php?branch_id=' . $bid),
    ];
  }

  $options[] = [
    'value' => 'kitchen',
    'label' => 'Dapur Produksi',
    'type' => 'kitchen',
    'allowed' => adena_portal_can_access_kitchen($u),
    'url' => base_url('kitchen/index.php'),
  ];
  return $options;
}

function adena_portal_current_value(): string {
  $path = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
  if (strpos($path, '/kitchen/') !== false) return 'kitchen';
  if (strpos($path, '/cabang/') !== false) {
    start_secure_session();
    $bid = (int)($_SESSION['active_branch_id'] ?? 0);
    if ($bid > 0) return 'branch:' . $bid;
    return 'branch:1';
  }
  if (strpos($path, '/branch/') !== false) return 'branch:1';
  return 'admin';
}

function adena_portal_switch(array $u, string $target): string {
  $target = trim($target);
  if ($target === 'admin') {
    if (!(has_menu_access($u, 'dashboard') || adena_portal_is_admin_like($u))) {
      throw new Exception('Akses ke halaman Admin tidak diizinkan.');
    }
    return base_url('admin/dashboard.php');
  }
  if ($target === 'kitchen') {
    if (!adena_portal_can_access_kitchen($u)) {
      throw new Exception('Akses ke halaman Dapur tidak diizinkan.');
    }
    return base_url('kitchen/index.php');
  }
  if (preg_match('/^branch:(\d+)$/', $target, $m)) {
    $branchId = (int)$m[1];
    if (!adena_portal_can_access_branch($u, $branchId)) {
      throw new Exception('Akses ke cabang ini tidak diizinkan.');
    }
    branch_portal_set_active_branch($u, $branchId);
    return base_url('cabang/dashboard.php');
  }
  throw new Exception('Pilihan halaman tidak valid.');
}
