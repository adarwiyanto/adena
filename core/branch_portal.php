<?php
/**
 * Helper cabang/dapur & harga per cabang.
 * Patch aman: hanya menambah helper, tidak mengubah print POS Desktop.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (!function_exists('table_exists')) {
  function table_exists(string $table): bool {
    try {
      $stmt = db()->prepare("SHOW TABLES LIKE ?");
      $stmt->execute([$table]);
      return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('column_exists')) {
  function column_exists(string $table, string $column): bool {
    try {
      $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
      $stmt->execute([$column]);
      return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

function ensure_branch_price_schema(): void {
  $db = db();
  if (table_exists('api_tokens') && !column_exists('api_tokens', 'branch_id')) {
    try { $db->exec("ALTER TABLE api_tokens ADD COLUMN branch_id INT NULL AFTER device_code"); } catch (Throwable $e) {}
  }
  if (table_exists('sales') && !column_exists('sales', 'branch_id')) {
    try { $db->exec("ALTER TABLE sales ADD COLUMN branch_id INT NULL AFTER product_id"); } catch (Throwable $e) {}
  }
  if (table_exists('pos_shifts') && !column_exists('pos_shifts', 'branch_id')) {
    try { $db->exec("ALTER TABLE pos_shifts ADD COLUMN branch_id INT NULL AFTER shift_code"); } catch (Throwable $e) {}
  }
  $db->exec("CREATE TABLE IF NOT EXISTS branch_product_prices (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_branch_product_price (branch_id, product_id),
    KEY idx_bpp_product (product_id),
    KEY idx_bpp_active (branch_id, is_active)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_stock_locations_for_active_branches();
}

function ensure_stock_locations_for_active_branches(): void {
  if (!table_exists('branches') || !table_exists('stock_locations')) return;
  $db = db();
  $branches = $db->query("SELECT id, branch_code, branch_name FROM branches WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($branches as $b) {
    $bid = (int)$b['id'];
    $check = $db->prepare("SELECT id FROM stock_locations WHERE branch_id=? AND is_active=1 LIMIT 1");
    $check->execute([$bid]);
    if ($check->fetchColumn()) continue;
    $code = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', (string)($b['branch_code'] ?? '')));
    if ($code === '') $code = 'BR' . $bid;
    $locCode = 'TOKO-' . $code;
    $locName = 'Toko ' . (string)($b['branch_name'] ?? ('Cabang ' . $bid));
    try {
      $ins = $db->prepare("INSERT INTO stock_locations (location_code, location_name, location_type, branch_id, is_active) VALUES (?, ?, 'branch', ?, 1)");
      $ins->execute([$locCode, $locName, $bid]);
    } catch (Throwable $e) {}
  }
}

function branch_product_price(int $branchId, int $productId, float $defaultPrice): float {
  if ($branchId <= 0 || $productId <= 0 || !table_exists('branch_product_prices')) return $defaultPrice;
  try {
    $stmt = db()->prepare("SELECT price FROM branch_product_prices WHERE branch_id=? AND product_id=? AND is_active=1 LIMIT 1");
    $stmt->execute([$branchId, $productId]);
    $v = $stmt->fetchColumn();
    return $v === false ? $defaultPrice : (float)$v;
  } catch (Throwable $e) { return $defaultPrice; }
}

function branch_for_api_token(array $token): int {
  $branchId = (int)($token['branch_id'] ?? 0);
  if ($branchId > 0) return $branchId;
  $code = strtoupper(trim((string)($token['device_code'] ?? '')));
  if ($code !== '' && table_exists('branches')) {
    try {
      $stmt = db()->prepare("SELECT id FROM branches WHERE UPPER(branch_code)=? AND is_active=1 LIMIT 1");
      $stmt->execute([$code]);
      $id = (int)$stmt->fetchColumn();
      if ($id > 0) return $id;
    } catch (Throwable $e) {}
  }
  return (int)setting('active_branch_id', '1');
}

function current_user_branch_ids(): array {
  $u = function_exists('current_user') ? current_user() : null;
  if (!$u || empty($u['id']) || !table_exists('user_branches')) return [];
  try {
    $stmt = db()->prepare("SELECT branch_id FROM user_branches WHERE user_id=? ORDER BY branch_id");
    $stmt->execute([(int)$u['id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
  } catch (Throwable $e) { return []; }
}

function require_portal_branch_id(): int {
  require_once __DIR__ . '/auth.php';
  require_once __DIR__ . '/rbac.php';
  start_secure_session();
  require_login();
  ensure_branch_price_schema();
  $u = current_user() ?: [];
  $role = (string)(resolve_user_role($u)['role_key'] ?? ($u['role'] ?? ''));
  $requested = (int)($_GET['branch_id'] ?? $_SESSION['portal_branch_id'] ?? 0);
  if (in_array($role, ['owner','admin'], true)) {
    if ($requested <= 0) $requested = (int)setting('active_branch_id', '1');
    $_SESSION['portal_branch_id'] = $requested;
    return $requested;
  }
  $ids = current_user_branch_ids();
  if (empty($ids)) {
    http_response_code(403);
    exit('Akses cabang belum dikonfigurasi untuk user ini. Hubungi owner/admin.');
  }
  if ($requested <= 0 || !in_array($requested, $ids, true)) $requested = $ids[0];
  $_SESSION['portal_branch_id'] = $requested;
  return $requested;
}

function portal_branch_row(int $branchId): array {
  if (!table_exists('branches')) return ['id'=>$branchId, 'branch_name'=>'Cabang'];
  $stmt = db()->prepare("SELECT * FROM branches WHERE id=? LIMIT 1");
  $stmt->execute([$branchId]);
  return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$branchId, 'branch_name'=>'Cabang'];
}

function portal_inventory_branch_location_id(int $branchId): int {
  ensure_branch_price_schema();
  if (!table_exists('stock_locations')) return 0;
  $stmt = db()->prepare("SELECT id FROM stock_locations WHERE branch_id=? AND is_active=1 ORDER BY FIELD(location_type,'branch','store','kitchen'), id ASC LIMIT 1");
  $stmt->execute([$branchId]);
  return (int)($stmt->fetchColumn() ?: 0);
}

/*
 * HOTFIX 1.4.3 portal compatibility helpers.
 * Beberapa file admin/portal lama memanggil nama fungsi branch_portal_*.
 * Helper ini dibuat sebagai adapter aman ke fungsi baru agar tidak fatal error 500.
 */
