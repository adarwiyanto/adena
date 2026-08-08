<?php
date_default_timezone_set('Asia/Jakarta');
// Installer Adena: mengikuti schema DB referensi di /db/adena_install_schema.sql.
// Prinsip: tidak membuat desain DB baru, tidak membawa data dump lama, hanya schema + owner pertama + setting minimal.
$lock = __DIR__ . '/install.lock';
$lockAlt = __DIR__ . '/LOCK';
if (file_exists($lock) || file_exists($lockAlt)) {
  header('Location: ../adm.php');
  exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function normalize_install_path($path) {
  $path = trim((string)$path);
  if ($path === '') return '';
  $path = str_replace('\\', '/', $path);
  return rtrim($path, '/') . '/';
}

function default_private_upload_base() {
  return normalize_install_path(dirname(__DIR__, 2) . '/private_uploads/ketam_isi_adena/');
}

function assert_private_upload_path($base) {
  if ($base === '') {
    throw new Exception('Folder upload privat wajib diisi.');
  }
  if (!is_dir($base)) {
    @mkdir($base, 0755, true);
  }
  if (!is_dir($base)) {
    throw new Exception('Folder upload privat tidak bisa dibuat: ' . $base);
  }
  foreach (['images', 'docs'] as $sub) {
    $dir = $base . $sub . '/';
    if (!is_dir($dir)) {
      @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir)) {
      throw new Exception('Subfolder upload tidak bisa dibuat: ' . $dir);
    }
  }
  if (!is_writable($base)) {
    throw new Exception('Folder upload privat tidak writable: ' . $base);
  }
}

function write_config_local($configLocalPath, $privateUploadBase) {
  $baseExport = var_export($privateUploadBase, true);
  $configLocalPhp = <<<PHP_LOCAL
<?php
// config.local.php — konfigurasi lokal hasil installer.
// Mengatur upload privat tanpa membawa data dari dump database lama.

\$base = {$baseExport};

if (!is_dir(\$base)) {
    @mkdir(\$base, 0755, true);
}

foreach (['images', 'docs'] as \$sub) {
    \$dir = rtrim(\$base, '/') . '/' . \$sub . '/';
    if (!is_dir(\$dir)) {
        @mkdir(\$dir, 0755, true);
    }
}

putenv('HOPE_UPLOAD_BASE=' . \$base);
\$_ENV['HOPE_UPLOAD_BASE'] = \$base;
\$_SERVER['HOPE_UPLOAD_BASE'] = \$base;

if (!defined('HOPE_UPLOAD_BASE')) {
    define('HOPE_UPLOAD_BASE', \$base);
}
PHP_LOCAL;
  file_put_contents($configLocalPath, $configLocalPhp);
}

function strip_sql_comments($sql) {
  // Buang komentar dump phpMyAdmin, pertahankan conditional comment MySQL /*! ... */ sebagai statement.
  $lines = preg_split('/\R/', $sql);
  $out = [];
  foreach ($lines as $line) {
    $trim = ltrim($line);
    if (strpos($trim, '--') === 0 || strpos($trim, '#') === 0) {
      continue;
    }
    $out[] = $line;
  }
  return implode("\n", $out);
}

function split_sql_statements($sql) {
  $sql = strip_sql_comments($sql);
  $statements = [];
  $buffer = '';
  $len = strlen($sql);
  $quote = null;
  $escape = false;

  for ($i = 0; $i < $len; $i++) {
    $ch = $sql[$i];
    $buffer .= $ch;

    if ($quote !== null) {
      if ($escape) {
        $escape = false;
      } elseif ($ch === '\\') {
        $escape = true;
      } elseif ($ch === $quote) {
        $quote = null;
      }
      continue;
    }

    if ($ch === "'" || $ch === '"' || $ch === '`') {
      $quote = $ch;
      continue;
    }

    if ($ch === ';') {
      $stmt = trim(substr($buffer, 0, -1));
      if ($stmt !== '') {
        $statements[] = $stmt;
      }
      $buffer = '';
    }
  }

  $tail = trim($buffer);
  if ($tail !== '') {
    $statements[] = $tail;
  }
  return $statements;
}

