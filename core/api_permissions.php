<?php
require_once __DIR__ . '/db.php';

function api_permission_catalog(): array {
  return [
    '*' => ['group' => 'Semua', 'label' => 'Semua akses API'],
    'master.view' => ['group' => 'Master Data', 'label' => 'Lihat master data'],
    'categories.view' => ['group' => 'Jenis Produk', 'label' => 'Lihat jenis produk'],
    'categories.import' => ['group' => 'Jenis Produk', 'label' => 'Impor/tambah jenis produk'],
    'categories.edit' => ['group' => 'Jenis Produk', 'label' => 'Edit jenis produk'],
    'products.view' => ['group' => 'Produk', 'label' => 'Lihat produk'],
    'products.import' => ['group' => 'Produk', 'label' => 'Impor/tambah produk'],
    'products.edit' => ['group' => 'Produk', 'label' => 'Edit produk'],
    'sales.view' => ['group' => 'Penjualan', 'label' => 'Lihat transaksi penjualan'],
    'sales.push' => ['group' => 'Penjualan', 'label' => 'Kirim/impor transaksi penjualan'],
    'purchases.view' => ['group' => 'Pembelian', 'label' => 'Lihat transaksi pembelian'],
    'purchases.push' => ['group' => 'Pembelian', 'label' => 'Kirim/impor transaksi pembelian'],
    'stocks.view' => ['group' => 'Stok', 'label' => 'Lihat stok'],
    'stocks.adjust' => ['group' => 'Stok', 'label' => 'Edit/adjustment stok'],
    'stocks.opname' => ['group' => 'Stok', 'label' => 'Stok opname'],
    'transfers.view' => ['group' => 'Transfer Stok', 'label' => 'Lihat transfer stok'],
    'transfers.create' => ['group' => 'Transfer Stok', 'label' => 'Buat transfer stok'],
    'transfers.receive' => ['group' => 'Transfer Stok', 'label' => 'Terima transfer stok'],
    'stock_transfer' => ['group' => 'API Dapur', 'label' => 'Transfer stok dari dapur ke toko'],
    'stock_return' => ['group' => 'API Dapur', 'label' => 'Pengembalian stok dari toko ke dapur'],
    'users.view' => ['group' => 'User', 'label' => 'Lihat user'],
    'users.sync' => ['group' => 'User', 'label' => 'Sinkron user'],
    'logs.view' => ['group' => 'Log API', 'label' => 'Lihat log API'],
  ];
}

function api_permission_groups(): array {
  $groups = [];
  foreach (api_permission_catalog() as $key => $meta) {
    if ($key === '*') continue;
    $groups[$meta['group']][$key] = $meta['label'];
  }
  return $groups;
}

function api_default_permissions(string $type): array {
  $type = strtolower(trim($type));
  if (in_array($type, ['desktop','kasir_desktop','pos_desktop'], true)) {
    // Aman untuk Kasir Desktop: mempertahankan akses yang biasa dipakai POS desktop.
    return ['master.view','categories.view','products.view','sales.view','sales.push','stocks.view','users.view'];
  }
  if (in_array($type, ['branch','antar_cabang','read_write','receiver','sender'], true)) {
    // Antar cabang default boleh baca dan tulis.
    return ['master.view','categories.view','categories.import','categories.edit','products.view','products.import','products.edit','sales.view','sales.push','purchases.view','purchases.push','stocks.view','stocks.adjust','stocks.opname','transfers.view','transfers.create','transfers.receive','users.view','users.sync','logs.view'];
  }
  if (in_array($type, ['kitchen','dapur','api_dapur'], true)) {
    return ['master.view','products.view','stocks.view','transfers.view','transfers.receive','stock_transfer','stock_return','logs.view'];
  }
  if (in_array($type, ['external','situs_lain','readonly','read_only'], true)) {
    // Situs lain hanya lihat. Tidak ada push/edit/transfer/adjustment.
    return ['master.view','categories.view','products.view','stocks.view'];
  }
  return ['master.view','categories.view','products.view','stocks.view'];
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

function ensure_api_settings_schema(): void {
  $pdo = db();
  if (function_exists('ensure_api_tokens_table')) {
    ensure_api_tokens_table();
  } else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      token_hash VARCHAR(255) NOT NULL,
      device_code VARCHAR(40) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      last_used_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      revoked_at DATETIME NULL,
      INDEX idx_api_tokens_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }

  $cols = [
    'branch_id' => "ALTER TABLE api_tokens ADD COLUMN branch_id INT NULL AFTER device_code",
    'token_plain' => "ALTER TABLE api_tokens ADD COLUMN token_plain TEXT NULL AFTER branch_id",
    'api_type' => "ALTER TABLE api_tokens ADD COLUMN api_type VARCHAR(50) NULL AFTER token_plain",
    'client_type' => "ALTER TABLE api_tokens ADD COLUMN client_type VARCHAR(30) NULL AFTER api_type",
    'api_mode' => "ALTER TABLE api_tokens ADD COLUMN api_mode VARCHAR(20) NOT NULL DEFAULT 'sender' AFTER client_type",
    'unit_code' => "ALTER TABLE api_tokens ADD COLUMN unit_code VARCHAR(40) NULL AFTER api_mode",
    'remote_base_url' => "ALTER TABLE api_tokens ADD COLUMN remote_base_url VARCHAR(255) NULL AFTER unit_code",
    'remote_token' => "ALTER TABLE api_tokens ADD COLUMN remote_token TEXT NULL AFTER remote_base_url",
    'permissions' => "ALTER TABLE api_tokens ADD COLUMN permissions TEXT NULL AFTER remote_token",
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

  try { $pdo->exec("UPDATE api_tokens SET api_mode='sender' WHERE api_mode IS NULL OR api_mode='' "); } catch (Throwable $e) {}
  try { $pdo->exec("UPDATE api_tokens SET client_type='desktop' WHERE (client_type IS NULL OR client_type='') AND (api_type IS NULL OR api_type='' OR api_type='desktop') "); } catch (Throwable $e) {}
  try { $pdo->exec("UPDATE api_tokens SET permissions='[\"master.view\",\"categories.view\",\"products.view\",\"sales.view\",\"sales.push\",\"stocks.view\",\"users.view\"]' WHERE permissions IS NULL OR permissions='' "); } catch (Throwable $e) {}
}