if (!function_exists('ensure_branch_portal_schema')) {
  function ensure_branch_portal_schema(): void {
    ensure_branch_price_schema();
    $db = db();
    if (table_exists('user_branches')) return;
    try {
      $db->exec("CREATE TABLE IF NOT EXISTS user_branches (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        branch_id INT NOT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_branch (user_id, branch_id),
        KEY idx_user_branches_user (user_id),
        KEY idx_user_branches_branch (branch_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
  }
}

if (!function_exists('branch_portal_all_branches')) {
  function branch_portal_all_branches(bool $activeOnly = true): array {
    ensure_branch_portal_schema();
    if (!table_exists('branches')) return [];
    try {
      $sql = "SELECT * FROM branches" . ($activeOnly ? " WHERE is_active=1" : "") . " ORDER BY branch_name ASC, id ASC";
      return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('branch_portal_user_branch_ids')) {
  function branch_portal_user_branch_ids(?array $u = null): array {
    ensure_branch_portal_schema();
    if ($u === null) $u = function_exists('current_user') ? (current_user() ?: []) : [];
    $uid = (int)($u['id'] ?? 0);
    if ($uid <= 0 || !table_exists('user_branches')) return [];
    try {
      $stmt = db()->prepare("SELECT branch_id FROM user_branches WHERE user_id=? ORDER BY branch_id ASC");
      $stmt->execute([$uid]);
      return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('branch_portal_set_active_branch')) {
  function branch_portal_set_active_branch(?array $u, int $branchId): void {
    start_secure_session();
    $_SESSION['portal_branch_id'] = $branchId;
    $_SESSION['adena_portal_branch_id'] = $branchId;
    $_SESSION['active_branch_id'] = $branchId;
  }
}

if (!function_exists('branch_portal_active_branch_id')) {
  function branch_portal_active_branch_id(?array $u = null): int {
    start_secure_session();
    $bid = (int)($_GET['branch_id'] ?? $_SESSION['portal_branch_id'] ?? $_SESSION['adena_portal_branch_id'] ?? $_SESSION['active_branch_id'] ?? 0);
    if ($bid > 0) return $bid;
    $ids = branch_portal_user_branch_ids($u);
    if ($ids) return (int)$ids[0];
    return (int)setting('active_branch_id', '1');
  }
}

if (!function_exists('branch_portal_branch')) {
  function branch_portal_branch(int $branchId): array {
    return portal_branch_row($branchId);
  }
}

if (!function_exists('branch_portal_current_user')) {
  function branch_portal_current_user(): array {
    return function_exists('current_user') ? (current_user() ?: []) : [];
  }
}

