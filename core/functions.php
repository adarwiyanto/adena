<?php
function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function get_number_setting(string $key, $default = null) {
  $defaults = [
    'number_decimal_places_qty' => '2',
    'number_decimal_places_money' => '2',
    'number_decimal_separator' => '.',
    'number_thousand_separator' => ',',
    'number_trim_trailing_zero' => '0',
    'number_show_unit_after_qty' => '1',
  ];
  $fallback = array_key_exists($key, $defaults) ? $defaults[$key] : $default;
  return setting($key, $fallback);
}

function append_unit(string $formatted, ?string $unit): string {
  $unit = trim((string)$unit);
  if ($unit === '') return $formatted;
  return $formatted . ' ' . $unit;
}

function format_number_custom($value, $decimals = null, array $options = []): string {
  $decimals = $decimals !== null ? (int)$decimals : (int)get_number_setting('number_decimal_places_qty', 2);
  if ($decimals < 0) $decimals = 0;

  $decimalSeparator = (string)($options['decimal_separator'] ?? get_number_setting('number_decimal_separator', '.'));
  $thousandSeparator = (string)($options['thousand_separator'] ?? get_number_setting('number_thousand_separator', ','));
  $trimTrailing = array_key_exists('trim_trailing_zero', $options)
    ? (bool)$options['trim_trailing_zero']
    : ((string)get_number_setting('number_trim_trailing_zero', '0') === '1');

  $formatted = number_format((float)$value, $decimals, $decimalSeparator, $thousandSeparator);
  if ($trimTrailing && $decimals > 0) {
    $negative = strpos($formatted, '-') === 0;
    $raw = $negative ? substr($formatted, 1) : $formatted;
    $parts = explode($decimalSeparator, $raw, 2);
    if (count($parts) === 2) {
      $parts[1] = rtrim($parts[1], '0');
      $raw = $parts[0] . ($parts[1] !== '' ? $decimalSeparator . $parts[1] : '');
    }
    $formatted = $negative ? '-' . $raw : $raw;
  }
  return $formatted;
}

function format_qty($value, $unit = null, array $options = []): string {
  $decimals = array_key_exists('decimals', $options)
    ? (int)$options['decimals']
    : (int)get_number_setting('number_decimal_places_qty', 2);
  $formatted = format_number_custom($value, $decimals, $options);
  $showUnit = array_key_exists('show_unit', $options)
    ? (bool)$options['show_unit']
    : ((string)get_number_setting('number_show_unit_after_qty', '1') === '1');
  return $showUnit ? append_unit($formatted, (string)$unit) : $formatted;
}

function format_money($value, array $options = []): string {
  $decimals = array_key_exists('decimals', $options)
    ? (int)$options['decimals']
    : (int)get_number_setting('number_decimal_places_money', 2);
  return format_number_custom($value, $decimals, $options);
}

function parse_number_input($raw): float {
  if (is_numeric($raw)) return (float)$raw;
  $s = trim((string)$raw);
  if ($s === '') return 0.0;
  $decimalSeparator = (string)get_number_setting('number_decimal_separator', '.');
  $thousandSeparator = (string)get_number_setting('number_thousand_separator', ',');
  if ($thousandSeparator !== '') {
    $s = str_replace($thousandSeparator, '', $s);
  }
  if ($decimalSeparator !== '.') {
    $s = str_replace($decimalSeparator, '.', $s);
  }
  $s = str_replace([' ', ','], ['', '.'], $s);
  return is_numeric($s) ? (float)$s : 0.0;
}

function format_number_id($number, int $decimals = 1): string {
  return format_number_custom($number, $decimals, ['decimal_separator' => ',', 'thousand_separator' => '.']);
}

function product_unit_fallback(array $product): array {
  $base = trim((string)($product['base_unit'] ?? ''));
  if ($base === '') {
    $base = (($product['product_type'] ?? '') === 'raw_material') ? 'pcs' : 'pcs';
  }
  $purchase = trim((string)($product['purchase_unit'] ?? ''));
  if ($purchase === '') $purchase = $base;
  $sale = trim((string)($product['sale_unit'] ?? ''));
  if ($sale === '') $sale = $base;
  $purchaseFactor = (float)($product['purchase_to_base_factor'] ?? 1);
  if ($purchaseFactor <= 0) $purchaseFactor = 1.0;
  $saleFactor = (float)($product['sale_to_base_factor'] ?? 1);
  if ($saleFactor <= 0) $saleFactor = 1.0;
  return [
    'base_unit' => $base,
    'purchase_unit' => $purchase,
    'purchase_to_base_factor' => $purchaseFactor,
    'sale_unit' => $sale,
    'sale_to_base_factor' => $saleFactor,
  ];
}


