<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_admin();
ensure_landing_order_tables();

$me = current_user();
start_session();
$err = $_SESSION['customers_err'] ?? '';
unset($_SESSION['customers_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  if ($action === 'delete_customer') {
    if (($me['role'] ?? '') !== 'owner') {
      $_SESSION['customers_err'] = 'Hanya owner yang dapat menghapus pelanggan.';
    } else {
      $customerId = (int)($_POST['customer_id'] ?? 0);
      if ($customerId > 0) {
        $stmt = db()->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->execute([$customerId]);
      }
    }
  }
  redirect(base_url('admin/customers.php'));
}

$customers = db()->query("
  SELECT c.id, c.name, c.email, c.phone, c.loyalty_points, c.created_at,
         MAX(o.created_at) AS last_order_at,
         COUNT(o.id) AS order_count
  FROM customers c
  LEFT JOIN orders o ON o.customer_id = c.id
  GROUP BY c.id
  ORDER BY last_order_at DESC, c.created_at DESC
  LIMIT 200
")->fetchAll();

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Data Pelanggan</title>
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
      <div class="badge">Data Pelanggan</div>
    </div>

    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Pelanggan Landing Page</h3>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>
        <table class="table">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Telepon/WA</th>
              <th>Poin</th>
              <th>Order</th>
              <th>Order Terakhir</th>
              <th>Terdaftar</th>
              <?php if (($me['role'] ?? '') === 'owner'): ?>
                <th>Aksi</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($customers)): ?>
              <tr><td colspan="<?php echo ($me['role'] ?? '') === 'owner' ? '7' : '6'; ?>">Belum ada data pelanggan.</td></tr>
            <?php else: ?>
              <?php foreach ($customers as $c): ?>
                <?php $contact = $c['phone'] ?: $c['email']; ?>
                <tr>
                  <td><?php echo e($c['name']); ?></td>
                  <td><?php echo e($contact); ?></td>
                  <td><?php echo e((string)($c['loyalty_points'] ?? 0)); ?></td>
                  <td><?php echo e((string)$c['order_count']); ?></td>
                  <td><?php echo e($c['last_order_at'] ?? '-'); ?></td>
                  <td><?php echo e($c['created_at']); ?></td>
                  <?php if (($me['role'] ?? '') === 'owner'): ?>
                    <td>
                      <form method="post" data-confirm="Hapus pelanggan ini?">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete_customer">
                        <input type="hidden" name="customer_id" value="<?php echo e((string)$c['id']); ?>">
                        <button class="btn btn-ghost" type="submit">Hapus</button>
                      </form>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
