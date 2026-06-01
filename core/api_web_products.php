<?php
/**
 * Helper khusus API antar website / impor produk.
 * Sengaja DIPISAH dari api/helpers.php agar API POS Desktop tidak terganggu.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function ensure_web_product_api_schema(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;
  $pdo = db();

  $pdo->exec("CREATE TABLE IF NOT EXISTS api_web_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    token_plain TEXT NULL,
    permissions TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    INDEX idx_api_web_tokens_active (is_active)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $pdo->exec("CREATE TABLE IF NOT EXISTS api_remote_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    token TEXT NOT NULL,
    permissions TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_test_at DATETIME NULL,
    last_test_status VARCHAR(30) NULL,
    last_test_message TEXT NULL,
    last_sync_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_api_remote_active (is_active)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $pdo->exec("CREATE TABLE IF NOT EXISTS product_import_mappings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    connection_id INT NOT NULL,
    remote_base_url VARCHAR(255) NOT NULL,
    remote_product_id INT NOT NULL,
    remote_hash VARCHAR(64) NULL,
    local_product_id INT NOT NULL,
    last_imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_remote_product (connection_id, remote_product_id),
    INDEX idx_local_product (local_product_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $pdo->exec("CREATE TABLE IF NOT EXISTS product_import_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    connection_id INT NULL,
    import_status VARCHAR(30) NOT NULL DEFAULT 'success',
    total_new INT NOT NULL DEFAULT 0,
    total_updated INT NOT NULL DEFAULT 0,
    total_skipped INT NOT NULL DEFAULT 0,
    total_conflict INT NOT NULL DEFAULT 0,
    message TEXT NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_import_logs_connection (connection_id, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  // Kolom produk yang dibutuhkan tetap mengikuti inventory schema existing.
  if (function_exists('ensure_products_category_column')) ensure_products_category_column();
  if (function_exists('ensure_product_categories_table')) ensure_product_categories_table();
}

function web_product_api_json(array $data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function web_product_api_ok(array $data = []): void {
  web_product_api_json(array_merge(['ok' => true], $data));
}

function web_product_api_err(string $message, int $status = 400): void {
  web_product_api_json(['ok' => false, 'message' => $message], $status);
}

function web_product_api_bearer_token(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if ($h === '' && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    foreach ($headers as $k => $v) {
      if (strtolower((string)$k) === 'authorization') {
        $h = (string)$v;
        break;
      }
    }
  }
  if (preg_match('/^Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
  return null;
}

function web_product_token_permissions(array $row): array {
  $raw = (string)($row['permissions'] ?? '');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

function require_web_product_api_token(string $permission): array {
  ensure_web_product_api_schema();
  $input = web_product_api_bearer_token();
  if (!$input || strlen($input) < 20) web_product_api_err('API token antar website tidak valid.', 401);

  $rows = db()->query('SELECT id, name, token_hash, permissions FROM api_web_tokens WHERE is_active = 1 ORDER BY id DESC')
    ->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $row) {
    if (password_verify($input, (string)$row['token_hash'])) {
      $perms = web_product_token_permissions($row);
      if (!in_array($permission, $perms, true)) {
        web_product_api_err('Permission API tidak mencukupi: ' . $permission, 403);
      }
      db()->prepare('UPDATE api_web_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
      return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'permissions' => $perms,
      ];
    }
  }
  web_product_api_err('API token antar website tidak valid.', 401);
}

function normalize_remote_base_url(string $url): string {
  $url = trim($url);
  $url = preg_replace('~\s+~', '', $url) ?? $url;
  $url = rtrim($url, '/');
  if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
  return $url;
}

function remote_api_url(string $baseUrl, string $path): string {
  return rtrim(normalize_remote_base_url($baseUrl), '/') . '/' . ltrim($path, '/');
}

function remote_api_fetch_json(string $baseUrl, string $token, string $path, int $timeout = 20): array {
  $url = remote_api_url($baseUrl, $path);
  $headers = "Authorization: Bearer " . trim($token) . "\r\n" .
             "Accept: application/json\r\n" .
             "User-Agent: Adena-Web-Product-Importer/1.0\r\n";
  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'header' => $headers,
      'timeout' => $timeout,
      'ignore_errors' => true,
    ],
    'ssl' => [
      'verify_peer' => true,
      'verify_peer_name' => true,
    ],
  ]);
  $raw = @file_get_contents($url, false, $ctx);
  $status = 0;
  if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $line) {
      if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) {
        $status = (int)$m[1];
        break;
      }
    }
  }
  if ($raw === false) {
    throw new RuntimeException('Gagal menghubungi endpoint: ' . $url);
  }
  $json = json_decode($raw, true);
  if (!is_array($json)) {
    throw new RuntimeException('Respons endpoint bukan JSON valid. HTTP ' . $status);
  }
  if ($status >= 400 || empty($json['ok'])) {
    $msg = (string)($json['message'] ?? ('HTTP ' . $status));
    throw new RuntimeException($msg);
  }
  return $json;
}

function web_product_remote_hash(array $product): string {
  $keys = ['remote_id','name','category','price','product_type','track_stock','allow_direct_purchase','allow_bom','show_on_pos','show_on_landing','base_unit','purchase_unit','purchase_to_base_factor','sale_unit','sale_to_base_factor','image_url','updated_at'];
  $payload = [];
  foreach ($keys as $k) $payload[$k] = $product[$k] ?? null;
  return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function web_product_safe_bool($value, int $default = 0): int {
  if ($value === null || $value === '') return $default;
  return ((int)$value) ? 1 : 0;
}

function web_product_safe_decimal($value, float $default = 0): float {
  if (is_numeric($value)) return (float)$value;
  return $default;
}

function web_product_download_image(string $imageUrl, string $token): ?string {
  if ($imageUrl === '') return null;
  require_once __DIR__ . '/../config/upload.php';
  $headers = "Authorization: Bearer " . trim($token) . "\r\n" .
             "Accept: image/jpeg,image/png,*/*\r\n" .
             "User-Agent: Adena-Web-Product-Importer/1.0\r\n";
  $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => $headers, 'timeout' => 20, 'ignore_errors' => true]]);
  $raw = @file_get_contents($imageUrl, false, $ctx);
  if ($raw === false || strlen($raw) < 16 || strlen($raw) > 2 * 1024 * 1024) return null;
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->buffer($raw);
  $ext = null;
  if ($mime === 'image/jpeg') $ext = 'jpg';
  if ($mime === 'image/png') $ext = 'png';
  if (!$ext) return null;
  $name = bin2hex(random_bytes(16)) . '.' . $ext;
  $dest = UPLOAD_IMG . $name;
  if (@file_put_contents($dest, $raw) === false) return null;
  return $name;
}

