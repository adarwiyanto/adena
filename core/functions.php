<?php
date_default_timezone_set('Asia/Jakarta');
function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
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


function is_employee_role(?string $role): bool {
  return in_array((string)$role, ['pegawai', 'pegawai_pos', 'pegawai_non_pos', 'manager_toko'], true);
}

function employee_can_process_payment(?string $role): bool {
  return in_array((string)$role, ['pegawai', 'pegawai_pos', 'manager_toko', 'admin', 'owner', 'superadmin'], true);
}

function app_now_jakarta(string $format = 'Y-m-d H:i:s'): string {
  $tz = new DateTimeZone('Asia/Jakarta');
  return (new DateTimeImmutable('now', $tz))->format($format);
}

function app_today_jakarta(): string {
  return app_now_jakarta('Y-m-d');
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
        status ENUM('pending','processing','completed','cancelled','pending_payment','unpaid') NOT NULL DEFAULT 'pending',
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
    try {
      $db->exec("ALTER TABLE orders MODIFY status ENUM('pending','processing','completed','cancelled','pending_payment','unpaid') NOT NULL DEFAULT 'pending'");
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
      db()->exec("UPDATE users SET role='pegawai' WHERE role='user'");
      db()->exec("ALTER TABLE users MODIFY role ENUM('owner','admin','pegawai','pegawai_pos','pegawai_non_pos','manager_toko') NOT NULL DEFAULT 'admin'");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}


function ensure_employee_attendance_tables(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $db = db();
    $db->exec("
      CREATE TABLE IF NOT EXISTS employee_attendance (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        attend_date DATE NOT NULL,
        checkin_time DATETIME NULL DEFAULT NULL,
        checkout_time DATETIME NULL DEFAULT NULL,
        checkin_photo_path VARCHAR(255) NULL,
        checkout_photo_path VARCHAR(255) NULL,
        checkin_device_info VARCHAR(255) NULL,
        checkout_device_info VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_date (user_id, attend_date),
        KEY idx_attend_date (attend_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");

    $db->exec("
      CREATE TABLE IF NOT EXISTS employee_schedule_weekly (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        weekday TINYINT NOT NULL,
        start_time TIME NULL DEFAULT NULL,
        end_time TIME NULL DEFAULT NULL,
        grace_minutes INT NOT NULL DEFAULT 0,
        is_off TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_weekday (user_id, weekday),
        KEY idx_user_weekday (user_id, weekday),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");

    $db->exec("
      CREATE TABLE IF NOT EXISTS employee_schedule_overrides (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        schedule_date DATE NOT NULL,
        start_time TIME NULL DEFAULT NULL,
        end_time TIME NULL DEFAULT NULL,
        grace_minutes INT NOT NULL DEFAULT 0,
        is_off TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_date (user_id, schedule_date),
        KEY idx_user_date (user_id, schedule_date),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");

    $addColumnIfMissing = static function (PDO $db, string $table, string $column, string $definition): void {
      $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
      $stmt->execute([$table, $column]);
      if ((int) $stmt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
      }
    };

    $addColumnIfMissing($db, 'employee_schedule_weekly', 'allow_checkin_before_minutes', 'INT NOT NULL DEFAULT 0 AFTER grace_minutes');
    $addColumnIfMissing($db, 'employee_schedule_weekly', 'overtime_before_minutes', 'INT NOT NULL DEFAULT 0 AFTER allow_checkin_before_minutes');
    $addColumnIfMissing($db, 'employee_schedule_weekly', 'overtime_after_minutes', 'INT NOT NULL DEFAULT 0 AFTER overtime_before_minutes');

    $addColumnIfMissing($db, 'employee_schedule_overrides', 'allow_checkin_before_minutes', 'INT NOT NULL DEFAULT 0 AFTER grace_minutes');
    $addColumnIfMissing($db, 'employee_schedule_overrides', 'overtime_before_minutes', 'INT NOT NULL DEFAULT 0 AFTER allow_checkin_before_minutes');
    $addColumnIfMissing($db, 'employee_schedule_overrides', 'overtime_after_minutes', 'INT NOT NULL DEFAULT 0 AFTER overtime_before_minutes');

    $addColumnIfMissing($db, 'employee_attendance', 'checkin_status', "ENUM('ontime','late','early','invalid_window','off','unscheduled','absent') NULL AFTER checkout_device_info");
    $addColumnIfMissing($db, 'employee_attendance', 'checkout_status', "ENUM('normal','early_leave','missing','off','unscheduled') NULL AFTER checkin_status");
    $addColumnIfMissing($db, 'employee_attendance', 'late_minutes', 'INT NOT NULL DEFAULT 0 AFTER checkout_status');
    $addColumnIfMissing($db, 'employee_attendance', 'early_minutes', 'INT NOT NULL DEFAULT 0 AFTER late_minutes');
    $addColumnIfMissing($db, 'employee_attendance', 'overtime_before_minutes', 'INT NOT NULL DEFAULT 0 AFTER early_minutes');
    $addColumnIfMissing($db, 'employee_attendance', 'overtime_after_minutes', 'INT NOT NULL DEFAULT 0 AFTER overtime_before_minutes');
    $addColumnIfMissing($db, 'employee_attendance', 'work_minutes', 'INT NOT NULL DEFAULT 0 AFTER overtime_after_minutes');
    $addColumnIfMissing($db, 'employee_attendance', 'early_checkout_reason', 'VARCHAR(255) NULL AFTER work_minutes');
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}


function ensure_employee_roles(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("UPDATE users SET role='pegawai' WHERE role='user'");
    db()->exec("ALTER TABLE users MODIFY role ENUM('owner','admin','pegawai','pegawai_pos','pegawai_non_pos','manager_toko') NOT NULL DEFAULT 'admin'");
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function attendance_photo_url(?string $path): string {
  if (empty($path)) {
    return '';
  }
  return base_url('download.php?type=attendance&f=' . urlencode($path));
}

function ensure_upload_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0755, true);
}

function normalize_money(string $s): float {
  // menerima "12.500" atau "12500"
  $s = trim($s);
  $s = str_replace([' ', ','], ['', ''], $s);
  return (float)$s;
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
