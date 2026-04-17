<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/customer_auth.php';

start_secure_session();
ensure_landing_order_tables();
ensure_loyalty_rewards_table();
customer_bootstrap_from_cookie();

$err = '';
$notice = '';
if (!empty($_SESSION['customer_notice'])) {
  $notice = (string)$_SESSION['customer_notice'];
  unset($_SESSION['customer_notice']);
}
if (!empty($_SESSION['customer_err'])) {
  $err = (string)$_SESSION['customer_err'];
  unset($_SESSION['customer_err']);
}

$customer = customer_current();
if (!$customer) {
  if ($notice === '') {
    $_SESSION['customer_notice'] = 'Silakan login terlebih dahulu.';
  }
  redirect(base_url('auth.php?mode=login'));
}

$stmt = db()->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
$stmt->execute([(int)$customer['id']]);
$customer = $stmt->fetch() ?: $customer;

$rewards = db()->query("
  SELECT lr.id, lr.points_required, p.name
  FROM loyalty_rewards lr
  JOIN products p ON p.id = lr.product_id
  ORDER BY lr.points_required ASC
")->fetchAll();

$stmt = db()->prepare("
  SELECT o.id, o.order_code, o.status, o.created_at,
         COALESCE(SUM(oi.subtotal), 0) AS total_amount
  FROM orders o
  LEFT JOIN order_items oi ON oi.order_id = o.id
  WHERE o.customer_id = ?
  GROUP BY o.id, o.order_code, o.status, o.created_at
  ORDER BY o.id DESC
");
$stmt->execute([(int)$customer['id']]);
$orders = $stmt->fetchAll();

$orderItemsByOrderId = [];
if (!empty($orders)) {
  $orderIds = array_map(static fn($row) => (int)$row['id'], $orders);
  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $stmt = db()->prepare("
    SELECT oi.order_id, oi.qty, oi.price_each, oi.subtotal, p.name AS product_name
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id IN ($placeholders)
    ORDER BY oi.id ASC
  ");
  $stmt->execute($orderIds);
  foreach ($stmt->fetchAll() as $item) {
    $oid = (int)$item['order_id'];
    if (!isset($orderItemsByOrderId[$oid])) {
      $orderItemsByOrderId[$oid] = [];
    }
    $orderItemsByOrderId[$oid][] = $item;
  }
}

$genderLabels = [
  'male' => 'Laki-laki',
  'female' => 'Perempuan',
  'other' => 'Lainnya',
];
$statusLabels = [
  'pending' => 'Menunggu',
  'processing' => 'Diproses',
  'completed' => 'Selesai',
  'cancelled' => 'Dibatalkan',
];
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Akun Pelanggan</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .customer-hero {display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .customer-grid {display:grid;gap:16px;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));}
    .customer-meta {display:grid;gap:8px;}
    .customer-badge {display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:rgba(59,130,246,.12);color:#1d4ed8;font-weight:600;}
    .order-items {margin:8px 0 0;padding-left:18px;color:var(--muted)}
  </style>
</head>
<body>
  <div class="container">
    <div class="main">
      <div class="topbar">
        <a class="btn" href="<?php echo e(base_url('index.php')); ?>">← Kembali</a>
      </div>

      <div class="content">
        <div class="card customer-hero">
          <div>
            <h2 style="margin:0">Akun Pelanggan</h2>
            <p style="margin:6px 0 0;color:var(--muted)">Kelola akun, cek poin, dan lihat riwayat belanja.</p>
          </div>
          <a class="btn" href="<?php echo e(base_url('customer_logout.php')); ?>">Logout</a>
        </div>

        <?php if ($notice): ?>
          <div class="card" style="margin-top:12px;border-color:rgba(16,185,129,.3);background:rgba(16,185,129,.08)"><?php echo e($notice); ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
          <div class="card" style="margin-top:12px;border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>

        <div class="customer-grid" style="margin-top:16px">
          <div class="card">
            <h3 style="margin-top:0">Profil</h3>
            <div class="customer-meta">
              <div><strong>Nama:</strong> <?php echo e($customer['name'] ?? '-'); ?></div>
              <div><strong>Username:</strong> <?php echo e($customer['username'] ?? '-'); ?></div>
              <div><strong>Email:</strong> <?php echo e($customer['email'] ?? '-'); ?></div>
              <div><strong>Nomor Telepon:</strong> <?php echo e($customer['phone'] ?? '-'); ?></div>
              <div><strong>Jenis Kelamin:</strong> <?php echo e($genderLabels[$customer['gender'] ?? ''] ?? '-'); ?></div>
              <div><strong>Tanggal Lahir:</strong> <?php echo e($customer['birth_date'] ?? '-'); ?></div>
            </div>
          </div>
          <div class="card">
            <h3 style="margin-top:0">Poin Loyalti</h3>
            <div style="font-size:28px;font-weight:700;margin-bottom:8px"><?php echo e((string)($customer['loyalty_points'] ?? 0)); ?> poin</div>
            <div class="customer-badge">Gunakan poin untuk klaim reward di POS.</div>
          </div>
        </div>

        <div class="card" style="margin-top:16px">
          <h3 style="margin-top:0">Riwayat Belanja</h3>
          <?php if (empty($orders)): ?>
            <p style="margin:0;color:var(--muted)">Belum ada riwayat pesanan.</p>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Kode Order</th>
                  <th>Status</th>
                  <th>Tanggal</th>
                  <th>Total</th>
                  <th>Item</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($orders as $order): ?>
                  <?php $items = $orderItemsByOrderId[(int)$order['id']] ?? []; ?>
                  <tr>
                    <td><?php echo e($order['order_code']); ?></td>
                    <td><?php echo e($statusLabels[$order['status'] ?? ''] ?? ($order['status'] ?? '-')); ?></td>
                    <td><?php echo e($order['created_at']); ?></td>
                    <td>Rp <?php echo e(format_number_id((float)$order['total_amount'], 0)); ?></td>
                    <td>
                      <?php if (empty($items)): ?>-
                      <?php else: ?>
                        <ul class="order-items">
                          <?php foreach ($items as $item): ?>
                            <li><?php echo e($item['product_name']); ?> × <?php echo e((string)$item['qty']); ?> (Rp <?php echo e(format_number_id((float)$item['subtotal'], 0)); ?>)</li>
                          <?php endforeach; ?>
                        </ul>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="card" style="margin-top:16px">
          <h3 style="margin-top:0">Reward yang Tersedia</h3>
          <?php if (empty($rewards)): ?>
            <p style="margin:0;color:var(--muted)">Belum ada reward yang tersedia.</p>
          <?php else: ?>
            <table>
              <thead><tr><th>Reward</th><th>Poin Dibutuhkan</th></tr></thead>
              <tbody>
                <?php foreach ($rewards as $reward): ?>
                  <tr>
                    <td><?php echo e($reward['name']); ?></td>
                    <td><?php echo e((string)$reward['points_required']); ?> poin</td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