function web_product_options_from_remote(array $r, array $options): array {
  return [
    'name' => trim((string)($r['name'] ?? '')),
    'category' => trim((string)($r['category'] ?? '')),
    'price' => !empty($options['overwrite_price']) ? web_product_safe_decimal($r['price'] ?? 0) : null,
    'product_type' => in_array((string)($r['product_type'] ?? ''), ['finished_good','raw_material','service'], true) ? (string)$r['product_type'] : 'finished_good',
    'track_stock' => web_product_safe_bool($r['track_stock'] ?? 1, 1),
    'allow_direct_purchase' => web_product_safe_bool($r['allow_direct_purchase'] ?? 0, 0),
    'allow_bom' => web_product_safe_bool($r['allow_bom'] ?? 0, 0),
    'show_on_pos' => !empty($options['overwrite_visibility']) ? web_product_safe_bool($r['show_on_pos'] ?? 1, 1) : null,
    'show_on_landing' => !empty($options['overwrite_visibility']) ? web_product_safe_bool($r['show_on_landing'] ?? 1, 1) : null,
    'base_unit' => trim((string)($r['base_unit'] ?? 'pcs')) ?: 'pcs',
    'purchase_unit' => trim((string)($r['purchase_unit'] ?? ($r['base_unit'] ?? 'pcs'))) ?: 'pcs',
    'purchase_to_base_factor' => max(0.000001, web_product_safe_decimal($r['purchase_to_base_factor'] ?? 1, 1)),
    'sale_unit' => trim((string)($r['sale_unit'] ?? ($r['base_unit'] ?? 'pcs'))) ?: 'pcs',
    'sale_to_base_factor' => max(0.000001, web_product_safe_decimal($r['sale_to_base_factor'] ?? 1, 1)),
  ];
}

function web_product_log_import(?int $connectionId, string $status, int $new, int $updated, int $skipped, int $conflict, string $message = ''): void {
  try {
    db()->prepare('INSERT INTO product_import_logs (connection_id, import_status, total_new, total_updated, total_skipped, total_conflict, message, created_by) VALUES (?,?,?,?,?,?,?,?)')
      ->execute([$connectionId, $status, $new, $updated, $skipped, $conflict, $message, (int)($_SESSION['user_id'] ?? 0)]);
  } catch (Throwable $e) {}
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
  http_response_code(204);
  exit;
}