function request_header(string $name): string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return trim((string)($_SERVER[$key] ?? ''));
}

function is_android_webview_request(): bool {
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
  if ($ua === '') return false;
  return stripos($ua, 'HOPePOSAndroidWebView') !== false
    || (stripos($ua, 'Android') !== false && stripos($ua, 'wv') !== false);
}

function is_android_app_request(): bool {
  if (is_android_webview_request()) return true;
  $marker = request_header('X-Hope-Android-App');
  return $marker === '1' || strtolower($marker) === 'true';
}

function base_url(string $path = ''): string {
  $cfg = app_config();
  $base = rtrim($cfg['app']['base_url'], '/');
  $path = ltrim($path, '/');
  return $path ? "{$base}/{$path}" : $base;
}

function app_cache_bust(): string {
  static $version = null;
  if ($version !== null) return $version;
  $version = function_exists('app_version') ? app_version() : (string)($_SERVER['REQUEST_TIME'] ?? time());
  return $version;
}

function asset_url(string $path = ''): string {
  $url = base_url($path);
  if ($path === '') return $url;
  $version = app_cache_bust();
  return "{$url}?v={$version}";
}

function upload_is_legacy_path(string $path): bool {
  return strpos($path, '/') !== false
    || strpos($path, '\\') !== false
    || strpos($path, 'uploads') !== false;
}

function upload_url(?string $path, string $type = 'image'): string {
  if (!$path) return '';
  if (upload_is_legacy_path($path)) {
    return base_url($path);
  }
  $type = $type === 'doc' ? 'doc' : 'image';
  return base_url('download.php?type=' . urlencode($type) . '&f=' . urlencode($path));
}

function redirect(string $to): void {
  header("Location: {$to}");
  exit;
}

function setting(string $key, $default = null) {
  try {
    $stmt = db()->prepare("SELECT value FROM settings WHERE `key` = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) return $default;
    return $row['value'];
  } catch (Throwable $e) {
    return $default;
  }
}

function favicon_url(): string {
  $storeLogo = setting('store_logo', '');
  if (!empty($storeLogo)) {
    return upload_url($storeLogo, 'image');
  }
  return base_url('assets/favicon.svg');
}

function set_setting(string $key, string $value): void {
  $stmt = db()->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?)
    ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
  $stmt->execute([$key, $value]);
}