function execute_schema_file(PDO $pdo, $schemaFile) {
  if (!is_file($schemaFile)) {
    throw new Exception('File schema installer tidak ditemukan: ' . $schemaFile);
  }
  $sql = file_get_contents($schemaFile);
  if ($sql === false || trim($sql) === '') {
    throw new Exception('File schema installer kosong atau tidak bisa dibaca.');
  }

  $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
  $statements = split_sql_statements($sql);

  $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
  try {
    foreach ($statements as $stmt) {
      $trim = trim($stmt);
      if ($trim === '') continue;
      // Schema referensi tidak boleh memaksa nama database hosting lama.
      if (preg_match('/^CREATE\s+DATABASE\b/i', $trim) || preg_match('/^USE\s+/i', $trim)) {
        continue;
      }
      $pdo->exec($trim);
    }
  } finally {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
  }
}

function table_has_rows(PDO $pdo, $table) {
  $stmt = $pdo->query("SELECT COUNT(*) FROM `" . str_replace('`', '``', $table) . "`");
  return ((int)$stmt->fetchColumn()) > 0;
}

function seed_minimal_data(PDO $pdo, $appName, $adminUsername, $adminName, $adminPassword) {
  // Role owner minimal agar user owner langsung bisa login dan mengakses halaman admin.
  $ownerRoleId = null;
  try {
    $stmt = $pdo->prepare("INSERT INTO roles (role_key, role_name, is_system, is_active) VALUES ('owner', 'Owner', 1, 1)
      ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), is_system=1, is_active=1");
    $stmt->execute();
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE role_key='owner' LIMIT 1");
    $stmt->execute();
    $ownerRoleId = $stmt->fetchColumn();
  } catch (Throwable $e) {
    $ownerRoleId = null;
  }

  $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
  $stmt = $pdo->prepare("INSERT INTO users (username, email, name, role, role_id, password_hash)
    VALUES (?, NULL, ?, 'owner', ?, ?)
    ON DUPLICATE KEY UPDATE name=VALUES(name), role='owner', role_id=VALUES(role_id), password_hash=VALUES(password_hash)");
  $stmt->execute([$adminUsername, $adminName, $ownerRoleId ?: null, $hash]);

  $settings = [
    'store_name' => $appName,
    'store_subtitle' => 'Katalog produk sederhana',
    'store_intro' => 'Kami adalah usaha yang menghadirkan produk pilihan dengan kualitas terbaik untuk kebutuhan Anda.',
    'custom_css' => '',
    'landing_css' => '',
    'landing_html' => '',
    'recaptcha_site_key' => '',
    'recaptcha_secret_key' => '',
    'landing_order_enabled' => '1',
  ];
  $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
  foreach ($settings as $key => $value) {
    $stmt->execute([$key, $value]);
  }
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $app_name = trim($_POST['app_name'] ?? '');
  $base_url = trim($_POST['base_url'] ?? '');
  $db_host = trim($_POST['db_host'] ?? '127.0.0.1');
  $db_port = trim($_POST['db_port'] ?? '3306');
  $db_name = trim($_POST['db_name'] ?? '');
  $db_user = trim($_POST['db_user'] ?? 'root');
  $db_pass = (string)($_POST['db_pass'] ?? '');
  $private_upload_base = normalize_install_path($_POST['private_upload_base'] ?? default_private_upload_base());

  $admin_username = trim($_POST['admin_username'] ?? 'admin');
  $admin_name = trim($_POST['admin_name'] ?? 'Administrator');
  $admin_pass1 = (string)($_POST['admin_pass1'] ?? '');
  $admin_pass2 = (string)($_POST['admin_pass2'] ?? '');

  try {
    if (!$app_name) throw new Exception('Nama aplikasi wajib diisi.');
    if (!$base_url) throw new Exception('Base URL wajib diisi (contoh: http://localhost/adena).');
    if (!$db_name) throw new Exception('Nama database wajib diisi.');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $db_name)) throw new Exception('Nama database hanya boleh huruf, angka, dan underscore.');
    assert_private_upload_path($private_upload_base);
    if ($admin_username === '') throw new Exception('Username owner wajib diisi.');
    if ($admin_pass1 === '' || $admin_pass1 !== $admin_pass2) throw new Exception('Password owner tidak cocok.');

    $dsn = "mysql:host={$db_host};port={$db_port};charset=utf8mb4";
    $pdoRoot = new PDO($dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoRoot->exec("SET time_zone = '+07:00'");
    $pdoRoot->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdoRoot->exec("USE `{$db_name}`");

    // Untuk keamanan clean install: jangan overwrite database yang sudah berisi tabel/data.
    $stmt = $pdoRoot->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdoRoot->quote($db_name));
    $tableCount = (int)$stmt->fetchColumn();
    if ($tableCount > 0 && empty($_POST['allow_existing_schema'])) {
      throw new Exception('Database sudah berisi tabel. Installer ini tidak menghapus/menimpa data. Gunakan database kosong, atau centang opsi lanjutan bila hanya ingin melanjutkan pada schema yang sudah sama.');
    }

    if ($tableCount === 0) {
      execute_schema_file($pdoRoot, __DIR__ . '/../db/adena_install_schema.sql');
    }

    seed_minimal_data($pdoRoot, $app_name, $admin_username, $admin_name, $admin_pass1);

    $config = [
      'app' => ['name' => $app_name, 'base_url' => rtrim($base_url, '/'), 'timezone' => 'Asia/Jakarta'],
      'db'  => ['host'=>$db_host,'port'=>$db_port,'name'=>$db_name,'user'=>$db_user,'pass'=>$db_pass,'charset'=>'utf8mb4'],
      'security' => ['session_name' => 'TOKOSESS'],
    ];

    $configPhp = "<?php\nreturn " . var_export($config, true) . ";\n";
    file_put_contents(__DIR__ . '/../config.php', $configPhp);
    write_config_local(__DIR__ . '/../config.local.php', $private_upload_base);

    file_put_contents($lock, "installed_at=" . date('c'));
    file_put_contents($lockAlt, "installed_at=" . date('c'));

    header('Location: ../adm.php');
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Adena Installer</title>
  <style>
    :root{
      --primary:#0b86d8;--primary-2:#0a78c7;--bg:#f4f7fb;--surface:#ffffff;--text:#1f2937;--muted:#6b7280;--border:#e5e7eb;--shadow:0 10px 24px rgba(17,24,39,.08);--radius:14px;--font:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;
    }
    *{box-sizing:border-box} body{margin:0;font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh}.topbar{min-height:64px;background:linear-gradient(90deg,var(--primary),var(--primary-2));color:#fff;display:flex;align-items:center;justify-content:space-between;gap:16px;padding:0 24px;box-shadow:0 6px 14px rgba(11,134,216,.18)}.brand{display:flex;align-items:center;gap:12px;font-weight:800;letter-spacing:.2px}.brand-mark{width:38px;height:38px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);font-weight:900}.topbar small{color:rgba(255,255,255,.86)}.wrap{max-width:1040px;margin:0 auto;padding:28px 18px 40px}.hero{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:18px}h1{font-size:26px;line-height:1.2;margin:0 0 6px;font-weight:800;color:#111827}h2{font-size:18px;margin:24px 0 12px;color:#111827}p{margin:0 0 12px}label{display:block;font-weight:700;font-size:14px;margin-bottom:7px;color:#374151}small{display:block;color:var(--muted);font-size:12px;line-height:1.45;margin-top:6px}.badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:7px 11px;background:#eaf4ff;border:1px solid #cfe8ff;color:#0a5ea7;font-size:12px;font-weight:800;white-space:nowrap}.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:22px;box-shadow:var(--shadow)}.section-card{border:1px solid var(--border);border-radius:var(--radius);padding:16px;background:#fff;margin-top:14px}.grid{display:grid;gap:14px}.cols{grid-template-columns:1fr 1fr}@media(max-width:860px){.topbar{padding:12px 16px;align-items:flex-start;flex-direction:column}.hero{flex-direction:column}.cols{grid-template-columns:1fr}.wrap{padding:20px 14px 34px}.card{padding:16px}}input{width:100%;padding:11px 12px;border-radius:12px;border:1px solid var(--border);background:#fff;color:var(--text);outline:none;font:inherit;transition:border-color .15s ease, box-shadow .15s ease}input:focus{border-color:#9bd2ff;box-shadow:0 0 0 4px rgba(11,134,216,.12)}.checkrow{display:flex;align-items:flex-start;gap:10px;padding:12px;border:1px dashed #cbd5e1;border-radius:12px;background:#f8fafc}.checkrow input{width:auto;margin-top:2px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 16px;border-radius:12px;border:1px solid var(--primary-2);background:linear-gradient(90deg,var(--primary),var(--primary-2));color:#fff;font-weight:800;cursor:pointer;box-shadow:0 8px 18px rgba(11,134,216,.2);font:inherit}.err{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;padding:12px 14px;border-radius:12px;margin-bottom:14px;font-weight:700}.notice{background:#f2f7ff;border:1px solid #dbeafe;color:#1f3b57;padding:12px 14px;border-radius:12px;margin-bottom:16px;line-height:1.5}.actions{margin-top:18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}.footer-note{margin-top:14px;color:var(--muted);font-size:12px;line-height:1.5}
  </style>
</head>
<body>
  <div class="topbar">
    <div class="brand"><div class="brand-mark">A</div><div><div>Adena Installer</div><small>Setup awal aplikasi</small></div></div>
    <small>Schema sesuai DB lampiran • Config lokal • Upload privat</small>
  </div>
  <div class="wrap">
    <div class="hero">
      <div><h1>Installer Adena</h1><small>Installer membuat struktur database mengikuti file <strong>db/adena_install_schema.sql</strong>, lalu menambahkan owner pertama dan setting minimal.</small></div>
      <div class="badge">DB Referensi</div>
    </div>
    <div class="card">
      <?php if ($err): ?><div class="err"><?php echo h($err); ?></div><?php endif; ?>
      <form method="post">
        <div class="notice"><strong>Catatan:</strong> installer tidak membuat schema versi baru sendiri dan tidak membawa data dump lama. Struktur tabel mengikuti DB yang Dok lampirkan. Data awal hanya owner pertama dan setting minimal.</div>
        <div class="section-card">
          <h2>Identitas Aplikasi</h2>
          <div class="grid cols">
            <div><label>Nama Aplikasi</label><input name="app_name" value="<?php echo h($_POST['app_name'] ?? 'Adena'); ?>" placeholder="Nama aplikasi"><small>Contoh: Adena</small></div>
            <div><label>Base URL</label><input name="base_url" placeholder="http://localhost/adena" value="<?php echo h($_POST['base_url'] ?? ''); ?>"><small>Sesuaikan dengan domain/folder aplikasi.</small></div>
          </div>
        </div>
        <div class="section-card">
          <h2>Upload Privat</h2>
          <div class="grid"><div><label>Folder Upload Privat (config.local.php)</label><input name="private_upload_base" value="<?php echo h($_POST['private_upload_base'] ?? default_private_upload_base()); ?>" placeholder="/home/user/private_uploads/ketam_isi_adena/"><small>Folder ini sebaiknya berada di luar public_html. Installer hanya membuat folder kosong images/ dan docs/.</small></div></div>
        </div>
        <div class="section-card">
          <h2>Koneksi MySQL/MariaDB</h2>
          <div class="grid cols">
            <div><label>Host</label><input name="db_host" value="<?php echo h($_POST['db_host'] ?? '127.0.0.1'); ?>"></div>
            <div><label>Port</label><input name="db_port" value="<?php echo h($_POST['db_port'] ?? '3306'); ?>"></div>
            <div><label>DB Name</label><input name="db_name" value="<?php echo h($_POST['db_name'] ?? 'adena'); ?>"></div>
            <div><label>DB User</label><input name="db_user" value="<?php echo h($_POST['db_user'] ?? 'root'); ?>"></div>
            <div><label>DB Password</label><input type="password" name="db_pass" value="<?php echo h($_POST['db_pass'] ?? ''); ?>"></div>
          </div>
          <div style="margin-top:14px" class="checkrow">
            <input type="checkbox" name="allow_existing_schema" value="1" <?php echo !empty($_POST['allow_existing_schema']) ? 'checked' : ''; ?>>
            <div><strong>Lanjutkan bila database sudah berisi schema yang sama</strong><small>Installer tidak akan import schema ulang dan tidak menghapus data. Dipakai hanya bila database sudah pernah dibuat manual dari file DB lampiran.</small></div>
          </div>
        </div>
        <div class="section-card">
          <h2>Owner Pertama</h2>
          <div class="grid cols">
            <div><label>Nama</label><input name="admin_name" value="<?php echo h($_POST['admin_name'] ?? 'Owner'); ?>"></div>
            <div><label>Username</label><input name="admin_username" value="<?php echo h($_POST['admin_username'] ?? 'owner'); ?>"></div>
            <div><label>Password</label><input type="password" name="admin_pass1"></div>
            <div><label>Ulangi Password</label><input type="password" name="admin_pass2"></div>
          </div>
        </div>
        <div class="actions"><button class="btn" type="submit">Install Sekarang</button></div>
        <p class="footer-note">Sesudah berhasil, installer otomatis terkunci (install/install.lock + install/LOCK) dan diarahkan ke login.</p>
      </form>
    </div>
  </div>
</body>
</html>
