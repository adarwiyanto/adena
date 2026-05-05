<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/rbac.php';

/**
 * Guard ringan untuk portal Cabang/Dapur.
 * Tidak memanggil ensure_adena14_schema(), supaya halaman portal tidak memicu
 * auto-migration saat dibuka dan tidak menyebabkan HTTP 500 di hosting live.
 */
function portal_light_area_guard(string $area): array {
  start_secure_session();
  require_admin();
  $u = current_user() ?: [];

  $role = '';
  try {
    $resolved = resolve_user_role($u);
    $role = (string)($resolved['role_key'] ?? '');
  } catch (Throwable $e) {
    $role = (string)($u['role_key'] ?? ($u['role'] ?? ''));
  }

  if (in_array($role, ['owner', 'admin'], true) || current_user_is_owner()) {
    $_SESSION['adena_portal_type'] = $area === 'kitchen' ? 'kitchen' : ($area === 'branch' ? 'branch' : 'admin');
    return $u;
  }

  if ($area === 'kitchen') {
    if (has_menu_access($u, 'kitchen_page') || has_menu_access($u, 'inventori') || has_menu_access($u, 'stok_opname')) {
      $_SESSION['adena_portal_type'] = 'kitchen';
      return $u;
    }
    http_response_code(403);
    echo '403 - Tidak diizinkan mengakses portal dapur.';
    exit;
  }

  if ($area === 'branch') {
    if (has_menu_access($u, 'branch_page')) {
      $_SESSION['adena_portal_type'] = 'branch';
      return $u;
    }
    http_response_code(403);
    echo '403 - Tidak diizinkan mengakses portal cabang.';
    exit;
  }

  return $u;
}
