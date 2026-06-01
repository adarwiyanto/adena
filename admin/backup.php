<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_admin();

$me = current_user() ?: [];
$role = strtolower((string)($me['role_key'] ?? $me['role'] ?? ''));
if ($role !== 'owner') {
  http_response_code(403);
  exit('Forbidden');
}

function adena_backup_mysql_value($value): string {
  if ($value === null) return 'NULL';
  if (is_int($value) || is_float($value)) return (string)$value;
  return db()->quote((string)$value);
}

function adena_backup_table_columns(PDO $pdo, string $table): array {
  $st = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
  $cols = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cols[] = (string)$row['Field'];
  }
  return $cols;
}

function adena_backup_native_dump(string $targetFile): void {
  $pdo = db();
  $cfg = app_config()['db'];
  $dbName = (string)$cfg['name'];
  $fh = fopen($targetFile, 'wb');
  if (!$fh) {
    throw new RuntimeException('Tidak bisa membuat file temporary backup.');
  }

  fwrite($fh, "-- Adena database backup\n");
  fwrite($fh, "-- Database: `" . str_replace('`', '``', $dbName) . "`\n");
  fwrite($fh, "-- Created at: " . date('Y-m-d H:i:s') . "\n\n");
  fwrite($fh, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
  fwrite($fh, "SET time_zone = '+00:00';\n");
  fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n");
  fwrite($fh, "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
  fwrite($fh, "/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
  fwrite($fh, "/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
  fwrite($fh, "/*!40101 SET NAMES utf8mb4 */;\n\n");

  $tables = [];
  $views = [];
  $st = $pdo->query('SHOW FULL TABLES');
  while ($row = $st->fetch(PDO::FETCH_NUM)) {
    $name = (string)$row[0];
    $type = strtoupper((string)($row[1] ?? 'BASE TABLE'));
    if ($type === 'VIEW') $views[] = $name; else $tables[] = $name;
  }

  foreach ($tables as $table) {
    $safeTable = str_replace('`', '``', $table);
    fwrite($fh, "\n-- --------------------------------------------------------\n");
    fwrite($fh, "-- Table structure for table `$safeTable`\n\n");
    fwrite($fh, "DROP TABLE IF EXISTS `$safeTable`;\n");
    $create = $pdo->query("SHOW CREATE TABLE `$safeTable`")->fetch(PDO::FETCH_ASSOC);
    $createSql = (string)($create['Create Table'] ?? array_values($create)[1] ?? '');
    fwrite($fh, $createSql . ";\n\n");

    $cols = adena_backup_table_columns($pdo, $table);
    if (!$cols) continue;
    $colList = '`' . implode('`, `', array_map(fn($c) => str_replace('`', '``', $c), $cols)) . '`';

    $count = (int)$pdo->query("SELECT COUNT(*) FROM `$safeTable`")->fetchColumn();
    if ($count <= 0) continue;

    fwrite($fh, "-- Dumping data for table `$safeTable`\n\n");
    $offset = 0;
    $limit = 300;
    while ($offset < $count) {
      $rows = $pdo->query("SELECT * FROM `$safeTable` LIMIT $limit OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
      if (!$rows) break;
      $values = [];
      foreach ($rows as $row) {
        $vals = [];
        foreach ($cols as $col) {
          $vals[] = adena_backup_mysql_value($row[$col] ?? null);
        }
        $values[] = '(' . implode(',', $vals) . ')';
      }
      fwrite($fh, "INSERT INTO `$safeTable` ($colList) VALUES\n" . implode(",\n", $values) . ";\n");
      $offset += $limit;
    }
    fwrite($fh, "\n");
  }

  foreach ($views as $view) {
    $safeView = str_replace('`', '``', $view);
    fwrite($fh, "\n-- --------------------------------------------------------\n");
    fwrite($fh, "-- View structure for view `$safeView`\n\n");
    fwrite($fh, "DROP VIEW IF EXISTS `$safeView`;\n");
    $create = $pdo->query("SHOW CREATE VIEW `$safeView`")->fetch(PDO::FETCH_ASSOC);
    $createSql = (string)($create['Create View'] ?? array_values($create)[1] ?? '');
    fwrite($fh, $createSql . ";\n\n");
  }

  fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
  fwrite($fh, "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
  fwrite($fh, "/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
  fwrite($fh, "/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
  fclose($fh);
}

function adena_backup_mysqldump(string $targetFile): bool {
  $cfg = app_config()['db'];
  $cmdParts = [
    'mysqldump',
    '--single-transaction',
    '--quick',
    '--routines',
    '--triggers',
    '--events',
    '--default-character-set=utf8mb4',
    '-h ' . escapeshellarg((string)$cfg['host']),
    '-P ' . escapeshellarg((string)$cfg['port']),
    '-u ' . escapeshellarg((string)$cfg['user']),
  ];
  if ((string)$cfg['pass'] !== '') {
    $cmdParts[] = '-p' . escapeshellarg((string)$cfg['pass']);
  }
  $cmdParts[] = escapeshellarg((string)$cfg['name']);
  $cmdParts[] = '> ' . escapeshellarg($targetFile);
  $cmdParts[] = '2>&1';
  $cmd = implode(' ', $cmdParts);
  @exec($cmd, $output, $resultCode);
  return $resultCode === 0 && is_file($targetFile) && filesize($targetFile) > 0;
}

$err = '';
$methodInfo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  @set_time_limit(0);
  $cfg = app_config()['db'];
  $tmpFile = tempnam(sys_get_temp_dir(), 'adena_backup_');
  $filename = sprintf('%s_%s.sql', preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)$cfg['name']), date('Ymd_His'));

  try {
    $ok = adena_backup_mysqldump($tmpFile);
    if (!$ok) {
      adena_backup_native_dump($tmpFile);
      $methodInfo = 'native';
    } else {
      $methodInfo = 'mysqldump';
    }

    if (!is_file($tmpFile) || filesize($tmpFile) <= 0) {
      throw new RuntimeException('File backup kosong.');
    }

    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('X-Adena-Backup-Method: ' . $methodInfo);
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
  } catch (Throwable $e) {
    if (is_file($tmpFile)) @unlink($tmpFile);
    $err = 'Backup gagal: ' . $e->getMessage();
  }
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Backup Database</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <div class="badge">Backup Database</div>
    </div>
    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Backup Database</h3>
        <p>Unduh database aktif sebagai file <b>.sql dump</b>. File ini bisa dipakai untuk restore melalui phpMyAdmin atau tool MySQL lain.</p>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <button class="btn" type="submit">Unduh Backup .SQL</button>
        </form>
        <p><small>Akses dibatasi untuk owner. Sistem akan memakai <code>mysqldump</code> bila tersedia, lalu otomatis fallback ke dump native PHP bila tidak tersedia.</small></p>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