function ensure_products_favorite_column(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM products LIKE 'is_favorite'");
    $hasColumn = (bool)$stmt->fetch();
    if (!$hasColumn) {
      db()->exec("ALTER TABLE products ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_products_category_column(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM products LIKE 'category'");
    $hasColumn = (bool)$stmt->fetch();
    if (!$hasColumn) {
      db()->exec("ALTER TABLE products ADD COLUMN category VARCHAR(120) NULL AFTER name");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_product_categories_table(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("
      CREATE TABLE IF NOT EXISTS product_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_name (name)
      ) ENGINE=InnoDB
    ");
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function product_categories(): array {
  try {
    $stmt = db()->query("SELECT id, name FROM product_categories ORDER BY name ASC");
    return $stmt->fetchAll();
  } catch (Throwable $e) {
    return [];
  }
}

function ensure_products_best_seller_column(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM products LIKE 'is_best_seller'");
    $hasColumn = (bool)$stmt->fetch();
    if (!$hasColumn) {
      db()->exec("ALTER TABLE products ADD COLUMN is_best_seller TINYINT(1) NOT NULL DEFAULT 0 AFTER category");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_sales_transaction_code_column(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM sales LIKE 'transaction_code'");
    $hasColumn = (bool)$stmt->fetch();
    if (!$hasColumn) {
      db()->exec("ALTER TABLE sales ADD COLUMN transaction_code VARCHAR(40) NULL AFTER id");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_sales_user_column(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM sales LIKE 'created_by'");
    $hasColumn = (bool)$stmt->fetch();
    if (!$hasColumn) {
      db()->exec("ALTER TABLE sales ADD COLUMN created_by INT NULL AFTER payment_proof_path");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_user_invites_table(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("
      CREATE TABLE IF NOT EXISTS user_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        role VARCHAR(30) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at TIMESTAMP NULL DEFAULT NULL,
        used_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_token_hash (token_hash),
        KEY idx_email (email)
      ) ENGINE=InnoDB
    ");
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_user_profile_columns(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'avatar_path'");
    $hasAvatar = (bool)$stmt->fetch();
    if (!$hasAvatar) {
      db()->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER role");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }

  try {
    $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'email'");
    $hasEmail = (bool)$stmt->fetch();
    if (!$hasEmail) {
      db()->exec("ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL AFTER username");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_password_resets_table(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("
      CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at TIMESTAMP NULL DEFAULT NULL,
        used_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_token_hash (token_hash),
        KEY idx_user_id (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_landing_order_tables(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $db = db();
    $db->exec("
      CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        email VARCHAR(190) NULL,
        phone VARCHAR(30) NULL,
        password_hash VARCHAR(255) NULL,
        gender VARCHAR(20) NULL,
        birth_date DATE NULL,
        loyalty_points INT NOT NULL DEFAULT 0,
        loyalty_remainder INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB
    ");

    $db->exec("
      CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_code VARCHAR(40) NOT NULL,
        customer_id INT NOT NULL,
        status ENUM('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        KEY idx_status (status),
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");

    $db->exec("
      CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        price_each DECIMAL(15,2) NOT NULL DEFAULT 0,
        subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");

    $db->exec("
      CREATE TABLE IF NOT EXISTS customer_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_used_at TIMESTAMP NULL DEFAULT NULL,
        expires_at TIMESTAMP NULL DEFAULT NULL,
        KEY idx_token_hash (token_hash),
        KEY idx_customer_id (customer_id),
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");

    $stmt = $db->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=`value`");
    $stmt->execute(['recaptcha_site_key', '']);
    $stmt->execute(['recaptcha_secret_key', '']);
    $stmt->execute(['loyalty_point_value', '0']);
    $stmt->execute(['loyalty_remainder_mode', 'discard']);
    $stmt->execute(['landing_order_enabled', '1']);

    $stmt = $db->query("SHOW COLUMNS FROM customers LIKE 'phone'");
    $hasPhone = (bool)$stmt->fetch();
    if (!$hasPhone) {
      $db->exec("ALTER TABLE customers ADD COLUMN phone VARCHAR(30) NULL AFTER name");
    }
    $stmt = $db->query("SHOW COLUMNS FROM customers LIKE 'password_hash'");
    $hasPassword = (bool)$stmt->fetch();
    if (!$hasPassword) {
      $db->exec("ALTER TABLE customers ADD COLUMN password_hash VARCHAR(255) NULL AFTER phone");
    }
    $stmt = $db->query("SHOW COLUMNS FROM customers LIKE 'gender'");
    $hasGender = (bool)$stmt->fetch();
    if (!$hasGender) {
      $db->exec("ALTER TABLE customers ADD COLUMN gender VARCHAR(20) NULL AFTER password_hash");
    }
    $stmt = $db->query("SHOW COLUMNS FROM customers LIKE 'birth_date'");
    $hasBirth = (bool)$stmt->fetch();
    if (!$hasBirth) {
      $db->exec("ALTER TABLE customers ADD COLUMN birth_date DATE NULL AFTER gender");
    }
    $stmt = $db->query("SHOW COLUMNS FROM customers LIKE 'loyalty_points'");
    $hasPoints = (bool)$stmt->fetch();
    if (!$hasPoints) {
      $db->exec("ALTER TABLE customers ADD COLUMN loyalty_points INT NOT NULL DEFAULT 0 AFTER phone");
    }
    $stmt = $db->query("SHOW COLUMNS FROM customers LIKE 'loyalty_remainder'");
    $hasRemainder = (bool)$stmt->fetch();
    if (!$hasRemainder) {
      $db->exec("ALTER TABLE customers ADD COLUMN loyalty_remainder INT NOT NULL DEFAULT 0 AFTER loyalty_points");
    }
    try {
      $db->exec("ALTER TABLE customers ADD UNIQUE KEY uniq_phone (phone)");
    } catch (Throwable $e) {
      // abaikan jika indeks sudah ada
    }
    try {
      $db->exec("ALTER TABLE customers MODIFY email VARCHAR(190) NULL");
    } catch (Throwable $e) {
      // abaikan jika tidak bisa mengubah kolom.
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_loyalty_rewards_table(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("
      CREATE TABLE IF NOT EXISTS loyalty_rewards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        points_required INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_product (product_id),
        KEY idx_points (points_required),
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_owner_role(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'role'");
    $column = $stmt->fetch();
    if (!$column) return;
    $type = (string)($column['Type'] ?? '');
    if (strpos($type, "'owner'") === false || strpos($type, "'superadmin'") !== false) {
      db()->exec("UPDATE users SET role='owner' WHERE role='superadmin'");
      db()->exec("ALTER TABLE users MODIFY role ENUM('owner','admin','manager','kasir','gudang','user','pegawai') NOT NULL DEFAULT 'admin'");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_upload_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0755, true);
}

function normalize_money(string $s): float {
  return parse_number_input($s);
}

function verify_recaptcha_response(
  string $token,
  string $secret,
  string $remoteIp = '',
  string $expectedAction = '',
  float $minScore = 0.5
): bool {
  if ($token === '' || $secret === '') {
    return false;
  }

  $payload = [
    'secret' => $secret,
    'response' => $token,
  ];
  if ($remoteIp !== '') {
    $payload['remoteip'] = $remoteIp;
  }

  $requestBody = http_build_query($payload);
  $result = false;

  if (function_exists('curl_init')) {
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    if ($ch !== false) {
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $requestBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 8,
      ]);
      $curlResponse = curl_exec($ch);
      $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($curlResponse !== false && $httpCode >= 200 && $httpCode < 300) {
        $result = $curlResponse;
      }
    }
  }

  if ($result === false) {
    $opts = [
      'http' => [
        'method' => 'POST',
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => $requestBody,
        'timeout' => 8,
      ],
    ];
    $context = stream_context_create($opts);
    $result = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
  }

  if ($result === false) {
    return false;
  }
  $data = json_decode($result, true);
  if (empty($data['success'])) {
    return false;
  }
  if ($expectedAction !== '' && (($data['action'] ?? '') !== $expectedAction)) {
    return false;
  }
  if (isset($data['score']) && $minScore > 0 && (float)$data['score'] < $minScore) {
    return false;
  }
  return true;
}

function landing_default_html(): string {
  return <<<'HTML'
<div class="content landing">
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:12px">
        {{store_logo_block}}
        <div>
          <h2 style="margin:0">{{store_name}}</h2>
          <p style="margin:6px 0 0"><small>{{store_subtitle}}</small></p>
        </div>
      </div>
      {{login_button}}
    </div>
  </div>

  {{notice}}

  <div class="card" style="margin-top:16px">
    <h3 style="margin:0 0 8px">Tentang Kami</h3>
    <p style="margin:0;color:var(--muted)">{{store_intro}}</p>
  </div>

  {{promo_section}}

  {{products}}

  {{cart}}
</div>
HTML;
}

function ensure_pos_print_jobs_table(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("\n      CREATE TABLE IF NOT EXISTS pos_print_jobs (\n        id BIGINT AUTO_INCREMENT PRIMARY KEY,\n        job_token VARCHAR(100) NOT NULL UNIQUE,\n        sale_id BIGINT NULL,\n        receipt_payload LONGTEXT NOT NULL,\n        payload_hash VARCHAR(64) NOT NULL,\n        status ENUM('pending','printed','expired','cancelled') NOT NULL DEFAULT 'pending',\n        created_at DATETIME NOT NULL,\n        expires_at DATETIME NOT NULL,\n        printed_at DATETIME NULL,\n        created_by INT NULL,\n        device_hint VARCHAR(100) NULL,\n        notes VARCHAR(255) NULL,\n        KEY idx_status_expires (status, expires_at),\n        KEY idx_sale_id (sale_id)\n      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n    ");
  } catch (Throwable $e) {
    // Diamkan agar modul lain tidak ikut gagal.
  }
}

function expire_old_pos_print_jobs(): void {
  try {
    $stmt = db()->prepare("\n      UPDATE pos_print_jobs\n      SET status = 'expired'\n      WHERE status = 'pending' AND expires_at < NOW()\n    ");
    $stmt->execute();
  } catch (Throwable $e) {
    // Diamkan.
  }
}

function build_pos_receipt_payload(array $receipt, array $opts = []): array {
  $storeName = (string)($opts['store_name'] ?? setting('store_name', app_config()['app']['name']));
  $storeSubtitle = (string)($opts['store_subtitle'] ?? setting('store_subtitle', ''));
  $storeAddress = (string)($opts['store_address'] ?? setting('store_address', ''));
  $storePhone = (string)($opts['store_phone'] ?? setting('store_phone', ''));
  $footer = (string)($opts['footer'] ?? setting('receipt_footer', ''));
  $storeLogo = (string)($opts['store_logo'] ?? setting('store_logo', ''));
  $paidAmount = (float)($opts['paid_amount'] ?? ($receipt['paid_amount'] ?? $receipt['total'] ?? 0));
  $total = (float)($receipt['total'] ?? 0);

  $items = [];
  foreach (($receipt['items'] ?? []) as $item) {
    $items[] = [
      'name' => (string)($item['name'] ?? ''),
      'qty' => (float)($item['qty'] ?? 0),
      'price' => (float)($item['price'] ?? 0),
      'subtotal' => (float)($item['subtotal'] ?? 0),
    ];
  }

  return [
    'receipt_id' => (string)($receipt['id'] ?? ''),
    'tanggal_jam' => (string)($receipt['time'] ?? date('d/m/Y H:i')),
    'cashier' => (string)($receipt['cashier'] ?? 'Kasir'),
    'store_name' => $storeName,
    'store_subtitle' => $storeSubtitle,
    'store_address' => $storeAddress,
    'store_phone' => $storePhone,
    'footer' => $footer,
    'payment_method' => (string)($receipt['payment'] ?? 'cash'),
    'total' => $total,
    'bayar' => $paidAmount,
    'kembalian' => max($paidAmount - $total, 0),
    'items' => $items,
    'logo_url' => $storeLogo !== '' ? upload_url($storeLogo, 'image') : null,
    'currency' => [
      'code' => 'IDR',
      'symbol' => 'Rp',
      'total_formatted' => format_number_id($total),
      'bayar_formatted' => format_number_id($paidAmount),
      'kembalian_formatted' => format_number_id(max($paidAmount - $total, 0)),
    ],
    'paper_width' => 58,
  ];
}

function create_pos_print_job(array $payload, array $opts = []): ?array {
  ensure_pos_print_jobs_table();
  expire_old_pos_print_jobs();

  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    return null;
  }

  $ttlMinutes = (int)($opts['ttl_minutes'] ?? 10);
  if ($ttlMinutes < 1) $ttlMinutes = 10;

  $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
  $hash = hash('sha256', $json);
  $createdBy = isset($opts['created_by']) ? (int)$opts['created_by'] : null;
  $saleId = isset($opts['sale_id']) ? (int)$opts['sale_id'] : null;
  $deviceHint = isset($opts['device_hint']) ? substr((string)$opts['device_hint'], 0, 100) : null;
  $notes = isset($opts['notes']) ? substr((string)$opts['notes'], 0, 255) : null;

  try {
    $stmt = db()->prepare("\n      INSERT INTO pos_print_jobs\n      (job_token, sale_id, receipt_payload, payload_hash, status, created_at, expires_at, printed_at, created_by, device_hint, notes)\n      VALUES (?, ?, ?, ?, 'pending', NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), NULL, ?, ?, ?)\n    ");
    $stmt->execute([$token, $saleId, $json, $hash, $ttlMinutes, $createdBy, $deviceHint, $notes]);

    return [
      'job_token' => $token,
      'expires_in_minutes' => $ttlMinutes,
      'payload_hash' => $hash,
    ];
  } catch (Throwable $e) {
    return null;
  }
}

function get_pos_print_job_by_token(string $token, array $options = []): ?array {
  ensure_pos_print_jobs_table();
  expire_old_pos_print_jobs();

  $token = trim($token);
  if ($token === '' || strlen($token) > 100) {
    return null;
  }

  $allowPrinted = !empty($options['allow_printed']);
  $requirePending = !empty($options['require_pending']);

  $sql = "SELECT * FROM pos_print_jobs WHERE job_token = ? LIMIT 1";
  $stmt = db()->prepare($sql);
  $stmt->execute([$token]);
  $job = $stmt->fetch();
  if (!$job) {
    return null;
  }

  if ((string)$job['status'] === 'pending' && strtotime((string)$job['expires_at']) < time()) {
    $stmtExpire = db()->prepare("UPDATE pos_print_jobs SET status='expired' WHERE id=?");
    $stmtExpire->execute([(int)$job['id']]);
    $job['status'] = 'expired';
  }

  if ($requirePending && (string)$job['status'] !== 'pending') {
    return null;
  }
  if (!$allowPrinted && (string)$job['status'] === 'printed') {
    return null;
  }

  $payload = json_decode((string)$job['receipt_payload'], true);
  if (!is_array($payload)) {
    return null;
  }

  $calculatedHash = hash('sha256', (string)$job['receipt_payload']);
  if (!hash_equals((string)$job['payload_hash'], $calculatedHash)) {
    return null;
  }

  $job['payload'] = $payload;
  return $job;
}

function mark_pos_print_job_printed(string $token): bool {
  ensure_pos_print_jobs_table();
  $token = trim($token);
  if ($token === '' || strlen($token) > 100) {
    return false;
  }

  try {
    $stmt = db()->prepare("\n      UPDATE pos_print_jobs\n      SET status = 'printed', printed_at = NOW()\n      WHERE job_token = ? AND status = 'pending' AND expires_at >= NOW()\n    ");
    $stmt->execute([$token]);
    return $stmt->rowCount() > 0;
  } catch (Throwable $e) {
    return false;
  }
}
