<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_admin();

$me = current_user();
if (($me['role'] ?? '') !== 'owner') {
  http_response_code(403);
  exit('Forbidden');
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $cfg = app_config()['db'];
  $tmpFile = tempnam(sys_get_temp_dir(), 'backup_');
  $filename = sprintf(
    '%s_%s.sql',
    preg_replace('/[^a-zA-Z0-9_-]+/', '-', $cfg['name']),
    date('Ymd_His')
  );
  $passwordArg = $cfg['pass'] !== '' ? '--password=' . escapeshellarg($cfg['pass']) : '';
  $cmdParts = [
    'mysqldump',
    '--single-transaction',
    '--routines',
    '--triggers',
    '--events',
    '-h ' . escapeshellarg($cfg['host']),
    '-P ' . escapeshellarg($cfg['port']),
    '-u ' . escapeshellarg($cfg['user']),
    $passwordArg,
    escapeshellarg($cfg['name']),
    '> ' . escapeshellarg($tmpFile),
    '2>&1',
  ];
  $cmd = implode(' ', array_filter($cmdParts));
  exec($cmd, $output, $resultCode);

  if ($resultCode !== 0 || !is_file($tmpFile) || filesize($tmpFile) === 0) {
    $err = 'Backup gagal. Pastikan mysqldump tersedia di server.';
    if (is_file($tmpFile)) {
      unlink($tmpFile);
    }
  } else {
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
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
        <p>Unduh backup database langsung ke komputer Anda tanpa membuka phpMyAdmin.</p>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <button class="btn" type="submit">Unduh Backup</button>
        </form>
        <p><small>Disarankan hanya untuk owner. Simpan file .sql di tempat aman.</small></p>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
