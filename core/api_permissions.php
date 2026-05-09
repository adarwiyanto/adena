<?php
require_once __DIR__ . '/db.php';

function api_permission_catalog(): array {
  return [
    'master.view' => ['group' => 'Master Data', 'label' => 'Lihat master data cabang/dapur/backoffice, payment, guide'],
    'categories.view' => ['group' => 'Jenis Produk', 'label' => 'Lihat jenis produk'],
    'categories.import' => ['group' => 'Jenis Produk', 'label' => 'Impor/tambah jenis produk'],
    'categories.edit' => ['group' => 'Jenis Produk', 'label' => 'Edit jenis produk'],
    'products.view' => ['group' => 'Produk', 'label' => 'Lihat produk'],
    'products.import' => ['group' => 'Produk', 'label' => 'Impor/tambah produk'],
    'products.edit' => ['group' => 'Produk', 'label' => 'Edit produk'],
    'sales.view' => ['group' => 'Penjualan', 'label' => 'Lihat transaksi penjualan'],
    'sales.push' => ['group' => 'Penjualan', 'label' => 'Push/impor transaksi penjualan'],
    'sales.revise' => ['group' => 'Penjualan', 'label' => 'Revisi transaksi penjualan'],
    'purchases.view' => ['group' => 'Pembelian', 'label' => 'Lihat transaksi pembelian'],
    'purchases.push' => ['group' => 'Pembelian', 'label' => 'Push/impor transaksi pembelian'],
    'purchases.revise' => ['group' => 'Pembelian', 'label' => 'Revisi transaksi pembelian'],
    'stocks.view' => ['group' => 'Stok', 'label' => 'Lihat stok'],
    'stocks.adjust' => ['group' => 'Stok', 'label' => 'Edit/adjustment stok'],
    'stocks.opname' => ['group' => 'Stok', 'label' => 'Stok opname'],
    'transfers.view' => ['group' => 'Transfer Stok', 'label' => 'Lihat transfer stok'],
    'transfers.create' => ['group' => 'Transfer Stok', 'label' => 'Buat transfer stok'],
    'transfers.receive' => ['group' => 'Transfer Stok', 'label' => 'Terima transfer stok'],
    'transfers.cancel' => ['group' => 'Transfer Stok', 'label' => 'Batalkan transfer stok'],
    'users.view' => ['group' => 'User', 'label' => 'Lihat user'],
    'users.sync' => ['group' => 'User', 'label' => 'Sinkron user'],
    'logs.view' => ['group' => 'Log API', 'label' => 'Lihat log API'],
  ];
}

function api_default_permissions(string $clientType): array {
  $clientType = strtolower(trim($clientType));
  if ($clientType === 'pos_desktop') {
    return ['master.view','categories.view','products.view','sales.view','sales.push','stocks.view','users.view'];
  }
  if ($clientType === 'branch') {
    return ['master.view','categories.view','products.view','sales.view','sales.push','stocks.view','transfers.view','transfers.receive','users.view'];
  }
  if ($clientType === 'kitchen') {
    return ['master.view','categories.view','products.view','purchases.view','stocks.view','stocks.adjust','transfers.view','transfers.create','transfers.receive'];
  }
  if ($clientType === 'backoffice') {
    return array_keys(api_permission_catalog());
  }
  if ($clientType === 'integration') {
    return ['master.view','categories.view','categories.import','products.view','products.import','sales.view','sales.push','purchases.view','purchases.push','stocks.view','transfers.view','users.view','users.sync'];
  }
  return ['master.view'];
}

function api_clean_permissions(array $permissions): array {
  $catalog = api_permission_catalog();
  $out = [];
  foreach ($permissions as $p) {
    $p = strtolower(trim((string)$p));
    if ($p !== '' && isset($catalog[$p])) $out[$p] = true;
  }
  return array_keys($out);
}

