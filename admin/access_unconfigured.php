<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';

start_secure_session();
require_login();

$reason = trim((string)($_GET['reason'] ?? ''));
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Akses Belum Dikonfigurasi</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <div class="main" style="margin:auto;max-width:720px">
    <div class="content">
      <div class="card">
        <h2>Akses Anda belum dikonfigurasi</h2>
        <p>Silakan hubungi owner untuk mengaktifkan permission role Anda.</p>
        <?php if ($reason !== ''): ?><p><small>Detail: <?php echo e($reason); ?></small></p><?php endif; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="btn" href="<?php echo e(base_url('adm.php')); ?>">Kembali ke Login</a>
          <a class="btn btn-light" href="<?php echo e(base_url('admin/logout.php')); ?>">Logout</a>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