function api_permissions_encode(array $permissions): string {
  return json_encode(api_clean_permissions($permissions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function api_permissions_decode($raw): array {
  if (is_array($raw)) return api_clean_permissions($raw);
  $raw = trim((string)$raw);
  if ($raw === '') return [];
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) return api_clean_permissions($decoded);
  return api_clean_permissions(array_map('trim', explode(',', $raw)));
}

function api_token_has_permission(array $token, string $permission): bool {
  $permission = strtolower(trim($permission));
  if ($permission === '') return true;
  $perms = api_permissions_decode($token['permissions'] ?? '');
  return in_array($permission, $perms, true);
}

function ensure_api_v1_schema(): void {
  $pdo = db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    device_code VARCHAR(20) NULL,
    branch_id INT NULL,
    unit_code VARCHAR(40) NULL,
    token_plain TEXT NULL,
    client_type VARCHAR(30) NOT NULL DEFAULT 'pos_desktop',
    permissions TEXT NULL,
    allowed_ips TEXT NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    INDEX idx_api_tokens_active (is_active),
    INDEX idx_api_tokens_client_type (client_type),
    INDEX idx_api_tokens_branch_id (branch_id),
    INDEX idx_api_tokens_unit_code (unit_code)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $cols = [
    'device_code' => "ALTER TABLE api_tokens ADD COLUMN device_code VARCHAR(20) NULL AFTER token_hash",
    'branch_id' => "ALTER TABLE api_tokens ADD COLUMN branch_id INT NULL AFTER device_code",
    'unit_code' => "ALTER TABLE api_tokens ADD COLUMN unit_code VARCHAR(40) NULL AFTER branch_id",
    'token_plain' => "ALTER TABLE api_tokens ADD COLUMN token_plain TEXT NULL AFTER unit_code",
    'client_type' => "ALTER TABLE api_tokens ADD COLUMN client_type VARCHAR(30) NOT NULL DEFAULT 'pos_desktop' AFTER token_plain",
    'permissions' => "ALTER TABLE api_tokens ADD COLUMN permissions TEXT NULL AFTER client_type",
    'allowed_ips' => "ALTER TABLE api_tokens ADD COLUMN allowed_ips TEXT NULL AFTER permissions",
    'notes' => "ALTER TABLE api_tokens ADD COLUMN notes TEXT NULL AFTER allowed_ips",
  ];
  foreach ($cols as $name => $sql) {
    try {
      $st = $pdo->prepare("SHOW COLUMNS FROM api_tokens LIKE ?");
      $st->execute([$name]);
      if (!$st->fetch(PDO::FETCH_ASSOC)) $pdo->exec($sql);
    } catch (Throwable $e) {}
  }
  try { $pdo->exec("ALTER TABLE api_tokens ADD INDEX idx_api_tokens_client_type (client_type)"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE api_tokens ADD INDEX idx_api_tokens_branch_id (branch_id)"); } catch (Throwable $e) {}
  try { $pdo->exec("ALTER TABLE api_tokens ADD INDEX idx_api_tokens_unit_code (unit_code)"); } catch (Throwable $e) {}
  try { $pdo->exec("UPDATE api_tokens SET client_type='pos_desktop' WHERE client_type IS NULL OR client_type='' "); } catch (Throwable $e) {}
  try { $pdo->exec("UPDATE api_tokens SET unit_code=UPPER(device_code) WHERE (unit_code IS NULL OR unit_code='') AND device_code IS NOT NULL AND device_code<>'' "); } catch (Throwable $e) {}
  try { $pdo->exec("UPDATE api_tokens SET permissions='[\"master.view\",\"categories.view\",\"products.view\",\"sales.view\",\"sales.push\",\"stocks.view\",\"users.view\"]' WHERE permissions IS NULL OR permissions='' "); } catch (Throwable $e) {}

  $pdo->exec("CREATE TABLE IF NOT EXISTS api_request_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    token_id INT NULL,
    client_type VARCHAR(30) NULL,
    endpoint VARCHAR(190) NOT NULL,
    method VARCHAR(10) NOT NULL,
    permission VARCHAR(80) NULL,
    ip_address VARCHAR(64) NULL,
    status_code INT NULL,
    message VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_logs_token (token_id),
    INDEX idx_api_logs_created (created_at),
    INDEX idx_api_logs_endpoint (endpoint)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function api_log_request(?array $token, string $endpoint, string $method, ?string $permission, int $statusCode, string $message = ''): void {
  try {
    ensure_api_v1_schema();
    db()->prepare("INSERT INTO api_request_logs (token_id, client_type, endpoint, method, permission, ip_address, status_code, message) VALUES (?,?,?,?,?,?,?,?)")
      ->execute([
        $token['id'] ?? null,
        $token['client_type'] ?? null,
        substr($endpoint, 0, 190),
        substr($method, 0, 10),
        $permission,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
        $statusCode,
        substr($message, 0, 255),
      ]);
  } catch (Throwable $e) {}
}

function api_ip_allowed(array $token): bool {
  $raw = trim((string)($token['allowed_ips'] ?? ''));
  if ($raw === '') return true;
  $clientIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
  foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $ip) {
    $ip = trim($ip);
    if ($ip !== '' && $ip === $clientIp) return true;
  }
  return false;
}
